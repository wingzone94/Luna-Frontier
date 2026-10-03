<?php

declare(strict_types=1);
/**
 * Gemini API モデル一覧の動的取得
 *
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient キー
 */
function node_gemini_models_cache_key(): string {
	return 'node_gemini_models_cache';
}

/**
 * protobuf Duration 文字列（例: "37s" / "1.5s" / "1m30s" / "1h"）を秒に変換する。
 */
if ( ! function_exists( 'node_gemini_parse_duration_seconds' ) ) {
	function node_gemini_parse_duration_seconds( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		// 単純な秒表現（"37s" / "56.7s"）。
		if ( preg_match( '/^([0-9]+(?:\.[0-9]+)?)s$/', $value, $m ) ) {
			return (int) ceil( (float) $m[1] );
		}
		// 複合表現（"1h2m3s" 等）。
		$total = 0;
		if ( preg_match( '/([0-9]+)\s*h/', $value, $m ) ) {
			$total += (int) $m[1] * 3600;
		}
		if ( preg_match( '/([0-9]+)\s*m(?!s)/', $value, $m ) ) {
			$total += (int) $m[1] * 60;
		}
		if ( preg_match( '/([0-9]+(?:\.[0-9]+)?)\s*s/', $value, $m ) ) {
			$total += (int) ceil( (float) $m[1] );
		}
		return $total;
	}
}

/**
 * Gemini のエラー詳細（RetryInfo）から再試行までの秒数を取り出す。
 *
 * @param mixed $data デコード済みレスポンス。
 */
if ( ! function_exists( 'node_gemini_extract_retry_seconds' ) ) {
	/**
	 * 429 応答の QuotaFailure から、超過した上限の種別と値を取り出す。
	 *
	 * Google は quotaId に種別を明示する。例:
	 *   GenerateRequestsPerMinutePerProjectPerModel-FreeTier → 毎分
	 *   GenerateRequestsPerDayPerProjectPerModel-FreeTier    → 日次
	 * RetryInfo の retryDelay は日次上限でも短い秒数が返ることがあるため、
	 * 「何分後に復帰するか」の判断に retryDelay 単体を使ってはいけない。
	 *
	 * @param mixed $data デコード済みレスポンス。
	 * @return array{scope: string, limit: string, metric: string} scope は 'minute' | 'day' | ''。
	 */
	function node_gemini_extract_quota_violation( $data ): array {
		$result = array(
			'scope'  => '',
			'limit'  => '',
			'metric' => '',
		);

		if ( ! is_array( $data ) ) {
			return $result;
		}

		foreach ( (array) ( $data['error']['details'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) || false === stripos( (string) ( $entry['@type'] ?? '' ), 'QuotaFailure' ) ) {
				continue;
			}

			foreach ( (array) ( $entry['violations'] ?? array() ) as $violation ) {
				if ( ! is_array( $violation ) ) {
					continue;
				}

				$quota_id = (string) ( $violation['quotaId'] ?? '' );
				$value    = trim( (string) ( $violation['quotaValue'] ?? '' ) );

				if ( false !== stripos( $quota_id, 'PerDay' ) ) {
					$result['scope'] = 'day';
					$result['limit'] = '' !== $value ? $value . '回/日' : '';
				} elseif ( false !== stripos( $quota_id, 'PerMinute' ) ) {
					// 日次と毎分が同時に返る場合は日次を優先する（復帰が遅い方が実態）
					if ( 'day' !== $result['scope'] ) {
						$result['scope'] = 'minute';
						$result['limit'] = '' !== $value ? $value . '回/分' : '';
					}
				}

				if ( '' === $result['metric'] ) {
					$result['metric'] = (string) ( $violation['quotaMetric'] ?? '' );
				}
			}
		}

		return $result;
	}

	function node_gemini_extract_retry_seconds( $data ): int {
		if ( ! is_array( $data ) ) {
			return 0;
		}
		$details = $data['error']['details'] ?? array();
		foreach ( (array) $details as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$type = (string) ( $entry['@type'] ?? '' );
			if ( false !== stripos( $type, 'RetryInfo' ) && isset( $entry['retryDelay'] ) ) {
				return node_gemini_parse_duration_seconds( (string) $entry['retryDelay'] );
			}
		}
		return 0;
	}
}

/**
 * 無料枠の日次 quota がリセットされる次回時刻（太平洋時間の翌0時）を UNIX 時刻で返す。
 * Gemini API の無料枠 RPD は太平洋時間の深夜0時にリセットされる。
 */
if ( ! function_exists( 'node_gemini_next_daily_quota_reset' ) ) {
	function node_gemini_next_daily_quota_reset(): int {
		try {
			$pt   = new DateTimeZone( 'America/Los_Angeles' );
			$next = new DateTime( 'tomorrow 00:00:00', $pt );
			return $next->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}
}

/**
 * UNIX 時刻を日本時間（Asia/Tokyo）の文字列に整形する。
 *
 * @param int    $timestamp UNIX 時刻。
 * @param string $format    日付フォーマット。
 * @return string
 */
if ( ! function_exists( 'node_gemini_format_jst' ) ) {
	function node_gemini_format_jst( int $timestamp, string $format ): string {
		try {
			return wp_date( $format, $timestamp, new DateTimeZone( 'Asia/Tokyo' ) );
		} catch ( Exception $e ) {
			return wp_date( $format, $timestamp );
		}
	}
}

/**
 * Gemini API のエラー応答から、利用者向けの日本語エラーメッセージを生成する。
 * 429（利用上限超過 / RESOURCE_EXHAUSTED）の場合は解除予定時刻も付与する。
 * 503（モデル混雑）の場合は、クォータ超過と区別して一時的な再試行案内を返す。
 *
 * @param int    $status HTTP ステータスコード（0 = 不明）。
 * @param mixed  $data   デコード済みレスポンス（連想配列想定）。
 * @param string $raw    生のレスポンス本文（フォールバック用）。
 * @return string
 */
if ( ! function_exists( 'node_gemini_format_api_error' ) ) {
	function node_gemini_format_api_error( int $status, $data, string $raw = '' ): string {
		$detail     = '';
		$api_status = '';
		if ( is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ) {
			$detail     = (string) ( $data['error']['message'] ?? '' );
			$api_status = (string) ( $data['error']['status'] ?? '' );
		}
		if ( '' === $detail ) {
			$detail = '' !== trim( $raw ) ? wp_trim_words( $raw, 40, '…' ) : 'Gemini API から有効な応答が得られませんでした。';
		}

		$is_quota = ( 429 === $status ) || ( 'RESOURCE_EXHAUSTED' === $api_status );

		if ( $is_quota ) {
			// 冗長な英語メッセージ（リンク等）を省略してUIの崩れを防ぐ
			if ( false !== stripos( $detail, 'You exceeded your current quota' ) ) {
				$detail = 'APIの無料枠または課金プランの利用枠を超過しました。';
			}

			$violation = node_gemini_extract_quota_violation( $data );
			$retry     = node_gemini_extract_retry_seconds( $data );
			$limit     = '' !== $violation['limit'] ? sprintf( '（上限 %s）', $violation['limit'] ) : '';

			// 日次上限は retryDelay が短く返ることがある。
			// それを「解除予定」として出すと復帰時刻の虚偽表示になるため、必ず日次リセット時刻を使う
			if ( 'day' === $violation['scope'] ) {
				$daily_reset = node_gemini_next_daily_quota_reset();

				return sprintf(
					'Gemini API の1日あたりの利用上限%sに達しました。日本時間 %s 頃（太平洋時間の翌0時）にリセットされます。それまでこのモデルは使えません。上限緩和には課金プランの確認が必要です。',
					$limit,
					node_gemini_format_jst( $daily_reset, 'n月j日 H:i' )
				);
			}

			if ( 'minute' === $violation['scope'] ) {
				$wait = $retry > 0 ? $retry : 60;

				return sprintf(
					'Gemini API の1分あたりのリクエスト上限%sに達しました。約 %d 秒後（日本時間 %s 頃）に解除されます。連続実行を控えるか、上限の大きいモデルへ切り替えてください。',
					$limit,
					$wait,
					node_gemini_format_jst( time() + $wait, 'n月j日 H:i:s' )
				);
			}

			// 種別が判別できない場合は、復帰時刻を断定しない
			if ( $retry > 0 ) {
				return sprintf(
					'Gemini API の利用上限に達しました。約 %d 秒後（日本時間 %s 頃）の再試行が案内されていますが、日次上限の場合は解消しません。詳細: %s',
					$retry,
					node_gemini_format_jst( time() + $retry, 'n月j日 H:i:s' ),
					$detail
				);
			}

			$daily_reset = node_gemini_next_daily_quota_reset();
			if ( $daily_reset > 0 ) {
				return sprintf(
					'Gemini API の利用上限（quota）に達しました。無料枠の日次上限であれば、日本時間 %s 頃（太平洋時間の翌0時）にリセットされます。上限緩和には課金プランの確認が必要です。詳細: %s',
					node_gemini_format_jst( $daily_reset, 'n月j日 H:i' ),
					$detail
				);
			}

			return 'Gemini API の利用上限（quota）に達しました。上限緩和には課金プランの確認が必要です。詳細: ' . $detail;
		}

		if ( 503 === $status || 'UNAVAILABLE' === $api_status ) {
			return 'Gemini API のモデルが一時的に混雑しています。クォータ超過や課金エラーではないため、数分置いてから再実行してください。急ぎの場合は、利用モデルを Flash 系へ切り替えると成功しやすくなります。詳細: ' . $detail;
		}

		if ( $status > 0 ) {
			return sprintf( 'Gemini API エラー (HTTP %d): %s', $status, $detail );
		}

		return 'Gemini API エラー: ' . $detail;
	}
}

/**
 * フォールバック用の静的モデル一覧
 *
 * @return array<string, string>
 */
function node_get_gemini_model_fallback_options(): array {
	// 2026-07-26 時点で実在確認済みのIDのみ（新しい Flash 系を先頭）。
	// Pro 系は無料枠対象外のため載せない。gemini-3.6-flash-lite は API に存在しない（Lite の最新は 3.5）
	return array(
		'gemini-3.6-flash'       => 'Gemini 3.6 Flash',
		'gemini-3.5-flash'       => 'Gemini 3.5 Flash',
		'gemini-3.5-flash-lite'  => 'Gemini 3.5 Flash-Lite',
		'gemini-3.1-flash-lite'  => 'Gemini 3.1 Flash-Lite',
		'gemini-2.5-flash'       => 'Gemini 2.5 Flash',
	);
}

/**
 * モデル一覧キャッシュを削除
 */
function node_clear_gemini_models_cache(): void {
	delete_transient( node_gemini_models_cache_key() );
}

/**
 * モデル一覧取得に使う API キーを解決
 *
 * @param int $user_id 優先するユーザーID。0 の場合は現在のユーザー。
 */
function node_resolve_gemini_api_key_for_models( int $user_id = 0 ): string {
	if ( function_exists( 'node_get_user_gemini_api_key' ) ) {
		$key = node_get_user_gemini_api_key( $user_id > 0 ? $user_id : 0 );
		if ( '' !== $key ) {
			return $key;
		}
	}

	$site_key = trim( (string) get_option( 'node_ai_gemini_api_key', '' ) );
	if ( '' !== $site_key ) {
		return $site_key;
	}

	if ( defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY ) {
		return (string) GEMINI_API_KEY;
	}

	return '';
}

/**
 * API レスポンスから generateContent 対応の Gemini モデルを抽出
 *
 * @param array<string, mixed> $model API モデルオブジェクト。
 * @return array{0: string, 1: string}|null [ id, label ]
 */
function node_parse_gemini_model_entry( array $model ): ?array {
	$name = (string) ( $model['name'] ?? '' );
	if ( ! str_starts_with( $name, 'models/' ) ) {
		return null;
	}

	$id = substr( $name, 7 );
	if ( ! preg_match( '/^gemini-[a-z0-9][a-z0-9.-]*$/i', $id ) ) {
		return null;
	}

	// 用途違い（埋め込み・画像・音声・ロボティクス等）はテキスト生成に使えないため除外
	if ( preg_match( '/(embedding|embed|aqa|imagen|veo|tts|live|computer-use|image|robotics)/i', $id ) ) {
		return null;
	}

	$methods = (array) ( $model['supportedGenerationMethods'] ?? array() );
	if ( ! in_array( 'generateContent', $methods, true ) ) {
		return null;
	}

	// --- ここから 1.3 の選別（選べるモデルだけをプルダウンに出す） ---
	// Google の ListModels はサポート終了モデルを一覧から落とすため、
	// 「API に載っている＝まだ使える」が前提。そのうえで実運用で選ぶ意味のないものを除く。

	// 旧世代（2.5 未満）は除外。思考量に非対応で出力上限も 8192 と小さく、
	// Google のサポート終了ラインに乗るのもこの帯（2.0 / 1.x）
	if ( preg_match( '/^gemini-(\d+(?:\.\d+)?)-/', $id, $generation ) && (float) $generation[1] < 2.5 ) {
		return null;
	}

	// 枝番付きスナップショット（-001 等）は同名の安定版エイリアスと重複する
	if ( preg_match( '/-\d{3}$/', $id ) ) {
		return null;
	}

	// 浮動エイリアス（gemini-flash-latest 等）は指す先が予告なく変わるため運用に使わない
	if ( str_ends_with( $id, '-latest' ) ) {
		return null;
	}

	// 特殊用途（ツール特化 / Omni）は本テーマの用途では選ばせない
	if ( preg_match( '/(customtools|omni)/i', $id ) ) {
		return null;
	}

	// 実行時に 404（no longer available）を返したモデルは以後出さない
	if ( function_exists( 'node_gemini_get_retired_models' ) && in_array( $id, node_gemini_get_retired_models(), true ) ) {
		return null;
	}

	// Pro 系は出さない。
	// 2026-04-01 以降、Pro は無料枠から外れており（Flash / Flash-Lite のみ無料）、
	// 選べても必ず 429 になる。Pro を使いたい場合はブラウザ版を手動で使う運用とする。
	// 課金プランへ移行したら node_gemini_allow_pro_models フィルタで解禁できる
	if ( preg_match( '/-pro(?:-|$)/i', $id ) && ! apply_filters( 'node_gemini_allow_pro_models', false ) ) {
		return null;
	}

	$label = trim( (string) ( $model['displayName'] ?? '' ) );
	if ( '' === $label ) {
		$label = $id;
	}

	return array( $id, $label );
}

/**
 * Gemini API からモデル一覧を取得
 *
 * @param string $api_key       API キー。
 * @param bool   $force_refresh キャッシュを無視する。
 * @return array{models: array<string, string>, from_api: bool, fetched_at: int}|WP_Error
 */
function node_fetch_gemini_models_from_api( string $api_key, bool $force_refresh = false ) {
	$cache_key   = node_gemini_models_cache_key();
	$cache_hours = (int) apply_filters( 'node_gemini_models_cache_hours', 6 );

	if ( ! $force_refresh ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached['models'] ) && is_array( $cached['models'] ) ) {
			return $cached;
		}
	}

	if ( '' === trim( $api_key ) ) {
		return new WP_Error( 'missing_api_key', 'API キーが未設定です。' );
	}

	$models          = array();
	$thinking_models = array();
	$last_error      = '';
	$page_url        = add_query_arg(
		array(
			'key'      => $api_key,
			'pageSize' => 100,
		),
		'https://generativelanguage.googleapis.com/v1beta/models'
	);

	// 管理画面の同期リクエストを最大30秒に抑える（1ページ100件）。
	for ( $page = 0; $page < 3; $page++ ) {
		$response = wp_remote_get(
			$page_url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$err_msg = $response->get_error_message();
			if ( strpos( $err_msg, 'timed out' ) !== false ) {
				$last_error = 'Gemini APIからの応答がタイムアウトしました。しばらく待ってから再度お試しください。';
			} else {
				$last_error = '詳細: ' . $err_msg;
			}
			break;
		}

		$http_status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_status ) {
			$err_body = (string) wp_remote_retrieve_body( $response );
			$last_error = function_exists( 'node_gemini_format_api_error' )
				? node_gemini_format_api_error( $http_status, json_decode( $err_body, true ), $err_body )
				: ( 'Gemini API エラー (HTTP ' . $http_status . ')' );
			break;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			break;
		}

		foreach ( (array) ( $data['models'] ?? array() ) as $model ) {
			if ( ! is_array( $model ) ) {
				continue;
			}

			$parsed = node_parse_gemini_model_entry( $model );
			if ( null === $parsed ) {
				continue;
			}

			$models[ $parsed[0] ] = $parsed[1];

			// 思考量に対応するかは API の thinking フラグを正とする
			if ( ! empty( $model['thinking'] ) ) {
				$thinking_models[] = $parsed[0];
			}
		}

		$next_token = (string) ( $data['nextPageToken'] ?? '' );
		if ( '' === $next_token ) {
			break;
		}

		$page_url = add_query_arg(
			array(
				'key'       => $api_key,
				'pageSize'  => 100,
				'pageToken' => $next_token,
			),
			'https://generativelanguage.googleapis.com/v1beta/models'
		);
	}

	if ( empty( $models ) ) {
		$stale = get_transient( $cache_key );
		if ( is_array( $stale ) && ! empty( $stale['models'] ) ) {
			return $stale;
		}

		return new WP_Error(
			'models_fetch_failed',
			'' !== $last_error
				? 'Gemini API からモデル一覧を取得できませんでした。' . $last_error
				: 'Gemini API からモデル一覧を取得できませんでした。'
		);
	}

	// 安定版が存在する preview は重複なので落とす（例: gemini-3.1-flash-lite-preview）
	foreach ( array_keys( $models ) as $id ) {
		if ( ! str_ends_with( $id, '-preview' ) ) {
			continue;
		}

		$stable = substr( $id, 0, -strlen( '-preview' ) );
		if ( isset( $models[ $stable ] ) ) {
			unset( $models[ $id ] );
		}
	}

	// 思考量（High / Low）は 1.3 でモデル一覧から分離し、別プルダウンで指定する。
	// ここでは「どのモデルが思考量に対応するか」を API の thinking フラグから記録するだけにする
	// （従来は正規表現で推測していたため、Lite 系など実際は対応しているモデルを取りこぼしていた）

	// 新しい世代を上に出す（バージョン降順 → 同世代はラベル順）
	uksort(
		$models,
		static function ( string $id_a, string $id_b ) use ( $models ): int {
			$version = static function ( string $id ): float {
				return preg_match( '/^gemini-(\d+(?:\.\d+)?)-/', $id, $m ) ? (float) $m[1] : 0.0;
			};

			$diff = $version( $id_b ) <=> $version( $id_a );

			return 0 !== $diff ? $diff : strnatcasecmp( $models[ $id_a ], $models[ $id_b ] );
		}
	);

	$payload = array(
		'models'    => $models,
		'thinking'  => $thinking_models,
		'from_api'  => true,
		'fetched_at'=> time(),
	);

	set_transient( $cache_key, $payload, $cache_hours * HOUR_IN_SECONDS );

	return $payload;
}

/**
 * 表示用モデル一覧（API 優先、失敗時フォールバック）
 *
 * @param int $user_id モデル取得に使う API キーのユーザー。
 * @return array<string, string>
 */
function node_get_gemini_model_options( int $user_id = 0 ): array {
	$api_key = node_resolve_gemini_api_key_for_models( $user_id );
	$result  = node_fetch_gemini_models_from_api( $api_key );

	if ( is_array( $result ) && ! empty( $result['models'] ) ) {
		return $result['models'];
	}

	return node_get_gemini_model_fallback_options();
}

/**
 * モデル一覧の取得メタ情報
 *
 * @param int $user_id ユーザーID。
 * @return array{from_api: bool, fetched_at: int}
 */
function node_get_gemini_models_meta( int $user_id = 0 ): array {
	$api_key = node_resolve_gemini_api_key_for_models( $user_id );
	$cached  = get_transient( node_gemini_models_cache_key() );

	if ( is_array( $cached ) && ! empty( $cached['models'] ) && '' !== $api_key ) {
		return array(
			'from_api'   => ! empty( $cached['from_api'] ),
			'fetched_at' => (int) ( $cached['fetched_at'] ?? 0 ),
		);
	}

	return array(
		'from_api'   => false,
		'fetched_at' => 0,
	);
}

/**
 * ユーザー向けモデル選択肢（保存済みモデルも含む）
 *
 * @param int $user_id ユーザーID。
 * @return array<string, string>
 */
function node_get_gemini_model_options_for_user( int $user_id ): array {
	$options = node_get_gemini_model_options( $user_id );
	$saved   = get_user_meta( $user_id, 'node_gemini_model', true );
	$saved   = is_string( $saved ) ? trim( $saved ) : '';

	// 思考量は別プルダウンへ分離したので、モデル部分だけで照合する。
	// 保存値をそのまま足すと `gemini-3.6-flash@low` のような項目が一覧に混ざる
	$saved = node_split_gemini_model( $saved )['model'];

	if ( '' !== $saved && ! isset( $options[ $saved ] ) ) {
		$options = array_merge(
			array( $saved => sprintf( '%s (%s)', $saved, __( '保存済み', 'node' ) ) ),
			$options
		);
	}

	return $options;
}

/**
 * デフォルトモデル（Flash 系を優先）
 */
function node_get_default_gemini_model(): string {
	// AI設定のサイト既定モデル（option）が最優先
	$site_default = trim( (string) get_option( 'node_ai_gemini_model', '' ) );
	if ( '' !== $site_default && node_is_valid_gemini_model_id( $site_default ) ) {
		return $site_default;
	}

	$options = node_get_gemini_model_options();

	// Flash 系のうち「最新バージョン」を既定にする。
	// 一覧はラベルの自然順（昇順）で並ぶため、単純に先頭から探すと最古（2.0系）を掴んでしまう。
	// 思考量つき仮想ID（@high / @low）・Lite・preview・枝番付き（-001 等）は既定にしない
	$best     = '';
	$best_ver = -1.0;

	foreach ( array_keys( $options ) as $id ) {
		if ( false !== strpos( $id, '@' ) ) {
			continue;
		}

		if ( ! preg_match( '/^gemini-(\d+(?:\.\d+)?)-flash$/', $id, $matches ) ) {
			continue;
		}

		$version = (float) $matches[1];
		if ( $version > $best_ver ) {
			$best_ver = $version;
			$best     = $id;
		}
	}

	if ( '' !== $best ) {
		return $best;
	}

	// 素の `gemini-<版>-flash` が無い場合は従来どおり flash を含む最初のIDへ退避
	foreach ( array_keys( $options ) as $id ) {
		if ( false !== stripos( $id, 'flash' ) && false === strpos( $id, '@' ) ) {
			return $id;
		}
	}

	$keys = array_keys( $options );
	return $keys[0] ?? 'gemini-3.5-flash';
}

/**
 * 保存可能なモデル ID か
 *
 * @param string $model モデル ID。
 */
function node_is_valid_gemini_model_id( string $model ): bool {
	return (bool) preg_match( '/^gemini-[a-z0-9][a-z0-9.-]*(?:@(?:high|low))?$/i', $model );
}

/**
 * 思考量（thinkingLevel）の選択肢
 *
 * @return array<string, string>
 */
function node_gemini_thinking_levels(): array {
	return array(
		''     => '標準（モデル既定）',
		'high' => 'High（じっくり考える）',
		'low'  => 'Low（速く答える）',
	);
}

/**
 * 保存値 `<モデルID>@<思考量>` をモデルと思考量に分解する。
 *
 * 1.3 で思考量はモデル一覧から分離したが、保存形式は 1.2 系と互換のまま
 * （Node_Gemini_API が @high / @low を解釈して thinkingConfig へ変換する）。
 *
 * @param string $stored 保存されているモデル指定。
 * @return array{model: string, thinking: string}
 */
function node_split_gemini_model( string $stored ): array {
	$stored = trim( $stored );

	if ( preg_match( '/^(.+)@(high|low)$/i', $stored, $matches ) ) {
		return array(
			'model'    => $matches[1],
			'thinking' => strtolower( $matches[2] ),
		);
	}

	return array(
		'model'    => $stored,
		'thinking' => '',
	);
}

/**
 * モデルと思考量を保存形式へ結合する。
 *
 * @param string $model    モデル ID（@ を含まない）。
 * @param string $thinking 思考量（'' | 'high' | 'low'）。
 */
function node_join_gemini_model( string $model, string $thinking ): string {
	$model    = node_split_gemini_model( $model )['model'];
	$thinking = strtolower( trim( $thinking ) );

	if ( '' === $model || ! in_array( $thinking, array( 'high', 'low' ), true ) ) {
		return $model;
	}

	return $model . '@' . $thinking;
}

/**
 * そのモデルが思考量の指定に対応しているか（API の thinking フラグが正）。
 *
 * @param string $model   モデル ID。
 * @param int    $user_id 一覧取得に使う API キーのユーザー。
 */
function node_gemini_model_supports_thinking( string $model, int $user_id = 0 ): bool {
	$model = node_split_gemini_model( $model )['model'];
	if ( '' === $model ) {
		return false;
	}

	return in_array( $model, node_get_gemini_thinking_models( $user_id ), true );
}

/**
 * 思考量に対応するモデル ID の一覧。
 *
 * @param int $user_id 一覧取得に使う API キーのユーザー。
 * @return array<int, string>
 */
function node_get_gemini_thinking_models( int $user_id = 0 ): array {
	$api_key = node_resolve_gemini_api_key_for_models( $user_id );
	$result  = node_fetch_gemini_models_from_api( $api_key );

	$candidates = ( is_wp_error( $result ) || empty( $result['thinking'] ) || ! is_array( $result['thinking'] ) )
		? array_keys( node_get_gemini_model_fallback_options() )
		: $result['thinking'];

	// thinkingLevel（High / Low）は Gemini 3 系以降の指定方法。
	// 2.5 系は thinking 自体は行うが指定方式が異なるため、選択肢を出さない
	// （出しても効かない選択肢を見せないための絞り込み）。
	return array_values(
		array_filter(
			$candidates,
			static function ( string $id ): bool {
				return (bool) preg_match( '/^gemini-(\d+(?:\.\d+)?)-/', $id, $matches )
					&& (float) $matches[1] >= 3.0;
			}
		)
	);
}

/**
 * Gemini Quota Limits
 *
 * @param string $model モデルID
 * @return array{rpm: int, tpm: int, rpd: int}
 */
function node_gemini_get_quota_limits( string $model ): array {
	if ( strpos( $model, '3.1-pro' ) !== false ) {
		return array( 'rpm' => 0, 'tpm' => 0, 'rpd' => 0 );
	}
	if ( strpos( $model, 'pro' ) !== false ) {
		return array( 'rpm' => 2, 'tpm' => 32000, 'rpd' => 50 );
	}
	return array( 'rpm' => 15, 'tpm' => 1000000, 'rpd' => 1500 );
}

/**
 * Get User Quota Usage
 *
 * @param int $user_id
 * @param string $model
 * @return array
 */
function node_gemini_get_user_quota_usage( int $user_id, string $model ): array {
	$key = 'node_gemini_usage_' . $user_id;
	$data = get_transient( $key );
	if ( ! is_array( $data ) ) {
		$data = array();
	}
	
	$now = time();
	$model_data = $data[ $model ] ?? array(
		'rpm' => array( 'count' => 0, 'expires' => $now + 60 ),
		'tpm' => array( 'count' => 0, 'expires' => $now + 60 ),
		'rpd' => array( 'count' => 0, 'expires' => node_gemini_next_daily_quota_reset() ),
		'last_error' => array(),
	);

	// Expire checks
	if ( isset( $model_data['rpm']['expires'] ) && $now >= $model_data['rpm']['expires'] ) {
		$model_data['rpm'] = array( 'count' => 0, 'expires' => $now + 60 );
	}
	if ( isset( $model_data['tpm']['expires'] ) && $now >= $model_data['tpm']['expires'] ) {
		$model_data['tpm'] = array( 'count' => 0, 'expires' => $now + 60 );
	}
	if ( isset( $model_data['rpd']['expires'] ) && $now >= $model_data['rpd']['expires'] ) {
		$model_data['rpd'] = array( 'count' => 0, 'expires' => node_gemini_next_daily_quota_reset() );
	}

	// Error expiry check (e.g. 429 short-term retryDelay)
	if ( ! empty( $model_data['last_error']['expires'] ) && $now >= $model_data['last_error']['expires'] ) {
		$model_data['last_error'] = array();
	}

	return $model_data;
}

/**
 * 提供終了が判明したモデルIDの一覧（option に永続化）。
 *
 * @return array<int, string>
 */
function node_gemini_get_retired_models(): array {
	$stored = get_option( 'node_gemini_retired_models', array() );

	return is_array( $stored ) ? array_values( array_unique( array_map( 'strval', $stored ) ) ) : array();
}

/**
 * API 応答から「提供終了」を検出したら記録する。
 *
 * ListModels には載り続けるのに generateContent が 404
 * （no longer available）を返すモデルがある（実測: gemini-2.5-flash-lite）。
 * 一覧に出したままだと必ず失敗する選択肢を見せることになるため、
 * 一度検出したら以後プルダウンから外す。
 *
 * @param string $model  モデルID。
 * @param int    $status HTTP ステータス。
 * @param mixed  $data   デコード済み応答。
 */
function node_gemini_maybe_record_retired_model( string $model, int $status, $data ): void {
	if ( 404 !== $status ) {
		return;
	}

	$message = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
	if ( false === stripos( $message, 'no longer available' ) && false === stripos( $message, 'not found' ) ) {
		return;
	}

	$model = node_split_gemini_model( $model )['model'];
	if ( '' === $model ) {
		return;
	}

	$retired = node_gemini_get_retired_models();
	if ( in_array( $model, $retired, true ) ) {
		return;
	}

	$retired[] = $model;
	update_option( 'node_gemini_retired_models', $retired, false );

	// 一覧キャッシュを捨てて次回から除外されるようにする
	node_clear_gemini_models_cache();
}

/**
 * 直近の 429 でそのモデルが利用不可のままか（リセット時刻前か）を判定する。
 *
 * 枯渇後も API を叩き続けると、失敗するだけの往復でレート枠と時間を浪費する。
 * 送信前にこれで弾き、リセット時刻を添えて即座に失敗させる。
 *
 * @param int    $user_id ユーザーID。
 * @param string $model   モデルID（思考量つき仮想IDでも可）。
 * @return array{blocked: bool, until: int}
 */
function node_gemini_get_quota_block( int $user_id, string $model ): array {
	if ( function_exists( 'node_split_gemini_model' ) ) {
		$model = node_split_gemini_model( $model )['model'];
	}

	$usage   = node_gemini_get_user_quota_usage( $user_id, $model );
	$expires = (int) ( $usage['last_error']['expires'] ?? 0 );
	$now     = time();

	// last_error は node_gemini_get_user_quota_usage 側で期限切れなら空になる
	if ( empty( $usage['last_error'] ) || $expires <= $now ) {
		return array(
			'blocked' => false,
			'until'   => 0,
		);
	}

	// 停止は必ず有限時間に収める。
	// 旧実装は分類できない 429 を「日次リセットまで」と記録しており、
	// 実際には使えるモデルが翌日まで締め出されていた。
	// 上限を超える古い記録はブロックとして扱わず、再挑戦を許す
	$cooldown = (int) apply_filters( 'node_gemini_quota_cooldown', 10 * MINUTE_IN_SECONDS );

	if ( $expires > $now + $cooldown ) {
		return array(
			'blocked' => false,
			'until'   => 0,
		);
	}

	return array(
		'blocked' => true,
		'until'   => $expires,
	);
}

/**
 * Record Usage
 */
function node_gemini_record_usage( int $user_id, string $model, int $tokens, int $requests = 1 ): void {
	$key = 'node_gemini_usage_' . $user_id;
	$data = get_transient( $key );
	if ( ! is_array( $data ) ) {
		$data = array();
	}

	$model_data = node_gemini_get_user_quota_usage( $user_id, $model );
	$model_data['rpm']['count'] += $requests;
	$model_data['tpm']['count'] += $tokens;
	$model_data['rpd']['count'] += $requests;
	
	// If it succeeded, clear any previous error
	$model_data['last_error'] = array();

	$data[ $model ] = $model_data;
	set_transient( $key, $data, DAY_IN_SECONDS );
}

/**
 * Record Quota Error
 */
function node_gemini_record_quota_error( int $user_id, string $model, int $status, $api_data ): void {
	if ( 429 !== $status && ( !is_array($api_data) || !isset($api_data['error']['status']) || 'RESOURCE_EXHAUSTED' !== $api_data['error']['status'] ) ) {
		return;
	}

	$key = 'node_gemini_usage_' . $user_id;
	$data = get_transient( $key );
	if ( ! is_array( $data ) ) {
		$data = array();
	}

	$model_data = node_gemini_get_user_quota_usage( $user_id, $model );

	$retry     = node_gemini_extract_retry_seconds( $api_data );
	$violation = node_gemini_extract_quota_violation( $api_data );

	// 送信前ガードの停止時間を決める。
	//
	// 重要: 日次上限に見えても、実際には次のリクエストが通ることがある
	// （429 の分類が付かない応答もある）。そこで「丸一日ロックする」ことはせず、
	// 上限に達したモデルでも一定時間後に必ず再挑戦できるようにする。
	// こうしないと、実際は使えるモデルを翌日まで締め出す事故が起きる。
	$cooldown = (int) apply_filters( 'node_gemini_quota_cooldown', 10 * MINUTE_IN_SECONDS );

	if ( 'minute' === $violation['scope'] ) {
		// 毎分上限は解除時刻が明確なので RetryInfo をそのまま使う
		$wait = $retry > 0 ? $retry : 60;
	} elseif ( 'day' === $violation['scope'] ) {
		// 日次上限は無駄打ちが多くなるため長めに空けるが、上限は cooldown まで
		$wait = $cooldown;
	} else {
		// 種別不明の 429。短く空けて再挑戦させる（誤って長時間止めない）
		$wait = $retry > 0 ? min( $retry, $cooldown ) : 60;
	}

	$model_data['last_error'] = array(
		'status'  => $status,
		'retry'   => $retry,
		'scope'   => $violation['scope'],
		'expires' => time() + $wait,
	);

	$data[ $model ] = $model_data;
	set_transient( $key, $data, DAY_IN_SECONDS );
}
