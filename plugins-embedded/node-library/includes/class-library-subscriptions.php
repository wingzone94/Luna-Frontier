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

	/** @var array<int, array<int, array<string, mixed>>> */
	private static array $history_cache = array();

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
		unset( self::$history_cache[ $id ] );
		if ( 'node_library' === $post->post_type && '1' === get_option( 'node_library_subscription_schema' ) ) {
			global $wpdb;
			$wpdb->delete( self::table(), array( 'item_id' => $id ), array( '%d' ) );
		}
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
	 * Prefetch subscription history rows for archive loops to avoid N+1 queries.
	 *
	 * @param array<int, int> $item_ids Library post IDs.
	 */
	public static function prime_history_cache( array $item_ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( ! $ids || '1' !== get_option( 'node_library_subscription_schema' ) ) {
			return;
		}
		$missing = array_values( array_filter( $ids, static fn( int $id ): bool => ! array_key_exists( $id, self::$history_cache ) ) );
		if ( ! $missing ) {
			return;
		}
		foreach ( $missing as $mid ) {
			self::$history_cache[ $mid ] = array();
		}
		global $wpdb;
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, item_id, service, joined_at, leaving_at, left_at FROM {$table} WHERE item_id IN ({$placeholders}) ORDER BY joined_at DESC, id DESC",
				$missing
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$item_id = (int) ( $row['item_id'] ?? 0 );
			if ( $item_id > 0 ) {
				self::$history_cache[ $item_id ][] = $row;
			}
		}
	}

	/**
	 * Build display badges/chips for all active subscription flags of an item,
	 * distinguishing ongoing catalogue memberships from giveaway distribution periods.
	 *
	 * @return array<string, array{service:string,label:string,kind:string,status:string,status_label:string,period_text:string,badge_text:string}>
	 */
	public static function get_display_badges( int $item_id, ?int $now = null ): array {
		if ( $item_id <= 0 || ! class_exists( 'Node_Library_Structured_Meta' ) ) {
			return array();
		}
		$services = Node_Library_Structured_Meta::subscriptions();
		$flags    = Node_Library_Structured_Meta::sanitize( 'subscriptions', get_post_meta( $item_id, '_node_library_subscriptions', true ) );
		if ( ! $flags ) {
			return array();
		}

		$now          = $now ?? time();
		$history_rows = self::get_item_history( $item_id );
		$latest_open  = array();
		$latest_any   = array();
		foreach ( $history_rows as $row ) {
			$svc = (string) ( $row['service'] ?? '' );
			if ( '' === $svc ) {
				continue;
			}
			if ( ! isset( $latest_any[ $svc ] ) ) {
				$latest_any[ $svc ] = $row;
			}
			if ( null === ( $row['left_at'] ?? null ) && ! isset( $latest_open[ $svc ] ) ) {
				$latest_open[ $svc ] = $row;
			}
		}

		$badges = array();
		foreach ( $services as $service => $label ) {
			if ( empty( $flags[ $service ] ) ) {
				continue;
			}
			$row         = $latest_open[ $service ] ?? ( $latest_any[ $service ] ?? null );
			$is_giveaway = self::is_giveaway_service( $service );
			if ( $is_giveaway ) {
				$start       = is_array( $row ) ? self::format_jst_date( (string) ( $row['joined_at'] ?? '' ) ) : '';
				$end         = is_array( $row ) && is_string( $row['leaving_at'] ?? null ) && '' !== $row['leaving_at']
					? self::format_jst_date( $row['leaving_at'] )
					: '';
				if ( '' !== $start && '' !== $end ) {
					$period_text = $start . '〜' . $end;
				} elseif ( '' !== $start ) {
					$period_text = $start . '〜';
				} elseif ( '' !== $end ) {
					$period_text = '〜' . $end;
				} else {
					$period_text = '';
				}
				$ended        = is_array( $row ) && self::is_period_ended( $row, $now );
				$status       = $ended ? 'ended' : 'active';
				$status_label = $ended ? __( '配布終了', 'node-library' ) : __( '配布中', 'node-library' );
				$badge_text   = '' !== $period_text
					? sprintf( '%s（%s：%s）', $label, $status_label, $period_text )
					: sprintf( '%s（%s）', $label, $status_label );
				$kind         = 'giveaway';
			} else {
				$kind         = 'catalog';
				$status       = 'active';
				$status_label = __( '加入中', 'node-library' );
				$period_text  = '';
				$badge_text   = sprintf( '%s（%s）', $label, $status_label );
			}

			$badges[ $service ] = array(
				'service'      => $service,
				'label'        => $label,
				'kind'         => $kind,
				'status'       => $status,
				'status_label' => $status_label,
				'period_text'  => $period_text,
				'badge_text'   => $badge_text,
			);
		}

		return $badges;
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
					unset( self::$history_cache[ $item_id ] );
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
							unset( self::$history_cache[ $item_id ] );
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
				unset( self::$history_cache[ $item_id ] );
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
		if ( array_key_exists( $item_id, self::$history_cache ) ) {
			return self::$history_cache[ $item_id ];
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
				$status   = self::is_period_ended( $row ) ? 'ended' : 'leaving';
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
		$labels       = class_exists( 'Node_Library_Structured_Meta' ) ? Node_Library_Structured_Meta::subscriptions() : array();
		$has_giveaway = false;
		$has_catalog  = false;
		foreach ( $rows as $row ) {
			if ( self::is_giveaway_service( (string) ( $row['service'] ?? '' ) ) ) {
				$has_giveaway = true;
			} else {
				$has_catalog = true;
			}
		}
		if ( $has_giveaway && ! $has_catalog ) {
			$section_heading = __( '配布期間履歴', 'node-library' );
		} elseif ( $has_giveaway && $has_catalog ) {
			$section_heading = __( 'サブスク在籍・配布期間履歴', 'node-library' );
		} else {
			$section_heading = __( 'サブスク在籍履歴', 'node-library' );
		}
		?>
		<section class="node-library-single__subscriptions" aria-labelledby="node-library-subscriptions-title">
			<div class="node-library-single__section-heading">
				<span class="material-symbols-outlined" data-icon="sports_esports" aria-hidden="true"></span>
				<h2 id="node-library-subscriptions-title"><?php echo esc_html( $section_heading ); ?></h2>
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
					$is_ended    = self::is_period_ended( $row );
					$epic_note   = 'epic_free' === $service ? __( '入手後は恒久所持（サブスク加入不要）', 'node-library' ) : '';

					if ( $is_giveaway ) {
						$end_display = '' !== $leaving ? $leaving : $left;
						if ( $is_ended && '' !== $end_display ) {
							$period = sprintf( '%s 〜 %s（配布終了）', $joined, $end_display );
						} elseif ( $is_ended ) {
							$period = sprintf( '%s 〜（配布終了）', $joined );
						} elseif ( '' !== $leaving ) {
							$period = sprintf( '%s 〜 %s（配布中）', $joined, $leaving );
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

					$item_classes = array( 'node-library-subscriptions__item' );
					$item_classes[] = $is_giveaway ? 'node-library-subscriptions__item--giveaway' : 'node-library-subscriptions__item--catalog';
					if ( $is_ended ) {
						$item_classes[] = 'node-library-subscriptions__item--ended';
					}
					?>
					<li class="<?php echo esc_attr( implode( ' ', $item_classes ) ); ?>">
						<span class="node-library-subscriptions__service-group">
							<?php if ( $is_giveaway ) : ?>
								<span class="node-library-subscriptions__kind"><?php esc_html_e( '配布期間', 'node-library' ); ?></span>
							<?php endif; ?>
							<span class="node-library-subscriptions__service"><?php echo esc_html( $label ); ?></span>
						</span>
						<span class="node-library-subscriptions__meta">
							<span class="node-library-subscriptions__period"><?php echo esc_html( $period ); ?></span>
							<?php if ( '' !== $epic_note ) : ?>
								<span class="node-library-subscriptions__note"><?php echo esc_html( $epic_note ); ?></span>
							<?php endif; ?>
						</span>
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
