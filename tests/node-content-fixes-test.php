<?php
/**
 * 暫定補正の既存出力を固定するテスト。
 *
 * @package Node
 */

class Node_Content_Fixes_Test extends WP_UnitTestCase {

	public function test_placeholder_title_strips_tags_and_space(): void {
		$this->assertTrue( node_is_auto_draft_placeholder_title( ' <b>自動下書き</b> ' ) );
	}

	public function test_placeholder_title_is_case_sensitive(): void {
		$this->assertFalse( node_is_auto_draft_placeholder_title( 'auto draft' ) );
	}

	public function test_brand_normalizes_trimmed_legacy_name(): void {
		$this->assertSame( 'Luminous Core', luminous_brand_normalize( ' Node ' ) );
	}

	public function test_brand_replaces_legacy_name_inside_text(): void {
		$this->assertSame( ' Luminous Core / Node ', luminous_brand_normalize( ' CyberNode / Node ' ) );
	}

	private function expected_contact_methods(): array {
		return array(
			'discord'       => 'Discord (URL)',
			'custom_link_1' => '追加リンク 1 (URL)',
			'custom_link_2' => '追加リンク 2 (URL)',
			'custom_link_3' => '追加リンク 3 (URL)',
			'custom_link_4' => '追加リンク 4 (URL)',
			'custom_link_5' => '追加リンク 5 (URL)',
		);
	}

	public function test_contact_methods_adds_six_fields(): void {
		$this->assertSame( $this->expected_contact_methods(), node_user_contact_methods( array() ) );
	}

	public function test_contact_methods_preserves_other_fields_and_overwrites_discord(): void {
		$this->assertSame(
			array_merge( array( 'website' => 'Website' ), $this->expected_contact_methods() ),
			node_user_contact_methods( array( 'website' => 'Website', 'discord' => 'Old label' ) )
		);
	}

	public function test_encoded_slug_uses_post_id(): void {
		$this->assertSame( 'post-42', luminous_core_auto_post_slug( '%e6%97%a5', 42, 'publish', 'post' ) );
	}

	public function test_draft_status_and_placeholder_title_are_normalized(): void {
		$this->assertSame(
			array( 'post_type' => 'post', 'post_title' => '', 'post_status' => 'pending' ),
			node_force_default_post_status_on_save(
				array( 'post_type' => 'post', 'post_title' => 'Auto Draft', 'post_status' => 'draft' ),
				array()
			)
		);
	}

	public function test_about_page_replaces_existing_server_and_social_text(): void {
		$page_id = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_name' => 'about', 'post_status' => 'publish' )
		);
		$original_query = $GLOBALS['wp_query'];
		$GLOBALS['wp_query'] = new WP_Query();
		$GLOBALS['wp_query']->is_page = true;
		$GLOBALS['wp_query']->queried_object = get_post( $page_id );
		$GLOBALS['wp_query']->queried_object_id = $page_id;
		try {
			$this->assertSame(
				'<p>Server: Conoha Wing</p><p>公式SNS：X</p>',
				node_normalize_about_page_content( '<p>Server：Conoha Wing</p><p>公式SNS：X / Threads</p>' )
			);
		} finally {
			$GLOBALS['wp_query'] = $original_query;
		}
	}
}
