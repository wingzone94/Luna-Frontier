<?php
require_once dirname( __DIR__ ) . '/inc/image-repair-admin.php';
/** @group ajax */
class Node_Image_Repair_Ajax_Test extends WP_Ajax_UnitTestCase {
	private function call_task( string $task, bool $nonce = true ): array {
		$_POST = array( 'task' => $task, 'nonce' => $nonce ? wp_create_nonce( 'node_image_repair' ) : 'invalid' );
		$_REQUEST = $_POST;
		$this->_last_response = '';
		try { $this->_handleAjax( 'node_image_repair' ); } catch ( WPAjaxDieContinueException $e ) {} catch ( WPAjaxDieStopException $e ) {}
		return json_decode( $this->_last_response, true ) ?: array();
	}
	public function tear_down(): void {
		delete_option( Node_Image_Repair::JOB ); delete_option( 'node_image_repair_worker' );
		$_POST = $_REQUEST = array(); parent::tear_down();
	}
	public function test_permissions_and_invalid_nonce_do_not_create_jobs(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $this->call_task( 'start' )['success'] );
		$this->assertFalse( get_option( Node_Image_Repair::JOB ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->call_task( 'start', false );
		$this->assertFalse( get_option( Node_Image_Repair::JOB ) );
	}
	public function test_read_only_scan_resume_and_repair_requires_completed_review(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create_many( 4, array( 'post_status' => 'publish', 'post_content' => '<p>記事本文</p>' ) );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft', 'post_content' => '非公開の本文' ) );
		$this->assertTrue( $this->call_task( 'start' )['success'] );
		$this->assertFalse( $this->call_task( 'repair' )['success'] );
		$first = $this->call_task( 'scan' );
		$this->assertSame( 3, $first['data']['job']['scanned'] );
		$second = $this->call_task( 'scan' );
		$this->assertSame( 'review', $second['data']['job']['phase'] );
		$this->assertSame( 4, $second['data']['job']['scanned'] );
		$this->assertSame( 'draft', get_post_status( $draft ) );
		$this->assertSame( '非公開の本文', get_post_field( 'post_content', $draft, 'raw' ) );
		$this->assertSame( 'repair_complete', $this->call_task( 'repair' )['data']['job']['phase'] );
	}
	public function test_existing_worker_lock_blocks_parallel_mutation(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_option( 'node_image_repair_worker', array( 'token' => 'other-worker' ), '', false );
		$this->assertFalse( $this->call_task( 'start' )['success'] );
		$this->assertFalse( get_option( Node_Image_Repair::JOB ) );
	}
}
