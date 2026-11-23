<?php
/**
 * Steam catalogue mapping, relation persistence, and store-specific validation.
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_Steam_Store_Test extends WP_UnitTestCase {
	/** @var array<int, string> */
	private array $requests = array();

	/** @var callable|null */
	private $mock = null;

	public function set_up(): void {
		parent::set_up();
		Node_Library::instance();
		Node_Library_Store::install();
		$this->requests = array();
		$this->mock     = function ( $pre, $args, $url ) {
			$this->requests[] = $url;
			return $this->fixture_response( $url );
		};
		add_filter( 'pre_http_request', $this->mock, 10, 3 );
	}

	public function tear_down(): void {
		if ( null !== $this->mock ) {
			remove_filter( 'pre_http_request', $this->mock, 10 );
		}
		parent::tear_down();
	}

	/**
	 * Recorded appdetails fixtures (no live network in PHPUnit).
	 *
	 * @return array<string, mixed>
	 */
	private function fixture_response( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$appid = (string) ( $query['appids'] ?? '' );

		if ( '620' === $appid ) {
			$data = array(
				'steam_appid'         => 620,
				'name'                => 'Portal 2',
				'short_description'   => 'ポータル2の紹介文',
				'header_image'        => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/620/header.jpg',
				'developers'          => array( 'Valve' ),
				'publishers'          => array( 'Valve' ),
				'release_date'        => array( 'coming_soon' => false, 'date' => '2011年4月19日' ),
				'supported_languages' => '英語<strong>*</strong>, 日本語<br><strong>*</strong>フル音声対応言語',
				'price_overview'      => array( 'currency' => 'JPY', 'initial' => 140000, 'final' => 70000 ),
				'genres'              => array(
					array( 'id' => '1', 'description' => 'アクション' ),
					array( 'id' => '25', 'description' => 'アドベンチャー' ),
				),
				'dlc'                 => array( 323180, 323180 ),
				'platforms'           => array( 'windows' => true, 'mac' => false, 'linux' => true ),
				'categories'          => array(
					array( 'id' => 38, 'description' => 'オンライン協力プレイ' ),
					array( 'id' => 27, 'description' => 'クロスプラットフォームマルチプレイヤー' ),
				),
			);
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => wp_json_encode( array( '620' => array( 'success' => true, 'data' => $data ) ) ),
			);
		}

		if ( '323180' === $appid ) {
			$data = array(
				'steam_appid'         => 323180,
				'name'                => 'Portal 2 Soundtrack',
				'developers'          => array( 'Aperture Science Psychoacoustics Laboratory' ),
				'publishers'          => array( 'Valve' ),
				'release_date'        => array( 'coming_soon' => false, 'date' => '24 Sep, 2014' ),
				'supported_languages' => 'English<strong>*</strong>, Japanese<strong>*</strong><br><strong>*</strong>languages with full audio support',
				'is_free'             => true,
				'fullgame'            => array( 'appid' => '620', 'name' => 'Portal 2' ),
				'genres'              => array( array( 'id' => '25', 'description' => 'アドベンチャー' ) ),
				'platforms'           => array( 'windows' => true, 'mac' => true, 'linux' => false ),
				'categories'          => array(),
			);
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => wp_json_encode( array( '323180' => array( 'success' => true, 'data' => $data ) ) ),
			);
		}

		if ( '429000' === $appid ) {
			return array(
				'response' => array( 'code' => 429 ),
				'headers'  => array(),
				'body'     => '',
			);
		}

		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => wp_json_encode( array( '620' => array( 'success' => true, 'data' => array( 'steam_appid' => 620, 'name' => 'Portal 2' ) ) ) ),
		);
	}

	public function test_japanese_fixture_maps_l1_meta_price_japanese_and_dlc_relations(): void {
		$result = Node_Library_Store::fetch( 'steam', 'https://store.steampowered.com/app/620/Portal_2/?l=japanese' );
		$this->assertIsArray( $result );
		$this->assertSame( 'steam', $result['store'] );
		$this->assertSame( '620', $result['id'] );
		$this->assertSame( 'Portal 2', $result['title'] );
		$this->assertSame( 'ポータル2の紹介文', $result['summary'] );
		$this->assertSame( 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/620/header.jpg', $result['image_url'] );
		$this->assertSame( '620', $result['meta']['store_ids']['steam'] );
		$this->assertSame( 'Valve', $result['meta']['developer'] );
		$this->assertSame( 'Valve', $result['meta']['publisher'] );
		$this->assertSame( array( 'アクション', 'アドベンチャー' ), $result['meta']['genres'] );
		$this->assertSame( '2011-04-19', $result['meta']['release_date'] );
		$this->assertTrue( $result['meta']['japanese']['ui'] );
		$this->assertFalse( $result['meta']['japanese']['audio'] );
		$this->assertArrayNotHasKey( 'subtitles', $result['meta']['japanese'] );
		$this->assertTrue( $result['meta']['capabilities']['online'] );
		$this->assertTrue( $result['meta']['capabilities']['crossplay'] );
		$this->assertSame( array( array( 'category' => 'pc', 'hardware' => 'windows-pc' ) ), $result['platforms'] );
		$this->assertEquals( array( 'price' => 700, 'list_price' => 1400, 'currency' => 'JPY', 'sale_end' => null ), $result['price'] );
		$this->assertSame( array( array( 'external_id' => '323180', 'relation' => 'dlc', 'store' => 'steam' ) ), $result['relations'] );
		$this->assertStringContainsString( 'appids=620', $this->requests[0] );
		$this->assertStringContainsString( 'cc=jp', $this->requests[0] );
		$this->assertStringContainsString( 'l=japanese', $this->requests[0] );
		$this->assertSame( $result['price'], Node_Library_Store::fetch_price( 'steam', '620' ) );
		$this->assertContains( 'steam', Node_Library_Store::price_stores() );
	}

	public function test_dlc_fixture_maps_full_audio_english_date_free_price_and_included_in_relation(): void {
		$result = Node_Library_Store::fetch( 'steam', '323180' );
		$this->assertIsArray( $result );
		$this->assertSame( '2014-09-24', $result['meta']['release_date'] );
		$this->assertTrue( $result['meta']['japanese']['ui'] );
		$this->assertTrue( $result['meta']['japanese']['audio'] );
		$this->assertArrayNotHasKey( 'subtitles', $result['meta']['japanese'] );
		$this->assertEquals( array( 'price' => 0, 'list_price' => 0, 'currency' => 'JPY', 'sale_end' => null ), $result['price'] );
		$this->assertSame( array( array( 'external_id' => '620', 'relation' => 'included_in', 'store' => 'steam' ) ), $result['relations'] );
	}

	public function test_persist_saves_steam_relations_with_external_id_scheme(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => 'Portal 2',
			)
		);
		$catalog = Node_Library_Store::fetch( 'steam', '620' );
		$this->assertIsArray( $catalog );
		$this->assertTrue( Node_Library_Store::persist( (int) $post_id, $catalog ) );

		global $wpdb;
		$table = $wpdb->prefix . 'node_library_relations';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT item_id, related_item_id, external_id, relation, store FROM {$table} WHERE item_id = %d",
				$post_id
			),
			ARRAY_A
		);
		$this->assertCount( 1, $rows );
		$this->assertNull( $rows[0]['related_item_id'] );
		$this->assertSame( '323180', $rows[0]['external_id'] );
		$this->assertSame( 'dlc', $rows[0]['relation'] );
		$this->assertSame( 'steam', $rows[0]['store'] );
	}

	public function test_rate_limit_foreign_url_and_mismatched_app_return_wp_error(): void {
		$rate_limited = Node_Library_Store::fetch( 'steam', '429000' );
		$this->assertWPError( $rate_limited );
		$this->assertSame( 'steam_rate_limited', $rate_limited->get_error_code() );
		$this->assertSame( 429, $rate_limited->get_error_data()['status'] ?? 0 );

		$this->requests = array();
		$this->assertWPError( Node_Library_Store::fetch( 'steam', 'https://example.test/app/620' ) );
		$this->assertSame( array(), $this->requests );
		$this->assertWPError( Node_Library_Store::fetch( 'steam', '730' ) );
	}
}
