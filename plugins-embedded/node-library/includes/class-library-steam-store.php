<?php
/**
 * Steam store catalogue support for Japan.
 *
 * @package Node_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Library_Steam_Store {
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) && (float) $id <= 4294967295;
	}

	private static function app_id( string $input ): string {
		$input = trim( $input );
		if ( self::valid_id( $input ) ) {
			return $input;
		}
		$url = wp_parse_url( $input );
		if ( ! is_array( $url ) || 'https' !== ( $url['scheme'] ?? '' ) || 'store.steampowered.com' !== strtolower( $url['host'] ?? '' ) || ( isset( $url['user'] ) || isset( $url['pass'] ) ) ) {
			return '';
		}
		return preg_match( '#^/app/([0-9]+)(?:/|$)#D', $url['path'] ?? '', $match ) && self::valid_id( $match[1] ) ? $match[1] : '';
	}

	/** @return array|WP_Error */
	private static function details( string $id ) {
		if ( ! self::valid_id( $id ) ) {
			return new WP_Error( 'invalid_store_id', 'SteamのApp IDまたは公式商品URLを入力してください。', array( 'status' => 400 ) );
		}
		$url      = add_query_arg( array( 'appids' => $id, 'cc' => 'jp', 'l' => 'japanese' ), 'https://store.steampowered.com/api/appdetails' );
		$response = wp_safe_remote_get( $url, array( 'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'steam_connection_failed', 'Steamへの接続に失敗しました。' . $response->get_error_message(), array( 'status' => 502 ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $status ) {
			return new WP_Error( 'steam_rate_limited', 'Steam APIのレート制限に達しました。しばらく待ってから再試行してください（HTTP 429）。', array( 'status' => 429 ) );
		}
		if ( 200 !== $status ) {
			return new WP_Error( 'steam_catalog_failed', sprintf( 'Steamのカタログ取得に失敗しました（HTTP %d）。', $status ), array( 'status' => 404 === $status ? 404 : 502 ) );
		}
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'steam_invalid_json', 'Steam APIの応答を解析できませんでした。', array( 'status' => 502 ) );
		}
		if ( false === ( $payload[ $id ]['success'] ?? null ) ) {
			return new WP_Error( 'steam_app_unavailable', 'Steamの日本向けカタログに商品がありません。', array( 'status' => 404 ) );
		}
		$data = $payload[ $id ]['data'] ?? null;
		if ( ! is_array( $data ) || (string) ( $data['steam_appid'] ?? '' ) !== $id || ! is_string( $data['name'] ?? null ) || '' === trim( $data['name'] ) ) {
			return new WP_Error( 'steam_catalog_failed', '要求した商品に一致する完全なSteamカタログ情報がありません。', array( 'status' => 502 ) );
		}
		return $data;
	}

	/** @return array|null|WP_Error */
	private static function price( array $data ) {
		if ( true === ( $data['is_free'] ?? null ) ) {
			return array( 'price' => 0, 'list_price' => 0, 'currency' => 'JPY', 'sale_end' => null );
		}
		$price = $data['price_overview'] ?? null;
		if ( null === $price ) {
			return null;
		}
		if ( ! is_array( $price ) || 'JPY' !== ( $price['currency'] ?? '' ) || ! is_int( $price['initial'] ?? null ) || ! is_int( $price['final'] ?? null ) || $price['final'] < 0 || $price['initial'] < $price['final'] ) {
			return new WP_Error( 'steam_invalid_price', 'Steamの日本円価格データを読み取れませんでした。', array( 'status' => 502 ) );
		}
		// Steam reports Japan prices in hundredths of one yen.
		return array( 'price' => $price['final'] / 100, 'list_price' => $price['initial'] / 100, 'currency' => 'JPY', 'sale_end' => null );
	}

	public static function fetch_price( string $id ) {
		$data = self::details( $id );
		return is_wp_error( $data ) ? $data : self::price( $data );
	}

	/**
	 * Parse Steam supported_languages string (English or Japanese locale).
	 * Steam marks full audio with an asterisk (*). Subtitles cannot be separated reliably, so they stay unset.
	 *
	 * @param mixed $value Raw supported_languages HTML string.
	 * @return array<string, bool>
	 */
	private static function japanese( $value ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}
		$head = preg_split( '/<br\s*\/?\s*>/i', $value, 2 )[0];
		foreach ( explode( ',', wp_strip_all_tags( $head ) ) as $entry ) {
			$normalized = trim( html_entity_decode( $entry, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( preg_match( '/^(?:Japanese|日本語)\s*(\*)?$/iuD', $normalized, $match ) ) {
				return array( 'ui' => true, 'audio' => ! empty( $match[1] ) );
			}
		}
		return array( 'ui' => false, 'audio' => false );
	}

	/**
	 * Parse Steam release_date.date in Japanese (YYYY年M月D日) or English formats.
	 *
	 * @param mixed $release_date Raw release_date array.
	 */
	private static function parse_release_date( $release_date ): string {
		if ( ! is_array( $release_date ) || ! empty( $release_date['coming_soon'] ) ) {
			return '';
		}
		$date = is_string( $release_date['date'] ?? null ) ? trim( $release_date['date'] ) : '';
		if ( '' === $date ) {
			return '';
		}
		if ( preg_match( '/^(\d{4})年\s*(\d{1,2})月\s*(\d{1,2})日$/uD', $date, $match ) ) {
			$year  = (int) $match[1];
			$month = (int) $match[2];
			$day   = (int) $match[3];
			if ( checkdate( $month, $day, $year ) ) {
				return sprintf( '%04d-%02d-%02d', $year, $month, $day );
			}
		}
		foreach ( array( '!j M, Y', '!M j, Y', '!j M Y' ) as $format ) {
			$parsed = DateTimeImmutable::createFromFormat( $format, $date, new DateTimeZone( 'UTC' ) );
			$errors = DateTimeImmutable::getLastErrors();
			if ( $parsed && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $parsed->format( 'Y-m-d' );
			}
		}
		return '';
	}

	/**
	 * Normalize a validated Steam appdetails data payload into the L2 catalogue schema.
	 *
	 * @param array<string, mixed> $data Raw data object from appdetails[id]['data'].
	 * @return array<string, mixed>|WP_Error
	 */
	public static function normalize( array $data ) {
		$id = isset( $data['steam_appid'] ) ? (string) $data['steam_appid'] : '';
		if ( ! self::valid_id( $id ) || ! is_string( $data['name'] ?? null ) || '' === trim( $data['name'] ) ) {
			return new WP_Error( 'steam_catalog_failed', '要求した商品に一致する完全なSteamカタログ情報がありません。', array( 'status' => 502 ) );
		}
		$price = self::price( $data );
		if ( is_wp_error( $price ) ) {
			return $price;
		}
		$release   = self::parse_release_date( $data['release_date'] ?? null );
		$platforms = array();
		foreach ( array( 'windows' => 'windows-pc', 'mac' => 'mac' ) as $key => $hardware ) {
			if ( true === ( $data['platforms'][ $key ] ?? null ) ) {
				$platforms[] = array( 'category' => 'pc', 'hardware' => $hardware );
			}
		}
		$categories   = array_map( 'intval', wp_list_pluck( (array) ( $data['categories'] ?? array() ), 'id' ) );
		$capabilities = array();
		if ( in_array( 27, $categories, true ) ) {
			$capabilities['crossplay'] = true;
		}
		if ( array_intersect( array( 27, 36, 38 ), $categories ) ) {
			$capabilities['online'] = true;
		}
		$relations = array();
		$seen_rel  = array();
		foreach ( (array) ( $data['dlc'] ?? array() ) as $dlc ) {
			if ( ( is_int( $dlc ) || is_string( $dlc ) ) && self::valid_id( (string) $dlc ) ) {
				$dlc_id = (string) $dlc;
				if ( ! isset( $seen_rel[ 'dlc:' . $dlc_id ] ) ) {
					$seen_rel[ 'dlc:' . $dlc_id ] = true;
					$relations[]                  = array( 'external_id' => $dlc_id, 'relation' => 'dlc', 'store' => 'steam' );
				}
			}
		}
		$parent = $data['fullgame']['appid'] ?? '';
		if ( ( is_int( $parent ) || is_string( $parent ) ) && self::valid_id( (string) $parent ) ) {
			$parent_id = (string) $parent;
			if ( ! isset( $seen_rel[ 'included_in:' . $parent_id ] ) ) {
				$seen_rel[ 'included_in:' . $parent_id ] = true;
				$relations[]                             = array( 'external_id' => $parent_id, 'relation' => 'included_in', 'store' => 'steam' );
			}
		}
		$meta = array(
			'store_ids'    => array( 'steam' => $id ),
			'developer'    => implode( ', ', array_filter( (array) ( $data['developers'] ?? array() ), 'is_string' ) ),
			'publisher'    => implode( ', ', array_filter( (array) ( $data['publishers'] ?? array() ), 'is_string' ) ),
			'genres'       => wp_list_pluck( (array) ( $data['genres'] ?? array() ), 'description' ),
			'release_date' => $release,
			'japanese'     => self::japanese( $data['supported_languages'] ?? null ),
			'capabilities' => $capabilities,
		);
		foreach ( $meta as $field => $value ) {
			$meta[ $field ] = Node_Library_Structured_Meta::sanitize( $field, $value );
		}
		$price_notice = '';
		if ( null === $price ) {
			$has_packages = ! empty( $data['package_groups'][0]['subs'] ) && is_array( $data['package_groups'][0]['subs'] );
			$price_notice = $has_packages
				? '単体価格（price_overview）がなく、バンドル／パッケージ専用商品の可能性があります。'
				: '単体販売の価格情報がありません（旧版・販売終了・価格未定の可能性があります）。';
		}
		return array(
			'store'                 => 'steam',
			'id'                    => $id,
			'title'                 => sanitize_text_field( $data['name'] ),
			'summary'               => sanitize_textarea_field( $data['short_description'] ?? '' ),
			'url'                   => 'https://store.steampowered.com/app/' . $id . '/',
			'image_url'             => esc_url_raw( $data['header_image'] ?? '', array( 'https' ) ),
			'price'                 => $price,
			'price_notice'          => $price_notice,
			'meta'                  => $meta,
			'features'              => array_values( wp_list_pluck( (array) ( $data['categories'] ?? array() ), 'description' ) ),
			'editions'              => array(),
			'subscriptions'         => array(),
			'subscription_metadata' => array(),
			'platforms'             => $platforms,
			'relations'             => $relations,
			'fetched_at'            => gmdate( 'c' ),
			'source'                => 'steam_appdetails',
		);
	}

	/** @return array|WP_Error */
	public static function fetch( string $input ) {
		$id   = self::app_id( $input );
		$data = self::details( $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::normalize( $data );
	}
}
