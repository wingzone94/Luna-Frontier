<?php
/**
 * Node Connect イベントバス。
 *
 * Nodeイベントの定義・発火（do_action 'node_connect_event'）と、
 * 発火されたイベントを設定済みWebhookへの非同期送信キューに載せるルーティングを担う。
 *
 * @package Node_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Connect_Event_Bus {

	public const EVENT_POST_PUBLISHED       = 'post_published';
	public const EVENT_POST_UPDATED         = 'post_updated';
	public const EVENT_POST_UNPUBLISHED     = 'post_unpublished';
	public const EVENT_POST_DELETED         = 'post_deleted';
	public const EVENT_AI_SUMMARY_COMPLETED = 'ai_summary_completed';
	public const EVENT_FACT_CHECK_COMPLETED = 'fact_check_completed';
	public const EVENT_AI_FAILED            = 'ai_failed';
	public const EVENT_NODE_UPDATED         = 'node_updated';

	// テーマ側のメンテナンスモード（inc/maintenance.php）から発火する。
	public const EVENT_MAINTENANCE_START = 'maintenance_start';
	public const EVENT_MAINTENANCE_END   = 'maintenance_end';

	public const CRON_HOOK          = 'node_connect_deliver';
	public const DEDUP_TTL          = 10 * MINUTE_IN_SECONDS;
	public const OPTION_ENABLED     = 'node_connect_enabled';
	public const OPTION_PAUSED      = 'node_connect_paused';
	public const OPTION_WEBHOOKS    = 'node_connect_webhooks';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function register(): void {
		// 外部（node-ai-tools・テーマ本体）からの発火もここで受ける。
		add_action( 'node_connect_event', [ $this, 'handle' ], 10, 2 );
		// 送信キュー消化はキュー投入時の設定に依存させないため常時登録する。
		add_action( self::CRON_HOOK, [ 'Node_Connect_Webhook_Sender', 'deliver' ], 10, 4 );
	}

	/**
	 * 設定画面に表示するイベント一覧（ID => ラベル）。
	 *
	 * @return array<string, string>
	 */
	public static function get_event_catalog(): array {
		return [
			self::EVENT_POST_PUBLISHED       => '記事の新規公開（予約公開を含む）',
			self::EVENT_POST_UPDATED         => '公開済み記事の更新',
			self::EVENT_POST_UNPUBLISHED     => '記事の非公開化',
			self::EVENT_POST_DELETED         => '記事の削除',
			self::EVENT_AI_SUMMARY_COMPLETED => 'AI要約の生成完了',
			self::EVENT_FACT_CHECK_COMPLETED => 'ファクトチェック完了',
			self::EVENT_AI_FAILED            => 'AI処理の失敗',
			self::EVENT_NODE_UPDATED         => 'Lunaアップデート',
			self::EVENT_MAINTENANCE_START    => 'メンテナンスの開始',
			self::EVENT_MAINTENANCE_END      => 'メンテナンスの終了',
		];
	}

	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	public static function is_paused(): bool {
		return (bool) get_option( self::OPTION_PAUSED, false );
	}

	/**
	 * 登録済みWebhook設定（最大 NODE_CONNECT_MAX_WEBHOOKS 件）を正規化して返す。
	 *
	 * @return array<int, array{label: string, url: string, events: string[], enabled: bool}>
	 */
	public static function get_webhooks(): array {
		$raw = get_option( self::OPTION_WEBHOOKS, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$webhooks = [];
		foreach ( array_slice( array_values( $raw ), 0, NODE_CONNECT_MAX_WEBHOOKS ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$webhooks[] = [
				'label'   => (string) ( $entry['label'] ?? '' ),
				'url'     => (string) ( $entry['url'] ?? '' ),
				'events'  => array_values( array_intersect( (array) ( $entry['events'] ?? [] ), array_keys( self::get_event_catalog() ) ) ),
				'enabled' => (bool) ( $entry['enabled'] ?? true ),
			];
		}

		return $webhooks;
	}

	/**
	 * 投稿ステータス遷移をNodeイベントへ分類する。該当しない遷移は null。
	 */
	public static function classify_transition( string $new_status, string $old_status ): ?string {
		if ( $new_status === $old_status ) {
			return null;
		}
		if ( 'publish' === $new_status ) {
			return self::EVENT_POST_PUBLISHED;
		}
		if ( 'publish' === $old_status && in_array( $new_status, [ 'draft', 'private', 'pending' ], true ) ) {
			return self::EVENT_POST_UNPUBLISHED;
		}
		return null;
	}

	/**
	 * イベントを発火する。node-connect 自身のルーティング（handle）もこの action 経由で動く。
	 *
	 * @param array<string, mixed> $payload スカラー値のみの連想配列（cron引数として直列化されるため）。
	 */
	public static function dispatch( string $event, array $payload = [] ): void {
		do_action( 'node_connect_event', $event, $payload );
	}

	/**
	 * イベントを購読Webhookごとの非同期送信キューへ載せる。
	 *
	 * 送信そのものは cron（wp_schedule_single_event）で行い、発火元の処理
	 * （記事公開など）へ失敗を波及させない（§1.2）。
	 *
	 * @param array<string, mixed> $payload
	 */
	public function handle( string $event, array $payload = [] ): void {
		if ( ! self::is_enabled() || self::is_paused() ) {
			return;
		}
		if ( ! array_key_exists( $event, self::get_event_catalog() ) ) {
			return;
		}

		foreach ( self::get_webhooks() as $index => $webhook ) {
			if ( ! $webhook['enabled'] || '' === $webhook['url'] ) {
				continue;
			}
			if ( ! in_array( $event, $webhook['events'], true ) ) {
				continue;
			}
			if ( ! self::mark_dedup( $event, $payload, $webhook['url'] ) ) {
				continue;
			}

			// URL自体は cron 引数に載せず、送信時点の設定から index で引く。
			wp_schedule_single_event( time(), self::CRON_HOOK, [ $event, $payload, $index, 1 ] );
		}
	}

	/**
	 * 重複送信防止。post_id + イベント種別 + URL のハッシュを transient に記録し、
	 * TTL内の同一通知を抑止する。送信してよければ true。
	 *
	 * 記事に紐つかないイベント（メンテナンスの開始・終了）は抑止の対象外にする。
	 * post_id が常に0のため、作業中に入切を繰り返すと2回目以降が握り潰されてしまい、
	 * かつWPフックの多重発火ではなく管理者の明示的な操作なので毎回通知してよい。
	 */
	private static function mark_dedup( string $event, array $payload, string $url ): bool {
		if ( in_array( $event, [ self::EVENT_MAINTENANCE_START, self::EVENT_MAINTENANCE_END ], true ) ) {
			return true;
		}

		$post_id = (int) ( $payload['post_id'] ?? 0 );
		$key     = 'node_connect_sent_' . md5( $post_id . '|' . $event . '|' . $url );

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, self::DEDUP_TTL );
		return true;
	}

	/**
	 * 投稿イベント共通のペイロードを組み立てる。
	 * 送信は非同期のため、削除等で後から取れなくなる情報も発火時点で確定させる。
	 *
	 * @return array<string, mixed>
	 */
	public static function build_post_payload( WP_Post $post ): array {
		$categories = [];
		foreach ( (array) get_the_terms( $post, 'category' ) as $term ) {
			if ( $term instanceof WP_Term ) {
				$categories[] = $term->name;
			}
		}

		$series_name  = '';
		$series_color = '';
		$series_terms = get_the_terms( $post, 'node_series' );
		if ( is_array( $series_terms ) && isset( $series_terms[0] ) && $series_terms[0] instanceof WP_Term ) {
			$series_name = $series_terms[0]->name;
		}
		if ( function_exists( 'node_series_get_color' ) ) {
			$series_color = (string) ( node_series_get_color( $post->ID ) ?? '' );
		}

		$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 60, '…' );

		return [
			'post_id'        => $post->ID,
			'title'          => get_the_title( $post ),
			'permalink'      => get_permalink( $post ),
			'author'         => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'date'           => get_post_time( 'Y-m-d H:i', false, $post ),
			'excerpt'        => wp_strip_all_tags( (string) $excerpt ),
			'image'          => (string) ( get_the_post_thumbnail_url( $post, 'large' ) ?: '' ),
			'categories'     => $categories,
			'series'         => $series_name,
			'series_color'   => $series_color,
			'has_ai_summary' => '' !== (string) get_post_meta( $post->ID, '_node_ai_summary', true ),
			'scheduled'      => false,
		];
	}
}
