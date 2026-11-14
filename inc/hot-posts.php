<?php

declare(strict_types=1);
/**
 * HOT posts and semi-automated HEADLINE selection.
 *
 * @package Node
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NODE_HOT_SCORE_META              = '_node_hot_score_7d';
const NODE_HOT_LOCAL_SCORE_META        = '_node_hot_local_score_7d';
const NODE_HOT_VIEW_META_PREFIX        = '_node_hot_views_';
const NODE_HOT_PERIOD_DAYS             = 7;
const NODE_HOT_RETENTION_DAYS          = 30;
const NODE_HOT_SCORE_RECALC_TTL        = 300;
const NODE_HOT_LIMIT                   = 3;
const NODE_HOT_CLEANUP_HOOK            = 'node_hot_cleanup_expired_views';

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
function node_hot_day_meta_key( int $offset = 0, ?int $timestamp = null ): string {
	// Work in the site timezone once; a WP local timestamp is not a Unix timestamp.
	$day = current_datetime();
	if ( null !== $timestamp ) {
		$day = $day->setTimestamp( $timestamp );
	}
	return NODE_HOT_VIEW_META_PREFIX . $day->modify( '-' . max( 0, $offset ) . ' days' )->format( 'Ymd' );
}

/**
 * Check whether the current visitor/user-agent should be counted.
 */
function node_hot_is_trackable_visitor( int $post_id = 0 ): bool {
	if ( $post_id > 0 && is_user_logged_in() && current_user_can( 'edit_post', $post_id ) ) {
		return false;
	}

	if ( 0 === $post_id && is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return false;
	}

	$user_agent = strtolower( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
	if ( '' === $user_agent ) {
		return true;
	}

	return 1 !== preg_match( '/bot|crawl|spider|slurp|mediapartners|google-inspectiontool|lighthouse|pagespeed|pingdom|uptime|headless/i', $user_agent );
}

/**
 * Avoid counting obvious non-reader traffic during HTML rendering.
 */
function node_hot_should_track_request( int $post_id ): bool {
	if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || wp_doing_cron() || is_preview() ) {
		return false;
	}

	return node_hot_is_trackable_visitor( $post_id );
}

/**
 * Recalculate local 7-day HOT score for a post.
 */
function node_hot_recalculate_local_score( int $post_id ): int {
	$score = 0;
	for ( $i = 0; $i < NODE_HOT_PERIOD_DAYS; $i++ ) {
		$score += max( 0, (int) get_post_meta( $post_id, node_hot_day_meta_key( $i ), true ) );
	}

	update_post_meta( $post_id, NODE_HOT_LOCAL_SCORE_META, (string) $score );
	update_post_meta( $post_id, NODE_HOT_SCORE_META, (string) $score );

	return $score;
}

/**
 * Delete expired daily view buckets older than retention days.
 */
function node_hot_cleanup_expired_view_meta( int $retention_days = NODE_HOT_RETENTION_DAYS ): int {
	global $wpdb;

	$retention_days = max( 1, $retention_days );
	$cutoff_key     = node_hot_day_meta_key( $retention_days );
	$prefix_like    = $wpdb->esc_like( NODE_HOT_VIEW_META_PREFIX ) . '%';
	$key_length     = strlen( $cutoff_key );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_key <= %s AND CHAR_LENGTH(meta_key) = %d",
			$prefix_like,
			$cutoff_key,
			$key_length
		)
	);

	if ( empty( $rows ) ) {
		return 0;
	}

	$meta_ids = array();
	$post_ids = array();
	$pattern  = '/^' . preg_quote( NODE_HOT_VIEW_META_PREFIX, '/' ) . '\d{8}$/';
	foreach ( $rows as $row ) {
		if ( 1 === preg_match( $pattern, (string) $row->meta_key ) ) {
			$meta_ids[] = (int) $row->meta_id;
			$post_ids[] = (int) $row->post_id;
		}
	}

	if ( empty( $meta_ids ) ) {
		return 0;
	}

	$deleted = 0;
	foreach ( array_chunk( $meta_ids, 500 ) as $chunk ) {
		$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
		$result       = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ($placeholders)",
				$chunk
			)
		);
		if ( is_int( $result ) && $result > 0 ) {
			$deleted += $result;
		}
	}

	foreach ( array_unique( $post_ids ) as $post_id ) {
		wp_cache_delete( $post_id, 'post_meta' );
	}

	return $deleted;
}

/**
 * Refresh 7-day HOT scores for posts currently holding a positive score.
 */
function node_hot_refresh_active_scores( int $batch_size = 200 ): int {
	global $wpdb;

	$batch_size = max( 1, $batch_size );
	$last_id    = 0;
	$updated    = 0;

	do {
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST(meta_value AS SIGNED) > 0 AND post_id > %d ORDER BY post_id ASC LIMIT %d",
				NODE_HOT_SCORE_META,
				$last_id,
				$batch_size
			)
		);

		if ( empty( $post_ids ) ) {
			break;
		}

		$ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );
		if ( empty( $ids ) ) {
			break;
		}

		update_meta_cache( 'post', $ids );
		foreach ( $ids as $post_id ) {
			node_hot_recalculate_local_score( $post_id );
			$updated++;
		}

		$last_id = max( $ids );
	} while ( count( $post_ids ) === $batch_size );

	return $updated;
}

/**
 * Daily cron callback for HOT posts maintenance.
 */
function node_hot_run_daily_maintenance(): void {
	node_hot_cleanup_expired_view_meta();
	node_hot_refresh_active_scores();
}
add_action( NODE_HOT_CLEANUP_HOOK, 'node_hot_run_daily_maintenance' );

/**
 * Schedule daily HOT posts maintenance if not already queued.
 */
function node_hot_schedule_cleanup(): void {
	if ( wp_installing() ) {
		return;
	}

	if ( false === wp_next_scheduled( NODE_HOT_CLEANUP_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', NODE_HOT_CLEANUP_HOOK );
	}
}
add_action( 'after_switch_theme', 'node_hot_schedule_cleanup' );
add_action( 'admin_init', 'node_hot_schedule_cleanup' );

/**
 * Unschedule daily HOT posts maintenance when switching themes.
 */
function node_hot_unschedule_cleanup(): void {
	wp_clear_scheduled_hook( NODE_HOT_CLEANUP_HOOK );
}
add_action( 'switch_theme', 'node_hot_unschedule_cleanup' );

/**
 * Rank published posts by daily views, including today, in a 7/30-day window.
 * Query daily buckets so inactive posts age out without another visit.
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
 * Record a single post view and throttle 7-day score recalculation.
 */
function node_hot_record_view( int $post_id ): bool {
	if ( $post_id <= 0 || 'post' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
		return false;
	}

	$key   = node_hot_day_meta_key();
	$count = max( 0, (int) get_post_meta( $post_id, $key, true ) );
	update_post_meta( $post_id, $key, (string) ( $count + 1 ) );

	// Throttling recalculation avoids repeated 7-day reads and meta row writes under burst traffic.
	$throttle_key = 'node_hot_score_throttle_' . $post_id;
	$has_score    = '' !== (string) get_post_meta( $post_id, NODE_HOT_SCORE_META, true );
	if ( ! $has_score || false === get_transient( $throttle_key ) ) {
		node_hot_recalculate_local_score( $post_id );
		set_transient( $throttle_key, '1', NODE_HOT_SCORE_RECALC_TTL );
	}

	node_hot_schedule_cleanup();

	return true;
}

/**
 * Register lightweight REST route for asynchronous view beacons.
 */
function node_hot_register_rest_routes(): void {
	register_rest_route(
		'node/v1',
		'/hot-posts/view',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'node_hot_rest_track_view',
			'permission_callback' => '__return_true',
			'args'                => array(
				'post_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'minimum'           => 1,
					'validate_callback' => static fn( $value ): bool => rest_is_integer( $value ) && (int) $value > 0,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'node_hot_register_rest_routes' );

/**
 * Handle async view tracking REST request.
 *
 * @return WP_REST_Response|WP_Error
 */
function node_hot_rest_track_view( WP_REST_Request $request ) {
	$post_id = absint( $request->get_param( 'post_id' ) );
	if ( $post_id <= 0 || 'post' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
		return new WP_Error(
			'node_hot_invalid_post',
			__( 'Invalid post ID.', 'node' ),
			array( 'status' => 404 )
		);
	}

	if ( ! node_hot_is_trackable_visitor( $post_id ) ) {
		return new WP_REST_Response( array( 'tracked' => false ), 202 );
	}

	return new WP_REST_Response( array( 'tracked' => node_hot_record_view( $post_id ) ), 202 );
}

/**
 * Output a non-blocking view beacon on single post footers.
 */
function node_hot_render_view_beacon(): void {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	$post_id = get_queried_object_id();
	if ( ! $post_id || 'post' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
		return;
	}

	if ( ! node_hot_should_track_request( $post_id ) ) {
		return;
	}

	$endpoint = esc_url_raw( rest_url( 'node/v1/hot-posts/view' ) );
	$script   = sprintf(
		'(function(){if(typeof navigator==="undefined")return;var sent=false,send=function(){if(sent)return;sent=true;var u=%1$s,b=typeof URLSearchParams==="function"?new URLSearchParams({post_id:%2$s}):null;if(!b)return;if(typeof navigator.sendBeacon==="function"&&navigator.sendBeacon(u,b))return;if(typeof window.fetch==="function"){window.fetch(u,{method:"POST",body:b,keepalive:true,credentials:"omit"}).catch(function(){});}};var arm=function(){if(document.readyState==="complete"){setTimeout(send,0);}else{window.addEventListener("load",function(){setTimeout(send,0);},{once:true});window.addEventListener("pagehide",send,{once:true});}};if(document.prerendering){document.addEventListener("prerenderingchange",arm,{once:true});}else{arm();}})();',
		wp_json_encode( $endpoint, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( (string) $post_id )
	);

	if ( function_exists( 'wp_print_inline_script_tag' ) ) {
		wp_print_inline_script_tag( $script, array( 'id' => 'node-hot-view-beacon' ) );
		return;
	}

	echo '<script id="node-hot-view-beacon">' . $script . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_footer', 'node_hot_render_view_beacon', 99 );

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
				'ID'             => 'DESC',
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
				'orderby'             => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
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
			'orderby'             => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
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
