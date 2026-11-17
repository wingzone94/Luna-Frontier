<?php
/**
 * Deterministic catalogue previews and confirmed catalogue persistence.
 *
 * @package Node_Library
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Library_Store {
	public static function init(): void {
		add_action( 'init', array( self::class, 'install' ), 5 );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'add_meta_boxes_node_library', array( self::class, 'add_meta_box' ) );
		add_action( 'save_post_node_library', array( self::class, 'save_form' ), 30 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'before_delete_post', array( self::class, 'delete_relations' ), 10, 2 );
	}

	/** Dedicated relation storage, shared by all store adapters. */
	public static function install(): void {
		if ( '1' === get_option( 'node_library_relations_schema' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'node_library_relations';
		dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			item_id bigint(20) unsigned NOT NULL,
			related_item_id bigint(20) unsigned DEFAULT NULL,
			external_id varchar(191) NOT NULL,
			relation varchar(32) NOT NULL,
			store varchar(24) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY item_relation (item_id,store,relation,external_id),
			KEY external_lookup (store,external_id),
			KEY related_item (related_item_id)
		) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			update_option( 'node_library_relations_schema', '1', false );
		}
	}

	/** Remove owned relations; external references from other items remain usable. */
	public static function delete_relations( int $post_id, $post ): void {
		if ( 'node_library' !== $post->post_type || '1' !== get_option( 'node_library_relations_schema' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'node_library_relations';
		$wpdb->delete( $table, array( 'item_id' => $post_id ), array( '%d' ) );
		$wpdb->update( $table, array( 'related_item_id' => null ), array( 'related_item_id' => $post_id ) );
	}

	public static function routes(): void {
		register_rest_route( 'node-library/v1', '/fetch-store', array(
			'methods' => 'POST', 'callback' => array( self::class, 'preview' ),
			'permission_callback' => static function ( $request ) {
				$id = absint( $request->get_param( 'post_id' ) );
				return $id && 'node_library' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
			},
			'args' => array(
				'post_id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
				'store' => array( 'type' => 'string', 'default' => 'microsoft', 'enum' => array( 'microsoft' ) ),
				'input' => array( 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 2048 ),
			),
		) );
	}

	/** @return array|WP_Error A complete normalized catalogue result, never partial success. */
	public static function fetch( string $store, string $input ) {
		if ( 'microsoft' !== $store ) {
			return new WP_Error( 'unsupported_store', 'このストアの取得にはまだ対応していません。', array( 'status' => 400 ) );
		}
		return Node_Library_Microsoft_Store::fetch( $input );
	}

	public static function price_stores(): array {
		return array( 'microsoft' );
	}

	/** @return array|null|WP_Error Current public offer, no offer, or an acquisition error. */
	public static function fetch_price( string $store, string $id ) {
		if ( 'microsoft' === $store ) {
			return Node_Library_Microsoft_Store::fetch_price( $id );
		}
		return new WP_Error( 'unsupported_store', '価格取得に対応していないストアです。', array( 'status' => 400 ) );
	}

	public static function preview( WP_REST_Request $request ) {
		$result = self::fetch( $request['store'] ?: 'microsoft', $request['input'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$ticket = wp_generate_uuid4();
		if ( ! set_transient( 'node_library_catalog_' . $ticket, array( 'user_id' => get_current_user_id(), 'post_id' => (int) $request['post_id'], 'catalog' => $result ), 2 * HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'catalog_preview_cache_failed', 'プレビューを一時保存できませんでした。編集欄には反映しません。', array( 'status' => 500 ) );
		}
		$result['ticket'] = $ticket;
		return rest_ensure_response( $result );
	}

	public static function add_meta_box(): void {
		add_meta_box( 'node_library_catalog_fetch', __( 'ストアカタログから取得', 'node-library' ), array( self::class, 'render' ), 'node_library', 'normal', 'high' );
	}

	public static function render( $post ): void {
		wp_nonce_field( 'node_library_save_catalog', 'node_library_catalog_nonce' );
		?>
		<p><label for="node-library-store"><strong><?php esc_html_e( 'ストア', 'node-library' ); ?></strong></label><br>
		<select id="node-library-store"><option value="microsoft">Microsoft Store / Xbox</option></select></p>
		<p><label for="node-library-store-input"><?php esc_html_e( 'プロダクトIDまたはストアURL', 'node-library' ); ?></label><br>
		<input class="large-text" id="node-library-store-input" type="text" placeholder="9NBLGGH537BL" maxlength="2048"></p>
		<p><button type="button" class="button button-secondary" id="node-library-fetch-store" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"><?php esc_html_e( 'カタログを取得', 'node-library' ); ?></button></p>
		<p id="node-library-fetch-store-status" role="status" aria-live="polite"></p>
		<div id="node-library-store-preview" hidden>
			<pre id="node-library-store-preview-text" style="white-space:pre-wrap;overflow-wrap:anywhere"></pre>
			<button type="button" class="button" id="node-library-apply-store"><?php esc_html_e( '確認した情報を編集欄へ反映', 'node-library' ); ?></button>
		</div>
		<input type="hidden" name="node_library_catalog_ticket" id="node-library-catalog-ticket" value="">
		<p class="description"><?php esc_html_e( '取得だけでは保存しません。プレビューを確認して編集欄へ反映し、最後に項目を保存してください。APIが提供しない情報は未設定のままです。', 'node-library' ); ?></p>
		<?php
	}

	public static function notice(): void {
		$message = get_transient( 'node_library_catalog_error_' . get_current_user_id() );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( 'node_library_catalog_error_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/** Persist an already confirmed server-side preview. Keep old links untouched. */
	public static function save_form( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$nonce = $_POST['node_library_catalog_nonce'] ?? null;
		$ticket = $_POST['node_library_catalog_ticket'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'node_library_save_catalog' ) || ! is_string( $ticket ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $ticket ) ) {
			return;
		}
		$pending = get_transient( 'node_library_catalog_' . $ticket );
		if ( ! is_array( $pending ) || $pending['post_id'] !== $post_id || $pending['user_id'] !== get_current_user_id() ) {
			set_transient( 'node_library_catalog_error_' . get_current_user_id(), '取得情報の期限が切れました。カタログを再取得して関係情報を保存してください。', MINUTE_IN_SECONDS );
			return;
		}
		$ids = get_post_meta( $post_id, '_node_library_store_ids', true );
		if ( ! is_array( $ids ) || ( $ids[ $pending['catalog']['store'] ] ?? null ) !== $pending['catalog']['id'] ) {
			set_transient( 'node_library_catalog_error_' . get_current_user_id(), 'ストアIDが取得時と変わっています。再取得して関係情報を保存してください。', MINUTE_IN_SECONDS );
			return;
		}
		$result = self::persist( $post_id, $pending['catalog'] );
		if ( is_wp_error( $result ) ) {
			set_transient( 'node_library_catalog_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
			return;
		}
		delete_transient( 'node_library_catalog_' . $ticket );
	}

	/** @return true|WP_Error Atomically replace one store's snapshot and relations. */
	public static function persist( int $post_id, array $catalog ) {
		global $wpdb;
		if ( 'node_library' !== get_post_type( $post_id ) || ! in_array( $catalog['store'] ?? '', array( 'microsoft' ), true ) || ! is_array( $catalog['relations'] ?? null ) ) {
			return new WP_Error( 'invalid_catalog', '保存対象のカタログ情報が無効です。' );
		}
		self::install();
		$table = $wpdb->prefix . 'node_library_relations';
		$store = $catalog['store'];
		$rows = array();
		foreach ( $catalog['relations'] as $row ) {
			if ( ! is_array( $row ) || ! in_array( $row['relation'] ?? '', array( 'dlc', 'included_in', 'bundle_contains' ), true ) || ! is_string( $row['external_id'] ?? null ) || ! preg_match( '/^[A-Z0-9]{12}$/D', $row['external_id'] ) ) {
				return new WP_Error( 'invalid_relation', '関係情報が無効なため保存を中止しました。' );
			}
			$rows[ $row['relation'] . ':' . $row['external_id'] ] = array( 'item_id' => $post_id, 'external_id' => $row['external_id'], 'relation' => $row['relation'], 'store' => $store );
		}
		$all = get_post_meta( $post_id, '_node_library_catalog', true );
		$all = is_array( $all ) ? $all : array();
		$snapshot = $catalog;
		unset( $snapshot['relations'] ); // Relations live in their dedicated table.
		$all[ $store ] = $snapshot;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'catalog_transaction_failed', '保存トランザクションを開始できません。' );
		}
		try {
			if ( false === $wpdb->delete( $table, array( 'item_id' => $post_id, 'store' => $store ), array( '%d', '%s' ) ) ) {
				throw new RuntimeException( 'delete' );
			}
			foreach ( $rows as $row ) {
				if ( false === $wpdb->insert( $table, $row ) ) {
					throw new RuntimeException( 'insert' );
				}
			}
			update_post_meta( $post_id, '_node_library_catalog', wp_slash( $all ) );
			if ( get_post_meta( $post_id, '_node_library_catalog', true ) !== $all ) {
				throw new RuntimeException( 'snapshot' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'commit' );
			}
		} catch ( RuntimeException $error ) {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_delete( $post_id, 'post_meta' );
			return new WP_Error( 'catalog_save_failed', 'カタログ・関係情報の保存に失敗しました。再取得して保存してください。' );
		}
		return true;
	}
}

/** Public Microsoft catalogue endpoints currently used by the official Xbox store. */
final class Node_Library_Microsoft_Store {
	/** @return array|WP_Error */
	private static function request( string $url, ?array $body = null ) {
		$args = array( 'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 4 * MB_IN_BYTES, 'headers' => array( 'Accept' => 'application/json', 'MS-CV' => base64_encode( random_bytes( 12 ) ) . '.0', 'X-MS-API-Version' => null === $body ? '1.0' : '1.1' ) );
		if ( null !== $body ) {
			$args['method'] = 'POST';
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'store_connection_failed', 'Microsoft Storeへの接続に失敗しました。' . $response->get_error_message(), array( 'status' => 502 ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $data ) || isset( $data['error'] ) ) {
			return new WP_Error( 'store_catalog_failed', sprintf( 'Microsoft Storeの取得に失敗しました（HTTP %d）。', $status ), array( 'status' => 404 === $status ? 404 : 502 ) );
		}
		return $data;
	}

	/** @return array|WP_Error */
	public static function fetch( string $input ) {
		$id = strtoupper( trim( $input ) );
		if ( ! preg_match( '/^[A-Z0-9]{12}$/D', $id ) ) {
			$parsed = Node_Library_Store_ID_Migration::extract( $input );
			$id = 'microsoft' === ( $parsed['store'] ?? '' ) ? $parsed['id'] : '';
		}
		if ( '' === $id ) {
			return new WP_Error( 'invalid_store_id', 'Microsoft Storeの12桁プロダクトIDまたは公式ストアURLを入力してください。', array( 'status' => 400 ) );
		}
		$raw = self::request( 'https://displaycatalog.mp.microsoft.com/v7.0/products/' . $id . '?market=JP&languages=ja-jp' );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$details = self::request( 'https://emerald.xboxservices.com/xboxcomfd/products/' . $id . '?locale=ja-JP' );
		if ( is_wp_error( $details ) ) {
			return $details;
		}
		$product = $raw['Product'] ?? null;
		$summary = null;
		foreach ( $details['productSummaries'] ?? array() as $candidate ) {
			if ( ( $candidate['productId'] ?? null ) === $id ) {
				$summary = $candidate;
				break;
			}
		}
		if ( ! is_array( $product ) || ( $product['ProductId'] ?? null ) !== $id || ! is_array( $summary ) || ! is_string( $summary['title'] ?? null ) || '' === trim( $summary['title'] ) ) {
			return new WP_Error( 'invalid_store_response', '要求した商品に一致する完全なカタログ情報がありません。', array( 'status' => 502 ) );
		}
		$relations = array();
		foreach ( $summary['bundledProductIds'] ?? array() as $related ) {
			$relations[] = array( 'external_id' => $related, 'relation' => 'bundle_contains', 'store' => 'microsoft' );
		}
		foreach ( $product['MarketProperties'][0]['RelatedProducts'] ?? array() as $related ) {
			if ( in_array( $related['RelationshipType'] ?? '', array( 'Bundle', 'Parent' ), true ) ) {
				$relations[] = array( 'external_id' => $related['RelatedProductId'], 'relation' => 'included_in', 'store' => 'microsoft' );
			}
		}
		if ( ! empty( $summary['hasAddOns'] ) ) {
			$addons = self::addons( $id );
			if ( is_wp_error( $addons ) ) {
				return $addons;
			}
			$relations = array_merge( $relations, $addons );
		}
		foreach ( $relations as $relation ) {
			if ( ! is_string( $relation['external_id'] ?? null ) || ! preg_match( '/^[A-Z0-9]{12}$/D', $relation['external_id'] ) ) {
				return new WP_Error( 'invalid_related_id', '関連商品のIDが不正なため、取得結果を反映しません。', array( 'status' => 502 ) );
			}
		}
		try {
			return self::normalize( $product, $summary, $relations );
		} catch ( Throwable $error ) {
			return new WP_Error( 'invalid_store_schema', 'カタログのデータ形式が変わったため、安全に取得できませんでした。', array( 'status' => 502 ) );
		}
	}

	/** @return array|WP_Error Follow the official continuation tokens until complete. */
	private static function addons( string $id ) {
		$key = 'PRODUCTADDONS_' . $id;
		$token = null;
		$seen = array();
		$relations = array();
		do {
			$body = array( 'ChannelId' => 'ProductAddOns_' . $id, 'ChannelKeyToBeUsedInResponse' => $key, 'ReturnFilters' => false );
			if ( null !== $token ) {
				$body['EncodedCT'] = $token;
			}
			$result = self::request( 'https://emerald.xboxservices.com/xboxcomfd/browse?locale=ja-JP', $body );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$channel = $result['channels'][ $key ] ?? null;
			if ( ! is_array( $channel ) || ! is_array( $channel['products'] ?? null ) ) {
				return new WP_Error( 'invalid_addons_response', 'DLC一覧を取得できないため、部分的な取得結果は反映しません。', array( 'status' => 502 ) );
			}
			foreach ( $channel['products'] as $related ) {
				$related_id = $related['productId'] ?? '';
				if ( ! is_string( $related_id ) || ! preg_match( '/^[A-Z0-9]{12}$/D', $related_id ) ) {
					return new WP_Error( 'invalid_addon_id', 'DLCの商品IDが不正です。', array( 'status' => 502 ) );
				}
				$relations[ $related_id ] = array( 'external_id' => $related_id, 'relation' => 'dlc', 'store' => 'microsoft' );
			}
			$token = $channel['encodedCT'] ?? null;
			if ( null !== $token && ( ! is_string( $token ) || isset( $seen[ $token ] ) || count( $seen ) >= 100 ) ) {
				return new WP_Error( 'incomplete_addons', 'DLC一覧のページ送りが完了しませんでした。部分データは反映しません。', array( 'status' => 502 ) );
			}
			if ( $token ) {
				$seen[ $token ] = true;
			}
		} while ( $token );
		if ( isset( $channel['totalItems'] ) && count( $relations ) < (int) $channel['totalItems'] ) {
			return new WP_Error( 'incomplete_addons', 'DLC一覧が全件そろっていません。部分データは反映しません。', array( 'status' => 502 ) );
		}
		return array_values( $relations );
	}

	/** Map actual catalogue data, leaving unknown values absent. */
	public static function normalize( array $product, array $summary, array $relations ): array {
		$localized = $product['LocalizedProperties'][0] ?? array();
		$meta = array( 'store_ids' => array( 'microsoft' => $product['ProductId'] ), 'developer' => $summary['developerName'] ?? $localized['DeveloperName'] ?? '', 'publisher' => $summary['publisherName'] ?? $localized['PublisherName'] ?? '', 'genres' => $summary['categories'] ?? $product['Properties']['Categories'] ?? array(), 'release_date' => '' );
		$released_at = $product['MarketProperties'][0]['OriginalReleaseDate'] ?? null;
		if ( is_string( $released_at ) && false !== strtotime( $released_at ) ) {
			$meta['release_date'] = wp_date( 'Y-m-d', strtotime( $released_at ), new DateTimeZone( 'Asia/Tokyo' ) );
		}
		$rating = array();
		foreach ( $product['MarketProperties'][0]['ContentRatings'] ?? array() as $entry ) {
			if ( in_array( $entry['RatingSystem'] ?? '', array( 'CERO', 'ESRB' ), true ) ) {
				$value = substr( $entry['RatingId'], strpos( $entry['RatingId'], ':' ) + 1 );
				$rating[ strtolower( $entry['RatingSystem'] ) ] = 'E10' === $value ? 'E10+' : $value;
			}
		}
		$meta['rating'] = $rating;
		$japanese = $summary['languagesSupported']['ja-JP'] ?? $summary['languagesSupported']['ja-jp'] ?? null;
		$meta['japanese'] = array();
		if ( is_array( $japanese ) ) {
			foreach ( array( 'audio' => 'isAudioSupported', 'subtitles' => 'areSubtitlesSupported', 'ui' => 'isInterfaceSupported' ) as $key => $source ) {
				if ( is_bool( $japanese[ $source ] ?? null ) ) {
					$meta['japanese'][ $key ] = $japanese[ $source ];
				}
			}
		}
		$flags = array_keys( $summary['capabilities'] ?? array() );
		$capabilities = array();
		foreach ( array( 'crossplay' => array( 'XblCrossPlatformMultiPlayer', 'XblCrossPlatformCoop' ), 'crosssave' => array( 'XboxPlayAnywhere', 'XPA' ), 'online' => array( 'XblOnlineMultiPlayer', 'XblOnlineCoop' ), 'hdr' => array( 'HDR10', 'DolbyVision', 'CapabilityHDR' ) ) as $key => $names ) {
			if ( array_intersect( $flags, $names ) ) {
				$capabilities[ $key ] = true;
			}
		}
		if ( in_array( '4k', array_map( 'strtolower', $flags ), true ) || in_array( '4KUHD', $flags, true ) || in_array( 'Capability4k', $flags, true ) ) {
			$capabilities['resolution'] = '3840×2160';
		}
		foreach ( array( '120fps' => 120, '60fps' => 60 ) as $flag => $fps ) {
			if ( in_array( $flag, array_map( 'strtolower', $flags ), true ) || in_array( 'Capability' . $flag, $flags, true ) ) {
				$capabilities['max_fps'] = $fps;
				break;
			}
		}
		$meta['capabilities'] = $capabilities;
		foreach ( $meta as $field => $value ) {
			$meta[ $field ] = Node_Library_Structured_Meta::sanitize( $field, $value );
		}
		$image = '';
		foreach ( $localized['Images'] ?? array() as $candidate ) {
			if ( in_array( $candidate['ImagePurpose'] ?? '', array( 'BoxArt', 'Poster' ), true ) && is_string( $candidate['Uri'] ?? null ) ) {
				$image = $candidate['Uri'];
				if ( str_starts_with( $image, '//' ) ) {
					$image = 'https:' . $image;
				}
				$image = esc_url_raw( $image, array( 'https' ) );
				break;
			}
		}
		$platforms = array();
		foreach ( $summary['availableOn'] ?? array() as $platform ) {
			$map = array( 'XboxOne' => array( 'console', 'xbox-one' ), 'XboxSeriesX' => array( 'console', 'xbox-series' ), 'PC' => array( 'pc', 'windows-pc' ), 'Windows' => array( 'pc', 'windows-pc' ) );
			if ( isset( $map[ $platform ] ) ) {
				$platforms[] = array( 'category' => $map[ $platform ][0], 'hardware' => $map[ $platform ][1] );
			}
		}
		return array( 'store' => 'microsoft', 'id' => $product['ProductId'], 'title' => sanitize_text_field( $summary['title'] ), 'summary' => sanitize_textarea_field( $summary['description'] ?? $localized['ProductDescription'] ?? '' ), 'url' => 'https://www.xbox.com/ja-JP/games/store/-/' . $product['ProductId'], 'image_url' => $image, 'price' => self::price( $product ), 'meta' => $meta, 'features' => $flags, 'editions' => array_values( array_filter( $summary['editions'] ?? array(), static fn( $id ) => is_string( $id ) && 1 === preg_match( '/^[A-Z0-9]{12}$/D', $id ) ) ), 'subscriptions' => $summary['includedWithPassesProductIds'] ?? array(), 'subscription_metadata' => $summary['passMetadataByPassProductId'] ?? array(), 'platforms' => $platforms, 'relations' => $relations, 'fetched_at' => gmdate( 'c' ), 'source' => 'microsoft_catalog' );
	}

	/** Poll the purchase catalogue without fetching slower editorial metadata or DLC. */
	public static function fetch_price( string $id ) {
		if ( ! preg_match( '/^[A-Z0-9]{12}$/D', $id ) ) {
			return new WP_Error( 'invalid_store_id', 'Microsoft StoreのプロダクトIDが無効です。', array( 'status' => 400 ) );
		}
		$raw = self::request( 'https://displaycatalog.mp.microsoft.com/v7.0/products/' . $id . '?market=JP&languages=ja-jp' );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$product = $raw['Product'] ?? null;
		if ( ! is_array( $product ) || $id !== ( $product['ProductId'] ?? '' ) || ! is_array( $product['DisplaySkuAvailabilities'] ?? null ) ) {
			return new WP_Error( 'invalid_store_response', '商品の価格カタログ形式が不正です。', array( 'status' => 502 ) );
		}
		return self::price( $product );
	}

	/** Only public, currently purchasable, non-trial, non-subscription offers count. */
	public static function price( array $product ): ?array {
		$offers = array();
		foreach ( $product['DisplaySkuAvailabilities'] ?? array() as $entry ) {
			if ( ! empty( $entry['Sku']['Properties']['IsTrial'] ) || ! empty( $entry['Sku']['RecurrencePolicy'] ) ) {
				continue;
			}
			foreach ( $entry['Availabilities'] ?? array() as $offer ) {
				$start = strtotime( $offer['Conditions']['StartDate'] ?? '' );
				$end = strtotime( $offer['Conditions']['EndDate'] ?? '' );
				if ( ! in_array( 'Purchase', $offer['Actions'] ?? array(), true ) || ! empty( $offer['RemediationRequired'] ) || ! empty( $offer['OrderManagementData']['PIFilter']['InclusionProperties'] ) || false === $start || false === $end || $start > time() || $end <= time() ) {
					continue;
				}
				$price = $offer['OrderManagementData']['Price'] ?? array();
				if ( 'JPY' !== ( $price['CurrencyCode'] ?? '' ) || ! is_numeric( $price['ListPrice'] ?? null ) || ! is_numeric( $price['MSRP'] ?? null ) || $price['ListPrice'] < 0 || $price['MSRP'] < 0 ) {
					continue;
				}
				$offers[] = array( 'price' => (float) $price['ListPrice'], 'list_price' => (float) $price['MSRP'], 'currency' => 'JPY', 'sale_end' => $price['ListPrice'] < $price['MSRP'] && (int) gmdate( 'Y', $end ) < 2100 ? gmdate( 'Y-m-d H:i:s', $end ) : null );
			}
		}
		usort( $offers, static fn( $a, $b ) => $a['price'] <=> $b['price'] );
		return $offers[0] ?? null;
	}
}
