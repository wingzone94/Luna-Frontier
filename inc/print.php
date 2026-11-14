<?php

declare(strict_types=1);
/**
 * 印刷機能（Node 1.3 第5段階）。
 *
 * 記事下部（シェアボタン群の並び）に印刷ボタンを置き、window.print() を
 * 呼ぶだけの構成。見た目は src/styles/_print.css の @media print が担い、
 * PDF化はブラウザの「PDFとして保存」に委ねる（サーバー側生成はしない）。
 *
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NODE_PRINT_OPTION_ENABLED   = 'node_print_enabled';
const NODE_PRINT_OPTION_POSITION  = 'node_print_button_position';
const NODE_PRINT_OPTION_SHOW_META = 'node_print_show_meta';

/**
 * 印刷機能（印刷ボタン・印刷専用要素）が有効か。既定は有効。
 */
function node_print_is_enabled(): bool {
	return '1' === (string) get_option( NODE_PRINT_OPTION_ENABLED, '1' );
}

/**
 * 印刷ボタンの表示位置。'start'（シェアボタン群の先頭）| 'end'（末尾・既定）。
 */
function node_print_button_position(): string {
	$position = (string) get_option( NODE_PRINT_OPTION_POSITION, 'end' );

	return in_array( $position, array( 'start', 'end' ), true ) ? $position : 'end';
}

/**
 * 印刷時に記事URL・著作権表記を出力するか。既定は出力する。
 */
function node_print_show_meta(): bool {
	return '1' === (string) get_option( NODE_PRINT_OPTION_SHOW_META, '1' );
}

/**
 * 印刷ボタンのHTMLを返す。シェアボタン群と同じ .m3-share-btn 系で描画する。
 */
function node_print_get_button_html(): string {
	ob_start();
	?>
		<button class="m3-share-btn m3-share-btn--print"
		        type="button"
		        onclick="window.print()"
		        title="この記事を印刷"
		        aria-label="この記事を印刷する">
			<span class="material-symbols-outlined" data-icon="print" aria-hidden="true"></span>
			<span class="m3-share-btn__label">印刷</span>
		</button>
	<?php
	return (string) ob_get_clean();
}

/**
 * 印刷時のみ表示するヘッダー（ブログ名）を出力する。
 */
function node_print_the_header(): void {
	if ( ! node_print_is_enabled() ) {
		return;
	}
	?>
	<div class="m3-print-only m3-print-header" aria-hidden="true"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
	<?php
}

/**
 * 印刷時のみ表示するフッター（記事URL・著作権表記）を出力する。
 */
function node_print_the_footer(): void {
	if ( ! node_print_is_enabled() || ! node_print_show_meta() ) {
		return;
	}
	?>
	<div class="m3-print-only m3-print-footer" aria-hidden="true">
		<p class="m3-print-footer__url"><?php echo esc_html( get_permalink() ); ?></p>
		<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?>. All rights reserved.</p>
	</div>
	<?php
}
