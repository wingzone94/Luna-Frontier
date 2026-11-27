<?php
/**
 * PR-8 権限・露出是正テスト（M-1, M-2, L-1）。
 *
 * @package Luna_Frontier
 */

class Node_Permissions_PR8_Test extends WP_UnitTestCase {

	public function test_render_block_rejects_draft_and_password_protected_library_items(): void {
		$draft_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'draft',
				'post_title'  => 'Secret Draft Game',
			)
		);
		$pw_id = self::factory()->post->create(
			array(
				'post_type'     => 'node_library',
				'post_status'   => 'publish',
				'post_password' => 'secret123',
				'post_title'    => 'Protected Game',
			)
		);
		$lib = Node_Library::instance();
		$this->assertSame( '', $lib->render_block( array( 'libraryId' => $draft_id ) ) );
		$this->assertSame( '', $lib->render_block( array( 'libraryId' => $pw_id ) ) );
	}

	public function test_sort_hidden_fields_filters_unknown_get_keys(): void {
		$_GET = array(
			'm3_platform' => array( 'pc' ),
			'evil_key'    => '"><script>alert(1)</script>',
			'paged'       => '2',
		);
		ob_start();
		luna_frontier_sort_hidden_fields( array( 'paged', 'sort' ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="m3_platform[0]" value="pc"', $html );
		$this->assertStringNotContainsString( 'evil_key', $html, '未許可の GET キーは hidden input へ反射されないこと' );
	}
}
