<?php

declare(strict_types=1);
/**
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
