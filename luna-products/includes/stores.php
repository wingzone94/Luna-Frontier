<?php
/**
 * Luna Products — ストアプリセットレジストリ・行データ整形ヘルパー
 *
 * ストア定義は本ファイルの luna_products_store_presets() を唯一の正本とし、
 * ブロックエディターの選択肢およびサーバーサイド描画の双方で共用する。
 *
 * @package Luna_Products
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ストアプリセットレジストリを返す。
 *
 * Tier 1: 常時表示（標準の2行、アフィリエイト自動付与対象）
 * Tier 2: メーカー直販（公式チップ対応、ゲーム系はテーマのブランド変数と色味を統一）
 * Tier 3: その他 EC プリセット＋カスタム自由入力
 *
 * @return array<string, array{id: string, label: string, color: string, brand_var: string, tier: int, affiliate: bool}>
 */
function luna_products_store_presets(): array {
	return array(
		// Tier 1: 常時表示（標準の2行）
		'amazon'      => array(
			'id'        => 'amazon',
			'label'     => 'Amazon',
			'color'     => '#FF9900',
			'brand_var' => '',
			'tier'      => 1,
			'affiliate' => true,
		),
		'rakuten'     => array(
			'id'        => 'rakuten',
			'label'     => '楽天市場',
			'color'     => '#BF0000',
			'brand_var' => '',
			'tier'      => 1,
			'affiliate' => true,
		),

		// Tier 2: メーカー直販（プリセット）
		'anker'       => array(
			'id'        => 'anker',
			'label'     => 'Anker Store',
			'color'     => '#00A9E0',
			'brand_var' => '',
			'tier'      => 2,
			'affiliate' => false,
		),
		'nintendo'    => array(
			'id'        => 'nintendo',
			'label'     => 'My Nintendo Store',
			'color'     => '#E60012',
			'brand_var' => '--brand-nintendo',
			'tier'      => 2,
			'affiliate' => false,
		),
		'apple'       => array(
			'id'        => 'apple',
			'label'     => 'Apple Store',
			'color'     => '#333333',
			'brand_var' => '--brand-mac',
			'tier'      => 2,
			'affiliate' => false,
		),
		'microsoft'   => array(
			'id'        => 'microsoft',
			'label'     => 'Microsoft Store',
			'color'     => '#107C10',
			'brand_var' => '--brand-xbox',
			'tier'      => 2,
			'affiliate' => false,
		),
		'playstation' => array(
			'id'        => 'playstation',
			'label'     => 'PlayStation Store',
			'color'     => '#003087',
			'brand_var' => '--brand-sony',
			'tier'      => 2,
			'affiliate' => false,
		),
		'steam'       => array(
			'id'        => 'steam',
			'label'     => 'Steam',
			'color'     => '#171A21',
			'brand_var' => '',
			'tier'      => 2,
			'affiliate' => false,
		),

		// Tier 3: その他 EC（プリセット＋カスタム）
		'yodobashi'   => array(
			'id'        => 'yodobashi',
			'label'     => 'ヨドバシカメラ',
			'color'     => '#CC0000',
			'brand_var' => '',
			'tier'      => 3,
			'affiliate' => false,
		),
		'biccamera'   => array(
			'id'        => 'biccamera',
			'label'     => 'ビックカメラ',
			'color'     => '#E60012',
			'brand_var' => '',
			'tier'      => 3,
			'affiliate' => false,
		),
		'joshin'      => array(
			'id'        => 'joshin',
			'label'     => 'Joshin',
			'color'     => '#004098',
			'brand_var' => '',
			'tier'      => 3,
			'affiliate' => false,
		),
		'sofmap'      => array(
			'id'        => 'sofmap',
			'label'     => 'ソフマップ',
			'color'     => '#005BAC',
			'brand_var' => '',
			'tier'      => 3,
			'affiliate' => false,
		),
		'yahoo'       => array(
			'id'        => 'yahoo',
			'label'     => 'Yahoo!ショッピング',
			'color'     => '#FF0033',
			'brand_var' => '',
			'tier'      => 3,
			'affiliate' => false,
		),
		'custom'      => array(
			'id'        => 'custom',
			'label'     => 'カスタム（自由入力）',
			'color'     => '#333333',
			'brand_var' => '',
			'tier'      => 3,
			'affiliate' => false,
		),
	);
}

/**
 * 初期状態のストア行（Amazon・楽天市場の2行）を返す。
 *
 * @return array<int, array<string, mixed>>
 */
function luna_products_default_stores(): array {
	return array(
		array(
			'preset'      => 'amazon',
			'label'       => '',
			'color'       => '',
			'url'         => '',
			'price'       => 0,
			'listPrice'   => 0,
			'saleNote'    => '',
			'official'    => false,
			'couponCode'  => '',
			'couponLabel' => '',
		),
		array(
			'preset'      => 'rakuten',
			'label'       => '',
			'color'       => '',
			'url'         => '',
			'price'       => 0,
			'listPrice'   => 0,
			'saleNote'    => '',
			'official'    => false,
			'couponCode'  => '',
			'couponLabel' => '',
		),
	);
}

/**
 * 定価と販売価格から OFF 率（切り捨て整数 %）を計算する。
 *
 * 浮動小数点誤差（例: (1 - 710/1000) * 100 = 28.999...）を避けるため整数除算で切り捨てる。
 *
 * @param int $price      販売価格（円・税込）。
 * @param int $list_price 定価（円・税込）。
 * @return int OFF 率（1〜99）。対象外の場合は 0。
 */
function luna_products_calc_off_percent( int $price, int $list_price ): int {
	if ( $price <= 0 || $list_price <= $price ) {
		return 0;
	}
	$diff = $list_price - $price;
	$pct  = intdiv( $diff * 100, $list_price );
	return max( 0, min( 99, $pct ) );
}

/**
 * 16進カラーコード（#RRGGBB または #RGB）を検証して正規化する。
 *
 * @param string $color 入力カラー文字列。
 * @return string 正規化済み16進カラー、不正時は空文字。
 */
function luna_products_sanitize_hex_color( string $color ): string {
	$color = trim( $color );
	if ( preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color ) ) {
		return $color;
	}
	return '';
}

/**
 * クーポンコード・クーポン説明文字列をサニタイズする。
 *
 * タグ除去・改行および前後空白の除去・最大64文字への切り詰めを行う。
 *
 * @param string $value 入力文字列。
 * @return string サニタイズ済み文字列。
 */
function luna_products_sanitize_coupon_code( string $value ): string {
	$clean = wp_strip_all_tags( $value );
	$clean = (string) preg_replace( '/[\r\n\t]+/u', '', $clean );
	$clean = trim( $clean );
	if ( '' === $clean ) {
		return '';
	}
	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $clean, 0, 64, 'UTF-8' );
	}
	return substr( $clean, 0, 64 );
}

/**
 * ストア行の最終 URL を構築する（Tier 1 のアフィリエイト自動付与に対応）。
 *
 * @param string $preset     プリセット ID。
 * @param string $raw_url    行に入力された URL。
 * @param string $asin       ブロック属性の Amazon ASIN。
 * @param string $amazon_id  Amazon トラッキング ID。
 * @param string $rakuten_id 楽天アフィリエイト ID。
 * @return string 構築済み URL（空の場合は空文字）。
 */
function luna_products_build_store_url(
	string $preset,
	string $raw_url,
	string $asin = '',
	string $amazon_id = '',
	string $rakuten_id = ''
): string {
	$raw_url    = trim( $raw_url );
	$asin       = trim( $asin );
	$amazon_id  = trim( $amazon_id );
	$rakuten_id = trim( $rakuten_id );

	if ( 'amazon' === $preset ) {
		// Luminous Nexus と同じ規則: ASIN があれば dp/{ASIN} を優先構築
		if ( '' !== $asin ) {
			$clean_asin = (string) preg_replace( '/[^A-Za-z0-9]/', '', $asin );
			if ( '' !== $clean_asin ) {
				$url = 'https://www.amazon.co.jp/dp/' . rawurlencode( $clean_asin );
				if ( '' !== $amazon_id ) {
					$url .= '?tag=' . rawurlencode( $amazon_id );
				}
				return $url;
			}
		}
		if ( '' !== $raw_url ) {
			if ( '' !== $amazon_id && ! str_contains( $raw_url, 'tag=' ) ) {
				$separator = str_contains( $raw_url, '?' ) ? '&' : '?';
				$raw_url  .= $separator . 'tag=' . rawurlencode( $amazon_id );
			}
			return $raw_url;
		}
		return '';
	}

	if ( 'rakuten' === $preset && '' !== $raw_url ) {
		if ( '' !== $rakuten_id && ! str_contains( $raw_url, 'hb.afl.rakuten.co.jp' ) ) {
			$encoded = rawurlencode( $raw_url );
			return 'https://hb.afl.rakuten.co.jp/hgc/' . rawurlencode( $rakuten_id ) . '/?pc=' . $encoded . '&m=' . $encoded;
		}
		return $raw_url;
	}

	return $raw_url;
}

/**
 * ブロック属性の stores 配列を検証・整形し、描画対象の行リストとメタ情報を返す。
 *
 * - 最大4行に制限
 * - URL と価格の両方が空の行は除外
 * - 最小価格（price > 0）の行に最安フラグを付与（同額時は先頭のみ）
 *
 * @param array<int, mixed> $raw_stores ブロック属性 stores。
 * @param string            $asin       Amazon ASIN。
 * @param string            $amazon_id  Amazon トラッキング ID。
 * @param string            $rakuten_id 楽天アフィリエイト ID。
 * @return array{rows: array<int, array<string, mixed>>, has_amazon: bool, has_rakuten: bool}
 */
function luna_products_prepare_rows(
	array $raw_stores,
	string $asin = '',
	string $amazon_id = '',
	string $rakuten_id = ''
): array {
	$presets     = luna_products_store_presets();
	$sliced      = array_slice( $raw_stores, 0, 4 );
	$rows        = array();
	$has_amazon  = false;
	$has_rakuten = false;
	$min_price   = null;

	foreach ( $sliced as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$preset_id = isset( $item['preset'] ) && is_string( $item['preset'] ) && isset( $presets[ $item['preset'] ] )
			? $item['preset']
			: 'custom';
		$preset    = $presets[ $preset_id ];

		$raw_url   = isset( $item['url'] ) && is_string( $item['url'] ) ? $item['url'] : '';
		$final_url = luna_products_build_store_url( $preset_id, $raw_url, $asin, $amazon_id, $rakuten_id );

		$price      = isset( $item['price'] ) && is_numeric( $item['price'] ) ? max( 0, (int) $item['price'] ) : 0;
		$list_price = isset( $item['listPrice'] ) && is_numeric( $item['listPrice'] ) ? max( 0, (int) $item['listPrice'] ) : 0;

		// URL か価格のどちらかが入っていれば表示する。両方空の行は描画しない。
		if ( '' === $final_url && $price <= 0 ) {
			continue;
		}

		$custom_label = isset( $item['label'] ) && is_string( $item['label'] ) ? trim( wp_strip_all_tags( $item['label'] ) ) : '';
		if ( 'custom' === $preset_id ) {
			$display_label = '' !== $custom_label ? $custom_label : __( 'ショップ', 'luna-products' );
		} else {
			$display_label = '' !== $custom_label ? $custom_label : $preset['label'];
		}

		$custom_color = '';
		if ( 'custom' === $preset_id && isset( $item['color'] ) && is_string( $item['color'] ) ) {
			$custom_color = luna_products_sanitize_hex_color( $item['color'] );
		}

		$sale_note = isset( $item['saleNote'] ) && is_string( $item['saleNote'] )
			? trim( wp_strip_all_tags( $item['saleNote'] ) )
			: '';
		$official  = ! empty( $item['official'] );

		$coupon_code = isset( $item['couponCode'] ) && is_string( $item['couponCode'] )
			? luna_products_sanitize_coupon_code( $item['couponCode'] )
			: '';
		$coupon_label = isset( $item['couponLabel'] ) && is_string( $item['couponLabel'] )
			? luna_products_sanitize_coupon_code( $item['couponLabel'] )
			: '';

		$off_percent = luna_products_calc_off_percent( $price, $list_price );

		if ( $price > 0 && ( null === $min_price || $price < $min_price ) ) {
			$min_price = $price;
		}

		// 最終 URL が空でない行でのみネットワーク使用フラグを立てる（価格のみ・URLなし行ではディスクロージャーを出さない）
		if ( '' !== $final_url ) {
			if ( 'amazon' === $preset_id ) {
				$has_amazon = true;
			} elseif ( 'rakuten' === $preset_id ) {
				$has_rakuten = true;
			}
		}

		$rows[] = array(
			'preset'       => $preset_id,
			'label'        => $display_label,
			'url'          => $final_url,
			'price'        => $price,
			'list_price'   => $list_price,
			'off_percent'  => $off_percent,
			'sale_note'    => $sale_note,
			'official'     => $official,
			'custom_color' => $custom_color,
			'coupon_code'  => $coupon_code,
			'coupon_label' => $coupon_label,
			'is_cheapest'  => false,
		);
	}

	// 最小価格の行に「最安」フラグを付与（同額の場合は先頭の行のみ）
	if ( null !== $min_price ) {
		foreach ( $rows as $idx => $row ) {
			if ( $row['price'] === $min_price ) {
				$rows[ $idx ]['is_cheapest'] = true;
				break;
			}
		}
	}

	return array(
		'rows'        => $rows,
		'has_amazon'  => $has_amazon,
		'has_rakuten' => $has_rakuten,
		'has_price'   => null !== $min_price,
	);
}
