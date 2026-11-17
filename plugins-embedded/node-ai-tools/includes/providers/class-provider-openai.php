<?php
/**
 * OpenAI互換プロバイダー（/chat/completions）。
 *
 * ローカルサーバー・OpenAI・DashScope・任意の互換サーバーを設定で選択する。
 * 旧Qwenクラスはこの実装を継承して既存の設定を維持する。
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Node_AI_Provider_OpenAI implements Node_AI_Provider {

	public const DEFAULT_ENDPOINT = 'http://127.0.0.1:11434/v1';
	public const DEFAULT_MODEL    = 'gemma3:4b';

	protected string $option_prefix;
	protected string $default_preset;
	private string $model_override = '';

	public function __construct( string $option_prefix = 'node_ai_openai', string $default_preset = 'local' ) {
		$this->option_prefix = $option_prefix;
		$this->default_preset = $default_preset;
	}

	public static function presets(): array {
		return array(
			'local' => array( 'label' => 'Ollama（OpenAI互換）', 'endpoint' => self::DEFAULT_ENDPOINT, 'model' => self::DEFAULT_MODEL, 'key_required' => false ),
			'openai' => array( 'label' => 'OpenAI', 'endpoint' => 'https://api.openai.com/v1', 'model' => '', 'key_required' => true ),
			'dashscope' => array( 'label' => 'DashScope（Qwen）', 'endpoint' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1', 'model' => 'qwen-plus', 'key_required' => true ),
			'custom' => array( 'label' => '任意のOpenAI互換サーバー', 'endpoint' => '', 'model' => '', 'key_required' => false ),
		);
	}

	private function preset(): array {
		$id = (string) get_option( $this->option_prefix . '_preset', $this->default_preset );
		return self::presets()[ $id ] ?? self::presets()[ $this->default_preset ];
	}

	public function set_model_override( string $model ): void {
		$this->model_override = trim( $model );
	}

	private function headers(): array {
		$headers = array( 'Content-Type' => 'application/json' );
		$key = $this->api_key();
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		return $headers;
	}

	private function validate_settings() {
		if ( ! preg_match( '#^https?://#i', $this->endpoint() ) || ! wp_parse_url( $this->endpoint(), PHP_URL_HOST ) || wp_parse_url( $this->endpoint(), PHP_URL_USER ) || wp_parse_url( $this->endpoint(), PHP_URL_PASS ) ) {
			return new WP_Error( 'ai_invalid_endpoint', 'OpenAI互換APIの接続先URLを確認してください。' );
		}
		if ( '' === $this->api_key() && $this->preset()['key_required'] ) {
			return new WP_Error( 'ai_missing_credentials', '選択したOpenAI互換サービスのAPIキーを設定してください。' );
		}
		if ( '' === $this->get_model() ) {
			return new WP_Error( 'ai_missing_model', '利用するモデルIDを設定してください。' );
		}
		return true;
	}

	public function generate( string $prompt, array $options = array() ) {
		$validation = $this->validate_settings();
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$options = wp_parse_args(
			$options,
			array(
				'system_instruction' => '',
				'max_tokens'         => 1024,
				'temperature'        => 0.7,
				'json'               => false,
				'timeout'            => 'local' === (string) get_option( $this->option_prefix . '_preset', $this->default_preset ) ? 600 : 180,
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
			'model'       => $this->get_model(),
			'messages'    => $messages,
			'max_tokens'  => (int) $options['max_tokens'],
			'temperature' => (float) $options['temperature'],
		);
		if ( ! empty( $options['json'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$response = wp_remote_post(
			$this->endpoint() . '/chat/completions',
			array(
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $body ),
				'timeout' => (int) $options['timeout'],
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			if ( false !== strpos( $message, 'timed out' ) ) {
				return new WP_Error( 'ai_timeout', 'OpenAI互換API からの応答がタイムアウトしました。しばらく待ってから再度お試しください。' );
			}
			return new WP_Error( 'ai_unavailable', 'OpenAI互換API への接続に失敗しました。詳細: ' . $message );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$api_message = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
			if ( '' !== $this->api_key() ) {
				$api_message = str_replace( $this->api_key(), '[redacted]', $api_message );
			}
			$code        = 'ai_error';
			if ( 401 === $status || 403 === $status ) {
				$code = 'ai_missing_credentials';
			} elseif ( 429 === $status ) {
				$code = 'ai_quota';
			} elseif ( $status >= 500 ) {
				$code = 'ai_unavailable';
			}
			return new WP_Error( $code, 'OpenAI互換API エラー (HTTP ' . $status . ')' . ( '' !== $api_message ? ': ' . $api_message : '' ) );
		}

		$content = is_array( $data ) ? ( $data['choices'][0]['message']['content'] ?? null ) : null;
		$text = is_string( $content ) ? $content : '';
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'ai_bad_response', 'OpenAI互換API から有効なレスポンスが得られませんでした。' );
		}

		$text = trim( $text );

		if ( ! empty( $options['return_metadata'] ) ) {
			return array(
				'text'      => $text,
				'grounding' => array(),
				'tokens'    => (int) ( $data['usage']['total_tokens'] ?? 0 ),
			);
		}

		return $text;
	}

	public function test_connection() {
		$validation = $this->validate_settings();
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$response = wp_remote_get(
			$this->endpoint() . '/models',
			array(
				'headers' => $this->headers(),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_unavailable', 'OpenAI互換API への接続に失敗しました。詳細: ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error( 'ai_error', 'OpenAI互換API 接続テストに失敗しました (HTTP ' . $status . ')。エンドポイントと API キーを確認してください。' );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! is_array( $data['data'] ?? null ) ) {
			return new WP_Error( 'ai_bad_response', 'OpenAI互換APIのモデル一覧を読み取れませんでした。' );
		}
		return true;
	}

	public function get_label(): string {
		return 'OpenAI互換';
	}

	public function get_model(): string {
		$model = '' !== $this->model_override ? $this->model_override : trim( (string) get_option( $this->option_prefix . '_model', '' ) );
		return '' !== $model ? $model : $this->preset()['model'];
	}

	private function endpoint(): string {
		$endpoint = trim( (string) get_option( $this->option_prefix . '_endpoint', '' ) );
		if ( '' === $endpoint ) {
			$endpoint = $this->preset()['endpoint'];
		}
		return rtrim( $endpoint, '/' );
	}

	private function api_key(): string {
		return trim( (string) get_option( $this->option_prefix . '_api_key', '' ) );
	}
}
