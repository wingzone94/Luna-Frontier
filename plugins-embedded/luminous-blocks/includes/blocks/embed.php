<?php
/**
 * Node 埋め込みブロック（node/embed）
 *
 * 投稿画面で X(Twitter) / YouTube / Google マップの URL を貼り付けると
 * このブロックへ自動変換され、エディタ内で埋め込みプレビューを表示する
 * （エディタ側の変換・プレビューは assets/js/editor.js が担当）。
 * フロント側の埋め込み HTML 生成はテーマの node_special_embed()
 * （inc/blogcard.php）へ委譲する。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'node_render_embed_block' ) ) {
	/**
	 * node/embed ブロックのフロント側レンダリング。
	 *
	 * @param array<string, mixed> $attributes ブロック属性。
	 * @return string
	 */
	function node_render_embed_block( $attributes ) {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '';
		}
		$url = esc_url_raw( (string) ( $attributes['url'] ?? '' ) );
		if ( '' === $url ) {
			return '';
		}

		if ( function_exists( 'node_steam_widget_html' ) ) {
			$steam_widget = node_steam_widget_html( $url );
			if ( '' !== $steam_widget ) {
				return $steam_widget;
			}
		}

		if ( function_exists( 'node_special_embed' ) ) {
			$embed = node_special_embed( $url );
			if ( '' !== $embed ) {
				return $embed;
			}
		}

		// 埋め込みへ変換できない URL は通常リンクとしてフォールバック。
		return '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a></p>';
	}
}
