<?php
/**
 * PlayStation Store public Japan catalogue and offer data.
 *
 * @package Node_Library
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Library_PS_Store {
	private const GRAPHQL_URL = 'https://web.np.playstation.com/api/graphql/v1/op';
	private const OPERATIONS = array(
		'productRetrieveForCtasWithPrice' => '911a43f47dbbf7b2f3d513d6237dedfd679e57af3f34787667a22ecad6a249cd',
		'productRetrieveForUpsellWithCtas' => '9bd6ae6de5060fbe63fac5f394e7e5c75532743f06241b3ac835985267fba2b5',
	);

	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[A-Z]{2}[0-9]{4}-[A-Z]{4}[0-9]{5}_[0-9]{2}-[A-Z0-9]{16}$/D', $id );
	}

	private static function input_id( string $input ): string {
		$input = trim( $input );
		$id = strtoupper( $input );
		if ( self::valid_id( $id ) ) {
			return $id;
		}
		$result = Node_Library_Store_ID_Migration::extract( $input );
		return 'ps' === ( $result['store'] ?? '' ) ? $result['id'] : '';
	}

	/** @return array|WP_Error */
	private static function product( string $id, string $operation ) {
		if ( ! self::valid_id( $id ) ) {
			return new WP_Error( 'invalid_store_id', 'PlayStation Storeの商品IDまたは公式商品URLを入力してください。', array( 'status' => 400 ) );
		}
		// Persisted operations used by the public store. No sign-in or session data is sent.
		$url = add_query_arg(
			array(
				'operationName' => $operation,
				'variables' => rawurlencode( wp_json_encode( array( 'productId' => $id ) ) ),
				'extensions' => rawurlencode( wp_json_encode( array( 'persistedQuery' => array( 'version' => 1, 'sha256Hash' => self::OPERATIONS[ $operation ] ) ) ) ),
			),
			self::GRAPHQL_URL
		);
		$response = wp_safe_remote_get( $url, array(
			'timeout' => 10,
			'redirection' => 0,
			'limit_response_size' => 3 * MB_IN_BYTES,
			'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'Accept-Language' => 'ja-JP', 'X-PSN-Store-Locale' => 'ja-jp' ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$product = $body['data']['productRetrieve'] ?? null;
		if ( 200 !== $status || ! empty( $body['errors'] ) || ! is_array( $product ) || $id !== ( $product['id'] ?? '' ) || 'Product' !== ( $product['__typename'] ?? '' ) ) {
			return new WP_Error( 'ps_store_catalog_failed', 'PlayStation Storeの商品カタログを取得できませんでした。', array( 'status' => 502 ) );
		}
		return $product;
	}

	private static function offer( array $product ): ?array {
		$offers = array();
		foreach ( (array) ( $product['webctas'] ?? array() ) as $cta ) {
			$price = $cta['price'] ?? null;
			if ( ! is_array( $price ) || ! in_array( $cta['type'] ?? '', array( 'ADD_TO_CART', 'ADD_TO_LIBRARY' ), true ) || 'JPY' !== ( $price['currencyCode'] ?? '' ) || 'APPLICABLE' !== ( $price['applicability'] ?? '' ) || ! empty( $price['isExclusive'] ) || ! empty( $price['isTiedToSubscription'] ) || ! empty( $price['qualifications'] ) ) {
				continue;
			}
			$list = $price['basePriceValue'] ?? null;
			$amount = $price['discountedValue'] ?? $list;
			if ( ! is_numeric( $amount ) || ! is_numeric( $list ) || $amount < 0 || $amount > $list ) {
				continue;
			}
			$end = is_numeric( $price['endTime'] ?? null ) ? (int) ( (float) $price['endTime'] / 1000 ) : 0;
			if ( $end && $end <= time() && $amount < $list ) {
				// An expired discount from an API cache must not be recorded as a live offer.
				continue;
			}
			$offers[] = array(
				'price' => (float) $amount,
				'list_price' => (float) $list,
				'currency' => 'JPY',
				'sale_end' => $end && $amount < $list ? gmdate( 'Y-m-d H:i:s', $end ) : null,
			);
		}
		usort( $offers, static fn( $a, $b ) => $a['price'] <=> $b['price'] );
		return $offers[0] ?? null;
	}

	public static function fetch_price( string $id ) {
		$product = self::product( $id, 'productRetrieveForCtasWithPrice' );
		return is_wp_error( $product ) ? $product : self::offer( $product );
	}

	public static function fetch( string $input ) {
		$id = self::input_id( $input );
		$product = self::product( $id, 'productRetrieveForCtasWithPrice' );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		$details = self::product( $id, 'productRetrieveForUpsellWithCtas' );
		if ( is_wp_error( $details ) ) {
			return $details;
		}
		$title = $product['name'] ?? '';
		if ( ! is_string( $title ) || '' === trim( $title ) ) {
			return new WP_Error( 'ps_store_no_title', 'PlayStation Storeのタイトルが取得できませんでした。', array( 'status' => 502 ) );
		}
		$variants = array();
		$selected = array();
		foreach ( (array) ( $details['concept']['products'] ?? array() ) as $entry ) {
			$related_id = $entry['id'] ?? '';
			if ( ! is_string( $related_id ) || ! self::valid_id( $related_id ) ) {
				continue;
			}
			if ( $id === $related_id ) {
				$selected = $entry;
			} else {
				$variants[] = $related_id;
			}
		}
		$platforms = array();
		$map = array( 'PS4' => 'playstation-4', 'PS5' => 'playstation-5' );
		foreach ( (array) ( $selected['platforms'] ?? array() ) as $platform ) {
			if ( isset( $map[ $platform ] ) ) {
				$platforms[] = array( 'category' => 'console', 'hardware' => $map[ $platform ] );
			}
		}
		if ( ! $platforms && preg_match( '/-(PPSA|CUSA)/', $id, $match ) ) {
			$platforms[] = array( 'category' => 'console', 'hardware' => 'PPSA' === $match[1] ? 'playstation-5' : 'playstation-4' );
		}
		$meta = array(
			'store_ids' => array( 'ps' => $id ),
			'genres' => wp_list_pluck( (array) ( $selected['localizedGenres'] ?? array() ), 'value' ),
		);
		$rating = $selected['contentRating']['name'] ?? '';
		if ( is_string( $rating ) && preg_match( '/^CERO_([ABCDZ])$/D', $rating, $match ) ) {
			$meta['rating'] = array( 'cero' => $match[1] );
		}
		foreach ( $meta as $field => $value ) {
			$meta[ $field ] = Node_Library_Structured_Meta::sanitize( $field, $value );
		}
		$image = '';
		foreach ( (array) ( $selected['media'] ?? array() ) as $asset ) {
			if ( 'IMAGE' === ( $asset['type'] ?? '' ) && 'EDITION_KEY_ART' === ( $asset['role'] ?? '' ) ) {
				$image = esc_url_raw( $asset['url'] ?? '', array( 'https' ) );
				break;
			}
		}
		$editions = array_values( array_unique( $variants ) );
		return array(
			'store' => 'ps', 'id' => $id, 'title' => sanitize_text_field( $title ), 'summary' => '',
			'url' => 'https://store.playstation.com/ja-jp/product/' . $id,
			'image_url' => $image, 'price' => self::offer( $product ), 'meta' => $meta,
			'features' => array(), 'editions' => $editions,
			'subscriptions' => array(), 'subscription_metadata' => array(),
			'platforms' => $platforms,
			'relations' => array_map( static fn( $key ) => array( 'external_id' => $key, 'relation' => 'edition', 'store' => 'ps' ), $editions ),
			'fetched_at' => gmdate( 'c' ), 'source' => 'playstation_graphql',
		);
	}
}
