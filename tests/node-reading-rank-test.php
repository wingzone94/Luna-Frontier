<?php
/**
 * 読了バッジのブログ分布基準テスト。
 *
 * @package Node
 */

class Node_Reading_Rank_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_transient( 'node_article_length_distribution_v1' );
	}

	private function create_published_post_with_chars( int $chars ): int {
		return $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => str_repeat( 'あ', $chars ),
			)
		);
	}

	public function test_fewer_than_twenty_posts_use_legacy_absolute_thresholds() {
		$short = $this->create_published_post_with_chars( 1000 );
		$long  = $this->create_published_post_with_chars( 11000 );

		$this->assertSame( 'short', node_get_article_ranking_info( $short )['rank'] );
		$this->assertSame( 10, node_get_article_ranking_info( $short )['progress'] );
		$this->assertSame( 'long', node_get_article_ranking_info( $long )['rank'] );
		$this->assertSame( 100, node_get_article_ranking_info( $long )['progress'] );
	}

	public function test_twenty_posts_enable_median_and_p90_baseline() {
		$post_ids = array();
		foreach ( range( 1, 20 ) as $index ) {
			$post_ids[] = $this->create_published_post_with_chars( $index * 500 );
		}

		$distribution = node_get_article_length_distribution();
		$this->assertSame( 20, $distribution['count'] );
		$this->assertSame( 5000, $distribution['median'] );
		$this->assertSame( 9000, $distribution['p90'] );

		$median_info = node_get_article_ranking_info( $post_ids[9] );
		$p90_info    = node_get_article_ranking_info( $post_ids[17] );
		$this->assertSame( 50, $median_info['progress'] );
		$this->assertSame( 'standard', $median_info['rank'] );
		$this->assertSame( 90, $p90_info['progress'] );
		$this->assertSame( 'long', $p90_info['rank'] );
	}

	public function test_extreme_outlier_does_not_destabilize_existing_article() {
		$target_id = 0;
		foreach ( range( 1, 20 ) as $index ) {
			$post_id = $this->create_published_post_with_chars( $index * 500 );
			if ( 14 === $index ) {
				$target_id = $post_id;
			}
		}

		$before = node_get_article_ranking_info( $target_id );
		$this->create_published_post_with_chars( 1000000 );
		delete_transient( 'node_article_length_distribution_v1' );
		$after = node_get_article_ranking_info( $target_id );

		$this->assertSame( $before['rank'], $after['rank'] );
		$this->assertLessThanOrEqual( 5, abs( $before['progress'] - $after['progress'] ) );
	}

	public function test_biased_tiny_post_distribution_uses_legacy_fallback() {
		foreach ( range( 1, 20 ) as $index ) {
			$this->create_published_post_with_chars( 50 );
		}
		$target_id = $this->create_published_post_with_chars( 1000 );

		$distribution = node_get_article_length_distribution();
		$this->assertSame( 50, $distribution['median'] );

		$info = node_get_article_ranking_info( $target_id );
		$this->assertSame( 'short', $info['rank'] );
		$this->assertSame( 10, $info['progress'] );
	}

	public function test_gauge_progress_moves_continuously_with_article_length() {
		$shorter_id = $this->create_published_post_with_chars( 1800 );
		$longer_id  = $this->create_published_post_with_chars( 2600 );

		$shorter = node_get_article_ranking_info( $shorter_id );
		$longer  = node_get_article_ranking_info( $longer_id );

		$this->assertGreaterThan( $shorter['progress'], $longer['progress'] );
		$this->assertNotContains( $shorter['progress'], array( 20, 40, 60, 80, 100 ), true );
	}

	public function test_post_change_hook_invalidates_distribution_cache() {
		set_transient(
			'node_article_length_distribution_v1',
			array( 'count' => 99, 'median' => 1, 'p90' => 2 ),
			DAY_IN_SECONDS
		);

		\LuminousCore\Engine\Plugin::article_metrics()->clear_distribution_cache();

		$this->assertFalse( get_transient( 'node_article_length_distribution_v1' ) );
	}
}
