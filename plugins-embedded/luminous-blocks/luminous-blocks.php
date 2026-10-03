<?php
/**
 * Plugin Name:  Luna Blocks
 * Plugin URI:   https://github.com/wingzone94/Node
 * Description:  Gutenberg カスタムブロック（Smart Sort Table, Voting）および外部サービス埋め込み（Apple Music, Spotify, Google Maps）。
 * Version:      1.4.0
 * Author:       Luminous Core Teams
 * Author URI:   https://github.com/wingzone94
 * License:      MIT
 * Text Domain:  luminous-blocks
 * Requires PHP: 8.0
 *
 * @package Luminous_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUMINOUS_BLOCKS_VERSION', '1.4.0' );
define( 'LUMINOUS_BLOCKS_DIR', plugin_dir_path( __FILE__ ) );

$luminous_blocks_embedded_dir = get_template_directory() . '/plugins-embedded/luminous-blocks/';
define(
	'LUMINOUS_BLOCKS_URL',
	is_dir( $luminous_blocks_embedded_dir )
		? get_template_directory_uri() . '/plugins-embedded/luminous-blocks/'
		: content_url( '/plugins/luminous-blocks/' )
);

/**
 * プラグイン初期化
 */
final class Luminous_Blocks {

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
		require_once LUMINOUS_BLOCKS_DIR . 'includes/utils.php';
		require_once LUMINOUS_BLOCKS_DIR . 'includes/blocks/sort-table.php';
		require_once LUMINOUS_BLOCKS_DIR . 'includes/blocks/apple-music.php';
		require_once LUMINOUS_BLOCKS_DIR . 'includes/blocks/google-map.php';
		require_once LUMINOUS_BLOCKS_DIR . 'includes/blocks/spotify.php';
		require_once LUMINOUS_BLOCKS_DIR . 'includes/blocks/embed.php';
		require_once LUMINOUS_BLOCKS_DIR . 'includes/media-label.php';
		require_once LUMINOUS_BLOCKS_DIR . 'includes/voting.php';
		require_once LUMINOUS_BLOCKS_DIR . 'includes/oembed-handlers.php';
	}

	private function register_hooks(): void {
		// ブロック登録
		add_action( 'init', [ $this, 'register_blocks' ] );

		// oEmbed ハンドラー登録
		add_action( 'init', [ $this, 'register_oembed_handlers' ] );

		// フロントエンド / エディタ用アセット
		add_action( 'luminous_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
	}

	public function register_blocks(): void {
		node_register_m3_blocks();
	}

	public function register_oembed_handlers(): void {
		node_register_oembed_handlers();
	}

	public function enqueue_frontend_assets(): void {
		$style_asset = $this->resolve_asset( 'assets/css/blocks.css', 'assets/css/blocks.css' );
		$script_asset = $this->resolve_asset( 'assets/js/blocks.js', 'assets/js/blocks.js' );

		if ( $style_asset ) {
			wp_enqueue_style(
				'luminous-blocks',
				$style_asset['url'],
				[],
				$style_asset['version']
			);
		}

		if ( $script_asset ) {
			wp_enqueue_script(
				'luminous-blocks',
				$script_asset['url'],
				[],
				$script_asset['version'],
				true
			);
		}

		// node/notice のフロント配色はテーマの style.css（_notice.css）が担当。
		// プラグインからの個別 enqueue は不要。
	}

	public function enqueue_editor_assets(): void {
		$editor_asset = $this->resolve_asset( 'assets/js/editor.js', 'assets/js/editor.js' );

		if ( ! $editor_asset ) {
			return;
		}

		wp_enqueue_script(
			'luminous-blocks-editor',
			$editor_asset['url'],
			[ 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-plugins', 'wp-edit-post', 'wp-i18n', 'wp-api-fetch' ],
			$editor_asset['version'],
			true
		);
	}

	/**
	 * Embedded plugin assets used to live under the theme-level assets directory.
	 * Resolve both locations so production ZIPs stay compact without breaking old installs.
	 *
	 * @return array{url:string, version:string}|null
	 */
	private function resolve_asset( string $embedded_relative_path, string $theme_relative_path ): ?array {
		$embedded_path = LUMINOUS_BLOCKS_DIR . $embedded_relative_path;

		if ( file_exists( $embedded_path ) ) {
			return [
				'url'     => LUMINOUS_BLOCKS_URL . $embedded_relative_path,
				'version' => (string) filemtime( $embedded_path ),
			];
		}

		$theme_path = get_template_directory() . '/' . ltrim( $theme_relative_path, '/' );

		if ( ! file_exists( $theme_path ) ) {
			return null;
		}

		return [
			'url'     => get_template_directory_uri() . '/' . ltrim( $theme_relative_path, '/' ),
			'version' => (string) filemtime( $theme_path ),
		];
	}
}

/**
 * プラグイン起動
 */
function luminous_blocks_init(): void {
	Luminous_Blocks::instance();
}
add_action( 'plugins_loaded', 'luminous_blocks_init' );
