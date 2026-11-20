<?php
/**
 * Gemini プロバイダーアダプター（既存 Node_Gemini_API の薄いラッパー）
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Node_AI_Provider_Gemini implements Node_AI_Provider {

	private int $user_id;

	/**
	 * 呼び出し側が指定したモデル（ファクトチェックの無料枠自動選択）。
	 */
	private string $model_override = '';

	public function __construct( ?int $user_id = null ) {
		$this->user_id = $user_id ?? get_current_user_id();
	}

	/**
	 * 使用モデルを明示指定する（利用履歴の記録にも反映される）。
	 */
	public function set_model_override( string $model ): void {
		$this->model_override = trim( $model );
	}

	public function generate( string $prompt, array $options = array() ) {
		if ( ! in_array( $options['feature'] ?? '', Node_AI_Core::GEMINI_FEATURES, true ) ) {
			return new WP_Error( 'ai_feature_restricted', 'Geminiの利用対象外の機能です。' );
		}

		$api = new Node_Gemini_API( $this->user_id );

		// 共通オプション → Gemini オプションへ変換
		$gemini_options = $options;
		if ( ! empty( $options['json'] ) && empty( $options['google_search_grounding'] ) ) {
			// ツール併用時は JSON モードが拒否されるため text/plain のまま（呼び出し側でパース）
			$gemini_options['response_mime_type'] = 'application/json';
		}
		unset( $gemini_options['json'] );

		return $api->generate_content( $prompt, $gemini_options );
	}

	public function test_connection() {
		$key = $this->resolve_api_key();
		if ( '' === $key ) {
			return new WP_Error(
				'ai_missing_credentials',
				'Gemini API キーが設定されていません。プロフィールの個人キー、またはAI設定のサイト共通キーを登録してください。'
			);
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'key'      => $key,
					'pageSize' => 1,
				),
				'https://generativelanguage.googleapis.com/v1beta/models'
			),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_unavailable', 'Gemini API への接続に失敗しました。詳細: ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error( 'ai_error', 'Gemini API 接続テストに失敗しました (HTTP ' . $status . ')。API キーを確認してください。' );
		}

		return true;
	}

	public function get_label(): string {
		return 'Gemini';
	}

	public function get_model(): string {
		if ( '' !== $this->model_override ) {
			return $this->model_override;
		}
		if ( function_exists( 'node_get_user_gemini_model' ) && $this->user_id > 0 ) {
			return node_get_user_gemini_model( $this->user_id );
		}
		if ( function_exists( 'node_get_default_gemini_model' ) ) {
			return node_get_default_gemini_model();
		}

		// テーマ側のモデル管理が無い場合も特定世代へ固定しない。
		$selected = class_exists( 'Node_AI_Fact_Check_Models' ) ? Node_AI_Fact_Check_Models::select() : '';

		return ( is_string( $selected ) && '' !== $selected ) ? $selected : '';
	}

	/**
	 * キー解決: user meta → サイト共通 option → 開発用定数（Node_Gemini_API と同順）
	 */
	private function resolve_api_key(): string {
		$key = '';
		if ( function_exists( 'node_get_user_gemini_api_key' ) && $this->user_id > 0 ) {
			$key = node_get_user_gemini_api_key( $this->user_id );
		}
		if ( '' === $key ) {
			$key = trim( (string) get_option( 'node_ai_gemini_api_key', '' ) );
		}
		if ( '' === $key && defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY ) {
			$key = (string) GEMINI_API_KEY;
		}
		return $key;
	}
}
