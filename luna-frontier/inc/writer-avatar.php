<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 / SkyAlow — ライターアイコン
 *
 * アイコンが設定されていない執筆者に、共通の人型ピクトグラムを出す
 * （2026-08-29 ユーザー指示）。設定済みのアイコンには手を触れない。
 *
 * これまでは get_avatar() が Gravatar の既定画像を返していた。
 *   - gravatar.com への外部リクエストが記事あたり数件増える
 *   - 未登録だと灰色の四角が出るだけで、何を指すアイコンか読み取れない
 *
 * 形は Material Symbols の person に寄せた塗りのピクトグラム。テーマの他の
 * アイコンと同じ語彙に見えるよう、線画ではなく塗りで、頭と肩だけを持つ。
 * currentColor なので置かれた場所の文字色に追従する。
 *
 * @package LunaFrontier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 共通のライターアイコン（SVG マークアップ）を返す。
 *
 * @param int                 $size ピクセル。
 * @param array<int,string>   $extra_classes 付与する追加クラス。
 */
function luna_frontier_writer_avatar_svg( int $size, array $extra_classes = array() ): string {
	$classes = array_merge( array( 'avatar', 'lf-writer-avatar', 'avatar-' . $size, 'photo' ), $extra_classes );

	/*
	 * Material Symbols の person と同じ骨格にする。
	 *   頭   : cy 7.4 / r 3.6
	 *   肩   : 下辺を切った角丸の台形。M3 は肩の両端を丸めるので arc で閉じる。
	 * 線幅ではなく塗りで描くのは、テーマの Material Symbols が既定で
	 * FILL 0〜1 の可変軸を持ち、この寸法では塗りに近い見え方になるため。
	 */
	return sprintf(
		'<svg class="%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="currentColor" '
		. 'role="img" aria-label="%3$s" focusable="false">'
		. '<path d="M12 11.6a3.8 3.8 0 1 0 0-7.6 3.8 3.8 0 0 0 0 7.6Z"></path>'
		. '<path d="M12 13.4c-3.2 0-6.4 1.6-6.4 3.8v1.4c0 .7.6 1.2 1.3 1.2h10.2c.7 0 1.3-.5 1.3-1.2v-1.4c0-2.2-3.2-3.8-6.4-3.8Z"></path>'
		. '</svg>',
		esc_attr( implode( ' ', array_unique( $classes ) ) ),
		$size,
		esc_attr__( '執筆者', 'luna-frontier' )
	);
}

/**
 * アイコン未設定のときだけ、共通のピクトグラムに差し替える。
 *
 * 判定は「出来上がった URL が gravatar.com か」で行う。ローカルアバターの
 * プラグインや自前の実装は自サイトの URL を返すので、そのまま通る。
 * Gravatar に本人の画像が登録されている可能性は判定できない（確かめるには
 * 外部リクエストが要る）が、このサイトは Gravatar 運用をしていないため、
 * 実質「未設定のとき」と一致する。運用が変わったらここを見直すこと。
 *
 * @param string              $avatar      完成した <img> マークアップ。
 * @param mixed               $id_or_email アバターの対象。
 * @param int                 $size        ピクセル。
 * @param string              $default     既定アバターの種類。
 * @param string              $alt         代替テキスト。
 * @param array<string,mixed> $args        get_avatar() の引数。
 */
function luna_frontier_writer_avatar( string $avatar, $id_or_email, int $size, string $default, string $alt, array $args = array() ): string {
	if ( luna_frontier_is_legacy_archive() ) {
		return $avatar;
	}
	if ( '' === $avatar ) {
		return $avatar;
	}

	// 自サイト（＝プラグイン等が用意したアイコン）なら触らない。
	if ( ! preg_match( '#src=["\']([^"\']+)["\']#', $avatar, $match ) ) {
		return $avatar;
	}

	$host = (string) wp_parse_url( $match[1], PHP_URL_HOST );
	if ( '' !== $host && false === stripos( $host, 'gravatar.com' ) ) {
		return $avatar;
	}

	$extra = array();
	if ( ! empty( $args['class'] ) ) {
		$list = is_array( $args['class'] ) ? $args['class'] : preg_split( '/\s+/', (string) $args['class'] );
		foreach ( (array) $list as $class ) {
			$class = trim( (string) $class );
			if ( '' !== $class ) {
				$extra[] = $class;
			}
		}
	}

	return luna_frontier_writer_avatar_svg( $size > 0 ? $size : 96, $extra );
}
add_filter( 'get_avatar', 'luna_frontier_writer_avatar', 20, 6 );
