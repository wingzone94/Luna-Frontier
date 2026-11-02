<?php
/**
 * 公開 API。
 *
 * @package Luna_Ads_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'luna_ads_get' ) ) {
	/**
	 * 指定スロットの広告マークアップを返す（表示条件を満たさなければ空文字）。
	 *
	 * @param string $slot in_article / article_bottom / infeed / sidebar。
	 * @return string
	 */
	function luna_ads_get( $slot = 'sidebar' ) {
		return Luna_Ads_Renderer::get_slot_html( $slot );
	}
}

if ( ! function_exists( 'luna_ads_render' ) ) {
	/**
	 * 指定スロットの広告を出力する。
	 *
	 * @param string $slot スロット名。
	 * @return void
	 */
	function luna_ads_render( $slot = 'sidebar' ) {
		echo luna_ads_get( $slot ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 管理者が設定した広告コード。
	}
}
