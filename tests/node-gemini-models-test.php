<?php
/**
 * Gemini モデル一覧・思考量（thinkingLevel）仮想IDの自動テスト
 *
 * @package Node
 */

class Node_Gemini_Models_Test extends WP_UnitTestCase {

	private $user_id;

	private $captured_request = null;

	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'Node_Gemini_API' ) ) {
			require_once dirname( __DIR__ ) . '/plugins-embedded/node-ai-tools/includes/class-gemini-api.php';
		}

		$this->user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		update_user_meta( $this->user_id, 'node_gemini_api_key', 'dummy_key' );
		wp_set_current_user( $this->user_id );

		delete_transient( node_gemini_models_cache_key() );
		$this->captured_request = null;
	}

	public function tear_down() {
		delete_transient( node_gemini_models_cache_key() );
		parent::tear_down();
	}

	// --- モデルIDバリデーション ---

	public function test_model_id_validation_accepts_thinking_suffix() {
		$this->assertTrue( node_is_valid_gemini_model_id( 'gemini-3.5-flash' ) );
		$this->assertTrue( node_is_valid_gemini_model_id( 'gemini-3.5-flash@high' ) );
		$this->assertTrue( node_is_valid_gemini_model_id( 'gemini-3.1-pro-preview@low' ) );
		$this->assertFalse( node_is_valid_gemini_model_id( 'gemini-3.5-flash@medium' ) );
		$this->assertFalse( node_is_valid_gemini_model_id( 'gpt-4o' ) );
		$this->assertFalse( node_is_valid_gemini_model_id( '@high' ) );
	}

	// --- ListModels エントリのフィルタ ---

	public function test_parse_entry_accepts_text_models_and_rejects_image_models() {
		$make = static function ( string $id ): array {
			return array(
				'name'                       => 'models/' . $id,
				'displayName'                => $id,
				'supportedGenerationMethods' => array( 'generateContent' ),
			);
		};

		$this->assertNotNull( node_parse_gemini_model_entry( $make( 'gemini-3.5-flash' ) ) );
		// 画像系（Nano Banana）・TTS・ロボティクスは除外
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-3.1-flash-image' ) ) );
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-3.1-flash-image-preview' ) ) );
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-3.1-flash-tts-preview' ) ) );
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-robotics-er-1.6-preview' ) ) );
	}

	// --- 1.3: 選べないモデルをプルダウンから排除する ---

	public function test_parse_entry_rejects_unselectable_models() {
		$make = static function ( string $id ): array {
			return array(
				'name'                       => 'models/' . $id,
				'displayName'                => $id,
				'supportedGenerationMethods' => array( 'generateContent' ),
			);
		};

		// 旧世代（2.5 未満）はサポート終了ラインなので出さない
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-2.0-flash' ) ) );
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-1.5-pro' ) ) );
		// 枝番スナップショットは安定版と重複
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-2.0-flash-001' ) ) );
		// 浮動エイリアスは指す先が変わる
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-flash-latest' ) ) );
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-pro-latest' ) ) );
		// 特殊用途
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-omni-flash-preview' ) ) );
		// 現行世代は残る
		$this->assertNotNull( node_parse_gemini_model_entry( $make( 'gemini-3.6-flash' ) ) );
		$this->assertNotNull( node_parse_gemini_model_entry( $make( 'gemini-2.5-flash' ) ) );
	}

	public function test_fetch_drops_preview_when_stable_exists_and_has_no_thinking_variants() {
		add_filter( 'pre_http_request', array( $this, 'mock_list_models' ), 10, 3 );
		$result = node_fetch_gemini_models_from_api( 'dummy_key', true );
		remove_filter( 'pre_http_request', array( $this, 'mock_list_models' ), 10 );

		$this->assertIsArray( $result );
		$models = $result['models'];

		$this->assertArrayHasKey( 'gemini-3.5-flash', $models );
		// 思考量はモデル一覧から分離したので仮想IDは作らない
		$this->assertArrayNotHasKey( 'gemini-3.5-flash@high', $models );
		$this->assertArrayNotHasKey( 'gemini-3.5-flash@low', $models );
		// 安定版がある preview は重複なので落ちる
		$this->assertArrayHasKey( 'gemini-3.1-flash-lite', $models );
		$this->assertArrayNotHasKey( 'gemini-3.1-flash-lite-preview', $models );
		// 思考量対応は API の thinking フラグから拾う（Lite も対象）
		$this->assertContains( 'gemini-3.5-flash', $result['thinking'] );
		$this->assertContains( 'gemini-3.1-flash-lite', $result['thinking'] );
		$this->assertNotContains( 'gemini-2.5-flash', $result['thinking'] );
	}

	public function test_fetch_sorts_newest_generation_first() {
		add_filter( 'pre_http_request', array( $this, 'mock_list_models' ), 10, 3 );
		$result = node_fetch_gemini_models_from_api( 'dummy_key', true );
		remove_filter( 'pre_http_request', array( $this, 'mock_list_models' ), 10 );

		$ids = array_keys( $result['models'] );
		$this->assertSame( 'gemini-3.5-flash', $ids[0] );
		$this->assertSame( 'gemini-2.5-flash', end( $ids ) );
	}

	public function test_fetch_limits_paginated_requests_on_cache_miss() {
		$requests = 0;
		$mock = static function () use ( &$requests ) {
			++$requests;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'models'        => array(
							array(
								'name'                       => 'models/gemini-3.5-flash',
								'displayName'                => 'Gemini 3.5 Flash',
								'supportedGenerationMethods' => array( 'generateContent' ),
							),
						),
						'nextPageToken' => 'more',
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $mock );
		$result = node_fetch_gemini_models_from_api( 'dummy_key', true );
		remove_filter( 'pre_http_request', $mock );

		$this->assertSame( 3, $requests );
		$this->assertArrayHasKey( 'gemini-3.5-flash', $result['models'] );
	}

	// --- 1.3: 思考量の分離（保存形式は 1.2 系と互換） ---

	public function test_user_options_do_not_leak_thinking_suffix_into_model_list() {
		add_filter( 'pre_http_request', array( $this, 'mock_list_models' ), 10, 3 );
		update_user_meta( $this->user_id, 'node_gemini_api_key', 'dummy_key' );
		update_user_meta( $this->user_id, 'node_gemini_model', 'gemini-3.5-flash@low' );

		$options = node_get_gemini_model_options_for_user( $this->user_id );
		remove_filter( 'pre_http_request', array( $this, 'mock_list_models' ), 10 );

		// 保存値をそのまま足すと `@low` 付きの項目が一覧に混ざってしまう
		$this->assertArrayNotHasKey( 'gemini-3.5-flash@low', $options );
		$this->assertArrayHasKey( 'gemini-3.5-flash', $options );
	}

	// --- 1.3: 429 の上限種別を quotaId から判定する（復帰時刻の虚偽表示を防ぐ） ---

	/**
	 * @param string $quota_id    Google が返す quotaId。
	 * @param string $retry_delay RetryInfo の retryDelay。
	 * @return array<string, mixed>
	 */
	private function make_429( string $quota_id, string $retry_delay = '59s', string $quota_value = '5' ): array {
		return array(
			'error' => array(
				'code'    => 429,
				'status'  => 'RESOURCE_EXHAUSTED',
				'message' => 'You exceeded your current quota',
				'details' => array(
					array(
						'@type'      => 'type.googleapis.com/google.rpc.QuotaFailure',
						'violations' => array(
							array(
								'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests',
								'quotaId'     => $quota_id,
								'quotaValue'  => $quota_value,
							),
						),
					),
					array(
						'@type'      => 'type.googleapis.com/google.rpc.RetryInfo',
						'retryDelay' => $retry_delay,
					),
				),
			),
		);
	}

	public function test_quota_violation_scope_is_read_from_quota_id() {
		$minute = node_gemini_extract_quota_violation(
			$this->make_429( 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier' )
		);
		$this->assertSame( 'minute', $minute['scope'] );
		$this->assertSame( '5回/分', $minute['limit'] );

		$day = node_gemini_extract_quota_violation(
			$this->make_429( 'GenerateRequestsPerDayPerProjectPerModel-FreeTier', '59s', '100' )
		);
		$this->assertSame( 'day', $day['scope'] );
		$this->assertSame( '100回/日', $day['limit'] );

		$unknown = node_gemini_extract_quota_violation( array( 'error' => array() ) );
		$this->assertSame( '', $unknown['scope'] );
	}

	public function test_daily_quota_message_does_not_promise_recovery_in_seconds() {
		// 日次上限でも retryDelay に短い秒数が返る。これを「解除予定」と出すのは虚偽になる
		$message = node_gemini_format_api_error(
			429,
			$this->make_429( 'GenerateRequestsPerDayPerProjectPerModel-FreeTier', '59s', '100' )
		);

		$this->assertStringContainsString( '1日あたりの利用上限', $message );
		$this->assertStringContainsString( '100回/日', $message );
		$this->assertStringNotContainsString( '59 秒後', $message );
		$this->assertStringNotContainsString( '秒後（日本時間', $message );
	}

	public function test_minute_quota_message_reports_seconds() {
		$message = node_gemini_format_api_error(
			429,
			$this->make_429( 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier', '14s' )
		);

		$this->assertStringContainsString( '1分あたりのリクエスト上限', $message );
		$this->assertStringContainsString( '約 14 秒後', $message );
	}

	public function test_unclassified_quota_error_does_not_lock_model_for_a_day() {
		$model = 'gemini-3.6-flash';

		// 分類できない 429（QuotaFailure も RetryInfo も無い）。
		// これを日次リセットまでブロックすると、実際は使えるモデルを翌日まで締め出す
		node_gemini_record_quota_error(
			$this->user_id,
			$model,
			429,
			array( 'error' => array( 'status' => 'RESOURCE_EXHAUSTED' ) )
		);

		$block = node_gemini_get_quota_block( $this->user_id, $model );
		$this->assertTrue( $block['blocked'] );
		$this->assertLessThanOrEqual( time() + 120, $block['until'] );
	}

	public function test_daily_quota_block_is_capped_so_it_can_recover() {
		$model = 'gemini-3.5-flash';

		node_gemini_record_quota_error(
			$this->user_id,
			$model,
			429,
			$this->make_429( 'GenerateRequestsPerDayPerProjectPerModel-FreeTier', '59s', '100' )
		);

		$block = node_gemini_get_quota_block( $this->user_id, $model );
		$this->assertTrue( $block['blocked'] );
		// 丸一日ではなく、上限つきの待機に収まること
		$this->assertLessThanOrEqual( time() + 10 * MINUTE_IN_SECONDS + 5, $block['until'] );
	}

	public function test_stale_long_block_from_old_format_is_ignored() {
		$model = 'gemini-3.6-flash';
		$key   = 'node_gemini_usage_' . $this->user_id;

		// 旧実装が書いた「日次リセットまで」の記録を再現する
		set_transient(
			$key,
			array(
				$model => array(
					'rpm'        => array( 'count' => 0, 'expires' => time() + 60 ),
					'tpm'        => array( 'count' => 0, 'expires' => time() + 60 ),
					'rpd'        => array( 'count' => 0, 'expires' => time() + DAY_IN_SECONDS ),
					'last_error' => array(
						'status'  => 429,
						'retry'   => 0,
						'expires' => time() + DAY_IN_SECONDS,
					),
				),
			),
			DAY_IN_SECONDS
		);

		$block = node_gemini_get_quota_block( $this->user_id, $model );
		$this->assertFalse( $block['blocked'], '古い長時間ブロックは無視して再挑戦できること' );
	}

	public function test_minute_quota_does_not_block_until_daily_reset() {
		$model = 'gemini-3.5-flash';

		node_gemini_record_quota_error(
			$this->user_id,
			$model,
			429,
			$this->make_429( 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier', '14s' )
		);

		$block = node_gemini_get_quota_block( $this->user_id, $model );
		$this->assertTrue( $block['blocked'] );
		// 毎分上限なのに丸一日ブロックしてはいけない
		$this->assertLessThanOrEqual( time() + 120, $block['until'] );
	}

	// --- 1.3: 枯渇済みモデルへ叩き続けて使用量を浪費しないためのガード ---

	public function test_quota_block_reports_blocked_until_reset() {
		$model = 'gemini-3.6-flash';

		// 未発生なら素通し
		$before = node_gemini_get_quota_block( $this->user_id, $model );
		$this->assertFalse( $before['blocked'] );

		// 429 を記録すると、リセット時刻までブロック扱いになる
		node_gemini_record_quota_error(
			$this->user_id,
			$model,
			429,
			array( 'error' => array( 'status' => 'RESOURCE_EXHAUSTED' ) )
		);

		$after = node_gemini_get_quota_block( $this->user_id, $model );
		$this->assertTrue( $after['blocked'] );
		$this->assertGreaterThan( time(), $after['until'] );

		// 思考量つき仮想IDでも同じモデルとして判定する
		$suffixed = node_gemini_get_quota_block( $this->user_id, $model . '@low' );
		$this->assertTrue( $suffixed['blocked'] );

		// 別モデルは巻き添えにしない
		$other = node_gemini_get_quota_block( $this->user_id, 'gemini-2.5-flash' );
		$this->assertFalse( $other['blocked'] );
	}

	public function test_split_and_join_gemini_model() {
		$this->assertSame(
			array( 'model' => 'gemini-3.6-flash', 'thinking' => 'high' ),
			node_split_gemini_model( 'gemini-3.6-flash@high' )
		);
		$this->assertSame(
			array( 'model' => 'gemini-3.6-flash', 'thinking' => '' ),
			node_split_gemini_model( 'gemini-3.6-flash' )
		);

		$this->assertSame( 'gemini-3.6-flash@low', node_join_gemini_model( 'gemini-3.6-flash', 'low' ) );
		$this->assertSame( 'gemini-3.6-flash', node_join_gemini_model( 'gemini-3.6-flash', '' ) );
		// 不正な思考量は付けない
		$this->assertSame( 'gemini-3.6-flash', node_join_gemini_model( 'gemini-3.6-flash', 'medium' ) );
		// 二重付与しない
		$this->assertSame( 'gemini-3.6-flash@high', node_join_gemini_model( 'gemini-3.6-flash@low', 'high' ) );
	}

	public function test_default_model_never_returns_thinking_variant() {
		add_filter( 'pre_http_request', array( $this, 'mock_list_models' ), 10, 3 );
		update_user_meta( $this->user_id, 'node_gemini_api_key', 'dummy_key' );
		$default = node_get_default_gemini_model();
		remove_filter( 'pre_http_request', array( $this, 'mock_list_models' ), 10 );

		$this->assertStringNotContainsString( '@', $default );
		$this->assertStringContainsStringIgnoringCase( 'flash', $default );
	}

	// --- generate_content の思考量変換 ---

	public function test_generate_content_strips_suffix_and_sends_thinking_level() {
		update_user_meta( $this->user_id, 'node_gemini_model', 'gemini-3.5-flash@low' );

		add_filter( 'pre_http_request', array( $this, 'mock_generate_capture' ), 10, 3 );
		$api    = new Node_Gemini_API();
		$result = $api->generate_content( 'テスト' );
		remove_filter( 'pre_http_request', array( $this, 'mock_generate_capture' ), 10 );

		$this->assertSame( 'ok', $result );
		$this->assertNotNull( $this->captured_request );

		// URL には @low を含まない実IDが使われる
		$this->assertStringContainsString( '/models/gemini-3.5-flash:generateContent', $this->captured_request['url'] );
		$this->assertStringNotContainsString( '@low', $this->captured_request['url'] );

		// generationConfig.thinkingConfig.thinkingLevel = low が送信される
		$body = json_decode( $this->captured_request['body'], true );
		$this->assertSame( 'low', $body['generationConfig']['thinkingConfig']['thinkingLevel'] ?? null );
	}

	public function test_generate_content_without_suffix_sends_no_thinking_config() {
		update_user_meta( $this->user_id, 'node_gemini_model', 'gemini-2.5-flash' );

		add_filter( 'pre_http_request', array( $this, 'mock_generate_capture' ), 10, 3 );
		$api = new Node_Gemini_API();
		$api->generate_content( 'テスト' );
		remove_filter( 'pre_http_request', array( $this, 'mock_generate_capture' ), 10 );

		$body = json_decode( $this->captured_request['body'], true );
		$this->assertArrayNotHasKey( 'thinkingConfig', $body['generationConfig'] );
	}

	// --- Mock Handlers ---

	public function mock_list_models( $preempt, $parsed_args, $url ) {
		if ( strpos( $url, 'generativelanguage.googleapis.com/v1beta/models' ) === false || strpos( $url, ':generateContent' ) !== false ) {
			return $preempt;
		}
		$make = static function ( string $id, string $label, bool $thinking = false ): array {
			$entry = array(
				'name'                       => 'models/' . $id,
				'displayName'                => $label,
				'supportedGenerationMethods' => array( 'generateContent' ),
			);
			if ( $thinking ) {
				$entry['thinking'] = true;
			}
			return $entry;
		};
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array(
				'models' => array(
					$make( 'gemini-3.5-flash', 'Gemini 3.5 Flash', true ),
					$make( 'gemini-3.1-pro-preview', 'Gemini 3.1 Pro Preview', true ),
					$make( 'gemini-3.1-flash-lite', 'Gemini 3.1 Flash Lite', true ),
					// 安定版があるので落ちるべき preview
					$make( 'gemini-3.1-flash-lite-preview', 'Gemini 3.1 Flash Lite Preview', true ),
					$make( 'gemini-2.5-flash', 'Gemini 2.5 Flash' ),
					$make( 'gemini-3.1-flash-image', 'Nano Banana 2' ),
					// 1.3 で選べなくなるもの
					$make( 'gemini-2.0-flash', 'Gemini 2.0 Flash' ),
					$make( 'gemini-flash-latest', 'Gemini Flash Latest', true ),
				),
			) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function mock_generate_capture( $preempt, $parsed_args, $url ) {
		if ( strpos( $url, ':generateContent' ) === false ) {
			return $preempt;
		}
		$this->captured_request = array(
			'url'  => $url,
			'body' => (string) ( $parsed_args['body'] ?? '' ),
		);
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array(
				'candidates' => array(
					array( 'content' => array( 'parts' => array( array( 'text' => 'ok' ) ) ) ),
				),
			) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	// --- 1.3: 思考量は指定が効くモデルにだけ出す ---

	public function test_thinking_models_are_limited_to_gemini3_and_newer() {
		add_filter( 'pre_http_request', array( $this, 'mock_list_models' ), 10, 3 );
		$models = node_get_gemini_thinking_models( $this->user_id );
		remove_filter( 'pre_http_request', array( $this, 'mock_list_models' ), 10 );

		// thinkingLevel は Gemini 3 系以降の指定方法（実測で 3.x のみ 200）
		$this->assertContains( 'gemini-3.5-flash', $models );
		$this->assertContains( 'gemini-3.1-flash-lite', $models );

		// 2.5 系は thinking 自体は行うが thinkingLevel は使わないので出さない
		$this->assertNotContains( 'gemini-2.5-flash', $models );
		$this->assertNotContains( 'gemini-2.5-pro', $models );

		foreach ( $models as $id ) {
			$this->assertMatchesRegularExpression( '/^gemini-[3-9]/', $id );
		}
	}

	// --- 1.3: 提供終了モデル（404）をプルダウンから外す ---

	public function test_retired_model_is_recorded_and_excluded() {
		$retired = 'gemini-2.5-flash-lite';
		delete_option( 'node_gemini_retired_models' );

		$make = static function ( string $id ): array {
			return array(
				'name'                       => 'models/' . $id,
				'displayName'                => $id,
				'supportedGenerationMethods' => array( 'generateContent' ),
			);
		};

		// 記録前は一覧に残る
		$this->assertNotNull( node_parse_gemini_model_entry( $make( $retired ) ) );

		node_gemini_maybe_record_retired_model(
			$retired,
			404,
			array( 'error' => array( 'message' => 'This model models/' . $retired . ' is no longer available to new users.' ) )
		);

		$this->assertContains( $retired, node_gemini_get_retired_models() );
		// 記録後は一覧から外れる
		$this->assertNull( node_parse_gemini_model_entry( $make( $retired ) ) );
		// 他のモデルは巻き添えにしない
		$this->assertNotNull( node_parse_gemini_model_entry( $make( 'gemini-3.6-flash' ) ) );

		delete_option( 'node_gemini_retired_models' );
	}

	public function test_other_404_does_not_mark_model_retired() {
		delete_option( 'node_gemini_retired_models' );

		// 「no longer available」でも「not found」でもない 404 は記録しない
		node_gemini_maybe_record_retired_model(
			'gemini-3.6-flash',
			404,
			array( 'error' => array( 'message' => 'Requested entity was invalid.' ) )
		);
		$this->assertSame( array(), node_gemini_get_retired_models() );

		// 429 は提供終了ではない
		node_gemini_maybe_record_retired_model(
			'gemini-3.6-flash',
			429,
			array( 'error' => array( 'message' => 'no longer available' ) )
		);
		$this->assertSame( array(), node_gemini_get_retired_models() );
	}



	// --- 1.3: Pro 系は無料枠対象外なのでプルダウンに出さない ---

	public function test_pro_models_are_excluded_by_default() {
		$make = static function ( string $id ): array {
			return array(
				'name'                       => 'models/' . $id,
				'displayName'                => $id,
				'supportedGenerationMethods' => array( 'generateContent' ),
			);
		};

		// 2026-04-01 以降 Pro は無料枠から外れ、選んでも必ず 429 になる
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-2.5-pro' ) ) );
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-3.1-pro-preview' ) ) );
		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-3-pro-preview' ) ) );

		// Flash / Flash-Lite は残す
		$this->assertNotNull( node_parse_gemini_model_entry( $make( 'gemini-3.6-flash' ) ) );
		$this->assertNotNull( node_parse_gemini_model_entry( $make( 'gemini-3.5-flash-lite' ) ) );
	}

	public function test_pro_models_can_be_re_enabled_for_paid_plans() {
		$make = static function ( string $id ): array {
			return array(
				'name'                       => 'models/' . $id,
				'displayName'                => $id,
				'supportedGenerationMethods' => array( 'generateContent' ),
			);
		};

		// 課金プランへ移行したらフィルタで解禁できる
		add_filter( 'node_gemini_allow_pro_models', '__return_true' );
		$this->assertNotNull( node_parse_gemini_model_entry( $make( 'gemini-2.5-pro' ) ) );
		remove_filter( 'node_gemini_allow_pro_models', '__return_true' );

		$this->assertNull( node_parse_gemini_model_entry( $make( 'gemini-2.5-pro' ) ) );
	}

	public function test_fallback_options_contain_no_pro_models() {
		foreach ( array_keys( node_get_gemini_model_fallback_options() ) as $id ) {
			$this->assertDoesNotMatchRegularExpression( '/-pro(?:-|$)/i', $id );
		}
	}

}
