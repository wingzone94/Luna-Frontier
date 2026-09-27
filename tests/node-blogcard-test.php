<?php
/**
 * inc/blogcard.php の自動テスト。
 *
 * @package Node
 */

class Node_Blogcard_Test extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'luminous_nexus_amazon_id' );
		parent::tear_down();
	}

	private function mock_ogp_response( string $url, string $title = 'External OGP Title', string $image = 'https://example.com/card.jpg', string $description = 'External OGP Description' ): callable {
		$callback = static function ( $preempt, $args, $request_url ) use ( $url, $title, $image, $description ) {
			if ( $request_url !== $url ) {
				return $preempt;
			}

			return array(
				'headers'  => array(
					'content-type' => 'text/html; charset=UTF-8',
				),
				'body'     => sprintf(
					'<!doctype html><html><head><title>%1$s</title><meta property="og:title" content="%1$s"><meta property="og:description" content="%3$s"><meta property="og:image" content="%2$s"><meta property="og:site_name" content="Example Site"></head><body></body></html>',
					esc_attr( $title ),
					esc_attr( $image ),
					esc_attr( $description )
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );
		return $callback;
	}

	public function test_excluded_oembed_provider_matches_domains_and_provider_name(): void {
		$data = (object) array( 'provider_name' => 'Example' );

		$this->assertTrue( node_is_excluded_oembed_provider( 'https://twitter.com/luminous/status/1', $data ) );
		$this->assertTrue( node_is_excluded_oembed_provider( 'https://x.com/luminous/status/1', $data ) );
		$this->assertTrue( node_is_excluded_oembed_provider( 'https://www.youtube.com/watch?v=abc', $data ) );
		$this->assertTrue( node_is_excluded_oembed_provider( 'https://youtu.be/abc', $data ) );
		$this->assertTrue( node_is_excluded_oembed_provider( 'https://example.com/watch', (object) array( 'provider_name' => 'YouTube' ) ) );
		$this->assertFalse( node_is_excluded_oembed_provider( 'https://example.com/article', $data ) );
		$this->assertFalse( node_is_excluded_oembed_provider( home_url( '/sample-post/' ), $data ) );
	}

	public function test_special_embed_builds_x_twitter_blockquote(): void {
		foreach ( array( 'https://twitter.com/jack/status/20', 'https://x.com/jack/status/20', 'https://mobile.twitter.com/jack/status/20' ) as $url ) {
			$embed = node_special_embed( $url );
			$this->assertStringContainsString( 'node-embed--x', $embed, $url );
			$this->assertStringContainsString( 'twitter-tweet', $embed, $url );
			$this->assertStringContainsString( esc_url( $url ), $embed, $url );
		}
	}

	public function test_special_embed_builds_youtube_iframe_for_short_and_shorts(): void {
		$this->assertStringContainsString( 'youtube.com/embed/dQw4w9WgXcQ', node_special_embed( 'https://youtu.be/dQw4w9WgXcQ' ) );
		$this->assertStringContainsString( 'youtube.com/embed/dQw4w9WgXcQ', node_special_embed( 'https://www.youtube.com/shorts/dQw4w9WgXcQ' ) );
		$this->assertStringContainsString( 'node-embed--video', node_special_embed( 'https://youtu.be/dQw4w9WgXcQ' ) );
	}

	public function test_special_embed_builds_google_maps_iframe(): void {
		$place = node_special_embed( 'https://www.google.com/maps/place/Tokyo+Tower/@35.6586,139.7454,17z/data=xxx' );
		$this->assertStringContainsString( 'node-embed--map', $place );
		$this->assertStringContainsString( 'maps.google.com/maps', $place );
		$this->assertStringContainsString( 'output=embed', $place );
		$this->assertStringContainsString( 'Tokyo', $place );

		$query = node_special_embed( 'https://www.google.com/maps?q=Tokyo+Tower' );
		$this->assertStringContainsString( 'node-embed--map', $query );
		$this->assertStringContainsString( 'output=embed', $query );
	}

	public function test_is_google_maps_url_matches_maps_and_short_links(): void {
		$this->assertTrue( node_is_google_maps_url( 'https://www.google.com/maps/place/Tokyo/@35.6,139.7,15z' ) );
		$this->assertTrue( node_is_google_maps_url( 'https://maps.google.com/?q=Tokyo' ) );
		$this->assertTrue( node_is_google_maps_url( 'https://maps.app.goo.gl/AbCdEf' ) );
		$this->assertFalse( node_is_google_maps_url( 'https://www.google.com/search?q=tokyo' ) );
		$this->assertFalse( node_is_google_maps_url( 'https://example.com/maps' ) );
	}

	public function test_maps_oembed_response_returns_rich_iframe_for_maps_url(): void {
		$request = new WP_REST_Request( 'GET', '/node/v1/maps-oembed' );
		$request->set_param( 'url', 'https://www.google.com/maps/place/Tokyo+Tower/@35.6586,139.7454,17z' );

		$data = node_maps_oembed_response( $request );

		$this->assertIsArray( $data );
		$this->assertSame( 'rich', $data['type'] );
		$this->assertSame( 'Google Maps', $data['provider_name'] );
		$this->assertStringContainsString( 'node-embed--map', $data['html'] );
		$this->assertStringContainsString( 'output=embed', $data['html'] );
	}

	public function test_maps_oembed_response_rejects_non_maps_url(): void {
		$request = new WP_REST_Request( 'GET', '/node/v1/maps-oembed' );
		$request->set_param( 'url', 'https://example.com/not-a-map' );

		$this->assertInstanceOf( WP_Error::class, node_maps_oembed_response( $request ) );
	}

	public function test_pre_oembed_result_short_circuits_google_maps(): void {
		$html = node_pre_oembed_maps_result( null, 'https://www.google.com/maps/place/Tokyo/@35.6,139.7,15z', array() );
		$this->assertIsString( $html );
		$this->assertStringContainsString( 'node-embed--map', (string) $html );

		// Non-maps URLs must pass through untouched.
		$this->assertNull( node_pre_oembed_maps_result( null, 'https://example.com/article', array() ) );
	}

	public function test_special_embed_returns_empty_for_ordinary_url(): void {
		$this->assertSame( '', node_special_embed( 'https://example.com/normal-article' ) );
		$this->assertSame( '', node_special_embed( 'https://twitter.com/jack' ) ); // profile, not a status
	}

	public function test_embed_maybe_make_link_prefers_special_embed_over_card(): void {
		$html = node_blogcard_hydrate( node_embed_maybe_make_link( '<a href="https://x.com/jack/status/20">x</a>', 'https://x.com/jack/status/20' ) );
		$this->assertStringContainsString( 'node-embed--x', $html );
		$this->assertStringNotContainsString( 'm3-blogcard__overlay', $html );
	}

	public function test_oembed_dataparse_returns_original_html_for_excluded_provider(): void {
		$return = '<blockquote class="twitter-tweet">Original embed</blockquote>';
		$data   = (object) array(
			'title'         => 'Tweet title',
			'provider_name' => 'Twitter',
		);

		$this->assertSame( $return, node_oembed_dataparse( $return, $data, 'https://twitter.com/luminous/status/1' ) );
	}

	public function test_oembed_dataparse_uses_internal_ogp_data_for_home_url(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Internal Blogcard Title',
				'post_excerpt' => 'Internal Blogcard Excerpt',
			)
		);
		$url     = get_permalink( $post_id );
		$html    = node_blogcard_hydrate(
			node_oembed_dataparse(
				'<div>Original embed</div>',
				(object) array(
					'title'         => 'oEmbed Title',
					'provider_name' => 'WordPress',
				),
				$url
			)
		);

		$this->assertStringContainsString( 'm3-blogcard__overlay', $html );
		$this->assertStringContainsString( 'm3-blogcard--internal', $html );
		$this->assertStringContainsString( 'Internal Blogcard Title', $html );
		$this->assertStringContainsString( 'Internal Blogcard Excerpt', $html );
		$this->assertStringNotContainsString( 'oEmbed Title', $html );
	}

	public function test_internal_ogp_failure_renders_non_clickable_fallback(): void {
		$url    = home_url( '/missing-internal-blogcard/' );
		$filter = static function ( $preempt, $args, $request_url ) use ( $url ) {
			if ( $request_url !== $url ) {
				return $preempt;
			}

			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 404,
					'message' => 'Not Found',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = node_render_blogcard( $url );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertStringContainsString( 'm3-blogcard__fallback--missing', $html );
		$this->assertStringContainsString( esc_html( $url ), $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( 'href=', $html );
	}

	public function test_external_ogp_failure_keeps_clickable_fallback(): void {
		$url = 'https://example.com/missing-external-blogcard/';

		$this->assertStringContainsString(
			'<a class="m3-blogcard__fallback" href="' . esc_url( $url ) . '">',
			node_blogcard_fallback_markup( $url )
		);
	}

	public function test_oembed_dataparse_builds_external_card_from_data(): void {
		$html = node_blogcard_hydrate(
			node_oembed_dataparse(
				'<div>Original embed</div>',
				(object) array(
					'title'         => 'External oEmbed Title',
					'thumbnail_url' => 'https://example.com/thumb.jpg',
					'provider_name' => 'Example Provider',
				),
				'https://example.com/article'
			)
		);

		$this->assertStringContainsString( 'm3-blogcard__overlay', $html );
		$this->assertStringContainsString( 'm3-blogcard--external', $html );
		$this->assertStringContainsString( 'External oEmbed Title', $html );
		$this->assertStringContainsString( 'https://example.com/thumb.jpg', $html );
		$this->assertStringContainsString( 'Example Provider', $html );
		$this->assertStringNotContainsString( 'm3-blogcard__description', $html );

		// コピー/シェアのアクションボタンがカードURL・タイトルを保持していること。
		$this->assertStringContainsString( 'm3-blogcard__action--copy', $html );
		$this->assertStringContainsString( 'm3-blogcard__action--share', $html );
		$this->assertStringContainsString( 'data-share-title="External oEmbed Title"', $html );
		$this->assertStringContainsString( 'data-url="https://example.com/article"', $html );
	}

	public function test_oembed_dataparse_builds_external_card_without_thumbnail(): void {
		$html = node_blogcard_hydrate(
			node_oembed_dataparse(
				'<div>Original embed</div>',
				(object) array(
					'title'         => 'No Thumbnail Title',
					'provider_name' => 'No Thumbnail Provider',
				),
				'https://example.net/no-thumbnail'
			)
		);

		$this->assertStringContainsString( 'No Thumbnail Title', $html );
		$this->assertStringNotContainsString( 'm3-blogcard__image', $html );
	}

	public function test_is_luminous_core_host_matches_apex_and_www_case_insensitive(): void {
		$this->assertTrue( node_is_luminous_core_host( 'luminous-core.net' ) );
		$this->assertTrue( node_is_luminous_core_host( 'www.luminous-core.net' ) );
		$this->assertTrue( node_is_luminous_core_host( 'LUMINOUS-CORE.NET' ) );
		$this->assertFalse( node_is_luminous_core_host( 'example.com' ) );
	}

	public function test_oembed_dataparse_keeps_real_image_for_luminous_core_host_by_default(): void {
		// 自動検出（単独行URL・core/embedブロック経由）は、本番側が生成済みの
		// 記事ごとのOGP画像をそのまま使う。ブランド画像で上書きしない。
		$html = node_blogcard_hydrate(
			node_oembed_dataparse(
				'<div>Original embed</div>',
				(object) array(
					'title'         => 'Luminous Core Article',
					'thumbnail_url' => 'https://luminous-core.net/wp-content/uploads/per-post-thumb.jpg',
					'provider_name' => 'Luminous Core',
				),
				'https://luminous-core.net/some-article'
			)
		);

		$this->assertStringContainsString( 'Luminous Core Article', $html );
		$this->assertStringContainsString( 'per-post-thumb.jpg', $html );
		$this->assertStringNotContainsString( 'luminous-core-card-image.png', $html );
	}

	public function test_shortcode_overrides_image_with_brand_card_for_luminous_core_host(): void {
		$url    = 'https://luminous-core.net/some-article';
		$filter = $this->mock_ogp_response( $url, 'Luminous Core Article', 'https://luminous-core.net/per-post-thumb.jpg' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = node_blogcard_shortcode( array( 'url' => $url ) );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertStringContainsString( 'Luminous Core Article', $html );
		$this->assertStringContainsString( 'luminous-core-card-image.png', $html );
		$this->assertStringNotContainsString( 'per-post-thumb.jpg', $html );
	}

	public function test_embed_maybe_make_link_keeps_real_image_for_luminous_core_host(): void {
		// embed_maybe_make_link（自動検出のOGPスクレイピング経路）もブランド画像で上書きしない。
		$url    = 'https://luminous-core.net/another-article';
		$filter = $this->mock_ogp_response( $url, 'Another Luminous Core Article', 'https://luminous-core.net/another-thumb.jpg' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = node_blogcard_hydrate( node_embed_maybe_make_link( '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>', $url ) );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertStringContainsString( 'Another Luminous Core Article', $html );
		$this->assertStringContainsString( 'another-thumb.jpg', $html );
		$this->assertStringNotContainsString( 'luminous-core-card-image.png', $html );
	}

	public function test_embed_maybe_make_link_delegates_to_render_blogcard_with_amazon_tag(): void {
		$url      = 'https://www.amazon.co.jp/dp/B000000000';
		$filter   = $this->mock_ogp_response( $url, 'Amazon Product Title' );
		$original = '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';

		update_option( 'luminous_nexus_amazon_id', 'luminous-22' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = node_blogcard_hydrate( node_embed_maybe_make_link( $original, $url ) );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertStringContainsString( 'Amazon Product Title', $html );
		$this->assertStringContainsString( 'tag=luminous-22', $html );
		$this->assertStringNotContainsString( $original, $html );
	}

	public function test_deferred_card_survives_wpautop_and_hydrates_intact(): void {
		// autoembed 経路のカードは wpautop より前に挿入されるため、直接カード HTML を返すと
		// wpautop が <p>/<br> を差し込んで <a>×ブロック <div> 構造を破壊する。プレースホルダ→
		// wpautop→hydrate の順で処理され、最終 HTML にカードが崩れず復元されることを検証する。
		$placeholder = node_oembed_dataparse(
			'<div>Original embed</div>',
			(object) array(
				'title'         => 'Deferred Card Title',
				'thumbnail_url' => 'https://example.com/thumb.jpg',
				'provider_name' => 'Example Provider',
			),
			'https://example.com/deferred'
		);

		$this->assertStringContainsString( 'node-blogcard-slot', $placeholder );
		$this->assertStringNotContainsString( 'm3-blogcard__overlay', $placeholder );

		// 本番の the_content と同じ順序: wpautop(10) → hydrate(20)。
		$final = node_blogcard_hydrate( wpautop( "<h2>foo</h2>\n\n{$placeholder}\n\n<h2>bar</h2>" ) );

		$this->assertStringNotContainsString( 'node-blogcard-slot', $final );
		$this->assertStringContainsString( 'm3-blogcard__overlay', $final );
		$this->assertStringContainsString( 'Deferred Card Title', $final );
		// カード内部に wpautop 由来の破壊がないこと。
		$this->assertStringNotContainsString( '<br', $final );
		$this->assertStringNotContainsString( '<p></a>', $final );
		$this->assertStringNotContainsString( '</p>' . "\n" . '<div class="m3-blogcard__body"', $final );
	}

	public function test_auto_blogcard_is_passthrough_and_filter_registration_remains(): void {
		$content = "<p>Before</p>\nhttps://example.com/article\n<p>After</p>";

		$this->assertSame( $content, node_auto_blogcard( $content ) );
		$this->assertNotFalse( has_filter( 'the_content', 'node_auto_blogcard' ) );
	}

	public function test_shortcode_and_ogp_scraping_fallback_still_render_blogcard(): void {
		$url    = 'https://example.org/ogp-article';
		$filter = $this->mock_ogp_response( $url, 'Scraped OGP Title', description: 'Scraped OGP Description' );

		delete_transient( 'node_ogp_' . md5( $url ) );

		$ogp  = node_get_ogp_data( $url );
		$html = do_shortcode( '[blogcard url="' . esc_url( $url ) . '"]' );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $ogp );
		$this->assertSame( 'Scraped OGP Title', $ogp['title'] );
		$this->assertStringContainsString( 'Scraped OGP Title', $html );
		$this->assertStringContainsString( 'Scraped OGP Description', $html );
		$this->assertStringContainsString( 'm3-blogcard__overlay', $html );
	}

	/**
	 * ストアのボット防御を模して 403 を返すモック。
	 *
	 * @param int $calls 呼び出し回数（参照渡し）。
	 * @return callable
	 */
	private function mock_blocked_response( int &$calls ): callable {
		$callback = static function ( $preempt, $args, $request_url ) use ( &$calls ) {
			++$calls;
			return array(
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
				'body'     => '<html><head><title>Access Denied</title></head><body></body></html>',
				'response' => array(
					'code'    => 403,
					'message' => 'Forbidden',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );
		return $callback;
	}

	public function test_store_provider_matches_game_stores_only(): void {
		$expected = array(
			'https://store-jp.nintendo.com/item/software/D70010000073404'                                => 'nintendo',
			'https://www.nintendo.com/jp/store/products/the-legend-of-zelda-tears-of-the-kingdom-switch/' => 'nintendo',
			'https://ec.nintendo.com/JP/ja/titles/70010000012332'                                        => 'nintendo',
			'https://www.nintendo.co.jp/software/switch/aaaaa/index.html'                                => 'nintendo',
			'https://store.playstation.com/ja-jp/product/JP0082-PPSA01284_00-ASTROSBGDELUXE01'           => 'playstation',
			'https://www.xbox.com/ja-JP/games/store/forza-horizon-5/9NKX70BBCDRN'                        => 'xbox',
			'https://apps.microsoft.com/detail/9NKX70BBCDRN'                                             => 'xbox',
			'https://store.steampowered.com/app/570/Dota_2/'                                             => 'steam',
		);

		foreach ( $expected as $url => $slug ) {
			$this->assertSame( $slug, node_store_provider( $url )['slug'] ?? '', $url );
		}

		// ストア以外のページ・別ドメインの偽装は対象外。
		$not_store = array(
			'https://www.nintendo.com/jp/topics/article/abc',
			'https://www.playstation.com/ja-jp/games/astro-bot/',
			'https://www.xbox.com/ja-JP/games/forza-horizon-5',
			'https://steamcommunity.com/app/570',
			'https://nintendo.com.evil.example.net/store/products/x',
			'https://example.com/article',
		);

		foreach ( $not_store as $url ) {
			$this->assertSame( array(), node_store_provider( $url ), $url );
		}
	}

	public function test_store_title_from_url_humanizes_slug_and_skips_product_ids(): void {
		$this->assertSame( 'Forza Horizon 5', node_store_title_from_url( 'https://www.xbox.com/ja-JP/games/store/forza-horizon-5/9NKX70BBCDRN', 'xbox' ) );
		$this->assertSame( 'Dota 2', node_store_title_from_url( 'https://store.steampowered.com/app/570/Dota_2/', 'steam' ) );

		// 商品 ID しか含まない URL からは題名を作らない。
		$this->assertSame( '', node_store_title_from_url( 'https://store-jp.nintendo.com/item/software/D70010000073404', 'nintendo' ) );
		$this->assertSame( '', node_store_title_from_url( 'https://store.playstation.com/ja-jp/product/JP0082-PPSA01284_00-ASTROSBGDELUXE01', 'playstation' ) );
	}

	/**
	 * 任天堂のソフト検索応答をモックする。
	 *
	 * @param array<mixed> $payload 応答ボディ（json_encode 前）。
	 * @param int          $calls   呼び出し回数（参照渡し）。
	 * @return callable
	 */
	private function mock_nintendo_search( array $payload, int &$calls ): callable {
		$callback = static function ( $preempt, $args, $request_url ) use ( $payload, &$calls ) {
			if ( ! str_contains( (string) $request_url, 'search.nintendo.jp' ) ) {
				// 商品ページ側の取得はボット防御を模して 403。
				return array(
					'headers'  => array( 'content-type' => 'text/html' ),
					'body'     => '<html><head><title>Access Denied</title></head></html>',
					'response' => array(
						'code'    => 403,
						'message' => 'Forbidden',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			++$calls;
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => (string) wp_json_encode( $payload ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );
		return $callback;
	}

	public function test_blogcard_block_renders_same_card_as_front_end(): void {
		$url    = 'https://example.org/block-article';
		$filter = $this->mock_ogp_response( $url, 'Block Card Title' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = node_render_blogcard_block( array( 'url' => $url ) );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertStringContainsString( 'Block Card Title', $html );
		$this->assertStringContainsString( 'm3-blogcard__overlay', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_blogcard_block_applies_manual_attributes(): void {
		$url      = 'https://store-jp.nintendo.com/item/software/D70010000000964';
		$calls    = 0;
		$callback = $this->mock_blocked_response( $calls );
		delete_transient( 'node_ogp_' . md5( $url ) );
		delete_transient( 'node_nintendo_soft_v2_' . md5( 'D70010000000964' ) );

		$html = node_render_blogcard_block(
			array(
				'url'   => $url,
				'title' => 'Minecraft',
				'image' => 'https://example.com/mc.jpg',
			)
		);

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertStringContainsString( 'Minecraft', $html );
		$this->assertStringContainsString( 'mc.jpg', $html );
		$this->assertStringContainsString( 'm3-blogcard--store-nintendo', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
		delete_transient( 'node_nintendo_soft_v2_' . md5( 'D70010000000964' ) );
	}

	public function test_legacy_node_library_block_renders_identically(): void {
		// 旧ブロック（node-library/blog-card）は node/blogcard へ統合したが、
		// 既存記事のために登録・レンダリングは残す。両者の出力が一致すること。
		if ( ! class_exists( 'Node_Library' ) ) {
			$this->markTestSkipped( 'node-library が読み込まれていない環境' );
		}

		$url    = 'https://example.org/legacy-block-article';
		$filter = $this->mock_ogp_response( $url, 'Legacy Block Title' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$new    = node_render_blogcard_block( array( 'url' => $url ) );
		$legacy = Node_Library::instance()->render_blog_card_block( array( 'url' => $url ) );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertSame( $new, $legacy );
		$this->assertStringContainsString( 'Legacy Block Title', $legacy );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_blogcard_block_can_hide_thumbnail(): void {
		$url    = 'https://example.org/thumb-toggle';
		$filter = $this->mock_ogp_response( $url, 'Thumb Toggle Title', 'https://example.com/thumb.jpg' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$with_image = node_render_blogcard_block( array( 'url' => $url ) );
		$no_image   = node_render_blogcard_block(
			array(
				'url'       => $url,
				'showImage' => false,
			)
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		// 既定は取得できた画像を表示する。
		$this->assertStringContainsString( 'm3-blogcard__image', $with_image );
		$this->assertStringContainsString( 'thumb.jpg', $with_image );

		// showImage=false ではサムネイル枠ごと出さない（題名・説明は残る）。
		$this->assertStringNotContainsString( 'm3-blogcard__image', $no_image );
		$this->assertStringNotContainsString( 'thumb.jpg', $no_image );
		$this->assertStringContainsString( 'Thumb Toggle Title', $no_image );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_hide_thumbnail_wins_over_manual_image(): void {
		$url    = 'https://example.org/thumb-priority';
		$filter = $this->mock_ogp_response( $url, 'Priority Title' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = node_render_blogcard_block(
			array(
				'url'       => $url,
				'image'     => 'https://example.com/manual.jpg',
				'showImage' => false,
			)
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertStringNotContainsString( 'manual.jpg', $html );
		$this->assertStringNotContainsString( 'm3-blogcard__image', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_shortcode_show_image_attribute_hides_thumbnail(): void {
		$url    = 'https://example.org/shortcode-thumb';
		$filter = $this->mock_ogp_response( $url, 'Shortcode Thumb Title', 'https://example.com/sc.jpg' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = do_shortcode( '[blogcard url="' . esc_url( $url ) . '" show_image="false"]' );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertStringNotContainsString( 'sc.jpg', $html );
		$this->assertStringContainsString( 'Shortcode Thumb Title', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_blogcard_preview_route_honours_show_image(): void {
		$url    = 'https://example.org/preview-thumb';
		$filter = $this->mock_ogp_response( $url, 'Preview Thumb Title', 'https://example.com/pv.jpg' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$request = new WP_REST_Request( 'GET', '/node/v1/blogcard-preview' );
		$request->set_param( 'url', $url );
		$request->set_param( 'show_image', false );

		$data = node_blogcard_preview_response( $request );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertStringNotContainsString( 'pv.jpg', $data['html'] );
		$this->assertStringContainsString( 'Preview Thumb Title', $data['html'] );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_blogcard_block_is_registered_as_dynamic_block(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		$this->assertTrue( $registry->is_registered( 'node/blogcard' ) );
		$this->assertSame( 'node_render_blogcard_block', $registry->get_registered( 'node/blogcard' )->render_callback );
	}

	public function test_blogcard_preview_route_returns_card_html(): void {
		$url    = 'https://example.org/preview-article';
		$filter = $this->mock_ogp_response( $url, 'Preview Card Title' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$request = new WP_REST_Request( 'GET', '/node/v1/blogcard-preview' );
		$request->set_param( 'url', $url );

		$data = node_blogcard_preview_response( $request );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $data );
		$this->assertStringContainsString( 'Preview Card Title', $data['html'] );
		$this->assertSame( '', $data['store'] );
		$this->assertFalse( $data['fallback'] );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_blogcard_preview_route_reports_store_and_rejects_empty_url(): void {
		$url      = 'https://store.steampowered.com/app/570/Dota_2/';
		$calls    = 0;
		$callback = $this->mock_blocked_response( $calls );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$request = new WP_REST_Request( 'GET', '/node/v1/blogcard-preview' );
		$request->set_param( 'url', $url );
		$data = node_blogcard_preview_response( $request );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 'steam', $data['store'] );
		$this->assertStringContainsString( 'm3-blogcard--store-steam', $data['html'] );

		$empty = new WP_REST_Request( 'GET', '/node/v1/blogcard-preview' );
		$empty->set_param( 'url', '' );
		$this->assertInstanceOf( WP_Error::class, node_blogcard_preview_response( $empty ) );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_blogcard_preview_route_requires_edit_posts_capability(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/node/v1/blogcard-preview', $routes );

		$permission_callback = $routes['/node/v1/blogcard-preview'][0]['permission_callback'];

		wp_set_current_user( 0 );
		$this->assertFalse( (bool) call_user_func( $permission_callback ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( (bool) call_user_func( $permission_callback ) );

		wp_set_current_user( 0 );
	}

	public function test_nintendo_title_id_is_extracted_from_store_urls(): void {
		$this->assertSame( 'D70010000000964', node_nintendo_title_id( 'https://store-jp.nintendo.com/item/software/D70010000000964' ) );
		$this->assertSame( '70010000012332', node_nintendo_title_id( 'https://ec.nintendo.com/JP/ja/titles/70010000012332' ) );
		$this->assertSame( '', node_nintendo_title_id( 'https://www.nintendo.com/jp/store/products/minecraft-switch/' ) );
	}

	public function test_store_catalog_lookups_return_matching_game_titles(): void {
		$requests = array();
		$callback = static function ( $preempt, $args, $request_url ) use ( &$requests ) {
			$requests[] = $request_url;
			$body       = '';
			if ( str_contains( $request_url, 'search.nintendo.jp' ) ) {
				$body = wp_json_encode( array( 'result' => array( 'items' => array( array( 'id' => '70010000027618', 'title' => 'あつまれ どうぶつの森' ) ) ) ) );
			} elseif ( str_contains( $request_url, 'web.np.playstation.com' ) && str_contains( $request_url, 'metGetConceptById' ) ) {
				$body = wp_json_encode( array( 'data' => array( 'conceptRetrieve' => array( 'id' => '10002684', 'name' => 'アストロボット' ) ) ) );
			} elseif ( str_contains( $request_url, 'web.np.playstation.com' ) ) {
				$body = wp_json_encode( array( 'data' => array( 'productRetrieve' => array( 'id' => 'JP0127-CUSA00283_00-JAPAN00000000001', 'name' => 'Minecraft' ) ) ) );
			} elseif ( str_contains( $request_url, 'displaycatalog.mp.microsoft.com' ) ) {
				$body = wp_json_encode( array( 'Product' => array( 'ProductId' => '9NKX70BBCDRN', 'LocalizedProperties' => array( array( 'ProductTitle' => 'Forza Horizon 5 標準版' ) ) ) ) );
			}
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => $body,
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		delete_transient( 'node_nintendo_soft_v2_' . md5( 'D70010000027618' ) );
		delete_transient( 'node_playstation_v2_product_' . md5( 'ja-jp:JP0127-CUSA00283_00-JAPAN00000000001' ) );
		delete_transient( 'node_playstation_v2_concept_' . md5( 'ja-jp:10002684' ) );
		delete_transient( 'node_microsoft_product_v2_' . md5( '9NKX70BBCDRN' ) );

		$this->assertSame( 'あつまれ どうぶつの森', node_nintendo_store_lookup( 'https://store-jp.nintendo.com/item/software/D70010000027618' )['title'] );
		$this->assertSame( 'Minecraft', node_playstation_store_title( 'https://store.playstation.com/ja-jp/product/JP0127-CUSA00283_00-JAPAN00000000001' ) );
		$this->assertSame( 'アストロボット', node_playstation_store_title( 'https://store.playstation.com/ja-jp/concept/10002684' ) );
		$this->assertSame( 'Forza Horizon 5 標準版', node_microsoft_store_title( 'https://www.xbox.com/ja-JP/games/store/forza-horizon-5/9NKX70BBCDRN' ) );
		$this->assertStringContainsString( 'fq=id:70010000027618', $requests[0] );
		$this->assertCount( 4, $requests );

		remove_filter( 'pre_http_request', $callback, 10 );
		delete_transient( 'node_nintendo_soft_v2_' . md5( 'D70010000027618' ) );
		delete_transient( 'node_playstation_v2_product_' . md5( 'ja-jp:JP0127-CUSA00283_00-JAPAN00000000001' ) );
		delete_transient( 'node_playstation_v2_concept_' . md5( 'ja-jp:10002684' ) );
		delete_transient( 'node_microsoft_product_v2_' . md5( '9NKX70BBCDRN' ) );
	}

	public function test_verified_store_titles_survive_temporary_catalog_failures(): void {
		$cases = array(
			array( 'nintendo:D70010000027618', 'node_nintendo_soft_v2_' . md5( 'D70010000027618' ), 'あつまれ どうぶつの森' ),
			array( 'playstation:ja-jp:product:JP0127-CUSA00283_00-JAPAN00000000001', 'node_playstation_v2_product_' . md5( 'ja-jp:JP0127-CUSA00283_00-JAPAN00000000001' ), 'Minecraft' ),
			array( 'microsoft:9NKX70BBCDRN', 'node_microsoft_product_v2_' . md5( '9NKX70BBCDRN' ), 'Forza Horizon 5 標準版' ),
		);
		foreach ( $cases as list( $key, $transient, $title ) ) {
			update_option( 'node_store_title_' . md5( $key ), $title, false );
			set_transient( $transient, node_blogcard_fetch_failure_marker(), HOUR_IN_SECONDS );
		}

		$this->assertSame( 'あつまれ どうぶつの森', node_nintendo_store_lookup( 'https://store-jp.nintendo.com/item/software/D70010000027618' )['title'] );
		$this->assertSame( 'Minecraft', node_playstation_store_title( 'https://store.playstation.com/ja-jp/product/JP0127-CUSA00283_00-JAPAN00000000001' ) );
		$this->assertSame( 'Forza Horizon 5 標準版', node_microsoft_store_title( 'https://www.xbox.com/ja-JP/games/store/forza-horizon-5/9NKX70BBCDRN' ) );

		foreach ( $cases as list( $key, $transient ) ) {
			delete_option( 'node_store_title_' . md5( $key ) );
			delete_transient( $transient );
		}
	}

	public function test_microsoft_store_uses_us_catalog_when_japanese_listing_is_missing(): void {
		$id       = '9NKX70BBCDRN';
		$requests = array();
		$callback = static function ( $preempt, $args, $request_url ) use ( &$requests, $id ) {
			$requests[] = $request_url;
			$body = str_contains( $request_url, 'market=US' )
				? wp_json_encode( array( 'Product' => array( 'ProductId' => $id, 'LocalizedProperties' => array( array( 'ProductTitle' => 'Forza Horizon 5' ) ) ) ) )
				: wp_json_encode( array( 'Product' => array() ) );
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => $body,
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		delete_option( 'node_store_title_' . md5( 'microsoft:' . $id ) );
		delete_transient( 'node_microsoft_product_v2_' . md5( $id ) );
		add_filter( 'pre_http_request', $callback, 10, 3 );

		$this->assertSame( 'Forza Horizon 5', node_microsoft_store_title( 'https://apps.microsoft.com/detail/' . $id ) );
		$this->assertCount( 2, $requests );

		remove_filter( 'pre_http_request', $callback, 10 );
		delete_option( 'node_store_title_' . md5( 'microsoft:' . $id ) );
		delete_transient( 'node_microsoft_product_v2_' . md5( $id ) );
	}

	public function test_nintendo_product_id_url_uses_search_lookup_for_title(): void {
		$url     = 'https://store-jp.nintendo.com/item/software/D70010000000964';
		$calls   = 0;
		$payload = array(
			'result' => array(
				'total' => 1,
				'items' => array(
					array(
						'id'            => 'D70010000000964',
						'title'         => 'Minecraft',
						'sales_img_url' => 'https://img-eshop.cdn.nintendo.net/i/minecraft.jpg',
					),
				),
			),
		);

		$callback = $this->mock_nintendo_search( $payload, $calls );
		delete_transient( 'node_ogp_' . md5( $url ) );
		delete_transient( 'node_nintendo_soft_v2_' . md5( 'D70010000000964' ) );

		$html = node_render_blogcard( $url );

		// 2回目はキャッシュから返し、検索を再度叩かないこと。
		node_render_blogcard( $url );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertStringContainsString( 'Minecraft', $html );
		$this->assertStringContainsString( 'minecraft.jpg', $html );
		$this->assertStringContainsString( 'm3-blogcard--store-nintendo', $html );
		$this->assertStringNotContainsString( 'm3-blogcard__fallback', $html );
		$this->assertSame( 1, $calls );

		delete_transient( 'node_ogp_' . md5( $url ) );
		delete_transient( 'node_nintendo_soft_v2_' . md5( 'D70010000000964' ) );
	}

	public function test_nintendo_lookup_ignores_old_failure_cache(): void {
		$id      = 'D70010000027618';
		$url     = 'https://store-jp.nintendo.com/item/software/' . $id;
		$calls   = 0;
		$payload = array(
			'result' => array(
				'items' => array(
					array( 'id' => '70010000027618', 'title' => 'あつまれ どうぶつの森' ),
				),
			),
		);
		set_transient( 'node_nintendo_soft_' . md5( $id ), node_blogcard_fetch_failure_marker(), 6 * HOUR_IN_SECONDS );
		delete_transient( 'node_nintendo_soft_v2_' . md5( $id ) );
		$callback = $this->mock_nintendo_search( $payload, $calls );

		$result = node_nintendo_store_lookup( $url );

		remove_filter( 'pre_http_request', $callback, 10 );
		$this->assertSame( 'あつまれ どうぶつの森', $result['title'] );
		$this->assertSame( 1, $calls );
		delete_transient( 'node_nintendo_soft_' . md5( $id ) );
		delete_transient( 'node_nintendo_soft_v2_' . md5( $id ) );
		delete_option( 'node_store_title_' . md5( 'nintendo:' . $id ) );
	}

	public function test_nintendo_lookup_ignores_items_with_different_id(): void {
		// 誤った題名を載せるくらいなら、ストア名のままにする。
		$url     = 'https://store-jp.nintendo.com/item/software/D70010000000964';
		$calls   = 0;
		$payload = array(
			'result' => array(
				'items' => array(
					array(
						'id'    => 'D70010000099999',
						'title' => '全然違うゲーム',
					),
				),
			),
		);

		$callback = $this->mock_nintendo_search( $payload, $calls );
		delete_transient( 'node_ogp_' . md5( $url ) );
		delete_transient( 'node_nintendo_soft_v2_' . md5( 'D70010000000964' ) );

		$html = node_render_blogcard( $url );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertStringNotContainsString( '全然違うゲーム', $html );
		$this->assertStringContainsString( 'ニンテンドーストア', $html );
		$this->assertStringContainsString( 'm3-blogcard--store-nintendo', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
		delete_transient( 'node_nintendo_soft_v2_' . md5( 'D70010000000964' ) );
	}

	public function test_nintendo_lookup_survives_unexpected_payload(): void {
		$url      = 'https://store-jp.nintendo.com/item/software/D70010000000964';
		$calls    = 0;
		$callback = $this->mock_nintendo_search( array( 'unexpected' => 'shape' ), $calls );
		delete_transient( 'node_ogp_' . md5( $url ) );
		delete_transient( 'node_nintendo_soft_v2_' . md5( 'D70010000000964' ) );

		$html = node_render_blogcard( $url );

		remove_filter( 'pre_http_request', $callback, 10 );

		// 応答が想定外でもカードは出す（題名はストア名）。
		$this->assertStringContainsString( 'ニンテンドーストア', $html );
		$this->assertStringContainsString( 'm3-blogcard--store-nintendo', $html );
		$this->assertStringNotContainsString( 'm3-blogcard__fallback', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
		delete_transient( 'node_nintendo_soft_v2_' . md5( 'D70010000000964' ) );
	}

	public function test_nintendo_lookup_is_skipped_when_title_comes_from_url(): void {
		// スラッグから題名を作れる URL では検索を叩かない。
		$url      = 'https://www.nintendo.com/jp/store/products/minecraft-switch/';
		$calls    = 0;
		$callback = $this->mock_nintendo_search( array(), $calls );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = node_render_blogcard( $url );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertStringContainsString( 'Minecraft', $html );
		$this->assertSame( 0, $calls );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_store_title_from_url_strips_nintendo_hardware_suffix(): void {
		// nintendo.com の商品スラッグは機種名で終わるため、題名としては落とす。
		$this->assertSame( 'Minecraft', node_store_title_from_url( 'https://www.nintendo.com/jp/store/products/minecraft-switch/', 'nintendo' ) );
		$this->assertSame( 'Minecraft', node_store_title_from_url( 'https://www.nintendo.com/us/store/products/minecraft-for-nintendo-switch/', 'nintendo' ) );
		$this->assertSame( 'Mario Kart World', node_store_title_from_url( 'https://www.nintendo.com/jp/store/products/mario-kart-world-switch-2/', 'nintendo' ) );
		$this->assertSame( 'The Legend Of Zelda Tears Of The Kingdom', node_store_title_from_url( 'https://www.nintendo.com/jp/store/products/the-legend-of-zelda-tears-of-the-kingdom-switch/', 'nintendo' ) );
	}

	public function test_shortcode_title_attribute_overrides_blocked_store_fetch(): void {
		// 商品 ID だけの URL は題名を復元できないため、著者が title 属性で指定できる。
		$url      = 'https://store-jp.nintendo.com/item/software/D70010000000964';
		$calls    = 0;
		$callback = $this->mock_blocked_response( $calls );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = do_shortcode( '[blogcard url="' . esc_url( $url ) . '" title="Minecraft" description="ブロックで遊ぶ" image="https://example.com/minecraft.jpg"]' );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertStringContainsString( 'Minecraft', $html );
		$this->assertStringContainsString( 'ブロックで遊ぶ', $html );
		$this->assertStringContainsString( 'https://example.com/minecraft.jpg', $html );
		$this->assertStringContainsString( 'm3-blogcard--store-nintendo', $html );
		$this->assertStringContainsString( 'ニンテンドーストア', $html );
		$this->assertStringNotContainsString( 'm3-blogcard__fallback', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_shortcode_title_attribute_overrides_scraped_title(): void {
		$url    = 'https://example.org/some-article';
		$filter = $this->mock_ogp_response( $url, 'Scraped Title' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = do_shortcode( '[blogcard url="' . esc_url( $url ) . '" title="手動タイトル"]' );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertStringContainsString( '手動タイトル', $html );
		$this->assertStringNotContainsString( 'Scraped Title', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_shortcode_without_overrides_keeps_existing_behaviour(): void {
		$url    = 'https://example.org/plain-article';
		$filter = $this->mock_ogp_response( $url, 'Plain Scraped Title' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = do_shortcode( '[blogcard url="' . esc_url( $url ) . '"]' );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertStringContainsString( 'Plain Scraped Title', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_store_response_is_product_page_detects_redirect_to_store_top(): void {
		$xbox = 'https://www.xbox.com/ja-JP/games/store/minecraft/9NBLGGH537BL';

		// 最終 URL 不明（pre_http_request 短絡時など）は検証しない。
		$this->assertTrue( node_store_response_is_product_page( $xbox, '' ) );
		// 地域だけ変わる転送は商品ページのまま。
		$this->assertTrue( node_store_response_is_product_page( $xbox, 'https://www.xbox.com/en-US/games/store/minecraft/9NBLGGH537BL' ) );
		// 商品ページからストアのトップへ飛ばされた場合は別ページ扱い。
		$this->assertFalse( node_store_response_is_product_page( $xbox, 'https://www.xbox.com/ja-JP/' ) );
		// 同じストア配下でも商品 ID が消えていれば別ページ。
		$this->assertFalse( node_store_response_is_product_page( $xbox, 'https://www.xbox.com/ja-JP/games/store/' ) );

		$nintendo = 'https://store-jp.nintendo.com/item/software/D70010000000964';
		$this->assertTrue( node_store_response_is_product_page( $nintendo, 'https://store-jp.nintendo.com/item/software/D70010000000964?utm=1' ) );
		$this->assertFalse( node_store_response_is_product_page( $nintendo, 'https://store-jp.nintendo.com/' ) );

		// スラッグ型（ID ではない）の商品パスは、転送で末尾が落ちても許容する。
		$steam = 'https://store.steampowered.com/app/570/Dota_2/';
		$this->assertTrue( node_store_response_is_product_page( $steam, 'https://store.steampowered.com/app/570/' ) );
	}

	public function test_store_card_falls_back_when_response_redirected_to_store_top(): void {
		// Xbox は商品ページを取得できないとき 403 ではなくトップへ転送し、
		// 「Xbox 公式サイト...」というサイト共通のタイトルを 200 で返す。
		$url = 'https://www.xbox.com/ja-JP/games/store/minecraft/9NBLGGH537BL';

		set_transient(
			'node_ogp_' . md5( $url ),
			array(
				'title'       => 'Xbox 公式サイト: 本体、ゲーム、コミュニティ |Xbox',
				'description' => 'Xbox のトップページ',
				'image'       => 'https://www.xbox.com/top.jpg',
				'favicon'     => '',
				'site_name'   => 'Xbox',
				'is_internal' => false,
				'final_url'   => 'https://www.xbox.com/ja-JP/',
			),
			HOUR_IN_SECONDS
		);

		$html = node_render_blogcard( $url );

		$this->assertStringNotContainsString( 'Xbox 公式サイト', $html );
		$this->assertStringContainsString( 'Minecraft', $html );
		$this->assertStringContainsString( 'm3-blogcard--store-xbox', $html );
		$this->assertStringNotContainsString( 'm3-blogcard__fallback', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_store_card_keeps_scraped_title_when_redirect_stays_on_product_page(): void {
		$url = 'https://store.playstation.com/ja-jp/product/JP0177-PPSA04466_00-MINECRAFTBEDROCK';

		set_transient(
			'node_ogp_' . md5( $url ),
			array(
				'title'       => 'Minecraft',
				'description' => 'Minecraft の説明',
				'image'       => 'https://image.api.playstation.com/cover.png',
				'favicon'     => '',
				'site_name'   => 'PlayStation',
				'is_internal' => false,
				// 地域リダイレクト後も商品 ID は保たれる。
				'final_url'   => 'https://store.playstation.com/ja-jp/concept/JP0177-PPSA04466_00-MINECRAFTBEDROCK/',
			),
			HOUR_IN_SECONDS
		);

		$html = node_render_blogcard( $url );

		$this->assertStringContainsString( 'Minecraft', $html );
		$this->assertStringContainsString( 'cover.png', $html );
		$this->assertStringContainsString( 'm3-blogcard--store-playstation', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_store_urls_render_card_even_when_fetch_is_blocked(): void {
		$cases = array(
			'https://store-jp.nintendo.com/item/software/D70010000073404'                      => array( 'nintendo', 'ニンテンドーストア' ),
			'https://store.playstation.com/ja-jp/product/JP0082-PPSA01284_00-ASTROSBGDELUXE01' => array( 'playstation', 'PlayStation Store' ),
			'https://www.xbox.com/ja-JP/games/store/forza-horizon-5/9NKX70BBCDRN'              => array( 'xbox', 'Microsoft ストア' ),
			'https://store.steampowered.com/app/570/Dota_2/'                                   => array( 'steam', 'Steam' ),
		);

		$calls    = 0;
		$callback = $this->mock_blocked_response( $calls );

		foreach ( $cases as $url => list( $slug, $store_name ) ) {
			delete_transient( 'node_ogp_' . md5( $url ) );

			$html = node_blogcard_hydrate( node_embed_maybe_make_link( '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>', $url ) );

			$this->assertStringContainsString( 'm3-blogcard--store-' . $slug, $html, $url );
			$this->assertStringContainsString( $store_name, $html, $url );
			$this->assertStringContainsString( 'm3-blogcard__overlay', $html, $url );
			$this->assertStringNotContainsString( 'm3-blogcard__fallback', $html, $url );
		}

		// 題名を復元できる URL ではスラッグ由来の題名を使う。
		$this->assertStringContainsString(
			'Forza Horizon 5',
			node_blogcard_hydrate( node_embed_maybe_make_link( '<a>x</a>', 'https://www.xbox.com/ja-JP/games/store/forza-horizon-5/9NKX70BBCDRN' ) )
		);

		remove_filter( 'pre_http_request', $callback, 10 );
	}

	public function test_non_store_url_renders_url_derived_card_when_blocked(): void {
		$url      = 'https://example.com/blocked-article';
		$calls    = 0;
		$callback = $this->mock_blocked_response( $calls );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = node_render_blogcard( $url );

		remove_filter( 'pre_http_request', $callback, 10 );

		// 素のリンクへ落とさず、URL から組み立てたカードにする。
		$this->assertStringContainsString( 'm3-blogcard--fallback', $html );
		$this->assertStringContainsString( 'm3-blogcard__overlay', $html );
		$this->assertStringContainsString( 'Blocked Article', $html );
		$this->assertStringContainsString( 'm3-blogcard__image--placeholder', $html );
	}

	public function test_blogcard_title_from_url_humanizes_slug_and_rejects_ids(): void {
		$cases = array(
			'https://www.epicgames.com/help/fortnite-battle-royale-c-202300000001636/billing-and-payment-c-202300000001723/v-bucks-a202300000011516' => 'V Bucks',
			'https://example.com/news/2024/05/my-great-post-123456.html' => 'My Great Post',
			'https://example.com/blog/index.html'                        => 'Blog',
			'https://ja.example.com/%E8%A8%98%E4%BA%8B%E3%82%BF%E3%82%A4%E3%83%88%E3%83%AB/' => '記事タイトル',
			'https://example.com/'                                       => '',
			'https://example.com/2024/05/12/'                            => '',
			'https://example.com/p/a1b2c3d4e5f6'                         => '',
		);

		foreach ( $cases as $url => $expected ) {
			$this->assertSame( $expected, node_blogcard_title_from_url( $url ), $url );
		}

		$this->assertSame(
			'Help › Fortnite Battle Royale › Billing And Payment',
			node_blogcard_breadcrumb_from_url( 'https://www.epicgames.com/help/fortnite-battle-royale-c-202300000001636/billing-and-payment-c-202300000001723/v-bucks-a202300000011516' )
		);
	}

	public function test_epic_games_url_renders_fallback_card_when_cloudflare_blocks_title_fetch(): void {
		$url      = 'https://www.epicgames.com/help/fortnite-battle-royale-c-202300000001636/billing-and-payment-c-202300000001723/v-bucks-a202300000011516';
		$calls    = 0;
		$callback = $this->mock_blocked_response( $calls );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = node_render_blogcard( $url );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertStringContainsString( 'm3-blogcard--fallback', $html );
		$this->assertStringContainsString( 'V Bucks', $html );
		$this->assertStringContainsString( 'Fortnite Battle Royale', $html );
		$this->assertStringContainsString( 'epicgames.com', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_unresolvable_internal_url_still_renders_missing_marker(): void {
		$html = node_render_blogcard( home_url( '/no-such-post-here/' ) );

		$this->assertStringContainsString( 'm3-blogcard__fallback--missing', $html );
		$this->assertStringNotContainsString( 'm3-blogcard--fallback', $html );
	}

	public function test_html_meta_charset_is_converted_to_utf8(): void {
		$sjis = '<meta charset="Shift_JIS">' . mb_convert_encoding( 'こんにちは', 'SJIS', 'UTF-8' );
		$this->assertSame( '<meta charset="Shift_JIS">こんにちは', node_blogcard_to_utf8( $sjis, 'text/html' ) );

		$euc = '<meta charset="EUC-JP">' . mb_convert_encoding( '日本語', 'EUC-JP', 'UTF-8' );
		$this->assertSame( '<meta charset="EUC-JP">日本語', node_blogcard_to_utf8( $euc, 'text/html' ) );

		$this->assertSame(
			'日本語',
			node_blogcard_to_utf8( mb_convert_encoding( '日本語', 'SJIS', 'UTF-8' ), 'text/html; charset=Shift_JIS' )
		);

		$this->assertSame( '<html>日本語</html>', node_blogcard_to_utf8( '<html>日本語</html>', 'text/html; charset=UTF-8' ) );
	}

	public function test_failed_fetch_is_negatively_cached(): void {
		$url      = 'https://store-jp.nintendo.com/item/software/D70010000099999';
		$calls    = 0;
		$callback = $this->mock_blocked_response( $calls );
		delete_transient( 'node_ogp_' . md5( $url ) );

		node_render_blogcard( $url );
		node_render_blogcard( $url );
		node_render_blogcard( $url );

		remove_filter( 'pre_http_request', $callback, 10 );

		// 商品ページ OGP と任天堂検索を初回に各1回試す。双方の失敗をキャッシュするため、
		// 2回目以降のレンダーでは外部 HTTP が増えない。
		$this->assertSame( 2, $calls );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_ogp_request_uses_browser_user_agent(): void {
		$url        = 'https://store.playstation.com/ja-jp/product/UA-CHECK';
		$user_agent = '';
		$callback   = static function ( $preempt, $args, $request_url ) use ( &$user_agent ) {
			$user_agent = (string) ( $args['user-agent'] ?? '' );
			return new WP_Error( 'http_request_failed', 'stub' );
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );
		delete_transient( 'node_ogp_' . md5( $url ) );

		node_get_ogp_data( $url );

		remove_filter( 'pre_http_request', $callback, 10 );

		// ボット UA だとゲームストアの防御に 403 で弾かれる。
		$this->assertStringContainsString( 'Chrome/', $user_agent );
		$this->assertStringNotContainsString( 'LuminousCore', $user_agent );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_ogp_data_falls_back_to_json_ld_when_og_tags_are_missing(): void {
		$url      = 'https://store.steampowered.com/app/570/Dota_2/';
		$json_ld  = wp_json_encode(
			array(
				'@context'    => 'https://schema.org',
				'@graph'      => array(
					array( '@type' => 'BreadcrumbList' ),
					array(
						'@type'       => 'VideoGame',
						'name'        => 'JSON-LD Game Title',
						'description' => 'JSON-LD Game Description',
						'image'       => array( 'url' => 'https://cdn.example.com/header.jpg' ),
					),
				),
			)
		);
		$callback = static function ( $preempt, $args, $request_url ) use ( $url, $json_ld ) {
			if ( $request_url !== $url ) {
				return $preempt;
			}

			return array(
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
				'body'     => '<html><head><title>Steam</title><script type="application/ld+json">' . $json_ld . '</script></head><body></body></html>',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$ogp  = node_get_ogp_data( $url );
		$html = node_render_blogcard( $url );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertIsArray( $ogp );
		$this->assertSame( 'JSON-LD Game Title', $ogp['title'] );
		$this->assertSame( 'https://cdn.example.com/header.jpg', $ogp['image'] );
		$this->assertStringContainsString( 'JSON-LD Game Title', $html );
		$this->assertStringContainsString( 'JSON-LD Game Description', $html );
		$this->assertStringContainsString( 'm3-blogcard--store-steam', $html );
		// サイト名はストアの正式名へ正規化する。
		$this->assertStringContainsString( 'Steam', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_ogp_data_reads_twitter_card_when_og_is_absent(): void {
		$url      = 'https://example.org/twitter-card-only';
		$callback = static function ( $preempt, $args, $request_url ) use ( $url ) {
			if ( $request_url !== $url ) {
				return $preempt;
			}

			return array(
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
				'body'     => '<html><head><meta name="twitter:title" content="Twitter Card Title"><meta name="twitter:description" content="Twitter Card Description"><meta name="twitter:image" content="/relative/card.jpg"></head><body></body></html>',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$ogp = node_get_ogp_data( $url );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertIsArray( $ogp );
		$this->assertSame( 'Twitter Card Title', $ogp['title'] );
		$this->assertSame( 'Twitter Card Description', $ogp['description'] );
		// 相対 URL の画像は絶対 URL へ補正する。
		$this->assertSame( 'https://example.org/relative/card.jpg', $ogp['image'] );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_store_card_uses_scraped_title_when_fetch_succeeds(): void {
		$url    = 'https://store-jp.nintendo.com/item/software/D70010000073404';
		$filter = $this->mock_ogp_response( $url, 'ゼルダの伝説', 'https://store-jp.nintendo.com/thumb.jpg' );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$html = node_render_blogcard( $url );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertStringContainsString( 'ゼルダの伝説', $html );
		$this->assertStringContainsString( 'm3-blogcard--store-nintendo', $html );
		$this->assertStringContainsString( 'ニンテンドーストア', $html );
		// og:site_name（モックは Example Site）ではなくストア正式名を表示する。
		$this->assertStringNotContainsString( 'Example Site', $html );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}

	public function test_oembed_dataparse_marks_store_urls(): void {
		$html = node_blogcard_hydrate(
			node_oembed_dataparse(
				'<div>Original embed</div>',
				(object) array(
					'title'         => 'Store oEmbed Title',
					'provider_name' => 'Sony Interactive Entertainment',
				),
				'https://store.playstation.com/ja-jp/product/JP0082-PPSA01284_00-ASTROSBGDELUXE01'
			)
		);

		$this->assertStringContainsString( 'm3-blogcard--store-playstation', $html );
		$this->assertStringContainsString( 'PlayStation Store', $html );
		$this->assertStringContainsString( 'Store oEmbed Title', $html );
	}

	public function test_store_card_keeps_single_line_markup_through_wpautop(): void {
		$url      = 'https://store.steampowered.com/app/570/Dota_2/';
		$calls    = 0;
		$callback = $this->mock_blocked_response( $calls );
		delete_transient( 'node_ogp_' . md5( $url ) );

		$placeholder = node_embed_maybe_make_link( '<a>x</a>', $url );
		$final       = node_blogcard_hydrate( wpautop( "<h2>foo</h2>\n\n{$placeholder}\n\n<h2>bar</h2>" ) );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertStringContainsString( 'm3-blogcard--store-steam', $final );
		$this->assertStringNotContainsString( '<br', $final );
		$this->assertStringNotContainsString( 'node-blogcard-slot', $final );

		delete_transient( 'node_ogp_' . md5( $url ) );
	}
}
