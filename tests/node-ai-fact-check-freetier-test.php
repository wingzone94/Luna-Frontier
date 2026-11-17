<?php
/**
 * ファクトチェックの無料枠モデル選択と判定ガードのテスト
 *
 * 2026-08-18 の事故（発売済みの Nintendo Switch 2 を「未発表」と断定し、
 * そこから USB-C / カメラ / AAC まで芋づる式に否定して記事全体を高リスクにした）を
 * 回帰ケースとして固定する。
 *
 * @package Node_AI_Tools
 */

class Node_AI_Fact_Check_Free_Tier_Test extends WP_UnitTestCase {

	/** @var array<int, array<string, mixed>> */
	public $requests = array();

	/** @var array<string, int> */
	public $model_status = array();

	private $user_id;

	public function set_up() {
		parent::set_up();

		$plugin = dirname( __DIR__ ) . '/plugins-embedded/node-ai-tools/';

		require_once $plugin . 'includes/guidelines-fetcher.php';
		require_once $plugin . 'includes/class-gemini-api.php';
		require_once $plugin . 'includes/providers/interface-node-ai-provider.php';
		require_once $plugin . 'includes/providers/class-provider-gemini.php';
		require_once $plugin . 'includes/providers/class-provider-qwen.php';
		require_once $plugin . 'includes/providers/class-provider-ollama.php';
		require_once $plugin . 'includes/class-ai-core.php';
		require_once $plugin . 'includes/class-fact-check-models.php';
		require_once $plugin . 'includes/class-fact-check-sources.php';
		require_once $plugin . 'includes/class-fact-check-discovery.php';
		require_once $plugin . 'includes/fact-check-verdict.php';
		require_once $plugin . 'includes/class-fact-check-runner.php';
		require_once $plugin . 'includes/fact-check-render.php';
		require_once $plugin . 'includes/ajax-handlers.php';
		require_once $plugin . 'includes/auto-check.php';

		$this->requests     = array();
		$this->model_status = array();

		$this->reset_model_state();

		update_option( 'node_ai_provider', 'gemini' );
		update_option( 'node_ai_gemini_api_key', 'dummy_key' );
		// 外部ページの取得はモデル選択のテストでは不要なので止めておく。
		update_option( Node_AI_Fact_Check_Sources::ENABLED_OPTION, '0' );

		$this->user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		// 再試行の待機はテストでは行わない。
		add_filter( 'node_ai_fc_retry_wait', '__return_zero' );
	}

	public function tear_down() {
		remove_filter( 'node_ai_fc_retry_wait', '__return_zero' );
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		parent::tear_down();
	}

	private function reset_model_state(): void {
		Node_AI_Fact_Check_Models::clear_cache();
		delete_option( Node_AI_Fact_Check_Models::CACHE_KEY . '_last' );
		delete_option( Node_AI_Fact_Check_Models::RETIRED_OPTION );
		delete_option( Node_AI_Fact_Check_Models::LAST_GOOD_OPTION );
		delete_option( Node_AI_Fact_Check_Models::MODE_OPTION );
		delete_option( Node_AI_Fact_Check_Models::MANUAL_OPTION );
		delete_option( Node_AI_Fact_Check_Models::ALLOW_PAID_OPTION );
		delete_option( Node_AI_Fact_Check_Runner::GROUNDING_USAGE_OPTION );

		foreach ( $this->all_mock_models() as $id ) {
			delete_transient( 'node_ai_fc_unavail_' . md5( $id ) );
		}
	}

	/**
	 * @return array<int, string>
	 */
	private function all_mock_models(): array {
		return array(
			'gemini-4.0-flash',
			'gemini-3.7-flash',
			'gemini-3.6-flash',
			'gemini-3.5-flash',
			'gemini-2.5-flash',
			'gemini-3.5-flash-lite',
			'gemini-3.1-flash-lite',
			'gemini-2.5-flash-lite',
			'gemini-2.5-pro',
			'gemini-3.1-pro-preview',
			'gemini-3-flash-preview',
			'gemini-flash-latest',
			'gemini-2.0-flash-exp',
		);
	}

	// ------------------------------------------------------------------
	// HTTP モック
	// ------------------------------------------------------------------

	private function enable_http_mock(): void {
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	/**
	 * ListModels と generateContent を差し替える。
	 * 外部への実アクセスはすべて遮断する。
	 *
	 * @param mixed                $preempt 既定値。
	 * @param array<string, mixed> $args    リクエスト引数。
	 * @param string               $url     URL。
	 */
	public function mock_http( $preempt, $args, $url ) {
		// 先に登録した個別モック（Wikidata / 公式ページ）が応答済みならそれを尊重する。
		// pre_http_request は短絡しないため、ここで上書きしてしまうと個別モックが効かない
		if ( false !== $preempt ) {
			return $preempt;
		}

		if ( false === strpos( $url, 'generativelanguage.googleapis.com' ) ) {
			return new WP_Error( 'test_blocked', '外部アクセスはテストでは行わない: ' . $url );
		}

		if ( false !== strpos( $url, ':generateContent' ) ) {
			return $this->mock_generate( $args, $url );
		}

		return $this->mock_list_models();
	}

	private function mock_list_models(): array {
		$models = array();

		foreach ( $this->all_mock_models() as $id ) {
			if ( 'gemini-4.0-flash' === $id && empty( $this->expose_future_model ) ) {
				continue;
			}

			$models[] = array(
				'name'                       => 'models/' . $id,
				'displayName'                => strtoupper( $id ),
				'version'                    => '1.0',
				'thinking'                   => true,
				'supportedGenerationMethods' => array( 'generateContent' ),
				'inputTokenLimit'            => 1000000,
				'outputTokenLimit'           => 65536,
			);
		}

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'models' => $models ) ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** @var bool */
	public $expose_future_model = false;

	/**
	 * @param array<string, mixed> $args リクエスト引数。
	 * @param string               $url  URL。
	 * @return array<string, mixed>
	 */
	private function mock_generate( array $args, string $url ) {
		$body    = json_decode( (string) ( $args['body'] ?? '{}' ), true );
		$model   = preg_match( '#/models/([^:]+):#', $url, $m ) ? $m[1] : '';
		$tools   = ! empty( $body['tools'] );
		$system  = (string) ( $body['system_instruction']['parts'][0]['text'] ?? '' );
		$premise = false !== strpos( $system, '基礎前提' );

		$this->requests[] = array(
			'model'   => $model,
			'tools'   => $tools,
			'premise' => $premise,
		);

		$status = (int) ( $this->model_status[ $model ] ?? 200 );

		if ( 200 !== $status ) {
			return $this->error_response( $status );
		}

		if ( $tools && ! empty( $this->grounding_status ) ) {
			return $this->error_response( (int) $this->grounding_status );
		}

		$payload = $premise ? $this->premise_payload() : $this->claims_payload();

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'candidates' => array(
						array(
							'finishReason' => 'STOP',
							'content'      => array(
								'parts' => array( array( 'text' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) ) ),
							),
						),
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** @var int */
	public $grounding_status = 0;

	/** @var array<string, mixed>|null */
	public $premise_override = null;

	/** @var array<string, mixed>|null */
	public $claims_override = null;

	private function premise_payload(): array {
		return $this->premise_override ?? array(
			'premises' => array(
				array(
					'premise'    => 'Nintendo Switch 2 は現在発売済みの製品である',
					'status'     => 'confirmed',
					'confidence' => 'high',
					'basis'      => '公式サイトで確認',
					'evidence'   => array(
						array(
							'url'      => 'https://www.nintendo.co.jp/hardware/switch2/',
							'title'    => 'Nintendo Switch 2',
							'official' => true,
						),
					),
				),
			),
		);
	}

	private function claims_payload(): array {
		return $this->claims_override ?? array(
			'summary' => 'おおむね問題ありません。',
			'claims'  => array(
				array(
					'claim'      => 'Nintendo Switch 2 は上部に USB-C 端子を備える',
					'claim_type' => 'fact',
					'depends_on' => 0,
					'status'     => 'likely_correct',
					'confidence' => 'medium',
					'note'       => '公式仕様ページに記載',
					'evidence'   => array(
						array(
							'url'      => 'https://www.nintendo.co.jp/hardware/switch2/spec/',
							'title'    => '仕様',
							'official' => true,
						),
					),
				),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function error_response( int $status ): array {
		$messages = array(
			404 => array( 'NOT_FOUND', 'is not found or is no longer available' ),
			429 => array( 'RESOURCE_EXHAUSTED', 'You exceeded your current quota' ),
			503 => array( 'UNAVAILABLE', 'The model is overloaded' ),
		);

		$detail = $messages[ $status ] ?? array( 'INVALID_ARGUMENT', 'error' );

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'code'    => $status,
						'status'  => $detail[0],
						'message' => $detail[1],
					),
				)
			),
			'response' => array(
				'code'    => $status,
				'message' => 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	// ------------------------------------------------------------------
	// 1〜5: モデル選択
	// ------------------------------------------------------------------

	/** 1. 特定モデル（gemini-2.5-flash 等）へ固定されていない */
	public function test_model_is_not_pinned_to_a_specific_generation() {
		$this->enable_http_mock();

		$selected = Node_AI_Fact_Check_Models::select();

		$this->assertNotWPError( $selected );
		$this->assertNotSame( 'gemini-2.5-flash', $selected );
		$this->assertNotSame( 'gemini-3.5-flash', $selected );

		// プラグイン本体にモデルIDが直書きされていないこと（静的フォールバック表は除く）。
		$plugin_dir = dirname( __DIR__ ) . '/plugins-embedded/node-ai-tools/';
		foreach ( glob( $plugin_dir . '{includes,includes/providers,admin}/*.php', GLOB_BRACE ) as $file ) {
			if ( str_ends_with( $file, 'class-fact-check-models.php' ) ) {
				continue;
			}

			$this->assertStringNotContainsString(
				'gemini-2.5-flash',
				(string) file_get_contents( $file ),
				basename( $file ) . ' にモデルIDが直書きされています'
			);
		}
	}

	/** 2. 無料枠で使える最新の Flash を選ぶ */
	public function test_selects_latest_free_tier_flash() {
		$this->enable_http_mock();

		$this->assertSame( 'gemini-3.7-flash', Node_AI_Fact_Check_Models::select() );
	}

	/** 3. 新しい Flash が追加されたら追従する */
	public function test_follows_newly_released_flash_model() {
		$this->enable_http_mock();
		$this->assertSame( 'gemini-3.7-flash', Node_AI_Fact_Check_Models::select() );

		// Google 側に新世代が追加された状況を再現する。
		$this->expose_future_model = true;
		Node_AI_Fact_Check_Models::clear_cache();

		$this->assertSame( 'gemini-4.0-flash', Node_AI_Fact_Check_Models::select() );
	}

	/** 4. 有料モデルへ自動で移行しない */
	public function test_never_falls_back_to_paid_models() {
		$this->enable_http_mock();

		$candidates = Node_AI_Fact_Check_Models::candidates();

		$this->assertNotContains( 'gemini-2.5-pro', $candidates );
		$this->assertNotContains( 'gemini-3.1-pro-preview', $candidates );

		// 手動設定でも、明示許可がなければ有料モデルは使わない。
		update_option( Node_AI_Fact_Check_Models::MODE_OPTION, 'manual' );
		update_option( Node_AI_Fact_Check_Models::MANUAL_OPTION, 'gemini-2.5-pro' );

		$this->assertSame( 'gemini-3.7-flash', Node_AI_Fact_Check_Models::select() );

		// 管理者が明示的に許可したときだけ使う。
		update_option( Node_AI_Fact_Check_Models::ALLOW_PAID_OPTION, '1' );
		$this->assertSame( 'gemini-2.5-pro', Node_AI_Fact_Check_Models::select() );
	}

	/** 5. Preview / Experimental / latest エイリアスへ無条件に切り替えない */
	public function test_excludes_preview_experimental_and_latest_alias() {
		$this->enable_http_mock();

		$candidates = Node_AI_Fact_Check_Models::candidates();

		$this->assertNotContains( 'gemini-3-flash-preview', $candidates );
		$this->assertNotContains( 'gemini-2.0-flash-exp', $candidates );
		$this->assertNotContains( 'gemini-flash-latest', $candidates );

		$this->assertSame( 'alias', Node_AI_Fact_Check_Models::classify( 'gemini-flash-latest' ) );
		$this->assertSame( 'preview', Node_AI_Fact_Check_Models::classify( 'gemini-3-flash-preview' ) );
		$this->assertSame( 'stable', Node_AI_Fact_Check_Models::classify( 'gemini-3.7-flash' ) );
	}

	// ------------------------------------------------------------------
	// 6〜9: 失敗時の挙動
	// ------------------------------------------------------------------

	/** 6. 最新モデルが 404 のときは無料の次モデルへフォールバックする */
	public function test_falls_back_to_next_free_model_on_404() {
		$this->enable_http_mock();
		$this->model_status['gemini-3.7-flash'] = 404;

		$result = Node_AI_Fact_Check_Runner::run( 'テスト本文', 'テスト', $this->user_id, 0 );

		$this->assertNotWPError( $result );
		$this->assertSame( 'gemini-3.6-flash', $result['context']['model'] );
		$this->assertContains( 'gemini-3.7-flash', Node_AI_Fact_Check_Models::get_retired() );
	}

	/** 7. 429 で無限に再試行しない */
	public function test_429_does_not_retry_forever() {
		$this->enable_http_mock();

		foreach ( $this->all_mock_models() as $id ) {
			$this->model_status[ $id ] = 429;
		}

		$result = Node_AI_Fact_Check_Runner::run( 'テスト本文', 'テスト', $this->user_id, 0 );

		$this->assertWPError( $result );
		$this->assertLessThanOrEqual( 15, count( $this->requests ), '再試行回数が上限を超えています' );
		$this->assertNotEmpty( $this->requests );

		// 429 は同じモデルで粘らず、無料枠の別モデル（Flash-Lite を含む）まで順に試す
		$tried = array_unique( array_column( $this->requests, 'model' ) );
		$this->assertGreaterThanOrEqual( 3, count( $tried ) );
		$this->assertContains( 'gemini-3.5-flash-lite', $tried, 'Flash-Lite まで辿れていません' );
	}

	/** 8. 無料モデルが全滅なら安全に中断する（有料へは行かない） */
	public function test_stops_safely_when_no_free_model_is_available() {
		$this->enable_http_mock();

		add_filter( 'node_ai_fc_is_free_tier_model', '__return_false' );
		$selected = Node_AI_Fact_Check_Models::select();
		$result   = Node_AI_Fact_Check_Runner::run( 'テスト本文', 'テスト', $this->user_id, 0 );
		remove_filter( 'node_ai_fc_is_free_tier_model', '__return_false' );

		$this->assertWPError( $selected );
		$this->assertSame( 'node_ai_fc_no_model', $selected->get_error_code() );
		$this->assertWPError( $result );

		// 有料モデルへは一切リクエストしていない。
		foreach ( $this->requests as $request ) {
			$this->assertStringNotContainsString( 'pro', (string) $request['model'] );
		}
	}

	/** 9. Google 検索が使えなくても処理自体は動く */
	public function test_runs_without_google_search_grounding() {
		$this->enable_http_mock();
		$this->grounding_status = 429;

		$result = Node_AI_Fact_Check_Runner::run( 'テスト本文', 'テスト', $this->user_id, 0 );

		$this->assertNotWPError( $result );
		$this->assertFalse( $result['context']['grounded'] );
		$this->assertNotEmpty( $result['context']['notices'] );

		// 検索が使えないことを理由に有料モデルへ切り替えていない。
		foreach ( $this->requests as $request ) {
			$this->assertStringNotContainsString( 'pro', (string) $request['model'] );
		}
	}

	/** 9-d. 検索つきが 429 でも、検索を諦める前に次の無料モデルで検索を試す */
	public function test_search_is_retried_on_next_free_model_before_giving_up() {
		$this->enable_http_mock();
		$this->grounding_status = 429;

		$result = Node_AI_Fact_Check_Runner::run( 'Nintendo Switch 2 の最新仕様について。', 'Switch 2', $this->user_id, 0 );

		$this->assertNotWPError( $result );

		// 検索つきの試行が複数モデルにまたがっている
		$grounded_models = array();
		foreach ( $this->requests as $request ) {
			if ( ! empty( $request['tools'] ) ) {
				$grounded_models[ (string) $request['model'] ] = true;
			}
		}

		$this->assertGreaterThanOrEqual( 2, count( $grounded_models ), '1モデルで検索を諦めています' );
		$this->assertArrayHasKey( 'gemini-3.7-flash', $grounded_models, '最新 Flash で検索を試していません' );

		// 全滅したときだけ検索なしの暫定結果になる
		$this->assertFalse( $result['context']['grounded'] );
		$this->assertStringContainsString( '暫定', implode( ' ', $result['context']['notices'] ) );
	}

	/** 9-e. 「検索できないなら中止」設定では暫定結果を作らない */
	public function test_search_required_policy_aborts_instead_of_degrading() {
		$this->enable_http_mock();
		$this->grounding_status = 429;
		update_option( Node_AI_Fact_Check_Runner::GROUNDING_OPTION, 'required' );

		$result = Node_AI_Fact_Check_Runner::run( 'Nintendo Switch 2 の最新仕様について。', 'Switch 2', $this->user_id, 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'node_ai_fc_search_unavailable', $result->get_error_code() );

		// 検索なしのリクエストは1本も送っていない
		foreach ( $this->requests as $request ) {
			$this->assertTrue( (bool) $request['tools'], '検索なしで実行してしまっています' );
		}
	}

	/** 9-f. 既定は「常に検索つき」。旧設定 auto も同じ扱い */
	public function test_default_search_policy_is_always() {
		delete_option( Node_AI_Fact_Check_Runner::GROUNDING_OPTION );
		$this->assertSame( 'always', Node_AI_Fact_Check_Runner::search_policy() );

		update_option( Node_AI_Fact_Check_Runner::GROUNDING_OPTION, 'auto' );
		$this->assertSame( 'always', Node_AI_Fact_Check_Runner::search_policy() );

		// 月あたりの自主上限は公式の無料枠（5,000回/月）の内側
		$this->assertLessThan( 5000, Node_AI_Fact_Check_Runner::grounding_cap() );
	}

	/** 9-b. 設定で検索をオフにしたら検索付きリクエストを送らない */
	public function test_grounding_can_be_disabled_by_option() {
		$this->enable_http_mock();
		update_option( Node_AI_Fact_Check_Runner::GROUNDING_OPTION, 'off' );

		$result = Node_AI_Fact_Check_Runner::run( 'テスト本文', 'テスト', $this->user_id, 0 );

		$this->assertNotWPError( $result );
		foreach ( $this->requests as $request ) {
			$this->assertFalse( $request['tools'] );
		}
	}

	/** 9-c. 月次の自主上限を超えたら検索なしへ落とす（課金を発生させない） */
	public function test_grounding_stops_at_monthly_self_cap() {
		update_option(
			Node_AI_Fact_Check_Runner::GROUNDING_USAGE_OPTION,
			array(
				'month' => current_time( 'Y-m' ),
				'count' => Node_AI_Fact_Check_Runner::grounding_cap(),
			)
		);

		$state = array( 'notices' => array() );

		$this->assertFalse( Node_AI_Fact_Check_Runner::grounding_allowed( $state ) );
		$this->assertNotEmpty( $state['notices'] );
	}

	// ------------------------------------------------------------------
	// 10〜15: 判定ロジック
	// ------------------------------------------------------------------

	/** 10. 根拠不足を理由に「不正確」と断定しない */
	public function test_unevidenced_claim_is_not_marked_incorrect() {
		$claim = node_ai_fc_normalize_claim(
			array(
				'claim'      => 'この製品の価格は 49,980 円である',
				'status'     => 'likely_incorrect',
				'confidence' => 'high',
				'note'       => '私の知識と違う',
				'evidence'   => array(),
			),
			array( 'grounded' => true )
		);

		$this->assertSame( 'uncertain', $claim['status'] );
	}

	/** 11. 否定命題を内部知識だけで断定しない */
	public function test_negative_claim_without_evidence_becomes_unverifiable() {
		$claim = node_ai_fc_normalize_claim(
			array(
				'claim'      => 'Nintendo Switch 2 は未発表である',
				'status'     => 'likely_incorrect',
				'confidence' => 'high',
				'note'       => '公式情報がないため',
				'evidence'   => array(),
			),
			array( 'grounded' => false )
		);

		$this->assertSame( 'unverifiable', $claim['status'] );
		$this->assertStringContainsString( '検証困難', $claim['note'] );

		$this->assertTrue( node_ai_fc_is_negative_claim( 'サービス終了している' ) );
		$this->assertTrue( node_ai_fc_is_negative_claim( 'AAC には対応していない' ) );
		$this->assertFalse( node_ai_fc_is_negative_claim( '本体上部に USB-C 端子がある' ) );
	}

	/** 12. 筆者の主観と客観的事実を区別する */
	public function test_subjective_statement_is_not_fact_checked() {
		$claim = node_ai_fc_normalize_claim(
			array(
				'claim'      => '筆者としては音質面にも物足りなさを感じていました',
				'status'     => 'likely_incorrect',
				'confidence' => 'high',
				'note'       => 'SBC でも音質は十分',
				'evidence'   => array(),
			),
			array( 'grounded' => true )
		);

		$this->assertSame( 'opinion', $claim['claim_type'] );
		$this->assertNotSame( 'likely_incorrect', $claim['status'] );

		// 感想はリスク計算に数えない。
		$this->assertSame( 'low', node_ai_fc_compute_overall_risk( array( $claim ), array( 'grounded' => true ) ) );
	}

	/** 13. ひとつの誤った前提から複数の誤判定を生成しない */
	public function test_single_unverified_premise_does_not_cascade() {
		$context = array(
			'grounded' => true,
			'premises' => array(
				array(
					'premise' => 'Nintendo Switch 2 は存在する',
					'status'  => 'unverified',
				),
			),
		);

		$claims = array();
		foreach ( array( '上部 USB-C 端子がある', 'カメラが使える', 'AAC に対応する' ) as $text ) {
			$claims[] = node_ai_fc_normalize_claim(
				array(
					'claim'      => $text,
					'status'     => 'likely_incorrect',
					'depends_on' => 0,
					'confidence' => 'high',
					'note'       => '製品が未発表のため',
					'evidence'   => array(),
				),
				$context
			);
		}

		foreach ( $claims as $claim ) {
			$this->assertNotSame( 'likely_incorrect', $claim['status'] );
		}

		$this->assertNotSame( 'high', node_ai_fc_compute_overall_risk( $claims, $context ) );
	}

	/** 13-b. 公式ページを引用していても、本文に裏づけが無ければ「誤り」と断定しない */
	public function test_incorrect_verdict_requires_support_in_cited_page() {
		$context = array(
			'grounded'      => false,
			'evidence_text' => array(
				'https://blog.example/gemini' => 'Gemini 3.7 Flash を発表しました。導入価格は従来の半額です。',
			),
		);

		$claim = node_ai_fc_normalize_claim(
			array(
				'claim'      => '「Gemini Spark」でも利用できる',
				'status'     => 'likely_incorrect',
				'confidence' => 'high',
				// 引用元ページには存在しない固有名を根拠にした指摘（モデルの内部知識由来）
				'note'       => '公式の名称は「Gemini Advanced」であり、「Gemini Spark」というサービスは存在しません。',
				'evidence'   => array(
					array(
						'url'      => 'https://blog.example/gemini',
						'official' => true,
					),
				),
			),
			$context
		);

		$this->assertNotSame( 'likely_incorrect', $claim['status'] );
		$this->assertTrue( ! empty( $claim['evidence_unsupported'] ) );
		$this->assertStringContainsString( '裏づける記述が見つからない', $claim['note'] );

		// 引用元本文に実際に書かれている指摘はそのまま残す
		$supported = node_ai_fc_normalize_claim(
			array(
				'claim'      => '導入価格は据え置きである',
				'status'     => 'likely_incorrect',
				'confidence' => 'high',
				'note'       => '公式ブログには「導入価格は従来の半額です」と記載されています。',
				'evidence'   => array(
					array(
						'url'      => 'https://blog.example/gemini',
						'official' => true,
					),
				),
			),
			$context
		);

		$this->assertSame( 'likely_incorrect', $supported['status'] );
	}

	/** 14. 検証困難項目だけで記事全体を高リスクにしない */
	public function test_unverifiable_claims_do_not_make_article_high_risk() {
		$claims = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$claims[] = array(
				'claim'      => '検証できない主張 ' . $i,
				'status'     => 'unverifiable',
				'claim_type' => 'fact',
				'depends_on' => -1,
				'evidence'   => array(),
			);
		}

		$this->assertSame( 'low', node_ai_fc_compute_overall_risk( $claims, array( 'grounded' => false ) ) );
	}

	/** 15. 公式一次情報がある場合はモデル内部知識より優先する */
	public function test_official_source_drives_the_verdict() {
		$claim = node_ai_fc_normalize_claim(
			array(
				'claim'      => '本体価格は 100 円である',
				'status'     => 'likely_incorrect',
				'confidence' => 'high',
				'note'       => '公式サイトの価格と矛盾',
				'evidence'   => array(
					array(
						'url'      => 'https://www.nintendo.co.jp/hardware/switch2/',
						'title'    => '公式',
						'official' => true,
					),
				),
			),
			array( 'grounded' => true )
		);

		// 公式根拠つきの矛盾はそのまま「おそらく不正確」として残る。
		$this->assertSame( 'likely_incorrect', $claim['status'] );
		$this->assertSame( 'high', node_ai_fc_compute_overall_risk( array( $claim ), array( 'grounded' => true ) ) );

		// プロンプトにも一次情報優先の指示が入っている。
		$this->assertStringContainsString( '公式情報を優先', Node_AI_Fact_Check_Runner::preamble() );
		$this->assertTrue( Node_AI_Fact_Check_Sources::is_official_host( 'www.nintendo.co.jp' ) );
		$this->assertFalse( Node_AI_Fact_Check_Sources::is_official_host( 'ja.wikipedia.org' ) );
	}

	// ------------------------------------------------------------------
	// 16: Nintendo Switch 2 回帰テスト
	// ------------------------------------------------------------------

	/** 16. 今回の誤判定（Switch 2 は未発表 → 全項目否定 → 高リスク）が再発しない */
	public function test_nintendo_switch_2_regression() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Nintendo Switch 2 を1か月使って分かったこと',
				'post_content' => 'Nintendo Switch 2 の上部には USB-C 端子があります。筆者としては音質面に物足りなさを感じていました。',
			)
		);

		// 事故当時の Gemini 出力をそのまま再現する。
		$result = array(
			'text'      => wp_json_encode(
				array(
					'summary'      => '記事の前提そのものが誤っている可能性があります。',
					'overall_risk' => 'high',
					'claims'       => array(
						array(
							'claim'      => 'Nintendo Switch 2 は発売済みの製品である',
							'claim_type' => 'premise',
							'depends_on' => 0,
							'status'     => 'likely_incorrect',
							'confidence' => 'high',
							'note'       => 'Nintendo Switch 2 は未発表であり、公式情報がありません。',
							'evidence'   => array(),
						),
						array(
							'claim'      => '本体上部に USB-C 端子がある',
							'claim_type' => 'fact',
							'depends_on' => 0,
							'status'     => 'likely_incorrect',
							'confidence' => 'high',
							'note'       => '製品が未発表のため検証困難です。',
							'evidence'   => array(),
						),
						array(
							'claim'      => 'Switch 2 のカメラが利用できる',
							'claim_type' => 'fact',
							'depends_on' => 0,
							'status'     => 'likely_incorrect',
							'confidence' => 'high',
							'note'       => 'カメラの存在は確認できません。',
							'evidence'   => array(),
						),
						array(
							'claim'      => 'Bluetooth オーディオが AAC に対応している',
							'claim_type' => 'fact',
							'depends_on' => 0,
							'status'     => 'likely_incorrect',
							'confidence' => 'high',
							'note'       => 'AAC 対応は確認できません。',
							'evidence'   => array(),
						),
						array(
							'claim'      => '筆者としては音質面に物足りなさを感じていた',
							'claim_type' => 'fact',
							'depends_on' => -1,
							'status'     => 'likely_incorrect',
							'confidence' => 'medium',
							'note'       => 'SBC でも音質は十分であり誤りです。',
							'evidence'   => array(),
						),
					),
				),
				JSON_UNESCAPED_UNICODE
			),
			'grounding' => array(),
			'context'   => array(
				'grounded' => false,
				'model'    => 'gemini-3.7-flash',
				'premises' => array(
					array(
						'premise' => 'Nintendo Switch 2 は現在存在し発売されている製品である',
						'status'  => 'unverified',
					),
				),
				'notices'  => array( 'Google 検索の利用枠に達したため、検索なしで実行しました。' ),
			),
		);

		$payload = node_ai_store_fact_check_result( $post_id, $result );

		$this->assertNotWPError( $payload );

		// 記事全体が「高リスク」に昇格しないこと。
		$this->assertNotSame( 'high', $payload['overall_risk'] );

		// 根拠のない否定的断定が「おそらく不正確」のまま残っていないこと。
		foreach ( $payload['claims'] as $claim ) {
			$this->assertNotSame(
				'likely_incorrect',
				$claim['status'],
				'根拠なしの断定が残っています: ' . $claim['claim']
			);
		}

		// 「未発表」等の否定命題は検証困難として扱われること。
		$this->assertSame( 'unverifiable', $payload['claims'][0]['status'] );

		// 筆者の感想は事実判定の対象外になること。
		$opinion = end( $payload['claims'] );
		$this->assertSame( 'opinion', $opinion['claim_type'] );

		// 実行条件（検索なし・使用モデル）が内部に残ること。
		$this->assertSame( 'gemini-3.7-flash', $payload['model'] );
		$this->assertNotEmpty( $payload['notices'] );
	}

	/** 実行時に現在日時をプロンプトへ渡している */
	public function test_prompt_includes_current_datetime() {
		$preamble = Node_AI_Fact_Check_Runner::preamble();

		$this->assertStringContainsString( '現在日時', $preamble );
		$this->assertStringContainsString( wp_date( 'Y-m-d' ), $preamble );
		$this->assertStringContainsString( '知らないことは「存在しないこと」の根拠になりません', $preamble );
	}

	/** 根拠情報（URL / 公式か / 取得日時）が内部データとして残る */
	public function test_evidence_is_persisted_internally() {
		$post_id = $this->factory->post->create();

		$payload = node_ai_store_fact_check_result(
			$post_id,
			array(
				'text'      => wp_json_encode(
					array(
						'summary' => 'ok',
						'claims'  => array(
							array(
								'claim'      => '公式仕様に記載がある',
								'claim_type' => 'fact',
								'status'     => 'correct',
								'confidence' => 'high',
								'note'       => '公式',
								'evidence'   => array(
									array(
										'url'      => 'https://www.nintendo.co.jp/hardware/switch2/spec/',
										'title'    => '仕様',
										'official' => true,
									),
								),
							),
						),
					),
					JSON_UNESCAPED_UNICODE
				),
				'grounding' => array(),
				'context'   => array( 'grounded' => true ),
			)
		);

		$this->assertNotWPError( $payload );

		$evidence = $payload['claims'][0]['evidence'][0];
		$this->assertSame( 'https://www.nintendo.co.jp/hardware/switch2/spec/', $evidence['url'] );
		$this->assertTrue( $evidence['official'] );
		$this->assertNotEmpty( $evidence['checked_at'] );
	}

	// ------------------------------------------------------------------
	// API 呼び出しの節約（前提検証のキャッシュとスキップ）
	// ------------------------------------------------------------------

	/** 同じ内容の再チェックでは前提検証を再実行しない */
	public function test_premise_result_is_cached_between_runs() {
		$this->enable_http_mock();

		$content = 'Nintendo Switch 2 は2025年に発売され、本体上部に USB-C 端子があります。';

		$first = Node_AI_Fact_Check_Runner::run( $content, 'Switch 2 レビュー', $this->user_id, 0 );
		$this->assertNotWPError( $first );

		$first_calls = $this->requests;
		$this->assertSame( 2, count( $first_calls ), '初回は前提と主張で2回呼ぶ' );
		$this->assertTrue( $first_calls[0]['premise'] );

		$this->requests = array();

		$second = Node_AI_Fact_Check_Runner::run( $content, 'Switch 2 レビュー', $this->user_id, 0 );
		$this->assertNotWPError( $second );

		// 2回目は主張の検証だけ（前提はキャッシュを再利用）
		$this->assertSame( 1, count( $this->requests ) );
		$this->assertFalse( $this->requests[0]['premise'] );
		$this->assertNotEmpty( $second['context']['premises'] );
		$this->assertStringContainsString( '再利用', implode( ' ', $second['context']['notices'] ) );
	}

	/** 時点依存の話題が無い記事では前提検証をそもそも行わない */
	public function test_premise_check_is_skipped_for_timeless_content() {
		$this->enable_http_mock();

		$this->assertFalse( Node_AI_Fact_Check_Runner::needs_premise_check( 'きょうは天気がよく、散歩が気持ちよかった。' ) );
		$this->assertTrue( Node_AI_Fact_Check_Runner::needs_premise_check( 'Nintendo Switch 2 を買いました' ) );
		$this->assertTrue( Node_AI_Fact_Check_Runner::needs_premise_check( '最新の仕様に対応しています' ) );

		$result = Node_AI_Fact_Check_Runner::run( 'きょうは天気がよく、散歩が気持ちよかった。', 'さんぽ', $this->user_id, 0 );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, count( $this->requests ) );
		$this->assertFalse( $this->requests[0]['premise'] );
	}

	/** 前提が確認できなかった場合はキャッシュを長く持たない（次回やり直せる） */
	public function test_unverified_premise_is_not_cached_for_long() {
		$this->enable_http_mock();

		$this->premise_override = array(
			'premises' => array(
				array(
					'premise'    => '対象製品は実在する',
					'status'     => 'unverified',
					'confidence' => 'low',
					'basis'      => '根拠なし',
					'evidence'   => array(),
				),
			),
		);

		$content = 'Nintendo Switch 2 の最新仕様について。';
		$result  = Node_AI_Fact_Check_Runner::run( $content, 'Switch 2', $this->user_id, 0 );
		$this->assertNotWPError( $result );

		// 「未確認」は1時間で切れる（確認済みの7日と区別している）
		$timeout = get_option( '_transient_timeout_' . $this->find_premise_transient() );
		$this->assertNotEmpty( $timeout );
		$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS + 5, (int) $timeout );
	}

	private function find_premise_transient(): string {
		global $wpdb;

		$name = (string) $wpdb->get_var(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_node_ai_fc_premises_%' LIMIT 1"
		);

		return str_replace( '_transient_', '', $name );
	}

	// ------------------------------------------------------------------
	// モデル一覧の表示と自動更新
	// ------------------------------------------------------------------

	/** 選択肢には Pro や Preview を出さない（選んでも使えないため） */
	public function test_usable_options_exclude_pro_and_preview() {
		$this->enable_http_mock();

		$options = Node_AI_Fact_Check_Models::usable_options( $this->user_id );

		$this->assertArrayHasKey( 'gemini-3.7-flash', $options );
		$this->assertArrayHasKey( 'gemini-3.5-flash-lite', $options );

		foreach ( array_keys( $options ) as $id ) {
			$this->assertStringNotContainsString( 'pro', (string) $id );
			$this->assertStringNotContainsString( 'preview', (string) $id );
			$this->assertStringNotContainsString( 'latest', (string) $id );
		}

		// 一時的に使えないモデルも出さない
		Node_AI_Fact_Check_Models::record_unavailable( 'gemini-3.7-flash', 300 );
		$this->assertArrayNotHasKey( 'gemini-3.7-flash', Node_AI_Fact_Check_Models::usable_options( $this->user_id ) );
	}

	/** 選択肢から消えた古い保存値（Pro / 提供終了）は使えるモデルへ置き換える */
	public function test_unusable_saved_model_is_replaced_at_call_time() {
		$this->enable_http_mock();

		$this->assertTrue( Node_AI_Fact_Check_Models::is_known_unusable( 'gemini-2.5-pro' ) );
		$this->assertFalse( Node_AI_Fact_Check_Models::is_known_unusable( 'gemini-3.7-flash' ) );

		// 提供終了として記録済みのモデルも「使えない」
		Node_AI_Fact_Check_Models::record_retired( 'gemini-3.5-flash' );
		$this->assertTrue( Node_AI_Fact_Check_Models::is_known_unusable( 'gemini-3.5-flash' ) );

		// user_meta に Pro が残っていても、実際の呼び出しは無料 Flash に置き換わる
		update_user_meta( $this->user_id, 'node_gemini_api_key', 'dummy_key' );
		update_user_meta( $this->user_id, 'node_gemini_model', 'gemini-2.5-pro@high' );

		$api    = new Node_Gemini_API( $this->user_id );
		$result = $api->generate_content( 'test', array( 'system_instruction' => '基礎前提', 'feature' => 'fact_check_premise' ) );

		$this->assertNotWPError( $result );
		$this->assertNotEmpty( $this->requests );
		$this->assertSame( 'gemini-3.7-flash', $this->requests[0]['model'] );
	}

	/** モデル一覧を日次で自動更新できる（cron から呼ばれる） */
	public function test_catalog_auto_refresh() {
		$this->enable_http_mock();

		Node_AI_Fact_Check_Models::fetch_catalog();
		$this->assertNotContains( 'gemini-4.0-flash', Node_AI_Fact_Check_Models::candidates() );

		// Google 側に新モデルが追加され、日次更新が走った状況
		$this->expose_future_model = true;
		Node_AI_Fact_Check_Models::refresh_catalog();

		$this->assertContains( 'gemini-4.0-flash', Node_AI_Fact_Check_Models::candidates() );
		$this->assertSame( 'gemini-4.0-flash', Node_AI_Fact_Check_Models::select() );
	}

	// ------------------------------------------------------------------
	// 公式（一次情報）判定
	// ------------------------------------------------------------------

	/** プリセットのドメインは設定なしで公式として扱う */
	public function test_preset_official_hosts_work_without_configuration() {
		delete_option( Node_AI_Fact_Check_Sources::OFFICIAL_HOSTS_OPTION );

		$presets = Node_AI_Fact_Check_Sources::preset_official_hosts();
		$this->assertGreaterThan( 20, count( $presets ) );

		foreach ( array( 'www.nintendo.co.jp', 'blog.google', 'support.apple.com', 'www.playstation.com', 'developer.nvidia.com' ) as $host ) {
			$this->assertTrue( Node_AI_Fact_Check_Sources::is_official_host( $host ), $host . ' がプリセットで公式になっていません' );
		}

		// 除外リスト（まとめ・SNS・EC）はプリセットより優先される
		$this->assertFalse( Node_AI_Fact_Check_Sources::is_official_host( 'ja.wikipedia.org' ) );
		$this->assertFalse( Node_AI_Fact_Check_Sources::is_official_host( 'www.amazon.co.jp' ) );
		// 無関係なドメインは公式にしない
		$this->assertFalse( Node_AI_Fact_Check_Sources::is_official_host( 'matome-blog.example' ) );
	}

	/** 管理画面で登録したドメインは公式として扱う（サブドメインも同一扱い） */
	public function test_configured_official_hosts() {
		update_option( Node_AI_Fact_Check_Sources::OFFICIAL_HOSTS_OPTION, "https://example-maker.com/products\nfoo.co.jp" );

		$this->assertTrue( Node_AI_Fact_Check_Sources::is_official_host( 'example-maker.com' ) );
		$this->assertTrue( Node_AI_Fact_Check_Sources::is_official_host( 'support.example-maker.com' ) );
		$this->assertTrue( Node_AI_Fact_Check_Sources::is_official_host( 'www.foo.co.jp' ) );
		$this->assertFalse( Node_AI_Fact_Check_Sources::is_official_host( 'example-maker.net' ) );

		$this->assertSame( 'example-maker.com', Node_AI_Fact_Check_Sources::registrable_domain( 'a.b.example-maker.com' ) );
		$this->assertSame( 'foo.co.jp', Node_AI_Fact_Check_Sources::registrable_domain( 'www.foo.co.jp' ) );
	}

	/** 「自分のサイトだ」と名乗るだけでは一次情報にしない（報道メディアも同じ宣言をするため） */
	public function test_self_declared_organization_is_not_treated_as_official() {
		$html = '<html><head><meta property="og:site_name" content="Automaton Media"><script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"Automaton Media","url":"https://automaton-media.example/"}</script></head><body>ニュース</body></html>';

		$verdict = Node_AI_Fact_Check_Sources::judge_source( 'automaton-media.example', $html );

		$this->assertFalse( $verdict['official'], '自称だけで一次情報に格上げしています' );
		$this->assertSame( 'self_published', $verdict['source_type'] );
	}

	/** 公式サイト情報から引いたホストは一次情報として扱う */
	public function test_discovered_host_is_treated_as_official() {
		update_option( Node_AI_Fact_Check_Sources::ENABLED_OPTION, '1' );
		add_filter( 'pre_http_request', array( $this, 'mock_official_page' ), 5, 3 );

		$plain = Node_AI_Fact_Check_Sources::fetch_urls( array( 'https://www.nintendo.com/jp/switch2/' ), 3 );
		$known = Node_AI_Fact_Check_Sources::fetch_urls(
			array( 'https://www.nintendo.com/jp/switch2/' ),
			3,
			array( 'www.nintendo.com' )
		);

		remove_filter( 'pre_http_request', array( $this, 'mock_official_page' ), 5 );

		$this->assertCount( 1, $known );
		$this->assertTrue( $known[0]['official'] );
		$this->assertCount( 1, $plain );
	}

	/** 企業の公式ブログ（NewsArticle スキーマつき）は一次情報として扱う */
	public function test_corporate_blog_with_news_article_schema_is_official() {
		// blog.google の実データと同じ構成: NewsArticle + 自社ドメインの Organization
		$html = '<html><head><meta property="og:site_name" content="Google"><script type="application/ld+json">{"@type":"NewsArticle","publisher":{"@type":"Organization","name":"Google","url":"https://blog.google/"}}</script></head></html>';

		$verdict = Node_AI_Fact_Check_Sources::judge_source( 'blog.google', $html );

		$this->assertTrue( $verdict['official'], '公式ブログが報道扱いになっています' );
		$this->assertSame( 'official_page', $verdict['source_type'] );

		// blog. サブドメインはホスト名だけでも一次情報とみなす
		$this->assertTrue( Node_AI_Fact_Check_Sources::is_official_host( 'blog.example.com' ) );
		// ただしブログプラットフォームは対象外
		$this->assertFalse( Node_AI_Fact_Check_Sources::is_official_host( 'blog.hatenablog.com' ) );
	}

	/** 大きい公式ページを黙って捨てない（400KB 固定で落ちていた回帰） */
	public function test_large_official_page_is_not_dropped() {
		update_option( Node_AI_Fact_Check_Sources::ENABLED_OPTION, '1' );

		$this->large_page_bytes = 900000;
		add_filter( 'pre_http_request', array( $this, 'mock_large_official_page' ), 5, 3 );

		$sources = Node_AI_Fact_Check_Sources::fetch_urls( array( 'https://blog.example-maker.com/news/1' ), 3 );

		remove_filter( 'pre_http_request', array( $this, 'mock_large_official_page' ), 5 );

		$this->assertCount( 1, $sources, '大きいページが根拠から落ちています' );
		$this->assertTrue( $sources[0]['official'] );
		$this->assertNotEmpty( $sources[0]['text'] );
	}

	/** @var int */
	public $large_page_bytes = 0;

	/**
	 * 大きな公式ページのモック。
	 *
	 * @param mixed                $preempt 既定値。
	 * @param array<string, mixed> $args    引数。
	 * @param string               $url     URL。
	 */
	public function mock_large_official_page( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'example-maker.com' ) ) {
			return $preempt;
		}

		$body = str_ends_with( $url, '/robots.txt' )
			? "User-agent: *\nDisallow: /admin/\n"
			: '<html><head><title>公式のお知らせ</title></head><body><p>新製品を本日発売しました。</p>'
				. str_repeat( '<span>padding</span>', (int) ( $this->large_page_bytes / 20 ) ) . '</body></html>';

		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** 報道機関は一次情報として扱わない */
	public function test_news_organization_is_not_official() {
		$html = '<html><head><meta property="og:site_name" content="Techpress"><script type="application/ld+json">{"@type":"NewsMediaOrganization","name":"Techpress","url":"https://techpress.example/"}</script></head></html>';

		$verdict = Node_AI_Fact_Check_Sources::judge_source( 'techpress.example', $html );

		$this->assertFalse( $verdict['official'] );
		$this->assertSame( 'news', $verdict['source_type'] );
	}

	/** まとめ・SNS・百科事典は登録より優先して除外する */
	public function test_non_primary_hosts_take_precedence() {
		update_option( Node_AI_Fact_Check_Sources::OFFICIAL_HOSTS_OPTION, 'wikipedia.org' );

		$html    = '<html><head><script type="application/ld+json">{"@type":"Organization","url":"https://ja.wikipedia.org/"}</script></head></html>';
		$verdict = Node_AI_Fact_Check_Sources::judge_source( 'ja.wikipedia.org', $html );

		$this->assertFalse( $verdict['official'] );
		$this->assertSame( 'non_primary', $verdict['source_type'] );
	}

	// ------------------------------------------------------------------
	// 公式サイトの自動発見
	// ------------------------------------------------------------------

	/** 記事タイトルから主題（製品名）を取り出す */
	public function test_entity_extraction() {
		$entities = Node_AI_Fact_Check_Discovery::extract_entities(
			'Nintendo Switch 2 を1か月使って分かったこと',
			'本体上部には USB-C 端子があります。'
		);

		$this->assertContains( 'Nintendo Switch 2', $entities );

		// タイトルの飾り語は主題として問い合わせない
		$this->assertSame( '', Node_AI_Fact_Check_Discovery::clean_entity( 'レビュー' ) );
		$this->assertSame( '', Node_AI_Fact_Check_Discovery::clean_entity( 'the' ) );
	}

	/** 公開API（Wikidata）から公式サイトURLを引ける */
	public function test_official_url_discovery() {
		add_filter( 'pre_http_request', array( $this, 'mock_wikidata' ), 10, 3 );

		$urls = Node_AI_Fact_Check_Discovery::official_urls_for( 'Nintendo Switch 2' );

		$this->assertSame( array( 'https://www.nintendo.com/jp/switch2/' ), $urls );

		// 2回目はキャッシュから返す（外部APIを叩き直さない）
		$this->wikidata_calls = 0;
		$again                = Node_AI_Fact_Check_Discovery::official_urls_for( 'Nintendo Switch 2' );

		$this->assertSame( $urls, $again );
		$this->assertSame( 0, $this->wikidata_calls );

		remove_filter( 'pre_http_request', array( $this, 'mock_wikidata' ), 10 );
	}

	/** 記事に公式リンクが無くても、自動発見した公式ページが根拠になる */
	public function test_discovered_official_page_becomes_evidence() {
		update_option( Node_AI_Fact_Check_Sources::ENABLED_OPTION, '1' );

		add_filter( 'pre_http_request', array( $this, 'mock_wikidata' ), 5, 3 );
		add_filter( 'pre_http_request', array( $this, 'mock_official_page' ), 6, 3 );
		$this->enable_http_mock();

		$result = Node_AI_Fact_Check_Runner::run(
			'Nintendo Switch 2 の上部には USB-C 端子があります。',
			'Nintendo Switch 2 を1か月使って分かったこと',
			$this->user_id,
			0
		);

		remove_filter( 'pre_http_request', array( $this, 'mock_wikidata' ), 5 );
		remove_filter( 'pre_http_request', array( $this, 'mock_official_page' ), 6 );

		$this->assertNotWPError( $result );

		$evidence = $result['context']['evidence'];
		$this->assertNotEmpty( $evidence, '公式サイトを自動発見できていません' );
		$this->assertSame( 'https://www.nintendo.com/jp/switch2/', $evidence[0]['url'] );
		$this->assertTrue( $evidence[0]['official'] );
		$this->assertSame( 'discovered_official', $evidence[0]['source_type'] );
		$this->assertStringContainsString( '公式サイトを自動で参照', implode( ' ', $result['context']['notices'] ) );
	}

	/** 設定でオフにすれば自動発見しない */
	public function test_discovery_can_be_disabled() {
		update_option( Node_AI_Fact_Check_Discovery::ENABLED_OPTION, '0' );

		$this->assertFalse( Node_AI_Fact_Check_Discovery::is_enabled() );
		$this->assertSame( array(), Node_AI_Fact_Check_Discovery::discover( 'Nintendo Switch 2', '本文' ) );
	}

	/** @var int */
	public $wikidata_calls = 0;

	/**
	 * Wikidata API のモック。
	 *
	 * @param mixed                $preempt 既定値。
	 * @param array<string, mixed> $args    引数。
	 * @param string               $url     URL。
	 */
	public function mock_wikidata( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'wikidata.org' ) ) {
			return $preempt;
		}

		$this->wikidata_calls++;

		if ( false !== strpos( $url, 'wbsearchentities' ) ) {
			$body = wp_json_encode(
				array(
					'search' => array(
						array(
							'id'    => 'Q122761124',
							'label' => 'Nintendo Switch 2',
						),
					),
				)
			);
		} else {
			$body = wp_json_encode(
				array(
					'claims' => array(
						'P856' => array(
							array(
								'rank'     => 'normal',
								'mainsnak' => array(
									'datavalue' => array( 'value' => 'https://www.nintendo.com/jp/switch2/' ),
								),
							),
						),
					),
				)
			);
		}

		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * 公式ページ本体（と robots.txt）のモック。
	 *
	 * @param mixed                $preempt 既定値。
	 * @param array<string, mixed> $args    引数。
	 * @param string               $url     URL。
	 */
	public function mock_official_page( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'nintendo.com' ) ) {
			return $preempt;
		}

		$body = str_ends_with( $url, '/robots.txt' )
			? "User-agent: *
Disallow: /private/
"
			: '<html><head><title>Nintendo Switch 2</title></head><body>本体上部に USB Type-C 端子を搭載しています。</body></html>';

		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	// ------------------------------------------------------------------
	// 表示
	// ------------------------------------------------------------------

	/** 参照元は Google Search 決め打ちにせず、公式かどうかを示す */
	public function test_sources_rendering_marks_official_and_drops_search_wording() {
		ob_start();
		node_ai_render_fact_check_sources(
			array(
				array(
					'title'    => '任天堂公式',
					'url'      => 'https://www.nintendo.co.jp/hardware/switch2/',
					'official' => true,
				),
				array(
					'title' => 'あるブログ',
					'url'   => 'https://example.com/blog',
				),
			),
			'admin'
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '参照元', $html );
		$this->assertStringNotContainsString( 'Google Search', $html );
		$this->assertSame( 1, substr_count( $html, '（公式）' ), '公式の根拠だけに公式表示が付くこと' );
	}

	/** 実行条件（使用モデル・検索の有無・自動参照）が保存され、表示に渡ること */
	public function test_run_context_is_rendered_in_admin_results() {
		ob_start();
		node_ai_render_fact_check_results(
			array(
				'summary'      => 'テスト',
				'overall_risk' => 'low',
				'model'        => 'gemini-3.7-flash',
				'notices'      => array( '公式サイトを自動で参照しました（www.nintendo.co.jp）。' ),
				'claims'       => array(
					array(
						'claim'      => '筆者の感想',
						'status'     => 'uncertain',
						'confidence' => 'low',
						'claim_type' => 'opinion',
					),
				),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'gemini-3.7-flash', $html );
		$this->assertStringContainsString( '公式サイトを自動で参照しました', $html );
		$this->assertStringContainsString( '筆者の感想（事実判定の対象外）', $html );
	}

	// ------------------------------------------------------------------
	// 動画埋め込み / 「存在しない」断定
	// ------------------------------------------------------------------

	/** YouTube 埋め込みが根拠の取得枠を奪わない */
	public function test_video_embeds_do_not_consume_source_slots() {
		$content = '<figure class="wp-block-embed is-provider-youtube"><div class="wp-block-embed__wrapper">https://youtu.be/74y14GWUNuU?si=abc</div></figure>'
			. '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://www.youtube.com/embed/Elp8yCwRFlk</div></figure>'
			. '<p>詳しくは <a href="https://gadgeteer.jp/u1/articles/95">こちらの記事</a> をご覧ください。</p>'
			. '<p><a href="https://x.com/example/status/1">X の投稿</a></p>';

		$urls = Node_AI_Fact_Check_Sources::extract_urls( $content );

		$this->assertSame( array( 'https://gadgeteer.jp/u1/articles/95' ), $urls );
		$this->assertTrue( Node_AI_Fact_Check_Sources::is_skipped_host( 'www.youtube.com' ) );
		$this->assertTrue( Node_AI_Fact_Check_Sources::is_skipped_host( 'youtu.be' ) );
		$this->assertTrue( Node_AI_Fact_Check_Sources::is_skipped_host( 'x.com' ) );
		$this->assertFalse( Node_AI_Fact_Check_Sources::is_skipped_host( 'gadgeteer.jp' ) );
	}

	/** 裏づけの無い「存在しない」は註記の文面からも取り除く */
	public function test_unsupported_denial_is_removed_from_note() {
		$claim = node_ai_fc_normalize_claim(
			array(
				'claim'      => '「Gemini Spark」でも利用できる',
				'status'     => 'likely_incorrect',
				'confidence' => 'high',
				'note'       => '「Gemini Spark」というサービスは存在しません。公式の名称は「Gemini Advanced」です。',
				'evidence'   => array(),
			),
			array( 'grounded' => false )
		);

		$this->assertStringNotContainsString( '存在しません', $claim['note'] );
		$this->assertStringContainsString( '確認できませんでした', $claim['note'] );
		$this->assertNotSame( 'likely_incorrect', $claim['status'] );
	}

	/** 裏づけのある指摘は註記も判定もそのまま残す */
	public function test_supported_correction_is_kept_as_is() {
		$context = array(
			'grounded'      => true,
			'evidence_text' => array(
				'https://example.com/interview' => 'Matthew Karch is the CEO of Saber Interactive.',
			),
		);

		$claim = node_ai_fc_normalize_claim(
			array(
				'claim'      => 'Matthew Karch 氏は CD PROJEKT RED の CEO である',
				'status'     => 'likely_incorrect',
				'confidence' => 'high',
				'note'       => 'Matthew Karch 氏は Saber Interactive の CEO です。',
				'evidence'   => array(
					array(
						'url'      => 'https://example.com/interview',
						'official' => false,
					),
				),
			),
			$context
		);

		$this->assertSame( 'likely_incorrect', $claim['status'] );
		$this->assertStringContainsString( 'Saber Interactive', $claim['note'] );
	}

	// ------------------------------------------------------------------
	// 検索由来の根拠 / 暫定結果の取り直し
	// ------------------------------------------------------------------

	/** groundingSupports から、検索で裏が取れた主張へ根拠を割り当てる */
	public function test_grounding_sources_are_attached_to_claims() {
		$claims = array(
			array(
				'claim' => 'Nintendo Switch 2 は発売済みである',
				'note'  => '公式サイトに製品情報が掲載されています。',
			),
			array(
				'claim'    => 'モデルが根拠を書いた主張',
				'note'     => '公式サイトに製品情報が掲載されています。',
				'evidence' => array( array( 'url' => 'https://example.com/a' ) ),
			),
		);

		$grounding = array(
			'groundingChunks'  => array(
				array( 'web' => array( 'uri' => 'https://vertexaisearch.example/redirect/1', 'title' => 'nintendo.co.jp' ) ),
				array( 'web' => array( 'uri' => 'https://vertexaisearch.example/redirect/2', 'title' => 'example-news.example' ) ),
			),
			'groundingSupports' => array(
				array(
					'segment'              => array( 'text' => '公式サイトに製品情報が掲載されています。' ),
					'groundingChunkIndices' => array( 0, 1 ),
				),
			),
		);

		$result = Node_AI_Fact_Check_Runner::attach_grounding_evidence( $claims, $grounding );

		$this->assertCount( 2, $result[0]['evidence'] );
		$this->assertSame( 'search', $result[0]['evidence'][0]['source_type'] );
		// 転送用URLでも、表示名のドメインで公式かどうかを判定する
		$this->assertTrue( $result[0]['evidence'][0]['official'] );
		$this->assertFalse( $result[0]['evidence'][1]['official'] );

		// モデルが自分で根拠を書いている主張は上書きしない
		$this->assertCount( 1, $result[1]['evidence'] );
		$this->assertSame( 'https://example.com/a', $result[1]['evidence'][0]['url'] );
	}

	/** 検索で裏が取れた指摘は、根拠が付くことで降格されない */
	public function test_grounded_claim_keeps_incorrect_verdict_after_attachment() {
		$claims = Node_AI_Fact_Check_Runner::attach_grounding_evidence(
			array(
				array(
					'claim'      => '価格は 100 円である',
					'status'     => 'likely_incorrect',
					'confidence' => 'high',
					'note'       => '公式の価格表と矛盾しています。',
				),
			),
			array(
				'groundingChunks'   => array( array( 'web' => array( 'uri' => 'https://vertexaisearch.example/r/1', 'title' => 'nintendo.co.jp' ) ) ),
				'groundingSupports' => array(
					array(
						'segment'               => array( 'text' => '公式の価格表と矛盾しています。' ),
						'groundingChunkIndices' => array( 0 ),
					),
				),
			)
		);

		$normalized = node_ai_fc_normalize_claim( $claims[0], array( 'grounded' => true ) );

		$this->assertSame( 'likely_incorrect', $normalized['status'] );
	}

	/** 検索なしで保存された結果には暫定フラグが立ち、検索ありなら消える */
	public function test_degraded_flag_tracks_search_availability() {
		$post_id = $this->factory->post->create();

		$payload = array(
			'text'      => wp_json_encode(
				array(
					'summary' => 's',
					'claims'  => array( array( 'claim' => 'c', 'status' => 'uncertain' ) ),
				)
			),
			'grounding' => array(),
			'context'   => array( 'grounded' => false ),
		);

		node_ai_store_fact_check_result( $post_id, $payload );
		$this->assertSame( '1', (string) get_post_meta( $post_id, '_node_ai_fact_check_degraded', true ) );

		$payload['context']['grounded'] = true;
		node_ai_store_fact_check_result( $post_id, $payload );
		$this->assertSame( '', (string) get_post_meta( $post_id, '_node_ai_fact_check_degraded', true ) );
	}

	/** 枠が回復したら暫定結果を取り直す。使えないうちは何もしない */
	public function test_degraded_results_are_rechecked_when_search_returns() {
		$this->enable_http_mock();

		$post_id = $this->factory->post->create(
			array(
				'post_author'  => $this->user_id,
				'post_content' => 'Nintendo Switch 2 の最新仕様について。',
			)
		);
		update_post_meta( $post_id, '_node_ai_fact_check_degraded', '1' );
		update_post_meta( $post_id, '_node_ai_fact_check', wp_json_encode( array( 'claims' => array( array( 'claim' => 'c' ) ) ) ) );

		// 検索が使えない状態では取り直さない
		update_option( Node_AI_Fact_Check_Runner::GROUNDING_OPTION, 'off' );
		node_ai_fc_run_degraded_recheck();
		$this->assertSame( '', (string) get_post_meta( $post_id, '_node_ai_fact_check_recheck_at', true ) );
		$this->assertEmpty( $this->requests );

		// 枠が戻れば取り直し、検索ありの結果でフラグが消える
		update_option( Node_AI_Fact_Check_Runner::GROUNDING_OPTION, 'auto' );
		node_ai_fc_run_degraded_recheck();

		$this->assertNotEmpty( $this->requests );
		$this->assertNotEmpty( get_post_meta( $post_id, '_node_ai_fact_check_recheck_at', true ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, '_node_ai_fact_check_degraded', true ) );
	}

	/** post_modified_gmt が空の下書きも取り直しの対象にする（実測で落ちていた） */
	public function test_recheck_covers_drafts_with_empty_modified_gmt() {
		global $wpdb;

		$this->enable_http_mock();

		$post_id = $this->factory->post->create(
			array(
				'post_author'  => $this->user_id,
				'post_status'  => 'pending',
				'post_content' => 'Nintendo Switch 2 の最新仕様について。',
			)
		);
		update_post_meta( $post_id, '_node_ai_fact_check_degraded', '1' );

		// CLI 経由で作られた下書きで実際に起きる状態を再現する
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '0000-00-00 00:00:00' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		node_ai_fc_run_degraded_recheck();

		$this->assertNotEmpty(
			get_post_meta( $post_id, '_node_ai_fact_check_recheck_at', true ),
			'post_modified_gmt が空の記事が取り直しから漏れています'
		);
	}

	/** 1回の実行で扱う件数を絞る（無料枠を食い潰さない） */
	public function test_recheck_respects_limit() {
		$this->enable_http_mock();

		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = $this->factory->post->create(
				array(
					'post_author'  => $this->user_id,
					'post_content' => 'Nintendo Switch 2 の仕様について ' . $i,
				)
			);
		}
		foreach ( $ids as $id ) {
			update_post_meta( $id, '_node_ai_fact_check_degraded', '1' );
		}

		add_filter( 'node_ai_fc_recheck_limit', static fn() => 1 );
		node_ai_fc_run_degraded_recheck();
		remove_all_filters( 'node_ai_fc_recheck_limit' );

		$rechecked = 0;
		foreach ( $ids as $id ) {
			if ( '' !== (string) get_post_meta( $id, '_node_ai_fact_check_recheck_at', true ) ) {
				$rechecked++;
			}
		}

		$this->assertSame( 1, $rechecked );
	}

	/** 手動実行の失敗理由が記事に残る（応答が届かなくても後から追える） */
	public function test_manual_run_records_failure_reason() {
		$post_id = $this->factory->post->create( array( 'post_author' => $this->user_id ) );

		$stored = node_ai_store_fact_check_result(
			$post_id,
			array(
				'text'      => 'これは JSON ではありません',
				'grounding' => array(),
			)
		);

		$this->assertWPError( $stored );

		// AJAX ハンドラと同じ記録経路（失敗理由を記事メタへ残す）
		update_post_meta( $post_id, '_node_ai_fact_check_error', sanitize_text_field( $stored->get_error_message() ) );

		$this->assertStringContainsString(
			'解析',
			(string) get_post_meta( $post_id, '_node_ai_fact_check_error', true )
		);
	}

	/** robots.txt で拒否されたパスは取得しない */
	public function test_robots_txt_is_respected() {
		$rules = Node_AI_Fact_Check_Sources::parse_robots( "User-agent: *\nDisallow: /private/\n\nUser-agent: Googlebot\nDisallow: /\n" );

		$this->assertSame( array( '/private/' ), $rules );
	}
}
