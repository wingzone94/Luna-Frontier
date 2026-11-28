<?php
/** Store fetch, failure handling, and confirmed persistence. @package Node */
require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_Store_Test extends WP_UnitTestCase {
	private array $posts_created = array();
	private array $users_created = array();
	private array $requests = array();
	private $mock;
	private array $previous_post;

	public function set_up(): void {
		parent::set_up();
		$this->previous_post = $_POST;
		$_POST = array();
		Node_Library::instance()->register_cpt();
		Node_Library_Store::install();
		Node_Library_Structured_Meta::register();
		$this->mock = function ( $pre, $args, $url ) {
			$this->requests[] = array( $url, $args );
			return $this->response( $url, $args );
		};
		add_filter( 'pre_http_request', $this->mock, 10, 3 );
	}

	public function tear_down(): void {
		global $wpdb;
		remove_filter( 'pre_http_request', $this->mock, 10 );
		$_POST = array();
		// persist() intentionally manages a real transaction, so clean committed fixtures explicitly.
		foreach ( $this->posts_created as $id ) {
			$wpdb->delete( $wpdb->prefix . 'node_library_relations', array( 'item_id' => $id ) );
			wp_delete_post( $id, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->users_created as $id ) {
			wp_delete_user( $id );
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%node\\_library\\_catalog\\_%'" );
		$wpdb->query( 'COMMIT' );
		$_POST = $this->previous_post;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function item(): int {
		$id = self::factory()->post->create( array( 'post_type' => 'node_library', 'post_title' => 'Original' ) );
		$this->posts_created[] = $id;
		return $id;
	}

	private function user( string $role = 'administrator' ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		$this->users_created[] = $id;
		wp_set_current_user( $id );
		return $id;
	}

	private function product(): array {
		return array(
			'ProductId' => '9NBLGGH537BL',
			'LocalizedProperties' => array( array( 'ProductTitle' => 'Minecraft', 'Images' => array( array( 'ImagePurpose' => 'BoxArt', 'Uri' => '//store-images.s-microsoft.com/example' ) ) ) ),
			'MarketProperties' => array( array( 'OriginalReleaseDate' => '2017-09-20T15:00:00Z', 'ContentRatings' => array( array( 'RatingSystem' => 'CERO', 'RatingId' => 'CERO:A' ) ), 'RelatedProducts' => array( array( 'RelatedProductId' => '9P2X6KZ61CBL', 'RelationshipType' => 'Bundle' ) ) ) ),
			'DisplaySkuAvailabilities' => array( array( 'Sku' => array( 'Properties' => array( 'IsTrial' => false ) ), 'Availabilities' => array( $this->offer() ) ) ),
		);
	}

	private function offer(): array {
		return array( 'Actions' => array( 'Purchase' ), 'Conditions' => array( 'StartDate' => '2000-01-01T00:00:00Z', 'EndDate' => gmdate( 'c', time() + DAY_IN_SECONDS ) ), 'OrderManagementData' => array( 'Price' => array( 'CurrencyCode' => 'JPY', 'ListPrice' => 1000, 'MSRP' => 2000 ) ) );
	}

	private function summary(): array {
		return array( 'productId' => '9NBLGGH537BL', 'title' => 'Minecraft', 'description' => '<b>Store description</b>', 'developerName' => 'Mojang', 'publisherName' => 'Microsoft', 'categories' => array( 'Adventure' ), 'hasAddOns' => true, 'bundledProductIds' => array(), 'availableOn' => array( 'XboxOne', 'XboxSeriesX' ), 'capabilities' => array( 'Capability4k' => '4K', 'CapabilityHDR' => 'HDR', 'XPA' => 'Play Anywhere', 'XblCrossPlatformMultiPlayer' => 'Crossplay' ), 'languagesSupported' => array( 'ja-JP' => array( 'isAudioSupported' => false, 'areSubtitlesSupported' => true, 'isInterfaceSupported' => true ) ) );
	}

	private function response( string $url, array $args ): array {
		if ( str_contains( $url, 'displaycatalog.mp.microsoft.com' ) ) {
			$data = array( 'Product' => $this->product() );
		} elseif ( str_contains( $url, '/products/' ) ) {
			$data = array( 'productSummaries' => array( $this->summary() ) );
		} else {
			$body = json_decode( $args['body'], true );
			$second = isset( $body['EncodedCT'] );
			$data = array( 'channels' => array( 'PRODUCTADDONS_9NBLGGH537BL' => array( 'products' => array( array( 'productId' => $second ? '9N6QCTXM0BC3' : '9P7C7PJ6BW3W' ) ), 'totalItems' => 2 ) ) );
			if ( ! $second ) {
				$data['channels']['PRODUCTADDONS_9NBLGGH537BL']['encodedCT'] = 'continuation';
			}
		}
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ), 'headers' => array() );
	}

	public function test_fetch_is_deterministic_and_has_complete_paged_relations(): void {
		$result = Node_Library_Store::fetch( 'microsoft', 'https://www.xbox.com/ja-JP/games/store/minecraft/9NBLGGH537BL' );
		$this->assertNotWPError( $result );
		$this->assertSame( 'Minecraft', $result['title'] );
		$this->assertSame( array( 'audio' => false, 'subtitles' => true, 'ui' => true ), $result['meta']['japanese'] );
		$this->assertSame( '2017-09-21', $result['meta']['release_date'] );
		$this->assertSame( '3840×2160', $result['meta']['capabilities']['resolution'] );
		$this->assertTrue( $result['meta']['capabilities']['hdr'] );
		$this->assertTrue( $result['meta']['capabilities']['crosssave'] );
		$this->assertSame( 1000.0, $result['price']['price'] );
		$this->assertNotEmpty( $result['price']['sale_end'] );
		$this->assertCount( 3, $result['relations'] );
		$this->assertCount( 4, $this->requests );
		$this->assertSame( 'https://store-images.s-microsoft.com/example', $result['image_url'] );
		foreach ( $this->requests as list( $url, $args ) ) {
			$this->assertContains( wp_parse_url( $url, PHP_URL_HOST ), array( 'displaycatalog.mp.microsoft.com', 'emerald.xboxservices.com' ) );
			$this->assertFalse( isset( $args['sslverify'] ) && false === $args['sslverify'] );
		}
	}

	public function test_invalid_input_never_performs_an_http_request(): void {
		$this->assertWPError( Node_Library_Store::fetch( 'microsoft', 'https://example.org/9NBLGGH537BL' ) );
		$this->assertWPError( Node_Library_Store::fetch( 'unknown', '9NBLGGH537BL' ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_dlc_failure_returns_error_without_any_partial_result(): void {
		$failure = static function ( $pre, $args, $url ) { return str_contains( $url, '/browse?' ) ? new WP_Error( 'timeout', 'test timeout' ) : $pre; };
		add_filter( 'pre_http_request', $failure, 20, 3 );
		try {
			$result = Node_Library_Store::fetch( 'microsoft', '9NBLGGH537BL' );
		} finally {
			remove_filter( 'pre_http_request', $failure, 20 );
		}
		$this->assertWPError( $result );
		$this->assertSame( 'store_connection_failed', $result->get_error_code() );
	}

	public function test_trial_license_and_membership_prices_are_not_reported_as_free(): void {
		$product = $this->product();
		$product['DisplaySkuAvailabilities'][0]['Availabilities'][0]['Actions'] = array( 'Redeem', 'License' );
		$this->assertNull( Node_Library_Microsoft_Store::price( $product ) );
		$product = $this->product();
		$product['DisplaySkuAvailabilities'][0]['Sku']['Properties']['IsTrial'] = true;
		$this->assertNull( Node_Library_Microsoft_Store::price( $product ) );
		$product = $this->product();
		$product['DisplaySkuAvailabilities'][0]['Availabilities'][0]['OrderManagementData']['PIFilter']['InclusionProperties'] = array( 'GamePass' );
		$this->assertNull( Node_Library_Microsoft_Store::price( $product ) );
	}

	public function test_preview_needs_permissions_and_does_not_save_until_confirmed(): void {
		$id = $this->item();
		$this->user( 'subscriber' );
		rest_get_server();
		$request = new WP_REST_Request( 'POST', '/node-library/v1/fetch-store' );
		$request->set_param( 'post_id', $id );
		$request->set_param( 'input', '9NBLGGH537BL' );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
		$this->user();
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( metadata_exists( 'post', $id, '_node_library_catalog' ) );
		$this->assertSame( 'Original', get_the_title( $id ) );
		update_post_meta( $id, '_node_library_store_ids', $data['meta']['store_ids'] );
		$_POST = array( 'node_library_catalog_nonce' => wp_create_nonce( 'node_library_save_catalog' ), 'node_library_catalog_ticket' => $data['ticket'] );
		Node_Library_Store::save_form( $id );
		$this->assertSame( 'Minecraft', get_post_meta( $id, '_node_library_catalog', true )['microsoft']['title'] );
		global $wpdb;
		$this->assertSame( '3', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}node_library_relations WHERE item_id = %d", $id ) ) );
	}

	public function test_invalid_relation_preserves_previous_snapshot_and_relations(): void {
		$id = $this->item();
		$data = Node_Library_Store::fetch( 'microsoft', '9NBLGGH537BL' );
		$this->assertTrue( Node_Library_Store::persist( $id, $data ) );
		$data['relations'][0]['external_id'] = 'invalid';
		$this->assertWPError( Node_Library_Store::persist( $id, $data ) );
		$this->assertNotSame( $data, get_post_meta( $id, '_node_library_catalog', true )['microsoft'] );
	}
	public function test_unknown_japanese_flags_stay_unknown(): void {
		$summary = $this->summary();
		unset( $summary['languagesSupported'] );
		$result = Node_Library_Microsoft_Store::normalize( $this->product(), $summary, array() );
		$this->assertSame( array(), $result['meta']['japanese'] );
	}

	public function test_repeated_dlc_continuation_token_is_an_error(): void {
		$repeat = function ( $pre, $args, $url ) {
			if ( str_contains( $url, '/browse?' ) ) {
				$data = json_decode( $pre['body'], true );
				$data['channels']['PRODUCTADDONS_9NBLGGH537BL']['encodedCT'] = 'repeated';
				$pre['body'] = wp_json_encode( $data );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $repeat, 20, 3 );
		try {
			$this->assertWPError( Node_Library_Store::fetch( 'microsoft', '9NBLGGH537BL' ) );
		} finally {
			remove_filter( 'pre_http_request', $repeat, 20 );
		}
	}

	public function test_invalid_json_is_an_error(): void {
		$invalid = static fn() => array( 'response' => array( 'code' => 200 ), 'body' => 'invalid json', 'headers' => array() );
		add_filter( 'pre_http_request', $invalid, 20 );
		try {
			$this->assertWPError( Node_Library_Store::fetch( 'microsoft', '9NBLGGH537BL' ) );
		} finally {
			remove_filter( 'pre_http_request', $invalid, 20 );
		}
	}

	public function test_snapshot_write_failure_rolls_back_relation_replacement(): void {
		global $wpdb;
		$id = $this->item();
		$catalog = Node_Library_Store::fetch( 'microsoft', '9NBLGGH537BL' );
		$this->assertTrue( Node_Library_Store::persist( $id, $catalog ) );
		$before = $wpdb->get_results( $wpdb->prepare( "SELECT external_id, relation FROM {$wpdb->prefix}node_library_relations WHERE item_id=%d ORDER BY id", $id ), ARRAY_A );
		$changed = $catalog;
		$changed['title'] = 'Changed';
		$changed['relations'] = array();
		$block = static fn( $pre, $id, $key ) => '_node_library_catalog' === $key ? false : $pre;
		add_filter( 'update_post_metadata', $block, 10, 3 );
		try {
			$this->assertWPError( Node_Library_Store::persist( $id, $changed ) );
		} finally {
			remove_filter( 'update_post_metadata', $block, 10 );
		}
		$this->assertSame( $before, $wpdb->get_results( $wpdb->prepare( "SELECT external_id, relation FROM {$wpdb->prefix}node_library_relations WHERE item_id=%d ORDER BY id", $id ), ARRAY_A ) );
		$this->assertSame( 'Minecraft', get_post_meta( $id, '_node_library_catalog', true )['microsoft']['title'] );
	}

	public function test_preview_ticket_cannot_be_used_by_another_editor_or_with_changed_store_id(): void {
		$id = $this->item();
		$owner = $this->user();
		$request = new WP_REST_Request( 'POST', '/node-library/v1/fetch-store' );
		$request->set_param( 'post_id', $id );
		$request->set_param( 'input', '9NBLGGH537BL' );
		$preview = rest_get_server()->dispatch( $request )->get_data();
		$this->user();
		$_POST = array( 'node_library_catalog_nonce' => wp_create_nonce( 'node_library_save_catalog' ), 'node_library_catalog_ticket' => $preview['ticket'] );
		Node_Library_Store::save_form( $id );
		$this->assertFalse( metadata_exists( 'post', $id, '_node_library_catalog' ) );
		wp_set_current_user( $owner );
		$_POST['node_library_catalog_nonce'] = wp_create_nonce( 'node_library_save_catalog' );
		update_post_meta( $id, '_node_library_store_ids', array( 'microsoft' => '9P2X6KZ61CBL' ) );
		Node_Library_Store::save_form( $id );
		$this->assertFalse( metadata_exists( 'post', $id, '_node_library_catalog' ) );
	}

	public function test_deleting_item_cleans_owned_relations(): void {
		global $wpdb;
		$id = $this->item();
		$this->assertTrue( Node_Library_Store::persist( $id, Node_Library_Store::fetch( 'microsoft', '9NBLGGH537BL' ) ) );
		wp_delete_post( $id, true );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}node_library_relations WHERE item_id=%d", $id ) ) );
	}

	public function test_cero_z_payment_instrument_filter_is_allowed_while_affirmations_are_excluded(): void {
		$product = $this->product();
		$product['DisplaySkuAvailabilities'][0]['Availabilities'][0]['OrderManagementData']['PIFilter']['InclusionProperties'] = array(
			'credit_card',
			'ewallet:token',
			'Token',
			'legacy:CreditCard',
			'legacy:Tokens',
		);
		$offer = Node_Library_Microsoft_Store::price( $product );
		$this->assertIsArray( $offer, 'CERO Z の決済手段制限のみのオファーは価格取得できること' );
		$this->assertSame( 'JPY', $offer['currency'] );

		$product_remediation = $this->product();
		$product_remediation['DisplaySkuAvailabilities'][0]['Availabilities'][0]['RemediationRequired'] = true;
		$this->assertNull( Node_Library_Microsoft_Store::price( $product_remediation ), 'RemediationRequired=true は除外されること' );

		$product_affirmation = $this->product();
		$product_affirmation['DisplaySkuAvailabilities'][0]['Availabilities'][0]['Conditions']['EligibilityProperties']['Affirmations'] = array( 'gamepass-affirmation' );
		$this->assertNull( Node_Library_Microsoft_Store::price( $product_affirmation ), 'EligibilityProperties.Affirmations ありは除外されること' );
	}

	public function test_non_purchasable_parent_product_returns_price_notice_and_sku_url_extracts(): void {
		$product = $this->product();
		$product['DisplaySkuAvailabilities'][0]['Availabilities'][0]['Actions'] = array( 'Details' );
		$summary = array(
			'title'    => 'Grand Theft Auto VI',
			'editions' => array( '9NL3WWNZLZZN', '9NP019G7NZXT' ),
		);
		$normalized = Node_Library_Microsoft_Store::normalize( $product, $summary, array() );
		$this->assertNull( $normalized['price'] );
		$this->assertStringContainsString( '9NP019G7NZXT', $normalized['price_notice'] );

		$extracted = Node_Library_Store_ID_Migration::extract( 'https://www.xbox.com/ja-JP/games/store/p/9n431px143p8/0010' );
		$this->assertSame( array( 'store' => 'microsoft', 'id' => '9N431PX143P8' ), $extracted );
	}

}
