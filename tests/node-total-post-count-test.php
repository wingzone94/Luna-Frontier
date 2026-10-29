<?php
/**
 * 公開記事総数ヘルパーの自動テスト。
 *
 * @package Node
 */

if ( ! function_exists( 'node_get_total_published_posts' ) ) {
	require_once dirname( __DIR__ ) . '/inc/utilities.php';
}

class Node_Total_Post_Count_Test extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_transient( 'node_total_published_posts' );
		parent::tear_down();
	}

	public function test_total_published_posts_follows_publish_draft_and_delete_changes(): void {
		delete_transient( 'node_total_published_posts' );
		$base_count = node_get_total_published_posts();

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->assertSame( $base_count, node_get_total_published_posts() );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);
		$this->assertSame( $base_count + 1, node_get_total_published_posts() );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			]
		);
		$this->assertSame( $base_count, node_get_total_published_posts() );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);
		$this->assertSame( $base_count + 1, node_get_total_published_posts() );

		wp_delete_post( $post_id, true );
		$this->assertSame( $base_count, node_get_total_published_posts() );
	}

	public function test_draft_and_private_posts_are_not_counted(): void {
		delete_transient( 'node_total_published_posts' );
		$base_count = node_get_total_published_posts();

		self::factory()->post->create( [ 'post_status' => 'draft' ] );
		self::factory()->post->create( [ 'post_status' => 'private' ] );
		self::factory()->post->create(
			[
				'post_status' => 'future',
				'post_date'   => '2030-01-01 00:00:00',
			]
		);
		self::factory()->post->create( [ 'post_status' => 'pending' ] );
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);

		$this->assertSame( $base_count, node_get_total_published_posts() );
	}

	public function test_password_protected_published_posts_are_counted_with_the_list(): void {
		delete_transient( 'node_total_published_posts' );
		$base_count = node_get_total_published_posts();

		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_password' => 'secret',
			]
		);

		$this->assertSame( $base_count + 1, node_get_total_published_posts() );

		$query = new WP_Query( node_get_all_articles_page_query_args( 1 ) );
		$this->assertContains( $post_id, wp_list_pluck( $query->posts, 'ID' ) );
	}
}
