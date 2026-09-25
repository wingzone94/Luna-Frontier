<?php
/**
 * Plugin Name: Luna SEO Tools
 * Description: X / Discord 向けシェア画像（OGP）の自動生成とメタタグ出力。公式素材をベースに合成します。
 * Version: 1.4.0
 * Author: Luminous Core Teams
 * Text Domain: node-seo-tools
 * Requires PHP: 8.0
 *
 * @package Node_SEO_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NODE_SEO_TOOLS_VERSION', '1.4.0' );
define( 'NODE_SEO_TOOLS_DIR', plugin_dir_path( __FILE__ ) );

$node_seo_embedded_dir = get_template_directory() . '/plugins-embedded/node-seo-tools/';
define(
	'NODE_SEO_TOOLS_URL',
	is_dir( $node_seo_embedded_dir )
		? get_template_directory_uri() . '/plugins-embedded/node-seo-tools/'
		: content_url( '/plugins/node-seo-tools/' )
);

spl_autoload_register(
	static function ( string $class ): void {
		$prefix   = 'Node\\SEO\\Tools\\';
		$base_dir = NODE_SEO_TOOLS_DIR . 'includes/';

		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * プラグイン起動
 */
function node_seo_tools_init(): void {
	\Node\SEO\Tools\Core\Plugin::instance();
}

add_action( 'plugins_loaded', 'node_seo_tools_init' );
