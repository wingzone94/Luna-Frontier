<?php
/**
 * Node AI Core / Provider Adapter の自動テスト（第3段階）
 *
 * @package Node_AI_Tools
 */

class Node_AI_Core_Test extends WP_UnitTestCase {

	private $user_id;

	private $captured = null;

	public function set_up() {
		parent::set_up();

		$base = dirname( __DIR__ ) . '/plugins-embedded/node-ai-tools/';
		if ( ! class_exists( 'Node_Gemini_API' ) ) {
			require_once $base . 'includes/class-gemini-api.php';
		}
		if ( ! interface_exists( 'Node_AI_Provider' ) ) {
			require_once $base . 'includes/providers/interface-node-ai-provider.php';
			require_once $base . 'includes/providers/class-provider-gemini.php';
			require_once $base . 'includes/providers/class-provider-qwen.php';
			require_once $base . 'includes/providers/class-provider-ollama.php';
			require_once $base . 'includes/class-ai-core.php';
		}
		if ( ! function_exists( 'node_ai_sanitize_secret' ) ) {
			require_once $base . 'admin/settings-page-ai.php';
		}
		require_once $base . 'includes/ajax-handlers.php';
		require_once $base . 'includes/fact-check-render.php';
		require_once $base . 'includes/auto-check.php';

		$this->user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		foreach ( array( 'node_ai_provider', 'node_ai_gemini_api_key', 'node_ai_gemini_model', 'node_ai_qwen_endpoint', 'node_ai_qwen_api_key', 'node_ai_qwen_model', 'node_ai_ollama_url', 'node_ai_ollama_model', Node_AI_Core::USAGE_LOG_OPTION ) as $option ) {
			delete_option( $option );
		}
		$this->captured = null;
	}

	// ------------------------------------------------------------------
	// プロバイダー解決
	// ------------------------------------------------------------------

	public function test_default_provider_is_gemini() {
		$this->assertSame( 'gemini', node_ai_core()->get_provider_id() );
		$this->assertInstanceOf( 'Node_AI_Provider_Gemini', node_ai_core()->get_provider() );
		$this->assertTrue( node_ai_core()->is_enabled() );
	}

	public function test_provider_resolution_from_option() {
		update_option( 'node_ai_provider', 'qwen' );
		$this->assertInstanceOf( 'Node_AI_Provider_Qwen', node_ai_core()->get_provider() );

		update_option( 'node_ai_provider', 'ollama' );
		$this->assertInstanceOf( 'Node_AI_Provider_Ollama', node_ai_core()->get_provider() );

		update_option( 'node_ai_provider', 'invalid-provider' );
		$this->assertSame( 'gemini', node_ai_core()->get_provider_id() );
	}

	public function test_provider_off_disables_generation() {
		update_option( 'node_ai_provider', 'off' );
		$this->assertFalse( node_ai_core()->is_enabled() );

		$provider = node_ai_core()->get_provider();
		$this->assertWPError( $provider );
		$this->assertSame( 'ai_disabled', $provider->get_error_code() );

		$result = node_ai_core()->summarize( 'テスト本文' );
		$this->assertWPError( $result );
		$this->assertSame( 'ai_disabled', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Qwen（OpenAI Compatible）
	// ------------------------------------------------------------------

	public function test_qwen_request_shape_openai_compatible() {
		update_option( 'node_ai_qwen_api_key', 'sk-test-123' );
		update_option( 'node_ai_qwen_model', 'qwen-max' );

		add_filter( 'pre_http_request', array( $this, 'mock_capture_openai_success' ), 10, 3 );
		$provider = new Node_AI_Provider_Qwen();
		$result   = $provider->generate(
			'こんにちは',
			array(
				'system_instruction' => 'あなたはテストです。',
				'json'               => true,
				'max_tokens'         => 500,
			)
		);
		remove_filter( 'pre_http_request', array( $this, 'mock_capture_openai_success' ), 10 );

		$this->assertSame( 'mocked response', $result );
		$this->assertStringContainsString( '/chat/completions', $this->captured['url'] );
		$this->assertStringContainsString( 'dashscope', $this->captured['url'] ); // 既定エンドポイント
		$this->assertSame( 'Bearer sk-test-123', $this->captured['headers']['Authorization'] );

		$body = json_decode( $this->captured['body'], true );
		$this->assertSame( 'qwen-max', $body['model'] );
		$this->assertSame( 'system', $body['messages'][0]['role'] );
		$this->assertSame( 'user', $body['messages'][1]['role'] );
		$this->assertSame( 'こんにちは', $body['messages'][1]['content'] );
		$this->assertSame( array( 'type' => 'json_object' ), $body['response_format'] );
		$this->assertSame( 500, $body['max_tokens'] );
	}

	public function test_qwen_custom_endpoint_is_used() {
		update_option( 'node_ai_qwen_api_key', 'sk-test-123' );
		update_option( 'node_ai_qwen_endpoint', 'https://example.com/v1/' );

		add_filter( 'pre_http_request', array( $this, 'mock_capture_openai_success' ), 10, 3 );
		( new Node_AI_Provider_Qwen() )->generate( 'x' );
		remove_filter( 'pre_http_request', array( $this, 'mock_capture_openai_success' ), 10 );

		$this->assertSame( 'https://example.com/v1/chat/completions', $this->captured['url'] );
	}

	public function test_qwen_missing_key_returns_credentials_error() {
		$result = ( new Node_AI_Provider_Qwen() )->generate( 'x' );
		$this->assertWPError( $result );
		$this->assertSame( 'ai_missing_credentials', $result->get_error_code() );
	}

	public function test_qwen_http_errors_are_classified() {
		update_option( 'node_ai_qwen_api_key', 'sk-test-123' );

		foreach ( array( 401 => 'ai_missing_credentials', 429 => 'ai_quota', 503 => 'ai_unavailable' ) as $status => $expected_code ) {
			$this->mock_status = $status;
			add_filter( 'pre_http_request', array( $this, 'mock_openai_error' ), 10, 3 );
			$result = ( new Node_AI_Provider_Qwen() )->generate( 'x' );
			remove_filter( 'pre_http_request', array( $this, 'mock_openai_error' ), 10 );

			$this->assertWPError( $result );
			$this->assertSame( $expected_code, $result->get_error_code(), "HTTP {$status}" );
		}
	}

	// ------------------------------------------------------------------
	// Ollama
	// ------------------------------------------------------------------

	public function test_ollama_request_shape() {
		update_option( 'node_ai_ollama_model', 'qwen3' );

		add_filter( 'pre_http_request', array( $this, 'mock_capture_ollama_success' ), 10, 3 );
		$result = ( new Node_AI_Provider_Ollama() )->generate(
			'テスト',
			array(
				'system_instruction' => 'sys',
				'json'               => true,
			)
		);
		remove_filter( 'pre_http_request', array( $this, 'mock_capture_ollama_success' ), 10 );

		$this->assertSame( 'ollama response', $result );
		$this->assertSame( 'http://localhost:11434/api/chat', $this->captured['url'] );

		$body = json_decode( $this->captured['body'], true );
		$this->assertSame( 'qwen3', $body['model'] );
		$this->assertFalse( $body['stream'] );
		$this->assertSame( 'json', $body['format'] );
		$this->assertSame( 'sys', $body['messages'][0]['content'] );
	}

	public function test_ollama_connection_failure_is_unavailable() {
		add_filter( 'pre_http_request', array( $this, 'mock_connection_refused' ), 10, 3 );
		$result = ( new Node_AI_Provider_Ollama() )->generate( 'x' );
		remove_filter( 'pre_http_request', array( $this, 'mock_connection_refused' ), 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'ai_unavailable', $result->get_error_code() );
	}

	public function test_ollama_test_connection_checks_model_presence() {
		update_option( 'node_ai_ollama_model', 'gemma3' );

		add_filter( 'pre_http_request', array( $this, 'mock_ollama_tags' ), 10, 3 );
		$ok = ( new Node_AI_Provider_Ollama() )->test_connection();
		$this->assertTrue( $ok );

		update_option( 'node_ai_ollama_model', 'not-installed-model' );
		$missing = ( new Node_AI_Provider_Ollama() )->test_connection();
		remove_filter( 'pre_http_request', array( $this, 'mock_ollama_tags' ), 10 );

		$this->assertWPError( $missing );
	}

	// ------------------------------------------------------------------
	// Gemini アダプター・サイト共通キー
	// ------------------------------------------------------------------

	public function test_gemini_adapter_json_option_sets_mime_type() {
		update_user_meta( $this->user_id, 'node_gemini_api_key', 'user-key' );

		add_filter( 'pre_http_request', array( $this, 'mock_capture_gemini_success' ), 10, 3 );
		$result = ( new Node_AI_Provider_Gemini( $this->user_id ) )->generate( 'x', array( 'json' => true ) );
		remove_filter( 'pre_http_request', array( $this, 'mock_capture_gemini_success' ), 10 );

		$this->assertSame( 'gemini response', $result );
		$body = json_decode( $this->captured['body'], true );
		$this->assertSame( 'application/json', $body['generationConfig']['responseMimeType'] );
	}

	public function test_gemini_site_wide_key_fallback() {
		// 個人キー無し + サイト共通キーあり → サイト共通キーで実行できる
		update_option( 'node_ai_gemini_api_key', 'site-wide-key' );

		add_filter( 'pre_http_request', array( $this, 'mock_capture_gemini_success' ), 10, 3 );
		$result = ( new Node_AI_Provider_Gemini( $this->user_id ) )->generate( 'x' );
		remove_filter( 'pre_http_request', array( $this, 'mock_capture_gemini_success' ), 10 );

		$this->assertSame( 'gemini response', $result );
		$this->assertStringContainsString( 'key=site-wide-key', $this->captured['url'] );

		// 公開ゲート/自動実行のキー判定もサイト共通キーを認識する
		$this->assertTrue( node_ai_author_has_api_key( $this->user_id ) );
	}

	public function test_site_default_gemini_model_option() {
		update_option( 'node_ai_gemini_model', 'gemini-3.5-flash' );
		$this->assertSame( 'gemini-3.5-flash', node_get_default_gemini_model() );

		update_option( 'node_ai_gemini_model', 'invalid model id!' );
		$this->assertNotSame( 'invalid model id!', node_get_default_gemini_model() );
	}

	// ------------------------------------------------------------------
	// Core: 機能API・利用履歴・エラー正規化
	// ------------------------------------------------------------------

	public function test_core_summarize_records_usage() {
		update_option( 'node_ai_provider', 'qwen' );
		update_option( 'node_ai_qwen_api_key', 'sk-test-123' );

		add_filter( 'pre_http_request', array( $this, 'mock_capture_openai_success' ), 10, 3 );
		$result = node_ai_core()->summarize( '本文テスト', array(), $this->user_id, 123 );
		remove_filter( 'pre_http_request', array( $this, 'mock_capture_openai_success' ), 10 );

		$this->assertSame( 'mocked response', $result );

		$log = node_ai_core()->get_usage_log();
		$this->assertCount( 1, $log );
		$this->assertSame( 'summarize', $log[0]['feature'] );
		$this->assertSame( 'Qwen', $log[0]['provider'] );
		$this->assertSame( 123, $log[0]['post_id'] );
		$this->assertTrue( $log[0]['ok'] );
		$this->assertSame( 1, node_ai_core()->get_monthly_usage_count() );
	}

	public function test_core_normalizes_gemini_quota_error_and_logs_failure() {
		update_user_meta( $this->user_id, 'node_gemini_api_key', 'user-key' );

		$this->mock_status = 429;
		add_filter( 'pre_http_request', array( $this, 'mock_gemini_error' ), 10, 3 );
		$result = node_ai_core()->summarize( '本文', array(), $this->user_id );
		remove_filter( 'pre_http_request', array( $this, 'mock_gemini_error' ), 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'ai_quota', $result->get_error_code() );
		$this->assertSame( 'gemini_quota_exceeded', $result->get_error_data()['original_code'] );

		$log = node_ai_core()->get_usage_log();
		$this->assertFalse( $log[0]['ok'] );
		$this->assertSame( 'ai_quota', $log[0]['error_code'] );
	}

	public function test_usage_log_capped_at_limit() {
		$entries = array_fill( 0, Node_AI_Core::USAGE_LOG_LIMIT, array( 'time' => '2026-01-01 00:00:00', 'feature' => 'old' ) );
		update_option( Node_AI_Core::USAGE_LOG_OPTION, $entries );

		node_ai_core()->record_usage( 'newest', new Node_AI_Provider_Qwen(), 0, true );

		$log = node_ai_core()->get_usage_log();
		$this->assertCount( Node_AI_Core::USAGE_LOG_LIMIT, $log );
		$this->assertSame( 'newest', $log[0]['feature'] );
	}

	public function test_core_fact_check_via_openai_compatible_provider() {
		update_option( 'node_ai_provider', 'qwen' );
		update_option( 'node_ai_qwen_api_key', 'sk-test-123' );

		$this->openai_response_text = '{"summary":"s","overall_risk":"low","claims":[{"claim":"c","status":"uncertain","confidence":"low","note":"n"}]}';
		add_filter( 'pre_http_request', array( $this, 'mock_capture_openai_success' ), 10, 3 );
		$result = node_ai_core()->fact_check( '本文', 'タイトル', $this->user_id, 55 );
		remove_filter( 'pre_http_request', array( $this, 'mock_capture_openai_success' ), 10 );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['grounding'] );

		// Google Search 前提の文言はプロンプトに含まれない
		$body = json_decode( $this->captured['body'], true );
		$this->assertStringNotContainsString( 'Google Search', $body['messages'][0]['content'] );

		// 既存の保存関数にそのまま渡せる
		$post_id = $this->factory->post->create();
		$payload = node_ai_store_fact_check_result( $post_id, $result );
		$this->assertIsArray( $payload );
		$this->assertFalse( $payload['grounded'] );
	}

	public function test_core_fact_check_via_gemini_uses_grounding_wording() {
		update_user_meta( $this->user_id, 'node_gemini_api_key', 'user-key' );

		$this->gemini_response_text = '{"summary":"s","overall_risk":"low","claims":[{"claim":"c"}]}';
		add_filter( 'pre_http_request', array( $this, 'mock_capture_gemini_success' ), 10, 3 );
		$result = node_ai_core()->fact_check( '本文', 'タイトル', $this->user_id );
		remove_filter( 'pre_http_request', array( $this, 'mock_capture_gemini_success' ), 10 );

		$this->assertIsArray( $result );
		$body = json_decode( $this->captured['body'], true );
		$this->assertStringContainsString( 'Google Search', $body['system_instruction']['parts'][0]['text'] );
		$this->assertArrayHasKey( 'tools', $body );
	}

	// ------------------------------------------------------------------
	// 設定のサニタイズ
	// ------------------------------------------------------------------

	public function test_secret_sanitize_keeps_existing_on_empty_and_clears_on_flag() {
		update_option( 'node_ai_qwen_api_key', 'existing-key' );

		unset( $_POST['node_ai_qwen_api_key_clear'] );
		$this->assertSame( 'existing-key', node_ai_sanitize_secret( '', 'node_ai_qwen_api_key' ) );
		$this->assertSame( 'new-key', node_ai_sanitize_secret( 'new-key', 'node_ai_qwen_api_key' ) );

		$_POST['node_ai_qwen_api_key_clear'] = '1';
		$this->assertSame( '', node_ai_sanitize_secret( 'whatever', 'node_ai_qwen_api_key' ) );
		unset( $_POST['node_ai_qwen_api_key_clear'] );
	}

	// ------------------------------------------------------------------
	// Mock Handlers
	// ------------------------------------------------------------------

	private $mock_status = 500;
	private $openai_response_text = 'mocked response';
	private $gemini_response_text = 'gemini response';

	private function capture( $parsed_args, $url ): void {
		$this->captured = array(
			'url'     => $url,
			'body'    => (string) ( $parsed_args['body'] ?? '' ),
			'headers' => (array) ( $parsed_args['headers'] ?? array() ),
		);
	}

	public function mock_capture_openai_success( $preempt, $parsed_args, $url ) {
		if ( false === strpos( $url, '/chat/completions' ) ) {
			return $preempt;
		}
		$this->capture( $parsed_args, $url );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array(
				'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => $this->openai_response_text ) ) ),
				'usage'   => array( 'total_tokens' => 42 ),
			) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function mock_openai_error( $preempt, $parsed_args, $url ) {
		if ( false === strpos( $url, '/chat/completions' ) ) {
			return $preempt;
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'error' => array( 'message' => 'mock error' ) ) ),
			'response' => array( 'code' => $this->mock_status, 'message' => 'Error' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function mock_capture_ollama_success( $preempt, $parsed_args, $url ) {
		if ( false === strpos( $url, '/api/chat' ) ) {
			return $preempt;
		}
		$this->capture( $parsed_args, $url );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array(
				'message'    => array( 'role' => 'assistant', 'content' => 'ollama response' ),
				'eval_count' => 10,
			) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function mock_ollama_tags( $preempt, $parsed_args, $url ) {
		if ( false === strpos( $url, '/api/tags' ) ) {
			return $preempt;
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array(
				'models' => array(
					array( 'name' => 'gemma3:latest' ),
					array( 'name' => 'qwen3:8b' ),
				),
			) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function mock_connection_refused( $preempt, $parsed_args, $url ) {
		return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to localhost port 11434: Connection refused' );
	}

	public function mock_capture_gemini_success( $preempt, $parsed_args, $url ) {
		if ( false === strpos( $url, ':generateContent' ) ) {
			return $preempt;
		}
		$this->capture( $parsed_args, $url );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array(
				'candidates' => array(
					array( 'content' => array( 'parts' => array( array( 'text' => $this->gemini_response_text ) ) ) ),
				),
			) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function mock_gemini_error( $preempt, $parsed_args, $url ) {
		if ( false === strpos( $url, ':generateContent' ) ) {
			return $preempt;
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'error' => array( 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded' ) ) ),
			'response' => array( 'code' => $this->mock_status, 'message' => 'Too Many Requests' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function test_auto_generate_summary_skips_when_locked_and_releases_lock_on_api_error(): void {
		update_option( 'node_ai_gemini_api_key', 'AIza-mock-test-key' );
		update_option( 'node_ai_gemini_model', 'gemini-2.5-flash' );

		$post_id = self::factory()->post->create(
			array(
				'post_author'  => $this->user_id,
				'post_status'  => 'publish',
				'post_content' => '自動要約の失敗時ロック解放テスト本文。',
			)
		);

		$plugin = Node_AI_Tools::instance();
		$calls  = 0;
		$counter = static function ( $preempt ) use ( &$calls ) {
			++$calls;
			return new WP_Error( 'http_request_failed', 'Operation timed out after 30000 milliseconds' );
		};

		try {
			// ロック保持中は外部APIへ進まない。
			$this->assertTrue( node_ai_acquire_summary_lock( $post_id, 90 ) );
			add_filter( 'pre_http_request', $counter, 10, 3 );
			$plugin->run_auto_generate_ai_summary( $post_id );
			$this->assertSame( 0, $calls );

			// ロック解放後にタイムアウトエラーが返ってもロックはクリーンに解放される。
			node_ai_release_summary_lock( $post_id );
			$plugin->run_auto_generate_ai_summary( $post_id );
			$this->assertSame( 1, $calls );
			$this->assertSame( '', (string) get_post_meta( $post_id, '_node_ai_summary', true ) );
			$this->assertTrue( node_ai_acquire_summary_lock( $post_id, 90 ) );
		} finally {
			remove_filter( 'pre_http_request', $counter, 10 );
			node_ai_release_summary_lock( $post_id );
		}
	}
}
