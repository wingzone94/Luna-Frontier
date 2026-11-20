<?php
/** Nintendo product identity and downloadable offers. */
require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_Nintendo_Store_Test extends WP_UnitTestCase {
	public function test_model_lookup_matches_hardware_and_excludes_dlc_before_resolving_nsuid(): void {
		$base = array(
			'nsuid' => '70010000096306', 'title' => 'ドンキーコング バナンザ',
			'icode' => 'AAACA', 'hard' => '05_BEE', 'sform' => 'BEE_DOWNLOADABLE',
			'ssitu' => 'onsale', 'dprice' => 7980, 'pprice' => 8980,
			'current_price' => 7980, 'sdate' => '2025.7.17', 'lang' => array( 'ja', 'en' ),
		);
		$fixture = array(
			array_merge( $base, array( 'nsuid' => '70050000059660', 'sform' => 'DLC' ) ),
			array_merge( $base, array( 'nsuid' => '70010000000828', 'hard' => '1_HAC', 'sform' => 'HAC_DOWNLOADABLE' ) ),
			$base,
		);
		$mock = static function ( $pre, $args, $url ) use ( &$fixture ) {
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
			$this->assertSame( array( 'ui' => true ), $result['meta']['japanese'] );
			$this->assertSame( 7980.0, $result['price']['list_price'] );
			$fixture[] = array_merge( $base, array( 'nsuid' => '70010000011111' ) );
			$error = Node_Library_Store::fetch( 'nintendo', 'BEE_P_AAACA' );
			$this->assertWPError( $error );
			$this->assertSame( 400, $error->get_error_data()['status'] );
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
	}

	public function test_sale_price_and_end_are_based_on_the_download_offer(): void {
		$mock = static function () {
			$item = array(
				'nsuid' => '70010000096306', 'title' => 'Nintendo test',
				'ssitu' => 'onsale', 'dprice' => 8000, 'current_price' => 6000, 'sedate' => '2027-01-02 23:59:00',
			);
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'status' => 0, 'result' => array( 'items' => array( $item ) ) ) ) );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$this->assertSame(
				array( 'price' => 6000.0, 'list_price' => 8000.0, 'currency' => 'JPY', 'sale_end' => '2027-01-02 14:59:00' ),
				Node_Library_Store::fetch_price( 'nintendo', '70010000096306' )
			);
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
	}
}
