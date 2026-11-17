<?php
/** Price transitions, product ID isolation and store polling. */
require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_Prices_Test extends WP_UnitTestCase {
	private const PRODUCT = '9NKX70BBCDRN';

	private function item( string $status = 'publish' ): int {
		Node_Library::instance()->register_cpt();
		$id = self::factory()->post->create( array( 'post_type' => 'node_library', 'post_status' => $status ) );
		update_post_meta( $id, '_node_library_store_ids', array( 'microsoft' => self::PRODUCT ) );
		return $id;
	}

	private function offer( float $price, float $list ): array {
		return array( 'price' => $price, 'list_price' => $list, 'currency' => 'JPY', 'sale_end' => null );
	}

	public function test_history_only_appends_changes_and_counts_sale_starts(): void {
		$id = $this->item();
		$now = time();
		$old_sale = $now - YEAR_IN_SECONDS - DAY_IN_SECONDS;
		$this->assertTrue( Node_Library_Prices::record( $id, 'microsoft', self::PRODUCT, $this->offer( 300, 500 ), $old_sale ) );
		$this->assertFalse( Node_Library_Prices::record( $id, 'microsoft', self::PRODUCT, $this->offer( 300, 500 ), $now - 1000 ) );
		$this->assertTrue( Node_Library_Prices::record( $id, 'microsoft', self::PRODUCT, $this->offer( 250, 500 ), $now - 900 ) );
		$this->assertTrue( Node_Library_Prices::record( $id, 'microsoft', self::PRODUCT, $this->offer( 500, 500 ), $now - 800 ) );
		$sale = $this->offer( 200, 500 );
		$sale['sale_end'] = gmdate( 'c', $now + DAY_IN_SECONDS );
		$this->assertTrue( Node_Library_Prices::record( $id, 'microsoft', self::PRODUCT, $sale, $now - 700 ) );
		$stats = Node_Library_Prices::stats( $id, 'microsoft', $now );
		$this->assertSame( '200.0000', $stats['current']['price'] );
		$this->assertSame( '200.0000', $stats['lowest'] );
		$this->assertSame( 1, $stats['sales_last_year'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $now + DAY_IN_SECONDS ), $stats['sale_end'] );
		update_post_meta( $id, '_node_library_store_ids', array( 'microsoft' => '9NBLGGH537BL' ) );
		$this->assertNull( Node_Library_Prices::current( $id, 'microsoft' ) );
	}

	public function test_poll_throttles_a_failing_store_without_writing_a_price(): void {
		$id = $this->item();
		$calls = array();
		$mock = static function ( $pre, $args, $url ) use ( &$calls ) {
			$calls[] = $url;
			return new WP_Error( 'http_request_failed', 'fixture outage' );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			Node_Library_Prices::poll();
			Node_Library_Prices::poll();
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertCount( 1, $calls );
		$this->assertStringContainsString( '/products/' . self::PRODUCT, $calls[0] );
		$this->assertNull( Node_Library_Prices::current( $id, 'microsoft' ) );
		$status = get_option( 'node_library_price_store_status' );
		$this->assertGreaterThan( time(), $status['microsoft']['retry_after'] );
	}

	public function test_poll_reads_only_registered_published_items_and_preserves_zero_price(): void {
		$id = $this->item();
		$this->item( 'draft' );
		$without_id = $this->item();
		delete_post_meta( $without_id, '_node_library_store_ids' );
		$calls = array();
		$mock = static function ( $pre, $args, $url ) use ( &$calls ) {
			$calls[] = $url;
			$availability = array(
				'Actions' => array( 'Purchase' ),
				'Conditions' => array( 'StartDate' => gmdate( 'c', time() - DAY_IN_SECONDS ), 'EndDate' => gmdate( 'c', time() + DAY_IN_SECONDS ) ),
				'OrderManagementData' => array( 'Price' => array( 'CurrencyCode' => 'JPY', 'ListPrice' => 0, 'MSRP' => 0 ) ),
			);
			$data = array( 'Product' => array(
				'ProductId' => self::PRODUCT,
				'DisplaySkuAvailabilities' => array( array( 'Sku' => array(), 'Availabilities' => array( $availability ) ) ),
			) );
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $data ) );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			Node_Library_Prices::poll();
			Node_Library_Prices::poll();
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertCount( 1, $calls );
		$this->assertSame( '0.0000', Node_Library_Prices::current( $id, 'microsoft' )['price'] );
		$status = get_option( 'node_library_price_store_status' );
		$this->assertGreaterThanOrEqual( time() - MINUTE_IN_SECONDS, $status['microsoft']['last_success'] );
		wp_delete_post( $id, true );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}node_library_price_history WHERE item_id=%d", $id ) ) );
	}
}
