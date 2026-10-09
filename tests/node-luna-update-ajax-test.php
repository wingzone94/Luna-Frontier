<?php
/** Node Settings must discover the independent Luna release. @group ajax */
require_once dirname( __DIR__ ) . '/inc/theme-setup.php';
require_once dirname( __DIR__ ) . '/inc/ajax.php';

class Node_Luna_Update_Ajax_Test extends WP_Ajax_UnitTestCase {
	private string $version = '2.0.0';
	private int $status = 200;
	private array $urls = array();

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'remote' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'remote' ), 10 );
		$_POST = $_REQUEST = array();
		parent::tear_down();
	}

	public function remote( $pre, $args, $url ) {
		if ( ! str_contains( $url, 'raw.githubusercontent.com/wingzone94/Luna-Frontier/' ) ) {
			return new WP_Error( 'test_external_request', 'Unrelated network requests are disabled in this test.' );
		}
		$this->urls[] = $url;
		$body = str_contains( $url, 'style.css' ) ? "/*\nTheme Name: Luna Frontier\nVersion: {$this->version}\n*/" : '{"version":"2.0.0","build_id":"luna-release"}';
		return array( 'response' => array( 'code' => $this->status ), 'body' => $body );
	}

	private function check_update( bool $nonce = true ): array {
		$_POST = $_REQUEST = array( 'nonce' => $nonce ? wp_create_nonce( 'luminous_update_nonce' ) : 'invalid' );
		$this->_last_response = '';
		try {
			$this->_handleAjax( 'luminous_check_update' );
		} catch ( WPAjaxDieContinueException | WPAjaxDieStopException $e ) {
		}
		return json_decode( $this->_last_response, true ) ?: array();
	}

	public function test_check_uses_luna_channel(): void {
		$result = $this->check_update();
		$this->assertTrue( $result['success'] );
		$this->assertSame( '2.0.0', $result['data']['remote_version'] );
		$this->assertSame( 'luna-release', $result['data']['remote_build'] );
		foreach ( $this->urls as $url ) {
			$this->assertStringContainsString( '/luna-frontier-2.0-skyalow/', $url );
		}
	}

	public function test_preview_is_not_offered_as_stable(): void {
		$this->version = '2.0.0-preview.6';
		$this->assertFalse( $this->check_update()['success'] );
	}

	public function test_http_error_is_not_reported_as_current(): void {
		$this->status = 404;
		$this->assertFalse( $this->check_update()['success'] );
	}

	public function test_permissions_and_nonce_block_remote_requests(): void {
		$this->check_update( false );
		$this->assertSame( array(), $this->urls );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $this->check_update()['success'] );
		$this->assertSame( array(), $this->urls );
	}
}
