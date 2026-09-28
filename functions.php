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
 * 9. Payload Cleanup (Remove emojis, global-styles, etc.)
 * -------------------------------------------------------
 */

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
 * Disable default inline HTML margin injection by the WordPress Admin Bar
 */
add_theme_support( 'admin-bar', array( 'callback' => '__return_false' ) );


