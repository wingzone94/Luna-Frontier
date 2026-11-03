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
  update_post_meta( $expired, NODE_HOT_SCORE_META, 1000 );
  update_post_meta( $draft, node_hot_day_meta_key(), 100 );
  $this->assertSame( array( $recent ), wp_list_pluck( node_get_popular_posts_for_period( 7 ), 'ID' ) );
  $this->assertSame( array( $older, $recent ), wp_list_pluck( node_get_popular_posts_for_period( 30 ), 'ID' ) );
  $this->assertSame( array( $recent ), wp_list_pluck( node_get_popular_posts_for_period( 90 ), 'ID' ) );
  $this->assertSame( array( $older ), wp_list_pluck( node_get_popular_posts_for_period( 30, 1 ), 'ID' ) );
  $this->assertSame( 0, node_hot_recalculate_local_score( $older ) );
  $this->assertSame( '20', get_post_meta( $older, node_hot_day_meta_key( 29 ), true ) );
  node_hot_recalculate_local_score( $expired );
  $this->assertSame( '', get_post_meta( $expired, node_hot_day_meta_key( 30 ), true ) );
 }
}
