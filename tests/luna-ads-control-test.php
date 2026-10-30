<?php
/**
 * Luna Ads Control の単体テスト。
 *
 * 設定の既定値・サニタイズ、権限、nonce、出力条件、二重挿入防止、
 * テーマ/Site Kit との競合回避を検証する。
 *
 * @package Luna_Ads_Control
 */

if ( ! class_exists( 'Luna_Ads_Control' ) ) {
	require_once dirname( __DIR__ ) . '/plugins/luna-ads-control/luna-ads-control.php';
}

class Luna_Ads_Control_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Luna_Ads_Settings::OPTION );
		delete_option( 'node_ad_code_article' );
		delete_option( 'node_ad_code_infeed' );
		update_option( 'show_on_front', 'posts' );
		Luna_Ads_Renderer::reset_state();
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		delete_option( Luna_Ads_Settings::OPTION );
		delete_option( 'node_ad_code_article' );
		delete_option( 'node_ad_code_infeed' );
		Luna_Ads_Renderer::reset_state();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * 指定スロットを有効にした設定を保存する。
	 *
	 * @param array<string,array<string,mixed>> $slots スロット設定。
	 * @param array<string,mixed>               $extra 追加設定。
	 * @return void
	 */
	private function configure( array $slots = array(), array $extra = array() ) {
		$settings            = Luna_Ads_Settings::defaults();
		$settings['enabled'] = true;

		foreach ( $slots as $slot => $config ) {
			$settings['slots'][ $slot ] = array_merge( $settings['slots'][ $slot ], $config );
		}

		$settings = array_replace_recursive( $settings, $extra );
		update_option( Luna_Ads_Settings::OPTION, $settings );
	}

	/**
	 * 記事の本文フィルターを通す。
	 *
	 * @param int    $post_id 投稿ID。
	 * @param string $content 本文。
	 * @return string
	 */
	private function render_content( $post_id, $content ) {
		$this->go_to( get_permalink( $post_id ) );
		Luna_Ads_Renderer::reset_state();
		return (string) apply_filters( 'the_content', $content );
	}

	public function test_defaults_are_safe_and_disabled() {
		$settings = Luna_Ads_Settings::get();

		$this->assertFalse( $settings['enabled'] );
		$this->assertSame( 'plugin', $settings['code_source'] );
		$this->assertSame( 'manual', $settings['delivery'] );
		$this->assertSame( '', $settings['client_id'] );
		$this->assertSame( 8, Luna_Ads_Settings::field_count() );

		foreach ( $settings['slots'] as $slot ) {
			$this->assertFalse( $slot['enabled'] );
			$this->assertSame( '', $slot['code'] );
		}
	}

	public function test_activation_does_not_enable_ads_or_overwrite_existing_settings() {
		delete_option( Luna_Ads_Settings::OPTION );
		Luna_Ads_Settings::activate();
		$this->assertFalse( Luna_Ads_Settings::get()['enabled'] );

		update_option( Luna_Ads_Settings::OPTION, array( 'enabled' => true ) );
		Luna_Ads_Settings::activate();
		$this->assertTrue( Luna_Ads_Settings::get()['enabled'] );
	}

	public function test_sanitize_rejects_invalid_enums_and_clamps_numbers() {
		$sanitized = Luna_Ads_Settings::sanitize(
			array(
				'enabled'     => '1',
				'code_source' => 'evil',
				'delivery'    => 'evil',
				'client_id'   => 'javascript:alert(1)',
				'slots'       => array(
					'in_article' => array(
						'enabled'   => '1',
						'paragraph' => '0',
					),
					'infeed'     => array(
						'every' => '999',
					),
				),
				'exclude'     => array(
					'post_ids'     => '1, 2,abc,2',
					'category_ids' => '3, x, 3',
					'logged_in'    => '1',
				),
			)
		);

		$this->assertTrue( $sanitized['enabled'] );
		$this->assertSame( 'plugin', $sanitized['code_source'] );
		$this->assertSame( 'manual', $sanitized['delivery'] );
		$this->assertSame( '', $sanitized['client_id'] );
		$this->assertSame( 1, $sanitized['slots']['in_article']['paragraph'] );
		$this->assertSame( 20, $sanitized['slots']['infeed']['every'] );
		$this->assertSame( '1,2', $sanitized['exclude']['post_ids'] );
		$this->assertSame( '3', $sanitized['exclude']['category_ids'] );
		$this->assertTrue( $sanitized['exclude']['logged_in'] );
	}

	public function test_sanitize_ad_code_keeps_ad_tags_and_strips_event_handlers() {
		$code = '<ins class="adsbygoogle" data-ad-client="ca-pub-1234567890" onclick="alert(1)"></ins>' .
			'<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script>' .
			'<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';

		$clean = Luna_Ads_Settings::sanitize_ad_code( $code );

		$this->assertStringContainsString( '<ins', $clean );
		$this->assertStringContainsString( 'adsbygoogle', $clean );
		$this->assertStringContainsString( '<script', $clean );
		$this->assertStringContainsString( 'window.adsbygoogle', $clean );
		$this->assertStringNotContainsString( 'onclick', $clean );
	}

	public function test_disabled_plugin_outputs_nothing() {
		$post_id = self::factory()->post->create();
		Luna_Ads_Settings::update(
			array(
				'slots' => array(
					'in_article' => array(
						'enabled'   => '1',
						'code'      => '<ins class="adsbygoogle"></ins>',
						'paragraph' => '1',
					),
				),
			)
		);

		$out = $this->render_content( $post_id, '<p>one</p><p>two</p>' );

		$this->assertStringNotContainsString( 'luna-ad', $out );
	}

	public function test_in_article_ad_is_inserted_after_requested_paragraph() {
		$post_id = self::factory()->post->create();
		$this->configure(
			array(
				'in_article' => array(
					'enabled'   => true,
					'code'      => '<ins class="adsbygoogle" data-ad-slot="111"></ins>',
					'paragraph' => 2,
				),
			)
		);

		$out = $this->render_content( $post_id, '<p>one</p><p>two</p><p>three</p>' );

		$second_p = strpos( $out, '</p>', strpos( $out, '</p>' ) + 4 ) + 4;
		$ad_pos   = strpos( $out, 'data-luna-ad="in_article"' );
		$third_p  = strpos( $out, '<p>three</p>' );

		$this->assertNotFalse( $ad_pos );
		$this->assertGreaterThan( $second_p, $ad_pos );
		$this->assertLessThan( $third_p, $ad_pos );
		$this->assertSame( 1, substr_count( $out, 'data-luna-ad="in_article"' ) );
	}

	public function test_in_article_falls_back_to_end_without_bottom_slot() {
		$post_id = self::factory()->post->create();
		$this->configure(
			array(
				'in_article' => array(
					'enabled'   => true,
					'code'      => '<ins class="adsbygoogle" data-ad-slot="222"></ins>',
					'paragraph' => 10,
				),
			)
		);

		$out = $this->render_content( $post_id, '<p>one</p>' );

		$this->assertStringContainsString( 'data-luna-ad="in_article"', $out );
		$this->assertGreaterThan( strpos( $out, '</p>' ), strpos( $out, 'data-luna-ad="in_article"' ) );
	}

	public function test_in_article_survives_an_earlier_content_pass() {
		$post_id = self::factory()->post->create();
		$this->configure(
			array(
				'in_article' => array(
					'enabled'   => true,
					'code'      => '<ins class="adsbygoogle" data-ad-slot="121"></ins>',
					'paragraph' => 1,
				),
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		Luna_Ads_Renderer::reset_state();

		// SEO 説明文など、表示されない本文フィルターが先に走るケース。
		apply_filters( 'the_content', '<p>preview</p>' );

		$out = (string) apply_filters( 'the_content', '<p>one</p><p>two</p>' );

		$this->assertStringContainsString( 'data-luna-ad="in_article"', $out );
		$this->assertSame( 1, substr_count( $out, 'data-luna-ad="in_article"' ) );
	}

	public function test_bottom_ad_is_appended_and_auto_delivery_skips_manual_slots() {
		$post_id = self::factory()->post->create();

		$this->configure(
			array(
				'article_bottom' => array(
					'enabled' => true,
					'code'    => '<ins class="adsbygoogle" data-ad-slot="333"></ins>',
				),
			)
		);
		$manual = $this->render_content( $post_id, '<p>one</p>' );
		$this->assertStringContainsString( 'data-luna-ad="article_bottom"', $manual );

		$this->configure(
			array(
				'article_bottom' => array(
					'enabled' => true,
					'code'    => '<ins class="adsbygoogle" data-ad-slot="333"></ins>',
				),
			),
			array( 'delivery' => 'auto' )
		);
		$auto = $this->render_content( $post_id, '<p>one</p>' );
		$this->assertStringNotContainsString( 'luna-ad', $auto );
	}

	public function test_bottom_ad_runs_after_late_content_filters() {
		$post_id = self::factory()->post->create();
		$this->configure(
			array(
				'article_bottom' => array(
					'enabled' => true,
					'code'    => '<ins class="adsbygoogle" data-ad-slot="888"></ins>',
				),
			)
		);

		$late = static function ( $content ) {
			return $content . '<div class="test-footnotes">note</div>';
		};
		add_filter( 'the_content', $late, 999 );

		$out = $this->render_content( $post_id, '<p>one</p>' );

		remove_filter( 'the_content', $late, 999 );

		$this->assertGreaterThan(
			strpos( $out, 'test-footnotes' ),
			strpos( $out, 'data-luna-ad="article_bottom"' )
		);
	}

	public function test_empty_code_outputs_no_placeholder() {
		$post_id = self::factory()->post->create();
		$this->configure(
			array(
				'in_article' => array(
					'enabled'   => true,
					'code'      => '',
					'paragraph' => 1,
				),
				'article_bottom' => array(
					'enabled' => true,
					'code'    => '   ',
				),
			)
		);

		$out = $this->render_content( $post_id, '<p>one</p>' );

		$this->assertStringNotContainsString( 'luna-ad', $out );
		$this->assertStringNotContainsString( 'm3-ad', $out );
		$this->assertStringNotContainsString( 'adsbygoogle', $out );
	}

	public function test_per_post_meta_exclusion_stops_output() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_luna_ads_disabled', '1' );

		$this->configure(
			array(
				'in_article' => array(
					'enabled'   => true,
					'code'      => '<ins class="adsbygoogle"></ins>',
					'paragraph' => 1,
				),
			)
		);

		$this->assertStringNotContainsString( 'luna-ad', $this->render_content( $post_id, '<p>one</p>' ) );
	}

	public function test_global_post_and_category_exclusions() {
		$post_id = self::factory()->post->create();
		$this->configure(
			array(
				'in_article' => array(
					'enabled'   => true,
					'code'      => '<ins class="adsbygoogle"></ins>',
					'paragraph' => 1,
				),
			),
			array(
				'exclude' => array(
					'post_ids' => (string) $post_id,
				),
			)
		);

		$this->assertStringNotContainsString( 'luna-ad', $this->render_content( $post_id, '<p>one</p>' ) );

		$category_id = self::factory()->category->create();
		wp_set_post_categories( $post_id, array( $category_id ) );
		$this->configure(
			array(
				'in_article' => array(
					'enabled'   => true,
					'code'      => '<ins class="adsbygoogle"></ins>',
					'paragraph' => 1,
				),
			),
			array(
				'exclude' => array(
					'category_ids' => (string) $category_id,
				),
			)
		);

		$this->assertStringNotContainsString( 'luna-ad', $this->render_content( $post_id, '<p>one</p>' ) );
	}

	public function test_logged_in_exclusion() {
		$post_id = self::factory()->post->create();
		$this->configure(
			array(
				'in_article' => array(
					'enabled'   => true,
					'code'      => '<ins class="adsbygoogle"></ins>',
					'paragraph' => 1,
				),
			),
			array(
				'exclude' => array(
					'logged_in' => true,
				),
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringNotContainsString( 'luna-ad', $this->render_content( $post_id, '<p>one</p>' ) );
	}

	public function test_same_slot_is_not_rendered_twice_in_one_request() {
		$this->configure(
			array(
				'sidebar' => array(
					'enabled' => true,
					'code'    => '<ins class="adsbygoogle" data-ad-slot="444"></ins>',
				),
			)
		);
		Luna_Ads_Renderer::reset_state();

		$first  = luna_ads_get( 'sidebar' );
		$second = luna_ads_get( 'sidebar' );

		$this->assertStringContainsString( 'data-luna-ad="sidebar"', $first );
		$this->assertSame( '', $second );
	}

	public function test_shortcode_and_invalid_slot() {
		$this->configure(
			array(
				'sidebar' => array(
					'enabled' => true,
					'code'    => '<ins class="adsbygoogle" data-ad-slot="555"></ins>',
				),
			)
		);
		Luna_Ads_Renderer::reset_state();

		$this->assertStringContainsString( 'data-luna-ad="sidebar"', do_shortcode( '[luna_ad slot="sidebar"]' ) );
		$this->assertSame( '', do_shortcode( '[luna_ad slot="does-not-exist"]' ) );
	}

	public function test_theme_managed_position_is_not_duplicated() {
		$post_id = self::factory()->post->create();
		update_option( 'node_ad_code_article', '<ins class="adsbygoogle"></ins>' );

		$this->configure(
			array(
				'article_bottom' => array(
					'enabled' => true,
					'code'    => '<ins class="adsbygoogle" data-ad-slot="666"></ins>',
				),
			)
		);

		$this->assertStringNotContainsString( 'data-luna-ad="article_bottom"', $this->render_content( $post_id, '<p>one</p>' ) );
	}

	public function test_shipped_theme_template_is_treated_as_empty() {
		$this->assertFalse( Luna_Ads_Renderer::theme_owns_slot( 'article_bottom' ) );
		$this->assertFalse( Luna_Ads_Renderer::theme_owns_slot( 'infeed' ) );
	}

	public function test_external_or_theme_source_outputs_no_plugin_ads() {
		$post_id = self::factory()->post->create();
		$this->configure(
			array(
				'in_article' => array(
					'enabled'   => true,
					'code'      => '<ins class="adsbygoogle"></ins>',
					'paragraph' => 1,
				),
			),
			array(
				'code_source' => 'external',
				'client_id'   => 'ca-pub-1234567890',
			)
		);

		$this->assertStringNotContainsString( 'luna-ad', $this->render_content( $post_id, '<p>one</p>' ) );

		ob_start();
		Luna_Ads_Renderer::maybe_output_adsense_loader();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_adsense_loader_is_printed_once_and_skipped_with_site_kit() {
		$this->configure(
			array(),
			array( 'client_id' => 'ca-pub-1234567890' )
		);
		Luna_Ads_Renderer::reset_state();

		ob_start();
		Luna_Ads_Renderer::maybe_output_adsense_loader();
		$first = (string) ob_get_clean();

		ob_start();
		Luna_Ads_Renderer::maybe_output_adsense_loader();
		$second = (string) ob_get_clean();

		$this->assertStringContainsString( 'adsbygoogle.js', $first );
		$this->assertStringContainsString( 'ca-pub-1234567890', $first );
		$this->assertSame( '', $second );

		add_filter( 'luna_ads_site_kit_active', '__return_true' );
		Luna_Ads_Renderer::reset_state();
		ob_start();
		Luna_Ads_Renderer::maybe_output_adsense_loader();
		$this->assertSame( '', (string) ob_get_clean() );
		remove_filter( 'luna_ads_site_kit_active', '__return_true' );
	}

	public function test_loader_is_not_added_when_ad_code_already_contains_it() {
		$this->configure(
			array(
				'in_article' => array(
					'enabled'   => true,
					'code'      => '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script>',
					'paragraph' => 1,
				),
			),
			array( 'client_id' => 'ca-pub-1234567890' )
		);

		ob_start();
		Luna_Ads_Renderer::maybe_output_adsense_loader();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_infeed_ad_is_emitted_between_archive_posts_only() {
		$post_ids = self::factory()->post->create_many( 4 );
		$this->configure(
			array(
				'infeed' => array(
					'enabled' => true,
					'code'    => '<ins class="adsbygoogle" data-ad-slot="777"></ins>',
					'every'   => 2,
				),
			)
		);

		$this->go_to( home_url( '/' ) );
		Luna_Ads_Renderer::reset_state();

		ob_start();
		do_action( 'the_post', get_post( $post_ids[0] ) );
		$first = (string) ob_get_clean();

		ob_start();
		do_action( 'the_post', get_post( $post_ids[1] ) );
		$second = (string) ob_get_clean();

		$this->assertSame( '', $first );
		$this->assertStringContainsString( 'data-luna-ad="infeed"', $second );

		$this->go_to( get_permalink( $post_ids[0] ) );
		Luna_Ads_Renderer::reset_state();
		ob_start();
		do_action( 'the_post', get_post( $post_ids[0] ) );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_pages_do_not_receive_in_article_ads_by_default() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->configure(
			array(
				'in_article' => array(
					'enabled'   => true,
					'code'      => '<ins class="adsbygoogle"></ins>',
					'paragraph' => 1,
				),
			)
		);

		$this->assertStringNotContainsString( 'luna-ad', $this->render_content( $page_id, '<p>one</p>' ) );
	}

	public function test_capability_requires_administrator() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( Luna_Ads_Settings::user_can_manage() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( Luna_Ads_Settings::user_can_manage() );
	}

	public function test_post_meta_requires_nonce_and_edit_capability() {
		$post_id = self::factory()->post->create();
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$original_post = $_POST;

		$_POST = array(
			'luna_ads_post_nonce'    => wp_create_nonce( 'luna_ads_save_post' ),
			'luna_ads_disable_post'  => '1',
		);
		Luna_Ads_Admin::save_post_meta( $post_id );
		$this->assertSame( '1', get_post_meta( $post_id, '_luna_ads_disabled', true ) );

		// nonce が無いリクエストでは既存値を変更しない。
		$_POST = array();
		Luna_Ads_Admin::save_post_meta( $post_id );
		$this->assertSame( '1', get_post_meta( $post_id, '_luna_ads_disabled', true ) );

		// 権限が無いユーザーは nonce があっても変更できない。
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST = array(
			'luna_ads_post_nonce' => wp_create_nonce( 'luna_ads_save_post' ),
		);
		Luna_Ads_Admin::save_post_meta( $post_id );
		$this->assertSame( '1', get_post_meta( $post_id, '_luna_ads_disabled', true ) );

		$_POST = $original_post;
	}
}
