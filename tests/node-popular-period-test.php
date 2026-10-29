<?php
class Node_Popular_Period_Test extends WP_UnitTestCase {
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
