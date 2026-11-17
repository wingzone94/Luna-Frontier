<?php
/**
 * ファクトチェックの自動実行（下書き保存時）と公開ゲート
 *
 * 仕様（2026-07-19 ユーザー指示）:
 * - APIキー登録済みライターの記事は、下書き保存時にファクトチェックを自動実行（cronで非同期）
 * - ファクトチェック未実行の記事は公開できない（キー登録者のみ。未登録者はゲート対象外）
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'node_ai_author_has_api_key' ) ) {
	/**
	 * 指定ユーザーが選択中のAIプロバイダーを実行できるか。
	 *
	 * @param int $user_id ユーザーID。
	 */
	function node_ai_author_has_api_key( int $user_id ): bool {
		if ( function_exists( 'node_ai_core' ) ) {
			$provider_id = node_ai_core()->get_fact_check_provider_id();
			if ( ! node_ai_core()->is_enabled() ) {
				return false;
			}
			if ( 'off' === $provider_id ) {
				return false;
			}
			if ( 'ollama' === $provider_id ) {
				return true;
			}
			if ( 'openai' === $provider_id ) {
				$preset = (string) get_option( 'node_ai_openai_preset', 'local' );
				return ! in_array( $preset, array( 'openai', 'dashscope' ), true ) || '' !== trim( (string) get_option( 'node_ai_openai_api_key', '' ) );
			}
		}

		$key = '';
		if ( function_exists( 'node_get_user_gemini_api_key' ) && $user_id > 0 ) {
			$key = node_get_user_gemini_api_key( $user_id );
		} elseif ( $user_id > 0 ) {
			$key = trim( (string) get_user_meta( $user_id, 'node_gemini_api_key', true ) );
		}

		if ( '' === $key ) {
			$key = trim( (string) get_option( 'node_ai_gemini_api_key', '' ) );
		}

		if ( '' === $key && defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY ) {
			$key = (string) GEMINI_API_KEY;
		}

		return '' !== $key;
	}
}

if ( ! function_exists( 'node_ai_fact_check_content_hash' ) ) {
	/**
	 * ファクトチェック対象コンテンツのハッシュ（再チェック要否の判定用）
	 *
	 * @param WP_Post $post 投稿。
	 */
	function node_ai_fact_check_content_hash( WP_Post $post ): string {
		return md5( $post->post_title . "\n" . strip_shortcodes( strip_tags( $post->post_content ) ) );
	}
}

if ( ! function_exists( 'node_ai_store_fact_check_result' ) ) {
	/**
	 * fact_check() の結果を整形して post meta に保存（AJAX・cron 共通処理）
	 *
	 * @param int                  $post_id 投稿ID。
	 * @param array<string, mixed> $result  Node_Gemini_API::fact_check() の戻り値。
	 * @return array<string, mixed>|WP_Error 保存済みペイロード。
	 */
	function node_ai_store_fact_check_result( int $post_id, array $result ) {
		$data = node_ai_parse_json_response( (string) ( $result['text'] ?? '' ) );
		if ( null === $data || empty( $data['claims'] ) || ! is_array( $data['claims'] ) ) {
			return new WP_Error( 'fact_check_parse_failed', 'ファクトチェック結果の解析に失敗しました。' );
		}

		$grounding = is_array( $result['grounding'] ?? null ) ? $result['grounding'] : array();
		$sources   = function_exists( 'node_ai_extract_grounding_sources' )
			? node_ai_extract_grounding_sources( $grounding )
			: array();

		$context = is_array( $result['context'] ?? null ) ? $result['context'] : array();

		// 記事内リンクから取得した公式ページも参照元として残す。
		foreach ( (array) ( $context['evidence'] ?? array() ) as $fetched ) {
			if ( ! is_array( $fetched ) || empty( $fetched['url'] ) ) {
				continue;
			}

			$sources[] = array(
				'title'    => sanitize_text_field( (string) ( $fetched['title'] ?? '' ) ),
				'url'      => esc_url_raw( (string) $fetched['url'] ),
				'official' => ! empty( $fetched['official'] ),
			);
		}

		$grounded = ! empty( $context )
			? ! empty( $context['grounded'] )
			: ( ! empty( $sources ) || ! empty( $grounding['webSearchQueries'] ) );

		$payload = array(
			'summary'         => sanitize_text_field( (string) ( $data['summary'] ?? '' ) ),
			'overall_risk'    => sanitize_key( (string) ( $data['overall_risk'] ?? 'medium' ) ),
			'claims'          => array(),
			'sources'         => $sources,
			'search_queries'  => array_map( 'sanitize_text_field', (array) ( $grounding['webSearchQueries'] ?? array() ) ),
			'grounded'        => $grounded,
			'guidelines_used' => ! empty( $result['guidelines_used'] ),
			'checked_at'      => current_time( 'mysql' ),
			// 内部追跡用（UI へ全部は出さない）
			'premises'        => (array) ( $context['premises'] ?? array() ),
			'provider'        => sanitize_key( (string) ( $context['provider'] ?? 'gemini' ) ),
			'model'           => sanitize_text_field( (string) ( $context['model'] ?? '' ) ),
			'notices'         => array_map( 'sanitize_text_field', (array) ( $context['notices'] ?? array() ) ),
		);

		foreach ( $data['claims'] as $claim ) {
			if ( ! is_array( $claim ) ) {
				continue;
			}

			$evidence = array();
			foreach ( (array) ( $claim['evidence'] ?? array() ) as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['url'] ) ) {
					continue;
				}

				$evidence[] = array(
					'url'         => esc_url_raw( (string) $entry['url'] ),
					'title'       => sanitize_text_field( (string) ( $entry['title'] ?? '' ) ),
					'source_type' => sanitize_key( (string) ( $entry['source_type'] ?? 'web' ) ),
					'official'    => ! empty( $entry['official'] ),
					'checked_at'  => sanitize_text_field( (string) ( $entry['checked_at'] ?? current_time( 'mysql' ) ) ),
				);
			}

			$payload['claims'][] = array(
				'claim'      => sanitize_text_field( (string) ( $claim['claim'] ?? '' ) ),
				'status'     => sanitize_key( (string) ( $claim['status'] ?? 'uncertain' ) ),
				'confidence' => sanitize_key( (string) ( $claim['confidence'] ?? 'low' ) ),
				'note'       => sanitize_textarea_field( (string) ( $claim['note'] ?? '' ) ),
				'claim_type' => sanitize_key( (string) ( $claim['claim_type'] ?? 'fact' ) ),
				'depends_on' => isset( $claim['depends_on'] ) ? (int) $claim['depends_on'] : -1,
				'evidence'   => $evidence,
			);
		}

		if ( empty( $payload['claims'] ) ) {
			return new WP_Error( 'fact_check_no_claims', '検証対象の主張が見つかりませんでした。' );
		}

		// AI の判定をそのまま保存しない。根拠のない断定・否定命題・カスケードをここで是正し、
		// 記事全体のリスクも PHP 側で計算し直す（AJAX と cron の共通ゲート）。
		if ( function_exists( 'node_ai_fc_normalize_payload' ) ) {
			// 取得済みページの本文を渡し、モデルが挙げた根拠が実在するか照合できるようにする
			$evidence_text = array();
			foreach ( (array) ( $context['evidence'] ?? array() ) as $fetched ) {
				if ( is_array( $fetched ) && ! empty( $fetched['url'] ) && ! empty( $fetched['text'] ) ) {
					$evidence_text[ (string) $fetched['url'] ] = (string) $fetched['text'];
				}
			}

			$payload = node_ai_fc_normalize_payload(
				$payload,
				array(
					'grounded'      => $grounded,
					'premises'      => (array) $payload['premises'],
					'evidence_text' => $evidence_text,
				)
			);
		}

		update_post_meta( $post_id, '_node_ai_fact_check', wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) );
		update_post_meta( $post_id, '_node_ai_fact_check_approved', '' );
		delete_post_meta( $post_id, '_node_ai_fact_check_error' );

		// 検索なしで実行した結果は暫定。枠が回復したら自動で取り直す（node_ai_fc_recheck_degraded）
		if ( $grounded || 'gemini' !== $payload['provider'] ) {
			delete_post_meta( $post_id, '_node_ai_fact_check_degraded' );
		} else {
			update_post_meta( $post_id, '_node_ai_fact_check_degraded', '1' );
		}

		$post = get_post( $post_id );
		if ( $post instanceof WP_Post ) {
			update_post_meta( $post_id, '_node_ai_fact_check_hash', node_ai_fact_check_content_hash( $post ) );
		}

		return $payload;
	}
}

if ( ! function_exists( 'node_ai_fc_run_degraded_recheck' ) ) {
	/**
	 * cron: 検索なしで実行された（＝暫定の）ファクトチェックを、枠が回復してから取り直す。
	 *
	 * Google 検索の枠が尽きた日に保存された結果は「検証困難」に寄る。
	 * 判定自体は正しくても、編集者が見るのは実行タイミング次第の内容になるため、
	 * 検索が使える状態に戻ったら黙って作り直しておく。
	 *
	 * 無料枠を食い潰さないよう、1回の実行で扱う件数と再実行の間隔に上限を設ける。
	 */
	function node_ai_fc_run_degraded_recheck(): void {
		if ( ! function_exists( 'node_ai_core' ) || ! node_ai_core()->is_enabled() ) {
			return;
		}

		// Gemini 以外は検索併用がないため、取り直しても結果は変わらない
		if ( 'gemini' !== node_ai_core()->get_fact_check_provider_id() ) {
			return;
		}

		if ( ! class_exists( 'Node_AI_Fact_Check_Runner' ) ) {
			return;
		}

		// 検索がまだ使えないなら、取り直しても同じ結果になるだけなので何もしない
		$state = array( 'notices' => array() );
		if ( ! Node_AI_Fact_Check_Runner::grounding_allowed( $state ) ) {
			return;
		}

		$limit = (int) apply_filters( 'node_ai_fc_recheck_limit', 3 );

		// 古い記事を除く条件は SQL ではなく PHP 側で見る。
		// 下書きは post_modified_gmt が 0000-00-00 のことがあり、
		// date_query に入れると対象記事がまるごと落ちるため（実測で発生）
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'draft', 'pending', 'future', 'publish' ),
				'posts_per_page' => max( 1, $limit ) * 3,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_node_ai_fact_check_degraded',
						'value' => '1',
					),
				),
				'fields'         => 'ids',
			)
		);

		$interval = (int) apply_filters( 'node_ai_fc_recheck_interval', 20 * HOUR_IN_SECONDS );
		$max_age  = (int) apply_filters( 'node_ai_fc_recheck_max_age', 30 * DAY_IN_SECONDS );
		$done     = 0;

		foreach ( $posts as $post_id ) {
			if ( $done >= $limit ) {
				break;
			}

			$post_id = (int) $post_id;

			// 日付が壊れている（0000-00-00）場合は除外せず対象にする
			$modified = strtotime( (string) get_post_field( 'post_modified', $post_id ) );
			if ( $modified && $max_age > 0 && ( time() - $modified ) > $max_age ) {
				continue;
			}

			$last = (int) get_post_meta( $post_id, '_node_ai_fact_check_recheck_at', true );

			// 同じ記事を毎日何度も叩かない
			if ( $last > 0 && ( time() - $last ) < $interval ) {
				continue;
			}

			update_post_meta( $post_id, '_node_ai_fact_check_recheck_at', time() );

			// 本文が変わっている記事は保存フックの自動実行に任せる
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$hash = (string) get_post_meta( $post_id, '_node_ai_fact_check_hash', true );
			if ( '' !== $hash && $hash !== node_ai_fact_check_content_hash( $post ) ) {
				continue;
			}

			// 同一内容ではスキップされるため、ハッシュを外してから実行する
			delete_post_meta( $post_id, '_node_ai_fact_check_hash' );
			node_ai_run_auto_fact_check( $post_id );
			$done++;
		}
	}
}

if ( ! function_exists( 'node_ai_maybe_schedule_fact_check' ) ) {
	/**
	 * 投稿保存時: 条件を満たせばファクトチェックの自動実行を予約する
	 *
	 * @param int          $post_id 投稿ID。
	 * @param WP_Post|null $post    投稿。
	 */
	function node_ai_maybe_schedule_fact_check( int $post_id, ?WP_Post $post = null ): void {
		$post = $post ?? get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'future', 'publish' ), true ) ) {
			return;
		}
		if ( ! node_ai_author_has_api_key( (int) $post->post_author ) ) {
			return;
		}

		$content = strip_shortcodes( strip_tags( $post->post_content ) );
		if ( '' === trim( $content ) ) {
			return;
		}

		// 既存結果があり、内容が前回チェック時から変わっていなければ再実行しない
		$hash = node_ai_fact_check_content_hash( $post );
		if (
			'' !== (string) get_post_meta( $post_id, '_node_ai_fact_check', true )
			&& $hash === (string) get_post_meta( $post_id, '_node_ai_fact_check_hash', true )
		) {
			return;
		}

		// デバウンス: 同一記事の予約済みイベントは置き換える
		$timestamp = wp_next_scheduled( 'node_ai_auto_fact_check', array( $post_id ) );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'node_ai_auto_fact_check', array( $post_id ) );
		}

		wp_schedule_single_event( time() + 30, 'node_ai_auto_fact_check', array( $post_id ) );
	}
}

if ( ! function_exists( 'node_ai_run_auto_fact_check' ) ) {
	/**
	 * cron: ファクトチェックを実行して結果を保存する（承認はしない）
	 *
	 * @param int $post_id 投稿ID。
	 */
	function node_ai_run_auto_fact_check( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}

		$author_id = (int) $post->post_author;
		if ( ! node_ai_author_has_api_key( $author_id ) ) {
			return;
		}

		$content = strip_shortcodes( strip_tags( $post->post_content ) );
		if ( '' === trim( $content ) ) {
			return;
		}

		// ライターのモデル設定・利用記録を使うため、cron 内でも投稿者として実行する
		$prev_user = get_current_user_id();
		wp_set_current_user( $author_id );

		if ( ! function_exists( 'node_ai_core' ) || ! node_ai_core()->is_enabled() ) {
			wp_set_current_user( $prev_user );
			return;
		}

		$result = node_ai_core()->fact_check( $content, $post->post_title, $author_id, $post_id );

		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, '_node_ai_fact_check_error', sanitize_text_field( $result->get_error_message() ) );
			if ( function_exists( 'node_ai_dispatch_connect_event' ) ) {
				node_ai_dispatch_connect_event( 'ai_failed', $post_id, 'fact_check', $result->get_error_message() );
			}
			wp_set_current_user( $prev_user );
			return;
		}

		$stored = node_ai_store_fact_check_result( $post_id, $result );
		if ( is_wp_error( $stored ) ) {
			update_post_meta( $post_id, '_node_ai_fact_check_error', sanitize_text_field( $stored->get_error_message() ) );
			if ( function_exists( 'node_ai_dispatch_connect_event' ) ) {
				node_ai_dispatch_connect_event( 'ai_failed', $post_id, 'fact_check', $stored->get_error_message() );
			}
		} elseif ( function_exists( 'node_ai_dispatch_connect_event' ) ) {
			node_ai_dispatch_connect_event( 'fact_check_completed', $post_id, 'fact_check' );
		}

		wp_set_current_user( $prev_user );
	}
}
