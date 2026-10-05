<?php
/**
 * Node AI Core — 機能API・プロバイダー解決・利用履歴・エラー正規化（NODE-1.3.md §4）
 *
 * 既存のメタボックス/AJAXは第4段階でこの Core 経由へ移行する。
 * 第3段階では Core 自体と設定画面・接続テスト・利用履歴を提供する。
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_AI_Core {

	public const USAGE_LOG_OPTION = 'node_ai_usage_log';
	public const USAGE_LOG_LIMIT  = 200;

	public const PROVIDERS = array( 'gemini', 'qwen', 'ollama', 'off' );

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 選択中のプロバイダーID（gemini / qwen / ollama / off）
	 */
	public function get_provider_id(): string {
		$provider = (string) get_option( 'node_ai_provider', 'gemini' );
		return in_array( $provider, self::PROVIDERS, true ) ? $provider : 'gemini';
	}

	public function is_enabled(): bool {
		return 'off' !== $this->get_provider_id();
	}

	/**
	 * プロバイダーアダプターを解決する
	 *
	 * @param int    $user_id  実行ユーザー（Gemini の個人キー/モデル解決に使用）。
	 * @param string $override 明示指定（接続テスト用）。空なら設定値。
	 * @return Node_AI_Provider|WP_Error
	 */
	public function get_provider( int $user_id = 0, string $override = '' ) {
		$id = '' !== $override ? $override : $this->get_provider_id();

		switch ( $id ) {
			case 'gemini':
				return new Node_AI_Provider_Gemini( $user_id > 0 ? $user_id : null );
			case 'qwen':
				return new Node_AI_Provider_Qwen();
			case 'ollama':
				return new Node_AI_Provider_Ollama();
			case 'off':
				return new WP_Error( 'ai_disabled', 'AI機能は現在無効に設定されています。設定 → Luna AI から有効化してください。' );
		}

		return new WP_Error( 'ai_disabled', '不明なAIプロバイダーです: ' . $id );
	}

	// ------------------------------------------------------------------
	// 機能API
	// ------------------------------------------------------------------

	/**
	 * AI要約（既存 Intelligence Summary と同一のJSON契約）
	 *
	 * @return string|WP_Error 生テキスト（JSON文字列想定）。
	 */
	public function summarize( string $content, array $args = array(), int $user_id = 0, int $post_id = 0 ) {
		$args = wp_parse_args(
			$args,
			array(
				'max_lines'     => 3,
				'max_chars'     => 120,
				'custom_prompt' => '',
			)
		);

		$system = "あなたは先進的な技術ブログ 'Luminous Core' の編集長です。
提供された記事を解析し、以下の JSON フォーマットでレスポンスしてください。
・必ず、要約は {$args['max_lines']} 行以内、かつ {$args['max_chars']} 文字以内厳守で作成してください。
・Markdownのコードブロック（```json ... ```）は絶対に使わず、生の中括弧 { } から始まる純粋なJSON文字列のみを出力してください。
・要約内に改行を含めないでください。
{
  \"summary\": \"読者の好奇心を刺激する、情緒的で洗練された要約。\",
  \"tone_color\": \"記事のトーンを表す色（hexコード）。\",
  \"vibe_keywords\": [\"キーワード1\", \"キーワード2\"]
}";

		$prompt = "以下の記事を解析し、最高の要約を生成してください：\n\n" . mb_substr( $content, 0, 5000 );
		if ( '' !== (string) $args['custom_prompt'] ) {
			$prompt .= "\n\n【追加の指示（プロンプト）】\n" . $args['custom_prompt'];
		}

		return $this->generate(
			'summarize',
			$prompt,
			array(
				'system_instruction' => $system,
				'json'               => true,
				'temperature'        => 0.4,
				'max_tokens'         => 2048,
			),
			$user_id,
			$post_id
		);
	}

	/**
	 * ファクトチェック（確認箇所の抽出支援）
	 *
	 * 判定の中身は Node_AI_Fact_Check_Runner が担当する。
	 * ここでモデル内部知識を前提にした一発プロンプトを組んでいたのが、
	 * 「未発表」等の古い知識による連鎖誤判定の原因だったため、実装を分離した。
	 *
	 * @return array{text: string, grounding: array, guidelines_used: bool, context: array}|WP_Error
	 */
	public function fact_check( string $content, string $title = '', int $user_id = 0, int $post_id = 0 ) {
		if ( ! class_exists( 'Node_AI_Fact_Check_Runner' ) ) {
			return new WP_Error( 'fact_check_unavailable', 'ファクトチェック処理を読み込めませんでした。' );
		}

		return Node_AI_Fact_Check_Runner::run( $content, $title, $user_id, $post_id );
	}

	/**
	 * 校正（誤字脱字・表記ゆれ・読みやすさ）
	 *
	 * @return string|WP_Error 生テキスト（JSON文字列想定）。
	 */
	public function proofread( string $content, int $user_id = 0, int $post_id = 0 ) {
		$system = 'あなたは商業メディア水準の日本語校閲者です。読者に配信して恥ずかしくない日本語かを厳しく見てください。
以下の JSON 形式のみで回答してください（コードブロック禁止）。指摘は最大15件。

type は次のいずれかを厳密に使うこと:
  typo        = 誤字・脱字・変換ミス（同音異義語の誤変換を含む）
  grammar     = 文法の誤り。主語と述語のねじれ、係り受けの不整合、助詞の誤用、
                時制の不一致、修飾語の位置による多義
  usage       = 国語的な誤り・言葉遣い。次を必ず見ること:
                ・慣用句/ことわざの誤用（例「的を得る」→「的を射る」「汚名挽回」→「汚名返上」）
                ・重言（例「頭痛が痛い」「まず最初に」「約〜程度」「各〜ごとに」）
                ・ら抜き言葉、い抜き言葉、さ入れ言葉
                ・敬語の誤り（二重敬語、尊敬語と謙譲語の取り違え、「〜させていただく」の乱用）
                ・語の意味の取り違え（例「役不足」「情けは人の為ならず」「確信犯」）
                ・話し言葉/若者言葉の混入（例「なので」の文頭使用、「〜的には」「めっちゃ」）
  style       = 表記ゆれ・用字用語の不統一。漢字とひらがなの使い分け（形式名詞「こと・とき・もの」、
                補助動詞「〜していく」等はひらがなが原則）、送り仮名、数字・単位・記号の統一、
                全角半角の混在、句読点の打ち方
  readability = 冗長・一文が長すぎる・同語反復・二重否定など読みにくい表現
  meaning     = 解釈違い・誤解を招く断定・事実と異なる言い回し

指摘は必ず原文どおりに引用し、修正案は文脈に沿った自然な日本語にすること。
好みの問題にすぎない書き換えは指摘しないこと（誤りとして説明できるものだけ）。

{
  "summary": "全体所見（1〜2文）",
  "issues": [
    { "type": "typo", "original": "問題のある原文", "suggestion": "修正案", "reason": "理由（簡潔に）", "severity": "low / medium / high" }
  ]
}';

		return $this->generate(
			'proofread',
			"以下の記事本文を校正してください：\n\n" . mb_substr( $content, 0, 8000 ),
			array(
				'system_instruction' => $system,
				'json'               => true,
				'temperature'        => 0.2,
				// 思考モデルは maxOutputTokens を思考にも消費するため、
				// JSON が途中で切れないよう長めに確保する
				'max_tokens'         => 8192,
			),
			$user_id,
			$post_id
		);
	}

	/**
	 * タイトル案の生成
	 *
	 * @return string|WP_Error 生テキスト（JSON文字列想定）。
	 */
	public function suggest_titles( string $content, int $user_id = 0, int $post_id = 0 ) {
		$system = 'あなたは技術ブログの編集者です。記事の内容を踏まえ、クリックしたくなる日本語タイトル案を5つ提案してください。
煽りすぎず、内容を正確に表すこと。以下の JSON 形式のみで回答してください（コードブロック禁止）。
{ "titles": ["案1", "案2", "案3", "案4", "案5"] }';

		return $this->generate(
			'suggest_titles',
			"以下の記事のタイトル案を考えてください：\n\n" . mb_substr( $content, 0, 5000 ),
			array(
				'system_instruction' => $system,
				'json'               => true,
				'temperature'        => 0.8,
				'max_tokens'         => 1024,
			),
			$user_id,
			$post_id
		);
	}

	/**
	 * SNS（X）投稿文案の生成
	 *
	 * @return string|WP_Error 生テキスト（JSON文字列想定）。
	 */
	public function social_post( string $content, string $title = '', int $user_id = 0, int $post_id = 0 ) {
		$system = 'あなたは技術ブログのSNS担当です。X（旧Twitter）向けの日本語投稿文案を3つ提案してください。
・各案は本文110文字以内（URLとハッシュタグの余白を残す）
・誇張せず、記事の要点と読みたくなる一言を入れる
・絵文字は使っても1つまで
以下の JSON 形式のみで回答してください（コードブロック禁止）。
{ "posts": ["案1", "案2", "案3"] }';

		$prompt = '';
		if ( '' !== $title ) {
			$prompt .= "【タイトル】\n" . $title . "\n\n";
		}
		$prompt .= "【本文】\n" . mb_substr( $content, 0, 5000 );

		return $this->generate(
			'social_post',
			"以下の記事のX投稿文案を作ってください：\n\n" . $prompt,
			array(
				'system_instruction' => $system,
				'json'               => true,
				'temperature'        => 0.7,
				'max_tokens'         => 1024,
			),
			$user_id,
			$post_id
		);
	}

	// ------------------------------------------------------------------
	// 共通処理
	// ------------------------------------------------------------------

	/**
	 * プロバイダー解決 → 生成 → 利用履歴記録 → エラー正規化
	 *
	 * @param string               $feature 機能ID（利用履歴用）。
	 * @param string               $prompt  プロンプト。
	 * @param array<string, mixed> $options 生成オプション。
	 * @param int                  $user_id 実行ユーザー。
	 * @param int                  $post_id 対象記事ID（無ければ0）。
	 * @return string|array<string, mixed>|WP_Error
	 */
	public function generate( string $feature, string $prompt, array $options = array(), int $user_id = 0, int $post_id = 0 ) {
		$provider = $this->get_provider( $user_id );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		// 呼び出し側がモデルを指定した場合（ファクトチェックの無料枠自動選択）は
		// 利用履歴にもそのモデルを残す。
		if ( ! empty( $options['model'] ) && method_exists( $provider, 'set_model_override' ) ) {
			$provider->set_model_override( (string) $options['model'] );
		}

		$result = $provider->generate( $prompt, $options );

		if ( is_wp_error( $result ) ) {
			$normalized = $this->normalize_error( $result );
			$this->record_usage( $feature, $provider, $post_id, false, 0, $normalized->get_error_code() );
			return $normalized;
		}

		$tokens = 0;
		if ( is_array( $result ) && isset( $result['tokens'] ) ) {
			$tokens = (int) $result['tokens'];
		}
		$this->record_usage( $feature, $provider, $post_id, true, $tokens, '' );

		return $result;
	}

	/**
	 * プロバイダー固有のエラーコードを共通コードへ正規化する
	 * （元のコードは data.original_code に保持）
	 */
	public function normalize_error( WP_Error $error ): WP_Error {
		$code = (string) $error->get_error_code();

		$map = array(
			'missing_api_key'          => 'ai_missing_credentials',
			'gemini_quota_exceeded'    => 'ai_quota',
			'gemini_timeout'           => 'ai_timeout',
			'gemini_model_unavailable' => 'ai_unavailable',
			'gemini_request_failed'    => 'ai_unavailable',
			'gemini_api_error'         => 'ai_error',
			'api_error'                => 'ai_bad_response',
		);

		$normalized_code = $map[ $code ] ?? ( 0 === strpos( $code, 'ai_' ) ? $code : 'ai_error' );

		$data = (array) $error->get_error_data();

		return new WP_Error(
			$normalized_code,
			$error->get_error_message(),
			array(
				'original_code' => $code,
				// 再試行制御（429 の待機・モデルのフォールバック）に必要な情報は落とさない。
				'status'        => (int) ( $data['status'] ?? 0 ),
				'retry_after'   => (int) ( $data['retry_after'] ?? 0 ),
				'model'         => (string) ( $data['model'] ?? '' ),
			)
		);
	}

	/**
	 * 利用履歴の記録（直近 USAGE_LOG_LIMIT 件・料金計算はしない）
	 */
	public function record_usage( string $feature, Node_AI_Provider $provider, int $post_id, bool $ok, int $tokens = 0, string $error_code = '' ): void {
		$log = get_option( self::USAGE_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'       => current_time( 'mysql' ),
				'feature'    => sanitize_key( $feature ),
				'provider'   => $provider->get_label(),
				'model'      => $provider->get_model(),
				'post_id'    => $post_id,
				'ok'         => $ok,
				'tokens'     => $tokens,
				'error_code' => sanitize_key( $error_code ),
			)
		);

		update_option( self::USAGE_LOG_OPTION, array_slice( $log, 0, self::USAGE_LOG_LIMIT ), false );
	}

	/**
	 * 利用履歴の取得
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_usage_log(): array {
		$log = get_option( self::USAGE_LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * 今月の実行回数（履歴上限内での概算）
	 */
	public function get_monthly_usage_count(): int {
		$prefix = current_time( 'Y-m' );
		$count  = 0;
		foreach ( $this->get_usage_log() as $entry ) {
			if ( 0 === strpos( (string) ( $entry['time'] ?? '' ), $prefix ) ) {
				$count++;
			}
		}
		return $count;
	}
}

if ( ! function_exists( 'node_ai_core' ) ) {
	/**
	 * Core シングルトンへのアクセサ
	 */
	function node_ai_core(): Node_AI_Core {
		return Node_AI_Core::instance();
	}
}
