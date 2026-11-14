<?php
class Node_Popular_Period_Test extends WP_UnitTestCase {
 public function test_day_keys_use_site_calendar_without_double_timezone_offset(): void {
  require_once dirname( __DIR__ ) . '/inc/hot-posts.php';
  $timezone = get_option( 'timezone_string' );
  try {
   update_option( 'timezone_string', 'Asia/Tokyo' );
   $afternoon = strtotime( '2026-11-02 10:00:00 UTC' );
   $this->assertSame( '_node_hot_views_20261102', node_hot_day_meta_key( 0, $afternoon ) );
   $this->assertSame( '_node_hot_views_20261027', node_hot_day_meta_key( 6, $afternoon ) );
   $this->assertSame( '_node_hot_views_20261004', node_hot_day_meta_key( 29, $afternoon ) );
   $this->assertSame( '_node_hot_views_20261102', node_hot_day_meta_key( -1, $afternoon ) );
   $this->assertSame( '_node_hot_views_20261102', node_hot_day_meta_key( 0, strtotime( '2026-11-02 14:59:59 UTC' ) ) );
   $this->assertSame( '_node_hot_views_20261103', node_hot_day_meta_key( 0, strtotime( '2026-11-02 15:00:00 UTC' ) ) );
   update_option( 'timezone_string', 'America/New_York' );
   $this->assertSame( '_node_hot_views_20261101', node_hot_day_meta_key( 1, strtotime( '2026-11-02 05:30:00 UTC' ) ) );
  } finally {
   update_option( 'timezone_string', $timezone );
  }
 }
 public function test_rank_windows_and_retention(): void {
  require_once dirname( __DIR__ ) . '/inc/hot-posts.php';
  $recent = self::factory()->post->create();
  $older = self::factory()->post->create();
  $expired = self::factory()->post->create();
  $draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
  update_post_meta( $recent, node_hot_day_meta_key( 6 ), 5 );
  update_post_meta( $older, node_hot_day_meta_key( 7 ), 10 );
  update_post_meta( $older, node_hot_day_meta_key( 29 ), 20 );
  update_post_meta( $expired, node_hot_day_meta_key( 30 ), 100 );
  update_post_meta( $expired, node_hot_day_meta_key( 65 ), 50 );
  update_post_meta( $expired, '_node_hot_views_custom', 'keep' );
  update_post_meta( $expired, NODE_HOT_SCORE_META, 1000 );
  update_post_meta( $draft, node_hot_day_meta_key(), 100 );
  $this->assertSame( array( $recent ), wp_list_pluck( node_get_popular_posts_for_period( 7 ), 'ID' ) );
  $this->assertSame( array( $older, $recent ), wp_list_pluck( node_get_popular_posts_for_period( 30 ), 'ID' ) );
  $this->assertSame( array( $recent ), wp_list_pluck( node_get_popular_posts_for_period( 90 ), 'ID' ) );
  $this->assertSame( array( $older ), wp_list_pluck( node_get_popular_posts_for_period( 30, 1 ), 'ID' ) );
  $this->assertSame( 0, node_hot_recalculate_local_score( $older ) );
  $this->assertSame( '20', get_post_meta( $older, node_hot_day_meta_key( 29 ), true ) );
  $this->assertSame( 0, node_hot_recalculate_local_score( $expired ) );
  $this->assertSame( '100', get_post_meta( $expired, node_hot_day_meta_key( 30 ), true ) );

  $deleted = node_hot_cleanup_expired_view_meta();
  $this->assertGreaterThanOrEqual( 2, $deleted );
  $this->assertSame( '', get_post_meta( $expired, node_hot_day_meta_key( 30 ), true ) );
  $this->assertSame( '', get_post_meta( $expired, node_hot_day_meta_key( 65 ), true ) );
  $this->assertSame( 'keep', get_post_meta( $expired, '_node_hot_views_custom', true ) );
  $this->assertSame( '20', get_post_meta( $older, node_hot_day_meta_key( 29 ), true ) );
  $this->assertSame( '5', get_post_meta( $recent, node_hot_day_meta_key( 6 ), true ) );
 }

 public function test_template_redirect_skips_db_writes_and_cron_schedules_cleanup(): void {
  require_once dirname( __DIR__ ) . '/inc/hot-posts.php';
  $this->assertFalse( has_action( 'template_redirect', 'node_hot_track_local_view' ) );
  $this->assertNotFalse( has_action( 'rest_api_init', 'node_hot_register_rest_routes' ) );
  $this->assertNotFalse( has_action( 'wp_footer', 'node_hot_render_view_beacon' ) );

  $post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
  $original_user   = get_current_user_id();
  $original_ua     = $_SERVER['HTTP_USER_AGENT'] ?? null;
  $captured_writes = array();
  $query_logger    = static function ( string $sql ) use ( &$captured_writes ): string {
   if ( 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) ) {
    $captured_writes[] = $sql;
   }
   return $sql;
  };

  try {
   wp_set_current_user( 0 );
   $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)';
   $this->go_to( get_permalink( $post_id ) );

   add_filter( 'query', $query_logger );
   do_action( 'template_redirect' );

   $this->assertSame( '', get_post_meta( $post_id, node_hot_day_meta_key(), true ) );
   $this->assertSame( '', get_post_meta( $post_id, NODE_HOT_SCORE_META, true ) );

   ob_start();
   node_hot_render_view_beacon();
   $anonymous_footer = (string) ob_get_clean();
   remove_filter( 'query', $query_logger );
   $this->assertSame( array(), $captured_writes );

   $this->assertStringContainsString( 'node-hot-view-beacon', $anonymous_footer );
   $this->assertStringContainsString( 'hot-posts/view', $anonymous_footer );
   $this->assertStringContainsString( (string) $post_id, $anonymous_footer );

   $editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
   wp_set_current_user( $editor_id );
   ob_start();
   node_hot_render_view_beacon();
   $editor_footer = (string) ob_get_clean();
   $this->assertSame( '', $editor_footer );
  } finally {
   remove_filter( 'query', $query_logger );
   wp_set_current_user( $original_user );
   if ( null === $original_ua ) {
    unset( $_SERVER['HTTP_USER_AGENT'] );
   } else {
    $_SERVER['HTTP_USER_AGENT'] = $original_ua;
   }
  }

  wp_clear_scheduled_hook( NODE_HOT_CLEANUP_HOOK );
  $this->assertFalse( wp_next_scheduled( NODE_HOT_CLEANUP_HOOK ) );
  node_hot_schedule_cleanup();
  $event = wp_get_scheduled_event( NODE_HOT_CLEANUP_HOOK );
  $this->assertIsObject( $event );
  $this->assertSame( 'daily', $event->schedule );
  node_hot_unschedule_cleanup();
  $this->assertFalse( wp_next_scheduled( NODE_HOT_CLEANUP_HOOK ) );
 }

 public function test_rest_beacon_records_views_with_throttled_score_recalculation(): void {
  require_once dirname( __DIR__ ) . '/inc/hot-posts.php';
  $post_id  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
  $draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

  $original_user = get_current_user_id();
  $original_ua   = $_SERVER['HTTP_USER_AGENT'] ?? null;
  $throttle_key  = 'node_hot_score_throttle_' . $post_id;
  delete_transient( $throttle_key );
  wp_clear_scheduled_hook( NODE_HOT_CLEANUP_HOOK );

  try {
   wp_set_current_user( 0 );
   $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)';

   $first = new WP_REST_Request( 'POST', '/node/v1/hot-posts/view' );
   $first->set_param( 'post_id', $post_id );
   $first_response = rest_do_request( $first );
   $this->assertSame( 202, $first_response->get_status() );
   $this->assertSame( array( 'tracked' => true ), $first_response->get_data() );
   $this->assertSame( '1', get_post_meta( $post_id, node_hot_day_meta_key(), true ) );
   $this->assertSame( '1', get_post_meta( $post_id, NODE_HOT_SCORE_META, true ) );
   $this->assertNotFalse( wp_next_scheduled( NODE_HOT_CLEANUP_HOOK ) );

   $second = new WP_REST_Request( 'POST', '/node/v1/hot-posts/view' );
   $second->set_param( 'post_id', $post_id );
   $second_response = rest_do_request( $second );
   $this->assertSame( 202, $second_response->get_status() );
   $this->assertSame( '2', get_post_meta( $post_id, node_hot_day_meta_key(), true ) );
   $this->assertSame( '1', get_post_meta( $post_id, NODE_HOT_SCORE_META, true ) );

   delete_transient( $throttle_key );
   $third = new WP_REST_Request( 'POST', '/node/v1/hot-posts/view' );
   $third->set_param( 'post_id', $post_id );
   $third_response = rest_do_request( $third );
   $this->assertSame( 202, $third_response->get_status() );
   $this->assertSame( '3', get_post_meta( $post_id, node_hot_day_meta_key(), true ) );
   $this->assertSame( '3', get_post_meta( $post_id, NODE_HOT_SCORE_META, true ) );

   $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
   $bot = new WP_REST_Request( 'POST', '/node/v1/hot-posts/view' );
   $bot->set_param( 'post_id', $post_id );
   $bot_response = rest_do_request( $bot );
   $this->assertSame( 202, $bot_response->get_status() );
   $this->assertSame( array( 'tracked' => false ), $bot_response->get_data() );
   $this->assertSame( '3', get_post_meta( $post_id, node_hot_day_meta_key(), true ) );

   $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
   $editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
   wp_set_current_user( $editor_id );
   $editor_req = new WP_REST_Request( 'POST', '/node/v1/hot-posts/view' );
   $editor_req->set_param( 'post_id', $post_id );
   $editor_response = rest_do_request( $editor_req );
   $this->assertSame( 202, $editor_response->get_status() );
   $this->assertSame( array( 'tracked' => false ), $editor_response->get_data() );
   $this->assertSame( '3', get_post_meta( $post_id, node_hot_day_meta_key(), true ) );

   wp_set_current_user( 0 );
   $draft_req = new WP_REST_Request( 'POST', '/node/v1/hot-posts/view' );
   $draft_req->set_param( 'post_id', $draft_id );
   $draft_response = rest_do_request( $draft_req );
   $this->assertSame( 404, $draft_response->get_status() );
   $this->assertSame( '', get_post_meta( $draft_id, node_hot_day_meta_key(), true ) );

   $invalid_req = new WP_REST_Request( 'POST', '/node/v1/hot-posts/view' );
   $invalid_req->set_param( 'post_id', 'abc' );
   $invalid_response = rest_do_request( $invalid_req );
   $this->assertSame( 400, $invalid_response->get_status() );
  } finally {
   delete_transient( $throttle_key );
   wp_clear_scheduled_hook( NODE_HOT_CLEANUP_HOOK );
   wp_set_current_user( $original_user );
   if ( null === $original_ua ) {
    unset( $_SERVER['HTTP_USER_AGENT'] );
   } else {
    $_SERVER['HTTP_USER_AGENT'] = $original_ua;
   }
  }
 }

 public function test_daily_maintenance_cleans_expired_buckets_and_decays_stale_scores(): void {
  require_once dirname( __DIR__ ) . '/inc/hot-posts.php';
  $post_id   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
  $active_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
  update_post_meta( $post_id, node_hot_day_meta_key( 8 ), 50 );
  update_post_meta( $post_id, node_hot_day_meta_key( 35 ), 10 );
  update_post_meta( $post_id, NODE_HOT_SCORE_META, '50' );
  update_post_meta( $post_id, NODE_HOT_LOCAL_SCORE_META, '50' );
  update_post_meta( $active_id, node_hot_day_meta_key( 0 ), 1 );
  update_post_meta( $active_id, NODE_HOT_SCORE_META, '1' );
  update_post_meta( $active_id, NODE_HOT_LOCAL_SCORE_META, '1' );

  $this->assertSame( array( $post_id ), node_get_hot_post_ids( 1 ) );

  node_hot_run_daily_maintenance();

  $this->assertSame( '', get_post_meta( $post_id, node_hot_day_meta_key( 35 ), true ) );
  $this->assertSame( '50', get_post_meta( $post_id, node_hot_day_meta_key( 8 ), true ) );
  $this->assertSame( '0', get_post_meta( $post_id, NODE_HOT_SCORE_META, true ) );
  $this->assertSame( '0', get_post_meta( $post_id, NODE_HOT_LOCAL_SCORE_META, true ) );
  $this->assertSame( array( $active_id ), node_get_hot_post_ids( 1 ) );
 }
}
