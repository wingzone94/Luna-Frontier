<?php
/**
 * Plugin Name: Luna Ads Center
 * Plugin URI:  https://github.com/wingzone94/Luna-Frontier
 * Description: 広告枠の配信を一元管理する独立プラグイン。初期状態では広告を配信せず、テーマや Site Kit の広告コードと二重に挿入しません。
 * Version:     1.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author:      Luminous Core Teams
 * Author URI:  https://luminous-core.net/
 * License:     MIT
 * Text Domain: luna-ads-control
 *
 * @package Luna_Ads_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUNA_ADS_CONTROL_VERSION', '1.1.0' );
define( 'LUNA_ADS_CONTROL_FILE', __FILE__ );
define( 'LUNA_ADS_CONTROL_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUNA_ADS_CONTROL_URL', plugin_dir_url( __FILE__ ) );

require_once LUNA_ADS_CONTROL_DIR . 'includes/class-luna-ads-settings.php';
require_once LUNA_ADS_CONTROL_DIR . 'includes/class-luna-ads-renderer.php';
require_once LUNA_ADS_CONTROL_DIR . 'includes/class-luna-ads-admin.php';

/**
 * プラグインの起動と公開 API。
 */
final class Luna_Ads_Control {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Luna_Ads_Renderer::init();

		if ( is_admin() ) {
			Luna_Ads_Admin::init();
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( 'luna_ad', array( 'Luna_Ads_Renderer', 'shortcode' ) );
	}

	/**
	 * 広告枠が実際に出力され得るときだけ最小限のCSSを読み込む。
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! Luna_Ads_Renderer::needs_public_styles() ) {
			return;
		}

		wp_enqueue_style(
			'luna-ads-control',
			LUNA_ADS_CONTROL_URL . 'assets/public.css',
			array(),
			LUNA_ADS_CONTROL_VERSION
		);
	}

}

require_once LUNA_ADS_CONTROL_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'Luna_Ads_Settings', 'activate' ) );

Luna_Ads_Control::instance();
