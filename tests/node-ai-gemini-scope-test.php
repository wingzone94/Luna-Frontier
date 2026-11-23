<?php
/** Gemini is reserved for Summary/TTS/fact checking at every dispatch layer. */
class Node_AI_Gemini_Scope_Test extends WP_UnitTestCase {
	private $requests = array();
	private $mock;
	private $billing_failure = false;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'node_ai_provider', 'ollama' );
		update_option( 'node_ai_gemini_api_key', 'scope-test-key' );
		update_option( 'node_ai_gemini_model', 'gemini-2.5-flash' );
		$this->mock = function ( $pre, $args, $url ) {
			$this->requests[] = array( 'url' => $url, 'body' => json_decode( $args['body'] ?? '{}', true ) );
			if ( $this->billing_failure && false !== strpos( $url, ':generateContent' ) ) {
				return array( 'response' => array( 'code' => 402 ), 'headers' => array(), 'body' => wp_json_encode( array( 'error' => array( 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Your prepayment credits are depleted.' ) ) ) );
			}
			$text = '{"summary":"要約です。","titles":["タイトル"],"issues":[],"posts":["文案"]}';
			$data = array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => $text ) ) ) ) ), 'message' => array( 'content' => $text ), 'models' => array( array( 'name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => array( 'generateContent' ) ) ) );
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $data ) );
		};
		add_filter( 'pre_http_request', $this->mock, 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->mock, 10 );
		parent::tear_down();
	}

	public function test_summary_is_gemini_while_general_provider_remains_local(): void {
		$this->assertIsString( node_ai_core()->summarize( '本文', array(), get_current_user_id() ) );
		$this->assertStringContainsString( ':generateContent', end( $this->requests )['url'] );
		$this->assertSame( 'ollama', node_ai_core()->get_provider_id() );
		$this->assertSame( 'Gemini', node_ai_core()->get_usage_log()[0]['provider'] );
	}

	public function test_migrated_general_gemini_choice_keeps_dedicated_keys_and_models(): void {
		update_option( 'node_ai_provider', 'gemini' );
		update_option( 'node_ai_openai_migration', '1' );
		delete_option( 'node_ai_gemini_scope_migration' );
		$this->assertSame( 'ollama', node_ai_core()->get_provider_id() );
		Node_AI_Core::migrate_provider_settings();
		$this->assertSame( 'ollama', get_option( 'node_ai_provider' ) );
		$this->assertSame( 'scope-test-key', get_option( 'node_ai_gemini_api_key' ) );
		$this->assertSame( 'gemini-2.5-flash', get_option( 'node_ai_gemini_model' ) );
		$this->assertSame( 'gemini', node_ai_core()->get_fact_check_provider_id() );
	}

	public function test_forbidden_gemini_features_are_blocked_before_http_at_all_layers(): void {
		foreach ( array( 'proofread', 'suggest_titles', 'social_post', 'alt_text', 'reading_time' ) as $feature ) {
			$this->assertWPError( node_ai_core()->generate( $feature, 'test', array( 'provider' => 'gemini', 'feature' => 'summarize' ) ) );
			$this->assertWPError( ( new Node_AI_Provider_Gemini() )->generate( 'test', array( 'feature' => $feature ) ) );
			$this->assertWPError( ( new Node_Gemini_API() )->generate_content( 'test', array( 'feature' => $feature ) ) );
		}
		$this->assertWPError( ( new Node_Gemini_API() )->generate_content( 'untagged' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_normal_editor_features_do_not_contact_gemini_after_legacy_selection(): void {
		update_option( 'node_ai_provider', 'gemini' );
		$this->assertIsString( node_ai_core()->proofread( '本文' ) );
		$this->assertIsString( node_ai_core()->suggest_titles( '本文' ) );
		$this->assertIsString( node_ai_core()->social_post( '本文' ) );
		foreach ( $this->requests as $r ) {
			$this->assertStringContainsString( '/api/chat', $r['url'] );
		}
	}

	public function test_summary_cannot_attach_google_search_and_global_off_still_wins(): void {
		$this->assertWPError( ( new Node_Gemini_API() )->generate_content( 'test', array( 'feature' => 'summarize', 'google_search_grounding' => true ) ) );
		update_option( 'node_ai_provider', 'off' );
		$this->assertWPError( node_ai_core()->summarize( '本文' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_legacy_reading_time_and_summary_use_the_shared_dispatcher(): void {
		$api = new Node_Gemini_API();
		$this->assertIsString( $api->generate_reading_time_estimate( '本文' ) );
		$this->assertStringContainsString( '/api/chat', end( $this->requests )['url'] );
		$this->assertSame( 'reading_time', node_ai_core()->get_usage_log()[0]['feature'] );
		$this->assertIsString( $api->generate_summary( '本文' ) );
		$this->assertStringContainsString( ':generateContent', end( $this->requests )['url'] );
		$this->assertSame( 'summarize', node_ai_core()->get_usage_log()[0]['feature'] );
	}
	public function test_dedicated_settings_do_not_offer_gemini_as_a_general_provider(): void {
		if ( ! function_exists( 'node_ai_render_settings_page' ) ) {
			require_once dirname( __DIR__ ) . '/plugins-embedded/node-ai-tools/admin/settings-page-ai.php';
		}
		ob_start(); node_ai_render_settings_page(); $html = ob_get_clean();
		$dom = new DOMDocument();
		@$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		$xpath = new DOMXPath( $dom );
		$this->assertSame( 0, $xpath->query( '//input[@name="node_ai_provider" and @value="gemini"]' )->length );
		$this->assertSame( 1, $xpath->query( '//div[@class="node-ai-gemini-section"]//input[@name="node_ai_gemini_api_key"]' )->length );
		$this->assertStringContainsString( 'Gemini専用設定（AI要約・TTS・ファクトチェック）', $html );
		ob_start(); node_render_gemini_user_fields( wp_get_current_user() ); $profile = ob_get_clean();
		$this->assertStringContainsString( 'Gemini API（要約・TTS・ファクトチェック専用）', $profile );
		$this->assertStringContainsString( '画像alt生成には、このキーを使用しません', $profile );
	}

	public function test_legacy_summary_preserves_the_explicit_users_credentials(): void {
		$id = $this->factory->user->create( array( 'role' => 'editor' ) );
		update_user_meta( $id, 'node_gemini_api_key', 'other-user-test-key' );
		$this->assertIsString( ( new Node_Gemini_API( $id ) )->generate_summary( '本文' ) );
		$this->assertStringContainsString( 'key=other-user-test-key', end( $this->requests )['url'] );
	}

	public function test_billing_refusal_is_not_a_daily_quota_or_a_reason_to_try_more_models(): void {
		$this->billing_failure = true;
		update_option( 'node_ai_fc_grounding', 'required' );
		update_option( 'node_ai_fc_model_mode', 'auto' );
		delete_option( Node_AI_Fact_Check_Models::RETIRED_OPTION );
		Node_AI_Fact_Check_Models::clear_cache();
		$result = node_ai_core()->fact_check( '本文を確認してください。', '確認', get_current_user_id(), 0, 'gemini' );
		$this->assertWPError( $result );
		$this->assertSame( 'ai_billing_unavailable', $result->get_error_code() );
		$this->assertSame( 402, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( '17:00', $result->get_error_message() );
		$generated = array_filter( $this->requests, static fn( $r ) => false !== strpos( $r['url'], ':generateContent' ) );
		$this->assertCount( 1, $generated );
	}

}
