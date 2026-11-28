<?php
/**
 * Subscription membership history tracking, admin change list, and single-page display.
 *
 * @package Node_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Library_Subscriptions {
	/** @var bool */
	private static $initialized = false;

	/** Register subscription tracking hooks once. */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'init', array( self::class, 'install' ), 6 );
		add_action( 'before_delete_post', array( self::class, 'delete_item' ), 10, 2 );
		add_action( 'save_post_node_library', array( self::class, 'sync_from_saved_meta' ), 20 );
		add_action( 'admin_menu', array( self::class, 'register_admin_page' ) );
		add_action( 'node_library_single_after_specs', array( self::class, 'render_single_history' ), 15, 1 );
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'node_library_subscription_history';
	}

	/** Create the subscription history table following the L3 schema pattern. */
	public static function install(): void {
		if ( '1' === get_option( 'node_library_subscription_schema' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				item_id bigint(20) unsigned NOT NULL,
				service varchar(32) NOT NULL,
				joined_at datetime NOT NULL,
				leaving_at datetime DEFAULT NULL,
				left_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY item_service_open (item_id,service,left_at),
				KEY recent_changes (left_at,joined_at,id)
			) {$wpdb->get_charset_collate()};"
		);
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			update_option( 'node_library_subscription_schema', '1', false );
		}
	}

	/** Remove subscription history rows when a library item is permanently deleted. */
	public static function delete_item( int $id, WP_Post $post ): void {
		if ( 'node_library' === $post->post_type && '1' === get_option( 'node_library_subscription_schema' ) ) {
			global $wpdb;
			$wpdb->delete( self::table(), array( 'item_id' => $id ), array( '%d' ) );
		}
	}

	/**
	 * Record an observed membership state for one item and one service.
	 * Guarantees at most one open row (left_at IS NULL) per (item_id, service) and is idempotent.
	 *
	 * @param int         $item_id    Library item post ID.
	 * @param string      $service    Canonical service key from Node_Library_Structured_Meta::subscriptions().
	 * @param bool        $present    Whether the item is currently in the subscription catalogue.
	 * @param int|null    $now        Optional UNIX timestamp override.
	 * @param string|null $leaving_at Optional scheduled leaving datetime (UTC Y-m-d H:i:s or parseable string).
	 * @return string One of 'joined', 'left', 'updated_leaving', 'unchanged', 'invalid'.
	 */
	public static function observe( int $item_id, string $service, bool $present, ?int $now = null, ?string $leaving_at = null, bool $update_leaving = true ): string {
		$services = class_exists( 'Node_Library_Structured_Meta' ) ? Node_Library_Structured_Meta::subscriptions() : array();
		if ( $item_id <= 0 || ! isset( $services[ $service ] ) ) {
			return 'invalid';
		}

		self::install();
		if ( '1' !== get_option( 'node_library_subscription_schema' ) ) {
			return 'invalid';
		}

		global $wpdb;
		$lock     = 'node_library_sub_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $item_id . ':' . $service );
		$got_lock = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) );

		try {
			$table         = self::table();
			$now_ts        = $now ?? time();
			$now_utc       = gmdate( 'Y-m-d H:i:s', $now_ts );
			$clean_leaving = self::normalize_datetime( $leaving_at );

			$open_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, joined_at, leaving_at FROM {$table} WHERE item_id = %d AND service = %s AND left_at IS NULL ORDER BY id DESC LIMIT 1",
					$item_id,
					$service
				),
				ARRAY_A
			);

			if ( $present ) {
				if ( null === $open_row ) {
					$inserted = $wpdb->insert(
						$table,
						array(
							'item_id'    => $item_id,
							'service'    => $service,
							'joined_at'  => $now_utc,
							'leaving_at' => $clean_leaving,
							'left_at'    => null,
						)
					);
					if ( false === $inserted ) {
						return 'invalid';
					}
					node_library_delete_detail_cache( $item_id );
					return 'joined';
				}

				if ( $update_leaving ) {
					$current_leaving = is_string( $open_row['leaving_at'] ?? null ) && '' !== $open_row['leaving_at'] ? $open_row['leaving_at'] : null;
					if ( $clean_leaving !== $current_leaving ) {
						$updated = $wpdb->update(
							$table,
							array( 'leaving_at' => $clean_leaving ),
							array( 'id' => (int) $open_row['id'] ),
							array( null === $clean_leaving ? null : '%s' ),
							array( '%d' )
						);
						if ( $updated ) {
							node_library_delete_detail_cache( $item_id );
						}
						return 'updated_leaving';
					}
				}

				return 'unchanged';
			}

			if ( null === $open_row ) {
				return 'unchanged';
			}

			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET left_at = %s WHERE item_id = %d AND service = %s AND left_at IS NULL",
					$now_utc,
					$item_id,
					$service
				)
			);

			if ( $updated ) {
				node_library_delete_detail_cache( $item_id );
			}
			return 'left';
		} finally {
			if ( $got_lock ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	/**
	 * Observe membership changes from a persisted store catalogue snapshot.
	 * Currently only Microsoft Store exposes pass membership (Game Pass) in normalize().
	 *
	 * @param int                  $item_id Library item post ID.
	 * @param array<string, mixed> $catalog Normalized catalogue array.
	 * @param int|null             $now     Optional UNIX timestamp override.
	 * @return array<string, string> Service => observe() result.
	 */
	public static function observe_from_catalog( int $item_id, array $catalog, ?int $now = null ): array {
		if ( 'microsoft' !== ( $catalog['store'] ?? '' ) ) {
			return array();
		}

		$pass_ids  = is_array( $catalog['subscriptions'] ?? null ) ? array_filter( $catalog['subscriptions'], 'is_string' ) : array();
		$pass_meta = is_array( $catalog['subscription_metadata'] ?? null ) ? $catalog['subscription_metadata'] : array();

		$in_gamepass = ! empty( $pass_ids );
		$leaving_at  = $in_gamepass ? self::extract_microsoft_leaving_date( $pass_meta ) : null;

		$results             = array();
		$results['gamepass'] = self::observe( $item_id, 'gamepass', $in_gamepass, $now, $leaving_at );

		// Keep L1 _node_library_subscriptions in sync for the observed service without touching other services.
		$stored = get_post_meta( $item_id, '_node_library_subscriptions', true );
		$stored = is_array( $stored ) ? $stored : array();
		if ( $in_gamepass ) {
			$stored['gamepass'] = true;
		} else {
			unset( $stored['gamepass'] );
		}
		if ( class_exists( 'Node_Library_Structured_Meta' ) ) {
			$stored = Node_Library_Structured_Meta::sanitize( 'subscriptions', $stored );
		}
		if ( $stored ) {
			$changed = update_post_meta( $item_id, '_node_library_subscriptions', wp_slash( $stored ) );
		} else {
			$changed = delete_post_meta( $item_id, '_node_library_subscriptions' );
		}

		if ( $changed ) {
			node_library_delete_detail_cache( $item_id );
		}

		return $results;
	}

	/**
	 * Whether a service key represents a time-limited distribution period (free giveaway).
	 */
	public static function is_giveaway_service( string $service ): bool {
		if ( class_exists( 'Node_Library_Structured_Meta' ) && method_exists( 'Node_Library_Structured_Meta', 'giveaway_subscriptions' ) ) {
			return in_array( $service, Node_Library_Structured_Meta::giveaway_subscriptions(), true );
		}
		return in_array( $service, array( 'psplus_freeplay', 'epic_free' ), true );
	}

	/**
	 * Determine whether a history row has ended (explicitly closed or giveaway leaving_at elapsed).
	 *
	 * @param array<string, mixed> $row History row.
	 * @param int|null             $now Optional UNIX timestamp override.
	 */
	public static function is_period_ended( array $row, ?int $now = null ): bool {
		if ( is_string( $row['left_at'] ?? null ) && '' !== $row['left_at'] ) {
			return true;
		}
		$service = (string) ( $row['service'] ?? '' );
		$leaving = $row['leaving_at'] ?? null;
		if ( self::is_giveaway_service( $service ) && is_string( $leaving ) && '' !== $leaving ) {
			$leaving_ts = strtotime( $leaving . ' UTC' );
			return false !== $leaving_ts && $leaving_ts <= ( $now ?? time() );
		}
		return false;
	}

	/**
	 * Fetch open subscription periods formatted as JST YYYY-MM-DD dates for the edit form.
	 *
	 * @return array<string, array{joined_date:string,leaving_date:string}>
	 */
	public static function get_open_periods( int $item_id ): array {
		if ( $item_id <= 0 || '1' !== get_option( 'node_library_subscription_schema' ) ) {
			return array();
		}
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT service, joined_at, leaving_at FROM {$table} WHERE item_id = %d AND left_at IS NULL ORDER BY id DESC",
				$item_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$periods = array();
		foreach ( $rows as $row ) {
			$service = (string) ( $row['service'] ?? '' );
			if ( '' === $service || isset( $periods[ $service ] ) ) {
				continue;
			}
			$periods[ $service ] = array(
				'joined_date'  => self::format_jst_input_date( (string) ( $row['joined_at'] ?? '' ) ),
				'leaving_date' => is_string( $row['leaving_at'] ?? null ) && '' !== $row['leaving_at']
					? self::format_jst_input_date( $row['leaving_at'] )
					: '',
			);
		}
		return $periods;
	}

	/**
	 * Dedicated manual period recording path for the admin meta box.
	 * Unlike sync_item_flags(), manual period entry allows backdated start dates and updates leaving_at.
	 *
	 * @param int         $item_id           Library item post ID.
	 * @param string      $service           Canonical service key.
	 * @param bool        $present           Whether the service checkbox is checked.
	 * @param string|null $joined_date       Optional JST Y-m-d or UTC datetime start.
	 * @param string|null $leaving_date      Optional JST Y-m-d or UTC datetime end.
	 * @param int|null    $now               Optional UNIX timestamp override.
	 * @param string|null $prev_leaving_date Previous JST Y-m-d rendered in the form to detect explicit clear.
	 * @return string One of 'joined', 'left', 'updated_period', 'updated_leaving', 'unchanged', 'invalid'.
	 */
	public static function record_manual_period(
		int $item_id,
		string $service,
		bool $present,
		?string $joined_date = null,
		?string $leaving_date = null,
		?int $now = null,
		?string $prev_leaving_date = null
	): string {
		$services = class_exists( 'Node_Library_Structured_Meta' ) ? Node_Library_Structured_Meta::subscriptions() : array();
		if ( $item_id <= 0 || ! isset( $services[ $service ] ) ) {
			return 'invalid';
		}

		self::install();
		if ( '1' !== get_option( 'node_library_subscription_schema' ) ) {
			return 'invalid';
		}

		$clean_joined  = self::normalize_manual_date( $joined_date, false );
		$clean_leaving = self::normalize_manual_date( $leaving_date, true );
		if ( false === $clean_joined || false === $clean_leaving ) {
			return 'invalid';
		}

		global $wpdb;
		$lock     = 'node_library_sub_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $item_id . ':' . $service );
		$got_lock = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) );

		try {
			$table    = self::table();
			$now_ts   = $now ?? time();
			$now_utc  = gmdate( 'Y-m-d H:i:s', $now_ts );
			$open_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, joined_at, leaving_at FROM {$table} WHERE item_id = %d AND service = %s AND left_at IS NULL ORDER BY id DESC LIMIT 1",
					$item_id,
					$service
				),
				ARRAY_A
			);

			if ( ! $present ) {
				if ( null === $open_row ) {
					return 'unchanged';
				}
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET left_at = %s WHERE item_id = %d AND service = %s AND left_at IS NULL",
						$now_utc,
						$item_id,
						$service
					)
				);
				if ( $updated ) {
					node_library_delete_detail_cache( $item_id );
				}
				return 'left';
			}

			$current_joined  = is_string( $open_row['joined_at'] ?? null ) && '' !== $open_row['joined_at'] ? $open_row['joined_at'] : null;
			$current_leaving = is_string( $open_row['leaving_at'] ?? null ) && '' !== $open_row['leaving_at'] ? $open_row['leaving_at'] : null;
			$target_joined   = is_string( $clean_joined ) ? $clean_joined : ( $current_joined ?? $now_utc );

			if ( is_string( $clean_leaving ) ) {
				$raw_leaving_trimmed = null !== $leaving_date ? trim( $leaving_date ) : '';
				if (
					null !== $current_leaving
					&& null !== $prev_leaving_date
					&& '' !== $prev_leaving_date
					&& self::format_jst_input_date( $current_leaving ) === $prev_leaving_date
					&& $raw_leaving_trimmed === $prev_leaving_date
				) {
					// Preserve exact UTC timestamp (e.g. Microsoft leavingDate) when the date input was not changed.
					$target_leaving = $current_leaving;
				} else {
					$target_leaving = $clean_leaving;
				}
			} elseif ( null !== $prev_leaving_date && '' !== trim( $prev_leaving_date ) ) {
				$target_leaving = null;
			} else {
				$target_leaving = $current_leaving;
			}

			if ( null !== $target_leaving && $target_joined > $target_leaving ) {
				return 'invalid';
			}

			if ( null === $open_row ) {
				$inserted = $wpdb->insert(
					$table,
					array(
						'item_id'    => $item_id,
						'service'    => $service,
						'joined_at'  => $target_joined,
						'leaving_at' => $target_leaving,
						'left_at'    => null,
					)
				);
				if ( false === $inserted ) {
					return 'invalid';
				}
				node_library_delete_detail_cache( $item_id );
				return 'joined';
			}

			$joined_changed  = $target_joined !== $current_joined;
			$leaving_changed = $target_leaving !== $current_leaving;
			if ( ! $joined_changed && ! $leaving_changed ) {
				return 'unchanged';
			}

			$updated = $wpdb->update(
				$table,
				array(
					'joined_at'  => $target_joined,
					'leaving_at' => $target_leaving,
				),
				array( 'id' => (int) $open_row['id'] ),
				array( '%s', null === $target_leaving ? null : '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				return 'invalid';
			}
			node_library_delete_detail_cache( $item_id );
			return $joined_changed ? 'updated_period' : 'updated_leaving';
		} finally {
			if ( $got_lock ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	/**
	 * Sync history when an editor saves _node_library_subscriptions in the meta box.
	 */
	public static function sync_from_saved_meta( int $post_id ): void {
		if ( 'node_library' !== get_post_type( $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['node_library_structured_nonce'] ) || ! is_string( $_POST['node_library_structured_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['node_library_structured_nonce'] ) ), 'node_library_save_structured' ) ) {
			return;
		}
		if ( ! isset( $_POST['node_library_structured'] ) || ! is_array( $_POST['node_library_structured'] ) || ! array_key_exists( 'subscriptions', $_POST['node_library_structured'] ) ) {
			return;
		}

		// Microsoft カタログチケット同時保存時は observe_from_catalog() が gamepass 履歴を正規更新するため、手動同期から除外して履歴分断を防ぐ（H-4）。
		$skip_services = array();
		if ( ! empty( $_POST['node_library_catalog_ticket'] ) && is_string( $_POST['node_library_catalog_ticket'] ) ) {
			$skip_services[] = 'gamepass';
		}

		$periods_raw = isset( $_POST['node_library_subscription_periods'] ) && is_array( $_POST['node_library_subscription_periods'] )
			? wp_unslash( $_POST['node_library_subscription_periods'] )
			: array();

		if ( $periods_raw ) {
			$services = class_exists( 'Node_Library_Structured_Meta' ) ? Node_Library_Structured_Meta::subscriptions() : array();
			$stored   = get_post_meta( $post_id, '_node_library_subscriptions', true );
			$stored   = is_array( $stored ) ? $stored : array();
			foreach ( array_keys( $services ) as $service ) {
				if ( in_array( $service, $skip_services, true ) || ! isset( $periods_raw[ $service ] ) || ! is_array( $periods_raw[ $service ] ) ) {
					continue;
				}
				$entry             = $periods_raw[ $service ];
				$joined_raw        = is_string( $entry['joined_date'] ?? null ) ? sanitize_text_field( $entry['joined_date'] ) : null;
				$leaving_raw       = is_string( $entry['leaving_date'] ?? null ) ? sanitize_text_field( $entry['leaving_date'] ) : null;
				$prev_leaving_raw  = is_string( $entry['prev_leaving_date'] ?? null ) ? sanitize_text_field( $entry['prev_leaving_date'] ) : null;
				$present           = ! empty( $stored[ $service ] );
				self::record_manual_period( $post_id, $service, $present, $joined_raw, $leaving_raw, null, $prev_leaving_raw );
				$skip_services[] = $service;
			}
		}

		self::sync_item_flags( $post_id, null, $skip_services );
	}

	/**
	 * Reconcile current _node_library_subscriptions post meta against open history rows.
	 * Existing flagged items without an open row get an initial row at observation time (never backdated).
	 *
	 * @param int             $item_id       Library item post ID.
	 * @param int|null        $now           Optional UNIX timestamp override.
	 * @param array<int,string> $skip_services Optional service keys to skip.
	 * @return array<string, string>
	 */
	public static function sync_item_flags( int $item_id, ?int $now = null, array $skip_services = array() ): array {
		$services = class_exists( 'Node_Library_Structured_Meta' ) ? Node_Library_Structured_Meta::subscriptions() : array();
		$stored   = get_post_meta( $item_id, '_node_library_subscriptions', true );
		$stored   = is_array( $stored ) ? $stored : array();
		$results  = array();

		foreach ( array_keys( $services ) as $service ) {
			if ( in_array( $service, $skip_services, true ) ) {
				continue;
			}
			$present             = ! empty( $stored[ $service ] );
			// 通常編集保存では leaving_at を NULL 上書きしない（H-4）。
			$results[ $service ] = self::observe( $item_id, $service, $present, $now, null, false );
		}

		return $results;
	}

	/**
	 * Fetch all membership history rows for one library item (newest first).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_item_history( int $item_id ): array {
		if ( $item_id <= 0 || '1' !== get_option( 'node_library_subscription_schema' ) ) {
			return array();
		}
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, item_id, service, joined_at, leaving_at, left_at FROM {$table} WHERE item_id = %d ORDER BY joined_at DESC, id DESC",
				$item_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch recent membership changes (joined / leaving / left) for the admin overview table.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_recent_changes( int $limit = 50 ): array {
		if ( '1' !== get_option( 'node_library_subscription_schema' ) ) {
			return array();
		}
		global $wpdb;
		$table = self::table();
		$limit = max( 1, min( 200, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.id, h.item_id, h.service, h.joined_at, h.leaving_at, h.left_at, p.post_title
				FROM {$table} h
				INNER JOIN {$wpdb->posts} p ON p.ID = h.item_id
				WHERE p.post_type = 'node_library' AND p.post_status <> 'trash'
				ORDER BY COALESCE(h.left_at, h.joined_at) DESC, h.id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$labels = class_exists( 'Node_Library_Structured_Meta' ) ? Node_Library_Structured_Meta::subscriptions() : array();
		$out    = array();
		foreach ( $rows as $row ) {
			$service = (string) ( $row['service'] ?? '' );
			$left    = is_string( $row['left_at'] ?? null ) && '' !== $row['left_at'] ? $row['left_at'] : null;
			$leaving = is_string( $row['leaving_at'] ?? null ) && '' !== $row['leaving_at'] ? $row['leaving_at'] : null;
			$joined  = (string) ( $row['joined_at'] ?? '' );

			if ( null !== $left ) {
				$status   = 'left';
				$event_at = $left;
			} elseif ( null !== $leaving ) {
				if ( self::is_period_ended( $row ) ) {
					$status = 'ended';
				} else {
					$status = 'leaving';
				}
				$event_at = $leaving;
			} else {
				$status   = 'joined';
				$event_at = $joined;
			}

			$out[] = array(
				'id'            => (int) $row['id'],
				'item_id'       => (int) $row['item_id'],
				'title'         => (string) ( $row['post_title'] ?? '' ),
				'service'       => $service,
				'service_label' => $labels[ $service ] ?? $service,
				'status'        => $status,
				'joined_at'     => $joined,
				'leaving_at'    => $leaving,
				'left_at'       => $left,
				'event_at'      => $event_at,
			);
		}

		return $out;
	}

	/** Register submenu page under the node_library CPT admin menu. */
	public static function register_admin_page(): void {
		add_submenu_page(
			'edit.php?post_type=node_library',
			__( 'サブスク在籍履歴', 'node-library' ),
			__( 'サブスク履歴', 'node-library' ),
			'edit_posts',
			'node-library-subscriptions',
			array( self::class, 'render_admin_page' )
		);
	}

	/** Render the admin membership change list. */
	public static function render_admin_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$changes       = self::get_recent_changes( 100 );
		$status_labels = array(
			'joined'  => __( '新規加入', 'node-library' ),
			'leaving' => __( '終了予定', 'node-library' ),
			'ended'   => __( '終了', 'node-library' ),
			'left'    => __( '終了', 'node-library' ),
		);
		$giveaway_status_labels = array(
			'joined'  => __( '配布開始', 'node-library' ),
			'leaving' => __( '配布終了予定', 'node-library' ),
			'ended'   => __( '配布終了', 'node-library' ),
			'left'    => __( '配布終了', 'node-library' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'サブスク在籍の変化一覧', 'node-library' ); ?></h1>
			<?php if ( ! $changes ) : ?>
				<p><?php esc_html_e( '記録されたサブスク在籍の変化はまだありません。', 'node-library' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'タイトル', 'node-library' ); ?></th>
							<th scope="col"><?php esc_html_e( 'サービス', 'node-library' ); ?></th>
							<th scope="col"><?php esc_html_e( '状態', 'node-library' ); ?></th>
							<th scope="col"><?php esc_html_e( '日付（JST）', 'node-library' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $changes as $entry ) : ?>
							<?php
							$edit_link = get_edit_post_link( (int) $entry['item_id'] );
							$label_map = self::is_giveaway_service( (string) $entry['service'] ) ? $giveaway_status_labels : $status_labels;
							?>
							<tr>
								<td>
									<?php if ( is_string( $edit_link ) && '' !== $edit_link ) : ?>
										<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $entry['title'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $entry['title'] ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $entry['service_label'] ); ?></td>
								<td><?php echo esc_html( $label_map[ $entry['status'] ] ?? $entry['status'] ); ?></td>
								<td><?php echo esc_html( self::format_jst_date( (string) $entry['event_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Render subscription membership history on the single library page (only when rows exist). */
	public static function render_single_history( int $post_id ): void {
		$rows = self::get_item_history( $post_id );
		if ( ! $rows ) {
			return;
		}
		$labels = class_exists( 'Node_Library_Structured_Meta' ) ? Node_Library_Structured_Meta::subscriptions() : array();
		?>
		<section class="node-library-single__subscriptions" aria-labelledby="node-library-subscriptions-title">
			<div class="node-library-single__section-heading">
				<span class="material-symbols-outlined" data-icon="sports_esports" aria-hidden="true"></span>
				<h2 id="node-library-subscriptions-title"><?php esc_html_e( 'サブスク在籍履歴', 'node-library' ); ?></h2>
			</div>
			<ul class="node-library-subscriptions__list">
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$service     = (string) ( $row['service'] ?? '' );
					$label       = $labels[ $service ] ?? $service;
					$joined      = self::format_jst_date( (string) ( $row['joined_at'] ?? '' ) );
					$left        = is_string( $row['left_at'] ?? null ) && '' !== $row['left_at'] ? self::format_jst_date( $row['left_at'] ) : '';
					$leaving     = is_string( $row['leaving_at'] ?? null ) && '' !== $row['leaving_at'] ? self::format_jst_date( $row['leaving_at'] ) : '';
					$is_giveaway = self::is_giveaway_service( $service );
					if ( $is_giveaway ) {
						if ( '' !== $left ) {
							$period = sprintf( '%s 〜 %s（配布終了）', $joined, '' !== $leaving ? $leaving : $left );
						} elseif ( '' !== $leaving ) {
							$period = self::is_period_ended( $row )
								? sprintf( '%s 〜 %s（配布終了）', $joined, $leaving )
								: sprintf( '%s 〜 %s（配布中）', $joined, $leaving );
						} else {
							$period = sprintf( '%s 〜（配布中）', $joined );
						}
					} elseif ( '' !== $left ) {
						$period = sprintf( '%s 〜 %s（終了）', $joined, $left );
					} elseif ( '' !== $leaving ) {
						$leaving_ts = strtotime( (string) $row['leaving_at'] . ' UTC' );
						if ( false !== $leaving_ts && $leaving_ts <= time() ) {
							$period = sprintf( '%s 〜（%s 終了予定・確認中）', $joined, $leaving );
						} else {
							$period = sprintf( '%s 〜（在籍中・%s 終了予定）', $joined, $leaving );
						}
					} else {
						$period = sprintf( '%s 〜（在籍中）', $joined );
					}
					?>
					<li class="node-library-subscriptions__item">
						<span class="node-library-subscriptions__service"><?php echo esc_html( $label ); ?></span>
						<span class="node-library-subscriptions__period"><?php echo esc_html( $period ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * Extract an optional leaving/end date from Microsoft passMetadataByPassProductId.
	 *
	 * @param array<string, mixed> $pass_meta Raw passMetadataByPassProductId map.
	 */
	private static function extract_microsoft_leaving_date( array $pass_meta ): ?string {
		$earliest_ts = null;
		foreach ( $pass_meta as $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			foreach ( array( 'leavingDate', 'endDate', 'expirationDate' ) as $key ) {
				$raw = $meta[ $key ] ?? null;
				if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
					continue;
				}
				$ts = strtotime( $raw );
				if ( false !== $ts && (int) gmdate( 'Y', $ts ) < 2100 ) {
					if ( null === $earliest_ts || $ts < $earliest_ts ) {
						$earliest_ts = $ts;
					}
				}
			}
		}
		return null !== $earliest_ts ? gmdate( 'Y-m-d H:i:s', $earliest_ts ) : null;
	}

	private static function normalize_datetime( ?string $value ): ?string {
		if ( null === $value || '' === trim( $value ) ) {
			return null;
		}
		$value = trim( $value );
		$ts    = strtotime( $value . ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ? ' UTC' : '' ) );
		return false !== $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}

	/**
	 * Normalize a manual JST date (YYYY-MM-DD) or datetime string to UTC Y-m-d H:i:s.
	 *
	 * @return string|null|false Null when empty, false when non-empty and invalid.
	 */
	private static function normalize_manual_date( ?string $value, bool $end_of_day ) {
		if ( null === $value || '' === trim( $value ) ) {
			return null;
		}
		$trimmed = trim( $value );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $trimmed, $m ) ) {
			if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return false;
			}
			$dt = new DateTimeImmutable(
				$m[1] . '-' . $m[2] . '-' . $m[3] . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' ),
				new DateTimeZone( 'Asia/Tokyo' )
			);
			return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}
		$normalized = self::normalize_datetime( $trimmed );
		return null !== $normalized ? $normalized : false;
	}

	private static function format_jst_input_date( string $utc_datetime ): string {
		$utc_datetime = trim( $utc_datetime );
		if ( '' === $utc_datetime ) {
			return '';
		}
		$ts = strtotime( $utc_datetime . ' UTC' );
		if ( false === $ts ) {
			return '';
		}
		return wp_date( 'Y-m-d', $ts, new DateTimeZone( 'Asia/Tokyo' ) );
	}

	private static function format_jst_date( string $utc_datetime ): string {
		$utc_datetime = trim( $utc_datetime );
		if ( '' === $utc_datetime ) {
			return '';
		}
		$ts = strtotime( $utc_datetime . ' UTC' );
		if ( false === $ts ) {
			return $utc_datetime;
		}
		return wp_date( 'Y/m/d', $ts, new DateTimeZone( 'Asia/Tokyo' ) );
	}
}
