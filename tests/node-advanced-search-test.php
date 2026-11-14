<?php
/**
 * Advanced search contract regression tests.
 */

class Node_Advanced_Search_Test extends WP_UnitTestCase {
	private $original_get;
	private $original_post;

	public function setUp(): void {
		parent::setUp();

		$this->original_get  = $_GET;
		$this->original_post = $_POST;
		$_GET                = array();
		$_POST               = array();
	}

	public function tearDown(): void {
		$_GET  = $this->original_get;
		$_POST = $this->original_post;

		parent::tearDown();
	}

	public function test_supported_filters_share_one_args_schema(): void {
		$args = \LuminousCore\Engine\get_advanced_search_args(
			array(
				's'             => 'Node',
				'm3_cat'        => '12',
				'm3_tag'        => 'WordPress',
				'm3_min'        => '500',
				'm3_max'        => '2500',
				'm3_start_date' => '2026-01-01',
				'm3_end_date'   => '2026-07-13',
				'm3_sort'       => 'oldest',
			)
		);

		$this->assertSame( 'Node', $args['s'] );
		$this->assertSame( 12, $args['tax_query'][0]['terms'] );
		$this->assertSame( 'WordPress', $args['tax_query'][1]['terms'] );
		$this->assertSame( array( 500, 2500 ), $args['meta_query'][0]['value'] );
		$this->assertSame( '2026-01-01', $args['date_query'][0]['after'] );
		$this->assertSame( '2026-07-13', $args['date_query'][0]['before'] );
		$this->assertSame( 'date', $args['orderby'] );
		$this->assertSame( 'ASC', $args['order'] );
	}

	public function test_sort_contract_covers_each_supported_order(): void {
		$expected = array(
			'word_count' => array( 'meta_value_num', 'DESC', '_node_char_count' ),
			'oldest'     => array( 'date', 'ASC', null ),
			'newest'     => array( 'date', 'DESC', null ),
			'alpha'      => array( 'title', 'ASC', null ),
		);

		foreach ( $expected as $sort => $contract ) {
			$args = \LuminousCore\Engine\get_advanced_search_args( array( 'm3_sort' => $sort ) );
			$this->assertSame( $contract[0], $args['orderby'], $sort );
			$this->assertSame( $contract[1], $args['order'], $sort );
			$this->assertSame( $contract[2], $args['meta_key'] ?? null, $sort );
		}
	}

	public function test_ajax_count_uses_post_transport_and_applies_category(): void {
		$category_id = self::factory()->category->create();
		self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_category' => array( $category_id ),
			)
		);
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$_GET  = array( 'm3_cat' => '999999' );
		$_POST = array( 'action' => 'node_get_search_count', 'm3_cat' => (string) $category_id );

		$params = wp_unslash( $_POST );

		$this->assertSame( (string) $category_id, $params['m3_cat'] );
		$this->assertSame( 1, \LuminousCore\Engine\get_search_count_for_params( $params ) );
	}

	public function test_main_search_count_matches_ajax_count_when_pages_match_keyword(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'ゲート検証キーワード 記事',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'ゲート検証キーワード 固定ページ',
			)
		);

		$this->go_to( '/?s=' . rawurlencode( 'ゲート検証キーワード' ) );

		$main_query = $GLOBALS['wp_query'];

		$this->assertTrue( $main_query->is_search() );
		$this->assertSame( 1, (int) $main_query->found_posts, 'メイン検索は記事のみを返すこと' );
		$this->assertSame(
			\LuminousCore\Engine\get_search_count_for_params( array( 's' => 'ゲート検証キーワード' ) ),
			(int) $main_query->found_posts,
			'モーダル件数とメイン検索の件数が一致すること'
		);
	}

	public function test_media_filter_is_bound_to_query_args_not_get_globals(): void {
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<figure class="wp-block-image"><img src="image.jpg" alt=""></figure>',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Text only.</p>',
			)
		);

		$_GET = array( 'm3_media_type' => array( 'youtube' ) );
		$args = array( 'm3_media_type' => array( 'image' ) );

		$this->assertSame( 1, \LuminousCore\Engine\get_search_count_for_params( $args ) );
	}

	public function test_multiple_media_types_match_any_selected_type(): void {
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<figure class="wp-block-image"><img src="image.jpg" alt=""></figure>',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<figure class="wp-block-video"><video src="video.mp4"></video></figure>',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Text only.</p>',
			)
		);

		$this->assertSame(
			2,
			\LuminousCore\Engine\get_search_count_for_params( array( 'm3_media_type' => array( 'image', 'video' ) ) )
		);
	}

	public function test_unknown_media_type_is_ignored(): void {
		$args = \LuminousCore\Engine\get_advanced_search_args( array( 'm3_media_type' => array( 'invalid' ) ) );

		$this->assertArrayNotHasKey( 'node_media_types', $args );
	}

	public function test_search_and_date_archive_pagination_use_deterministic_id_tiebreaker(): void {
		$ids = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_title'  => 'Same Timestamp Tie',
					'post_date'   => '2026-11-10 12:00:00',
				)
			);
		}

		$original_get = $_GET;
		try {
			$_GET = array(
				's'       => '',
				'm3_sort' => 'newest',
			);
			$this->go_to( home_url( '/?s=&m3_sort=newest' ) );
			$search_orderby = $GLOBALS['wp_query']->get( 'orderby' );
			$this->assertSame(
				array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				$search_orderby
			);

			$page1 = new \WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'post__in'       => $ids,
					'posts_per_page' => 3,
					'paged'          => 1,
					'orderby'        => $search_orderby,
				)
			);
			$page2 = new \WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'post__in'       => $ids,
					'posts_per_page' => 3,
					'paged'          => 2,
					'orderby'        => $search_orderby,
				)
			);

			$p1_ids = wp_list_pluck( $page1->posts, 'ID' );
			$p2_ids = wp_list_pluck( $page2->posts, 'ID' );
			$this->assertCount( 3, $p1_ids );
			$this->assertCount( 3, $p2_ids );
			$this->assertSame( array(), array_intersect( $p1_ids, $p2_ids ) );
			$merged = array_merge( $p1_ids, $p2_ids );
			sort( $merged );
			$expected = $ids;
			sort( $expected );
			$this->assertSame( $expected, $merged );

			// Non-empty keyword search without explicit sort keeps WP relevance ordering.
			$_GET = array( 's' => 'Timestamp' );
			$this->go_to( home_url( '/?s=Timestamp' ) );
			$this->assertSame( '', $GLOBALS['wp_query']->get( 'orderby' ) );

			// Date archive sort helper adds ID tiebreaker.
			$this->go_to( add_query_arg( 'sort', 'date-asc', get_month_link( 2026, 11 ) ) );
			$this->assertTrue( $GLOBALS['wp_query']->is_date() );
			$this->assertSame(
				array(
					'date' => 'ASC',
					'ID'   => 'ASC',
				),
				$GLOBALS['wp_query']->get( 'orderby' )
			);

			// REST search results with identical timestamps return descending ID order cleanly.
			$rest_req = new \WP_REST_Request( 'GET', '/luminous-core-engine/v1/search/results' );
			$rest_req->set_param( 's', 'Same Timestamp Tie' );
			$rest_req->set_param( 'm3_sort', 'newest' );
			$rest_res = rest_do_request( $rest_req );
			$this->assertSame( 200, $rest_res->get_status() );
			$rest_data = $rest_res->get_data();
			$rest_ids  = wp_list_pluck( $rest_data['results'], 'id' );
			$expected_desc = $ids;
			rsort( $expected_desc );
			$this->assertSame( $expected_desc, $rest_ids );
		} finally {
			$_GET = $original_get;
		}
	}
}
