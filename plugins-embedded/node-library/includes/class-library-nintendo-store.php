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
		$input      = trim( $input );
		$normalized = strtoupper( $input );
		if ( preg_match( '/^(?:[0-9]{14}|(?:HAC|BEE)_[A-Z]_[A-Z0-9]{5})$/D', $normalized ) ) {
			return $normalized;
		}
		$parsed = Node_Library_Store_ID_Migration::extract( $input );
		return 'nintendo' === ( $parsed['store'] ?? '' ) ? $parsed['id'] : '';
	}

	/** @return array|WP_Error */
	private static function details( string $id ) {
		$numeric  = self::valid_id( $id );
		$platform = '';
		$code     = '';
		if ( ! $numeric ) {
			if ( ! preg_match( '/^(HAC|BEE)_[A-Z]_([A-Z0-9]{5})$/D', $id, $matches ) ) {
				return new WP_Error( 'invalid_store_id', 'NintendoのNSUID、型番、または公式商品URLを入力してください。', array( 'status' => 400 ) );
			}
			list( , $platform, $code ) = $matches;
		}
		$query    = $numeric ? 'nsuid_s:' . $id : 'icode_s:' . $code;
		$url      = add_query_arg( array( 'fq' => $query, 'limit' => $numeric ? 5 : 100 ), 'https://search.nintendo.jp/nintendo_soft/search.json' );
		$response = wp_safe_remote_get( $url, array( 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'nintendo_connection_failed', 'Nintendoへの接続に失敗しました。' . $response->get_error_message(), array( 'status' => 502 ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $status ) {
			return new WP_Error( 'nintendo_rate_limited', 'Nintendo APIのレート制限に達しました。しばらく待ってから再試行してください（HTTP 429）。', array( 'status' => 429 ) );
		}
		if ( 200 !== $status ) {
			return new WP_Error( 'nintendo_catalog_failed', sprintf( 'Nintendoのカタログ取得に失敗しました（HTTP %d）。', $status ), array( 'status' => 404 === $status ? 404 : 502 ) );
		}
		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = is_array( $body ) ? ( $body['result']['items'] ?? null ) : null;
		if ( ! is_array( $body ) || '0' !== (string) ( $body['status'] ?? '' ) || ! is_array( $items ) ) {
			return new WP_Error( 'nintendo_catalog_failed', 'Nintendo APIの応答形式が不正です。', array( 'status' => 502 ) );
		}
		$matched = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id_value = (string) ( $item['nsuid'] ?? $item['id'] ?? '' );
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

	/**
	 * Parse downloadable JPY price and optional JST sale end date (YYYY-MM-DD HH:MM:SS).
	 */
	private static function price( array $item ): ?array {
		$list = $item['dprice'] ?? null;
		if ( ! is_numeric( $list ) || (float) $list < 0 || 'onsale' !== ( $item['ssitu'] ?? '' ) ) {
			return null;
		}
		$on_sale_flag = '1' === (string) ( $item['sale_flg'] ?? '0' );
		if ( $on_sale_flag && is_numeric( $item['sprice'] ?? null ) ) {
			$current = $item['sprice'];
		} else {
			$current = $item['current_price'] ?? $list;
		}
		if ( ! is_numeric( $current ) || (float) $current < 0 || (float) $current > (float) $list ) {
			return null;
		}
		$sale_end = null;
		if ( (float) $current < (float) $list && is_string( $item['sedate'] ?? null ) ) {
			$raw_end = trim( $item['sedate'] );
			$parsed  = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $raw_end, new DateTimeZone( 'Asia/Tokyo' ) );
			$errors  = DateTimeImmutable::getLastErrors();
			if ( $parsed && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				$sale_end = $parsed->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
		}
		return array(
			'price'      => (float) $current,
			'list_price' => (float) $list,
			'currency'   => 'JPY',
			'sale_end'   => $sale_end,
		);
	}

	public static function fetch_price( string $id ) {
		$item = self::details( $id );
		return is_wp_error( $item ) ? $item : self::price( $item );
	}

	/**
	 * Parse release date: prefer dsdate (YYYY-MM-DD prefix), fall back to sdate (YYYY.M.D).
	 */
	private static function parse_release_date( array $item ): string {
		$dsdate = is_string( $item['dsdate'] ?? null ) ? trim( $item['dsdate'] ) : '';
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:\s|$)/D', $dsdate, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $parts[1], (int) $parts[2], (int) $parts[3] );
		}
		$sdate = is_string( $item['sdate'] ?? null ) ? trim( $item['sdate'] ) : '';
		if ( preg_match( '/^(\d{4})\.(\d{1,2})\.(\d{1,2})$/D', $sdate, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $parts[1], (int) $parts[2], (int) $parts[3] );
		}
		return '';
	}

	/**
	 * Normalize a Nintendo search.nintendo.jp item into the L2 catalogue schema.
	 *
	 * @param array<string, mixed> $item Raw item from result.items.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function normalize( array $item ) {
		$id = (string) ( $item['nsuid'] ?? $item['id'] ?? '' );
		if ( ! self::valid_id( $id ) || ! is_string( $item['title'] ?? null ) || '' === trim( $item['title'] ) ) {
			return new WP_Error( 'nintendo_catalog_failed', '要求した商品に一致する完全なNintendoカタログ情報がありません。', array( 'status' => 502 ) );
		}
		$hardware = '';
		$hard     = (string) ( $item['hard'] ?? '' );
		$sform    = (string) ( $item['sform'] ?? '' );
		if ( str_ends_with( $hard, '_BEE' ) || str_starts_with( $sform, 'BEE_' ) ) {
			$hardware = 'nintendo-switch-2';
		} elseif ( str_ends_with( $hard, '_HAC' ) || str_starts_with( $sform, 'HAC_' ) ) {
			$hardware = 'nintendo-switch';
		}

		$release_date = self::parse_release_date( $item );
		$ratings      = array( '1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D', '5' => 'Z' );
		$cero_code    = is_array( $item['cero'] ?? null ) ? (string) ( $item['cero'][0] ?? '' ) : (string) ( $item['cero'] ?? '' );
		$rating       = $ratings[ $cero_code ] ?? '';

		$capabilities = array();
		$nplayer      = $item['nplayer'] ?? null;
		if ( ( is_array( $nplayer ) && array_filter( $nplayer, static fn( $v ) => is_scalar( $v ) && '' !== trim( (string) $v ) && '0' !== trim( (string) $v ) ) )
			|| ( is_scalar( $nplayer ) && '' !== trim( (string) $nplayer ) && '0' !== trim( (string) $nplayer ) )
		) {
			$capabilities['online'] = true;
		}

		// lang only lists language codes without audio/subtitles/UI separation; leave japanese unset.
		$meta = array(
			'store_ids'    => array( 'nintendo' => $id ),
			'publisher'    => is_string( $item['maker'] ?? null ) ? $item['maker'] : '',
			'genres'       => (array) ( $item['genre'] ?? array() ),
			'release_date' => $release_date,
			'rating'       => '' !== $rating ? array( 'cero' => $rating ) : array(),
			'japanese'     => array(),
			'capabilities' => $capabilities,
		);
		foreach ( $meta as $field => $value ) {
			$meta[ $field ] = Node_Library_Structured_Meta::sanitize( $field, $value );
		}

		$relations = array();
		$seen_rel  = array();
		foreach ( (array) ( $item['cnsuid'] ?? array() ) as $related ) {
			$related_id = is_scalar( $related ) ? trim( (string) $related ) : '';
			if ( self::valid_id( $related_id ) && $related_id !== $id && ! isset( $seen_rel[ $related_id ] ) ) {
				$seen_rel[ $related_id ] = true;
				$relations[]             = array(
					'external_id' => $related_id,
					'relation'    => 'dlc',
					'store'       => 'nintendo',
				);
			}
		}

		$image     = $item['iurl'] ?? '';
		$image_url = is_string( $image ) && preg_match( '/^[a-f0-9]{64}$/iD', $image )
			? 'https://img-eshop.cdn.nintendo.net/i/' . strtolower( $image ) . '.jpg'
			: '';

		$summary = is_string( $item['text'] ?? null ) && '' !== trim( $item['text'] )
			? $item['text']
			: implode( "\n", array_filter( (array) ( $item['hcopy'] ?? array() ), 'is_string' ) );

		return array(
			'store'                 => 'nintendo',
			'id'                    => $id,
			'title'                 => sanitize_text_field( $item['title'] ),
			'summary'               => sanitize_textarea_field( $summary ),
			'url'                   => 'https://store-jp.nintendo.com/item/software/D' . $id,
			'image_url'             => $image_url,
			'price'                 => self::price( $item ),
			'meta'                  => $meta,
			'features'              => array(),
			'editions'              => array(),
			'subscriptions'         => array(),
			'subscription_metadata' => array(),
			'platforms'             => '' !== $hardware ? array( array( 'category' => 'console', 'hardware' => $hardware ) ) : array(),
			'relations'             => $relations,
			'fetched_at'            => gmdate( 'c' ),
			'source'                => 'nintendo_search_catalog',
		);
	}

	public static function fetch( string $input ) {
		$item = self::details( self::parse_input( $input ) );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		return self::normalize( $item );
	}
}
