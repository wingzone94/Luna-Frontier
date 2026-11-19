<?php
/**
 * Library item spec table and Product JSON-LD.
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

/**
 * Detail skeleton for /library/<slug>/.
 */
class Node_Library_Single_Spec_Test extends WP_UnitTestCase {
	private int $item_id;

	public function set_up(): void {
		parent::set_up();
		Node_Library::instance();
		$this->item_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => 'Spec Sample',
				'post_name'   => 'spec-sample',
			)
		);
		update_post_meta( $this->item_id, '_node_library_type', 'game' );
		update_post_meta( $this->item_id, '_node_library_summary', "一行目\n二行目" );
		update_post_meta( $this->item_id, '_node_library_release_date', '2024-01-01' );
		update_post_meta(
			$this->item_id,
			'_node_library_links',
			array(
				array(
					'platform' => 'Steam',
					'url'      => 'https://example.com/steam',
					'category' => 'auto',
					'hardware' => 'windows-pc',
				),
				array(
					'platform' => 'Nintendo Switch',
					'url'      => 'https://example.com/switch',
					'category' => 'console',
					'hardware' => 'nintendo-switch',
				),
			)
		);
	}

	public function test_spec_rows_use_only_stored_summary_hardware_and_links(): void {
		$rows   = node_library_get_spec_rows( $this->item_id );
		$labels = array_column( $rows, 'label' );

		$this->assertSame( array( '種別', '紹介文', '対応機種', '入手先' ), $labels );
		$this->assertSame( 'ゲーム', $rows[0]['value'] );
		$this->assertSame( "一行目\n二行目", $rows[1]['value'] );
		$this->assertSame( 'Windows PC、Nintendo Switch', $rows[2]['value'] );
		$this->assertSame( 'Steam', $rows[3]['links'][0]['platform'] );
		$this->assertSame( 'https://example.com/steam', $rows[3]['links'][0]['url'] );
		$this->assertNotContains( '発売日', $labels );
		$this->assertNotContains( '価格', $labels );
	}

	public function test_spec_table_renders_and_leaves_the_extension_hook(): void {
		$seen = false;
		add_action(
			'node_library_single_after_specs',
			static function () use ( &$seen ): void {
				$seen = true;
			}
		);

		ob_start();
		node_library_render_spec_table( $this->item_id );
		$html = (string) ob_get_clean();

		$this->assertTrue( $seen );
		$this->assertStringContainsString( 'node-library-specs', $html );
		$this->assertStringContainsString( '対応機種', $html );
		$this->assertStringContainsString( 'Windows PC、Nintendo Switch', $html );
		$this->assertStringContainsString( 'https://example.com/switch', $html );
	}

	public function test_product_jsonld_parses_and_omits_offers_until_extended(): void {
		$data = node_library_get_product_jsonld( $this->item_id );
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$decoded = json_decode( (string) $json, true );

		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'https://schema.org', $decoded['@context'] );
		$this->assertSame( 'Product', $decoded['@type'] );
		$this->assertSame( 'Spec Sample', $decoded['name'] );
		$this->assertSame( "一行目\n二行目", $decoded['description'] );
		$this->assertSame( 'ゲーム', $decoded['category'] );
		$this->assertSame( '対応機種', $decoded['additionalProperty'][0]['name'] );
		$this->assertSame( 'Windows PC、Nintendo Switch', $decoded['additionalProperty'][0]['value'] );
		$this->assertSame(
			array( 'https://example.com/steam', 'https://example.com/switch' ),
			$decoded['sameAs']
		);
		$this->assertArrayNotHasKey( 'offers', $decoded );
		$this->assertArrayNotHasKey( 'price', $decoded );

		ob_start();
		node_library_render_product_jsonld( $this->item_id );
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/<script type="application\/ld\+json">\{.*\}<\/script>/s', $html );
		preg_match( '/<script type="application\/ld\+json">(.*)<\/script>/s', $html, $matches );
		$from_html = json_decode( $matches[1], true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
		$this->assertSame( 'Product', $from_html['@type'] );
		$this->assertArrayNotHasKey( 'offers', $from_html );
	}

	public function test_offer_filter_is_the_only_way_price_enters_jsonld(): void {
		$callback = static function (): array {
			return array(
				'@type'         => 'Offer',
				'priceCurrency' => 'JPY',
				'price'         => '1000',
			);
		};
		add_filter( 'node_library_product_jsonld_offers', $callback );

		$data = node_library_get_product_jsonld( $this->item_id );
		remove_filter( 'node_library_product_jsonld_offers', $callback );

		$this->assertSame( 'Offer', $data['offers']['@type'] );
		$this->assertSame( '1000', $data['offers']['price'] );
		$this->assertArrayNotHasKey( 'offers', node_library_get_product_jsonld( $this->item_id ) );
	}
}
