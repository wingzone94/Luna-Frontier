<?php
/**
 * UI unification regression tests for Luna Library archive and single templates.
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

/**
 * Display state coverage: with/without image, with/without price,
 * many/few stores, and empty summary.
 */
class Node_Library_UI_Unification_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Node_Library::instance();
		Node_Library::instance()->register_cpt();
	}

	private function create_library_item( array $args = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => $args['title'] ?? 'Sample Game',
			)
		);
		update_post_meta( $post_id, '_node_library_type', $args['type'] ?? 'game' );
		if ( array_key_exists( 'summary', $args ) ) {
			update_post_meta( $post_id, '_node_library_summary', $args['summary'] );
		}
		if ( array_key_exists( 'links', $args ) ) {
			update_post_meta( $post_id, '_node_library_links', $args['links'] );
		}
		if ( array_key_exists( 'subscriptions', $args ) ) {
			update_post_meta( $post_id, '_node_library_subscriptions', $args['subscriptions'] );
		}
		return $post_id;
	}

	public function test_shelf_card_renders_navy_placeholder_and_fallback_excerpt_when_thin(): void {
		$item_id = $this->create_library_item(
			array(
				'title'   => 'Thin Item',
				'type'    => 'app',
				'summary' => '',
				'links'   => array(),
			)
		);

		ob_start();
		node_library_render_shelf_card( $item_id, array( 'article_count' => 0 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'node-library-visual--placeholder', $html );
		$this->assertStringContainsString( 'data-icon="smartphone"', $html );
		$this->assertStringContainsString( 'node-library-visual__initial">T<', $html );
		$this->assertStringContainsString( 'node-library-shelf-card__excerpt is-fallback', $html );
		$this->assertStringContainsString( 'node-library-store-pill--empty', $html );
		$this->assertStringContainsString( '記事 0件', $html );
	}

	public function test_shelf_card_renders_image_when_thumbnail_is_attached(): void {
		$item_id = $this->create_library_item(
			array(
				'title'   => 'Visual Item',
				'summary' => 'Has featured image.',
			)
		);
		$att_id  = self::factory()->attachment->create_object(
			'sample.jpg',
			$item_id,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		set_post_thumbnail( $item_id, $att_id );

		ob_start();
		node_library_render_visual( $item_id, 'game' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'node-library-visual--has-image', $html );
		$this->assertStringNotContainsString( 'node-library-visual--placeholder', $html );
	}

	public function test_shelf_card_caps_store_chips_at_three_plus_overflow_and_shows_subscriptions(): void {
		$item_id = $this->create_library_item(
			array(
				'title'         => 'Multi Store Game',
				'summary'       => 'Available on five stores.',
				'subscriptions' => array( 'gamepass' => true ),
				'links'         => array(
					array( 'platform' => 'Steam', 'url' => 'https://store.steampowered.com/app/10/', 'hardware' => 'windows-pc' ),
					array( 'platform' => 'Nintendo Switch', 'url' => 'https://store-jp.nintendo.com/list/software/70010000000001.html', 'hardware' => 'nintendo-switch' ),
					array( 'platform' => 'PS5', 'url' => 'https://store.playstation.com/ja-jp/product/JP0001-PPSA00001_00-0000000000000001', 'hardware' => 'playstation-5' ),
					array( 'platform' => 'Xbox Series X|S', 'url' => 'https://www.xbox.com/ja-JP/games/store/sample/9NBLGGH4R315', 'hardware' => 'xbox-series' ),
					array( 'platform' => 'Epic Games', 'url' => 'https://store.epicgames.com/ja/p/sample', 'hardware' => 'windows-pc' ),
				),
			)
		);

		ob_start();
		node_library_render_shelf_card( $item_id );
		$html = (string) ob_get_clean();

		$this->assertSame( 3, substr_count( $html, 'class="node-library-store-pill"' ) );
		$this->assertStringContainsString( 'node-library-store-pill--more">+2<', $html );

		$single_store_id = $this->create_library_item(
			array(
				'title'         => 'Single Store Game',
				'subscriptions' => array( 'gamepass' => true ),
				'links'         => array(
					array( 'platform' => 'Xbox', 'url' => 'https://www.xbox.com/ja-JP/games/store/sample/9NBLGGH4R315', 'hardware' => 'xbox-series' ),
				),
			)
		);

		ob_start();
		node_library_render_shelf_card( $single_store_id );
		$single_html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $single_html, 'class="node-library-store-pill"' ) );
		$this->assertStringNotContainsString( 'node-library-store-pill--more', $single_html );
		$this->assertStringContainsString( 'node-library-store-pill__sub', $single_html );
	}

	public function test_store_card_renders_priced_and_unpriced_states(): void {
		$priced_game_info = array(
			'title'               => 'Priced Game',
			'type'                => 'game',
			'summary'             => 'Priced summary',
			'release_date'        => '2020-01-01',
			'subscription_badges' => array(),
			'links'               => array(
				array(
					'platform'         => 'Steam',
					'url'              => 'https://store.steampowered.com/app/123/',
					'catalogue_store'  => 'steam',
					'price_label'      => '¥1,980',
					'price_date'       => '2026-11-24',
					'list_price_label' => '¥3,960',
					'discount_percent' => 50,
				),
				array(
					'platform'         => 'Nintendo Store',
					'url'              => 'https://store-jp.nintendo.com/list/software/70010000010991.html',
					'catalogue_store'  => 'nintendo',
					'price_label'      => '',
					'price_date'       => '',
					'list_price_label' => '',
					'discount_percent' => 0,
				),
			),
		);

		$game_info = $priced_game_info;
		ob_start();
		include dirname( __DIR__ ) . '/plugins-embedded/node-library/templates/card-library.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '¥1,980', $html );
		$this->assertStringContainsString( '-50%', $html );
		$this->assertStringContainsString( '価格未登録', $html );
	}

	public function test_single_template_has_unified_header_japanese_dates_and_no_additional_details(): void {
		$template = (string) file_get_contents( dirname( __DIR__ ) . '/single-node_library.php' );

		$this->assertStringContainsString( 'node-library-single__hero-grid', $template );
		$this->assertStringContainsString( 'node-library-single__platforms', $template );
		$this->assertStringContainsString( '公開日', $template );
		$this->assertStringContainsString( '更新日', $template );
		$this->assertStringContainsString( 'node_library_render_article_card', $template );
		$this->assertStringNotContainsString( 'Additional Details', $template );
		$this->assertStringNotContainsString( 'node_library_render_spec_table', $template );
	}
}
