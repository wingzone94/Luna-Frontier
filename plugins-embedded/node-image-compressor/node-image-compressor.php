<?php
/**
 * Plugin Name:  Luna Image Compressor
 * Plugin URI:   https://github.com/wingzone94/Node
 * Description:  アイキャッチ画像を自動で WebP に置き換え、転送量と LCP を改善する。元ファイルを残す設定にすれば復元もできる。
 * Version:      1.4.0
 * Author:       Luminous Core Teams
 * Author URI:   https://github.com/wingzone94
 * License:      MIT
 * Text Domain:  node-image-compressor
 * Requires PHP: 8.0
 *
 * @package Node_Image_Compressor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NODE_IC_VERSION', '1.4.0' );
define( 'NODE_IC_DIR', plugin_dir_path( __FILE__ ) );

$node_ic_embedded_dir = get_template_directory() . '/plugins-embedded/node-image-compressor/';
define(
	'NODE_IC_URL',
	is_dir( $node_ic_embedded_dir )
		? get_template_directory_uri() . '/plugins-embedded/node-image-compressor/'
		: content_url( '/plugins/node-image-compressor/' )
);

/**
 * プラグイン本体。
 *
 * 同梱版（テーマの $embedded_plugins）と単体プラグイン版の両方から起動されうるため、
 * 初期化はシングルトンで一度きりに畳む。
 */
final class Node_Image_Compressor {

	private static ?self $instance = null;

	/**
	 * 管理ページのフックサフィックス（admin_enqueue_scripts の自ページ判定に使う）。
	 */
	public static string $page_hook = '';

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
		require_once NODE_IC_DIR . 'includes/class-webp-converter.php';
		require_once NODE_IC_DIR . 'includes/query.php';
		require_once NODE_IC_DIR . 'includes/hooks-featured-image.php';
		require_once NODE_IC_DIR . 'includes/ajax.php';
		require_once NODE_IC_DIR . 'includes/redirect.php';

		if ( is_admin() ) {
			require_once NODE_IC_DIR . 'admin/settings-page.php';
			require_once NODE_IC_DIR . 'admin/meta-box.php';
		}
	}

	private function register_hooks(): void {
		add_action( 'wp_ajax_node_ic_convert_one', 'node_ic_ajax_convert_one' );
		add_action( 'wp_ajax_node_ic_restore_one', 'node_ic_ajax_restore_one' );
		add_action( 'wp_ajax_node_ic_skip_toggle', 'node_ic_ajax_skip_toggle' );

		// アイキャッチが設定されたら非同期で置き換えを予約する
		add_action( 'added_post_meta', 'node_ic_on_thumbnail_meta', 10, 4 );
		add_action( 'updated_post_meta', 'node_ic_on_thumbnail_meta', 10, 4 );
		add_action( Node_IC_Converter::CRON_HOOK, 'node_ic_run_scheduled_conversion', 10, 1 );

		// 消えた旧画像 URL を WebP へ転送する。
		// 404 テンプレートの描画に入る前に済ませたいので、template_redirect の最優先で走らせる
		add_action( 'template_redirect', 'node_ic_maybe_redirect_replaced_image', 0 );
	}
}

/**
 * 初期化関数（テーマの $embedded_plugins から直接呼ばれる契約）。
 */
function node_image_compressor_init(): void {
	Node_Image_Compressor::instance();
}

add_action( 'plugins_loaded', 'node_image_compressor_init' );
