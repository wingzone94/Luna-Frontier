<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 / SkyAlow — Dynamic Color（Phase 7）
 *
 * 指示書 §36〜§42。
 *
 * 設計:
 * - seed は「記事の人格」。Featured Image 由来を第一とする。
 * - Category Color は Wayfinding（分類）なので seed で上書きしない（§39）。
 *   親テーマは「カテゴリ色 → 画像色」の順で seed を決めるが、Luna Frontier では
 *   Dynamic Color と Category Color を役割として分離し、画像を優先する。
 * - 生 RGB を全 Surface へ直貼りしない（§37）。M3 の HCT / tonal palette の思想に
 *   寄せ、Web/PHP 実装では OKLCH の知覚明度を使って Light / Dark の role tone を選ぶ。
 * - 表示は PHP が <head> にインライン出力するため、初回描画から正しい色になる（§41）。
 *   JS は meta 未保存記事のフォールバックに降格させる（このプロトタイプでは
 *   PHP 側が毎回算出できるため JS 経路は不要 = 通常経路に JS を置かない）。
 * - 既存メタは rename しない。新規に _lc_seed_color を「追加」するだけで、
 *   Node 1.3 へ戻しても無視されるだけ（不可逆 migration をしない。§61）。
 *
 * @package LunaFrontier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * hex を [r,g,b] へ。
 *
 * @return array{0:int,1:int,2:int}|null
 */
function luna_frontier_hex_to_rgb( string $hex ): ?array {
	$hex = ltrim( trim( $hex ), '#' );

	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}

	if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
		return null;
	}

	return array(
		(int) hexdec( substr( $hex, 0, 2 ) ),
		(int) hexdec( substr( $hex, 2, 2 ) ),
		(int) hexdec( substr( $hex, 4, 2 ) ),
	);
}

/**
 * sRGB チャンネル（0-255）を linear RGB（0-1）へ。
 */
function luna_frontier_srgb_to_linear( float $value ): float {
	$value = min( 1.0, max( 0.0, $value / 255 ) );

	return $value <= 0.04045 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
}

/**
 * linear RGB チャンネル（0-1）を sRGB（0-255）へ。
 */
function luna_frontier_linear_to_srgb( float $value ): int {
	$value = min( 1.0, max( 0.0, $value ) );
	$srgb  = $value <= 0.0031308 ? 12.92 * $value : 1.055 * pow( $value, 1 / 2.4 ) - 0.055;

	return (int) round( min( 1.0, max( 0.0, $srgb ) ) * 255 );
}

/**
 * RGB → OKLCH（l/c: 0-1, h: 0-360）。
 *
 * @param array{0:int,1:int,2:int} $rgb RGB。
 * @return array{l:float,c:float,h:float}
 */
function luna_frontier_rgb_to_oklch( array $rgb ): array {
	$r = luna_frontier_srgb_to_linear( (float) $rgb[0] );
	$g = luna_frontier_srgb_to_linear( (float) $rgb[1] );
	$b = luna_frontier_srgb_to_linear( (float) $rgb[2] );

	$l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
	$m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
	$s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

	$l_ = pow( max( 0.0, $l ), 1 / 3 );
	$m_ = pow( max( 0.0, $m ), 1 / 3 );
	$s_ = pow( max( 0.0, $s ), 1 / 3 );

	$ok_l = 0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_;
	$ok_a = 1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_;
	$ok_b = 0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_;

	$h = rad2deg( atan2( $ok_b, $ok_a ) );

	return array(
		'l' => $ok_l,
		'c' => sqrt( $ok_a * $ok_a + $ok_b * $ok_b ),
		'h' => fmod( $h + 360, 360 ),
	);
}

/**
 * OKLCH → linear RGB。
 *
 * @return array{0:float,1:float,2:float}
 */
function luna_frontier_oklch_to_linear_rgb( float $lightness, float $chroma, float $hue ): array {
	$hue = deg2rad( fmod( fmod( $hue, 360 ) + 360, 360 ) );
	$a   = cos( $hue ) * $chroma;
	$b   = sin( $hue ) * $chroma;

	$l_ = $lightness + 0.3963377774 * $a + 0.2158037573 * $b;
	$m_ = $lightness - 0.1055613458 * $a - 0.0638541728 * $b;
	$s_ = $lightness - 0.0894841775 * $a - 1.2914855480 * $b;

	$l = $l_ * $l_ * $l_;
	$m = $m_ * $m_ * $m_;
	$s = $s_ * $s_ * $s_;

	return array(
		4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
		-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
		-0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
	);
}

/**
 * OKLCH が sRGB gamut 内に収まるか。
 */
function luna_frontier_oklch_in_srgb_gamut( float $lightness, float $chroma, float $hue ): bool {
	$rgb = luna_frontier_oklch_to_linear_rgb( $lightness, $chroma, $hue );

	foreach ( $rgb as $channel ) {
		if ( $channel < 0.0 || $channel > 1.0 ) {
			return false;
		}
	}

	return true;
}

/**
 * OKLCH → hex。sRGB gamut 外のときは chroma だけを落として hue / tone を保つ。
 */
function luna_frontier_oklch_to_hex( float $lightness, float $chroma, float $hue ): string {
	$lightness = min( 1.0, max( 0.0, $lightness ) );
	$chroma    = max( 0.0, $chroma );

	if ( ! luna_frontier_oklch_in_srgb_gamut( $lightness, $chroma, $hue ) ) {
		$low  = 0.0;
		$high = $chroma;

		for ( $i = 0; $i < 24; $i++ ) {
			$mid = ( $low + $high ) / 2;
			if ( luna_frontier_oklch_in_srgb_gamut( $lightness, $mid, $hue ) ) {
				$low = $mid;
			} else {
				$high = $mid;
			}
		}

		$chroma = $low;
	}

	$rgb = luna_frontier_oklch_to_linear_rgb( $lightness, $chroma, $hue );

	return sprintf(
		'#%02x%02x%02x',
		luna_frontier_linear_to_srgb( $rgb[0] ),
		luna_frontier_linear_to_srgb( $rgb[1] ),
		luna_frontier_linear_to_srgb( $rgb[2] )
	);
}

/**
 * 相対輝度（WCAG）。
 *
 * @param array{0:int,1:int,2:int} $rgb RGB。
 */
function luna_frontier_relative_luminance( array $rgb ): float {
	$channels = array();

	foreach ( $rgb as $value ) {
		$v          = $value / 255;
		$channels[] = $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
	}

	return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/**
 * 2 色のコントラスト比。
 */
function luna_frontier_contrast_ratio( string $fg, string $bg ): float {
	$fg_rgb = luna_frontier_hex_to_rgb( $fg );
	$bg_rgb = luna_frontier_hex_to_rgb( $bg );

	if ( null === $fg_rgb || null === $bg_rgb ) {
		return 1.0;
	}

	$l1 = luna_frontier_relative_luminance( $fg_rgb );
	$l2 = luna_frontier_relative_luminance( $bg_rgb );

	$lighter = max( $l1, $l2 );
	$darker  = min( $l1, $l2 );

	return ( $lighter + 0.05 ) / ( $darker + 0.05 );
}

/**
 * OKLCH 面に対する on-color。白黒固定の前に、同じ hue の高低 tone を候補にする。
 */
function luna_frontier_oklch_on_color( string $background, float $hue, float $chroma ): string {
	$candidates = array(
		luna_frontier_oklch_to_hex( 0.14, min( $chroma * 0.45, 0.08 ), $hue ),
		luna_frontier_oklch_to_hex( 0.96, min( $chroma * 0.25, 0.04 ), $hue ),
		'#000000',
		'#ffffff',
	);

	$best       = '#000000';
	$best_ratio = 0.0;

	foreach ( $candidates as $candidate ) {
		$ratio = luna_frontier_contrast_ratio( $candidate, $background );

		if ( $ratio >= 4.5 ) {
			return $candidate;
		}

		if ( $ratio > $best_ratio ) {
			$best_ratio = $ratio;
			$best       = $candidate;
		}
	}

	return $best;
}

/**
 * OKLCH role tone から、背景に対して目標コントラストを満たす最も近い tone を選ぶ。
 *
 * M3 は tonal palette の tone を color role へ割り当てる。ここでは HCT の代替として
 * OKLCH の L を tone として扱い、role ごとの初期 tone を保ちながら AA を下回る時だけ
 * 明暗方向へ寄せる。
 *
 * @param array<int, string> $backgrounds 載る可能性のある背景色 hex。
 */
function luna_frontier_oklch_tone_meeting_contrast( float $hue, float $chroma, float $lightness, array $backgrounds, float $target ): string {
	$meets = static function ( string $candidate ) use ( $backgrounds, $target ): bool {
		foreach ( $backgrounds as $background ) {
			if ( luna_frontier_contrast_ratio( $candidate, $background ) < $target ) {
				return false;
			}
		}

		return true;
	};

	$origin = luna_frontier_oklch_to_hex( $lightness, $chroma, $hue );

	if ( $meets( $origin ) ) {
		return $origin;
	}

	$reference = luna_frontier_hex_to_rgb( $backgrounds[0] ?? '' );

	if ( null === $reference ) {
		return $origin;
	}

	$darken = luna_frontier_relative_luminance( $reference ) > 0.18;
	$limit  = $darken ? 0.0 : 1.0;
	$edge   = luna_frontier_oklch_to_hex( $limit, $chroma, $hue );

	if ( ! $meets( $edge ) ) {
		return $edge;
	}

	$near = $lightness;
	$contrast_bound  = $limit;

	for ( $i = 0; $i < 24; $i++ ) {
		$mid = ( $near + $contrast_bound ) / 2;

		if ( $meets( luna_frontier_oklch_to_hex( $mid, $chroma, $hue ) ) ) {
			$contrast_bound = $mid;
		} else {
			$near = $mid;
		}
	}

	return luna_frontier_oklch_to_hex( $contrast_bound, $chroma, $hue );
}

/**
 * 現在の表示対象から seed 色を解決する。
 *
 * 優先順位:
 *   1. 保存済み _lc_seed_color（Luna Frontier が保存したもの）
 *   2. 投稿個別カラー _m3_primary_color（既存メタ。編集者の明示指定を尊重）
 *   3. Featured Image 由来（親の node_get_image_seed_color。attachment meta にキャッシュ）
 *   4. ブランドオレンジ（フォールバック）
 *
 * カテゴリ色は使わない。Category Color は分類として別に生き続ける（§39）。
 *
 * @return array{seed:string,source:string}
 */
function luna_frontier_resolve_seed(): array {
	$fallback = array(
		'seed'   => '#FF9900',
		'source' => 'brand',
	);

	if ( ! is_singular( array( 'post', 'page', 'node_library' ) ) ) {
		return $fallback;
	}

	$post_id = (int) get_the_ID();
	if ( ! $post_id ) {
		return $fallback;
	}

	return luna_frontier_resolve_post_seed( $post_id );
}

/**
 * 投稿 ID から seed 色を解決する。
 *
 * 一覧カードの Spectrum 化でも同じ優先順位を使うため、singular 専用の
 * luna_frontier_resolve_seed() から切り出して再利用する。
 *
 * @return array{seed:string,source:string}
 */
function luna_frontier_resolve_post_seed( int $post_id ): array {
	$fallback = array(
		'seed'   => '#FF9900',
		'source' => 'brand',
	);

	if ( $post_id <= 0 ) {
		return $fallback;
	}

	$stored = sanitize_hex_color( (string) get_post_meta( $post_id, '_lc_seed_color', true ) );
	if ( $stored ) {
		return array(
			'seed'   => $stored,
			'source' => 'meta',
		);
	}

	$manual = sanitize_hex_color( (string) get_post_meta( $post_id, '_m3_primary_color', true ) );
	if ( $manual ) {
		return array(
			'seed'   => $manual,
			'source' => 'manual',
		);
	}

	$thumb_id = (int) get_post_thumbnail_id( $post_id );
	if ( $thumb_id && function_exists( 'node_get_image_seed_color' ) ) {
		$extracted = sanitize_hex_color( (string) node_get_image_seed_color( $thumb_id ) );
		if ( $extracted ) {
			return array(
				'seed'   => $extracted,
				'source' => 'image',
			);
		}
	}

	return $fallback;
}

/**
 * seed から Material 3 相当のロールトークンを導出する。
 *
 * Google Material 3 の color system は、source color から tonal palette を作り、
 * そこから primary / surface / on-* などの color role へ tone を割り当てる。
 * Luna Frontier は PHP ランタイム内で完結させるため HCT そのものではなく OKLCH を使い、
 * hue / chroma と知覚明度 L を role tone として扱う。
 *
 * @return array<string, array<string, string>> scheme => tokens。
 */
function luna_frontier_build_roles( string $seed ): array {
	$rgb = luna_frontier_hex_to_rgb( $seed );

	if ( null === $rgb ) {
		$rgb = array( 255, 153, 0 );
	}

	$oklch = luna_frontier_rgb_to_oklch( $rgb );

	// 無彩色に近い画像の hue は不安定なので、Luminous Core のフォールバックへ戻す。
	if ( $oklch['c'] < 0.015 ) {
		$oklch = luna_frontier_rgb_to_oklch( array( 255, 153, 0 ) );
	}

	$h = $oklch['h'];
	$c = min( 0.16, max( 0.055, $oklch['c'] ) );

	$light_primary = luna_frontier_oklch_to_hex( 0.50, $c, $h );
	$light_second  = luna_frontier_oklch_to_hex( 0.56, $c * 0.62, $h + 18 );

	$dark_primary = luna_frontier_oklch_to_hex( 0.76, $c * 0.88, $h );
	$dark_second  = luna_frontier_oklch_to_hex( 0.78, $c * 0.58, $h + 18 );

	// accent は本文リンク・現在地・focus リングに使う「文字色」なので、
	// 載る面を先に確定させてからトーンを決める。本文の紙（--lf-paper）だけでなく、
	// 記事ヘッダーの面（--lc-primary-container）の上にもリンクチップが載るため、
	// 両方を満たすトーンを選ぶ。片方だけで決めると黄緑系で 3.82:1 まで落ちる。
	$light_paper = luna_frontier_oklch_to_hex( 0.995, $c * 0.035, $h );
	$dark_paper  = luna_frontier_oklch_to_hex( 0.145, $c * 0.04, $h );

	$light_primary_container = luna_frontier_oklch_to_hex( 0.91, $c * 0.55, $h );
	$dark_primary_container  = luna_frontier_oklch_to_hex( 0.27, $c * 0.55, $h );

	// accent は本文リンク・現在地・focus ring に使う文字色 role。
	// 載る可能性のある面（paper / primary-container）を同時に満たす tone を選ぶ。
	$light_accent = luna_frontier_oklch_tone_meeting_contrast( $h, min( 0.17, $c * 1.05 ), 0.45, array( $light_paper, $light_primary_container ), 4.5 );
	$dark_accent  = luna_frontier_oklch_tone_meeting_contrast( $h, min( 0.14, $c * 0.86 ), 0.78, array( $dark_paper, $dark_primary_container ), 4.5 );

	$light = array(
		'--lc-seed'                => $seed,
		'--lc-primary'             => $light_primary,
		'--lc-on-primary'          => luna_frontier_oklch_on_color( $light_primary, $h, $c ),
		'--lc-primary-container'   => $light_primary_container,
		'--lc-on-primary-container' => luna_frontier_oklch_to_hex( 0.17, min( 0.10, $c * 0.7 ), $h ),
		'--lc-secondary'           => $light_second,
		'--lc-on-secondary'        => luna_frontier_oklch_on_color( $light_second, $h + 18, $c * 0.62 ),
		'--lc-surface'             => luna_frontier_oklch_to_hex( 0.96, $c * 0.10, $h ),
		'--lc-surface-variant'     => luna_frontier_oklch_to_hex( 0.92, $c * 0.14, $h ),
		'--lc-on-surface'          => luna_frontier_oklch_to_hex( 0.16, min( 0.04, $c * 0.24 ), $h ),
		'--lc-accent'              => $light_accent,
		'--lc-outline'             => luna_frontier_oklch_to_hex( 0.75, $c * 0.15, $h ),
		'--lf-paper'               => $light_paper,
		'--lf-paper-sunken'        => luna_frontier_oklch_to_hex( 0.94, $c * 0.09, $h ),
	);

	$dark = array(
		'--lc-seed'                => $seed,
		'--lc-primary'             => $dark_primary,
		'--lc-on-primary'          => luna_frontier_oklch_on_color( $dark_primary, $h, $c ),
		'--lc-primary-container'   => $dark_primary_container,
		'--lc-on-primary-container' => luna_frontier_oklch_to_hex( 0.91, min( 0.06, $c * 0.28 ), $h ),
		'--lc-secondary'           => $dark_second,
		'--lc-on-secondary'        => luna_frontier_oklch_on_color( $dark_second, $h + 18, $c * 0.58 ),
		'--lc-surface'             => luna_frontier_oklch_to_hex( 0.10, $c * 0.06, $h ),
		'--lc-surface-variant'     => luna_frontier_oklch_to_hex( 0.17, $c * 0.08, $h ),
		'--lc-on-surface'          => luna_frontier_oklch_to_hex( 0.92, min( 0.035, $c * 0.18 ), $h ),
		'--lc-accent'              => $dark_accent,
		'--lc-outline'             => luna_frontier_oklch_to_hex( 0.46, $c * 0.10, $h ),
		'--lf-paper'               => $dark_paper,
		'--lf-paper-sunken'        => luna_frontier_oklch_to_hex( 0.08, $c * 0.04, $h ),
	);

	// on-surface が面に対して AA を割らないよう最終確認する。
	if ( luna_frontier_contrast_ratio( $light['--lc-on-surface'], $light['--lf-paper'] ) < 7.0 ) {
		$light['--lc-on-surface'] = '#141110';
	}
	if ( luna_frontier_contrast_ratio( $dark['--lc-on-surface'], $dark['--lf-paper'] ) < 7.0 ) {
		$dark['--lc-on-surface'] = '#f2ede9';
	}

	return array(
		'light' => $light,
		'dark'  => $dark,
	);
}

/**
 * <head> へ初期 CSS Token をインライン出力する。
 *
 * ヘッダー / フッターへは流し込まない（§23 / §38）。
 * body.lf-theme スコープに閉じるため、header / footer 内の要素も継承はするが、
 * ブランドクロームは親テーマの --md-sys-color-* を使い続けるので影響しない。
 */
function luna_frontier_print_dynamic_color(): void {
	$resolved = luna_frontier_resolve_seed();
	$roles    = luna_frontier_build_roles( $resolved['seed'] );

	$to_css = static function ( array $tokens ): string {
		$out = '';
		foreach ( $tokens as $name => $value ) {
			$out .= $name . ':' . $value . ';';
		}
		return $out;
	};

	// 子テーマの静的トークン（body.lf-theme）より必ず後で勝つよう、
	// 属性セレクタ 1 つぶん specificity を上げる。
	/*
	 * ダークは body だけでなく html に data-theme が付いた場合も拾う。
	 * 親の <head> スクリプトは document.body がまだ存在しない段階で走るため、
	 * 初回描画では <html> にしか付かない。body 限定にすると、その間だけ
	 * ライトのトークンが残って「白い紙に明るい文字」になる。
	 *
	 * specificity は light: body.lf-theme[class] = (0,2,1)、
	 * dark: body.lf-theme[data-theme] / [data-theme] body.lf-theme = 同値。
	 * 後に出力する dark が勝つ。
	 */
	printf(
		'<style id="luna-frontier-dynamic-color">body.lf-theme[class]{%1$s}'
		. 'body.lf-theme[data-theme="dark"]{%2$s}'
		. '[data-theme="dark"] body.lf-theme{%2$s}</style>' . "\n",
		esc_html( $to_css( $roles['light'] ) ),
		esc_html( $to_css( $roles['dark'] ) )
	);
}
// 親の node_generate_m3_colors（優先度 10）より後に出す。
add_action( 'wp_head', 'luna_frontier_print_dynamic_color', 20 );

/**
 * M3 の primary ロールをブランドカラーへ固定する。
 *
 * 親テーマ（inc/utilities.php / node_generate_m3_colors）は、記事の
 * プライマリカテゴリの色を seed にして --md-sys-color-primary を出している
 * （投稿個別カラー → カテゴリカラー → アイキャッチ の順）。この結果、
 * ボタン・リンク・フォーカスなど primary を参照する UI が、その記事の
 * プライマリカテゴリのチップとまったく同じ色になっていた
 * （実測: カテゴリ「スマートフォン」#706E00 の記事で primary も #706E00）。
 *
 * 2026-08-29 のユーザー指示でこの仕様を取り止める。カテゴリ色は
 * 分類のための Wayfinding（§39）としてチップの中だけに置き、UI の色は
 * ブランドオレンジで一定にする。
 *
 * 親テーマは書き換えない（本番稼働中）。親のインラインより後に同じ
 * :root を出して上書きする。値は親のフォールバックと同じ式で作るので、
 * 「カテゴリ色が設定されていない記事」と同じ見え方に揃う。
 */
function luna_frontier_pin_brand_primary(): void {
	if ( ! function_exists( 'node_mix_hex_color' ) || ! function_exists( 'node_get_readable_text_color' ) ) {
		return;
	}

	// 親の $default_primary / $default_primary_dark と同じ値。
	$brand      = '#FF9900';
	$brand_dark = '#ffb85d';

	$light = array(
		'--md-sys-color-primary'              => $brand,
		'--md-sys-color-on-primary'           => node_get_readable_text_color( $brand ),
		'--md-sys-color-primary-container'    => node_mix_hex_color( $brand, '#fff4e5', 0.78 ),
		'--md-sys-color-on-primary-container' => node_get_readable_text_color( node_mix_hex_color( $brand, '#fff4e5', 0.78 ) ),
	);

	$dark_container = node_mix_hex_color( $brand, '#1e1b16', 0.54 );
	$dark           = array(
		'--md-sys-color-primary'              => $brand_dark,
		'--md-sys-color-on-primary'           => node_get_readable_text_color( $brand_dark ),
		'--md-sys-color-primary-container'    => $dark_container,
		'--md-sys-color-on-primary-container' => node_get_readable_text_color( $dark_container ),
	);

	$to_css = static function ( array $tokens ): string {
		$out = '';
		foreach ( $tokens as $name => $value ) {
			$out .= $name . ':' . $value . ';';
		}
		return $out;
	};

	printf(
		'<style id="luna-frontier-brand-primary">:root{%1$s}[data-theme="dark"]{%2$s}</style>' . "\n",
		esc_html( $to_css( $light ) ),
		esc_html( $to_css( $dark ) )
	);
}
// 親の node_generate_m3_colors（優先度 10）より後、かつ同じ :root で上書きする。
add_action( 'wp_head', 'luna_frontier_pin_brand_primary', 15 );

/**
 * 一覧カード用の Spectrum accent を inline style として返す。
 *
 * Phase 5 は全面的な多色グリッドではなく、M3 の color role として
 * 罫・focus・弱い面に限定して seed を渡すところから始める。
 */
function luna_frontier_get_card_color_style( int $post_id ): string {
	$resolved = luna_frontier_resolve_post_seed( $post_id );
	$roles    = luna_frontier_build_roles( $resolved['seed'] );

	$tokens = array(
		'--lf-card-accent'         => $roles['light']['--lc-accent'],
		'--lf-card-accent-dark'    => $roles['dark']['--lc-accent'],
		'--lf-card-outline'        => $roles['light']['--lc-outline'],
		'--lf-card-outline-dark'   => $roles['dark']['--lc-outline'],
		'--lf-card-surface'        => $roles['light']['--lc-surface-variant'],
		'--lf-card-surface-dark'   => $roles['dark']['--lc-surface-variant'],
	);

	$style = '';
	foreach ( $tokens as $name => $value ) {
		$style .= $name . ':' . $value . ';';
	}

	return $style;
}

/**
 * 一覧カードの Spectrum seed 解決に必要な meta をループ前にまとめて温める。
 *
 * WP_Query は通常 post meta をまとめて読むが、Phase 5 のカード Spectrum は
 * `_lc_seed_color` が未保存の記事で thumbnail attachment 側の seed cache まで
 * 参照する。ホーム / アーカイブ / 検索のメインループでは、カードごとの遅延取得に
 * ならないよう post meta と thumbnail cache を明示的に preload する。
 */
function luna_frontier_preload_card_seed_meta( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( ! ( $query->is_home() || $query->is_archive() || $query->is_search() ) ) {
		return;
	}

	$post_ids = array_values(
		array_filter(
			array_map(
				static fn( $post ): int => $post instanceof WP_Post ? (int) $post->ID : 0,
				(array) $query->posts
			)
		)
	);

	if ( empty( $post_ids ) ) {
		return;
	}

	update_meta_cache( 'post', $post_ids );

	if ( function_exists( 'update_post_thumbnail_cache' ) ) {
		update_post_thumbnail_cache( $query );
	}
}
add_action( 'loop_start', 'luna_frontier_preload_card_seed_meta' );

/**
 * 保存時に seed を投稿メタへ書き出す（§40 の「保存時抽出」）。
 *
 * 既存キーは触らず _lc_seed_color を追加するだけ。Node 1.3 はこのキーを読まないため、
 * テーマを戻しても影響しない（不可逆 migration にしない。§61）。
 */
function luna_frontier_store_seed_on_save( int $post_id, WP_Post $post ): void {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}

	$thumb_id = (int) get_post_thumbnail_id( $post_id );

	if ( ! $thumb_id || ! function_exists( 'node_get_image_seed_color' ) ) {
		delete_post_meta( $post_id, '_lc_seed_color' );
		return;
	}

	$seed = sanitize_hex_color( (string) node_get_image_seed_color( $thumb_id ) );

	if ( $seed ) {
		update_post_meta( $post_id, '_lc_seed_color', $seed );
	} else {
		delete_post_meta( $post_id, '_lc_seed_color' );
	}
}
add_action( 'save_post', 'luna_frontier_store_seed_on_save', 20, 2 );
