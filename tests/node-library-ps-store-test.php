<?php
/**
 * PlayStation Store GraphQL adapter tests (Plan A: manual fetch, excluded from L3 auto-polling).
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_PS_Store_Test extends WP_UnitTestCase {
	private const ELDEN_RING_ID = 'JP0506-PPSA03169_00-2465683171993968';

	public function set_up(): void {
		parent::set_up();
		Node_Library::instance();
		Node_Library_Store::install();
	}

	public function test_price_stores_excludes_ps_under_plan_a(): void {
		$stores = Node_Library_Store::price_stores();
		$this->assertContains( 'microsoft', $stores );
		$this->assertContains( 'steam', $stores );
		$this->assertContains( 'nintendo', $stores );
		$this->assertNotContains( 'ps', $stores );
	}

	public function test_elden_ring_fixture_maps_all_verified_fields_and_sale_end(): void {
		$sale_end_ms = ( time() + ( 2 * DAY_IN_SECONDS ) ) * 1000;
		$seen_ops    = array();
		$mock        = function ( $pre, $args, $url ) use ( &$seen_ops, $sale_end_ms ) {
			$this->assertSame( 'ja-JP', $args['headers']['x-psn-store-locale-override'] ?? '' );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$op         = (string) ( $query['operationName'] ?? '' );
			$seen_ops[] = $op;

			if ( 'metGetProductById' === $op ) {
				$product = array(
					'__typename'              => 'Product',
					'id'                      => self::ELDEN_RING_ID,
					'name'                    => 'ELDEN RING PS4 & PS5',
					'publisherName'           => '(株)フロム・ソフトウェア',
					'releaseDate'             => '2022-02-24T15:00:00Z',
					'platforms'               => array( 'PS4', 'PS5' ),
					'screenLanguages'         => array( 'ja' ),
					'spokenLanguages'         => array( 'en' ),
					'combinedLocalizedGenres' => array(
						array( 'value' => 'RPG' ),
						array( 'value' => 'ファンタジー' ),
					),
					'contentRating'           => array(
						'authority' => 'CERO',
						'name'      => 'CERO_D',
					),
					'compatibilityNotices'    => array(
						array( 'type' => 'NO_OF_PLAYERS', 'value' => '1' ),
						array( 'type' => 'NO_OF_NETWORK_PLAYERS_PS_PLUS', 'value' => '6' ),
					),
					'media'                   => array(
						array(
							'type' => 'IMAGE',
							'role' => 'EDITION_KEY_ART',
							'url'  => 'https://image.api.playstation.com/vulcan/ap/rnd/202108/0205/Vp4Fw9HN9KmcyvEXeg62yrvP.jpg',
						),
					),
					'concept'                 => array(
						'id'           => '10000333',
						'descriptions' => array(
							array( 'type' => 'SHORT', 'value' => '王となれ' ),
						),
					),
				);
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => array( 'productRetrieve' => $product ) ) ),
				);
			}

			$price_product = array(
				'__typename' => 'Product',
				'id'         => self::ELDEN_RING_ID,
				'webctas'    => array(
					array(
						'type'  => 'ADD_TO_CART',
						'price' => array(
							'applicability'        => 'APPLICABLE',
							'currencyCode'         => 'JPY',
							'basePriceValue'       => 9240,
							'discountedValue'      => 6468,
							'endTime'              => $sale_end_ms,
							'isExclusive'          => false,
							'isTiedToSubscription' => false,
							'qualifications'       => array(),
						),
					),
				),
			);
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'data' => array( 'productRetrieve' => $price_product ) ) ),
			);
		};

		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$result = Node_Library_Store::fetch( 'ps', 'https://store.playstation.com/ja-jp/product/' . self::ELDEN_RING_ID );
			$this->assertIsArray( $result );
			$this->assertSame( array( 'metGetProductById', 'productRetrieveForCtasWithPrice' ), $seen_ops );
			$this->assertSame( self::ELDEN_RING_ID, $result['meta']['store_ids']['ps'] );
			$this->assertSame( 'ELDEN RING PS4 & PS5', $result['title'] );
			$this->assertSame( '王となれ', $result['summary'] );
			$this->assertSame( '(株)フロム・ソフトウェア', $result['meta']['publisher'] );
			$this->assertSame( '2022-02-25', $result['meta']['release_date'] );
			$this->assertSame( array( 'RPG', 'ファンタジー' ), $result['meta']['genres'] );
			$this->assertSame( array( 'cero' => 'D' ), $result['meta']['rating'] );
			$this->assertTrue( $result['meta']['japanese']['ui'] );
			$this->assertFalse( $result['meta']['japanese']['audio'] );
			$this->assertArrayNotHasKey( 'subtitles', $result['meta']['japanese'] );
			$this->assertSame( array( 'online' => true ), $result['meta']['capabilities'] );
			$this->assertSame( array( 'playstation-4', 'playstation-5' ), array_column( $result['platforms'], 'hardware' ) );
			$this->assertSame( 'https://image.api.playstation.com/vulcan/ap/rnd/202108/0205/Vp4Fw9HN9KmcyvEXeg62yrvP.jpg', $result['image_url'] );
			$this->assertSame( 6468.0, $result['price']['price'] );
			$this->assertSame( 9240.0, $result['price']['list_price'] );
			$this->assertSame( gmdate( 'Y-m-d H:i:s', (int) floor( $sale_end_ms / 1000 ) ), $result['price']['sale_end'] );
			$this->assertSame( array(), $result['relations'] );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
	}

	public function test_concept_url_is_rejected_without_http_request(): void {
		$called = 0;
		$mock   = static function () use ( &$called ) {
			++$called;
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '{}' );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$err = Node_Library_Store::fetch( 'ps', 'https://store.playstation.com/ja-jp/concept/10000333' );
			$this->assertWPError( $err );
			$this->assertSame( 'concept_requires_edition_product_id', $err->get_error_code() );
			$this->assertSame( 0, $called );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
	}

	public function test_persisted_query_not_found_and_hash_filter_and_http_errors(): void {
		$captured_hash = '';
		$hash_filter   = static function ( string $hash, string $operation ): string {
			return 'metGetProductById' === $operation ? 'customhash123456' : $hash;
		};
		add_filter( 'node_library_ps_store_graphql_hash', $hash_filter, 10, 2 );

		$mode = 'pqnf';
		$mock = static function ( $pre, $args, $url ) use ( &$mode, &$captured_hash ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$ext           = json_decode( (string) ( $query['extensions'] ?? '' ), true );
			$captured_hash = (string) ( $ext['persistedQuery']['sha256Hash'] ?? '' );

			if ( 'pqnf' === $mode ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'errors' => array(
								array(
									'message'    => 'PersistedQueryNotFound',
									'extensions' => array( 'code' => 'PERSISTED_QUERY_NOT_FOUND' ),
								),
							),
						)
					),
				);
			}
			return array(
				'response' => array( 'code' => 429 ),
				'headers'  => array(),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$pqnf = Node_Library_Store::fetch( 'ps', self::ELDEN_RING_ID );
			$this->assertWPError( $pqnf );
			$this->assertSame( 'ps_store_persisted_query_not_found', $pqnf->get_error_code() );
			$this->assertStringContainsString( 'node_library_ps_store_graphql_hash', $pqnf->get_error_message() );
			$this->assertSame( 'customhash123456', $captured_hash );

			$mode = '429';
			$rate = Node_Library_Store::fetch( 'ps', self::ELDEN_RING_ID );
			$this->assertWPError( $rate );
			$this->assertSame( 'ps_store_rate_limited', $rate->get_error_code() );
			$this->assertSame( 429, $rate->get_error_data()['status'] ?? 0 );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
			remove_filter( 'node_library_ps_store_graphql_hash', $hash_filter, 10 );
		}
	}
}
