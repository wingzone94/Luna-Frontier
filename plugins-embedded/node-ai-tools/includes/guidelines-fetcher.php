<?php
/**
 * Luminous Core ガイドライン（Google ドキュメント公開URL）取得
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NODE_AI_GUIDELINES_DEFAULT_URL' ) ) {
	define(
		'NODE_AI_GUIDELINES_DEFAULT_URL',
		'https://docs.google.com/document/d/e/2PACX-1vT6ocv8B8pnyKcu11OtkIoSVOrxic1X-bmydww7zEvVexIoN6N6QZMSnOPghnTBCxM1_EAKQ81cksD0/pub'
	);
}

if ( ! function_exists( 'node_ai_get_guidelines_url' ) ) {
	/**
	 * ガイドライン公開 URL を取得
	 */
	function node_ai_get_guidelines_url(): string {
		$url = get_option( 'node_ai_guidelines_url', NODE_AI_GUIDELINES_DEFAULT_URL );
		$url = is_string( $url ) ? trim( $url ) : '';

		return '' !== $url ? $url : NODE_AI_GUIDELINES_DEFAULT_URL;
	}
}

if ( ! function_exists( 'node_ai_parse_guidelines_html' ) ) {
	/**
	 * 公開 Google ドキュメント HTML からテキストを抽出
	 *
	 * @param string $html 取得した HTML。
	 */
	function node_ai_parse_guidelines_html( string $html ): string {
		$html = (string) preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $html );
		$html = (string) preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $html );

		$lines = array();

		if ( preg_match_all( '/<(?:p|li)\b[^>]*>(.*?)<\/(?:p|li)>/is', $html, $matches ) ) {
			foreach ( $matches[1] as $chunk ) {
				$text = html_entity_decode( wp_strip_all_tags( (string) $chunk ), ENT_QUOTES, 'UTF-8' );
				$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

				if ( '' === $text || mb_strlen( $text ) < 2 ) {
					continue;
				}

				if ( str_contains( $text, 'lst-kix' ) ) {
					continue;
				}

				if ( preg_match( '/Google ドキュメント|不正行為を報告|分ごとに自動更新/u', $text ) ) {
					continue;
				}

				$lines[] = $text;
			}
		}

		$lines = array_values( array_unique( $lines ) );
		$text  = implode( "\n", $lines );

		return trim( $text );
	}
}

if ( ! function_exists( 'node_ai_fetch_guidelines' ) ) {
	/**
	 * ガイドラインテキストを取得（Transient キャッシュ付き）
	 *
	 * @param bool $force_refresh キャッシュを無視して再取得する。
	 * @return string|WP_Error
	 */
	function node_ai_fetch_guidelines( bool $force_refresh = false ): string|WP_Error {
		$url          = node_ai_get_guidelines_url();
		$cache_key    = 'node_ai_guidelines_' . md5( $url );
		$cache_hours  = (int) apply_filters( 'node_ai_guidelines_cache_hours', 12 );
		$cached       = get_transient( $cache_key );

		if ( ! $force_refresh && is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => MB_IN_BYTES,
				'user-agent'          => 'Mozilla/5.0 (compatible; LuminousCore/1.0; +https://luminous-core.net/)',
			)
		);

		if ( is_wp_error( $response ) ) {
			return is_string( $cached ) && '' !== $cached ? $cached : $response;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$error = new WP_Error( 'guidelines_http_error', 'ガイドラインの取得に失敗しました。' );
			return is_string( $cached ) && '' !== $cached ? $cached : $error;
		}

		$text = node_ai_parse_guidelines_html( (string) wp_remote_retrieve_body( $response ) );

		if ( '' === $text ) {
			$error = new WP_Error( 'guidelines_empty', 'ガイドライン本文を解析できませんでした。' );
			return is_string( $cached ) && '' !== $cached ? $cached : $error;
		}

		$max_chars = (int) apply_filters( 'node_ai_guidelines_max_chars', 6000 );
		if ( mb_strlen( $text ) > $max_chars ) {
			$text = mb_substr( $text, 0, $max_chars );
		}

		set_transient( $cache_key, $text, $cache_hours * HOUR_IN_SECONDS );

		return $text;
	}
}
