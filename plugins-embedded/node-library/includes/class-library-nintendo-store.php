<?php
/**
 * Nintendo Japan's public software catalogue.
 *
 * @package Node_Library
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Library_Nintendo_Store {
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[0-9]{14}$/D', $id );
	}

	private static function parse_input( string $input ): string {
		$input = trim( $input );
		$normalized = strtoupper( $input );
		if ( preg_match( '/^(?:[0-9]{14}|(?:HAC|BEE)_[A-Z]_[A-Z0-9]{5})$/D', $normalized ) ) {
			return $normalized;
		}
		$parsed = Node_Library_Store_ID_Migration::extract( trim( $input ) );
		return 'nintendo' === ( $parsed['store'] ?? '' ) ? $parsed['id'] : '';
	}

	/** @return array|WP_Error */
	private static function details( string $id ) {
		$numeric = self::valid_id( $id );
		$platform = '';
		$code = '';
		if ( ! $numeric ) {
			if ( ! preg_match( '/^(HAC|BEE)_[A-Z]_([A-Z0-9]{5})$/D', $id, $matches ) ) {
				return new WP_Error( 'invalid_store_id', 'NintendoのNSUID、型番、または公式商品URLを入力してください。', array( 'status' => 400 ) );
			}
			list( , $platform, $code ) = $matches;
		}
		$query = $numeric ? 'nsuid_s:' . $id : 'icode_s:' . $code;
		$url = add_query_arg( array( 'fq' => $query, 'limit' => $numeric ? 5 : 100 ), 'https://search.nintendo.jp/nintendo_soft/search.json' );
		$response = wp_safe_remote_get( $url, array( 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = $body['result']['items'] ?? null;
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || '0' !== (string) ( $body['status'] ?? '' ) || ! is_array( $items ) ) {
			return new WP_Error( 'nintendo_catalog_failed', 'Nintendoのカタログを取得できませんでした。', array( 'status' => 502 ) );
		}
		$matched = array();
		foreach ( $items as $item ) {
			$id_value = (string) ( $item['nsuid'] ?? '' );
			if ( ! self::valid_id( $id_value ) || empty( $item['title'] ) ) {
				continue;
			}
			if ( $numeric && $id_value === $id ) {
				$matched[ $id_value ] = $item;
			} elseif ( ! $numeric && $code === ( $item['icode'] ?? '' ) && str_ends_with( (string) ( $item['hard'] ?? '' ), '_' . $platform ) && in_array( $item['sform'] ?? '', array( 'HAC_DOWNLOADABLE', 'BEE_DOWNLOADABLE' ), true ) ) {
				$matched[ $id_value ] = $item;
			}
		}
		if ( 1 !== count( $matched ) || ( ! $numeric && count( $items ) >= 100 ) ) {
			$ambiguous = count( $matched ) > 1 || ( ! $numeric && count( $items ) >= 100 );
			return new WP_Error( 'nintendo_id_unresolved', $ambiguous ? '型番から商品を一意に決められません。14桁のNSUIDで指定してください。' : 'Nintendoカタログに一致する商品がありません。', array( 'status' => $ambiguous ? 400 : 404 ) );
		}
		return reset( $matched );
	}

	private static function price( array $item ): ?array {
		$list = $item['dprice'] ?? null;
		$current = $item['current_price'] ?? $list;
		if ( ! is_numeric( $list ) || ! is_numeric( $current ) || $list < 0 || $current < 0 || $current > $list || 'onsale' !== ( $item['ssitu'] ?? '' ) ) {
			return null;
		}
		$date = $item['sedate'] ?? null;
		$end = is_string( $date ) && (float) $current < (float) $list ? strtotime( $date . ' Asia/Tokyo' ) : false;
		return array( 'price' => (float) $current, 'list_price' => (float) $list, 'currency' => 'JPY', 'sale_end' => $end ? gmdate( 'Y-m-d H:i:s', $end ) : null );
	}

	public static function fetch_price( string $id ) {
		$item = self::details( $id );
		return is_wp_error( $item ) ? $item : self::price( $item );
	}

	public static function fetch( string $input ) {
		$item = self::details( self::parse_input( $input ) );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$id = (string) $item['nsuid'];
		$hardware = '';
		foreach ( array( '_HAC' => 'nintendo-switch', '_BEE' => 'nintendo-switch-2' ) as $suffix => $platform ) {
			if ( str_ends_with( (string) ( $item['hard'] ?? '' ), $suffix ) ) {
				$hardware = $platform;
			}
		}
		$date = (string) ( $item['sdate'] ?? '' );
		$release_date = '';
		if ( preg_match( '/^(\d{4})\.(\d{1,2})\.(\d{1,2})$/D', $date, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			$release_date = sprintf( '%04d-%02d-%02d', $parts[1], $parts[2], $parts[3] );
		}
		$ratings = array( '1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D', '5' => 'Z' );
		$rating = $ratings[ $item['cero'][0] ?? '' ] ?? '';
		$meta = array(
			'store_ids' => array( 'nintendo' => $id ),
			'publisher' => $item['maker'] ?? '',
			'genres' => (array) ( $item['genre'] ?? array() ),
			'release_date' => $release_date,
			'rating' => $rating ? array( 'cero' => $rating ) : array(),
		);
		$lang = $item['lang'] ?? null;
		if ( is_array( $lang ) && $lang ) {
			$meta['japanese'] = array( 'ui' => in_array( 'ja', $lang, true ) );
		}
		foreach ( $meta as $field => $value ) {
			$meta[ $field ] = Node_Library_Structured_Meta::sanitize( $field, $value );
		}
		$image = $item['iurl'] ?? '';
		$image_url = is_string( $image ) && preg_match( '/^[a-f0-9]{64}$/iD', $image ) ? 'https://img-eshop.cdn.nintendo.net/i/' . strtolower( $image ) . '.jpg' : '';
		return array(
			'store' => 'nintendo',
			'id' => $id,
			'title' => sanitize_text_field( $item['title'] ),
			'summary' => sanitize_textarea_field( implode( "\n", array_filter( (array) ( $item['hcopy'] ?? array() ), 'is_string' ) ) ),
			'url' => 'https://store-jp.nintendo.com/item/software/D' . $id,
			'image_url' => $image_url,
			'price' => self::price( $item ),
			'meta' => $meta,
			'features' => array(),
			'editions' => array(),
			'subscriptions' => array(),
			'subscription_metadata' => array(),
			'platforms' => $hardware ? array( array( 'category' => 'console', 'hardware' => $hardware ) ) : array(),
			'relations' => array(),
			'fetched_at' => gmdate( 'c' ),
			'source' => 'nintendo_search_catalog',
		);
	}
}
