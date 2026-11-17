<?php
/** Independent fact-check routing, truthful evidence and automatic rechecks. */
class Node_AI_Fact_Check_Providers_Test extends WP_UnitTestCase {
	private $requests = array();
	private $mock;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );
		update_option( 'node_ai_provider', 'ollama' );
		delete_option( 'node_ai_fc_provider' );
		update_option( 'node_ai_gemini_api_key', 'test-only-key' );
		update_option( 'node_ai_fc_grounding', 'always' );
		update_option( 'node_ai_fc_model_mode', 'auto' );
		update_option( 'node_ai_openai_preset', 'local' );
		delete_option( 'node_ai_openai_endpoint' );
		delete_option( 'node_ai_openai_model' );
		delete_option( 'node_ai_ollama_model' );
		delete_transient( Node_AI_Fact_Check_Models::CACHE_KEY );
		delete_option( Node_AI_Fact_Check_Models::RETIRED_OPTION );
		delete_option( Node_AI_Fact_Check_Runner::GROUNDING_USAGE_OPTION );
		$this->mock = function ( $pre, $args, $url ) {
			$this->requests[] = array( 'url' => $url, 'body' => json_decode( $args['body'] ?? '{}', true ) );
			$payload = '{"premises":[{"premise":"テスト機器が発売済み","status":"unverified","confidence":"low","basis":"外部資料なし","evidence":[{"url":"https://www.microsoft.com/fabricated","title":"invented","official":true}]}],"summary":"外部根拠を確認できません。","claims":[{"claim":"テスト機器が発売済み","status":"unverifiable","confidence":"low","note":"公式資料を確認してください。","evidence":[{"url":"https://www.microsoft.com/fabricated","title":"invented","official":true}]}]}';
			if ( false !== strpos( $url, ':generateContent' ) ) {
				$data = array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => $payload ) ) ), 'groundingMetadata' => array( 'webSearchQueries' => array( 'test' ) ) ) ) );
			} elseif ( false !== strpos( $url, '/api/chat' ) ) {
				$data = array( 'message' => array( 'content' => $payload ) );
			} elseif ( false !== strpos( $url, '/chat/completions' ) ) {
				$data = array( 'choices' => array( array( 'message' => array( 'content' => $payload ) ) ) );
			} elseif ( false !== strpos( $url, 'generativelanguage.googleapis.com' ) ) {
				$data = array( 'models' => array( array( 'name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => array( 'generateContent' ) ) ) );
			} else {
				return new WP_Error( 'test_no_network', 'Fixture blocks external lookups.' );
			}
			return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ) );
		};
		add_filter( 'pre_http_request', $this->mock, 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->mock, 10 );
		parent::tear_down();
	}

	public function test_default_is_gemini_even_when_general_provider_is_ollama(): void {
		$this->assertSame( 'gemini', node_ai_core()->get_fact_check_provider_id() );
		$result = node_ai_core()->fact_check( '確認する文章です。', '確認', get_current_user_id() );
		$this->assertIsArray( $result );
		$this->assertSame( 'gemini', $result['context']['provider'] );
		$this->assertTrue( $result['context']['grounded'] );
		$generated = array_values( array_filter( $this->requests, static fn( $r ) => false !== strpos( $r['url'], ':generateContent' ) ) );
		$this->assertNotEmpty( $generated );
		$this->assertArrayHasKey( 'tools', $generated[0]['body'] );
		$this->assertSame( 'ollama', node_ai_core()->get_provider_id() );
	}

	public function test_runtime_local_override_has_no_search_and_never_becomes_a_gemini_recheck(): void {
		$result = node_ai_core()->fact_check( '<a href="https://www.microsoft.com/test">説明</a>', '確認', get_current_user_id(), 0, 'ollama' );
		$this->assertIsArray( $result );
		$this->assertSame( 'ollama', $result['context']['provider'] );
		$this->assertFalse( $result['context']['grounded'] );
		$this->assertSame( array(), $result['context']['evidence'] );
		$claims = json_decode( $result['text'], true )['claims'];
		$this->assertSame( array(), $claims[0]['evidence'] );
		$this->assertContains( Node_AI_Core::fact_check_provider_description( 'ollama' ), $result['context']['notices'] );
		$this->assertSame( 'gemini', node_ai_core()->get_fact_check_provider_id() );
		foreach ( $this->requests as $request ) {
			$this->assertStringNotContainsString( 'generativelanguage', $request['url'] );
			$this->assertStringNotContainsString( 'microsoft.com', $request['url'] );
			$this->assertArrayNotHasKey( 'tools', $request['body'] ?? array() );
		}
		$post = $this->factory->post->create();
		$payload = node_ai_store_fact_check_result( $post, $result );
		$this->assertSame( 'ollama', $payload['provider'] );
		$this->assertSame( '', get_post_meta( $post, '_node_ai_fact_check_degraded', true ) );
		$this->assertSame( array(), get_option( Node_AI_Fact_Check_Runner::GROUNDING_USAGE_OPTION, array() ) );
		update_post_meta( $post, '_node_ai_fact_check_approved', '1' );
		ob_start(); node_ai_render_fact_check_front( $post ); $html = ob_get_clean();
		$this->assertStringContainsString( 'by Ollama', $html );
		$this->assertStringNotContainsString( 'by Gemini', $html );
		$this->assertStringContainsString( '検索なし', $html );
	}

	public function test_saved_openai_choice_is_independent_of_general_gemini(): void {
		update_option( 'node_ai_provider', 'gemini' );
		update_option( 'node_ai_fc_provider', 'openai' );
		$result = node_ai_core()->fact_check( '確認する文章です。', '確認', get_current_user_id() );
		$this->assertIsArray( $result );
		$this->assertSame( 'openai', $result['context']['provider'] );
		$this->assertFalse( $result['context']['grounded'] );
		$this->assertStringContainsString( '/chat/completions', end( $this->requests )['url'] );
	}

	public function test_invalid_override_and_global_off_cannot_invoke_any_provider(): void {
		$this->assertWPError( node_ai_core()->fact_check( '本文', '', 0, 0, 'invalid' ) );
		update_option( 'node_ai_provider', 'off' );
		$this->assertWPError( node_ai_core()->fact_check( '本文', '', 0, 0, 'ollama' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_premise_cache_does_not_cross_provider_boundaries(): void {
		foreach ( array( 'ollama', 'openai', 'ollama' ) as $provider ) {
			$this->assertIsArray( node_ai_core()->fact_check( 'テスト機器が発売されました。', 'テスト機器の発売', get_current_user_id(), 0, $provider ) );
		}
		$native = array_filter( $this->requests, static fn( $r ) => false !== strpos( $r['url'], '/api/chat' ) );
		$compatible = array_filter( $this->requests, static fn( $r ) => false !== strpos( $r['url'], '/chat/completions' ) );
		$this->assertCount( 3, $native ); // First premise + two claims calls; last premise is cached.
		$this->assertCount( 2, $compatible ); // Independent premise + claims.
	}
}
