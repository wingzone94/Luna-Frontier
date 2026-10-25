<?php
/** Period ranking and retention regression tests. */
class Node_Hot_Posts_Test extends WP_UnitTestCase {
	public function test_period_boundaries_and_published_posts(): void {
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
	}

	public function test_recalculation_keeps_thirty_days(): void {
		require_once dirname( __DIR__ ) . '/inc/hot-posts.php';
		$id = self::factory()->post->create();
		foreach ( array( 0, 6, 7, 10, 29, 30, 61 ) as $offset ) {
			update_post_meta( $id, node_hot_day_meta_key( $offset ), 2 );
		}
		$this->assertSame( 4, node_hot_recalculate_local_score( $id ) );
		foreach ( array( 7, 10, 29 ) as $offset ) {
			$this->assertSame( '2', get_post_meta( $id, node_hot_day_meta_key( $offset ), true ) );
		}
		foreach ( array( 30, 61 ) as $offset ) {
			$this->assertSame( '', get_post_meta( $id, node_hot_day_meta_key( $offset ), true ) );
		}
	}
}
