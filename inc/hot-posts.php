<?php

declare(strict_types=1);
/**
 * HOT posts and semi-automated HEADLINE selection.
 *
 * @package Node
 */

const NODE_HOT_SCORE_META              = '_node_hot_score_7d';
const NODE_HOT_LOCAL_SCORE_META        = '_node_hot_local_score_7d';
const NODE_HOT_PERIOD_DAYS             = 7;
const NODE_HOT_LIMIT                   = 3;

/**
 * Register post IDs already featured in the home top area.
 *
 * @param int[] $post_ids Post IDs.
 */
function node_home_register_featured_post_ids( array $post_ids ): void {
	$GLOBALS['node_home_featured_post_ids'] = array_values(
		array_unique(
			array_filter(
				array_map( 'absint', array_merge( $GLOBALS['node_home_featured_post_ids'] ?? array(), $post_ids ) )
			)
		)
	);
}

/**
 * Get post IDs already featured in the home top area.
 *
 * @return int[]
 */
function node_home_get_featured_post_ids(): array {
	return array_values( array_filter( array_map( 'absint', $GLOBALS['node_home_featured_post_ids'] ?? array() ) ) );
}

/**
 * Return recent day meta key.
 */
function node_hot_day_meta_key( int $offset = 0 ): string {
	return '_node_hot_views_' . wp_date( 'Ymd', strtotime( '-' . max( 0, $offset ) . ' days', current_time( 'timestamp' ) ) );
}

/**
 * Avoid counting obvious non-reader traffic.
 */
function node_hot_should_track_request( int $post_id ): bool {
	if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_preview() ) {
		return false;
	}

	if ( is_user_logged_in() && current_user_can( 'edit_post', $post_id ) ) {
		return false;
	}

	$user_agent = strtolower( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
	if ( '' === $user_agent ) {
		return true;
	}

	return ! preg_match( '/bot|crawl|spider|slurp|mediapartners|google-inspectiontool|lighthouse|pagespeed|pingdom|uptime|headless/i', $user_agent );
}

/**
 * Recalculate local 7-day HOT score for a post.
 */
function node_hot_recalculate_local_score( int $post_id ): int {
	$score = 0;
	for ( $i = 0; $i < NODE_HOT_PERIOD_DAYS; $i++ ) {
		$score += max( 0, (int) get_post_meta( $post_id, node_hot_day_meta_key( $i ), true ) );
	}

	for ( $i = 30; $i <= 61; $i++ ) {
		delete_post_meta( $post_id, node_hot_day_meta_key( $i ) );
	}

	update_post_meta( $post_id, NODE_HOT_LOCAL_SCORE_META, (string) $score );
	update_post_meta( $post_id, NODE_HOT_SCORE_META, (string) $score );

	return $score;
}

/**
 * Rank published posts by views within the selected calendar-day window.
 * Query daily buckets so inactive posts also age out without another visit.
 *
 * @return WP_Post[]
 */
function node_get_popular_posts_for_period( int $days = 7, int $limit = 5 ): array {
	global $wpdb;

	$days = 30 === $days ? 30 : 7;
	$keys = array();
	for ( $i = 0; $i < $days; $i++ ) {
		$keys[] = node_hot_day_meta_key( $i );
	}
	$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
	// Only daily view buckets contribute; lifetime and saved HOT scores do not.
	$sql = "SELECT p.ID FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
		WHERE p.post_type = 'post' AND p.post_status = 'publish'
		AND pm.meta_key IN ($placeholders)
		GROUP BY p.ID, p.post_date
		HAVING SUM(GREATEST(0, CAST(pm.meta_value AS SIGNED))) > 0
		ORDER BY SUM(GREATEST(0, CAST(pm.meta_value AS SIGNED))) DESC, p.post_date DESC, p.ID DESC
		LIMIT %d";
	$ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $keys, array( max( 1, $limit ) ) ) ) );
	if ( ! $ids ) {
		return array();
	}

	return get_posts( array(
		'post_type' => 'post', 'post_status' => 'publish',
		'post__in' => array_map( 'absint', $ids ), 'orderby' => 'post__in',
		'posts_per_page' => count( $ids ), 'ignore_sticky_posts' => true,
	) );
}

/**
 * Count a local fallback page view.
 */
function node_hot_track_local_view(): void {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	$post_id = get_queried_object_id();
	if ( ! $post_id || ! node_hot_should_track_request( $post_id ) ) {
		return;
	}

	$key   = node_hot_day_meta_key();
	$count = max( 0, (int) get_post_meta( $post_id, $key, true ) );
	update_post_meta( $post_id, $key, (string) ( $count + 1 ) );
	node_hot_recalculate_local_score( $post_id );
}
add_action( 'template_redirect', 'node_hot_track_local_view', 20 );

/**
 * Get HOT post IDs in display order.
 *
 * @param int   $limit   Number of posts.
 * @param int[] $exclude Excluded post IDs.
 * @return int[]
 */
function node_get_hot_post_ids( int $limit = NODE_HOT_LIMIT, array $exclude = array() ): array {
	$limit   = max( 1, $limit );
	$exclude = array_values( array_filter( array_map( 'absint', $exclude ) ) );
	$ids     = array();

	$query = new WP_Query(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit - count( $ids ),
			'post__not_in'        => array_merge( $exclude, $ids ),
			'meta_key'            => NODE_HOT_SCORE_META,
			'orderby'             => array(
				'meta_value_num' => 'DESC',
				'date'           => 'DESC',
			),
			'meta_query'          => array(
				array(
					'key'     => NODE_HOT_SCORE_META,
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'fields'              => 'ids',
		)
	);
	$ids   = array_merge( $ids, array_map( 'absint', $query->posts ) );

	if ( count( $ids ) < $limit ) {
		$fallback = new WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => $limit - count( $ids ),
				'post__not_in'        => array_merge( $exclude, $ids ),
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'fields'              => 'ids',
			)
		);
		$ids      = array_merge( $ids, array_map( 'absint', $fallback->posts ) );
	}

	return array_slice( array_values( array_unique( $ids ) ), 0, $limit );
}

/**
 * Get HOT posts in display order.
 *
 * @return WP_Post[]
 */
function node_get_hot_posts( int $limit = NODE_HOT_LIMIT, array $exclude = array() ): array {
	$ids = node_get_hot_post_ids( $limit, $exclude );
	if ( empty( $ids ) ) {
		return array();
	}

	$query = new WP_Query(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => count( $ids ),
			'post__in'            => $ids,
			'orderby'             => 'post__in',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		)
	);

	return $query->posts;
}

/**
 * Get semi-automated HEADLINE post IDs.
 *
 * @return int[]
 */
function node_get_headline_post_ids( int $limit = 5 ): array {
	$limit   = max( 1, $limit );
	$exclude = node_home_get_featured_post_ids();
	$news    = get_term_by( 'name', 'ニュース', 'category' );
	$cat_id  = $news ? (int) $news->term_id : 0;
	$ids     = array();

	$sticky = array_values( array_filter( array_map( 'absint', (array) get_option( 'sticky_posts', array() ) ) ) );
	if ( ! empty( $sticky ) ) {
		$args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'post__in'            => $sticky,
			'post__not_in'        => $exclude,
			'orderby'             => 'post__in',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'fields'              => 'ids',
		);
		if ( $cat_id ) {
			$args['cat'] = $cat_id;
		}
		$query = new WP_Query( $args );
		$ids   = array_merge( $ids, array_map( 'absint', $query->posts ) );
	}

	foreach ( array( true, false ) as $thumbnail_required ) {
		if ( count( $ids ) >= $limit ) {
			break;
		}

		$args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit - count( $ids ),
			'post__not_in'        => array_merge( $exclude, $ids ),
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'fields'              => 'ids',
		);
		if ( $cat_id ) {
			$args['cat'] = $cat_id;
		}
		if ( $thumbnail_required ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'EXISTS',
				),
			);
		}

		$query = new WP_Query( $args );
		$ids   = array_merge( $ids, array_map( 'absint', $query->posts ) );
	}

	return array_slice( array_values( array_unique( $ids ) ), 0, $limit );
}
