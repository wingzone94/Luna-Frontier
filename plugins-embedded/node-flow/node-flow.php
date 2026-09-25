<?php
/**
 * Plugin Name: Luna Flow
 * Plugin URI: https://github.com/wingzone94/Node
 * Description: フロントエンドUXと動的ルーティングを担当するプラグイン。
 * Version: 1.4.0
 * Author: Luminous Core Teams
 * Text Domain: node-flow
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'NODE_FLOW_VERSION', '1.4.0' );
define( 'NODE_FLOW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$node_flow_embedded_dir = get_template_directory() . '/plugins-embedded/node-flow/';
define(
    'NODE_FLOW_PLUGIN_URL',
    is_dir( $node_flow_embedded_dir )
        ? get_template_directory_uri() . '/plugins-embedded/node-flow/'
        : content_url( '/plugins/node-flow/' )
);

// 簡易オートローダー
spl_autoload_register( function ( $class ) {
    $prefix = 'Node\\Flow\\';
    $base_dir = NODE_FLOW_PLUGIN_DIR . 'includes/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// プラグインの初期化
function node_flow_init() {
    \Node\Flow\Core\Plugin::get_instance();
}
add_action( 'plugins_loaded', 'node_flow_init' );
