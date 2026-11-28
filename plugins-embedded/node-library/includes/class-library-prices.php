<?php
/**
 * Price observations and polling for registered catalogue IDs.
 *
 * @package Node_Library
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Library_Prices {
	public const CRON_HOOK     = 'node_library_poll_prices';
	public const CRON_SCHEDULE = 'node_library_15min';

	public static function init(): void {
		add_action( 'init', array( self::class, 'install' ), 6 );
		add_action( 'init', array( self::class, 'schedule' ), 20 );
		add_filter( 'cron_schedules', array( self::class, 'intervals' ) );
		add_action( self::CRON_HOOK, array( self::class, 'poll' ) );
		add_action( 'before_delete_post', array( self::class, 'delete_item' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'status_notice' ) );
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'node_library_price_history';
	}

	public static function install(): void {
		if ( '1' === get_option( 'node_library_price_schema' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			item_id bigint(20) unsigned NOT NULL,
			store varchar(24) NOT NULL,
			external_id varchar(191) NOT NULL,
			price decimal(18,4) DEFAULT NULL,
			list_price decimal(18,4) DEFAULT NULL,
			currency char(3) NOT NULL DEFAULT '',
			sale_end datetime DEFAULT NULL,
			recorded_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY item_offer_time (item_id,store,external_id,recorded_at,id)
		) {$wpdb->get_charset_collate()};" );
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			update_option( 'node_library_price_schema', '1', false );
		}
	}

	public static function intervals( array $schedules ): array {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Luna Library: 15分ごと',
		);
		return $schedules;
	}

	public static function schedule(): void {
		$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::CRON_HOOK ) : false;
		if ( $event && self::CRON_SCHEDULE === $event->schedule ) {
			return;
		}
		if ( $event ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function delete_item( int $id, WP_Post $post ): void {
		if ( 'node_library' === $post->post_type && '1' === get_option( 'node_library_price_schema' ) ) {
			global $wpdb;
			$wpdb->delete( self::table(), array( 'item_id' => $id ), array( '%d' ) );
		}
	}

	/** Return the latest persisted observation for the currently registered product. */
	public static function current( int $id, string $store ): ?array {
		$ids = get_post_meta( $id, '_node_library_store_ids', true );
		$external_id = is_array( $ids ) ? ( $ids[ $store ] ?? '' ) : '';
		if ( '1' !== get_option( 'node_library_price_schema' ) ) {
			return null;
		}
		return is_string( $external_id ) && '' !== $external_id ? self::latest( $id, $store, $external_id ) : null;
	}

	private static function latest( int $id, string $store, string $external_id ): ?array {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT price,list_price,currency,sale_end,recorded_at FROM $table WHERE item_id=%d AND store=%s AND external_id=%s ORDER BY recorded_at DESC,id DESC LIMIT 1",
			$id, $store, $external_id
		), ARRAY_A );
	}

	private static function on_sale( ?array $row ): bool {
		return isset( $row['price'], $row['list_price'] ) && (float) $row['price'] < (float) $row['list_price'];
	}

	/**
	 * セール終了日時（sale_end）経過後の価格行を定価（list_price）へ正規化する（H-5: 5経路統一）。
	 *
	 * @param array<string, mixed>|null $row 価格レコード行。
	 * @param int|null                  $now 任意の現在 UNIX タイムスタンプ。
	 * @return array<string, mixed>|null
	 */
	public static function effective_offer( ?array $row, ?int $now = null ): ?array {
		if ( null === $row ) {
			return null;
		}
		$now      = $now ?? time();
		$sale_end = $row['sale_end'] ?? null;
		if ( is_string( $sale_end ) && '' !== $sale_end ) {
			$end_ts = strtotime( $sale_end . ' UTC' );
			if ( false === $end_ts || $end_ts <= $now ) {
				if ( is_numeric( $row['list_price'] ?? null ) ) {
					$row['price'] = $row['list_price'];
				}
				$row['sale_end'] = null;
			}
		}
		return $row;
	}

	/** @return bool|WP_Error True for a new observation, false for an unchanged offer. */
	public static function record( int $id, string $store, string $external_id, ?array $offer, ?int $now = null ) {
		global $wpdb;
		$price = array( 'price' => null, 'list_price' => null, 'currency' => '', 'sale_end' => null );
		if ( null !== $offer ) {
			$currency = $offer['currency'] ?? '';
			if ( ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/D', $currency ) ) {
				return new WP_Error( 'invalid_price_currency', '価格の通貨コードが不正です。' );
			}
			foreach ( array( 'price', 'list_price' ) as $key ) {
				if ( ! is_numeric( $offer[ $key ] ?? null ) || ! is_finite( (float) $offer[ $key ] ) || $offer[ $key ] < 0 || $offer[ $key ] >= 1e14 ) {
					return new WP_Error( 'invalid_price_amount', '価格が不正です。' );
				}
				$price[ $key ] = number_format( (float) $offer[ $key ], 4, '.', '' );
			}
			$price['currency'] = $currency;
			$sale_end = $offer['sale_end'] ?? '';
			if ( null !== $sale_end && ! is_string( $sale_end ) ) {
				return new WP_Error( 'invalid_sale_end', 'セール終了日時が不正です。' );
			}
			$end = $sale_end ? strtotime( $sale_end . ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $sale_end ) ? ' UTC' : '' ) ) : false;
			if ( $sale_end && false === $end ) {
				return new WP_Error( 'invalid_sale_end', 'セール終了日時が不正です。' );
			}
			$price['sale_end'] = $end && self::on_sale( $price ) ? gmdate( 'Y-m-d H:i:s', $end ) : null;
		}
		$latest = self::latest( $id, $store, $external_id );
		// 初回ポーリングで価格取得不能（null）のときは NULL 行を記録しない（A-6 / M-4①）。
		if ( null === $offer && null === $latest ) {
			return false;
		}
		if ( null !== $latest && $price === array_intersect_key( $latest, $price ) ) {
			return false;
		}
		$result = $wpdb->insert( self::table(), array_merge(
			array( 'item_id' => $id, 'store' => $store, 'external_id' => $external_id ),
			$price,
			array( 'recorded_at' => gmdate( 'Y-m-d H:i:s', $now ?? time() ) )
		) );
		if ( false === $result ) {
			return new WP_Error( 'price_write_failed', '価格履歴の保存に失敗しました。' );
		}

		node_library_delete_detail_cache( $id );
		return true;
	}

	/** Current observation, currency-specific minimum, and observed sale starts within one year. */
	public static function stats( int $id, string $store, ?int $now = null ): array {
		global $wpdb;
		$now     = $now ?? time();
		$current = self::effective_offer( self::current( $id, $store ), $now );
		$stats   = array( 'current' => $current, 'lowest' => null, 'sales_last_year' => 0, 'sale_end' => null, 'discount_percent' => null );
		if ( null === $current ) {
			return $stats;
		}
		if ( self::on_sale( $current ) ) {
			$end = $current['sale_end'];
			if ( ! $end || strtotime( $end . ' UTC' ) > $now ) {
				$stats['sale_end'] = $end;
				$stats['discount_percent'] = (int) round( 100 * ( 1 - (float) $current['price'] / (float) $current['list_price'] ) );
			}
		}
		$ids = get_post_meta( $id, '_node_library_store_ids', true );
		$ext = (string) ( $ids[ $store ] ?? '' );
		$table = self::table();
		$currency = $current['currency'] ?: $wpdb->get_var(
			$wpdb->prepare(
				"SELECT currency FROM $table WHERE item_id=%d AND store=%s AND external_id=%s AND price IS NOT NULL ORDER BY recorded_at DESC,id DESC LIMIT 1",
				$id,
				$store,
				$ext
			)
		);
		if ( $currency ) {
			$stats['lowest'] = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MIN(price) FROM $table WHERE item_id=%d AND store=%s AND external_id=%s AND currency=%s AND price IS NOT NULL",
					$id,
					$store,
					$ext,
					$currency
				)
			);
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', $now - YEAR_IN_SECONDS );
		$previous = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT price,list_price,currency,sale_end FROM $table WHERE item_id=%d AND store=%s AND external_id=%s AND recorded_at<%s ORDER BY recorded_at DESC,id DESC LIMIT 1",
				$id,
				$store,
				$ext,
				$cutoff
			),
			ARRAY_A
		);
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT price,list_price,currency,sale_end,recorded_at FROM $table WHERE item_id=%d AND store=%s AND external_id=%s AND recorded_at>=%s ORDER BY recorded_at,id",
				$id,
				$store,
				$ext,
				$cutoff
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			if ( self::on_sale( $row ) && ( ! self::on_sale( $previous ) || $row['currency'] !== $previous['currency'] || ( $previous['sale_end'] && $previous['sale_end'] < $row['recorded_at'] ) ) ) {
				++$stats['sales_last_year'];
			}
			$previous = $row;
		}
		return $stats;
	}

	/** Poll in bounded batches. A database advisory lock also covers overlapping server cron runs. */
	public static function poll(): void {
		global $wpdb;
		if ( '1' !== get_option( 'node_library_price_schema' ) ) {
			return;
		}
		$lock = 'node_library_price_' . md5( DB_NAME . ':' . $wpdb->prefix );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
			return;
		}
		try {
			self::poll_batch();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function poll_batch(): void {
		global $wpdb;
		$now = time();
		$cursor = (int) get_option( 'node_library_price_poll_cursor', 0 );
		$status = (array) get_option( 'node_library_price_store_status', array() );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p WHERE p.ID>%d AND p.post_type='node_library' AND p.post_status='publish'
			AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_node_library_store_ids')
			ORDER BY p.ID LIMIT 100",
			$cursor
		) );
		$request_count = 0;
		$last_id = $cursor;
		$deadline = microtime( true ) + 20;
		update_meta_cache( 'post', array_map( 'intval', $ids ) );
		foreach ( $ids as $id ) {
			if ( $request_count >= 10 || microtime( true ) >= $deadline ) {
				break;
			}
			$last_id = (int) $id;
			$store_ids = (array) get_post_meta( $id, '_node_library_store_ids', true );
			$checks = (array) get_post_meta( $id, '_node_library_price_poll_state', true );
			foreach ( Node_Library_Store::price_stores() as $store ) {
				if ( $request_count >= 10 || microtime( true ) >= $deadline ) {
					break;
				}
				$external_id = $store_ids[ $store ] ?? '';
				if ( ! is_string( $external_id ) || '' === $external_id || (int) ( $status[ $store ]['retry_after'] ?? 0 ) > $now ) {
					continue;
				}
				$last_check = $checks[ $store ] ?? array();
				if ( $external_id === ( $last_check['id'] ?? '' ) ) {
					$interval = ! empty( $last_check['sale'] ) || self::is_featured( (int) $id ) ? 2 * HOUR_IN_SECONDS : DAY_IN_SECONDS;
					if ( $now - (int) ( $last_check['checked'] ?? 0 ) < $interval ) {
						continue;
					}
				}
				++$request_count;
				$offer = Node_Library_Store::fetch_price( $store, $external_id );
				// An editor can change the registered ID while a request is in flight.
				wp_cache_delete( (int) $id, 'post_meta' );
				$fresh_ids = (array) get_post_meta( $id, '_node_library_store_ids', true );
				if ( $external_id !== ( $fresh_ids[ $store ] ?? '' ) || 'publish' !== get_post_status( $id ) ) {
					continue;
				}
				if ( is_wp_error( $offer ) ) {
					$code = (int) ( $offer->get_error_data()['status'] ?? 0 );
					if ( in_array( $code, array( 400, 404 ), true ) ) {
						$checks[ $store ] = array( 'id' => $external_id, 'checked' => $now, 'sale' => false );
						continue;
					}
					$failures = min( 8, 1 + (int) ( $status[ $store ]['failures'] ?? 0 ) );
					$status[ $store ]['failures'] = $failures;
					$status[ $store ]['retry_after'] = $now + min( 6 * HOUR_IN_SECONDS, ( 2 ** $failures ) * MINUTE_IN_SECONDS );
					continue;
				}
				if ( is_wp_error( self::record( (int) $id, $store, $external_id, $offer, $now ) ) ) {
					continue;
				}
				$checks[ $store ] = array( 'id' => $external_id, 'checked' => $now, 'sale' => self::on_sale( $offer ) );
				$status[ $store ] = array( 'last_success' => $now, 'failures' => 0, 'retry_after' => 0 );
			}
			if ( $checks ) {
				update_post_meta( $id, '_node_library_price_poll_state', $checks );
			}
		}
		update_option( 'node_library_price_store_status', $status, false );
		$wrapped = ! $ids || ( $last_id === (int) end( $ids ) && count( $ids ) < 100 );
		update_option( 'node_library_price_poll_cursor', $wrapped ? 0 : $last_id, false );
	}

	private static function is_featured( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id
			WHERE m.meta_key IN ('_node_library_card_reference', '_node_linked_library_id', '_node_linked_library_second_id') AND m.meta_value=%s AND p.post_type='post' AND p.post_status='publish' LIMIT 1",
			(string) $id
		) );
	}

	public static function status_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-node_library' !== $screen->id || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$statuses = (array) get_option( 'node_library_price_store_status', array() );
		echo '<div class="notice notice-info"><p>' . esc_html__( 'ストア価格の最終取得成功時刻', 'node-library' ) . '</p><ul>';
		foreach ( Node_Library_Store::price_stores() as $store ) {
			$status = $statuses[ $store ] ?? array();
			$last = empty( $status['last_success'] ) ? '未取得' : wp_date( 'Y-m-d H:i', $status['last_success'] );
			$retry = (int) ( $status['retry_after'] ?? 0 );
			echo '<li>' . esc_html( $store . ' — ' . $last . ( $retry > time() ? '（再開予定 ' . wp_date( 'Y-m-d H:i', $retry ) . '）' : '' ) ) . '</li>';
		}
		echo '</ul></div>';
	}
}
