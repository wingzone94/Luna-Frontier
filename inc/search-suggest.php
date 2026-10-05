<?php

declare(strict_types=1);
/**
 * Search keyword suggestions for the header search bar.
 *
 * @package Node
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NODE_SEARCH_SUGGEST_PER_SOURCE = 5;
const NODE_SEARCH_SUGGEST_LIMIT      = 6;

/**
 * Register the public search suggestion REST route.
 */
function node_register_search_suggest_route(): void {
	register_rest_route(
		'node/v1',
		'/search/suggest',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'node_search_suggest_response',
			'permission_callback' => '__return_true',
			'args'                => array(
				'q' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'node_register_search_suggest_route' );

/**
 * Return search keyword suggestions.
 */
function node_search_suggest_response( WP_REST_Request $request ): WP_REST_Response {
	$query = trim( (string) $request->get_param( 'q' ) );

	if ( '' === $query || mb_strlen( $query ) < 2 ) {
		return rest_ensure_response( array() );
	}

	$suggestions = array_merge(
		node_get_library_keyword_suggestions( $query ),
		node_get_category_keyword_suggestions( $query )
	);

	return rest_ensure_response( node_merge_search_keyword_suggestions( $suggestions ) );
}

/**
 * Build suggestions from Luna Library item titles.
 *
 * @return array<int, array<string, mixed>>
 */
function node_get_library_keyword_suggestions( string $query ): array {
	if ( ! post_type_exists( 'node_library' ) ) {
		return array();
	}

	$items = get_posts(
		array(
			'post_type'        => 'node_library',
			'post_status'      => 'publish',
			'posts_per_page'   => NODE_SEARCH_SUGGEST_PER_SOURCE,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			's'                => $query,
		)
	);

	$suggestions = array();
	foreach ( $items as $item ) {
		$type = (string) get_post_meta( $item->ID, '_node_library_type', true );

		$suggestions[] = array(
			'keyword'     => $item->post_title,
			'source'      => 'library',
			'sourceLabel' => 'ライブラリ',
			'icon'        => 'app' === $type ? 'smartphone' : 'sports_esports',
		);
	}

	return $suggestions;
}

/**
 * Build suggestions from category names.
 *
 * @return array<int, array<string, mixed>>
 */
function node_get_category_keyword_suggestions( string $query ): array {
	$terms = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => true,
			'number'     => NODE_SEARCH_SUGGEST_PER_SOURCE,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'search'     => $query,
		)
	);

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	$suggestions = array();
	foreach ( $terms as $term ) {
		$suggestions[] = array(
			'keyword'     => $term->name,
			'source'      => 'category',
			'sourceLabel' => 'カテゴリ',
			'icon'        => 'category',
			'count'       => (int) $term->count,
		);
	}

	return $suggestions;
}

/**
 * Merge suggestions that point to the same keyword.
 *
 * Library and category records can share the exact visible name, for example
 * "Minecraft". In that case the header should show one selectable keyword,
 * with both source labels, not two visually identical rows.
 *
 * @param array<int, array<string, mixed>> $suggestions Raw suggestions.
 * @return array<int, array<string, mixed>>
 */
function node_merge_search_keyword_suggestions( array $suggestions ): array {
	$merged = array();
	$order  = array();

	foreach ( $suggestions as $suggestion ) {
		$keyword = isset( $suggestion['keyword'] ) ? trim( (string) $suggestion['keyword'] ) : '';
		if ( '' === $keyword ) {
			continue;
		}

		$key    = node_search_suggest_keyword_key( $keyword );
		$source = isset( $suggestion['source'] ) ? sanitize_key( (string) $suggestion['source'] ) : 'search';

		if ( ! isset( $merged[ $key ] ) ) {
			$merged[ $key ]             = $suggestion;
			$merged[ $key ]['keyword']  = $keyword;
			$merged[ $key ]['_sources'] = array();
			$order[]                    = $key;
		}

		if ( ! in_array( $source, $merged[ $key ]['_sources'], true ) ) {
			$merged[ $key ]['_sources'][] = $source;
		}

		if ( 'category' === $source && isset( $suggestion['count'] ) ) {
			$merged[ $key ]['count'] = (int) $suggestion['count'];
		}
	}

	$result = array();
	foreach ( $order as $key ) {
		$item    = $merged[ $key ];
		$sources = $item['_sources'];
		unset( $item['_sources'] );

		$item['source']      = implode( '+', $sources );
		$item['sourceLabel'] = node_search_suggest_source_label( $sources );
		$result[]            = $item;

		if ( count( $result ) >= NODE_SEARCH_SUGGEST_LIMIT ) {
			break;
		}
	}

	return $result;
}

/**
 * Normalize a visible keyword for de-duplication.
 */
function node_search_suggest_keyword_key( string $keyword ): string {
	$key = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $keyword ) ) ?? $keyword );

	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $key, 'UTF-8' ) : strtolower( $key );
}

/**
 * Convert merged source keys to a compact display label.
 *
 * @param string[] $sources Source keys.
 */
function node_search_suggest_source_label( array $sources ): string {
	$labels = array();

	if ( in_array( 'library', $sources, true ) ) {
		$labels[] = 'ライブラリ';
	}

	if ( in_array( 'category', $sources, true ) ) {
		$labels[] = 'カテゴリ';
	}

	return implode( ' / ', $labels );
}
