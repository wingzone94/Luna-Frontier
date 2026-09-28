<?php

declare(strict_types=1);
/**
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
