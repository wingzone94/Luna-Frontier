<?php
/**
 * Plugin Name: Luna Signal
 * Plugin URI: https://github.com/wingzone94/Node
 * Description: メディアの収益化とユーザビリティを両立するためのマネタイズ・検知層プラグイン。報酬レート管理や広告ブロック検知UIを提供します。
 * Version: 1.4.0
 * Author: Luminous Core Teams
 * Text Domain: node-signal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'NODE_SIGNAL_VERSION', '1.4.0' );
define( 'NODE_SIGNAL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$node_signal_embedded_dir = get_template_directory() . '/plugins-embedded/node-signal/';
define(
    'NODE_SIGNAL_PLUGIN_URL',
    is_dir( $node_signal_embedded_dir )
        ? get_template_directory_uri() . '/plugins-embedded/node-signal/'
        : content_url( '/plugins/node-signal/' )
);

// 簡易オートローダー
spl_autoload_register( function ( $class ) {
    $prefix = 'Node\\Signal\\';
    $base_dir = NODE_SIGNAL_PLUGIN_DIR . 'includes/';

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
function node_signal_init() {
    \Node\Signal\Core\Plugin::get_instance();
}
add_action( 'plugins_loaded', 'node_signal_init' );
