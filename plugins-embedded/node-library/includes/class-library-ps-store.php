<?php
/**
 * PlayStation Store public Japan catalogue adapter (manual fetch; excluded from L3 auto-polling).
 *
 * @package Node_Library
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Library_PS_Store {
	private const GRAPHQL_URL = 'https://web.np.playstation.com/api/graphql/v1/op';

	private const DEFAULT_HASHES = array(
		'metGetProductById'               => 'a128042177bd93dd831164103d53b73ef790d56f51dae647064cb8f9d9fc9d1a',
		'productRetrieveForCtasWithPrice' => '911a43f47dbbf7b2f3d513d6237dedfd679e57af3f34787667a22ecad6a249cd',
	);

	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[A-Z]{2}[0-9]{4}-[A-Z]{4}[0-9]{5}_[0-9]{2}-[A-Z0-9]{16}$/D', $id );
	}

	/**
	 * Resolve a Product ID from raw input or store.playstation.com /product/ URL.
	 * Rejects /concept/ URLs explicitly because they cannot uniquely identify an edition.
	 *
	 * @return string|WP_Error
	 */
	private static function parse_input( string $input ) {
		$input = trim( $input );
		$upper = strtoupper( $input );
		if ( self::valid_id( $upper ) ) {
			return $upper;
		}
		$parsed = Node_Library_Store_ID_Migration::extract( $input );
		if ( 'concept_requires_edition_product_id' === ( $parsed['reason'] ?? '' ) ) {
			return new WP_Error(
				'concept_requires_edition_product_id',
				'PlayStation Storeのconcept URLからはエディションを一意に特定できません。個別の商品ページURL（/product/...）またはProduct IDを入力してください。',
				array( 'status' => 400 )
			);
		}
		if ( 'ps' === ( $parsed['store'] ?? '' ) && is_string( $parsed['id'] ?? null ) && self::valid_id( $parsed['id'] ) ) {
			return $parsed['id'];
		}
		return new WP_Error(
			'invalid_store_id',
			'PlayStation StoreのProduct IDまたは公式商品URL（/product/...）を入力してください。',
			array( 'status' => 400 )
		);
	}

	/**
	 * Allow persisted-query SHA-256 hashes to be overridden via filter when Sony rotates them.
	 */
	public static function graphql_hash( string $operation ): string {
		$default = self::DEFAULT_HASHES[ $operation ] ?? '';
		$hash    = apply_filters( 'node_library_ps_store_graphql_hash', $default, $operation );
		return is_string( $hash ) ? trim( $hash ) : $default;
	}

	/**
	 * Execute one persisted GraphQL query against the Japan PS Store endpoint.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function query_product( string $id, string $operation ) {
		if ( ! self::valid_id( $id ) ) {
			return new WP_Error( 'invalid_store_id', 'PlayStation StoreのProduct IDが無効です。', array( 'status' => 400 ) );
		}
		$hash = self::graphql_hash( $operation );
		if ( '' === $hash ) {
			return new WP_Error( 'ps_store_missing_hash', 'PlayStation StoreのGraphQLクエリハッシュが未設定です。', array( 'status' => 500 ) );
		}

		$url = add_query_arg(
			array(
				'operationName' => $operation,
				'variables'     => rawurlencode( wp_json_encode( array( 'productId' => $id ) ) ),
				'extensions'    => rawurlencode(
					wp_json_encode(
						array(
							'persistedQuery' => array(
								'version'    => 1,
								'sha256Hash' => $hash,
							),
						)
					)
				),
			),
			self::GRAPHQL_URL
		);

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => 3 * MB_IN_BYTES,
				'headers'             => array(
					'Accept'                         => 'application/json',
					'Content-Type'                   => 'application/json',
					'x-psn-store-locale-override'    => 'ja-JP',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ps_store_connection_failed', 'PlayStation Storeへの接続に失敗しました。' . $response->get_error_message(), array( 'status' => 502 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $status ) {
			return new WP_Error( 'ps_store_rate_limited', 'PlayStation Store APIのレート制限に達しました。しばらく待ってから再試行してください（HTTP 429）。', array( 'status' => 429 ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'ps_store_invalid_json', sprintf( 'PlayStation Store APIの応答を解析できませんでした（HTTP %d）。', $status ), array( 'status' => 502 ) );
		}

		if ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
			foreach ( $body['errors'] as $err ) {
				$msg  = is_string( $err['message'] ?? null ) ? $err['message'] : '';
				$code = is_string( $err['extensions']['code'] ?? null ) ? $err['extensions']['code'] : '';
				if ( false !== stripos( $msg, 'PersistedQueryNotFound' ) || 'PERSISTED_QUERY_NOT_FOUND' === strtoupper( $code ) ) {
					return new WP_Error(
						'ps_store_persisted_query_not_found',
						'PlayStation StoreのGraphQLクエリハッシュが失効している可能性があります（PersistedQueryNotFound）。node_library_ps_store_graphql_hash フィルターでハッシュを更新してください。',
						array( 'status' => 502 )
					);
				}
				if ( 404 === (int) ( $err['extensions']['statusCode'] ?? 0 ) || 'data_not_found' === ( $err['extensions']['error']['reason'] ?? '' ) ) {
					return new WP_Error( 'ps_store_product_not_found', 'PlayStation Storeの日本向けカタログに該当商品がありません。', array( 'status' => 404 ) );
				}
			}
			return new WP_Error( 'ps_store_catalog_failed', 'PlayStation Storeのカタログ取得でエラーが返されました。', array( 'status' => 502 ) );
		}

		$product = $body['data']['productRetrieve'] ?? null;
		if ( 200 !== $status || ! is_array( $product ) || $id !== ( $product['id'] ?? '' ) || 'Product' !== ( $product['__typename'] ?? '' ) ) {
			return new WP_Error( 'ps_store_catalog_failed', sprintf( 'PlayStation Storeの商品カタログを取得できませんでした（HTTP %d）。', $status ), array( 'status' => 502 ) );
		}

		return $product;
	}

	/**
	 * Extract the lowest public non-subscription JPY purchase offer from webctas.
	 */
	private static function extract_offer( ?array $price_product ): ?array {
		if ( ! is_array( $price_product ) ) {
			return null;
		}
		$offers = array();
		foreach ( (array) ( $price_product['webctas'] ?? array() ) as $cta ) {
			if ( ! is_array( $cta ) ) {
				continue;
			}
			$price = $cta['price'] ?? null;
			if (
				! is_array( $price )
				|| ! in_array( $cta['type'] ?? '', array( 'ADD_TO_CART', 'ADD_TO_LIBRARY' ), true )
				|| 'JPY' !== ( $price['currencyCode'] ?? '' )
				|| 'APPLICABLE' !== ( $price['applicability'] ?? '' )
				|| ! empty( $price['isExclusive'] )
				|| ! empty( $price['isTiedToSubscription'] )
				|| ! empty( $price['qualifications'] )
			) {
				continue;
			}
			$list   = $price['basePriceValue'] ?? null;
			$amount = $price['discountedValue'] ?? $list;
			if ( ! is_numeric( $amount ) || ! is_numeric( $list ) || (float) $amount < 0 || (float) $amount > (float) $list ) {
				continue;
			}
			$end_ts = self::parse_end_time( $price['endTime'] ?? null );
			if ( $end_ts && $end_ts <= time() && (float) $amount < (float) $list ) {
				continue;
			}
			$offers[] = array(
				'price'      => (float) $amount,
				'list_price' => (float) $list,
				'currency'   => 'JPY',
				'sale_end'   => $end_ts && (float) $amount < (float) $list ? gmdate( 'Y-m-d H:i:s', $end_ts ) : null,
			);
		}
		usort( $offers, static fn( $a, $b ) => $a['price'] <=> $b['price'] );
		return $offers[0] ?? null;
	}

	/**
	 * Parse endTime from epoch milliseconds or ISO 8601 string into UNIX seconds.
	 *
	 * @param mixed $raw Raw endTime value.
	 */
	private static function parse_end_time( $raw ): int {
		if ( is_numeric( $raw ) ) {
			$val = (float) $raw;
			return $val > 1e12 ? (int) floor( $val / 1000 ) : (int) $val;
		}
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$ts = strtotime( $raw );
			return false !== $ts ? (int) $ts : 0;
		}
		return 0;
	}

	/**
	 * Check whether a language code array contains Japanese (e.g. "ja", "ja-JP").
	 *
	 * @param array<int, mixed> $codes Language code list.
	 */
	private static function has_japanese_code( array $codes ): bool {
		foreach ( $codes as $code ) {
			if ( is_string( $code ) && preg_match( '/^ja(?:[-_]|$)/i', trim( $code ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize metGetProductById (+ optional productRetrieveForCtasWithPrice) into L2 schema.
	 *
	 * @param array<string, mixed>      $product       Raw metGetProductById productRetrieve payload.
	 * @param array<string, mixed>|null $price_product Optional productRetrieveForCtasWithPrice payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function normalize( array $product, ?array $price_product = null ) {
		$id    = is_string( $product['id'] ?? null ) ? $product['id'] : '';
		$title = is_string( $product['name'] ?? null ) ? trim( $product['name'] ) : '';
		if ( ! self::valid_id( $id ) || '' === $title ) {
			return new WP_Error( 'ps_store_catalog_failed', '要求した商品に一致する完全なPlayStation Storeカタログ情報がありません。', array( 'status' => 502 ) );
		}

		$concept   = is_array( $product['concept'] ?? null ) ? $product['concept'] : array();
		$publisher = is_string( $product['publisherName'] ?? null ) && '' !== trim( $product['publisherName'] )
			? $product['publisherName']
			: ( is_string( $concept['publisherName'] ?? null ) ? $concept['publisherName'] : '' );

		$release_raw  = is_string( $product['releaseDate'] ?? null ) && '' !== trim( $product['releaseDate'] )
			? $product['releaseDate']
			: ( is_string( $concept['releaseDate']['value'] ?? null ) ? $concept['releaseDate']['value'] : '' );
		$release_date = '';
		if ( '' !== $release_raw && false !== strtotime( $release_raw ) ) {
			$release_date = wp_date( 'Y-m-d', strtotime( $release_raw ), new DateTimeZone( 'Asia/Tokyo' ) );
		}

		$genre_source = ! empty( $product['combinedLocalizedGenres'] ) && is_array( $product['combinedLocalizedGenres'] )
			? $product['combinedLocalizedGenres']
			: ( is_array( $concept['combinedLocalizedGenres'] ?? null ) ? $concept['combinedLocalizedGenres'] : array() );
		$genres       = array_values( array_filter( wp_list_pluck( $genre_source, 'value' ), 'is_string' ) );

		$rating_meta = array();
		$rating_name = is_string( $product['contentRating']['name'] ?? null ) ? $product['contentRating']['name'] : '';
		if ( preg_match( '/^CERO_([ABCDZ])$/D', $rating_name, $match ) ) {
			$rating_meta['cero'] = $match[1];
		}

		// Map only fields backed by separate screenLanguages (UI) / spokenLanguages (audio). Subtitles remain unset.
		$japanese = array();
		if ( is_array( $product['screenLanguages'] ?? null ) && $product['screenLanguages'] ) {
			$japanese['ui'] = self::has_japanese_code( $product['screenLanguages'] );
		}
		if ( is_array( $product['spokenLanguages'] ?? null ) && $product['spokenLanguages'] ) {
			$japanese['audio'] = self::has_japanese_code( $product['spokenLanguages'] );
		}

		$capabilities = array();
		$notices      = is_array( $product['compatibilityNotices'] ?? null ) ? $product['compatibilityNotices'] : array();
		foreach ( $notices as $notice ) {
			$type = is_string( $notice['type'] ?? null ) ? $notice['type'] : '';
			if ( in_array( $type, array( 'NO_OF_NETWORK_PLAYERS_PS_PLUS', 'NO_OF_NETWORK_PLAYERS' ), true ) ) {
				$capabilities['online'] = true;
				break;
			}
		}

		$platforms = array();
		$hw_map    = array( 'PS4' => 'playstation-4', 'PS5' => 'playstation-5' );
		foreach ( (array) ( $product['platforms'] ?? array() ) as $platform ) {
			if ( is_string( $platform ) && isset( $hw_map[ $platform ] ) ) {
				$platforms[] = array( 'category' => 'console', 'hardware' => $hw_map[ $platform ] );
			}
		}
		if ( ! $platforms && preg_match( '/-(PPSA|CUSA)/', $id, $match ) ) {
			$platforms[] = array( 'category' => 'console', 'hardware' => 'PPSA' === $match[1] ? 'playstation-5' : 'playstation-4' );
		}

		$summary = '';
		$descs   = array_merge(
			is_array( $concept['descriptions'] ?? null ) ? $concept['descriptions'] : array(),
			is_array( $product['descriptions'] ?? null ) ? $product['descriptions'] : array()
		);
		foreach ( array( 'SHORT', 'LONG' ) as $preferred_type ) {
			foreach ( $descs as $desc ) {
				if ( is_array( $desc ) && $preferred_type === ( $desc['type'] ?? '' ) && is_string( $desc['value'] ?? null ) && '' !== trim( $desc['value'] ) ) {
					$text    = preg_replace( '/<br\s*\/?>/i', "\n", $desc['value'] );
					$summary = trim( wp_strip_all_tags( (string) $text ) );
					break 2;
				}
			}
		}

		$image_url  = '';
		$media_list = ! empty( $product['media'] ) && is_array( $product['media'] )
			? $product['media']
			: ( is_array( $concept['media'] ?? null ) ? $concept['media'] : array() );
		foreach ( array( 'EDITION_KEY_ART', 'GAMEHUB_COVER_ART', 'MASTER' ) as $preferred_role ) {
			foreach ( $media_list as $asset ) {
				if ( is_array( $asset ) && 'IMAGE' === ( $asset['type'] ?? '' ) && $preferred_role === ( $asset['role'] ?? '' ) && is_string( $asset['url'] ?? null ) ) {
					$image_url = esc_url_raw( $asset['url'], array( 'https' ) );
					break 2;
				}
			}
		}

		$meta = array(
			'store_ids'    => array( 'ps' => $id ),
			'publisher'    => $publisher,
			'genres'       => $genres,
			'release_date' => $release_date,
			'rating'       => $rating_meta,
			'japanese'     => $japanese,
			'capabilities' => $capabilities,
		);
		foreach ( $meta as $field => $value ) {
			$meta[ $field ] = Node_Library_Structured_Meta::sanitize( $field, $value );
		}

		return array(
			'store'                 => 'ps',
			'id'                    => $id,
			'title'                 => sanitize_text_field( $title ),
			'summary'               => sanitize_textarea_field( $summary ),
			'url'                   => 'https://store.playstation.com/ja-jp/product/' . $id,
			'image_url'             => $image_url,
			'price'                 => self::extract_offer( $price_product ),
			'meta'                  => $meta,
			'features'              => array(),
			'editions'              => array(),
			'subscriptions'         => array(),
			'subscription_metadata' => array(),
			'platforms'             => $platforms,
			'relations'             => array(),
			'fetched_at'            => gmdate( 'c' ),
			'source'                => 'playstation_graphql',
		);
	}

	public static function fetch_price( string $id ) {
		$price_product = self::query_product( $id, 'productRetrieveForCtasWithPrice' );
		return is_wp_error( $price_product ) ? $price_product : self::extract_offer( $price_product );
	}

	public static function fetch( string $input ) {
		$id = self::parse_input( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$product = self::query_product( $id, 'metGetProductById' );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		$price_product = self::query_product( $id, 'productRetrieveForCtasWithPrice' );
		if ( is_wp_error( $price_product ) ) {
			return $price_product;
		}
		return self::normalize( $product, $price_product );
	}
}
