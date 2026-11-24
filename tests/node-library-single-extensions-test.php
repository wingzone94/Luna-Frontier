<?php
/**
 * Library single page extensions (L6 Step 2).
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

/**
 * Spec table rows, price history/badges, bidirectional relations,
 * related-edition articles, and JSON-LD Offer tests.
 */
class Node_Library_Single_Extensions_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		$library = Node_Library::instance();
		$this->set_permalink_structure( '/%postname%/' );
		$library->register_cpt();
		flush_rewrite_rules( false );
		Node_Library_Store::install();
		Node_Library_Prices::install();
		Node_Library_Single_Extensions::init();
	}

	public function test_spec_rows_include_all_l1_meta_and_omit_unset_fields(): void {
		$empty_id = $this->create_library_item( 'Empty Item' );
		$empty_labels = array_column( node_library_get_spec_rows( $empty_id ), 'label' );
		$this->assertSame( array( '種別' ), $empty_labels );

		$full_id = $this->create_library_item( 'Full Meta Item' );
		update_post_meta( $full_id, '_node_library_developer', 'Studio Alpha' );
		update_post_meta( $full_id, '_node_library_publisher', 'Publisher Beta' );
		update_post_meta( $full_id, '_node_library_release_date', '2025-03-14' );
		update_post_meta( $full_id, '_node_library_genres', array( 'RPG', 'アクション' ) );
		update_post_meta(
			$full_id,
			'_node_library_rating',
			array(
				'cero' => 'C',
				'esrb' => 'T',
			)
		);
		update_post_meta(
			$full_id,
			'_node_library_japanese',
			array(
				'audio'     => true,
				'subtitles' => false,
				// ui intentionally omitted (unknown).
			)
		);
		update_post_meta(
			$full_id,
			'_node_library_capabilities',
			array(
				'crossplay'  => true,
				'hdr'        => true,
				'max_fps'    => 120,
				'resolution' => '3840×2160',
			)
		);
		update_post_meta(
			$full_id,
			'_node_library_subscriptions',
			array(
				'gamepass' => true,
				'ea_play'  => true,
			)
		);
		update_post_meta(
			$full_id,
			'_node_library_store_ids',
			array(
				'microsoft'   => '9NBLGGH537BL',
				'steam'       => '1245620',
				'amazon_asin' => 'B0TEST1234',
				'nintendo'    => '70010000000025',
			)
		);

		$rows = node_library_get_spec_rows( $full_id );
		$by_label = array();
		foreach ( $rows as $row ) {
			$by_label[ $row['label'] ] = $row;
		}

		$this->assertSame( 'Studio Alpha', $by_label['開発元']['value'] ?? '' );
		$this->assertSame( 'Publisher Beta', $by_label['発売元']['value'] ?? '' );
		$this->assertSame( '2025-03-14', $by_label['発売日']['value'] ?? '' );
		$this->assertSame( 'RPG、アクション', $by_label['ジャンル']['value'] ?? '' );
		$this->assertSame( 'CERO: C / ESRB: T', $by_label['レーティング']['value'] ?? '' );
		$this->assertSame( '音声: 対応 / 字幕: 非対応', $by_label['日本語対応']['value'] ?? '' );
		$this->assertStringNotContainsString( 'UI', $by_label['日本語対応']['value'] ?? '' );
		$this->assertStringContainsString( 'クロスプレイ: 対応', $by_label['能力・映像仕様']['value'] ?? '' );
		$this->assertStringContainsString( 'HDR: 対応', $by_label['能力・映像仕様']['value'] ?? '' );
		$this->assertStringContainsString( '最大FPS: 120fps', $by_label['能力・映像仕様']['value'] ?? '' );
		$this->assertStringContainsString( '解像度: 3840×2160', $by_label['能力・映像仕様']['value'] ?? '' );
		$this->assertArrayNotHasKey( 'サブスク在籍', $by_label );

		$this->assertArrayNotHasKey( 'ストアID', $by_label );
		$this->assertArrayNotHasKey( 'ストアID（その他）', $by_label );
	}

	public function test_public_store_labels_hide_ids_in_article_cards_and_qr_dialogs(): void {
		$item = $this->create_library_item( 'Mobile Example' );
		$id   = 'com.example.game';
		$url  = 'https://play.google.com/store/apps/details?id=' . $id;
		$link = array( 'platform' => 'Google Play (' . $id . ')', 'url' => $url );
		$this->assertSame( 'Google Play', node_library_public_store_label( $link, array() ) );
		$this->assertSame( 'example.org', node_library_public_store_label( array( 'platform' => 'Epic (sample-id)', 'url' => 'https://example.org/product' ), array( 'epic' => 'sample-id' ) ) );
		update_post_meta( $item, '_node_library_links', array( $link ) );
		$html = Node_Library::instance()->render_block( array( 'libraryId' => $item ) );
		$this->assertStringContainsString( esc_url( $url ), $html );
		$this->assertStringContainsString( 'ストアページを開く', $html );
		$this->assertStringNotContainsString( $id, wp_strip_all_tags( $html ) );
	}

	public function test_store_offers_prioritize_japan_and_price_only_the_registered_product(): void {
		$item = $this->create_library_item( 'Store Offers' );
		update_post_meta( $item, '_node_library_store_ids', array( 'steam' => '42', 'nintendo' => '70010000000025' ) );
		Node_Library_Prices::record( $item, 'steam', '42', array( 'currency' => 'JPY', 'price' => 0, 'list_price' => 500 ), strtotime( '2026-11-22 16:00 UTC' ) );
		$offers = Node_Library_Single_Extensions::store_offers( $item, array(
			array( 'platform' => 'Steam', 'url' => 'https://store.steampowered.com/app/99/' ),
			array( 'platform' => 'Steam', 'url' => 'https://store.steampowered.com/app/42/' ),
		) );

		$this->assertSame( 'Nintendo', $offers[0]['platform'] );
		$this->assertSame( '', $offers[0]['price_label'] );
		$this->assertSame( '', $offers[1]['price_label'] );
		$this->assertSame( '¥0', $offers[2]['price_label'] );
		$this->assertSame( '2026/11/23', $offers[2]['price_date'] );

		$game_info = array( 'title' => 'Store Offers', 'links' => $offers, 'subscription_badges' => array( 'Xbox / PC Game Pass' ) );
		ob_start();
		include NODE_LIBRARY_DIR . 'templates/card-library.php';
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Xbox / PC Game Pass</li>', $html );
		$this->assertSame( 1, substr_count( $html, '¥0' ) );
		$this->assertStringContainsString( '価格記録日 2026/11/23', $html );
	}

	public function test_price_history_section_and_badges_render_and_omit_when_empty(): void {
		$plain_id = $this->create_library_item( 'No Price Item' );
		ob_start();
		do_action( 'node_library_single_after_specs', $plain_id );
		$empty_html = (string) ob_get_clean();
		$this->assertSame( '', trim( $empty_html ) );

		$now     = time();
		$item_id = $this->create_library_item( 'Priced Sale Item' );
		update_post_meta( $item_id, '_node_library_store_ids', array( 'microsoft' => '9PRICETEST01' ) );
		update_post_meta( $item_id, '_node_library_subscriptions', array( 'gamepass' => true ) );

		// Older regular price.
		Node_Library_Prices::record(
			$item_id,
			'microsoft',
			'9PRICETEST01',
			array(
				'price'      => 6000,
				'list_price' => 6000,
				'currency'   => 'JPY',
				'sale_end'   => null,
			),
			$now - ( 30 * DAY_IN_SECONDS )
		);

		ob_start();
		do_action( 'node_library_single_after_specs', $item_id );
		$regular_only_html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '過去最安値', $regular_only_html );

		// Current lowest sale price with future end date.
		$sale_end_utc = gmdate( 'Y-m-d H:i:s', $now + ( 3 * DAY_IN_SECONDS ) );
		Node_Library_Prices::record(
			$item_id,
			'microsoft',
			'9PRICETEST01',
			array(
				'price'      => 3000,
				'list_price' => 6000,
				'currency'   => 'JPY',
				'sale_end'   => $sale_end_utc,
			),
			$now - HOUR_IN_SECONDS
		);

		ob_start();
		do_action( 'node_library_single_after_specs', $item_id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'node-library-single__pricing', $html );
		$this->assertStringContainsString( 'セール中（-50%）', $html );
		$this->assertStringContainsString( '過去最安値', $html );
		$this->assertStringContainsString( '¥3,000', $html );
		$this->assertStringContainsString( '¥6,000', $html );
		$this->assertStringContainsString( '1回', $html );
		$this->assertStringContainsString(
			wp_date( 'Y/m/d H:i', strtotime( $sale_end_utc . ' UTC' ), new DateTimeZone( 'Asia/Tokyo' ) ),
			$html
		);
		$this->assertStringContainsString( '直近の価格変動履歴', $html );
		$this->assertStringContainsString( 'data-icon="sell"', $html );
		$this->assertStringContainsString( 'data-icon="verified"', $html );
		$this->assertStringContainsString( 'data-icon="schedule"', $html );
	}

	public function test_bidirectional_relations_resolve_published_items_only_and_hide_unregistered(): void {
		$lone_id = $this->create_library_item( 'Lone Item' );
		ob_start();
		do_action( 'node_library_single_after_stores', $lone_id );
		$this->assertSame( '', trim( (string) ob_get_clean() ) );

		$base_id = $this->create_library_item( 'Base Game' );
		update_post_meta( $base_id, '_node_library_store_ids', array( 'microsoft' => '9BASEGAME001' ) );

		$dlc_id = $this->create_library_item( 'Story DLC' );
		update_post_meta( $dlc_id, '_node_library_store_ids', array( 'microsoft' => '9STORYDLC001' ) );

		$draft_dlc_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'draft',
				'post_title'  => 'Draft DLC',
			)
		);
		update_post_meta( $draft_dlc_id, '_node_library_store_ids', array( 'microsoft' => '9DRAFTDLC001' ) );

		// Persist relations on Base Game: one published DLC, one draft DLC, one unregistered external_id.
		$persisted = Node_Library_Store::persist(
			$base_id,
			array(
				'store'     => 'microsoft',
				'id'        => '9BASEGAME001',
				'relations' => array(
					array(
						'store'       => 'microsoft',
						'relation'    => 'dlc',
						'external_id' => '9STORYDLC001',
					),
					array(
						'store'       => 'microsoft',
						'relation'    => 'dlc',
						'external_id' => '9DRAFTDLC001',
					),
					array(
						'store'       => 'microsoft',
						'relation'    => 'dlc',
						'external_id' => '9UNREGIST001',
					),
				),
			)
		);
		$this->assertTrue( $persisted );

		// Forward view on Base Game: shows Story DLC only, never draft or unregistered external_id.
		ob_start();
		do_action( 'node_library_single_after_stores', $base_id );
		$base_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'node-library-single__relations', $base_html );
		$this->assertStringContainsString( 'Story DLC', $base_html );
		$this->assertStringContainsString( 'DLC', $base_html );
		$this->assertStringNotContainsString( 'Draft DLC', $base_html );
		$this->assertStringNotContainsString( '9UNREGIST001', $base_html );
		$this->assertStringNotContainsString( 'xbox.com', $base_html );

		// Reverse view on Story DLC: shows Base Game with inverted label "本編".
		ob_start();
		do_action( 'node_library_single_after_stores', $dlc_id );
		$dlc_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Base Game', $dlc_html );
		$this->assertStringContainsString( '本編', $dlc_html );
	}

	public function test_related_edition_articles_reverse_lookup_and_deduplication(): void {
		$base_id = $this->create_library_item( 'Edition Base' );
		update_post_meta( $base_id, '_node_library_store_ids', array( 'microsoft' => '9EDBASE00001' ) );

		$dlc_id = $this->create_library_item( 'Edition DLC' );
		update_post_meta( $dlc_id, '_node_library_store_ids', array( 'microsoft' => '9EDDLC000001' ) );

		Node_Library_Store::persist(
			$base_id,
			array(
				'store'     => 'microsoft',
				'id'        => '9EDBASE00001',
				'relations' => array(
					array(
						'store'       => 'microsoft',
						'relation'    => 'dlc',
						'external_id' => '9EDDLC000001',
					),
				),
			)
		);

		// No linked articles yet -> outputs nothing.
		ob_start();
		do_action( 'node_library_single_after_articles', $base_id );
		$this->assertSame( '', trim( (string) ob_get_clean() ) );

		// Article linked to both base and DLC (already in primary list -> must be excluded).
		$shared_article = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Shared Base and DLC Review',
			)
		);
		update_post_meta( $shared_article, '_node_linked_library_id', (string) $base_id );
		update_post_meta( $shared_article, '_node_library_card_reference', (string) $dlc_id );

		// Article linked only to DLC -> must appear in related-edition articles section.
		$dlc_article = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'DLC Exclusive Walkthrough',
			)
		);
		update_post_meta( $dlc_article, '_node_linked_library_id', (string) $dlc_id );

		ob_start();
		do_action( 'node_library_single_after_articles', $base_id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'node-library-single__related-articles', $html );
		$this->assertStringContainsString( 'DLC Exclusive Walkthrough', $html );
		$this->assertStringNotContainsString( 'Shared Base and DLC Review', $html );
		$this->assertStringContainsString( 'data-icon="article"', $html );
	}

	public function test_jsonld_offers_generated_from_l3_prices_and_escaped_safely(): void {
		$no_price_id = $this->create_library_item( 'Unpriced JSONLD' );
		$no_price_ld = node_library_get_product_jsonld( $no_price_id );
		$this->assertArrayNotHasKey( 'offers', $no_price_ld );

		$now      = time();
		$end_utc  = gmdate( 'Y-m-d H:i:s', $now + ( 2 * DAY_IN_SECONDS ) );
		$item_id  = $this->create_library_item( 'Priced JSONLD </script>' );
		update_post_meta( $item_id, '_node_library_store_ids', array( 'microsoft' => '9JSONLD00001' ) );
		Node_Library_Prices::record(
			$item_id,
			'microsoft',
			'9JSONLD00001',
			array(
				'price'      => 2980,
				'list_price' => 5960,
				'currency'   => 'JPY',
				'sale_end'   => $end_utc,
			),
			$now - MINUTE_IN_SECONDS
		);

		$data = node_library_get_product_jsonld( $item_id );
		$this->assertArrayHasKey( 'offers', $data );
		$this->assertSame( 'Offer', $data['offers']['@type'] ?? '' );
		$this->assertSame( '2980', $data['offers']['price'] ?? '' );
		$this->assertSame( 'JPY', $data['offers']['priceCurrency'] ?? '' );
		$this->assertArrayNotHasKey( 'availability', $data['offers'] );
		$this->assertSame( wp_date( 'Y-m-d', strtotime( $end_utc . ' UTC' ), new DateTimeZone( 'Asia/Tokyo' ) ), $data['offers']['priceValidUntil'] ?? '' );
		$this->assertSame( 'https://www.xbox.com/ja-JP/games/store/-/9JSONLD00001', $data['offers']['url'] ?? '' );

		ob_start();
		node_library_render_product_jsonld( $item_id );
		$html = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $html, '</script>' ) );
		$this->assertStringContainsString( '"price":"2980"', $html );
	}

	public function test_expired_sale_does_not_publish_an_offer_without_a_new_observation(): void {
		$item_id = $this->create_library_item( 'Expired sale' );
		$product = '9JSONLD00001';
		update_post_meta( $item_id, '_node_library_store_ids', array( 'microsoft' => $product ) );
		$offer = array(
			'price'      => 100,
			'list_price' => 200,
			'currency'   => 'JPY',
			'sale_end'   => gmdate( 'c', time() - DAY_IN_SECONDS ),
		);
		$this->assertTrue( Node_Library_Prices::record( $item_id, 'microsoft', $product, $offer, time() - 2 * DAY_IN_SECONDS ) );
		$this->assertArrayNotHasKey( 'offers', node_library_get_product_jsonld( $item_id ) );
	}

	public function test_nintendo_offer_links_to_registered_product(): void {
		$id = $this->create_library_item( 'Nintendo offer' );
		$product = '70010000000026';
		update_post_meta( $id, '_node_library_store_ids', array( 'nintendo' => $product ) );
		update_post_meta( $id, '_node_library_links', array( array( 'url' => 'https://example.org/?ref=' . $product ) ) );
		$this->assertTrue( Node_Library_Prices::record( $id, 'nintendo', $product, array( 'price' => 0, 'list_price' => 0, 'currency' => 'JPY' ) ) );
		$data = node_library_get_product_jsonld( $id );
		$this->assertSame( '0', $data['offers']['price'] );
		$this->assertSame( 'https://store-jp.nintendo.com/item/software/D' . $product, $data['offers']['url'] );
	}

	public function test_store_card_states_sale_subscription_upcoming_and_warning_gate(): void {
		$item_id = $this->create_library_item( 'Store Card States' );
		update_post_meta(
			$item_id,
			'_node_library_store_ids',
			array(
				'microsoft' => '9STORECARD01',
				'steam'     => '555001',
				'nintendo'  => '70010000000025',
			)
		);
		update_post_meta( $item_id, '_node_library_subscriptions', array( 'gamepass' => true ) );

		$recorded_at = strtotime( '2026-11-22 16:00 UTC' );
		Node_Library_Prices::record(
			$item_id,
			'microsoft',
			'9STORECARD01',
			array(
				'currency'   => 'JPY',
				'price'      => 2400,
				'list_price' => 4800,
			),
			$recorded_at
		);

		$offers = Node_Library_Single_Extensions::store_offers(
			$item_id,
			array(
				array(
					'platform' => 'Nintendo Switch',
					'url'      => 'https://store-jp.nintendo.com/item/software/D70010000000025',
					'hardware' => 'nintendo-switch',
				),
				array(
					'platform' => 'Steam',
					'url'      => 'https://store.steampowered.com/app/555001/',
				),
			)
		);

		$game_info = array(
			'title'               => 'Store Card States',
			'links'               => $offers,
			'subscription_badges' => array( 'gamepass' => 'Xbox / PC Game Pass' ),
			'release_date'        => '2024-01-01',
		);

		ob_start();
		include NODE_LIBRARY_DIR . 'templates/card-library.php';
		$html = (string) ob_get_clean();

		// Microsoft card: sale price, list price, discount badge, inline subscription badge, no official badge img.
		$this->assertStringContainsString( 'node-library-store-card--microsoft', $html );
		$this->assertStringContainsString( '¥2,400', $html );
		$this->assertStringContainsString( '¥4,800', $html );
		$this->assertStringContainsString( '-50%', $html );
		$this->assertStringContainsString( 'node-library-store-card__sub-badge', $html );
		$this->assertStringContainsString( 'Xbox / PC Game Pass', $html );
		$this->assertStringNotContainsString( 'microsoft-store-badge-ja.svg', $html );

		// Steam card (unpriced): unpriced status pill + CTA, no raw store ID, no Steam embed toggle on detail view.
		$this->assertStringContainsString( 'node-library-store-card--steam', $html );
		$this->assertStringContainsString( '価格未登録', $html );
		$this->assertStringContainsString( 'ストアで見る', $html );
		$this->assertStringNotContainsString( 'node-library-steam-control', $html );
		$this->assertStringNotContainsString( 'node-library-steam-panel', $html );
		$this->assertStringNotContainsString( '9STORECARD01', wp_strip_all_tags( $html ) );

		// Nintendo card: warning gate + aria-describedby.
		$this->assertStringContainsString( 'node-library-store-card--nintendo', $html );
		$this->assertStringContainsString( 'data-node-library-platform-warning', $html );
		$this->assertStringContainsString( 'aria-describedby="node-library-warning-', $html );
		$this->assertStringContainsString( 'このソフトはSwitch専用ソフトです。', $html );

		// Upcoming release state when release_date is in the future and unpriced.
		$game_info['release_date'] = '2099-12-31';
		ob_start();
		include NODE_LIBRARY_DIR . 'templates/card-library.php';
		$upcoming_html = (string) ob_get_clean();
		$this->assertStringContainsString( '発売前', $upcoming_html );
		$this->assertStringContainsString( 'node-library-store-card__status--upcoming', $upcoming_html );
	}

	private function create_library_item( string $title ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		return (int) $post_id;
	}
}
