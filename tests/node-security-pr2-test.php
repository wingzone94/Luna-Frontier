<?php
/**
 * PR-2 セキュリティ強化テスト（C-1, H-1, H-2, H-3, M-3）。
 *
 * @package Luna_Frontier
 */

class Node_Security_PR2_Test extends WP_UnitTestCase {

	public function test_native_app_registers_wpsc_cookie_exclusion(): void {
		$captured = [];
		$cb       = static function ( $cookie ) use ( &$captured ): void {
			$captured[] = $cookie;
		};
		add_action( 'wpsc_add_cookie', $cb, 10, 1 );
		node_native_app_prevent_page_cache();
		remove_action( 'wpsc_add_cookie', $cb, 10 );

		$this->assertContains( NODE_NATIVE_APP_COOKIE, $captured, 'WP Super Cache へ native-app Cookie 除外が登録されること' );
	}

	public function test_seo_json_ld_escapes_script_tag_and_omits_empty_fields(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Test </script><script>alert(1)</script> & Title',
				'post_content' => '',
				'post_excerpt' => '',
				'post_status'  => 'publish',
			]
		);
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		node_seo_json_ld();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( '</script><script>', $output, 'JSON_HEX_TAG により </script> がエスケープされること' );
		$this->assertStringContainsString( '\u003C/script\u003E', $output );

		$this->assertSame( 1, preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $output, $m ) );
		$decoded = json_decode( $m[1], true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'Article', $decoded['@type'] );
		$this->assertArrayNotHasKey( 'description', $decoded, '空の description は出力しない' );
		$this->assertArrayNotHasKey( 'image', $decoded, 'アイキャッチ・サイトアイコン未設定時は image を出力しない' );
	}

	public function test_seo_json_ld_skips_article_on_node_library(): void {
		if ( ! post_type_exists( 'node_library' ) ) {
			$this->markTestSkipped( 'node_library post type is not registered' );
		}

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'node_library',
				'post_title'  => 'Sample Library Item',
				'post_status' => 'publish',
			]
		);
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		node_seo_json_ld();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( '"@type":"Article"', $output, 'node_library 詳細ページで Article JSON-LD を二重出力しないこと' );
	}

	public function test_node_connect_rejects_non_discord_webhook_urls(): void {
		$this->assertTrue( Node_Connect_Webhook_Sender::is_allowed_webhook_url( 'https://discord.com/api/webhooks/123456/token' ) );
		$this->assertTrue( Node_Connect_Webhook_Sender::is_allowed_webhook_url( 'https://discordapp.com/api/webhooks/123456/token' ) );
		$this->assertFalse( Node_Connect_Webhook_Sender::is_allowed_webhook_url( 'https://example.com/api/webhooks/123456/token' ) );
		$this->assertFalse( Node_Connect_Webhook_Sender::is_allowed_webhook_url( 'http://discord.com/api/webhooks/123456/token' ) );
		$this->assertFalse( Node_Connect_Webhook_Sender::is_allowed_webhook_url( 'https://169.254.169.254/latest/meta-data/' ) );
		$this->assertFalse( Node_Connect_Webhook_Sender::is_allowed_webhook_url( 'https://discord.com:8443/api/webhooks/123/token' ) );

		$sanitized = node_connect_sanitize_webhooks(
			[
				[
					'label'   => 'Bad',
					'url'     => 'https://evil.example.com/hook',
					'events'  => [ 'post_published' ],
					'enabled' => true,
				],
			]
		);
		$this->assertSame( [], $sanitized, 'Discord 以外の Webhook URL は保存時に拒否されること' );
	}

	public function test_luminous_nexus_blogcard_blocks_unpublished_internal_post(): void {
		$draft_id = self::factory()->post->create(
			[
				'post_title'  => 'Secret Draft Post',
				'post_status' => 'draft',
			]
		);
		$url = home_url( '/?p=' . $draft_id );
		delete_transient( 'luminous_ogp_' . md5( $url ) );

		$ogp = luminous_nexus_get_ogp_data( $url );
		$this->assertFalse( $ogp, '下書きの内部記事は OGP 取得対象外であること' );
	}
}
