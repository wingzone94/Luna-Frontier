<?php
/** Steam catalogue mapping and store-specific validation. */
require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_Steam_Store_Test extends WP_UnitTestCase {
	private array $requests = array();
	private $mock;

	public function set_up(): void {
		parent::set_up();
		$this->requests = array();
		$this->mock = function ( $pre, $args, $url ) {
			$this->requests[] = $url;
			$data = array(
				'steam_appid' => 620,
				'name' => 'Portal 2',
				'developers' => array( 'Valve' ),
				'publishers' => array( 'Valve' ),
				'release_date' => array( 'coming_soon' => false, 'date' => '18 Apr, 2011' ),
				'supported_languages' => 'English<strong>*</strong>, Japanese<br><strong>*</strong>languages with full audio support',
				'price_overview' => array( 'currency' => 'JPY', 'initial' => 140000, 'final' => 70000 ),
				'genres' => array( array( 'description' => 'Adventure' ) ),
				'dlc' => array( 323180 ),
				'platforms' => array( 'windows' => true, 'mac' => false, 'linux' => true ),
				'categories' => array( array( 'id' => 38, 'description' => 'Online Co-op' ) ),
			);
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 620 => array( 'success' => true, 'data' => $data ) ) ) );
		};
		add_filter( 'pre_http_request', $this->mock, 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->mock, 10 );
		parent::tear_down();
	}

	public function test_portal_catalogue_has_jpy_japanese_full_audio_and_dlc_with_unknown_subtitles(): void {
		$result = Node_Library_Store::fetch( 'steam', 'https://store.steampowered.com/app/620/Portal_2/?l=japanese' );
		$this->assertIsArray( $result );
		$this->assertSame( '620', $result['meta']['store_ids']['steam'] );
		$this->assertSame( '2011-04-18', $result['meta']['release_date'] );
		$this->assertTrue( $result['meta']['japanese']['ui'] );
		$this->assertFalse( $result['meta']['japanese']['audio'] );
		$this->assertArrayNotHasKey( 'subtitles', $result['meta']['japanese'] );
		$this->assertEquals( array( 'price' => 700, 'list_price' => 1400, 'currency' => 'JPY', 'sale_end' => null ), $result['price'] );
		$this->assertSame( array( array( 'external_id' => '323180', 'relation' => 'dlc', 'store' => 'steam' ) ), $result['relations'] );
		$this->assertStringContainsString( 'appids=620&cc=jp', $this->requests[0] );
		$this->assertSame( $result['price'], Node_Library_Store::fetch_price( 'steam', '620' ) );
	}

	public function test_foreign_url_and_different_app_response_do_not_write_or_masquerade_as_a_product(): void {
		$this->assertWPError( Node_Library_Store::fetch( 'steam', 'https://example.test/app/620' ) );
		$this->assertSame( array(), $this->requests );
		$this->assertWPError( Node_Library_Store::fetch( 'steam', '730' ) );
	}
}
