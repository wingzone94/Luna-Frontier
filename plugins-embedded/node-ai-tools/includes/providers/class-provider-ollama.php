<?php
/**
 * Ollama プロバイダーアダプター（ローカル /api/chat・キー不要）
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Node_AI_Provider_Ollama implements Node_AI_Provider {

	public const DEFAULT_URL   = 'http://localhost:11434';
	public const DEFAULT_MODEL = 'gemma3:4b';

	/**
	 * 標準の選択肢（詳細設定で任意IDに変更可能）
	 *
	 * @return array<string, string>
	 */
	public static function preset_models(): array {
		return array(
			'gemma3:4b' => 'Gemma 3 4B（既定・画像対応）',
			'qwen3:4b' => 'Qwen 3 4B',
			'qwen3:8b' => 'Qwen 3 8B',
			'tinyswallow-1.5b:latest' => 'TinySwallow 1.5B',
			'gemma3' => 'Gemma 3（既存設定用）',
			'qwen3'  => 'Qwen 3',
		);
	}

	public function generate( string $prompt, array $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'system_instruction' => '',
				'max_tokens'         => 1024,
				'temperature'        => 0.7,
				'json'               => false,
				'timeout'            => 600, // CPU上のローカル推論・初回ロードを待つ（呼び出し側で変更可）
			)
		);

		$messages = array();
		if ( '' !== (string) $options['system_instruction'] ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => (string) $options['system_instruction'],
			);
		}
		$messages[] = array(
			'role'    => 'user',
			'content' => $prompt,
		);

		$body = array(
			'model'    => $this->get_model(),
			'messages' => $messages,
			'stream'   => false,
			'options'  => array(
				'temperature' => (float) $options['temperature'],
				'num_predict' => (int) $options['max_tokens'],
			),
		);
		if ( ! empty( $options['json'] ) ) {
			$body['format'] = 'json';
		}

		$response = wp_remote_post(
			$this->base_url() . '/api/chat',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
				'timeout' => (int) $options['timeout'],
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			if ( false !== strpos( $message, 'timed out' ) ) {
				return new WP_Error( 'ai_timeout', 'Ollama からの応答がタイムアウトしました。モデルの初回ロード中の可能性があります。' );
			}
			return new WP_Error( 'ai_unavailable', 'Ollama に接続できません。Ollama が起動しているか、接続先URLを確認してください。詳細: ' . $message );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$api_message = is_array( $data ) ? (string) ( $data['error'] ?? '' ) : '';
			if ( 404 === $status && false !== stripos( $api_message, 'model' ) ) {
				return new WP_Error( 'ai_error', 'Ollama にモデル「' . $this->get_model() . '」が見つかりません。`ollama pull ' . $this->get_model() . '` で取得してください。' );
			}
			return new WP_Error( 'ai_error', 'Ollama API エラー (HTTP ' . $status . ')' . ( '' !== $api_message ? ': ' . $api_message : '' ) );
		}

		$text = is_array( $data ) ? (string) ( $data['message']['content'] ?? '' ) : '';
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'ai_bad_response', 'Ollama から有効なレスポンスが得られませんでした。' );
		}

		$text = trim( $text );

		if ( ! empty( $options['return_metadata'] ) ) {
			return array(
				'text'      => $text,
				'grounding' => array(),
				'tokens'    => (int) ( $data['eval_count'] ?? 0 ),
			);
		}

		return $text;
	}

	public function test_connection() {
		$response = wp_remote_get( $this->base_url() . '/api/tags', array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_unavailable', 'Ollama に接続できません。Ollama が起動しているか、接続先URL（既定 ' . self::DEFAULT_URL . '）を確認してください。' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error( 'ai_error', 'Ollama 接続テストに失敗しました (HTTP ' . $status . ')。' );
		}

		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$models = array();
		foreach ( (array) ( $data['models'] ?? array() ) as $model ) {
			if ( is_array( $model ) && ! empty( $model['name'] ) ) {
				$models[] = (string) $model['name'];
			}
		}

		$current = $this->get_model();
		$found   = false;
		foreach ( $models as $name ) {
			if ( $name === $current || 0 === strpos( $name, $current . ':' ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return new WP_Error(
				'ai_error',
				'Ollama には接続できましたが、モデル「' . $current . '」が見つかりません。`ollama pull ' . $current . '` で取得してください。'
				. ( ! empty( $models ) ? '（導入済み: ' . implode( ', ', array_slice( $models, 0, 5 ) ) . '）' : '' )
			);
		}

		return true;
	}

	public function get_label(): string {
		return 'Ollama';
	}

	public function get_model(): string {
		$model = trim( (string) get_option( 'node_ai_ollama_model', '' ) );
		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	private function base_url(): string {
		$url = trim( (string) get_option( 'node_ai_ollama_url', '' ) );
		if ( '' === $url ) {
			$url = self::DEFAULT_URL;
		}
		return rtrim( $url, '/' );
	}
}
