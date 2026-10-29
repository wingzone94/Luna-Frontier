<?php
/**
 * 範囲外ページネーション301の自動テスト。
 *
 * @package Node
 */

class Node_Pagination_Redirect_Test extends WP_UnitTestCase {
	private int $category_id;
	private string $category_slug = 'pagination-redirect-category';
	private WP_Post $split_post;
	private string $original_query_string = '';

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->original_query_string = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
		$_SERVER['QUERY_STRING'] = '';

		$this->category_id = self::factory()->category->create(
			array(
				'name' => 'Pagination Redirect Category',
				'slug' => $this->category_slug,
			)
		);

		self::factory()->post->create_many(
			30,
			array(
				'post_status'   => 'publish',
				'post_category' => array( $this->category_id ),
			)
		);

		$split_post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_name'    => 'split-pagination-post',
				'post_content' => 'Page 1<!--nextpage-->Page 2<!--nextpage-->Page 3',
			)
		);
		$this->split_post = get_post( $split_post_id );
	}

	public function tear_down(): void {
		$_SERVER['QUERY_STRING'] = $this->original_query_string;
		parent::tear_down();
	}

	private function category_query( int $paged ): WP_Query {
		return new WP_Query(
			array(
				'category_name' => $this->category_slug,
				'paged'         => $paged,
				'posts_per_page' => 24,
				'post_status'   => 'publish',
			)
		);
	}

	public function test_category_page_99_redirects_to_last_page(): void {
		$target = home_url( '/category/' . $this->category_slug . '/page/2/' );

		$this->assertSame(
			$target,
			node_resolve_404_redirect_target( $this->category_query( 99 ), '/category/' . $this->category_slug . '/page/99/' )
		);
	}

	public function test_category_page_immediately_after_last_redirects_to_last_page(): void {
		$target = home_url( '/category/' . $this->category_slug . '/page/2/' );

		$this->assertSame(
			$target,
			node_resolve_404_redirect_target( $this->category_query( 3 ), '/category/' . $this->category_slug . '/page/3/' )
		);
	}

	public function test_single_page_category_redirects_to_archive_base(): void {
		$category_id = self::factory()->category->create(
			array(
				'name' => 'Single Page Category',
				'slug' => 'single-page-category',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_category' => array( $category_id ),
			)
		);
		$query = new WP_Query(
			array(
				'category_name'  => 'single-page-category',
				'paged'          => 2,
				'posts_per_page' => 24,
				'post_status'    => 'publish',
			)
		);

		$this->assertSame(
			home_url( '/category/single-page-category/' ),
			node_resolve_404_redirect_target( $query, '/category/single-page-category/page/2/' )
		);
	}

	public function test_search_out_of_range_redirects_to_last_page(): void {
		self::factory()->post->create_many(
			25,
			array(
				'post_status' => 'publish',
				'post_title'  => 'Unique Search Redirect Needle',
			)
		);
		$query = new WP_Query(
			array(
				's'              => 'Unique Search Redirect Needle',
				'paged'          => 99,
				'posts_per_page' => 24,
				'post_status'    => 'publish',
			)
		);

		$this->assertSame(
			home_url( '/search/Unique%20Search%20Redirect%20Needle/page/2/' ),
			node_resolve_404_redirect_target( $query, '/search/Unique%20Search%20Redirect%20Needle/page/99/' )
		);
	}

	public function test_tag_out_of_range_redirects_to_last_page(): void {
		$tag_id   = self::factory()->tag->create( array( 'slug' => 'pagination-tag' ) );
		$post_ids = self::factory()->post->create_many( 25, array( 'post_status' => 'publish' ) );

		foreach ( $post_ids as $post_id ) {
			wp_set_post_terms( $post_id, array( $tag_id ), 'post_tag' );
		}

		$query = new WP_Query(
			array(
				'tag'            => 'pagination-tag',
				'paged'          => 99,
				'posts_per_page' => 24,
				'post_status'    => 'publish',
			)
		);

		$this->assertSame(
			home_url( '/tag/pagination-tag/page/2/' ),
			node_resolve_404_redirect_target( $query, '/tag/pagination-tag/page/99/' )
		);
	}

	public function test_series_out_of_range_redirects_to_last_page(): void {
		$series_id = self::factory()->term->create(
			array(
				'taxonomy' => 'node_series',
				'name'     => 'Pagination Series',
				'slug'     => 'pagination-series',
			)
		);
		$post_ids = self::factory()->post->create_many( 25, array( 'post_status' => 'publish' ) );

		foreach ( $post_ids as $post_id ) {
			wp_set_object_terms( $post_id, array( $series_id ), 'node_series' );
		}

		$query = new WP_Query(
			array(
				'node_series'   => 'pagination-series',
				'paged'         => 99,
				'posts_per_page' => 24,
				'post_status'   => 'publish',
			)
		);

		$this->assertSame(
			home_url( '/series/pagination-series/page/2/' ),
			node_resolve_404_redirect_target( $query, '/series/pagination-series/page/99/' )
		);
	}

	public function test_home_page_99_redirects_to_last_page(): void {
		$query = new WP_Query(
			array(
				'paged'          => 99,
				'posts_per_page' => 12,
				'post_status'    => 'publish',
			)
		);
		$this->assertSame(
			home_url( '/page/3/' ),
			node_resolve_404_redirect_target( $query, '/page/99/' )
		);
	}

	public function test_all_articles_page_99_redirects_to_configured_last_page(): void {
		$total_published = node_get_total_published_posts();
		$max_page        = node_get_all_articles_total_pages( $total_published );

		$this->assertSame(
			home_url( '/all-articles/page/' . $max_page . '/' ),
			node_resolve_404_redirect_target( new WP_Query(), '/all-articles/page/99/' )
		);
	}

	public function test_split_post_out_of_range_redirects_to_first_page(): void {
		$this->assertSame(
			get_permalink( $this->split_post ),
			node_resolve_404_redirect_target( new WP_Query(), '/split-pagination-post/9/' )
		);
	}

	public function test_query_string_is_preserved(): void {
		$target = home_url( '/category/' . $this->category_slug . '/page/2/?utm_source=x' );

		$this->assertSame(
			$target,
			node_resolve_404_redirect_target(
				$this->category_query( 99 ),
				'/category/' . $this->category_slug . '/page/99/?utm_source=x'
			)
		);
	}

	public function test_unknown_page_is_not_redirected(): void {
		$this->assertNull( node_resolve_404_redirect_target( new WP_Query( array( 'pagename' => 'no-such-page' ) ), '/no-such-page/' ) );
	}

	public function test_unknown_category_is_not_redirected(): void {
		$query = new WP_Query(
			array(
				'category_name' => 'no-such',
				'paged'         => 2,
				'posts_per_page' => 24,
			)
		);

		$this->assertNull( node_resolve_404_redirect_target( $query, '/category/no-such/page/2/' ) );
	}

	public function test_unknown_series_is_not_redirected(): void {
		$this->assertNull( node_resolve_404_redirect_target( new WP_Query(), '/series/no-such/' ) );
	}

	public function test_last_valid_category_page_is_not_redirected(): void {
		$this->assertNull(
			node_resolve_404_redirect_target( $this->category_query( 2 ), '/category/' . $this->category_slug . '/page/2/' )
		);
	}

	public function test_valid_split_post_page_is_not_redirected(): void {
		$this->assertNull( node_resolve_404_redirect_target( new WP_Query(), '/split-pagination-post/2/' ) );
	}

	public function test_handler_is_registered_after_spotlight_fallback(): void {
		$this->assertSame( 1, has_action( 'template_redirect', 'node_handle_404_redirect' ) );
		$this->assertSame( 0, has_action( 'template_redirect', 'node_spotlight_404_fallback' ) );
	}

	public function test_redirect_targets_are_not_redirected_again(): void {
		$category_target = node_resolve_404_redirect_target(
			$this->category_query( 99 ),
			'/category/' . $this->category_slug . '/page/99/'
		);
		$this->assertNotNull( $category_target );

		$this->assertNull(
			node_resolve_404_redirect_target(
				$this->category_query( 2 ),
				(string) wp_parse_url( $category_target, PHP_URL_PATH )
			)
		);

		$split_target = node_resolve_404_redirect_target( new WP_Query(), '/split-pagination-post/9/' );
		$this->assertSame( get_permalink( $this->split_post ), $split_target );

		$this->go_to( $split_target );
		$this->assertTrue( is_singular( 'post' ) );
		$this->assertFalse( is_404() );
	}

	public function test_old_slug_out_of_range_request_is_left_for_core_redirect(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_name'    => 'legacy-split-slug',
				'post_content' => 'Page 1<!--nextpage-->Page 2',
			)
		);
		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_name' => 'current-split-slug',
			)
		);

		$this->go_to( '/legacy-split-slug/9/' );
		global $wp_query;

		$this->assertTrue( is_404() );
		$this->assertNull( node_resolve_404_redirect_target( $wp_query, '/legacy-split-slug/9/' ) );
		$this->assertSame( $post_id, _find_post_by_old_slug( 'post' ) );
		$this->assertSame( 10, has_action( 'template_redirect', 'wp_old_slug_redirect' ) );
	}
}
