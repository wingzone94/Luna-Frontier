<?php

declare(strict_types=1);
/**
 * Luminous Core Theme Functions
 *
 * このファイルは「ローダー + 最低限の初期化」に徹し、
 * 実際のロジックは inc/ ディレクトリに委譲します。
 *
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * -------------------------------------------------------
 * 1. 定数定義（テーマパス / バージョン）
 * -------------------------------------------------------
 */
define( 'NODE_THEME_DIR', get_template_directory() );
define( 'NODE_THEME_URI', get_template_directory_uri() );
define( 'NODE_ALL_ARTICLES_SLUG', 'all-articles' );
define( 'NODE_ALL_ARTICLES_PER_PAGE', 24 );
define( 'NODE_ALL_ARTICLES_TOTAL_LIMIT', 240 );
// トップとフッターの「カテゴリから探す」に出す件数。増やすとピルが数段に折り返して
// トップが縦に伸びるため、記事数の多い順で頭打ちにする。
define( 'NODE_CATEGORY_NAV_LIMIT', 12 );
define( 'NODE_CATEGORY_NAV_FOOTER_LIMIT', 8 );
define( 'NODE_PREFERRED_SOURCE_DEFAULT_URL', 'https://google.com/preferences/source?q=luminous-core.net' );
// Official Google preferred source badges are served from production uploads, not bundled in the theme.
define( 'NODE_PREFERRED_SOURCE_BADGE_BASE_URL', 'https://luminous-core.net/wp-content/uploads/2026/07/' );

$node_composer_autoloader = NODE_THEME_DIR . '/vendor/autoload.php';

if ( file_exists( $node_composer_autoloader ) ) {
	require_once $node_composer_autoloader;
}

/**
 * Keep the theme classes loadable when a distribution omits Composer's vendor directory.
 * Composer remains the primary PSR-4 loader; this fallback mirrors its NodeTheme\\ mapping.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$namespace_prefix = 'NodeTheme\\';

		if ( 0 !== strpos( $class_name, $namespace_prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $namespace_prefix ) );

		if ( '' === $relative_class || 1 !== preg_match( '/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/D', $relative_class ) ) {
			return;
		}

		$class_file     = NODE_THEME_DIR . '/src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $class_file ) ) {
			require_once $class_file;
		}
	}
);

require_once NODE_THEME_DIR . '/inc/theme-setup.php';
define( 'NODE_THEME_VERSION', node_get_theme_version() );

\NodeTheme\Setup\ThemeSupport::register();
\NodeTheme\Hooks\CustomHooks::register();

/**
 * -------------------------------------------------------
 * 2. inc/ の読み込み（責務ごとに分離）
 * -------------------------------------------------------
 */
require_once NODE_THEME_DIR . '/inc/hooks.php';
require_once NODE_THEME_DIR . '/inc/meta-boxes.php';
require_once NODE_THEME_DIR . '/inc/category-meta.php';
require_once NODE_THEME_DIR . '/inc/ajax.php';
require_once NODE_THEME_DIR . '/inc/spotlight.php';
require_once NODE_THEME_DIR . '/inc/archive-helpers.php';
require_once NODE_THEME_DIR . '/inc/icon-font.php';
require_once NODE_THEME_DIR . '/inc/search-suggest.php';
require_once NODE_THEME_DIR . '/inc/media.php';
require_once NODE_THEME_DIR . '/inc/featured-webp.php';
require_once NODE_THEME_DIR . '/inc/utilities.php';
require_once NODE_THEME_DIR . '/inc/gemini-helper.php';
require_once NODE_THEME_DIR . '/inc/gemini-models.php';
require_once NODE_THEME_DIR . '/inc/gemini-user-settings.php';
require_once NODE_THEME_DIR . '/inc/admin-settings.php';
require_once NODE_THEME_DIR . '/inc/seo.php';
require_once NODE_THEME_DIR . '/inc/indexing.php';
require_once NODE_THEME_DIR . '/inc/scheduler.php';
require_once NODE_THEME_DIR . '/inc/ogp-generator.php';
require_once NODE_THEME_DIR . '/inc/toc-engine.php';
require_once NODE_THEME_DIR . '/inc/blogcard-store.php';
require_once NODE_THEME_DIR . '/inc/blogcard.php';
require_once NODE_THEME_DIR . '/inc/maintenance.php';
require_once NODE_THEME_DIR . '/inc/print.php';
require_once NODE_THEME_DIR . '/inc/cleanup.php';
require_once NODE_THEME_DIR . '/inc/redirects.php';
require_once NODE_THEME_DIR . '/inc/modified-date.php';
require_once NODE_THEME_DIR . '/inc/front-head.php';
require_once NODE_THEME_DIR . '/inc/footnotes.php';
require_once NODE_THEME_DIR . '/inc/routing.php';
require_once NODE_THEME_DIR . '/inc/content-fixes.php';

/**
 * -------------------------------------------------------
 * 2.5 埋め込みプラグインの読み込み (Plugins Embedded)
 * -------------------------------------------------------
 */
$embedded_plugins = [
	'luminous-core-engine/luminous-core-engine.php' => 'luminous_core_engine_init',
	'node-signal/node-signal.php'           => 'node_signal_init',
	'luminous-blocks/luminous-blocks.php'   => 'luminous_blocks_init',
	'node-ai-tools/node-ai-tools.php'       => 'node_ai_core_init',
	'node-flow/node-flow.php'               => 'node_flow_init',
	'luminous-nexus/luminous-nexus.php'     => 'luminous_nexus_init',
	'luminous-interactivity/luminous-interactivity.php' => 'luminous_interactivity_init',
	'node-library/node-library.php'         => 'node_library_init',
	'node-seo-tools/node-seo-tools.php'     => 'node_seo_tools_init',
	'node-series/node-series.php'           => 'node_series_init',
	'node-connect/node-connect.php'         => 'node_connect_init',
	'node-image-compressor/node-image-compressor.php' => 'node_image_compressor_init',
];

// 単体プラグインとして導入済みのものは、そちらを優先して同梱版を読み込まない。
// 初期化関数の有無だけで判定すると、その関数を持たない古い版が単体で入っている環境で
// 判定をすり抜け、Cannot redeclare の致命的エラーになる（1.2.3 の node-connect 障害）。
$node_active_plugins = (array) get_option( 'active_plugins', [] );

foreach ( $embedded_plugins as $plugin_file => $init_func ) {
	$path = NODE_THEME_DIR . '/plugins-embedded/' . $plugin_file;

	if ( function_exists( $init_func ) || in_array( $plugin_file, $node_active_plugins, true ) ) {
		continue;
	}

	if ( file_exists( $path ) ) {
		require_once $path;
		// 読み込んだ直後に初期化関数を直接実行（plugins_loaded フックを待たずに確実に起動）
		if ( function_exists( $init_func ) ) {
			$init_func();
		}
	}
}

// Luna interactive は単独版が有効ならそちらを優先する。テーマ同梱版は
// init フックでブロックを登録するため、上の即時初期化ループには入れない。
if ( ! function_exists( 'luna_interactive_register_block' )
	&& ! in_array( 'luna-interactive/luna-interactive.php', $node_active_plugins, true ) ) {
	$luna_embedded = NODE_THEME_DIR . '/plugins-embedded/luna-interactive/luna-interactive.php';
	if ( is_file( $luna_embedded ) ) {
		define( 'LUNA_INTERACTIVE_EMBEDDED', true );
		require_once $luna_embedded;
	}
}





/**
 * -------------------------------------------------------
 * 9. Payload Cleanup (Remove emojis, global-styles, etc.)
 * -------------------------------------------------------
 */



/**
 * Disable default inline HTML margin injection by the WordPress Admin Bar
 */
add_theme_support( 'admin-bar', array( 'callback' => '__return_false' ) );


