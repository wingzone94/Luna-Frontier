<?php

declare(strict_types=1);
/**
 * Archive page context helpers (category, tag, author, date, taxonomy).
 *
 * @package Node
 */

/**
 * Material icon for a category archive header.
 *
 * @param WP_Term|null $term Category term.
 * @return string Material Symbols icon name.
 */
function node_get_category_archive_icon( $term ) {
	return 'folder';
}

/**
 * Material icon for a tag archive header.
 *
 * @param WP_Term|null $term Tag term.
 * @return string Material Symbols icon name.
 */
function node_get_tag_archive_icon( $term ) {
	return 'sell';
}

/**
 * Build archive header context for the current query.
 *
 * @return array<string, mixed>
 */
function node_get_archive_context() {
	global $wp_query;

	$context = array(
		'type'         => 'archive',
		'title'        => '',
		'subtitle'     => __( 'アーカイブ', 'node' ),
		'icon'         => 'folder',
		'description'  => '',
		'count'        => isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0,
		'accent_color' => '',
		'header_id'    => 'archive-title',
	);

	if ( is_category() ) {
		$term = get_queried_object();
		$context['type']        = 'category';
		$context['title']       = single_cat_title( '', false );
		$context['subtitle']    = __( 'カテゴリ', 'node' );
		$context['icon']        = node_get_category_archive_icon( $term );
		$context['description'] = $term ? term_description( $term->term_id, 'category' ) : '';
		if ( $term && function_exists( 'node_get_category_label_props' ) ) {
			$context['label_props']  = node_get_category_label_props( $term );
			$context['accent_color'] = $context['label_props']['color'];
		}
	} elseif ( is_tag() ) {
		$term = get_queried_object();
		$context['type']        = 'tag';
		$context['title']       = single_tag_title( '', false );
		$context['subtitle']    = __( 'タグ', 'node' );
		$context['icon']        = node_get_tag_archive_icon( $term );
		$context['description'] = $term ? term_description( $term->term_id, 'post_tag' ) : '';
	} elseif ( is_author() ) {
		$author_id = get_queried_object_id();
		$context['type']     = 'author';
		$context['title']    = get_the_author();
		$context['subtitle'] = __( '著者', 'node' );
		$context['icon']     = 'person';
		$bio                 = get_the_author_meta( 'description', $author_id );
		if ( $bio ) {
			$context['description'] = wp_kses_post( $bio );
		}
	} elseif ( is_year() ) {
		$context['type']     = 'date';
		$context['title']    = get_the_date( 'Y年' );
		$context['subtitle'] = __( '年別アーカイブ', 'node' );
		$context['icon']     = 'folder';
	} elseif ( is_month() ) {
		$context['type']     = 'date';
		$context['title']    = get_the_date( 'Y年n月' );
		$context['subtitle'] = __( '月別アーカイブ', 'node' );
		$context['icon']     = 'folder';
	} elseif ( is_day() ) {
		$context['type']     = 'date';
		$context['title']    = get_the_date( 'Y年n月j日' );
		$context['subtitle'] = __( '日別アーカイブ', 'node' );
		$context['icon']     = 'folder';
	} elseif ( is_tax() ) {
		$term = get_queried_object();
		$context['type']        = 'taxonomy';
		$context['title']       = single_term_title( '', false );
		$context['subtitle']    = __( 'アーカイブ', 'node' );
		$context['icon']        = 'folder';
		$context['description'] = term_description();
		if ( $term && isset( $term->term_id ) && function_exists( 'node_get_category_label_props' ) ) {
			$context['label_props']  = node_get_category_label_props( $term );
			$context['accent_color'] = $context['label_props']['color'];
		}
	} elseif ( is_post_type_archive() ) {
		$context['type']     = 'post_type';
		$context['title']    = post_type_archive_title( '', false );
		$context['subtitle'] = __( 'アーカイブ', 'node' );
		$context['icon']     = 'folder';
	} elseif ( function_exists( 'node_get_archive_title' ) ) {
		$context['title'] = node_get_archive_title();
	}

	return $context;
}

/**
 * Filter the Luna Library archive by its saved content type.
 *
 * @param WP_Query $query Main query.
 * @return void
 */
function node_filter_library_archive_by_type( $query ) {
	$is_library_archive = $query->is_post_type_archive( 'node_library' ) || 'node_library' === $query->get( 'post_type' );
	if ( is_admin() || ! $query->is_main_query() || ! $is_library_archive ) {
		return;
	}

	$type = sanitize_key( (string) get_query_var( 'node_library_type' ) );
	if ( ! $type && isset( $_GET['type'] ) ) {
		$type = sanitize_key( wp_unslash( $_GET['type'] ) );
	}
	if ( ! in_array( $type, array( 'game', 'app' ), true ) ) {
		return;
	}

	$query->set(
		'meta_query',
		array(
			array(
				'key'   => '_node_library_type',
				'value' => $type,
			),
		)
	);
}
add_action( 'pre_get_posts', 'node_filter_library_archive_by_type' );

/**
 * Archive header mark style (same tokens as category labels).
 *
 * @param array<string, mixed> $context Archive context.
 * @return string
 */
function node_get_archive_mark_style( $context ) {
	if ( ! empty( $context['label_props'] ) && function_exists( 'node_get_category_label_style' ) ) {
		return node_get_category_label_style( $context['label_props'] );
	}

	$accent = ! empty( $context['accent_color'] ) ? $context['accent_color'] : '#FF9900';
	$on     = function_exists( 'node_get_readable_text_color' )
		? node_get_readable_text_color( $accent )
		: '#ffffff';

	return sprintf(
		'--category-color:%1$s;--category-on-color:%2$s;--m3-archive-accent:%1$s;',
		esc_attr( $accent ),
		esc_attr( $on )
	);
}

/**
 * Published-post counts grouped by year/month (for date archive navigation).
 *
 * @return array<int, array<int, int>> [ year => [ month => count ] ]
 */
function node_get_date_archive_map() {
	static $map = null;

	if ( null !== $map ) {
		return $map;
	}

	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, COUNT(ID) AS c
		 FROM {$wpdb->posts}
		 WHERE post_type = 'post' AND post_status = 'publish'
		 GROUP BY y, m
		 ORDER BY y ASC, m ASC"
	);

	$map = array();
	foreach ( (array) $rows as $row ) {
		$map[ (int) $row->y ][ (int) $row->m ] = (int) $row->c;
	}

	return $map;
}

/**
 * Year/month/day currently queried on a date archive.
 *
 * @return array{year:int, month:int, day:int}
 */
function node_get_queried_date_archive() {
	$year  = (int) get_query_var( 'year' );
	$month = (int) get_query_var( 'monthnum' );
	$day   = (int) get_query_var( 'day' );

	// Fallback for compact date queries (?m=YYYYMM[DD]).
	$compact = (string) get_query_var( 'm' );
	if ( ! $year && strlen( $compact ) >= 4 ) {
		$year  = (int) substr( $compact, 0, 4 );
		$month = strlen( $compact ) >= 6 ? (int) substr( $compact, 4, 2 ) : $month;
		$day   = strlen( $compact ) >= 8 ? (int) substr( $compact, 6, 2 ) : $day;
	}

	return array(
		'year'  => $year,
		'month' => $month,
		'day'   => $day,
	);
}

/**
 * Adjacent year that has published posts.
 *
 * @param int $year Base year.
 * @param int $dir  -1 for previous (older), 1 for next (newer).
 * @return array{year:int, count:int}|null
 */
function node_get_adjacent_archive_year( $year, $dir ) {
	$map   = node_get_date_archive_map();
	$years = array_keys( $map );

	if ( $dir < 0 ) {
		$years = array_reverse( $years );
	}

	foreach ( $years as $candidate ) {
		if ( ( $dir < 0 && $candidate < $year ) || ( $dir > 0 && $candidate > $year ) ) {
			return array(
				'year'  => $candidate,
				'count' => array_sum( $map[ $candidate ] ),
			);
		}
	}

	return null;
}

/**
 * Adjacent month that has published posts (crosses year boundaries).
 *
 * @param int $year  Base year.
 * @param int $month Base month.
 * @param int $dir   -1 for previous (older), 1 for next (newer).
 * @return array{year:int, month:int, count:int}|null
 */
function node_get_adjacent_archive_month( $year, $month, $dir ) {
	$map  = node_get_date_archive_map();
	$flat = array();

	foreach ( $map as $y => $months ) {
		foreach ( $months as $m => $count ) {
			$flat[] = array(
				'year'  => $y,
				'month' => $m,
				'count' => $count,
			);
		}
	}

	if ( $dir < 0 ) {
		$flat = array_reverse( $flat );
	}

	$base = $year * 100 + $month;
	foreach ( $flat as $candidate ) {
		$key = $candidate['year'] * 100 + $candidate['month'];
		if ( ( $dir < 0 && $key < $base ) || ( $dir > 0 && $key > $base ) ) {
			return $candidate;
		}
	}

	return null;
}

/** Keep article archives and search in a stable date order across pages. */
function luna_frontier_sort_article_results( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ( ! $query->is_archive() && ! $query->is_search() ) || $query->is_post_type_archive( 'node_library' ) ) {
		return;
	}
	$key = $query->is_search() ? 'm3_sort' : 'sort';
	$value = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
	$descending = $query->is_search() ? 'newest' === $value : 'date-desc' === $value;
	$order = $descending ? 'DESC' : 'ASC';
	$query->set( 'orderby', array( 'date' => $order, 'ID' => $order ) );
	$query->set( 'order', $order );
	$query->set( 'ignore_sticky_posts', true );
}
add_action( 'pre_get_posts', 'luna_frontier_sort_article_results', 1000 );

/** Use the current result pages for infinite scrolling on article listings. */
function luna_frontier_enqueue_archive_flow() {
	if ( ( ! is_archive() && ! is_search() ) || is_post_type_archive( 'node_library' ) ) {
		return;
	}
	$path = get_theme_file_path( 'assets/js/luna-archive-flow.js' );
	if ( ! is_file( $path ) ) {
		return;
	}
	wp_dequeue_script( 'luna-flow-scroller' );
	wp_dequeue_script( 'node-flow-scroller' );
	wp_enqueue_script( 'luna-archive-flow', get_theme_file_uri( 'assets/js/luna-archive-flow.js' ), array(), (string) filemtime( $path ), true );
	$css_path = get_theme_file_path( 'assets/css/luna-archive-flow.css' );
	if ( is_file( $css_path ) ) {
		wp_enqueue_style( 'luna-archive-flow', get_theme_file_uri( 'assets/css/luna-archive-flow.css' ), array(), (string) filemtime( $css_path ) );
	}
}
add_action( 'wp_enqueue_scripts', 'luna_frontier_enqueue_archive_flow', 1000 );

/** Preserve other GET filters when submitting a result sort form. */
function luna_frontier_sort_hidden_fields( $excluded ) {
	$render = static function ( $name, $value ) use ( &$render ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$render( $name . '[' . $key . ']', $child );
			}
		} elseif ( is_scalar( $value ) ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( wp_unslash( (string) $value ) ) );
		}
	};
	foreach ( $_GET as $key => $value ) {
		if ( ! in_array( $key, $excluded, true ) ) {
			$render( (string) $key, $value );
		}
	}
}

/**
 * Merge default archive context with template overrides.
 *
 * @param array<string, mixed> $overrides Optional overrides.
 * @return array<string, mixed>
 */
function node_merge_archive_context( $overrides = array() ) {
	$context = node_get_archive_context();

	foreach ( $overrides as $key => $value ) {
		if ( null === $value || '' === $value ) {
			continue;
		}
		$context[ $key ] = $value;
	}

	return $context;
}
