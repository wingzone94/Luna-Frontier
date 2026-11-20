<?php
/**
 * Gemini API 通信クラス
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Node_Gemini_API
 */
class Node_Gemini_API {

    /**
     * API Key
     */
    private string $api_key;
    private int $user_id;

    /**
     * API URL Base
     */
    private string $api_url_base = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct( ?int $user_id = null ) {
        $user_id = $user_id ?? get_current_user_id();
        $this->user_id = $user_id;

        if ( function_exists( 'node_get_user_gemini_api_key' ) && $user_id > 0 ) {
            $this->api_key = node_get_user_gemini_api_key( $user_id );
        } else {
            $this->api_key = $user_id > 0
                ? (string) get_user_meta( $user_id, 'node_gemini_api_key', true )
                : '';
        }

        // サイト共通キー（AI設定）をフォールバック。個人キー未登録のライターに適用
        if ( empty( $this->api_key ) ) {
            $this->api_key = trim( (string) get_option( 'node_ai_gemini_api_key', '' ) );
        }

        // 開発環境のみ: wp-config.php の GEMINI_API_KEY 定数をフォールバック
        if ( empty( $this->api_key ) && defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY ) {
            $this->api_key = (string) GEMINI_API_KEY;
        }
    }

    /**
     * 高度なコンテンツ生成 (システム指示対応)
     */
    public function generate_content(string $prompt, array $options = []): string|array|WP_Error {
        if ( ! class_exists( 'Node_AI_Core' ) || ! in_array( $options['feature'] ?? '', Node_AI_Core::GEMINI_FEATURES, true ) ) {
            return new WP_Error( 'ai_feature_restricted', 'GeminiはAI要約・TTS・ファクトチェック専用です。' );
        }
        if ( ! in_array( $options['feature'], array( 'fact_check', 'fact_check_premise' ), true ) && ! empty( $options['google_search_grounding'] ) ) {
            return new WP_Error( 'ai_feature_restricted', 'Google検索はGeminiのファクトチェックでのみ使用します。' );
        }

        if (empty($this->api_key)) {
            return new WP_Error(
                'missing_api_key',
                'Gemini API キーが設定されていません。ユーザー → プロフィール の「Gemini API（要約・TTS・ファクトチェック専用）」から、あなた専用のキーを登録してください。'
            );
        }

        $options = wp_parse_args($options, [
            'system_instruction' => 'あなたはプロのテクニカルライターであり、デザイナーです。ユーザーに驚きと知覚的な喜びを与える文章を作成してください。',
            'max_tokens'  => 400,
            'temperature' => 0.7,
            'response_mime_type' => 'text/plain',
            'google_search_grounding' => false,
            'return_metadata' => false,
            'timeout' => 45,
            // 呼び出し側がモデルを固定したい場合（ファクトチェックの無料枠自動選択など）。
            'model' => '',
            // Gemini 3 系の thinkingLevel（low / medium / high）。空なら指定しない。
            'thinking_level' => '',
        ]);

        $user_id = $this->user_id;
        if ( '' !== trim( (string) $options['model'] ) ) {
            // 明示指定（ファクトチェックは無料枠のモデルを自動選択して渡す）
            $model_name = trim( (string) $options['model'] );
        } elseif ( function_exists( 'node_get_user_gemini_model' ) && $user_id > 0 ) {
            $model_name = node_get_user_gemini_model( $user_id );
        } elseif ( function_exists( 'node_get_default_gemini_model' ) ) {
            $model_name = node_get_default_gemini_model();
        } else {
            // テーマ側のモデル管理が無い環境でも、特定世代のモデルIDへ固定しない。
            $selected   = class_exists( 'Node_AI_Fact_Check_Models' ) ? Node_AI_Fact_Check_Models::select() : '';
            $model_name = ( is_string( $selected ) && '' !== $selected ) ? $selected : '';
        }

        // 保存済みモデルが提供終了・上限中・（許可なしの）Pro なら、使えるモデルへ置き換える。
        // 選択肢から消えた古い設定が user_meta に残っていても、必ず失敗する呼び出しにはしない
        if ( '' === trim( (string) $options['model'] ) && class_exists( 'Node_AI_Fact_Check_Models' ) ) {
            // 保存値は `<モデルID>@high` 形式のことがあるため、モデルID部分で判定する
            $candidate = (string) preg_replace( '/@(?:high|low)$/i', '', (string) $model_name );

            if ( Node_AI_Fact_Check_Models::is_known_unusable( $candidate ) ) {
                $replacement = Node_AI_Fact_Check_Models::select();

                if ( is_string( $replacement ) && '' !== $replacement ) {
                    // 思考量の指定は保存値のものを引き継ぐ
                    $suffix     = preg_match( '/@(high|low)$/i', (string) $model_name, $mt ) ? '@' . strtolower( $mt[1] ) : '';
                    $model_name = $replacement . $suffix;
                }
            }
        }

        if ( '' === trim( (string) $model_name ) ) {
            return new WP_Error(
                'gemini_no_model',
                '利用できる Gemini モデルを特定できなかったため、実行を中止しました。',
                array( 'status' => 503 )
            );
        }
        
        // `<モデルID>@high` / `@low` は思考量（thinkingLevel）つき仮想ID。実IDと思考量に分解する
        $thinking_level = '';
        if ( preg_match( '/^(.+)@(high|low)$/i', $model_name, $m ) ) {
            $model_name     = $m[1];
            $thinking_level = strtolower( $m[2] );
        }

        // Google 検索グラウンディングは通常生成とは別枠で、Gemini 3系では
        // 通常生成が通るのにグラウンディングだけ 429 になることがある。
        // 枠を一緒に扱うと、検索なしなら使えるモデルまで止めてしまうため分けて記録する
        $quota_key = ! empty( $options['google_search_grounding'] )
            ? $model_name . '#grounding'
            : $model_name;

        // 直近の 429 でこのモデルがまだ利用不可なら、API を叩かずに即座に失敗させる。
        // 叩いても失敗するだけでレート枠と待ち時間を消費するため
        if ( function_exists( 'node_gemini_get_quota_block' ) ) {
            $block = node_gemini_get_quota_block( $user_id, $quota_key );

            if ( ! empty( $block['blocked'] ) ) {
                return new WP_Error(
                    'gemini_quota_exceeded',
                    sprintf(
                        '%s は利用上限に達しているため実行を中止しました（%s頃に再開見込み）。別のモデルを選ぶか、時間をおいてお試しください。',
                        $model_name,
                        wp_date( 'n月j日 H:i', (int) $block['until'] )
                    ),
                    array( 'status' => 429 )
                );
            }
        }

        $url = $this->api_url_base . $model_name . ':generateContent?key=' . $this->api_key;

        // 画像入力（マルチモーダル）。指定時は画像パートを先に置く
        $parts = [];
        if ( ! empty( $options['image'] ) && is_array( $options['image'] ) ) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => (string) $options['image']['mime'],
                    'data'      => (string) $options['image']['data'],
                ],
            ];
        }
        $parts[] = ['text' => $prompt];

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => $parts]
            ],
            'system_instruction' => [
                'parts' => [['text' => $options['system_instruction']]]
            ],
            'generationConfig' => [
                'maxOutputTokens'  => $options['max_tokens'],
                'temperature'      => $options['temperature'],
                'responseMimeType' => $options['response_mime_type'],
            ]
        ];

        // 明示指定（low / medium / high）が来ていれば、そちらを使う。
        $explicit_level = strtolower( trim( (string) $options['thinking_level'] ) );
        if ( in_array( $explicit_level, array( 'minimal', 'low', 'medium', 'high' ), true ) ) {
            $thinking_level = $explicit_level;
        }

        if ( '' !== $thinking_level ) {
            $payload['generationConfig']['thinkingConfig'] = array( 'thinkingLevel' => $thinking_level );
        }

        if ( ! empty( $options['google_search_grounding'] ) ) {
            $payload['tools'] = array(
                array( 'google_search' => new \stdClass() ),
            );
        }

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($payload),
            'timeout' => (int) $options['timeout'],
        ]);

        if (is_wp_error($response)) {
            $err_msg = $response->get_error_message();
            if (strpos($err_msg, 'timed out') !== false) {
                return new WP_Error('gemini_timeout', 'Gemini APIからの応答がタイムアウトしました。しばらく待ってから再度お試しください。', ['status' => 504]);
            }
            return new WP_Error('gemini_request_failed', 'Gemini APIへの接続に失敗しました。詳細: ' . $err_msg, ['status' => 502]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        $data   = json_decode($body, true);

        // HTTP エラー / API エラー応答（429 の利用上限超過を含む）を分かりやすく返す。
        if (200 !== $status || (is_array($data) && isset($data['error']))) {
            if ( 402 === $status ) {
                return new WP_Error(
                    'ai_billing_unavailable',
                    'Gemini APIがプロジェクトの課金状態により生成を拒否しました（HTTP 402）。プリペイド残高または無料枠を利用可能なプロジェクトの設定を確認してください。モデルの自動切り替えや再試行は行いません。',
                    array( 'status' => 402, 'model' => $model_name )
                );
            }

            if ( function_exists( 'node_gemini_record_quota_error' ) ) {
                node_gemini_record_quota_error( $user_id, $quota_key, $status, $data );
            }

            // 提供終了モデル（404）は以後プルダウンから外す
            if ( function_exists( 'node_gemini_maybe_record_retired_model' ) ) {
                node_gemini_maybe_record_retired_model( $model_name, $status, $data );
            }

            $message = function_exists('node_gemini_format_api_error')
                ? node_gemini_format_api_error($status, $data, $body)
                : ('Gemini API エラー (HTTP ' . $status . ')');
            $code = (429 === $status) ? 'gemini_quota_exceeded' : ((503 === $status) ? 'gemini_model_unavailable' : 'gemini_api_error');

            // 呼び出し側（ファクトチェックの再試行制御）が Retry-After を尊重できるようにする。
            $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
            if ( $retry_after <= 0 && function_exists( 'node_gemini_extract_retry_seconds' ) ) {
                $retry_after = (int) node_gemini_extract_retry_seconds( $data );
            }

            return new WP_Error(
                $code,
                $message,
                array(
                    'status'      => $status,
                    'retry_after' => $retry_after,
                    'model'       => $model_name,
                )
            );
        }

        $parts         = $data['candidates'][0]['content']['parts'] ?? null;
        $finish_reason = (string) ( $data['candidates'][0]['finishReason'] ?? '' );

        // 出力上限で打ち切られた応答は、途中まで本文が返っていても使えない
        // （JSON が閉じずに壊れるため）。部分応答を成功として返さず、原因が分かる形で失敗させる。
        // 思考モデルは maxOutputTokens を思考にも消費するため起きやすい
        if ( 'MAX_TOKENS' === $finish_reason ) {
            return new WP_Error(
                'gemini_max_tokens',
                'Gemini の出力が上限に達し、応答が途中で切れました。思考量を Low にするか、記事を短くしてお試しください。',
                ['status' => 500]
            );
        }

        // 思考モデル（Gemini 3系）は先頭に thought パートを返すことがあり、
        // Google Search グラウンディング併用時は本文が複数パートに分割されることもある。
        // parts[0] 決め打ちだと思考文や断片だけを拾い JSON 解析に失敗するため、
        // thought 以外の text パートをすべて連結する
        if ( is_array( $parts ) ) {
            $chunks = array();

            foreach ( $parts as $part ) {
                if ( ! is_array( $part ) || ! isset( $part['text'] ) || ! empty( $part['thought'] ) ) {
                    continue;
                }
                $chunks[] = (string) $part['text'];
            }

            $text = trim( implode( '', $chunks ) );

            if ( '' !== $text ) {
                if ( function_exists( 'node_gemini_record_usage' ) ) {
                    $tokens = (int) ($data['usageMetadata']['totalTokenCount'] ?? 0);
                    node_gemini_record_usage( $user_id, $model_name, $tokens, 1 );
                }

                if ( ! empty( $options['return_metadata'] ) ) {
                    return array(
                        'text'      => $text,
                        'grounding' => $data['candidates'][0]['groundingMetadata'] ?? array(),
                    );
                }

                return $text;
            }
        }

        return new WP_Error(
            'api_error',
            function_exists('node_gemini_format_api_error')
                ? node_gemini_format_api_error($status, $data, $body)
                : 'Gemini API から有効なレスポンスが得られませんでした。'
        );
    }

    /**
     * デザイナー品質の要約を生成する
     */
    public function generate_summary(string $content, string $custom_prompt = '', array $options = []): string|WP_Error {
        if ( ! function_exists( 'node_ai_core' ) ) {
            return new WP_Error( 'ai_unavailable', 'AI基盤を読み込めませんでした。' );
        }
        $options['custom_prompt'] = $custom_prompt;
        return node_ai_core()->summarize( $content, $options, $this->user_id );
    }

    /**
     * 記事本文のファクトチェック（編集者向け・要手動確認）
     *
     * 実処理は Node_AI_Fact_Check_Runner（基礎前提の先行検証 → 主張の検証 →
     * PHP 側での判定正規化）に委譲する。ここは後方互換のための入口。
     *
     * @return array{text: string, grounding: array, guidelines_used: bool}|WP_Error
     */
    public function fact_check( string $content, string $title = '' ): array|WP_Error {
        if ( ! class_exists( 'Node_AI_Fact_Check_Runner' ) ) {
            return new WP_Error( 'fact_check_unavailable', 'ファクトチェック処理を読み込めませんでした。' );
        }

        return Node_AI_Fact_Check_Runner::run( $content, $title, $this->user_id, 0 );
    }

    /**
     * 画像から代替テキスト（alt）の案を生成する。
     *
     * 画像の「出典」は推測させない。Gemini のグラウンディングは逆画像検索ではなく、
     * 画像内の文字から話題を検索するだけなので、自作画像に他社の記事URLを
     * 出典として提示してしまう（実測で確認済み）。
     *
     * @param string $path 画像のローカルパス。
     * @return array{alt_suggestion: string}|WP_Error
     */
    public function analyze_image( string $path ) {
        // Compatibility entry point: image work follows the common provider, never a direct Gemini request.
        return function_exists( 'node_ai_core' )
            ? node_ai_core()->analyze_image( $path, $this->user_id )
            : new WP_Error( 'ai_unavailable', 'AI基盤を読み込めませんでした。' );
    }

    /**
     * AIによる読了目安時間の推定
     */
    public function generate_reading_time_estimate(string $content): string|WP_Error {
        $prompt = "以下の記事の読了目安時間を推定し、「〇分〇秒」という形式で回答してください。日本語として自然な読書速度（分速400〜600文字程度）を基準にしつつ、内容の難易度や構成（コード、リスト、画像等）も加味して人間が読み終えるのにかかる時間を算出してください。解説は不要です。結果の数値のみを返してください。\n\n" . mb_substr($content, 0, 4000);
        return function_exists( 'node_ai_core' )
            ? node_ai_core()->generate( 'reading_time', $prompt, array( 'max_tokens' => 32, 'temperature' => 0.1 ), $this->user_id )
            : new WP_Error( 'ai_unavailable', 'AI基盤を読み込めませんでした。' );
    }
}
