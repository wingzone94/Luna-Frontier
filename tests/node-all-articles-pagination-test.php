<?php
/**
 * /all-articles/ が公開記事の全件へページ送りで到達すること。
 *
 * @package Node
 */

class Node_All_Articles_Pagination_Test extends WP_UnitTestCase {

	private $original_sticky_posts;

	public function set_up(): void {
		parent::set_up();
		$this->original_sticky_posts = get_option( 'sticky_posts' );
	}

	public function tear_down(): void {
		update_option( 'sticky_posts', $this->original_sticky_posts );
		delete_transient( 'node_total_published_posts' );
		parent::tear_down();
	}

	public function test_template_drops_the_latest_subset_notice_and_the_hard_cap(): void {
		$template = (string) file_get_contents( dirname( __DIR__ ) . '/template-parts/all-articles.php' );
		$functions = (string) file_get_contents( dirname( __DIR__ ) . '/functions.php' );

		$this->assertStringNotContainsString( 'NODE_ALL_ARTICLES_TOTAL_LIMIT', $template );
		$this->assertStringNotContainsString( 'NODE_ALL_ARTICLES_TOTAL_LIMIT', $functions );
		$this->assertStringNotContainsString( '最新', $template );
		$this->assertStringContainsString( '公開記事', $template );
		$this->assertStringContainsString( 'node_get_all_articles_page_query_args', $template );
		$this->assertFalse( defined( 'NODE_ALL_ARTICLES_TOTAL_LIMIT' ) );
	}

	public function test_page_counts_for_empty_exact_and_one_over_boundaries(): void {
		$this->assertSame( 24, node_get_all_articles_per_page() );
		$this->assertSame( 1, node_get_all_articles_total_pages( 0 ) );
		$this->assertSame( 1, node_get_all_articles_total_pages( 24 ) );
		$this->assertSame( 2, node_get_all_articles_total_pages( 25 ) );
		$this->assertSame( 10, node_get_all_articles_total_pages( 240 ) );
		$this->assertSame( 11, node_get_all_articles_total_pages( 241 ) );
		$this->assertSame( 13, node_get_all_articles_total_pages( 289 ) );
	}

	public function test_listing_query_uses_the_same_filters_as_the_counter(): void {
		$page_args = node_get_all_articles_page_query_args( 3 );
		$shared    = node_get_published_posts_query_args();

		$this->assertSame( 'post', $page_args['post_type'] );
		$this->assertSame( 'publish', $page_args['post_status'] );
		$this->assertTrue( $page_args['ignore_sticky_posts'] );
		$this->assertSame( $shared['post_type'], $page_args['post_type'] );
		$this->assertSame( $shared['post_status'], $page_args['post_status'] );
		$this->assertSame( $shared['ignore_sticky_posts'], $page_args['ignore_sticky_posts'] );
		$this->assertSame(
			array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			$page_args['orderby']
		);
		$this->assertSame( $shared['orderby'], $page_args['orderby'] );
		$this->assertSame( 24, $page_args['posts_per_page'] );
		$this->assertSame( 3, $page_args['paged'] );
		$this->assertArrayNotHasKey( 'offset', $page_args );
		$this->assertArrayNotHasKey( 'post__in', $page_args );
	}

	public function test_every_published_post_is_reachable_without_duplicates(): void {
		delete_transient( 'node_total_published_posts' );
		$before = node_get_total_published_posts();

		$shared_date = '2026-06-01 12:00:00';
		$included    = array();

		for ( $i = 0; $i < 25; $i++ ) {
			$included[] = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_date'   => $shared_date,
					'post_title'  => 'Same Date ' . $i,
				)
			);
		}

		$sticky_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-05-01 00:00:00',
				'post_title'  => 'Sticky Article',
			)
		);
		update_option( 'sticky_posts', array( $sticky_id ) );
		$included[] = $sticky_id;

		$password_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_date'     => $shared_date,
			)
		);
		$included[] = $password_id;

		$draft_id   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$future_id  = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => '2030-01-01 00:00:00',
			)
		);
		$private_id = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$page_id    = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Not A Post',
			)
		);
		$excluded   = array( $draft_id, $future_id, $private_id, $page_id );

		delete_transient( 'node_total_published_posts' );
		$total = node_get_total_published_posts();
		$this->assertSame( $before + count( $included ), $total );

		$per_page    = node_get_all_articles_per_page();
		$total_pages = node_get_all_articles_total_pages( $total );
		$collected   = array();

		for ( $page = 1; $page <= $total_pages; $page++ ) {
			$query = new WP_Query( node_get_all_articles_page_query_args( $page ) );
			$ids   = array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) );

			$this->assertSame( $total, (int) $query->found_posts, 'Page ' . $page . ' count drifted from the total.' );
			$this->assertSame( $total_pages, node_get_all_articles_total_pages( (int) $query->found_posts ) );

			$expected_count = $page < $total_pages ? $per_page : ( ( 0 === $total % $per_page ) ? $per_page : $total % $per_page );
			$this->assertCount( $expected_count, $ids, 'Unexpected page length on page ' . $page );

			foreach ( $ids as $id ) {
				$this->assertNotContains( $id, $collected, 'Duplicate post ID ' . $id );
				$collected[] = $id;
			}
		}

		$this->assertSame( $total, count( $collected ) );
		sort( $included );
		$collected_included = array_values( array_intersect( $collected, $included ) );
		$sorted_included    = $collected_included;
		sort( $sorted_included );
		$this->assertSame( $sorted_included, $included );

		foreach ( $excluded as $id ) {
			$this->assertNotContains( $id, $collected );
		}

		$same_date_ids = array();
		foreach ( $collected as $id ) {
			if ( get_post_field( 'post_date', $id ) === $shared_date ) {
				$same_date_ids[] = $id;
			}
		}
		$expected_order = $same_date_ids;
		rsort( $expected_order, SORT_NUMERIC );
		$this->assertSame( $expected_order, $same_date_ids );

		$next = new WP_Query( node_get_all_articles_page_query_args( $total_pages + 1 ) );
		$this->assertSame( array(), wp_list_pluck( $next->posts, 'ID' ) );
		$this->assertSame( $total, (int) $next->found_posts );
	}

	public function test_posts_past_the_former_240_cap_remain_on_the_last_page(): void {
		self::factory()->post->create_many( 241, array( 'post_status' => 'publish' ) );
		delete_transient( 'node_total_published_posts' );

		$total    = node_get_total_published_posts();
		$max_page = node_get_all_articles_total_pages( $total );

		$this->assertGreaterThan( 240, $total );
		$this->assertGreaterThan( 10, $max_page );
		$this->assertSame(
			home_url( '/all-articles/page/' . $max_page . '/' ),
			node_resolve_404_redirect_target( new WP_Query(), '/all-articles/page/9999/' )
		);
		$this->assertNull(
			node_resolve_404_redirect_target( new WP_Query(), '/all-articles/page/' . $max_page . '/' )
		);

		$last = new WP_Query( node_get_all_articles_page_query_args( $max_page ) );
		$this->assertSame( $total, (int) $last->found_posts );
		$this->assertGreaterThan( 0, $last->post_count );

		$remainder = $total % node_get_all_articles_per_page();
		$this->assertSame(
			0 === $remainder ? node_get_all_articles_per_page() : $remainder,
			$last->post_count
		);
	}
}
