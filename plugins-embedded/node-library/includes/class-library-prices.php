<<<<<<< HEAD
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

	/** @var array<int, array<string, array<string, array<string, mixed>|null>>> */
	private static array $current_cache = array();

	/**
	 * 一覧ページ等で対象 item_id 群の最新価格行を単一クエリでプリフェッチし N+1 を解消する（H-14③）。
	 *
	 * @param array<int, int> $item_ids 対象の node_library 投稿 ID 配列。
	 */
	public static function prime_current_cache( array $item_ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( ! $ids || '1' !== get_option( 'node_library_price_schema' ) ) {
			return;
		}
		$missing = array_values( array_filter( $ids, static fn( int $id ): bool => ! array_key_exists( $id, self::$current_cache ) ) );
		if ( ! $missing ) {
			return;
		}
		update_meta_cache( 'post', $missing );
		foreach ( $missing as $mid ) {
			self::$current_cache[ $mid ] = array();
		}
		global $wpdb;
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.item_id, h.store, h.external_id, h.price, h.list_price, h.currency, h.sale_end, h.recorded_at
				FROM {$table} h
				WHERE h.item_id IN ({$placeholders})
				AND NOT EXISTS (
					SELECT 1 FROM {$table} h2
					WHERE h2.item_id = h.item_id
					AND h2.store = h.store
					AND h2.external_id = h.external_id
					AND (
						h2.recorded_at > h.recorded_at
						OR (h2.recorded_at = h.recorded_at AND h2.id > h.id)
					)
				)",
				$missing
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$item_id = (int) ( $row['item_id'] ?? 0 );
			$store   = (string) ( $row['store'] ?? '' );
			$ext_id  = (string) ( $row['external_id'] ?? '' );
			if ( $item_id > 0 && '' !== $store && '' !== $ext_id ) {
				self::$current_cache[ $item_id ][ $store ][ $ext_id ] = array(
					'price'       => $row['price'],
					'list_price'  => $row['list_price'],
					'currency'    => $row['currency'],
					'sale_end'    => $row['sale_end'],
					'recorded_at' => $row['recorded_at'],
				);
			}
		}
	}

	public static function delete_item( int $id, WP_Post $post ): void {
		unset( self::$current_cache[ $id ] );
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
		if ( ! is_string( $external_id ) || '' === $external_id ) {
			return null;
		}
		if ( array_key_exists( $id, self::$current_cache ) ) {
			return self::$current_cache[ $id ][ $store ][ $external_id ] ?? null;
		}
		return self::latest( $id, $store, $external_id );
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

		unset( self::$current_cache[ $id ] );
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
<<<<<<< HEAD
					"SELECT MIN(price) FROM $table WHERE item_id=%d AND store=%s AND external_id=%s AND currency=%s AND price IS NOT NULL AND list_price IS NOT NULL AND price < list_price",
					$id,
					$store,
					$ext,
=======
					"SELECT MIN(price) FROM $table WHERE $where AND currency=%s AND price IS NOT NULL",
>>>>>>> fix/pr7-library-ui
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
=======
PD9waHAKLyoqCiAqIFByaWNlIG9ic2VydmF0aW9ucyBhbmQgcG9sbGluZyBmb3IgcmVnaXN0ZXJlZCBjYXRhbG9ndWUgSURzLgogKgogKiBAcGFja2FnZSBOb2RlX0xpYnJhcnkKICovCmlmICggISBkZWZpbmVkKCAnQUJTUEFUSCcgKSApIHsKCWV4aXQ7Cn0KCmZpbmFsIGNsYXNzIE5vZGVfTGlicmFyeV9QcmljZXMgewoJcHVibGljIGNvbnN0IENST05fSE9PSyAgICAgPSAnbm9kZV9saWJyYXJ5X3BvbGxfcHJpY2VzJzsKCXB1YmxpYyBjb25zdCBDUk9OX1NDSEVEVUxFID0gJ25vZGVfbGlicmFyeV8xNW1pbic7CgoJcHVibGljIHN0YXRpYyBmdW5jdGlvbiBpbml0KCk6IHZvaWQgewoJCWFkZF9hY3Rpb24oICdpbml0JywgYXJyYXkoIHNlbGY6OmNsYXNzLCAnaW5zdGFsbCcgKSwgNiApOwoJCWFkZF9hY3Rpb24oICdpbml0JywgYXJyYXkoIHNlbGY6OmNsYXNzLCAnc2NoZWR1bGUnICksIDIwICk7CgkJYWRkX2ZpbHRlciggJ2Nyb25fc2NoZWR1bGVzJywgYXJyYXkoIHNlbGY6OmNsYXNzLCAnaW50ZXJ2YWxzJyApICk7CgkJYWRkX2FjdGlvbiggc2VsZjo6Q1JPTl9IT09LLCBhcnJheSggc2VsZjo6Y2xhc3MsICdwb2xsJyApICk7CgkJYWRkX2FjdGlvbiggJ2JlZm9yZV9kZWxldGVfcG9zdCcsIGFycmF5KCBzZWxmOjpjbGFzcywgJ2RlbGV0ZV9pdGVtJyApLCAxMCwgMiApOwoJCWFkZF9hY3Rpb24oICdhZG1pbl9ub3RpY2VzJywgYXJyYXkoIHNlbGY6OmNsYXNzLCAnc3RhdHVzX25vdGljZScgKSApOwoJfQoKCXByaXZhdGUgc3RhdGljIGZ1bmN0aW9uIHRhYmxlKCk6IHN0cmluZyB7CgkJZ2xvYmFsICR3cGRiOwoJCXJldHVybiAkd3BkYi0+cHJlZml4IC4gJ25vZGVfbGlicmFyeV9wcmljZV9oaXN0b3J5JzsKCX0KCglwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIGluc3RhbGwoKTogdm9pZCB7CgkJaWYgKCAnMScgPT09IGdldF9vcHRpb24oICdub2RlX2xpYnJhcnlfcHJpY2Vfc2NoZW1hJyApICkgewoJCQlyZXR1cm47CgkJfQoJCWdsb2JhbCAkd3BkYjsKCQlyZXF1aXJlX29uY2UgQUJTUEFUSCAuICd3cC1hZG1pbi9pbmNsdWRlcy91cGdyYWRlLnBocCc7CgkJJHRhYmxlID0gc2VsZjo6dGFibGUoKTsKCQlkYkRlbHRhKCAiQ1JFQVRFIFRBQkxFICR0YWJsZSAoCgkJCWlkIGJpZ2ludCgyMCkgdW5zaWduZWQgTk9UIE5VTEwgQVVUT19JTkNSRU1FTlQsCgkJCWl0ZW1faWQgYmlnaW50KDIwKSB1bnNpZ25lZCBOT1QgTlVMTCwKCQkJc3RvcmUgdmFyY2hhcigyNCkgTk9UIE5VTEwsCgkJCWV4dGVybmFsX2lkIHZhcmNoYXIoMTkxKSBOT1QgTlVMTCwKCQkJcHJpY2UgZGVjaW1hbCgxOCw0KSBERUZBVUxUIE5VTEwsCgkJCWxpc3RfcHJpY2UgZGVjaW1hbCgxOCw0KSBERUZBVUxUIE5VTEwsCgkJCWN1cnJlbmN5IGNoYXIoMykgTk9UIE5VTEwgREVGQVVMVCAnJywKCQkJc2FsZV9lbmQgZGF0ZXRpbWUgREVGQVVMVCBOVUxMLAoJCQlyZWNvcmRlZF9hdCBkYXRldGltZSBOT1QgTlVMTCwKCQkJUFJJTUFSWSBLRVkgIChpZCksCgkJCUtFWSBpdGVtX29mZmVyX3RpbWUgKGl0ZW1faWQsc3RvcmUsZXh0ZXJuYWxfaWQscmVjb3JkZWRfYXQsaWQpCgkJKSB7JHdwZGItPmdldF9jaGFyc2V0X2NvbGxhdGUoKX07IiApOwoJCWlmICggJHRhYmxlID09PSAkd3BkYi0+Z2V0X3ZhciggJHdwZGItPnByZXBhcmUoICdTSE9XIFRBQkxFUyBMSUtFICVzJywgJHdwZGItPmVzY19saWtlKCAkdGFibGUgKSApICkgKSB7CgkJCXVwZGF0ZV9vcHRpb24oICdub2RlX2xpYnJhcnlfcHJpY2Vfc2NoZW1hJywgJzEnLCBmYWxzZSApOwoJCX0KCX0KCglwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIGludGVydmFscyggYXJyYXkgJHNjaGVkdWxlcyApOiBhcnJheSB7CgkJJHNjaGVkdWxlc1sgc2VsZjo6Q1JPTl9TQ0hFRFVMRSBdID0gYXJyYXkoCgkJCSdpbnRlcnZhbCcgPT4gMTUgKiBNSU5VVEVfSU5fU0VDT05EUywKCQkJJ2Rpc3BsYXknICA9PiAnTHVuYSBMaWJyYXJ5OiAxNeWIhuOBlOOBqCcsCgkJKTsKCQlyZXR1cm4gJHNjaGVkdWxlczsKCX0KCglwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIHNjaGVkdWxlKCk6IHZvaWQgewoJCSRldmVudCA9IGZ1bmN0aW9uX2V4aXN0cyggJ3dwX2dldF9zY2hlZHVsZWRfZXZlbnQnICkgPyB3cF9nZXRfc2NoZWR1bGVkX2V2ZW50KCBzZWxmOjpDUk9OX0hPT0sgKSA6IGZhbHNlOwoJCWlmICggJGV2ZW50ICYmIHNlbGY6OkNST05fU0NIRURVTEUgPT09ICRldmVudC0+c2NoZWR1bGUgKSB7CgkJCXJldHVybjsKCQl9CgkJaWYgKCAkZXZlbnQgKSB7CgkJCXdwX2NsZWFyX3NjaGVkdWxlZF9ob29rKCBzZWxmOjpDUk9OX0hPT0sgKTsKCQl9CgkJd3Bfc2NoZWR1bGVfZXZlbnQoIHRpbWUoKSArIDE1ICogTUlOVVRFX0lOX1NFQ09ORFMsIHNlbGY6OkNST05fU0NIRURVTEUsIHNlbGY6OkNST05fSE9PSyApOwoJfQoKCXB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gdW5zY2hlZHVsZSgpOiB2b2lkIHsKCQl3cF9jbGVhcl9zY2hlZHVsZWRfaG9vayggc2VsZjo6Q1JPTl9IT09LICk7Cgl9CgoJcHVibGljIHN0YXRpYyBmdW5jdGlvbiBkZWxldGVfaXRlbSggaW50ICRpZCwgV1BfUG9zdCAkcG9zdCApOiB2b2lkIHsKCQlpZiAoICdub2RlX2xpYnJhcnknID09PSAkcG9zdC0+cG9zdF90eXBlICYmICcxJyA9PT0gZ2V0X29wdGlvbiggJ25vZGVfbGlicmFyeV9wcmljZV9zY2hlbWEnICkgKSB7CgkJCWdsb2JhbCAkd3BkYjsKCQkJJHdwZGItPmRlbGV0ZSggc2VsZjo6dGFibGUoKSwgYXJyYXkoICdpdGVtX2lkJyA9PiAkaWQgKSwgYXJyYXkoICclZCcgKSApOwoJCX0KCX0KCgkvKiogUmV0dXJuIHRoZSBsYXRlc3QgcGVyc2lzdGVkIG9ic2VydmF0aW9uIGZvciB0aGUgY3VycmVudGx5IHJlZ2lzdGVyZWQgcHJvZHVjdC4gKi8KCXB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gY3VycmVudCggaW50ICRpZCwgc3RyaW5nICRzdG9yZSApOiA/YXJyYXkgewoJCSRpZHMgPSBnZXRfcG9zdF9tZXRhKCAkaWQsICdfbm9kZV9saWJyYXJ5X3N0b3JlX2lkcycsIHRydWUgKTsKCQkkZXh0ZXJuYWxfaWQgPSBpc19hcnJheSggJGlkcyApID8gKCAkaWRzWyAkc3RvcmUgXSA/PyAnJyApIDogJyc7CgkJaWYgKCAnMScgIT09IGdldF9vcHRpb24oICdub2RlX2xpYnJhcnlfcHJpY2Vfc2NoZW1hJyApICkgewoJCQlyZXR1cm4gbnVsbDsKCQl9CgkJcmV0dXJuIGlzX3N0cmluZyggJGV4dGVybmFsX2lkICkgJiYgJycgIT09ICRleHRlcm5hbF9pZCA/IHNlbGY6OmxhdGVzdCggJGlkLCAkc3RvcmUsICRleHRlcm5hbF9pZCApIDogbnVsbDsKCX0KCglwcml2YXRlIHN0YXRpYyBmdW5jdGlvbiBsYXRlc3QoIGludCAkaWQsIHN0cmluZyAkc3RvcmUsIHN0cmluZyAkZXh0ZXJuYWxfaWQgKTogP2FycmF5IHsKCQlnbG9iYWwgJHdwZGI7CgkJJHRhYmxlID0gc2VsZjo6dGFibGUoKTsKCQlyZXR1cm4gJHdwZGItPmdldF9yb3coICR3cGRiLT5wcmVwYXJlKAoJCQkiU0VMRUNUIHByaWNlLGxpc3RfcHJpY2UsY3VycmVuY3ksc2FsZV9lbmQscmVjb3JkZWRfYXQgRlJPTSAkdGFibGUgV0hFUkUgaXRlbV9pZD0lZCBBTkQgc3RvcmU9JXMgQU5EIGV4dGVybmFsX2lkPSVzIE9SREVSIEJZIHJlY29yZGVkX2F0IERFU0MsaWQgREVTQyBMSU1JVCAxIiwKCQkJJGlkLCAkc3RvcmUsICRleHRlcm5hbF9pZAoJCSksIEFSUkFZX0EgKTsKCX0KCglwcml2YXRlIHN0YXRpYyBmdW5jdGlvbiBvbl9zYWxlKCA/YXJyYXkgJHJvdyApOiBib29sIHsKCQlyZXR1cm4gaXNzZXQoICRyb3dbJ3ByaWNlJ10sICRyb3dbJ2xpc3RfcHJpY2UnXSApICYmIChmbG9hdCkgJHJvd1sncHJpY2UnXSA8IChmbG9hdCkgJHJvd1snbGlzdF9wcmljZSddOwoJfQoKCS8qKgoJICog44K744O844Or57WC5LqG5pel5pmC77yIc2FsZV9lbmTvvInntYzpgY7lvozjga7kvqHmoLzooYzjgpLlrprkvqHvvIhsaXN0X3ByaWNl77yJ44G45q2j6KaP5YyW44GZ44KL77yISC01OiA157WM6Lev57Wx5LiA77yJ44CCCgkgKgoJICogQHBhcmFtIGFycmF5PHN0cmluZywgbWl4ZWQ+fG51bGwgJHJvdyDkvqHmoLzjg6zjgrPjg7zjg4nooYzjgIIKCSAqIEBwYXJhbSBpbnR8bnVsbCAgICAgICAgICAgICAgICAgICRub3cg5Lu75oSP44Gu54++5ZyoIFVOSVgg44K/44Kk44Og44K544K/44Oz44OX44CCCgkgKiBAcmV0dXJuIGFycmF5PHN0cmluZywgbWl4ZWQ+fG51bGwKCSAqLwoJcHVibGljIHN0YXRpYyBmdW5jdGlvbiBlZmZlY3RpdmVfb2ZmZXIoID9hcnJheSAkcm93LCA/aW50ICRub3cgPSBudWxsICk6ID9hcnJheSB7CgkJaWYgKCBudWxsID09PSAkcm93ICkgewoJCQlyZXR1cm4gbnVsbDsKCQl9CgkJJG5vdyAgICAgID0gJG5vdyA/PyB0aW1lKCk7CgkJJHNhbGVfZW5kID0gJHJvd1snc2FsZV9lbmQnXSA/PyBudWxsOwoJCWlmICggaXNfc3RyaW5nKCAkc2FsZV9lbmQgKSAmJiAnJyAhPT0gJHNhbGVfZW5kICkgewoJCQkkZW5kX3RzID0gc3RydG90aW1lKCAkc2FsZV9lbmQgLiAnIFVUQycgKTsKCQkJaWYgKCBmYWxzZSA9PT0gJGVuZF90cyB8fCAkZW5kX3RzIDw9ICRub3cgKSB7CgkJCQlpZiAoIGlzX251bWVyaWMoICRyb3dbJ2xpc3RfcHJpY2UnXSA/PyBudWxsICkgKSB7CgkJCQkJJHJvd1sncHJpY2UnXSA9ICRyb3dbJ2xpc3RfcHJpY2UnXTsKCQkJCX0KCQkJCSRyb3dbJ3NhbGVfZW5kJ10gPSBudWxsOwoJCQl9CgkJfQoJCXJldHVybiAkcm93OwoJfQoKCS8qKiBAcmV0dXJuIGJvb2x8V1BfRXJyb3IgVHJ1ZSBmb3IgYSBuZXcgb2JzZXJ2YXRpb24sIGZhbHNlIGZvciBhbiB1bmNoYW5nZWQgb2ZmZXIuICovCglwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIHJlY29yZCggaW50ICRpZCwgc3RyaW5nICRzdG9yZSwgc3RyaW5nICRleHRlcm5hbF9pZCwgP2FycmF5ICRvZmZlciwgP2ludCAkbm93ID0gbnVsbCApIHsKCQlnbG9iYWwgJHdwZGI7CgkJJHByaWNlID0gYXJyYXkoICdwcmljZScgPT4gbnVsbCwgJ2xpc3RfcHJpY2UnID0+IG51bGwsICdjdXJyZW5jeScgPT4gJycsICdzYWxlX2VuZCcgPT4gbnVsbCApOwoJCWlmICggbnVsbCAhPT0gJG9mZmVyICkgewoJCQkkY3VycmVuY3kgPSAkb2ZmZXJbJ2N1cnJlbmN5J10gPz8gJyc7CgkJCWlmICggISBpc19zdHJpbmcoICRjdXJyZW5jeSApIHx8ICEgcHJlZ19tYXRjaCggJy9eW0EtWl17M30kL0QnLCAkY3VycmVuY3kgKSApIHsKCQkJCXJldHVybiBuZXcgV1BfRXJyb3IoICdpbnZhbGlkX3ByaWNlX2N1cnJlbmN5JywgJ+S+oeagvOOBrumAmuiyqOOCs+ODvOODieOBjOS4jeato+OBp+OBmeOAgicgKTsKCQkJfQoJCQlmb3JlYWNoICggYXJyYXkoICdwcmljZScsICdsaXN0X3ByaWNlJyApIGFzICRrZXkgKSB7CgkJCQlpZiAoICEgaXNfbnVtZXJpYyggJG9mZmVyWyAka2V5IF0gPz8gbnVsbCApIHx8ICEgaXNfZmluaXRlKCAoZmxvYXQpICRvZmZlclsgJGtleSBdICkgfHwgJG9mZmVyWyAka2V5IF0gPCAwIHx8ICRvZmZlclsgJGtleSBdID49IDFlMTQgKSB7CgkJCQkJcmV0dXJuIG5ldyBXUF9FcnJvciggJ2ludmFsaWRfcHJpY2VfYW1vdW50JywgJ+S+oeagvOOBjOS4jeato+OBp+OBmeOAgicgKTsKCQkJCX0KCQkJCSRwcmljZVsgJGtleSBdID0gbnVtYmVyX2Zvcm1hdCggKGZsb2F0KSAkb2ZmZXJbICRrZXkgXSwgNCwgJy4nLCAnJyApOwoJCQl9CgkJCSRwcmljZVsnY3VycmVuY3knXSA9ICRjdXJyZW5jeTsKCQkJJHNhbGVfZW5kID0gJG9mZmVyWydzYWxlX2VuZCddID8/ICcnOwoJCQlpZiAoIG51bGwgIT09ICRzYWxlX2VuZCAmJiAhIGlzX3N0cmluZyggJHNhbGVfZW5kICkgKSB7CgkJCQlyZXR1cm4gbmV3IFdQX0Vycm9yKCAnaW52YWxpZF9zYWxlX2VuZCcsICfjgrvjg7zjg6vntYLkuobml6XmmYLjgYzkuI3mraPjgafjgZnjgIInICk7CgkJCX0KCQkJJGVuZCA9ICRzYWxlX2VuZCA/IHN0cnRvdGltZSggJHNhbGVfZW5kIC4gKCBwcmVnX21hdGNoKCAnL15cZHs0fS1cZHsyfS1cZHsyfSBcZHsyfTpcZHsyfTpcZHsyfSQvRCcsICRzYWxlX2VuZCApID8gJyBVVEMnIDogJycgKSApIDogZmFsc2U7CgkJCWlmICggJHNhbGVfZW5kICYmIGZhbHNlID09PSAkZW5kICkgewoJCQkJcmV0dXJuIG5ldyBXUF9FcnJvciggJ2ludmFsaWRfc2FsZV9lbmQnLCAn44K744O844Or57WC5LqG5pel5pmC44GM5LiN5q2j44Gn44GZ44CCJyApOwoJCQl9CgkJCSRwcmljZVsnc2FsZV9lbmQnXSA9ICRlbmQgJiYgc2VsZjo6b25fc2FsZSggJHByaWNlICkgPyBnbWRhdGUoICdZLW0tZCBIOmk6cycsICRlbmQgKSA6IG51bGw7CgkJfQoJCSRsYXRlc3QgPSBzZWxmOjpsYXRlc3QoICRpZCwgJHN0b3JlLCAkZXh0ZXJuYWxfaWQgKTsKCQkvLyDliJ3lm57jg53jg7zjg6rjg7PjgrDjgafkvqHmoLzlj5blvpfkuI3og73vvIhudWxs77yJ44Gu44Go44GN44GvIE5VTEwg6KGM44KS6KiY6Yyy44GX44Gq44GE77yIQS02IC8gTS004pGg77yJ44CCCgkJaWYgKCBudWxsID09PSAkb2ZmZXIgJiYgbnVsbCA9PT0gJGxhdGVzdCApIHsKCQkJcmV0dXJuIGZhbHNlOwoJCX0KCQlpZiAoIG51bGwgIT09ICRsYXRlc3QgJiYgJHByaWNlID09PSBhcnJheV9pbnRlcnNlY3Rfa2V5KCAkbGF0ZXN0LCAkcHJpY2UgKSApIHsKCQkJcmV0dXJuIGZhbHNlOwoJCX0KCQkkcmVzdWx0ID0gJHdwZGItPmluc2VydCggc2VsZjo6dGFibGUoKSwgYXJyYXlfbWVyZ2UoCgkJCWFycmF5KCAnaXRlbV9pZCcgPT4gJGlkLCAnc3RvcmUnID0+ICRzdG9yZSwgJ2V4dGVybmFsX2lkJyA9PiAkZXh0ZXJuYWxfaWQgKSwKCQkJJHByaWNlLAoJCQlhcnJheSggJ3JlY29yZGVkX2F0JyA9PiBnbWRhdGUoICdZLW0tZCBIOmk6cycsICRub3cgPz8gdGltZSgpICkgKQoJCSkgKTsKCQlpZiAoIGZhbHNlID09PSAkcmVzdWx0ICkgewoJCQlyZXR1cm4gbmV3IFdQX0Vycm9yKCAncHJpY2Vfd3JpdGVfZmFpbGVkJywgJ+S+oeagvOWxpeattOOBruS/neWtmOOBq+WkseaVl+OBl+OBvuOBl+OBn+OAgicgKTsKCQl9CgoJCW5vZGVfbGlicmFyeV9kZWxldGVfZGV0YWlsX2NhY2hlKCAkaWQgKTsKCQlyZXR1cm4gdHJ1ZTsKCX0KCgkvKiogQ3VycmVudCBvYnNlcnZhdGlvbiwgY3VycmVuY3ktc3BlY2lmaWMgbWluaW11bSwgYW5kIG9ic2VydmVkIHNhbGUgc3RhcnRzIHdpdGhpbiBvbmUgeWVhci4gKi8KCXB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gc3RhdHMoIGludCAkaWQsIHN0cmluZyAkc3RvcmUsID9pbnQgJG5vdyA9IG51bGwgKTogYXJyYXkgewoJCWdsb2JhbCAkd3BkYjsKCQkkbm93ICAgICA9ICRub3cgPz8gdGltZSgpOwoJCSRjdXJyZW50ID0gc2VsZjo6ZWZmZWN0aXZlX29mZmVyKCBzZWxmOjpjdXJyZW50KCAkaWQsICRzdG9yZSApLCAkbm93ICk7CgkJJHN0YXRzICAgPSBhcnJheSggJ2N1cnJlbnQnID0+ICRjdXJyZW50LCAnbG93ZXN0JyA9PiBudWxsLCAnc2FsZXNfbGFzdF95ZWFyJyA9PiAwLCAnc2FsZV9lbmQnID0+IG51bGwsICdkaXNjb3VudF9wZXJjZW50JyA9PiBudWxsICk7CgkJaWYgKCBudWxsID09PSAkY3VycmVudCApIHsKCQkJcmV0dXJuICRzdGF0czsKCQl9CgkJaWYgKCBzZWxmOjpvbl9zYWxlKCAkY3VycmVudCApICkgewoJCQkkZW5kID0gJGN1cnJlbnRbJ3NhbGVfZW5kJ107CgkJCWlmICggISAkZW5kIHx8IHN0cnRvdGltZSggJGVuZCAuICcgVVRDJyApID4gJG5vdyApIHsKCQkJCSRzdGF0c1snc2FsZV9lbmQnXSA9ICRlbmQ7CgkJCQkkc3RhdHNbJ2Rpc2NvdW50X3BlcmNlbnQnXSA9IChpbnQpIHJvdW5kKCAxMDAgKiAoIDEgLSAoZmxvYXQpICRjdXJyZW50WydwcmljZSddIC8gKGZsb2F0KSAkY3VycmVudFsnbGlzdF9wcmljZSddICkgKTsKCQkJfQoJCX0KCQkkaWRzID0gZ2V0X3Bvc3RfbWV0YSggJGlkLCAnX25vZGVfbGlicmFyeV9zdG9yZV9pZHMnLCB0cnVlICk7CgkJJGV4dCA9IChzdHJpbmcpICggJGlkc1sgJHN0b3JlIF0gPz8gJycgKTsKCQkkdGFibGUgPSBzZWxmOjp0YWJsZSgpOwoJCSRjdXJyZW5jeSA9ICRjdXJyZW50WydjdXJyZW5jeSddID86ICR3cGRiLT5nZXRfdmFyKAoJCQkkd3BkYi0+cHJlcGFyZSgKCQkJCSJTRUxFQ1QgY3VycmVuY3kgRlJPTSAkdGFibGUgV0hFUkUgaXRlbV9pZD0lZCBBTkQgc3RvcmU9JXMgQU5EIGV4dGVybmFsX2lkPSVzIEFORCBwcmljZSBJUyBOT1QgTlVMTCBPUkRFUiBCWSByZWNvcmRlZF9hdCBERVNDLGlkIERFU0MgTElNSVQgMSIsCgkJCQkkaWQsCgkJCQkkc3RvcmUsCgkJCQkkZXh0CgkJCSkKCQkpOwoJCWlmICggJGN1cnJlbmN5ICkgewoJCQkkc3RhdHNbJ2xvd2VzdCddID0gJHdwZGItPmdldF92YXIoCgkJCQkkd3BkYi0+cHJlcGFyZSgKCQkJCQkiU0VMRUNUIE1JTihwcmljZSkgRlJPTSAkdGFibGUgV0hFUkUgaXRlbV9pZD0lZCBBTkQgc3RvcmU9JXMgQU5EIGV4dGVybmFsX2lkPSVzIEFORCBjdXJyZW5jeT0lcyBBTkQgcHJpY2UgSVMgTk9UIE5VTEwgQU5EIGxpc3RfcHJpY2UgSVMgTk9UIE5VTEwgQU5EIHByaWNlIDwgbGlzdF9wcmljZSIsCgkJCQkJJGlkLAoJCQkJCSRzdG9yZSwKCQkJCQkkZXh0LAoJCQkJCSRjdXJyZW5jeQoJCQkJKQoJCQkpOwoJCX0KCQkkY3V0b2ZmID0gZ21kYXRlKCAnWS1tLWQgSDppOnMnLCAkbm93IC0gWUVBUl9JTl9TRUNPTkRTICk7CgkJJHByZXZpb3VzID0gJHdwZGItPmdldF9yb3coCgkJCSR3cGRiLT5wcmVwYXJlKAoJCQkJIlNFTEVDVCBwcmljZSxsaXN0X3ByaWNlLGN1cnJlbmN5LHNhbGVfZW5kIEZST00gJHRhYmxlIFdIRVJFIGl0ZW1faWQ9JWQgQU5EIHN0b3JlPSVzIEFORCBleHRlcm5hbF9pZD0lcyBBTkQgcmVjb3JkZWRfYXQ8JXMgT1JERVIgQlkgcmVjb3JkZWRfYXQgREVTQyxpZCBERVNDIExJTUlUIDEiLAoJCQkJJGlkLAoJCQkJJHN0b3JlLAoJCQkJJGV4dCwKCQkJCSRjdXRvZmYKCQkJKSwKCQkJQVJSQVlfQQoJCSk7CgkJJHJvd3MgPSAkd3BkYi0+Z2V0X3Jlc3VsdHMoCgkJCSR3cGRiLT5wcmVwYXJlKAoJCQkJIlNFTEVDVCBwcmljZSxsaXN0X3ByaWNlLGN1cnJlbmN5LHNhbGVfZW5kLHJlY29yZGVkX2F0IEZST00gJHRhYmxlIFdIRVJFIGl0ZW1faWQ9JWQgQU5EIHN0b3JlPSVzIEFORCBleHRlcm5hbF9pZD0lcyBBTkQgcmVjb3JkZWRfYXQ+PSVzIE9SREVSIEJZIHJlY29yZGVkX2F0LGlkIiwKCQkJCSRpZCwKCQkJCSRzdG9yZSwKCQkJCSRleHQsCgkJCQkkY3V0b2ZmCgkJCSksCgkJCUFSUkFZX0EKCQkpOwoJCWZvcmVhY2ggKCAkcm93cyBhcyAkcm93ICkgewoJCQlpZiAoIHNlbGY6Om9uX3NhbGUoICRyb3cgKSAmJiAoICEgc2VsZjo6b25fc2FsZSggJHByZXZpb3VzICkgfHwgJHJvd1snY3VycmVuY3knXSAhPT0gJHByZXZpb3VzWydjdXJyZW5jeSddIHx8ICggJHByZXZpb3VzWydzYWxlX2VuZCddICYmICRwcmV2aW91c1snc2FsZV9lbmQnXSA8ICRyb3dbJ3JlY29yZGVkX2F0J10gKSApICkgewoJCQkJKyskc3RhdHNbJ3NhbGVzX2xhc3RfeWVhciddOwoJCQl9CgkJCSRwcmV2aW91cyA9ICRyb3c7CgkJfQoJCXJldHVybiAkc3RhdHM7Cgl9CgoJLyoqIFBvbGwgaW4gYm91bmRlZCBiYXRjaGVzLiBBIGRhdGFiYXNlIGFkdmlzb3J5IGxvY2sgYWxzbyBjb3ZlcnMgb3ZlcmxhcHBpbmcgc2VydmVyIGNyb24gcnVucy4gKi8KCXB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gcG9sbCgpOiB2b2lkIHsKCQlnbG9iYWwgJHdwZGI7CgkJaWYgKCAnMScgIT09IGdldF9vcHRpb24oICdub2RlX2xpYnJhcnlfcHJpY2Vfc2NoZW1hJyApICkgewoJCQlyZXR1cm47CgkJfQoJCSRsb2NrID0gJ25vZGVfbGlicmFyeV9wcmljZV8nIC4gbWQ1KCBEQl9OQU1FIC4gJzonIC4gJHdwZGItPnByZWZpeCApOwoJCWlmICggMSAhPT0gKGludCkgJHdwZGItPmdldF92YXIoICR3cGRiLT5wcmVwYXJlKCAnU0VMRUNUIEdFVF9MT0NLKCVzLDApJywgJGxvY2sgKSApICkgewoJCQlyZXR1cm47CgkJfQoJCXRyeSB7CgkJCXNlbGY6OnBvbGxfYmF0Y2goKTsKCQl9IGZpbmFsbHkgewoJCQkkd3BkYi0+Z2V0X3ZhciggJHdwZGItPnByZXBhcmUoICdTRUxFQ1QgUkVMRUFTRV9MT0NLKCVzKScsICRsb2NrICkgKTsKCQl9Cgl9CgoJcHJpdmF0ZSBzdGF0aWMgZnVuY3Rpb24gcG9sbF9iYXRjaCgpOiB2b2lkIHsKCQlnbG9iYWwgJHdwZGI7CgkJJG5vdyA9IHRpbWUoKTsKCQkkY3Vyc29yID0gKGludCkgZ2V0X29wdGlvbiggJ25vZGVfbGlicmFyeV9wcmljZV9wb2xsX2N1cnNvcicsIDAgKTsKCQkkc3RhdHVzID0gKGFycmF5KSBnZXRfb3B0aW9uKCAnbm9kZV9saWJyYXJ5X3ByaWNlX3N0b3JlX3N0YXR1cycsIGFycmF5KCkgKTsKCQkkaWRzID0gJHdwZGItPmdldF9jb2woICR3cGRiLT5wcmVwYXJlKAoJCQkiU0VMRUNUIHAuSUQgRlJPTSB7JHdwZGItPnBvc3RzfSBwIFdIRVJFIHAuSUQ+JWQgQU5EIHAucG9zdF90eXBlPSdub2RlX2xpYnJhcnknIEFORCBwLnBvc3Rfc3RhdHVzPSdwdWJsaXNoJwoJCQlBTkQgRVhJU1RTIChTRUxFQ1QgMSBGUk9NIHskd3BkYi0+cG9zdG1ldGF9IG0gV0hFUkUgbS5wb3N0X2lkPXAuSUQgQU5EIG0ubWV0YV9rZXk9J19ub2RlX2xpYnJhcnlfc3RvcmVfaWRzJykKCQkJT1JERVIgQlkgcC5JRCBMSU1JVCAxMDAiLAoJCQkkY3Vyc29yCgkJKSApOwoJCSRyZXF1ZXN0X2NvdW50ID0gMDsKCQkkbGFzdF9pZCA9ICRjdXJzb3I7CgkJJGRlYWRsaW5lID0gbWljcm90aW1lKCB0cnVlICkgKyAyMDsKCQl1cGRhdGVfbWV0YV9jYWNoZSggJ3Bvc3QnLCBhcnJheV9tYXAoICdpbnR2YWwnLCAkaWRzICkgKTsKCQlmb3JlYWNoICggJGlkcyBhcyAkaWQgKSB7CgkJCWlmICggJHJlcXVlc3RfY291bnQgPj0gMTAgfHwgbWljcm90aW1lKCB0cnVlICkgPj0gJGRlYWRsaW5lICkgewoJCQkJYnJlYWs7CgkJCX0KCQkJJGxhc3RfaWQgPSAoaW50KSAkaWQ7CgkJCSRzdG9yZV9pZHMgPSAoYXJyYXkpIGdldF9wb3N0X21ldGEoICRpZCwgJ19ub2RlX2xpYnJhcnlfc3RvcmVfaWRzJywgdHJ1ZSApOwoJCQkkY2hlY2tzID0gKGFycmF5KSBnZXRfcG9zdF9tZXRhKCAkaWQsICdfbm9kZV9saWJyYXJ5X3ByaWNlX3BvbGxfc3RhdGUnLCB0cnVlICk7CgkJCWZvcmVhY2ggKCBOb2RlX0xpYnJhcnlfU3RvcmU6OnByaWNlX3N0b3JlcygpIGFzICRzdG9yZSApIHsKCQkJCWlmICggJHJlcXVlc3RfY291bnQgPj0gMTAgfHwgbWljcm90aW1lKCB0cnVlICkgPj0gJGRlYWRsaW5lICkgewoJCQkJCWJyZWFrOwoJCQkJfQoJCQkJJGV4dGVybmFsX2lkID0gJHN0b3JlX2lkc1sgJHN0b3JlIF0gPz8gJyc7CgkJCQlpZiAoICEgaXNfc3RyaW5nKCAkZXh0ZXJuYWxfaWQgKSB8fCAnJyA9PT0gJGV4dGVybmFsX2lkIHx8IChpbnQpICggJHN0YXR1c1sgJHN0b3JlIF1bJ3JldHJ5X2FmdGVyJ10gPz8gMCApID4gJG5vdyApIHsKCQkJCQljb250aW51ZTsKCQkJCX0KCQkJCSRsYXN0X2NoZWNrID0gJGNoZWNrc1sgJHN0b3JlIF0gPz8gYXJyYXkoKTsKCQkJCWlmICggJGV4dGVybmFsX2lkID09PSAoICRsYXN0X2NoZWNrWydpZCddID8/ICcnICkgKSB7CgkJCQkJJGludGVydmFsID0gISBlbXB0eSggJGxhc3RfY2hlY2tbJ3NhbGUnXSApIHx8IHNlbGY6OmlzX2ZlYXR1cmVkKCAoaW50KSAkaWQgKSA/IDIgKiBIT1VSX0lOX1NFQ09ORFMgOiBEQVlfSU5fU0VDT05EUzsKCQkJCQlpZiAoICRub3cgLSAoaW50KSAoICRsYXN0X2NoZWNrWydjaGVja2VkJ10gPz8gMCApIDwgJGludGVydmFsICkgewoJCQkJCQljb250aW51ZTsKCQkJCQl9CgkJCQl9CgkJCQkrKyRyZXF1ZXN0X2NvdW50OwoJCQkJJG9mZmVyID0gTm9kZV9MaWJyYXJ5X1N0b3JlOjpmZXRjaF9wcmljZSggJHN0b3JlLCAkZXh0ZXJuYWxfaWQgKTsKCQkJCS8vIEFuIGVkaXRvciBjYW4gY2hhbmdlIHRoZSByZWdpc3RlcmVkIElEIHdoaWxlIGEgcmVxdWVzdCBpcyBpbiBmbGlnaHQuCgkJCQl3cF9jYWNoZV9kZWxldGUoIChpbnQpICRpZCwgJ3Bvc3RfbWV0YScgKTsKCQkJCSRmcmVzaF9pZHMgPSAoYXJyYXkpIGdldF9wb3N0X21ldGEoICRpZCwgJ19ub2RlX2xpYnJhcnlfc3RvcmVfaWRzJywgdHJ1ZSApOwoJCQkJaWYgKCAkZXh0ZXJuYWxfaWQgIT09ICggJGZyZXNoX2lkc1sgJHN0b3JlIF0gPz8gJycgKSB8fCAncHVibGlzaCcgIT09IGdldF9wb3N0X3N0YXR1cyggJGlkICkgKSB7CgkJCQkJY29udGludWU7CgkJCQl9CgkJCQlpZiAoIGlzX3dwX2Vycm9yKCAkb2ZmZXIgKSApIHsKCQkJCQkkY29kZSA9IChpbnQpICggJG9mZmVyLT5nZXRfZXJyb3JfZGF0YSgpWydzdGF0dXMnXSA/PyAwICk7CgkJCQkJaWYgKCBpbl9hcnJheSggJGNvZGUsIGFycmF5KCA0MDAsIDQwNCApLCB0cnVlICkgKSB7CgkJCQkJCSRjaGVja3NbICRzdG9yZSBdID0gYXJyYXkoICdpZCcgPT4gJGV4dGVybmFsX2lkLCAnY2hlY2tlZCcgPT4gJG5vdywgJ3NhbGUnID0+IGZhbHNlICk7CgkJCQkJCWNvbnRpbnVlOwoJCQkJCX0KCQkJCQkkZmFpbHVyZXMgPSBtaW4oIDgsIDEgKyAoaW50KSAoICRzdGF0dXNbICRzdG9yZSBdWydmYWlsdXJlcyddID8/IDAgKSApOwoJCQkJCSRzdGF0dXNbICRzdG9yZSBdWydmYWlsdXJlcyddID0gJGZhaWx1cmVzOwoJCQkJCSRzdGF0dXNbICRzdG9yZSBdWydyZXRyeV9hZnRlciddID0gJG5vdyArIG1pbiggNiAqIEhPVVJfSU5fU0VDT05EUywgKCAyICoqICRmYWlsdXJlcyApICogTUlOVVRFX0lOX1NFQ09ORFMgKTsKCQkJCQljb250aW51ZTsKCQkJCX0KCQkJCWlmICggaXNfd3BfZXJyb3IoIHNlbGY6OnJlY29yZCggKGludCkgJGlkLCAkc3RvcmUsICRleHRlcm5hbF9pZCwgJG9mZmVyLCAkbm93ICkgKSApIHsKCQkJCQljb250aW51ZTsKCQkJCX0KCQkJCSRjaGVja3NbICRzdG9yZSBdID0gYXJyYXkoICdpZCcgPT4gJGV4dGVybmFsX2lkLCAnY2hlY2tlZCcgPT4gJG5vdywgJ3NhbGUnID0+IHNlbGY6Om9uX3NhbGUoICRvZmZlciApICk7CgkJCQkkc3RhdHVzWyAkc3RvcmUgXSA9IGFycmF5KCAnbGFzdF9zdWNjZXNzJyA9PiAkbm93LCAnZmFpbHVyZXMnID0+IDAsICdyZXRyeV9hZnRlcicgPT4gMCApOwoJCQl9CgkJCWlmICggJGNoZWNrcyApIHsKCQkJCXVwZGF0ZV9wb3N0X21ldGEoICRpZCwgJ19ub2RlX2xpYnJhcnlfcHJpY2VfcG9sbF9zdGF0ZScsICRjaGVja3MgKTsKCQkJfQoJCX0KCQl1cGRhdGVfb3B0aW9uKCAnbm9kZV9saWJyYXJ5X3ByaWNlX3N0b3JlX3N0YXR1cycsICRzdGF0dXMsIGZhbHNlICk7CgkJJHdyYXBwZWQgPSAhICRpZHMgfHwgKCAkbGFzdF9pZCA9PT0gKGludCkgZW5kKCAkaWRzICkgJiYgY291bnQoICRpZHMgKSA8IDEwMCApOwoJCXVwZGF0ZV9vcHRpb24oICdub2RlX2xpYnJhcnlfcHJpY2VfcG9sbF9jdXJzb3InLCAkd3JhcHBlZCA/IDAgOiAkbGFzdF9pZCwgZmFsc2UgKTsKCX0KCglwcml2YXRlIHN0YXRpYyBmdW5jdGlvbiBpc19mZWF0dXJlZCggaW50ICRpZCApOiBib29sIHsKCQlnbG9iYWwgJHdwZGI7CgkJcmV0dXJuIChib29sKSAkd3BkYi0+Z2V0X3ZhciggJHdwZGItPnByZXBhcmUoCgkJCSJTRUxFQ1QgMSBGUk9NIHskd3BkYi0+cG9zdG1ldGF9IG0gSU5ORVIgSk9JTiB7JHdwZGItPnBvc3RzfSBwIE9OIHAuSUQ9bS5wb3N0X2lkCgkJCVdIRVJFIG0ubWV0YV9rZXkgSU4gKCdfbm9kZV9saWJyYXJ5X2NhcmRfcmVmZXJlbmNlJywgJ19ub2RlX2xpbmtlZF9saWJyYXJ5X2lkJywgJ19ub2RlX2xpbmtlZF9saWJyYXJ5X3NlY29uZF9pZCcpIEFORCBtLm1ldGFfdmFsdWU9JXMgQU5EIHAucG9zdF90eXBlPSdwb3N0JyBBTkQgcC5wb3N0X3N0YXR1cz0ncHVibGlzaCcgTElNSVQgMSIsCgkJCShzdHJpbmcpICRpZAoJCSkgKTsKCX0KCglwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIHN0YXR1c19ub3RpY2UoKTogdm9pZCB7CgkJJHNjcmVlbiA9IGdldF9jdXJyZW50X3NjcmVlbigpOwoJCWlmICggISAkc2NyZWVuIHx8ICdlZGl0LW5vZGVfbGlicmFyeScgIT09ICRzY3JlZW4tPmlkIHx8ICEgY3VycmVudF91c2VyX2NhbiggJ2VkaXRfcG9zdHMnICkgKSB7CgkJCXJldHVybjsKCQl9CgkJJHN0YXR1c2VzID0gKGFycmF5KSBnZXRfb3B0aW9uKCAnbm9kZV9saWJyYXJ5X3ByaWNlX3N0b3JlX3N0YXR1cycsIGFycmF5KCkgKTsKCQllY2hvICc8ZGl2IGNsYXNzPSJub3RpY2Ugbm90aWNlLWluZm8iPjxwPicgLiBlc2NfaHRtbF9fKCAn44K544OI44Ki5L6h5qC844Gu5pyA57WC5Y+W5b6X5oiQ5Yqf5pmC5Yi7JywgJ25vZGUtbGlicmFyeScgKSAuICc8L3A+PHVsPic7CgkJZm9yZWFjaCAoIE5vZGVfTGlicmFyeV9TdG9yZTo6cHJpY2Vfc3RvcmVzKCkgYXMgJHN0b3JlICkgewoJCQkkc3RhdHVzID0gJHN0YXR1c2VzWyAkc3RvcmUgXSA/PyBhcnJheSgpOwoJCQkkbGFzdCA9IGVtcHR5KCAkc3RhdHVzWydsYXN0X3N1Y2Nlc3MnXSApID8gJ+acquWPluW+lycgOiB3cF9kYXRlKCAnWS1tLWQgSDppJywgJHN0YXR1c1snbGFzdF9zdWNjZXNzJ10gKTsKCQkJJHJldHJ5ID0gKGludCkgKCAkc3RhdHVzWydyZXRyeV9hZnRlciddID8/IDAgKTsKCQkJZWNobyAnPGxpPicgLiBlc2NfaHRtbCggJHN0b3JlIC4gJyDigJQgJyAuICRsYXN0IC4gKCAkcmV0cnkgPiB0aW1lKCkgPyAn77yI5YaN6ZaL5LqI5a6aICcgLiB3cF9kYXRlKCAnWS1tLWQgSDppJywgJHJldHJ5ICkgLiAn77yJJyA6ICcnICkgKSAuICc8L2xpPic7CgkJfQoJCWVjaG8gJzwvdWw+PC9kaXY+JzsKCX0KfQo=
>>>>>>> fix/pr8-permissions-and-exposure
