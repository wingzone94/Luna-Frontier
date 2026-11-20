<?php
/**
 * Archive filter fields, query-arg propagation, and batched post-ID filtering.
 *
 * @package Node_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Library_Archive_Filters {
	private const PRICE_CHUNK_SIZE = 500;

	/** @var bool */
	private static $initialized = false;

	/** Register archive filter hooks once. */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'node_library_archive_toolbar_fields', array( self::class, 'render_fields' ), 10, 1 );
		add_filter( 'node_library_archive_toolbar_query_args', array( self::class, 'filter_toolbar_query_args' ), 10, 2 );
		add_action( 'pre_get_posts', array( self::class, 'sync_post_id_filter' ), 15 );
	}

	/**
	 * Attach the post-ID filter only when at least one extra filter parameter is active,
	 * so plain /library/ requests keep the untouched default WP_Query.
	 *
	 * @param WP_Query $query Main query.
	 */
	public static function sync_post_id_filter( $query ): void {
		if ( ! $query instanceof WP_Query || is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->is_singular() || ! $query->is_post_type_archive( 'node_library' ) ) {
			return;
		}

		if ( self::has_active_filters() ) {
			if ( ! has_filter( 'node_library_archive_filter_post_ids', array( self::class, 'filter_post_ids' ) ) ) {
				add_filter( 'node_library_archive_filter_post_ids', array( self::class, 'filter_post_ids' ), 10, 2 );
			}
		} else {
			remove_filter( 'node_library_archive_filter_post_ids', array( self::class, 'filter_post_ids' ), 10 );
		}
	}

	/**
	 * Supported capability boolean query parameters and their UI labels.
	 *
	 * @return array<string, string>
	 */
	public static function capability_flags(): array {
		return array(
			'crossplay' => __( 'クロスプレイ', 'node-library' ),
			'crosssave' => __( 'クロスセーブ', 'node-library' ),
			'online'    => __( 'オンライン対応', 'node-library' ),
			'hdr'       => 'HDR',
		);
	}

	/**
	 * Supported FPS threshold options.
	 *
	 * @return array<string, string>
	 */
	public static function fps_options(): array {
		return array(
			'60'  => __( '60fps以上', 'node-library' ),
			'120' => __( '120fps以上', 'node-library' ),
		);
	}

	/**
	 * Supported resolution preset options.
	 *
	 * @return array<string, string>
	 */
	public static function resolution_options(): array {
		return array(
			'4k'    => '4K (2160p)',
			'1440p' => 'WQHD (1440p)',
			'1080p' => 'Full HD (1080p)',
		);
	}

	/**
	 * Read and sanitize all scalar archive filter parameters from the current request.
	 *
	 * @return array<string, string>
	 */
	public static function request_filters(): array {
		$hardware_options = function_exists( 'node_library_hardware_options' ) ? node_library_hardware_options() : array();
		unset( $hardware_options['auto'] );

		$hw = sanitize_key( self::read_scalar_param( 'hw' ) );
		if ( ! isset( $hardware_options[ $hw ] ) ) {
			$hw = '';
		}

		$genre = trim( self::read_scalar_param( 'genre' ) );
		if ( '' !== $genre ) {
			$genre = function_exists( 'mb_substr' ) ? mb_substr( $genre, 0, 80 ) : substr( $genre, 0, 80 );
		}

		$sub_options = class_exists( 'Node_Library_Structured_Meta' ) ? Node_Library_Structured_Meta::subscriptions() : array();
		$sub         = sanitize_key( self::read_scalar_param( 'sub' ) );
		if ( ! isset( $sub_options[ $sub ] ) ) {
			$sub = '';
		}

		$min_fps_raw = self::read_scalar_param( 'min_fps' );
		$min_fps     = '';
		if ( '' !== $min_fps_raw && preg_match( '/^[0-9]{1,4}$/D', $min_fps_raw ) && (int) $min_fps_raw > 0 ) {
			$min_fps = (string) (int) $min_fps_raw;
		}

		$res = sanitize_key( self::read_scalar_param( 'res' ) );

		$price_min = self::sanitize_non_negative_int( self::read_scalar_param( 'price_min' ) );
		$price_max = self::sanitize_non_negative_int( self::read_scalar_param( 'price_max' ) );
		$on_sale   = '1' === self::read_scalar_param( 'on_sale' ) ? '1' : '';

		$filters = array(
			'hw'        => $hw,
			'genre'     => $genre,
			'crossplay' => '1' === self::read_scalar_param( 'crossplay' ) ? '1' : '',
			'crosssave' => '1' === self::read_scalar_param( 'crosssave' ) ? '1' : '',
			'online'    => '1' === self::read_scalar_param( 'online' ) ? '1' : '',
			'hdr'       => '1' === self::read_scalar_param( 'hdr' ) ? '1' : '',
			'min_fps'   => $min_fps,
			'res'       => $res,
			'sub'       => $sub,
			'price_min' => $price_min,
			'price_max' => $price_max,
			'on_sale'   => $on_sale,
		);

		return $filters;
	}

	/**
	 * Whether any extra archive filter is active.
	 *
	 * @param array<string, string>|null $filters Optional pre-read filter state.
	 */
	public static function has_active_filters( ?array $filters = null ): bool {
		$filters = $filters ?? self::request_filters();
		foreach ( $filters as $value ) {
			if ( '' !== $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Count active extra archive filters for UI feedback.
	 *
	 * @param array<string, string> $filters Filter state.
	 */
	private static function active_filter_count( array $filters ): int {
		$count = 0;
		foreach ( $filters as $value ) {
			if ( '' !== $value ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Propagate active scalar filter parameters to pagination and tab links.
	 *
	 * @param array<string, string> $args  Existing query args.
	 * @param array<string, string> $state Toolbar state.
	 * @return array<string, string>
	 */
	public static function filter_toolbar_query_args( array $args, array $state ): array {
		unset( $state );
		foreach ( self::request_filters() as $key => $value ) {
			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}
		return $args;
	}

	/**
	 * Narrow candidate library post IDs using batched meta and price queries.
	 *
	 * @param array<int, int>       $ids   Candidate post IDs.
	 * @param array<string, string> $state Toolbar state.
	 * @return array<int, int>
	 */
	public static function filter_post_ids( array $ids, array $state ): array {
		unset( $state );
		$filters = self::request_filters();
		if ( ! self::has_active_filters( $filters ) ) {
			return $ids;
		}

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		$has_meta_filters = '' !== $filters['hw']
			|| '' !== $filters['genre']
			|| '' !== $filters['crossplay']
			|| '' !== $filters['crosssave']
			|| '' !== $filters['online']
			|| '' !== $filters['hdr']
			|| '' !== $filters['min_fps']
			|| '' !== $filters['res']
			|| '' !== $filters['sub'];

		if ( $has_meta_filters ) {
			update_meta_cache( 'post', $ids );
			$matched = array();
			foreach ( $ids as $post_id ) {
				if ( self::matches_meta_filters( $post_id, $filters ) ) {
					$matched[] = $post_id;
				}
			}
			$ids = $matched;
			if ( ! $ids ) {
				return array();
			}
		}

		$has_price_filters = '' !== $filters['price_min']
			|| '' !== $filters['price_max']
			|| '' !== $filters['on_sale'];

		if ( $has_price_filters ) {
			$ids = self::filter_ids_by_price( $ids, $filters );
		}

		return $ids;
	}

	/**
	 * Render collapsible extra filter fields inside #node-library-tools.
	 *
	 * @param array<string, string> $state Toolbar state.
	 */
	public static function render_fields( array $state ): void {
		unset( $state );
		$filters      = self::request_filters();
		$active_count = self::active_filter_count( $filters );
		$hw_options   = function_exists( 'node_library_hardware_options' ) ? node_library_hardware_options() : array();
		unset( $hw_options['auto'] );

		$sub_options = class_exists( 'Node_Library_Structured_Meta' ) ? Node_Library_Structured_Meta::subscriptions() : array();
		$genres      = self::available_genres();
		if ( '' !== $filters['genre'] && ! in_array( $filters['genre'], $genres, true ) ) {
			$genres[] = $filters['genre'];
		}

		$reset_url = self::reset_url();
		?>
		<details class="node-library-tools__advanced"<?php echo $active_count > 0 ? ' open' : ''; ?>>
			<summary class="node-library-tools__advanced-toggle">
				<span class="material-symbols-outlined" data-icon="tune" aria-hidden="true"></span>
				<span><?php esc_html_e( '詳細フィルター', 'node-library' ); ?></span>
				<?php if ( $active_count > 0 ) : ?>
					<span class="node-library-tools__active-count"><?php echo esc_html( sprintf( __( '%d件適用中', 'node-library' ), $active_count ) ); ?></span>
				<?php endif; ?>
				<span class="material-symbols-outlined node-library-tools__advanced-chevron" data-icon="expand_more" aria-hidden="true"></span>
			</summary>
			<div class="node-library-tools__advanced-body">
				<div class="node-library-tools__advanced-grid">
					<label class="node-library-tools__field">
						<span><?php esc_html_e( '対応機種', 'node-library' ); ?></span>
						<select class="node-library-tools__control" name="hw">
							<option value=""><?php esc_html_e( 'すべて', 'node-library' ); ?></option>
							<?php foreach ( $hw_options as $hw_key => $hw_label ) : ?>
								<option value="<?php echo esc_attr( $hw_key ); ?>" <?php selected( $filters['hw'], $hw_key ); ?>><?php echo esc_html( $hw_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="node-library-tools__field">
						<span><?php esc_html_e( 'ジャンル', 'node-library' ); ?></span>
						<select class="node-library-tools__control" name="genre">
							<option value=""><?php esc_html_e( 'すべて', 'node-library' ); ?></option>
							<?php foreach ( $genres as $genre_label ) : ?>
								<option value="<?php echo esc_attr( $genre_label ); ?>" <?php selected( $filters['genre'], $genre_label ); ?>><?php echo esc_html( $genre_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="node-library-tools__field">
						<span><?php esc_html_e( 'サブスク', 'node-library' ); ?></span>
						<select class="node-library-tools__control" name="sub">
							<option value=""><?php esc_html_e( 'すべて', 'node-library' ); ?></option>
							<?php foreach ( $sub_options as $sub_key => $sub_label ) : ?>
								<option value="<?php echo esc_attr( $sub_key ); ?>" <?php selected( $filters['sub'], $sub_key ); ?>><?php echo esc_html( $sub_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="node-library-tools__field">
						<span><?php esc_html_e( '最大FPS', 'node-library' ); ?></span>
						<select class="node-library-tools__control" name="min_fps">
							<option value=""><?php esc_html_e( '指定なし', 'node-library' ); ?></option>
							<?php foreach ( self::fps_options() as $fps_value => $fps_label ) : ?>
								<option value="<?php echo esc_attr( $fps_value ); ?>" <?php selected( $filters['min_fps'], $fps_value ); ?>><?php echo esc_html( $fps_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="node-library-tools__field">
						<span><?php esc_html_e( '解像度', 'node-library' ); ?></span>
						<select class="node-library-tools__control" name="res">
							<option value=""><?php esc_html_e( '指定なし', 'node-library' ); ?></option>
							<?php foreach ( self::resolution_options() as $res_value => $res_label ) : ?>
								<option value="<?php echo esc_attr( $res_value ); ?>" <?php selected( $filters['res'], $res_value ); ?>><?php echo esc_html( $res_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="node-library-tools__field">
						<span><?php esc_html_e( '価格下限（円）', 'node-library' ); ?></span>
						<input class="node-library-tools__control" type="number" name="price_min" min="0" step="100" inputmode="numeric" value="<?php echo esc_attr( $filters['price_min'] ); ?>" placeholder="0">
					</label>

					<label class="node-library-tools__field">
						<span><?php esc_html_e( '価格上限（円）', 'node-library' ); ?></span>
						<input class="node-library-tools__control" type="number" name="price_max" min="0" step="100" inputmode="numeric" value="<?php echo esc_attr( $filters['price_max'] ); ?>" placeholder="9800">
					</label>
				</div>

				<fieldset class="node-library-tools__checks">
					<legend class="screen-reader-text"><?php esc_html_e( 'セール・機能条件', 'node-library' ); ?></legend>
					<label class="node-library-tools__check">
						<input type="checkbox" name="on_sale" value="1" <?php checked( $filters['on_sale'], '1' ); ?>>
						<span><?php esc_html_e( 'セール中のみ', 'node-library' ); ?></span>
					</label>
					<?php foreach ( self::capability_flags() as $flag_key => $flag_label ) : ?>
						<label class="node-library-tools__check">
							<input type="checkbox" name="<?php echo esc_attr( $flag_key ); ?>" value="1" <?php checked( $filters[ $flag_key ], '1' ); ?>>
							<span><?php echo esc_html( $flag_label ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<div class="node-library-tools__advanced-actions">
					<button class="node-library-tools__submit" type="submit">
						<span class="material-symbols-outlined" data-icon="filter_alt" aria-hidden="true"></span>
						<?php esc_html_e( '条件を適用', 'node-library' ); ?>
					</button>
					<?php if ( $active_count > 0 && '' !== $reset_url ) : ?>
						<a class="node-library-tools__reset" href="<?php echo esc_url( $reset_url ); ?>"><?php esc_html_e( '詳細条件をクリア', 'node-library' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</details>
		<?php
	}

	/**
	 * Match post-meta filters in memory (expects update_meta_cache already called).
	 *
	 * @param int                   $post_id Library item ID.
	 * @param array<string, string> $filters Sanitized filters.
	 */
	private static function matches_meta_filters( int $post_id, array $filters ): bool {
		if ( '' !== $filters['hw'] && ! self::matches_hardware( $post_id, $filters['hw'] ) ) {
			return false;
		}

		if ( '' !== $filters['genre'] ) {
			$genres = get_post_meta( $post_id, '_node_library_genres', true );
			if ( ! is_array( $genres ) || ! in_array( $filters['genre'], $genres, true ) ) {
				return false;
			}
		}

		$needs_caps = '' !== $filters['crossplay']
			|| '' !== $filters['crosssave']
			|| '' !== $filters['online']
			|| '' !== $filters['hdr']
			|| '' !== $filters['min_fps']
			|| '' !== $filters['res'];

		if ( $needs_caps ) {
			$caps = get_post_meta( $post_id, '_node_library_capabilities', true );
			if ( ! is_array( $caps ) ) {
				return false;
			}
			foreach ( array( 'crossplay', 'crosssave', 'online', 'hdr' ) as $flag ) {
				if ( '1' === $filters[ $flag ] && true !== ( $caps[ $flag ] ?? null ) ) {
					return false;
				}
			}
			if ( '' !== $filters['min_fps'] ) {
				if ( ! isset( $caps['max_fps'] ) || (int) $caps['max_fps'] < (int) $filters['min_fps'] ) {
					return false;
				}
			}
			if ( '' !== $filters['res'] && ! self::matches_resolution( (string) ( $caps['resolution'] ?? '' ), $filters['res'] ) ) {
				return false;
			}
		}

		if ( '' !== $filters['sub'] ) {
			$subs = get_post_meta( $post_id, '_node_library_subscriptions', true );
			if ( ! is_array( $subs ) || empty( $subs[ $filters['sub'] ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Match hardware against _node_library_links using the canonical normalize/infer helpers.
	 */
	private static function matches_hardware( int $post_id, string $target_hw ): bool {
		$links = get_post_meta( $post_id, '_node_library_links', true );
		if ( ! is_array( $links ) ) {
			return false;
		}

		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$hardware = function_exists( 'node_library_normalize_hardware' )
				? node_library_normalize_hardware( $link['hardware'] ?? '' )
				: 'auto';
			if ( 'auto' === $hardware && function_exists( 'node_library_infer_hardware_from_platform' ) ) {
				$hardware = node_library_infer_hardware_from_platform( (string) ( $link['platform'] ?? '' ) );
			}
			if ( $hardware === $target_hw ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match stored resolution text (e.g. "3840×2160") against a resolution preset or token.
	 */
	private static function matches_resolution( string $stored, string $target ): bool {
		$stored = strtolower( trim( $stored ) );
		if ( '' === $stored ) {
			return false;
		}

		$patterns = array(
			'4k'    => array( '4k', '2160', '3840' ),
			'1440p' => array( '1440', '2560', 'wqhd' ),
			'1080p' => array( '1080', '1920', 'fhd' ),
		);

		$needles = $patterns[ $target ] ?? array( strtolower( $target ) );
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && false !== strpos( $stored, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Batch-filter candidate IDs against {$wpdb->prefix}node_library_price_history without N+1 queries.
	 *
	 * @param array<int, int>       $ids     Candidate post IDs.
	 * @param array<string, string> $filters Sanitized filters.
	 * @param int|null              $now     Optional UNIX timestamp override.
	 * @return array<int, int>
	 */
	public static function filter_ids_by_price( array $ids, array $filters, ?int $now = null ): array {
		if ( '1' !== get_option( 'node_library_price_schema' ) ) {
			return array();
		}
		if ( ! $ids ) {
			return array();
		}

		update_meta_cache( 'post', $ids );

		$store_ids_by_post = array();
		$query_ids         = array();
		foreach ( $ids as $post_id ) {
			$store_ids = get_post_meta( $post_id, '_node_library_store_ids', true );
			if ( ! is_array( $store_ids ) || ! $store_ids ) {
				continue;
			}
			$clean_map = array();
			foreach ( $store_ids as $store => $external_id ) {
				if ( is_string( $store ) && '' !== $store && is_string( $external_id ) && '' !== $external_id ) {
					$clean_map[ $store ] = $external_id;
				}
			}
			if ( $clean_map ) {
				$store_ids_by_post[ $post_id ] = $clean_map;
				$query_ids[]                   = $post_id;
			}
		}

		if ( ! $query_ids ) {
			return array();
		}

		global $wpdb;
		$table       = $wpdb->prefix . 'node_library_price_history';
		$now         = $now ?? time();
		$has_min     = '' !== $filters['price_min'];
		$has_max     = '' !== $filters['price_max'];
		$price_min   = $has_min ? (float) $filters['price_min'] : 0.0;
		$price_max   = $has_max ? (float) $filters['price_max'] : 0.0;
		$on_sale     = '1' === $filters['on_sale'];
		$matched_map = array();

		foreach ( array_chunk( $query_ids, self::PRICE_CHUNK_SIZE ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT h.item_id, h.store, h.external_id, h.price, h.list_price, h.currency, h.sale_end
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
				$chunk
			);

			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$item_id     = (int) ( $row['item_id'] ?? 0 );
				$store       = (string) ( $row['store'] ?? '' );
				$external_id = (string) ( $row['external_id'] ?? '' );

				if ( ! isset( $store_ids_by_post[ $item_id ][ $store ] ) || $store_ids_by_post[ $item_id ][ $store ] !== $external_id ) {
					continue;
				}
				if ( null === $row['price'] || '' === (string) $row['price'] ) {
					continue;
				}

				$price = (float) $row['price'];
				if ( $has_min && $price < $price_min ) {
					continue;
				}
				if ( $has_max && $price > $price_max ) {
					continue;
				}
				if ( $on_sale ) {
					if ( null === $row['list_price'] || '' === (string) $row['list_price'] || $price >= (float) $row['list_price'] ) {
						continue;
					}
					$sale_end = $row['sale_end'] ?? null;
					if ( is_string( $sale_end ) && '' !== $sale_end ) {
						$end_ts = strtotime( $sale_end . ' UTC' );
						if ( false === $end_ts || $end_ts <= $now ) {
							continue;
						}
					}
				}

				$matched_map[ $item_id ] = true;
			}
		}

		$result = array();
		foreach ( $ids as $post_id ) {
			if ( isset( $matched_map[ $post_id ] ) ) {
				$result[] = $post_id;
			}
		}

		return $result;
	}

	/**
	 * Collect distinct genre labels saved on published library items.
	 *
	 * @return array<int, string>
	 */
	private static function available_genres(): array {
		global $wpdb;
		$raw_values = $wpdb->get_col(
			"SELECT DISTINCT m.meta_value
			FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key = '_node_library_genres'
			AND p.post_type = 'node_library'
			AND p.post_status = 'publish'"
		);
		if ( ! is_array( $raw_values ) ) {
			return array();
		}

		$genres = array();
		foreach ( $raw_values as $raw ) {
			$list = maybe_unserialize( $raw );
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $genre ) {
				if ( is_string( $genre ) ) {
					$genre = trim( $genre );
					if ( '' !== $genre ) {
						$genres[ $genre ] = true;
					}
				}
			}
		}

		$labels = array_keys( $genres );
		sort( $labels, SORT_STRING );
		return $labels;
	}

	/**
	 * Build a URL that clears only the extra filters while keeping base toolbar state (q/tab/sort/dir).
	 */
	private static function reset_url(): string {
		$archive = get_post_type_archive_link( 'node_library' );
		if ( ! is_string( $archive ) || '' === $archive ) {
			return '';
		}

		$type = sanitize_key( (string) get_query_var( 'node_library_type' ) );
		if ( '' === $type && isset( $_GET['type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type_raw = wp_unslash( $_GET['type'] );
			$type     = sanitize_key( is_string( $type_raw ) ? $type_raw : '' );
		}
		$base = in_array( $type, array( 'game', 'app' ), true ) ? trailingslashit( $archive . $type ) : $archive;

		if ( ! function_exists( 'node_library_archive_toolbar_state' ) ) {
			return $base;
		}
		$state = node_library_archive_toolbar_state();
		$args  = array();
		if ( '' !== $state['q'] ) {
			$args['q'] = $state['q'];
		}
		if ( '' !== $state['tab'] ) {
			$args['tab'] = $state['tab'];
		}
		if ( '' !== $state['sort'] ) {
			$args['sort'] = $state['sort'];
			if ( function_exists( 'node_library_archive_effective_dir' ) ) {
				$args['dir'] = node_library_archive_effective_dir( $state['sort'], $state['dir'] );
			}
		}

		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/**
	 * Read a scalar string parameter from $_GET.
	 */
	private static function read_scalar_param( string $key ): string {
		if ( ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		$raw = wp_unslash( $_GET[ $key ] );
		if ( ! is_string( $raw ) ) {
			return '';
		}
		return sanitize_text_field( $raw );
	}

	/**
	 * Sanitize a non-negative integer string (allows '0').
	 */
	private static function sanitize_non_negative_int( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || ! preg_match( '/^[0-9]{1,9}$/D', $value ) ) {
			return '';
		}
		return (string) (int) $value;
	}
}
