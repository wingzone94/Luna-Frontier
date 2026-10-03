<?php
require_once dirname( __DIR__ ) . '/inc/image-repair-admin.php';
/** @group ajax */
class Node_Image_Repair_Ajax_Test extends WP_Ajax_UnitTestCase {
	private function call_task( string $task, bool $nonce = true ): array {
		$_POST = array( 'task' => $task, 'nonce' => $nonce ? wp_create_nonce( Node_Image_Repair::ACTION ) : 'invalid' );
		$_POST['job_id'] = get_option( Node_Image_Repair::JOB )['id'] ?? '';
		$_REQUEST = $_POST;
		$this->_last_response = '';
		try { $this->_handleAjax( Node_Image_Repair::ACTION ); } catch ( WPAjaxDieContinueException $e ) {} catch ( WPAjaxDieStopException $e ) {}
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
		self::factory()->post->create_many( 4, array( 'post_status' => 'publish', 'post_content' => '<p>記事本文</p><img src="https://outside.example/image.png">' ) );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft', 'post_content' => '非公開の本文' ) );
		$before_status = get_post_status( $draft );
		$this->assertTrue( $this->call_task( 'start' )['success'] );
		$this->assertFalse( $this->call_task( 'repair' )['success'] );
		$first = $this->call_task( 'scan' );
		$this->assertSame( 3, $first['data']['job']['scanned'] );
		$second = $this->call_task( 'scan' );
		$this->assertSame( 'review', $second['data']['job']['phase'] );
		$this->assertSame( 4, $second['data']['job']['scanned'] );
		$this->assertSame( $before_status, get_post_status( $draft ) );
		$this->assertSame( '非公開の本文', get_post_field( 'post_content', $draft, 'raw' ) );
		for ( $i = 0; $i < 4; ++$i ) { $result = $this->call_task( 'repair' ); }
		$this->assertSame( 'repair_complete', $result['data']['job']['phase'] );
	}
	public function test_existing_worker_lock_blocks_parallel_mutation(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', Node_Image_Repair::lock_name() ) ) );
		try {
			$this->assertFalse( $this->call_task( 'start' )['success'] );
			$this->assertFalse( get_option( Node_Image_Repair::JOB ) );
		} finally { $other->close(); }
		// Simulates abnormal worker connection termination: no option deletion required.
		$this->assertTrue( $this->call_task( 'start' )['success'] );
	}
	public function test_checkpoint_failure_replays_frozen_post_without_duplicate_rows(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<img src="https://outside.example/one.png">' ) );
		$this->assertTrue( $this->call_task( 'start' )['success'] );
		$fail = static function ( $value, $old ) { return ! empty( $value['cursor'] ) ? $old : $value; };
		add_filter( 'pre_update_option_' . Node_Image_Repair::JOB, $fail, 10, 2 );
		try { $this->assertFalse( $this->call_task( 'scan' )['success'] ); }
		finally { remove_filter( 'pre_update_option_' . Node_Image_Repair::JOB, $fail, 10 ); }
		wp_update_post( array( 'ID' => $id, 'post_content' => '<img src="https://outside.example/changed.png"><img src="https://outside.example/two.png">' ) );
		$scan = $this->call_task( 'scan' );
		$this->assertTrue( $scan['success'] );
		$this->assertSame( 1, $scan['data']['job']['count'] );
		$this->assertSame( 'https://outside.example/one.png', $scan['data']['rows'][0]['url'] );
		$this->assertSame( 1, $scan['data']['job']['scanned'] );
	}

}
