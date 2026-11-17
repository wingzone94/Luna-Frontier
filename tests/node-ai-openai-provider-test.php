<?php
/** Generic compatible endpoints and legacy credential migration. @package Node_AI_Tools */
class Node_AI_OpenAI_Provider_Test extends WP_UnitTestCase {
	private $capture;
	private $mock;

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'node_ai_openai_preset', 'node_ai_openai_endpoint', 'node_ai_openai_model', 'node_ai_openai_api_key', 'node_ai_openai_migration', 'node_ai_provider', 'node_ai_qwen_endpoint', 'node_ai_qwen_model', 'node_ai_qwen_api_key' ) as $option ) {
			delete_option( $option );
		}
		$this->mock = function ( $pre, $args, $url ) {
			$this->capture = array( 'args' => $args, 'url' => $url );
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => '{"ok":true}' ) ) ), 'usage' => array( 'total_tokens' => 12 ), 'message' => array( 'content' => '{"ok":true}' ), 'eval_count' => 12, 'data' => array() ) ) );
		};
		add_filter( 'pre_http_request', $this->mock, 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->mock, 10 );
		parent::tear_down();
	}

	public function test_local_compatible_endpoint_works_without_any_api_key(): void {
		update_option( 'node_ai_provider', 'openai' );
		$result = node_ai_core()->generate( 'test', 'テスト', array( 'json' => true, 'return_metadata' => true ) );
		$this->assertSame( '{"ok":true}', $result['text'] );
		$this->assertSame( 12, $result['tokens'] );
		$this->assertSame( array(), $result['grounding'] );
		$this->assertSame( 'http://127.0.0.1:11434/v1/chat/completions', $this->capture['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->capture['args']['headers'] );
		$body = json_decode( $this->capture['args']['body'], true );
		$this->assertSame( 'gemma3:4b', $body['model'] );
		$this->assertArrayNotHasKey( 'reasoning_effort', $body );
	}

	public function test_endpoint_model_and_key_are_independently_configurable(): void {
		update_option( 'node_ai_openai_preset', 'custom' );
		update_option( 'node_ai_openai_endpoint', 'https://example.com/custom/v1/' );
		update_option( 'node_ai_openai_model', 'custom-model' );
		update_option( 'node_ai_openai_api_key', 'configured-key' );
		$provider = new Node_AI_Provider_OpenAI();
		$this->assertSame( '{"ok":true}', $provider->generate( 'test' ) );
		$this->assertSame( 'https://example.com/custom/v1/chat/completions', $this->capture['url'] );
		$this->assertSame( 'Bearer configured-key', $this->capture['args']['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'reasoning_effort', json_decode( $this->capture['args']['body'], true ) );
		$provider->set_model_override( 'feature-model' );
		$this->assertSame( 'feature-model', $provider->get_model() );
		$this->assertTrue( $provider->test_connection() );
	}

	public function test_cloud_presets_require_keys_and_openai_requires_explicit_model(): void {
		update_option( 'node_ai_openai_preset', 'openai' );
		$provider = new Node_AI_Provider_OpenAI();
		$this->assertSame( 'ai_missing_credentials', $provider->generate( 'test' )->get_error_code() );
		update_option( 'node_ai_openai_api_key', 'key' );
		$this->assertSame( 'ai_missing_model', $provider->generate( 'test' )->get_error_code() );
	}

	public function test_migration_keeps_legacy_tuple_and_does_not_delete_originals(): void {
		update_option( 'node_ai_provider', 'qwen' );
		update_option( 'node_ai_qwen_endpoint', 'https://legacy.example/v1' );
		update_option( 'node_ai_qwen_model', 'legacy-model' );
		update_option( 'node_ai_qwen_api_key', 'legacy-key' );
		Node_AI_Core::migrate_provider_settings();
		$this->assertSame( 'openai', get_option( 'node_ai_provider' ) );
		$this->assertSame( 'dashscope', get_option( 'node_ai_openai_preset' ) );
		foreach ( array( 'endpoint', 'model', 'api_key' ) as $field ) {
			$this->assertSame( get_option( 'node_ai_qwen_' . $field ), get_option( 'node_ai_openai_' . $field ) );
		}
		$this->assertSame( 'legacy-model', node_ai_core()->get_provider()->get_model() );
		$this->assertSame( 'legacy-model', ( new Node_AI_Provider_Qwen() )->get_model() );
		Node_AI_Core::migrate_provider_settings();
		$this->assertSame( 'legacy-key', get_option( 'node_ai_openai_api_key' ) );
	}

	public function test_migration_does_not_mix_an_old_key_with_a_new_endpoint(): void {
		update_option( 'node_ai_provider', 'qwen' );
		update_option( 'node_ai_qwen_api_key', 'private-old-key' );
		update_option( 'node_ai_openai_endpoint', 'http://127.0.0.1:1234/v1' );
		Node_AI_Core::migrate_provider_settings();
		$this->assertSame( 'http://127.0.0.1:1234/v1', get_option( 'node_ai_openai_endpoint' ) );
		$this->assertFalse( get_option( 'node_ai_openai_api_key' ) );
		$this->assertSame( 'private-old-key', get_option( 'node_ai_qwen_api_key' ) );
	}

	public function test_unsafe_url_schemes_and_embedded_credentials_are_rejected(): void {
		foreach ( array( 'file:///tmp/data', 'https://user:password@example.com/v1' ) as $url ) {
			update_option( 'node_ai_openai_endpoint', $url );
			$this->assertSame( 'ai_invalid_endpoint', ( new Node_AI_Provider_OpenAI() )->generate( 'test' )->get_error_code() );
		}
	}
	public function test_explicit_legacy_selection_after_migration_remains_supported(): void {
		Node_AI_Core::migrate_provider_settings();
		update_option( 'node_ai_provider', 'qwen' );
		update_option( 'node_ai_qwen_model', 'legacy-programmatic-model' );
		Node_AI_Core::migrate_provider_settings();
		$this->assertSame( 'qwen', node_ai_core()->get_provider_id() );
		$this->assertSame( 'legacy-programmatic-model', node_ai_core()->get_provider()->get_model() );
	}

	public function test_ollama_uses_the_vision_capable_default_without_model_specific_prompt_injection(): void {
		delete_option( 'node_ai_ollama_model' );
		$this->assertSame( '{"ok":true}', ( new Node_AI_Provider_Ollama() )->generate( 'JSONで返してください。', array( 'json' => true ) ) );
		$body = json_decode( $this->capture['args']['body'], true );
		$this->assertSame( 'gemma3:4b', $body['model'] );
		$this->assertArrayNotHasKey( 'think', $body );
		$this->assertSame( 'user', end( $body['messages'] )['role'] );
	}
}
