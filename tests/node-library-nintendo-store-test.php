<?php
/**
 * Nintendo product identity, downloadable offers, and cnsuid relations.
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_Nintendo_Store_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Node_Library::instance();
		Node_Library_Store::install();
	}

	public function test_nsuid_fixture_maps_meta_cnsuid_relations_and_omits_japanese_flags(): void {
		$item = array(
			'id'            => '70010000000026',
			'nsuid'         => '70010000000026',
			'title'         => 'ゼルダの伝説 ブレス オブ ザ ワイルド',
			'text'          => '広大な世界を舞台にした冒険。',
			'maker'         => '任天堂',
			'genre'         => array( 'アクション', 'アドベンチャー' ),
			'dsdate'        => '2017-03-03 00:00:00',
			'sdate'         => '2017.3.3',
			'hard'          => '1_HAC',
			'sform'         => 'HAC_DOWNLOADABLE',
			'ssitu'         => 'onsale',
			'dprice'        => 7678,
			'current_price' => 7678,
			'sale_flg'      => '0',
			'cero'          => array( '2' ),
			'lang'          => array( 'ja', 'en' ),
			'nplayer'       => array( '1-8' ),
			'iurl'          => '66a1e231272eb0241d0aa0a81f9eb0416e9bd5b205759caaac71220aebdc3a84',
			'cnsuid'        => array( '70070000000024', '70070000000024', 'invalid' ),
		);
		$mock = static function () use ( $item ) {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'status' => 0, 'result' => array( 'items' => array( $item ) ) ) ),
			);
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$result = Node_Library_Store::fetch( 'nintendo', 'https://store-jp.nintendo.com/item/software/D70010000000026' );
			$this->assertIsArray( $result );
			$this->assertSame( '70010000000026', $result['meta']['store_ids']['nintendo'] );
			$this->assertSame( '広大な世界を舞台にした冒険。', $result['summary'] );
			$this->assertSame( '任天堂', $result['meta']['publisher'] );
			$this->assertArrayNotHasKey( 'developer', $result['meta'] );
			$this->assertSame( array( 'アクション', 'アドベンチャー' ), $result['meta']['genres'] );
			$this->assertSame( '2017-03-03', $result['meta']['release_date'] );
			$this->assertSame( array( 'cero' => 'B' ), $result['meta']['rating'] );
			$this->assertSame( array(), $result['meta']['japanese'] );
			$this->assertSame( array( 'online' => true ), $result['meta']['capabilities'] );
			$this->assertSame( 'https://img-eshop.cdn.nintendo.net/i/66a1e231272eb0241d0aa0a81f9eb0416e9bd5b205759caaac71220aebdc3a84.jpg', $result['image_url'] );
			$this->assertSame( 'nintendo-switch', $result['platforms'][0]['hardware'] );
			$this->assertSame( array( 'price' => 7678.0, 'list_price' => 7678.0, 'currency' => 'JPY', 'sale_end' => null ), $result['price'] );
			$this->assertSame(
				array(
					array(
						'external_id' => '70070000000024',
						'relation'    => 'dlc',
						'store'       => 'nintendo',
					),
				),
				$result['relations']
			);
			$this->assertContains( 'nintendo', Node_Library_Store::price_stores() );

			// Persist into {prefix}node_library_relations with external_id scheme.
			$post_id = self::factory()->post->create(
				array(
					'post_type'   => 'node_library',
					'post_status' => 'publish',
					'post_title'  => $result['title'],
				)
			);
			$this->assertTrue( Node_Library_Store::persist( (int) $post_id, $result ) );
			global $wpdb;
			$table = $wpdb->prefix . 'node_library_relations';
			$rows  = $wpdb->get_results(
				$wpdb->prepare( "SELECT related_item_id, external_id, relation, store FROM {$table} WHERE item_id = %d", $post_id ),
				ARRAY_A
			);
			$this->assertCount( 1, $rows );
			$this->assertNull( $rows[0]['related_item_id'] );
			$this->assertSame( '70070000000024', $rows[0]['external_id'] );
			$this->assertSame( 'dlc', $rows[0]['relation'] );
			$this->assertSame( 'nintendo', $rows[0]['store'] );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
	}

	public function test_model_lookup_matches_hardware_and_sdate_fallback(): void {
		$base = array(
			'nsuid'         => '70010000096306',
			'title'         => 'ドンキーコング バナンザ',
			'icode'         => 'AAACA',
			'hard'          => '05_BEE',
			'sform'         => 'BEE_DOWNLOADABLE',
			'ssitu'         => 'onsale',
			'dprice'        => 7980,
			'pprice'        => 8980,
			'current_price' => 7980,
			'sdate'         => '2025.7.17',
			'lang'          => array( 'ja', 'en' ),
		);
		$fixture = array(
			array_merge( $base, array( 'nsuid' => '70050000059660', 'sform' => 'DLC' ) ),
			array_merge( $base, array( 'nsuid' => '70010000000828', 'hard' => '1_HAC', 'sform' => 'HAC_DOWNLOADABLE' ) ),
			$base,
		);
		$mock = static function () use ( &$fixture ) {
			$body = array( 'status' => 0, 'result' => array( 'items' => $fixture ) );
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $body ) );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$result = Node_Library_Store::fetch( 'nintendo', 'https://store-jp.nintendo.com/item/software/BEE_P_AAACA' );
			$this->assertIsArray( $result );
			$this->assertSame( '70010000096306', $result['meta']['store_ids']['nintendo'] );
			$this->assertSame( 'nintendo-switch-2', $result['platforms'][0]['hardware'] );
			$this->assertSame( '2025-07-17', $result['meta']['release_date'] );
			$this->assertSame( array(), $result['meta']['japanese'] );
			$this->assertSame( 7980.0, $result['price']['list_price'] );

			$fixture[] = array_merge( $base, array( 'nsuid' => '70010000011111' ) );
			$error     = Node_Library_Store::fetch( 'nintendo', 'BEE_P_AAACA' );
			$this->assertWPError( $error );
			$this->assertSame( 400, $error->get_error_data()['status'] );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
	}

	public function test_sale_price_and_sedate_jst_to_utc_and_fallback_on_bad_date(): void {
		$item = array(
			'nsuid'         => '70010000122712',
			'title'         => 'アルファディアⅢ Nintendo Switch 2 Edition',
			'ssitu'         => 'onsale',
			'dprice'        => 1640,
			'sprice'        => 1066,
			'current_price' => 1066,
			'sale_flg'      => '1',
			'sedate'        => '2026-12-02 23:59:59',
		);
		$mock = static function () use ( &$item ) {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'status' => 0, 'result' => array( 'items' => array( $item ) ) ) ),
			);
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$this->assertSame(
				array(
					'price'      => 1066.0,
					'list_price' => 1640.0,
					'currency'   => 'JPY',
					'sale_end'   => '2026-12-02 14:59:59',
				),
				Node_Library_Store::fetch_price( 'nintendo', '70010000122712' )
			);

			$item['sedate'] = 'invalid-format';
			$fallback       = Node_Library_Store::fetch_price( 'nintendo', '70010000122712' );
			$this->assertSame( 1066.0, $fallback['price'] );
			$this->assertNull( $fallback['sale_end'] );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
	}

	public function test_http_errors_and_rate_limit_return_wp_error_without_partial_data(): void {
		$code = 429;
		$mock = static function () use ( &$code ) {
			return array(
				'response' => array( 'code' => $code ),
				'headers'  => array(),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$rate = Node_Library_Store::fetch( 'nintendo', '70010000000026' );
			$this->assertWPError( $rate );
			$this->assertSame( 'nintendo_rate_limited', $rate->get_error_code() );
			$this->assertSame( 429, $rate->get_error_data()['status'] );

			$code = 503;
			$err  = Node_Library_Store::fetch( 'nintendo', '70010000000026' );
			$this->assertWPError( $err );
			$this->assertSame( 'nintendo_catalog_failed', $err->get_error_code() );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
	}
}
