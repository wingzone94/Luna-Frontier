<?php
/**
 * Plugin Name:  Luna Connect
 * Plugin URI:   https://github.com/wingzone94/Node
 * Description:  外部サービス連携基盤（ベータ版）。記事の公開・更新などのイベントを Webhook（Discord）へ通知する（旧称: Node Connect）。
 * Version:      1.4.0
 * Author:       Luminous Core Teams
 * Author URI:   https://github.com/wingzone94
 * License:      MIT
 * Text Domain:  node-connect
 * Requires PHP: 8.0
 *
 * @package Node_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 既に別経路（単体プラグイン or テーマ同梱）で読み込み済みなら何もしない。
 *
 * テーマ側のローダー（functions.php の $embedded_plugins）は node_connect_init() の
 * 有無で二重読み込みを判定するが、1.3.3 以前の単体プラグインはこの関数を持たないため
 * 判定をすり抜け、定数の再定義警告と Cannot redeclare class Node_Connect の
 * 致命的エラーを起こしていた（1.2.3 で本番障害・1.2.4 で修正）。
 * ローダー側の判定に依存せず、このファイル自身を冪等にして再発を防ぐ。
 *
 * 判定に class_exists() は使えない。PHPは無条件のクラス宣言をコンパイル時に
 * 巻き上げるため、このファイル自身の Node_Connect を検知して常に true になり、
 * 定数が未定義のまま return してしまう。実行時にしか定義されない定数で判定する。
 * 併せて class / function の宣言を条件付きにし、巻き上げによるコンパイル時の
 * 再宣言エラー自体を起こさないようにする。
 */
if ( defined( 'NODE_CONNECT_VERSION' ) ) {
	return;
}

define( 'NODE_CONNECT_VERSION', '1.4.0' );
define( 'NODE_CONNECT_DIR', plugin_dir_path( __FILE__ ) );
define( 'NODE_CONNECT_MAX_WEBHOOKS', 3 );

// 条件付き宣言にすることでコンパイル時の早期束縛（巻き上げ）を回避する。
if ( ! class_exists( 'Node_Connect', false ) ) :

/**
 * プラグイン初期化
 */
final class Node_Connect {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->register_hooks();
	}

	private function load_dependencies(): void {
		require_once NODE_CONNECT_DIR . 'includes/class-delivery-log.php';
		require_once NODE_CONNECT_DIR . 'includes/class-discord-formatter.php';
		require_once NODE_CONNECT_DIR . 'includes/class-webhook-sender.php';
		require_once NODE_CONNECT_DIR . 'includes/class-event-bus.php';
		require_once NODE_CONNECT_DIR . 'includes/class-x-poster.php';

		if ( is_admin() ) {
			require_once NODE_CONNECT_DIR . 'admin/settings-page.php';
			require_once NODE_CONNECT_DIR . 'admin/meta-box-x-post.php';
		}
	}

	private function register_hooks(): void {
		Node_Connect_Event_Bus::instance()->register();
		Node_Connect_X_Poster::instance()->register();

		// Webhook通知・X自動投稿とも無効なら投稿イベントの監視自体を登録しない（§1.2）。
		if ( ! Node_Connect_Event_Bus::is_enabled() && ! Node_Connect_X_Poster::is_enabled() ) {
			return;
		}

		add_action( 'transition_post_status', [ $this, 'on_transition_post_status' ], 10, 3 );
		add_action( 'post_updated', [ $this, 'on_post_updated' ], 10, 3 );
		add_action( 'wp_trash_post', [ $this, 'on_trash_post' ] );
		add_action( 'before_delete_post', [ $this, 'on_before_delete_post' ], 10, 2 );
	}

	/**
	 * 新規公開（予約公開含む）・非公開化を検知する。
	 */
	public function on_transition_post_status( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'post' !== $post->post_type ) {
			return;
		}

		$event = Node_Connect_Event_Bus::classify_transition( $new_status, $old_status );
		if ( null === $event ) {
			return;
		}

		$payload              = Node_Connect_Event_Bus::build_post_payload( $post );
		$payload['scheduled'] = ( 'future' === $old_status );

		Node_Connect_Event_Bus::dispatch( $event, $payload );
	}

	/**
	 * 公開済み記事の更新（publish→publish）。リビジョン・自動保存は除外。
	 */
	public function on_post_updated( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {
		if ( 'post' !== $post_after->post_type ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( 'publish' !== $post_after->post_status || 'publish' !== $post_before->post_status ) {
			return;
		}

		Node_Connect_Event_Bus::dispatch(
			Node_Connect_Event_Bus::EVENT_POST_UPDATED,
			Node_Connect_Event_Bus::build_post_payload( $post_after )
		);
	}

	/**
	 * 公開済み記事のゴミ箱移動を「記事の削除」として通知する。
	 */
	public function on_trash_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		Node_Connect_Event_Bus::dispatch(
			Node_Connect_Event_Bus::EVENT_POST_DELETED,
			Node_Connect_Event_Bus::build_post_payload( $post )
		);
	}

	/**
	 * ゴミ箱を経由しない完全削除。公開状態から直接削除された場合のみ通知する
	 * （ゴミ箱経由は on_trash_post で通知済みのため二重通知しない）。
	 */
	public function on_before_delete_post( int $post_id, ?WP_Post $post = null ): void {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		Node_Connect_Event_Bus::dispatch(
			Node_Connect_Event_Bus::EVENT_POST_DELETED,
			Node_Connect_Event_Bus::build_post_payload( $post )
		);
	}
}

endif;

if ( ! function_exists( 'node_connect_init' ) ) :

/**
 * 初期化エントリポイント。
 *
 * テーマ同梱（functions.php の $embedded_plugins）からの読み込みと、
 * 単体プラグインとしてのインストールの両対応。テーマ側のローダーは
 * この関数の有無で二重読み込みを判定するため、他の同梱プラグイン
 * （node_series_init 等）と同じく名前付き関数として公開する。
 */
function node_connect_init() {
	Node_Connect::instance();
}

endif;

add_action( 'plugins_loaded', 'node_connect_init' );
