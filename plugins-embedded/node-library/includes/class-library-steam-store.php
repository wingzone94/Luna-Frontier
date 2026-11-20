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
		$url = add_query_arg( array( 'appids' => $id, 'cc' => 'jp', 'l' => 'english' ), 'https://store.steampowered.com/api/appdetails' );
		$response = wp_safe_remote_get( $url, array( 'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === $status && false === ( $payload[ $id ]['success'] ?? null ) ) {
			return new WP_Error( 'steam_app_unavailable', 'Steamの日本向けカタログに商品がありません。', array( 'status' => 404 ) );
		}
		$data = $payload[ $id ]['data'] ?? null;
		if ( 200 !== $status || ! is_array( $data ) || (string) ( $data['steam_appid'] ?? '' ) !== $id || ! is_string( $data['name'] ?? null ) || '' === trim( $data['name'] ) ) {
			return new WP_Error( 'steam_catalog_failed', 'Steamのカタログを取得できませんでした。', array( 'status' => 502 ) );
		}
		return $data;
	}

	/** @return array|null|WP_Error */
	private static function price( array $data ) {
		$price = $data['price_overview'] ?? null;
		if ( null === $price ) {
			return true === ( $data['is_free'] ?? null ) ? array( 'price' => 0, 'list_price' => 0, 'currency' => 'JPY', 'sale_end' => null ) : null;
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

	/** The catalogue language list marks full audio with an asterisk. Subtitles are not specified. */
	private static function japanese( $value ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}
		$head = preg_split( '/<br\s*\/?\s*>/i', $value, 2 )[0];
		foreach ( explode( ',', wp_strip_all_tags( $head ) ) as $entry ) {
			if ( preg_match( '/^Japanese\s*(\*)?$/iD', trim( $entry ), $match ) ) {
				return array( 'ui' => true, 'audio' => ! empty( $match[1] ) );
			}
		}
		return array( 'ui' => false, 'audio' => false );
	}

	/** @return array|WP_Error */
	public static function fetch( string $input ) {
		$id = self::app_id( $input );
		$data = self::details( $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$price = self::price( $data );
		if ( is_wp_error( $price ) ) {
			return $price;
		}
		$release = '';
		if ( empty( $data['release_date']['coming_soon'] ) ) {
			$date = $data['release_date']['date'] ?? '';
			foreach ( array( '!j M, Y', '!M j, Y', '!j M Y' ) as $format ) {
				$parsed = is_string( $date ) ? DateTimeImmutable::createFromFormat( $format, $date, new DateTimeZone( 'UTC' ) ) : false;
				$errors = DateTimeImmutable::getLastErrors();
				if ( $parsed && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
					$release = $parsed->format( 'Y-m-d' );
					break;
				}
			}
		}
		$platforms = array();
		foreach ( array( 'windows' => 'windows-pc', 'mac' => 'mac', 'linux' => 'linux' ) as $key => $hardware ) {
			if ( true === ( $data['platforms'][ $key ] ?? null ) ) {
				$platforms[] = array( 'category' => 'pc', 'hardware' => $hardware );
			}
		}
		$categories = wp_list_pluck( (array) ( $data['categories'] ?? array() ), 'id' );
		$capabilities = array();
		if ( array_intersect( array( 27, 36, 38 ), $categories ) ) {
			$capabilities['online'] = true;
		}
		$relations = array();
		foreach ( (array) ( $data['dlc'] ?? array() ) as $dlc ) {
			if ( ( is_int( $dlc ) || is_string( $dlc ) ) && self::valid_id( (string) $dlc ) ) {
				$relations[] = array( 'external_id' => (string) $dlc, 'relation' => 'dlc', 'store' => 'steam' );
			}
		}
		$parent = $data['fullgame']['appid'] ?? '';
		if ( ( is_int( $parent ) || is_string( $parent ) ) && self::valid_id( (string) $parent ) ) {
			$relations[] = array( 'external_id' => (string) $parent, 'relation' => 'included_in', 'store' => 'steam' );
		}
		$meta = array(
			'store_ids' => array( 'steam' => $id ),
			'developer' => implode( ', ', array_filter( (array) ( $data['developers'] ?? array() ), 'is_string' ) ),
			'publisher' => implode( ', ', array_filter( (array) ( $data['publishers'] ?? array() ), 'is_string' ) ),
			'genres' => wp_list_pluck( (array) ( $data['genres'] ?? array() ), 'description' ),
			'release_date' => $release,
			'japanese' => self::japanese( $data['supported_languages'] ?? null ),
			'capabilities' => $capabilities,
		);
		foreach ( $meta as $field => $value ) {
			$meta[ $field ] = Node_Library_Structured_Meta::sanitize( $field, $value );
		}
		return array(
			'store' => 'steam',
			'id' => $id,
			'title' => sanitize_text_field( $data['name'] ),
			'summary' => sanitize_textarea_field( $data['short_description'] ?? '' ),
			'url' => 'https://store.steampowered.com/app/' . $id . '/',
			'image_url' => esc_url_raw( $data['header_image'] ?? '', array( 'https' ) ),
			'price' => $price,
			'meta' => $meta,
			'features' => array_values( wp_list_pluck( (array) ( $data['categories'] ?? array() ), 'description' ) ),
			'editions' => array(),
			'subscriptions' => array(),
			'subscription_metadata' => array(),
			'platforms' => $platforms,
			'relations' => $relations,
			'fetched_at' => gmdate( 'c' ),
			'source' => 'steam_appdetails',
		);
	}
}
