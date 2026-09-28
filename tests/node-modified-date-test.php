<?php
/**
 * 手動更新日の既存出力を固定するテスト。
 *
 * @package Node
 */

class Node_Modified_Date_Test extends WP_UnitTestCase {

	public function test_sanitize_preserves_valid_leap_day(): void {
		$this->assertSame( '2024-02-29', node_sanitize_manual_modified_date( '2024-02-29' ) );
	}

	public function test_sanitize_rejects_invalid_leap_day(): void {
		$this->assertSame( '', node_sanitize_manual_modified_date( '2025-02-29' ) );
	}

	public function test_auto_record_returns_null_for_non_update(): void {
		$post = new WP_Post( (object) array( 'ID' => 0 ) );
		$this->assertNull( node_maybe_auto_record_manual_modified_date( 0, $post, false ) );
	}

	public function test_auto_record_returns_null_for_invalid_post(): void {
		$this->assertNull( node_maybe_auto_record_manual_modified_date( 0, null, true ) );
	}
}
