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
 * 全記事一覧ページ（上限付き）のURLを返す。
 */
function node_get_all_articles_url() {
	return home_url( '/' . trim( NODE_ALL_ARTICLES_SLUG, '/' ) . '/' );
}

/**
 * 速報（HEADLINE）カテゴリを返す。見つからなければ null。
 */
function node_get_news_category() {
	$news_cat = get_term_by( 'name', 'ニュース', 'category' );

	return ( $news_cat && ! is_wp_error( $news_cat ) ) ? $news_cat : null;
}

/**
 * ヘッドライン（速報）一覧のURLを返す。
 *
 * 1.3 で独自URL `/headlines/` を廃止し、ニュースカテゴリのアーカイブを正とした。
 * 単一カテゴリの別名でしかなく、title / canonical / sitemap が標準経路のほうが
 * 正しく出るため（NODE-1.3.md §16）。
 */
function node_get_headlines_url() {
	$news_cat = node_get_news_category();
	if ( $news_cat ) {
		$link = get_category_link( $news_cat->term_id );
		if ( $link && ! is_wp_error( $link ) ) {
			return $link;
		}
	}

	return home_url( '/' );
}

/**
 * 速報カテゴリのアーカイブに category-news.php を使う。
 *
 * スラッグが日本語（`ニュース`）のため `category-news.php` は
 * テンプレート階層に一致せず、汎用 archive.php にフォールバックしていた。
 */
function node_news_category_template_hierarchy( $templates ) {
	$news_cat = node_get_news_category();
	if ( $news_cat && is_category( $news_cat->term_id ) ) {
		array_unshift( $templates, 'category-news.php' );
	}

	return $templates;
}
add_filter( 'category_template_hierarchy', 'node_news_category_template_hierarchy' );

/**
 * SPOTLIGHT 特集一覧ページのURLを返す。
 */
function node_get_spotlight_url() {
	return home_url( '/spotlight/' );
}

/**
 * リクエストパスが SPOTLIGHT 専用アーカイブ（/spotlight/）か判定する。
 * リライトルール未フラッシュ環境向けフォールバックでも使用。
 */
function node_is_spotlight_archive_request(): bool {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path        = trim( (string) parse_url( $request_uri, PHP_URL_PATH ), '/' );
	$home_path   = trim( (string) parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

	if ( '' !== $home_path ) {
		if ( $path === $home_path ) {
			$path = '';
		} elseif ( str_starts_with( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) + 1 );
		}
	}

	return 'spotlight' === $path;
}

/**
 * リライト未反映時も /spotlight/ を node_spotlight クエリとして解決する。
 *
 * @param array<string, mixed> $query_vars クエリ変数。
 * @return array<string, mixed>
 */
function node_spotlight_request_fallback( $query_vars ) {
	if ( ! empty( $query_vars['node_spotlight'] ) ) {
		return $query_vars;
	}

	if ( node_is_spotlight_archive_request() ) {
		return array( 'node_spotlight' => '1' );
	}

	return $query_vars;
}
add_filter( 'request', 'node_spotlight_request_fallback' );

/**
 * 404 判定後の最終フォールバック（本番で rewrite_rules が古い場合）。
 */
function node_spotlight_404_fallback(): void {
	if ( ! is_404() || ! node_is_spotlight_archive_request() ) {
		return;
	}

	global $wp_query;

	$wp_query->query_vars['node_spotlight'] = '1';
	$wp_query->query_vars['error']          = '';
	unset( $wp_query->query_vars['pagename'], $wp_query->query_vars['name'] );
	$wp_query->is_404     = false;
	$wp_query->is_home    = false;
	$wp_query->is_archive = false;
	$wp_query->is_singular = false;

	status_header( 200 );
}
add_action( 'template_redirect', 'node_spotlight_404_fallback', 0 );

/**
 * 全記事一覧専用のリライトルールを登録する。
 */
function node_register_all_articles_rewrite_rule() {
	add_rewrite_tag( '%node_all_articles%', '1' );
	add_rewrite_rule(
		'^' . preg_quote( NODE_ALL_ARTICLES_SLUG, '/' ) . '/?$',
		'index.php?node_all_articles=1',
		'top'
	);
	add_rewrite_rule(
		'^' . preg_quote( NODE_ALL_ARTICLES_SLUG, '/' ) . '/page/([0-9]{1,})/?$',
		'index.php?node_all_articles=1&paged=$matches[1]',
		'top'
	);

	// SPOTLIGHT 専用のリライトルール
	add_rewrite_tag( '%node_spotlight%', '1' );
	add_rewrite_rule(
		'^spotlight/?$',
		'index.php?node_spotlight=1',
		'top'
	);
}
add_action( 'init', 'node_register_all_articles_rewrite_rule' );

/**
 * クエリ変数を明示的に公開する。
 */
function node_add_all_articles_query_var( $vars ) {
	$vars[] = 'node_all_articles';
	$vars[] = 'node_spotlight';
	return $vars;
}
add_filter( 'query_vars', 'node_add_all_articles_query_var' );

/**
 * 専用一覧テンプレートに差し替える。
 */
function node_use_all_articles_template( $template ) {
	if ( get_query_var( 'node_all_articles' ) ) {
		$custom_template = NODE_THEME_DIR . '/template-parts/all-articles.php';
		if ( file_exists( $custom_template ) ) {
			return $custom_template;
		}
	}

	if ( get_query_var( 'node_spotlight' ) ) {
		$custom_template = NODE_THEME_DIR . '/template-parts/spotlight-archive.php';
		if ( file_exists( $custom_template ) ) {
			return $custom_template;
		}
	}

	return $template;
}
add_filter( 'template_include', 'node_use_all_articles_template', 99 );

/**
 * リライトルールを一度だけフラッシュする（本番運用向け）。
 */
function node_maybe_flush_rewrite_rules_for_all_articles() {
	// v6: /headlines/ の独自リライトを撤去（ニュースカテゴリへ301）
	$rewrite_version = 'node_all_articles_v6';
	if ( get_option( 'node_rewrite_rules_version' ) === $rewrite_version ) {
		return;
	}

	if ( wp_installing() ) {
		return;
	}

	node_register_all_articles_rewrite_rule();
	flush_rewrite_rules( false );
	update_option( 'node_rewrite_rules_version', $rewrite_version );
}
add_action( 'after_switch_theme', 'node_maybe_flush_rewrite_rules_for_all_articles' );
add_action( 'init', 'node_maybe_flush_rewrite_rules_for_all_articles', 20 );

/**
 * -------------------------------------------------------
 * 4. Service Worker の登録 (重複排除・最適化)
 * -------------------------------------------------------
 */
function node_register_service_worker() {
    // header.phpではなく、安全にフッターで遅延登録する
	echo '<script>
		window.addEventListener("load", () => {
			if ("serviceWorker" in navigator) {
				navigator.serviceWorker.register("' . esc_url( NODE_THEME_URI . '/sw.js' ) . '")
				.then(() => {})
				.catch(err => console.error("SW registration failed: ", err));
			}
		});
	</script>';
}
add_action( 'wp_footer', 'node_register_service_worker' );

/**
 * -------------------------------------------------------
 * 5. Branding Normalization & DB Updates
 * -------------------------------------------------------
 */
function node_enforce_branding_update() {
    if ( ! is_admin() || (defined('REST_REQUEST') && REST_REQUEST) ) return;
    $current_name = get_option('blogname');
    if ($current_name === 'CyberNode' || $current_name === 'Node' || empty($current_name)) {
        update_option('blogname', 'Luminous Core');
    }
}
add_action('admin_init', 'node_enforce_branding_update');

function luminous_brand_normalize( $value ) {
    if ( is_string( $value ) ) {
        $trimmed_value = trim( $value );
        if ( in_array( $trimmed_value, array( 'CyberNode', 'Node' ), true ) ) {
            return 'Luminous Core';
        }

        return str_replace( 'CyberNode', 'Luminous Core', $value );
    }
    return $value;
}
add_filter( 'option_blogname', 'luminous_brand_normalize' );
add_filter( 'option_blogdescription', 'luminous_brand_normalize' );
add_filter( 'pre_get_document_title', 'luminous_brand_normalize', 999 );

/**
 * -------------------------------------------------------
 * 6. Slug Sanitization (日本語スラッグ自動回避ロジック)
 * 本番環境でのSEO・シェア時のURL文字化けを防ぎます。
 * -------------------------------------------------------
 */
function luminous_core_auto_post_slug( $slug, $post_ID, $post_status, $post_type ) {
	$slug = (string) $slug;

    // ID未確定（wp_insert_post の新規挿入時は 0）の場合は書き換えない。
    // 書き換えると全記事が「post-0」に衝突し、シングルクエリが複数件を返して
    // ループ二重描画→comments.php の関数再宣言 Fatal を誘発する。
    if ( ! $post_ID ) {
        return $slug;
    }

    // カスタム投稿タイプなどを含め、日本語（URLエンコードされる文字）が含まれているかを判定
    if ( preg_match( '/(%[0-9a-f]{2})+/i', $slug ) || preg_match( '/[^a-z0-9\-]/i', $slug ) ) {
        // 日本語が含まれている場合、一律で「post-投稿ID」の形式に書き換える
        $slug = 'post-' . $post_ID;
    }
    return $slug;
}
add_filter( 'wp_unique_post_slug', 'luminous_core_auto_post_slug', 10, 4 );

/**
 * -------------------------------------------------------
 * 7. Font Preconnect (存在しないフォントファイルのプリロードは削除)
 * -------------------------------------------------------
 */
function node_preload_webfonts() {
    // Google Fonts preconnect のみ（ローカルフォントファイルは存在しないため削除）
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}
add_action( 'wp_head', 'node_preload_webfonts', 1 );

/**
 * -------------------------------------------------------
 * 7.5. body 非表示フォールバック（JSが失敗した場合の保険）
 *
 * style.css の `body { opacity: 0; visibility: hidden }` は
 * JS が `body.is-loaded` を付与することで解除される設計だが、
 * CDN の GSAP 読み込み失敗などで JS がエラーになった場合に
 * ページが真っ白のままになる。
 * noscript タグと JS フォールバックで確実に表示させる。
 * -------------------------------------------------------
 */
function node_body_visibility_fallback() {
    echo '<noscript><style>body { opacity: 1 !important; visibility: visible !important; }</style></noscript>' . "\n";
    // JS が遅延しても最大2秒後には強制表示するフォールバック
    echo '<script>
        (function() {
            var timeout = setTimeout(function() {
                document.body.classList.add("is-loaded");
            }, 2000);
            document.addEventListener("DOMContentLoaded", function() {
                clearTimeout(timeout);
                // main.js が is-loaded を付与するが、失敗した場合のフォールバック
                setTimeout(function() {
                    if (!document.body.classList.contains("is-loaded")) {
                        document.body.classList.add("is-loaded");
                    }
                }, 500);
            });
        })();
    </script>' . "\n";
}
add_action( 'wp_head', 'node_body_visibility_fallback', 2 );

/**
 * -------------------------------------------------------
 * 8. Script Async & Module Loading (TBT Optimization)
 * -------------------------------------------------------
 */
function node_script_loader_tag($tag, $handle, $src) {
    // Vite handles: node-vite-*
    // Force module script so browser can parse `import/export` bundles.
    if (strpos($handle, 'node-vite-') === 0) {
        if (strpos($tag, 'type="module"') === false) {
            $tag = str_replace('<script ', '<script type="module" crossorigin ', $tag);
        }
    }
    return $tag;
}
add_filter('script_loader_tag', 'node_script_loader_tag', 10, 3);

/**
 * -------------------------------------------------------
 * 9. Payload Cleanup (Remove emojis, global-styles, etc.)
 * -------------------------------------------------------
 */
require_once NODE_THEME_DIR . '/inc/cleanup.php';

/**
 * -------------------------------------------------------
 * 10. カスタムライター情報（追加リンク枠最大5つ）
 * -------------------------------------------------------
 */
function node_user_contact_methods( $methods ) {
    $methods['discord']       = 'Discord (URL)';
    $methods['custom_link_1'] = '追加リンク 1 (URL)';
    $methods['custom_link_2'] = '追加リンク 2 (URL)';
    $methods['custom_link_3'] = '追加リンク 3 (URL)';
    $methods['custom_link_4'] = '追加リンク 4 (URL)';
    $methods['custom_link_5'] = '追加リンク 5 (URL)';
    return $methods;
}
add_filter( 'user_contactmethods', 'node_user_contact_methods' );
/**
 * -------------------------------------------------------
 * 6. FOUC対策の修正 — オレンジフラッシュ除去 & PageSpeed最適化
 * -------------------------------------------------------
 * 旧実装: html bg=#f90 + body opacity:0 → JS で is-loaded 付与
 * 問題点: JS実行までオレンジ色しか表示されず、LCP を著しく遅延させていた
 * 新実装: html/body を最初から表示。アニメーション演出は is-loaded で行う（任意）
 */
function node_critical_inline_styles() {
    // フロントエンド: 最優先でレンダリングブロックを解除
    if ( ! is_admin() ) {
        // 背景色は @media screen 限定にする（印刷時に _print.css の白背景を潰さないため）
        echo '<style id="node-critical-fouc-fix">
            @media screen {
                html {
                    background-color: #FFF4E5 !important;
                }
                html[data-theme="dark"],
                body[data-theme="dark"] ~ html,
                [data-theme="dark"] {
                    background-color: #1B1812 !important;
                }
            }
            body {
                opacity: 1 !important;
                visibility: visible !important;
            }
        </style>';
    }
}
add_action( 'wp_head', 'node_critical_inline_styles', 1 );

/**
 * 管理画面 / エディタ用の表示保護
 */
function node_fix_admin_visibility() {
    echo '<style>
        body.wp-admin {
            opacity: 1 !important;
            visibility: visible !important;
            background-color: #f1f1f1 !important;
        }
        .editor-styles-wrapper {
            opacity: 1 !important;
            visibility: visible !important;
            background-color: #ffffff !important;
        }
        html.wp-toolbar {
            background-color: #f1f1f1 !important;
        }
    </style>';
}
add_action( 'admin_head', 'node_fix_admin_visibility' );
add_action( 'enqueue_block_editor_assets', 'node_fix_admin_visibility' );

/**
 * 記事タイトルカードに表示する手動更新日。
 */
function node_sanitize_manual_modified_date( $value ) {
	$value = sanitize_text_field( (string) $value );

	if ( '' === $value ) {
		return '';
	}

	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
		return '';
	}

	return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ? $value : '';
}

function node_register_manual_modified_date_meta() {
	register_post_meta(
		'post',
		'_node_manual_modified_date',
		array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'node_sanitize_manual_modified_date',
			'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', (int) $post_id );
			},
		)
	);
}
add_action( 'init', 'node_register_manual_modified_date_meta' );

function node_maybe_auto_record_manual_modified_date( $post_id, $post, $is_update ) {
	if ( ! $is_update || ! $post instanceof WP_Post ) {
		return;
	}

	if ( 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return;
	}

	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	$timezone     = wp_timezone();
	$published_at = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $post->post_date, $timezone );

	if ( ! $published_at ) {
		return;
	}

	$now = new DateTimeImmutable( 'now', $timezone );

	// 公開から3時間経過で追記日を記録できる（訂正などを当日中に反映するため）
	if ( $now->getTimestamp() - $published_at->getTimestamp() < 3 * HOUR_IN_SECONDS ) {
		return;
	}

	$auto_date    = $now->format( 'Y-m-d' );
	$current_date = get_post_meta( $post_id, '_node_manual_modified_date', true );

	if ( $current_date !== $auto_date ) {
		update_post_meta( $post_id, '_node_manual_modified_date', $auto_date );
	}
}

function node_auto_record_manual_modified_date_on_save( $post_id, $post, $update ) {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	node_maybe_auto_record_manual_modified_date( $post_id, $post, $update );
}
add_action( 'save_post_post', 'node_auto_record_manual_modified_date_on_save', 20, 3 );

function node_auto_record_manual_modified_date_after_rest_save( $post, $request, $creating ) {
	node_maybe_auto_record_manual_modified_date( $post->ID, $post, ! $creating );
}
add_action( 'rest_after_insert_post', 'node_auto_record_manual_modified_date_after_rest_save', 20, 3 );

function node_enqueue_manual_modified_date_editor_assets() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'post' !== $screen->post_type ) {
		return;
	}

	wp_register_script(
		'node-manual-modified-date-editor',
		false,
		array( 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-notices', 'wp-plugins' ),
		NODE_THEME_VERSION,
		true
	);
	wp_enqueue_script( 'node-manual-modified-date-editor' );

	$script = <<<'JS'
(function (wp) {
	if (!wp || !wp.plugins || !wp.editPost || !wp.element || !wp.data || !wp.components) {
		return;
	}

	var createElement = wp.element.createElement;
	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var TextControl = wp.components.TextControl;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;

	function NodeManualModifiedDatePanel() {
		var postType = useSelect(function (select) {
			return select('core/editor').getCurrentPostType();
		}, []);
		var postDate = useSelect(function (select) {
			return select('core/editor').getEditedPostAttribute('date');
		}, []);
		var meta = useSelect(function (select) {
			return select('core/editor').getEditedPostAttribute('meta') || {};
		}, []);
		var isSavingPost = useSelect(function (select) {
			return select('core/editor').isSavingPost();
		}, []);
		var isAutosavingPost = useSelect(function (select) {
			return select('core/editor').isAutosavingPost();
		}, []);
		var editPost = useDispatch('core/editor').editPost;
		var createSuccessNotice = useDispatch('core/notices').createSuccessNotice;
		var value = meta._node_manual_modified_date || '';
		var publishedAt = postDate ? new Date(postDate) : null;
		var publishedTime = publishedAt && !isNaN(publishedAt.getTime()) ? publishedAt.getTime() : 0;
		var canSetManualDate = publishedTime > 0 && Date.now() - publishedTime >= 3 * 60 * 60 * 1000;
		var minDate = '';
		var wasSaving = useRef(false);
		var valueBeforeSave = useRef(value);

		function setManualDate(nextValue) {
			editPost({
				meta: Object.assign({}, meta, {
					_node_manual_modified_date: nextValue || ''
				})
			});
		}

		if (publishedTime > 0) {
			var nextDate = new Date(publishedTime);
			minDate = [
				nextDate.getFullYear(),
				String(nextDate.getMonth() + 1).padStart(2, '0'),
				String(nextDate.getDate()).padStart(2, '0')
			].join('-');
		}

		useEffect(function () {
			var isManualSave = isSavingPost && !isAutosavingPost;

			if (isManualSave && !wasSaving.current) {
				valueBeforeSave.current = value;
			}

			if (wasSaving.current && !isManualSave && canSetManualDate && value && value !== valueBeforeSave.current) {
				createSuccessNotice('追記日が自動的に記録されました', {
					type: 'snackbar',
					id: 'node-manual-modified-date-auto-recorded'
				});
			}

			wasSaving.current = isManualSave;
		}, [isSavingPost, isAutosavingPost, canSetManualDate, value]);

		if (postType !== 'post') {
			return null;
		}

		return createElement(
			PluginDocumentSettingPanel,
			{
				name: 'node-manual-modified-date',
				title: '更新日表示',
				className: 'node-manual-modified-date-panel'
			},
			!canSetManualDate ? createElement(
				Notice,
				{
					status: 'info',
					isDismissible: false
				},
				'公開から3時間後に設定できます。'
			) : null,
			createElement(TextControl, {
				label: '記事タイトルカードの更新日',
				type: 'date',
				value: value,
				min: minDate,
				disabled: !canSetManualDate,
				onChange: setManualDate,
				help: '公開から3時間後に保存すると自動で記録されます。手動で設定した追記日は公開日と同じ日でも表示されます。'
			}),
			value ? createElement(
				Button,
				{
					variant: 'link',
					isDestructive: true,
					onClick: function () {
						setManualDate('');
					}
				},
				'日付をクリア'
			) : null
		);
	}

	registerPlugin('node-manual-modified-date', {
		render: NodeManualModifiedDatePanel
	});
})(window.wp);
JS;

	wp_add_inline_script( 'node-manual-modified-date-editor', $script );
}
add_action( 'enqueue_block_editor_assets', 'node_enqueue_manual_modified_date_editor_assets' );

/**
 * -------------------------------------------------------
 * 11. 投稿保存時のデフォルトステータス制御
 * -------------------------------------------------------
 * 新規投稿や下書き系からの保存時は「レビュー待ち」に固定する。
 * 公開済み投稿の更新には影響を与えない。
 */
function node_is_auto_draft_placeholder_title( $title ) {
    $normalized_title = trim( wp_strip_all_tags( (string) $title ) );
    return in_array( $normalized_title, array( 'Auto Draft', '自動下書き' ), true );
}

function node_force_default_post_status_on_save( $data, $postarr ) {
    if ( ! isset( $data['post_type'] ) || 'post' !== $data['post_type'] ) {
        return $data;
    }

    if ( isset( $data['post_title'] ) && node_is_auto_draft_placeholder_title( $data['post_title'] ) ) {
        $data['post_title'] = '';
    }

    $incoming_status = $data['post_status'] ?? '';
    if ( 'auto-draft' === $incoming_status ) {
        return $data;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return $data;
    }

    if ( isset( $postarr['ID'] ) && wp_is_post_revision( (int) $postarr['ID'] ) ) {
        return $data;
    }

    // 新規作成時や下書き保存時のみ「レビュー待ち（pending）」に強制する
    // publish（公開）等のその他のステータス変更には干渉せず、WordPressコアの権限チェックに委ねる
    if ( in_array( $incoming_status, array( '', 'draft' ), true ) ) {
        $data['post_status'] = 'pending';
    }

    return $data;
}
add_filter( 'wp_insert_post_data', 'node_force_default_post_status_on_save', 99, 2 );

/**
 * RSS GUID を常に正規パーマリンクにそろえる。
 * これにより localhost / staging / 一時ドメイン由来の GUID 残存を防ぐ。
 */
function node_normalize_feed_guid( $guid, $post_id ) {
    if ( ! is_feed() ) {
        return $guid;
    }

    $post_id = absint( $post_id );
    if ( $post_id <= 0 ) {
        return $guid;
    }

    $permalink = get_permalink( $post_id );
    if ( ! $permalink ) {
        return $guid;
    }

    return $permalink;
}
add_filter( 'the_guid', 'node_normalize_feed_guid', 10, 2 );

/**
 * フッターメニュー内に残っている仮URLを正規URLへ補正する。
 */
function node_fix_footer_menu_placeholder_urls( $items, $args ) {
    if ( empty( $args->theme_location ) || 'footer' !== $args->theme_location ) {
        return $items;
    }

    $privacy_url = function_exists( 'node_get_existing_page_url' ) ? node_get_existing_page_url( '/privacy-policy/' ) : '';
    $contact_url = function_exists( 'node_get_existing_page_url' ) ? node_get_existing_page_url( '/contact/' ) : '';

    foreach ( $items as $item_index => $item ) {
        if ( ! isset( $item->url ) ) {
            continue;
        }

        $item_title = isset( $item->title ) ? wp_strip_all_tags( (string) $item->title ) : '';
        $is_privacy_placeholder = false !== strpos( $item->url, '/sample-page/' )
            || false !== strpos( $item->url, '/sample-page-2/' );
        $is_contact_placeholder = false !== strpos( $item->url, '/post-0-2/' )
            || false !== strpos( $item->url, '/%e3%81%8a%e5%95%8f%e3%81%84%e5%90%88%e3%82%8f%e3%81%9b/' );

        if ( $is_privacy_placeholder
            || false !== strpos( $item_title, 'プライバシーポリシー' )
        ) {
            if ( '' !== $privacy_url ) {
                $item->url = $privacy_url;
            } elseif ( $is_privacy_placeholder ) {
                unset( $items[ $item_index ] );
            }
            continue;
        }

        if ( $is_contact_placeholder
            || false !== strpos( $item_title, 'お問い合わせ' )
        ) {
            if ( '' !== $contact_url ) {
                $item->url = $contact_url;
            } elseif ( $is_contact_placeholder ) {
                unset( $items[ $item_index ] );
            }
        }
    }

    return array_values( $items );
}
add_filter( 'wp_nav_menu_objects', 'node_fix_footer_menu_placeholder_urls', 10, 2 );

/**
 * Aboutページの公開文面を正規表記へ補正する。
 * ※ 固定ページ本文が管理画面で未更新でも、公開画面では適切な文面を表示する。
 */
function node_normalize_about_page_content( $content ) {
    if ( is_admin() || ! is_page() ) {
        return $content;
    }

    $about_page = get_page_by_path( 'about' );
    if ( ! $about_page || get_queried_object_id() !== (int) $about_page->ID ) {
        return $content;
    }

    $keep_first_paragraph = static function( string $html, string $text ): string {
        $pattern = '#<p>\s*' . preg_quote( $text, '#' ) . '\s*</p>#u';
        $seen    = 0;
        return (string) preg_replace_callback(
            $pattern,
            static function( $matches ) use ( &$seen ) {
                $seen++;
                return 1 === $seen ? $matches[0] : '';
            },
            $html
        );
    };

    $duplicate_targets = array(
        'Luminous Coreは、ガジェット、ゲーム、Webサービス、AIに関する最新情報や商品レビューなどをお伝えするブログメディアです。',
        'ブログのロゴはガジェット、ゲーム、AI・Webサービスを構成する３つの光が交差し、その交点を表す部分を切り取り、作成したものです。',
        'ブログ名のLuminous Coreはこれらを構成するイメージカラーから着想を経て、命名したものであり、赤が「ゲーム」、青が「ガジェット」、緑が「Webサービス・AI」を司ることを意味しています。',
    );
    foreach ( $duplicate_targets as $target ) {
        $content = $keep_first_paragraph( $content, $target );
    }

    $replacements = array(
        'wingzone94を中心とする複数のメンバーで複製され、日々、記事制作に取り組んでいます。'
            => 'wingzone94を中心とする複数のメンバーで構成され、日々、記事制作に取り組んでいます。',
        '<strong>2026年5月下旬</strong>：正式サービス開始（予定）Luminous Coreは、ガジェット、ゲーム、Webサービス、AIに関する最新情報や商品レビューなどをお伝えするブログメディアです。'
            => '<strong>2026年5月下旬</strong>：正式サービス開始（予定）。Luminous Coreは、ガジェット、ゲーム、Webサービス、AIに関する最新情報や商品レビューなどをお伝えするブログメディアです。',
        '略称（才能）の響きが自分たちのスタイルに対して少し主張が強すぎると感じたため、'
            => '旧ブログ名の略称の響きが自分たちのスタイルに対して少し主張が強すぎると感じたため、',
        'Server：Conoha Wing'
            => 'Server: Conoha Wing',
        'Server：'
            => 'Server: ',
    );
    $content = str_replace( array_keys( $replacements ), array_values( $replacements ), $content );

    $content = (string) preg_replace(
        '#<td>\s*X(?:\s*<br\s*/?>\s*Threads)?\s*</td>#u',
        '<td><a href="https://x.com/LuminousCoreJP" target="_blank" rel="noopener noreferrer">X</a></td>',
        $content
    );

    // 「Threads」を案内文や運営情報表から完全に除去する。
    $content = str_replace(
        array( '公式SNS：X / Threads', '公式SNS: X / Threads', 'X / Threads', 'Threads' ),
        array( '公式SNS：X', '公式SNS: X', 'X', '' ),
        $content
    );

    return $content;
}
add_filter( 'the_content', 'node_normalize_about_page_content', 30 );

/**
 * Keep WordPress footnotes at the bottom of the currently displayed page.
 *
 * Split posts can otherwise show the generated footnote list before later pages.
 * The inline references stay in place, so tooltip-style UI can be layered on top
 * while the canonical bottom footnotes remain available for every page.
 */
function node_get_footnote_multipage_url( $page_number ) {
    $link = _wp_link_page( max( 1, (int) $page_number ) );

    if ( preg_match( '/href=(["\'])(.*?)\1/', $link, $match ) ) {
        return html_entity_decode( $match[2], ENT_QUOTES, get_bloginfo( 'charset' ) );
    }

    return get_permalink();
}

function node_extract_footnote_references_from_html( $html ) {
    $result = array(
        'ids'     => array(),
        'numbers' => array(),
    );

    if ( ! is_string( $html ) || '' === $html ) {
        return $result;
    }

    if ( ! preg_match_all( '#<sup\b(?=[^>]*\bdata-fn=(["\'])([^"\']+)\1)[^>]*>.*?</sup>#is', $html, $references, PREG_SET_ORDER ) ) {
        return $result;
    }

    foreach ( $references as $reference ) {
        $id = html_entity_decode( $reference[2], ENT_QUOTES, get_bloginfo( 'charset' ) );

        if ( ! in_array( $id, $result['ids'], true ) ) {
            $result['ids'][] = $id;
        }

        if ( preg_match( '#<a\b[^>]*>(\d+)</a>#is', $reference[0], $number_match ) ) {
            $result['numbers'][ $id ] = (int) $number_match[1];
        }
    }

    return $result;
}

function node_render_footnote_section( $groups, $current_page, $total_pages ) {
    $groups = array_filter(
        $groups,
        static function ( $group ) {
            return ! empty( $group['items'] );
        }
    );

    if ( empty( $groups ) ) {
        return '';
    }

    ksort( $groups, SORT_NUMERIC );

    $post_id       = get_the_ID();
    $section_id    = 'node-footnotes-' . $post_id;
    $current_count = isset( $groups[ (int) $current_page ]['count'] )
        ? (int) $groups[ (int) $current_page ]['count']
        : (int) reset( $groups )['count'];
    $article_count = array_sum( array_map( 'intval', array_column( $groups, 'count' ) ) );
    $is_tabbed     = count( $groups ) > 1;
    $classes       = 'node-footnotes ' . ( $is_tabbed ? 'node-footnotes--tabs' : 'node-footnotes--single' );
    $attributes    = ' data-node-footnotes' . ( $is_tabbed ? ' data-node-footnote-tabs' : '' );
    $info_id       = $section_id . '-info';

    $html  = sprintf( '<section class="%1$s"%2$s aria-label="脚注">', esc_attr( $classes ), $attributes );
    $html .= '<div class="node-footnotes__header">';
    $html .= '<span class="node-footnotes__info-label">脚注について</span>';
    $html .= sprintf(
        '<button class="node-footnotes__info-toggle" type="button" aria-label="脚注の説明を表示" aria-expanded="false" aria-controls="%1$s" data-footnote-info-toggle><span class="material-symbols-outlined" aria-hidden="true">info</span></button>',
        esc_attr( $info_id )
    );
    $html .= sprintf(
        '<span class="node-footnotes__info-panel" id="%1$s" hidden>脚注は本文の補足です。番号で切替、↩︎で戻ります。</span>',
        esc_attr( $info_id )
    );
    $html .= sprintf(
        '<div class="node-footnotes__meta" data-footnote-count="%1$d" data-footnote-total-count="%2$d"><span class="node-footnotes__meta-desktop">このページの脚注：%1$d件 / 記事全体：%2$d件</span><span class="node-footnotes__meta-mobile">ページ %1$d件 / 全体 %2$d件</span></div>',
        $current_count,
        $article_count
    );
    $html .= '</div>';

    if ( $is_tabbed ) {
        $html .= '<div class="node-footnotes__tabs" role="tablist" aria-label="脚注のページ">';
        foreach ( $groups as $page_number => $group ) {
            $is_current = (int) $page_number === (int) $current_page;
            $tab_id     = sprintf( '%1$s-tab-%2$d', $section_id, (int) $page_number );
            $panel_id   = sprintf( '%1$s-panel-%2$d', $section_id, (int) $page_number );
            $label      = (string) (int) $page_number;
            $aria_label = $is_current ? sprintf( '%dページ 現在', (int) $page_number ) : sprintf( '%dページ', (int) $page_number );

            $html .= sprintf(
                '<button class="node-footnotes__tab" id="%1$s" type="button" role="tab" aria-selected="%2$s" aria-controls="%3$s" tabindex="%4$s" aria-label="%5$s" data-footnote-tab="%6$d" data-footnote-count="%7$d"><span class="node-footnotes__tab-label">%8$s</span></button>',
                esc_attr( $tab_id ),
                $is_current ? 'true' : 'false',
                esc_attr( $panel_id ),
                $is_current ? '0' : '-1',
                esc_attr( $aria_label ),
                (int) $page_number,
                (int) $group['count'],
                esc_html( $label )
            );
        }
        $html .= '</div>';
    }

    $html .= '<div class="node-footnotes__panels">';
    foreach ( $groups as $page_number => $group ) {
        $is_current = (int) $page_number === (int) $current_page;
        $tab_id     = sprintf( '%1$s-tab-%2$d', $section_id, (int) $page_number );
        $panel_id   = sprintf( '%1$s-panel-%2$d', $section_id, (int) $page_number );
        $start_attr = $group['first_number'] > 1 ? ' start="' . esc_attr( (string) $group['first_number'] ) . '"' : '';
        $panel_attr = $is_tabbed
            ? sprintf(
                ' id="%1$s" role="tabpanel" aria-labelledby="%2$s" data-footnote-panel="%3$d"%4$s',
                esc_attr( $panel_id ),
                esc_attr( $tab_id ),
                (int) $page_number,
                $is_current ? '' : ' hidden'
            )
            : '';
        $list_class = 'wp-block-footnotes node-current-page-footnotes';

        if ( ! $is_current ) {
            $list_class .= ' node-other-page-footnotes';
        }

        $html .= sprintf( '<div class="node-footnotes__panel"%s>', $panel_attr );
        $html .= sprintf(
            '<ol class="%1$s" data-footnote-page="%2$d"%3$s>%4$s</ol>',
            esc_attr( $list_class ),
            (int) $page_number,
            $start_attr,
            implode( '', $group['items'] )
        );
        $html .= '</div>';
    }
    $html .= '</div></section>';

    return $html;
}

function node_reposition_current_page_footnotes( $content ) {
    if ( is_admin() || is_feed() || ! is_singular() || false === strpos( $content, 'data-fn=' ) ) {
        return $content;
    }

    if ( false !== strpos( $content, 'node-current-page-footnotes' ) ) {
        return $content;
    }

    $existing_footnotes = array();
    $content_without_footnotes = preg_replace_callback(
        '#<ol\b[^>]*\bclass=(["\'])[^"\']*\bwp-block-footnotes\b[^"\']*\1[^>]*>.*?</ol>#is',
        static function ( $matches ) use ( &$existing_footnotes ) {
            if ( preg_match_all( '#<li\b[^>]*\bid=(["\'])([^"\']+)\1[^>]*>(.*?)</li>#is', $matches[0], $items, PREG_SET_ORDER ) ) {
                foreach ( $items as $item ) {
                    $existing_footnotes[ html_entity_decode( $item[2], ENT_QUOTES, get_bloginfo( 'charset' ) ) ] = $item[3];
                }
            }

            return '';
        },
        $content
    );

    if ( ! is_string( $content_without_footnotes ) ) {
        return $content;
    }

    $current_references = node_extract_footnote_references_from_html( $content_without_footnotes );

    if ( empty( $current_references['ids'] ) ) {
        return $content_without_footnotes;
    }

    $footnotes = array();
    $raw_meta = get_post_meta( get_the_ID(), 'footnotes', true );
    if ( is_string( $raw_meta ) && '' !== $raw_meta ) {
        $decoded_meta = json_decode( $raw_meta, true );
        if ( is_array( $decoded_meta ) ) {
            foreach ( $decoded_meta as $footnote ) {
                if ( ! empty( $footnote['id'] ) && isset( $footnote['content'] ) ) {
                    $footnotes[ (string) $footnote['id'] ] = (string) $footnote['content'];
                }
            }
        }
    }

    $footnotes = array_merge( $existing_footnotes, $footnotes );

    global $page, $numpages;
    $current_page = max( 1, (int) $page );
    $total_pages  = max( 1, (int) $numpages );
    $page_refs    = array();

    if ( $total_pages > 1 ) {
        $raw_post_content = get_post_field( 'post_content', get_the_ID() );
        $raw_pages        = is_string( $raw_post_content ) ? preg_split( '/<!--nextpage-->/', $raw_post_content ) : array();

        if ( is_array( $raw_pages ) && count( $raw_pages ) > 1 ) {
            foreach ( $raw_pages as $index => $raw_page_content ) {
                $page_refs[ $index + 1 ] = node_extract_footnote_references_from_html( $raw_page_content );
            }
        }
    }

    if ( empty( $page_refs[ $current_page ]['ids'] ) ) {
        $page_refs[ $current_page ] = $current_references;
    }

    $groups = array();
    foreach ( $page_refs as $page_number => $references ) {
        if ( empty( $references['ids'] ) ) {
            continue;
        }

        $items = array();
        foreach ( $references['ids'] as $id ) {
            if ( ! isset( $footnotes[ $id ] ) ) {
                continue;
            }

            $number = $references['numbers'][ $id ] ?? ( count( $items ) + 1 );
            $target = sprintf( '#%s-link', $id );

            if ( (int) $page_number !== $current_page ) {
                $target = node_get_footnote_multipage_url( (int) $page_number ) . $target;
            }

            $return_link_pattern = '#\s*<a\b[^>]*href=["\']' . preg_quote( '#' . $id . '-link', '#' ) . '["\'][^>]*>.*?</a>\s*#is';
            $footnote_content = preg_replace(
                array(
                    $return_link_pattern,
                    '#\s*<a\b[^>]*aria-label=["\']脚注参照\d+にジャンプ["\'][^>]*>.*?</a>\s*#u',
                ),
                array( ' ', ' ' ),
                $footnotes[ $id ]
            );
            $footnote_content = is_string( $footnote_content ) ? trim( $footnote_content ) : $footnotes[ $id ];

            $items[] = sprintf(
                '<li id="%1$s">%2$s <a href="%3$s" aria-label="%4$s">↩︎</a></li>',
                esc_attr( $id ),
                wp_kses_post( $footnote_content ),
                esc_url( $target ),
                esc_attr( sprintf( '脚注参照%dにジャンプ', $number ) )
            );
        }

        if ( empty( $items ) ) {
            continue;
        }

        $first_id = $references['ids'][0] ?? '';
        $groups[ (int) $page_number ] = array(
            'items'        => $items,
            'count'        => count( $items ),
            'first_number' => $references['numbers'][ $first_id ] ?? 1,
        );
    }

    if ( empty( $groups ) ) {
        return $content_without_footnotes;
    }

    $footnote_section = node_render_footnote_section( $groups, $current_page, $total_pages );

    return rtrim( $content_without_footnotes ) . "\n\n" . $footnote_section;
}
add_filter( 'the_content', 'node_reposition_current_page_footnotes', 999 );

/**
 * Disable default inline HTML margin injection by the WordPress Admin Bar
 */
add_theme_support( 'admin-bar', array( 'callback' => '__return_false' ) );

/**
 * -------------------------------------------------------
 * 12. 記事表示数の制御 (トップ/アーカイブ)
 * -------------------------------------------------------
 */
function node_custom_posts_per_page( $query ) {
    if ( ! is_admin() && $query->is_main_query() ) {
        if ( is_home() || is_front_page() ) {
            $query->set( 'posts_per_page', 12 );
        } elseif ( is_archive() || is_search() || $query->is_paged() ) {
            $query->set( 'posts_per_page', 24 );
        }
    }
}
add_action( 'pre_get_posts', 'node_custom_posts_per_page' );

/**
 * -------------------------------------------------------
 * 13. 廃止した /headlines/ をニュースカテゴリへ301
 * -------------------------------------------------------
 * 独自URLは 1.3 で廃止したが、既存リンク・クローラのために転送だけ残す。
 * ページ送り（/headlines/page/2/）も対応する。
 */
function node_redirect_legacy_headlines_url() {
	if ( ! is_404() ) {
		return;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path        = node_404_redirect_relative_path( $request_uri );

	if ( ! preg_match( '#^headlines(?:/page/([0-9]{1,}))?$#', $path, $matches ) ) {
		return;
	}

	$target = node_get_headlines_url();
	if ( ! empty( $matches[1] ) && (int) $matches[1] > 1 ) {
		$target = trailingslashit( $target ) . 'page/' . (int) $matches[1] . '/';
	}

	wp_safe_redirect( node_404_redirect_preserve_query( $target, $request_uri ), 301 );
	exit;
}
add_action( 'template_redirect', 'node_redirect_legacy_headlines_url', 0 );

/**
 * -------------------------------------------------------
 * 14. SPOTLIGHT専用ページのメインクエリ制御
 * -------------------------------------------------------
 */
function node_spotlight_pre_get_posts( $query ) {
	if ( ! is_admin() && $query->is_main_query() && $query->get( 'node_spotlight' ) ) {
		// 過去特集カタログ専用ページのため、記事ループは実行しない。
		$query->set( 'post__in', array( 0 ) );
	}
}
add_action( 'pre_get_posts', 'node_spotlight_pre_get_posts' );

/**
 * 旧カテゴリアーカイブ /category/spotlight/ を専用URLへ統合する。
 */
function node_redirect_spotlight_category_archive() {
	if ( ! is_category( 'spotlight' ) ) {
		return;
	}

	wp_safe_redirect( node_get_spotlight_url(), 301 );
	exit;
}
add_action( 'template_redirect', 'node_redirect_spotlight_category_archive' );

/**
 * リクエストURIをサイトルート相対のパスへ正規化する。
 */
function node_404_redirect_relative_path( string $request_uri ): string {
	$request_path = (string) parse_url( $request_uri, PHP_URL_PATH );
	$home_path    = trim( (string) parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	$relative     = trim( $request_path, '/' );

	if ( '' !== $home_path ) {
		if ( $relative === $home_path ) {
			$relative = '';
		} elseif ( str_starts_with( $relative, $home_path . '/' ) ) {
			$relative = substr( $relative, strlen( $home_path ) + 1 );
		}
	}

	return trim( $relative, '/' );
}

/**
 * リダイレクト先へ元リクエストのクエリ文字列を引き継ぐ。
 */
function node_404_redirect_preserve_query( string $target_url, string $request_uri ): string {
	$query_string = parse_url( $request_uri, PHP_URL_QUERY );

	if ( ! is_string( $query_string ) || '' === $query_string ) {
		$query_string = isset( $_SERVER['QUERY_STRING'] )
			? ltrim( (string) wp_unslash( $_SERVER['QUERY_STRING'] ), '?' )
			: '';
	}

	return '' === $query_string ? $target_url : $target_url . '?' . $query_string;
}

/**
 * 最終有効ページのURLを組み立てる。
 */
function node_404_redirect_page_url( string $base_path, int $max_page ): string {
	$base_url = home_url( '/' . ( '' === $base_path ? '' : trailingslashit( $base_path ) ) );

	if ( 1 === $max_page ) {
		return trailingslashit( $base_url );
	}

	return trailingslashit( trailingslashit( $base_url ) . 'page/' . $max_page );
}

/**
 * ページ送り救済の対象となるアーカイブクエリか判定する。
 */
function node_404_redirect_is_supported_archive( WP_Query $wp_query, string $base_path ): bool {
	if ( '' === $base_path ) {
		return true;
	}

	if ( '' !== (string) $wp_query->get( 's' ) || ! empty( $wp_query->get( 'tax_query' ) ) ) {
		return true;
	}

	foreach ( array( 'year', 'monthnum', 'day', 'm', 'w' ) as $date_query_var ) {
		if ( ! empty( $wp_query->get( $date_query_var ) ) ) {
			return true;
		}
	}

	foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
		if ( ! empty( $taxonomy->query_var ) && ! empty( $wp_query->get( $taxonomy->query_var ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * 404リクエストの正規リダイレクト先を解決する。
 *
 * HTTP操作は行わず、対象外の場合はnullを返す。
 */
function node_resolve_404_redirect_target( WP_Query $wp_query, string $request_uri ): ?string {
	$relative_path = node_404_redirect_relative_path( $request_uri );

	if ( preg_match( '#^(.*?)(?:^|/)page/([1-9][0-9]*)$#', $relative_path, $page_match ) ) {
		$base_path      = trim( (string) $page_match[1], '/' );
		$requested_page = (int) $page_match[2];

		if ( NODE_ALL_ARTICLES_SLUG === $base_path ) {
			$per_page         = max( 1, (int) NODE_ALL_ARTICLES_PER_PAGE );
			$total_limit      = max( 1, (int) NODE_ALL_ARTICLES_TOTAL_LIMIT );
			$total_published  = function_exists( 'node_get_total_published_posts' )
				? node_get_total_published_posts()
				: (int) wp_count_posts( 'post' )->publish;
			$total_displaying = min( max( 0, (int) $total_published ), $total_limit );
			$max_page        = max( 1, (int) ceil( $total_displaying / $per_page ) );

			if ( $requested_page > $max_page ) {
				return node_404_redirect_preserve_query(
					node_404_redirect_page_url( $base_path, $max_page ),
					$request_uri
				);
			}

			return null;
		}

		if ( ! node_404_redirect_is_supported_archive( $wp_query, $base_path ) ) {
			return null;
		}

		$query_args = $wp_query->query_vars;
		$per_page   = (int) $wp_query->get( 'posts_per_page' );

		unset(
			$query_args['attachment'],
			$query_args['attachment_id'],
			$query_args['error'],
			$query_args['name'],
			$query_args['page'],
			$query_args['paged'],
			$query_args['pagename'],
			$query_args['preview']
		);

		$query_args['fields']              = 'ids';
		$query_args['ignore_sticky_posts'] = true;
		$query_args['no_found_rows']       = false;
		$query_args['paged']               = 1;
		$query_args['posts_per_page']      = $per_page > 0 ? $per_page : ( '' === $base_path ? 12 : 24 );
		$query_args['update_post_meta_cache'] = false;
		$query_args['update_post_term_cache'] = false;

		$base_query = new WP_Query( $query_args );
		$per_page   = max( 1, (int) $base_query->get( 'posts_per_page' ) );
		$max_page   = (int) ceil( (int) $base_query->found_posts / $per_page );

		if ( $max_page > 0 && $requested_page > $max_page ) {
			return node_404_redirect_preserve_query(
				node_404_redirect_page_url( $base_path, $max_page ),
				$request_uri
			);
		}

		return null;
	}

	if ( ! preg_match( '#^(.+)/([1-9][0-9]*)$#', $relative_path, $post_page_match ) ) {
		return null;
	}

	$post_path      = trim( (string) $post_page_match[1], '/' );
	$requested_page = (int) $post_page_match[2];
	$post_id        = url_to_postid( home_url( '/' . trailingslashit( $post_path ) ) );

	if ( ! $post_id && ! str_contains( $post_path, '/' ) ) {
		$post = get_page_by_path( rawurldecode( $post_path ), OBJECT, 'post' );
	} else {
		$post = $post_id ? get_post( $post_id ) : null;
	}

	if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return null;
	}

	$nextpage_count = preg_match_all( '/<!--\s*nextpage\s*-->/i', (string) $post->post_content );

	if ( ! is_int( $nextpage_count ) || 0 === $nextpage_count || $requested_page <= ( $nextpage_count + 1 ) ) {
		return null;
	}

	return node_404_redirect_preserve_query( get_permalink( $post ), $request_uri );
}

/**
 * 404時だけ正規URLへ301リダイレクトする薄いフック。
 */
function node_handle_404_redirect(): void {
	if ( ! is_404() ) {
		return;
	}

	global $wp_query;

	if ( ! $wp_query instanceof WP_Query ) {
		return;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$target_url  = node_resolve_404_redirect_target( $wp_query, $request_uri );

	if ( null === $target_url ) {
		return;
	}

	wp_safe_redirect( $target_url, 301 );
	exit;
}
add_action( 'template_redirect', 'node_handle_404_redirect', 1 );

/**
 * 404レスポンスを検索インデックス対象外にする。
 *
 * @param array<string, bool|string> $robots robotsディレクティブ。
 * @return array<string, bool|string>
 */
function node_robots_noindex_404( array $robots ): array {
	if ( is_404() ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
	}

	return $robots;
}
add_filter( 'wp_robots', 'node_robots_noindex_404' );
