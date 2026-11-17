<?php
/** Vision input contract, original image preservation and asynchronous alt generation. */
class Node_AI_Image_Provider_Test extends WP_UnitTestCase {
	private $path;
	private $requests = array();
	private $mock;
	private $on_request;
	private $error;

	public function set_up(): void {
		parent::set_up();
		$this->path = wp_tempnam( 'node-image-test' );
		$image = imagecreatetruecolor( 1024, 512 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 230, 20, 20 ) );
		imagejpeg( $image, $this->path, 85 );
		imagedestroy( $image );
		update_option( 'node_ai_provider', 'ollama' );
		update_option( 'node_ai_auto_alt', '1' );
		delete_option( 'node_ai_gemini_api_key' );
		update_option( 'node_ai_openai_preset', 'local' );
		delete_option( 'node_ai_openai_endpoint' );
		delete_option( 'node_ai_openai_api_key' );
		$this->mock = function ( $pre, $args, $url ) {
			$this->requests[] = array( 'url' => $url, 'args' => $args, 'body' => json_decode( $args['body'] ?? '{}', true ) );
			if ( $this->on_request ) {
				call_user_func( $this->on_request );
			}
			if ( $this->error ) { return $this->error; }
			$text = '{"alt_suggestion":"赤い長方形。"}';
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'message' => array( 'content' => $text ), 'choices' => array( array( 'message' => array( 'content' => $text ) ) ) ) ) );
		};
		add_filter( 'pre_http_request', $this->mock, 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->mock, 10 );
		wp_delete_file( $this->path );
		parent::tear_down();
	}

	public function test_ollama_receives_resized_image_without_modifying_original_or_logging_pixels(): void {
		$hash = hash_file( 'sha256', $this->path );
		$before = glob( get_temp_dir() . 'node-ai-alt*' );
		$this->assertSame( array( 'alt_suggestion' => '赤い長方形。' ), node_ai_core()->analyze_image( $this->path ) );
		$request = $this->requests[0];
		$this->assertStringContainsString( '/api/chat', $request['url'] );
		$image = base64_decode( $request['body']['messages'][1]['images'][0], true );
		$size = getimagesizefromstring( $image );
		$this->assertSame( 768, $size[0] );
		$this->assertSame( 384, $size[1] );
		$this->assertSame( $hash, hash_file( 'sha256', $this->path ) );
		$this->assertSame( $before, glob( get_temp_dir() . 'node-ai-alt*' ) );
		$log = node_ai_core()->get_usage_log();
		$this->assertSame( 'alt_text', $log[0]['feature'] );
		$this->assertArrayNotHasKey( 'image', $log[0] );
		$this->assertArrayNotHasKey( 'prompt', $log[0] );
	}

	public function test_compatible_api_receives_data_url_with_no_required_gemini_or_local_key(): void {
		update_option( 'node_ai_provider', 'openai' );
		$this->assertIsArray( node_ai_core()->analyze_image( $this->path ) );
		$request = $this->requests[0];
		$this->assertStringContainsString( '/chat/completions', $request['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
		$content = $request['body']['messages'][1]['content'];
		$this->assertSame( 'text', $content[0]['type'] );
		$this->assertSame( 'image_url', $content[1]['type'] );
		$this->assertStringStartsWith( 'data:image/jpeg;base64,', $content[1]['image_url']['url'] );
	}

	public function test_auto_alt_uses_provider_and_preserves_a_human_edit_made_while_waiting(): void {
		$id = $this->factory->attachment->create_object( array( 'file' => $this->path, 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $id, '_wp_attached_file', $this->path );
		update_post_meta( $id, '_node_ai_alt_error', 'old error' );
		$this->on_request = static function () use ( $id ) { update_post_meta( $id, '_wp_attachment_image_alt', '人が入力した説明。' ); };
		node_ai_run_auto_alt( $id );
		$this->assertSame( '人が入力した説明。', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( '', get_post_meta( $id, '_node_ai_alt_error', true ) );
		$this->assertCount( 1, $this->requests );
		node_ai_run_auto_alt( $id );
		$this->assertCount( 1, $this->requests );
	}

	public function test_disabled_ai_and_non_images_do_not_send_any_request(): void {
		update_option( 'node_ai_provider', 'off' );
		$this->assertWPError( node_ai_core()->analyze_image( $this->path ) );
		update_option( 'node_ai_provider', 'ollama' );
		$this->assertWPError( node_ai_core()->analyze_image( 'file://' . $this->path ) );
		$this->assertWPError( node_ai_core()->analyze_image( '/no/such/image.png' ) );
		file_put_contents( $this->path, '<svg xmlns="http://www.w3.org/2000/svg"></svg>' );
		$this->assertWPError( node_ai_core()->analyze_image( $this->path ) );
		file_put_contents( $this->path, str_repeat( 'x', 10 * MB_IN_BYTES + 1 ) );
		$this->assertSame( 'image_too_large', node_ai_core()->analyze_image( $this->path )->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_legacy_gemini_image_entry_point_delegates_to_selected_local_provider(): void {
		$this->assertIsArray( ( new Node_Gemini_API() )->analyze_image( $this->path ) );
		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( '/api/chat', $this->requests[0]['url'] );
	}
	public function test_auto_alt_saves_compatible_result_and_clears_old_failure(): void {
		update_option( 'node_ai_provider', 'openai' );
		$id = $this->factory->attachment->create_object( array( 'file' => $this->path, 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $id, '_wp_attached_file', $this->path );
		update_post_meta( $id, '_node_ai_alt_error', 'old error' );
		node_ai_run_auto_alt( $id );
		$this->assertSame( '赤い長方形。', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( '', get_post_meta( $id, '_node_ai_alt_error', true ) );
		$this->assertSame( $id, node_ai_core()->get_usage_log()[0]['post_id'] );
	}

	public function test_failed_inference_cleans_resized_file_and_never_falls_back_to_gemini(): void {
		$this->error = new WP_Error( 'ai_error', 'This model does not support images.' );
		$before = glob( get_temp_dir() . 'node-ai-alt*' );
		$id = $this->factory->attachment->create_object( array( 'file' => $this->path, 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $id, '_wp_attached_file', $this->path );
		node_ai_run_auto_alt( $id );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( '', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertStringContainsString( 'does not support images', get_post_meta( $id, '_node_ai_alt_error', true ) );
		$this->assertSame( $before, glob( get_temp_dir() . 'node-ai-alt*' ) );
	}

}
