<?php
/** PlayStation IDs, public purchase prices, and platform-specific editions. */
require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_PS_Store_Test extends WP_UnitTestCase {
	public function test_public_price_and_distinct_editions_from_persisted_operations(): void {
		$ps5 = 'JP9000-PPSA21559_00-0000000000000000';
		$ps4 = 'JP9000-CUSA21559_00-0000000000000000';
		$requests = array();
		$mock = function ( $pre, $args, $url ) use ( &$requests, $ps5, $ps4 ) {
			parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$variables = json_decode( $query['variables'], true );
			$id = $variables['productId'];
			$requests[] = $id;
			$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
			$price = array(
				'applicability' => 'APPLICABLE', 'currencyCode' => 'JPY',
				'basePriceValue' => 7980, 'discountedValue' => 5266,
				'endTime' => ( time() + DAY_IN_SECONDS ) * 1000,
			);
			$product = array( 'id' => $id, '__typename' => 'Product', 'name' => 'Fixture' );
			if ( 'productRetrieveForCtasWithPrice' === $query['operationName'] ) {
				$product['webctas'] = array(
					array( 'type' => 'ADD_TO_CART', 'price' => $price ),
					array( 'type' => 'ADD_TO_CART', 'price' => array_merge( $price, array( 'discountedValue' => 0, 'isTiedToSubscription' => true ) ) ),
				);
			} else {
				$product['concept']['products'] = array(
					array( 'id' => $ps5, 'platforms' => array( 'PS5' ) ),
					array( 'id' => $ps4, 'platforms' => array( 'PS4' ) ),
				);
			}
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'data' => array( 'productRetrieve' => $product ) ) ) );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$catalog = Node_Library_Store::fetch( 'ps', $ps5 );
			$this->assertIsArray( $catalog );
			$this->assertSame( $ps5, $catalog['id'] );
			$this->assertSame( 5266.0, $catalog['price']['price'] );
			$this->assertNotNull( $catalog['price']['sale_end'] );
			$this->assertSame( 'playstation-5', $catalog['platforms'][0]['hardware'] );
			$this->assertSame( array( array( 'external_id' => $ps4, 'relation' => 'edition', 'store' => 'ps' ) ), $catalog['relations'] );
			$catalog = Node_Library_Store::fetch( 'ps', $ps4 );
			$this->assertSame( $ps4, $catalog['id'] );
			$this->assertSame( 'playstation-4', $catalog['platforms'][0]['hardware'] );
			$this->assertSame( array( $ps5, $ps5, $ps4, $ps4 ), $requests );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
	}
}
