<?php
/**
 * Library archive extended filters (meta + batched price history).
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

/**
 * Step 1 archive filter integration tests.
 */
class Node_Library_Archive_Filters_Test extends WP_UnitTestCase {
	private const TOKEN = 'nlfilt-9b4a';

	/** @var array<int, string> */
	private array $param_keys = array(
		'q',
		'tab',
		'sort',
		'dir',
		'hw',
		'genre',
		'crossplay',
		'crosssave',
		'online',
		'hdr',
		'min_fps',
		'res',
		'sub',
		'price_min',
		'price_max',
		'on_sale',
	);

	public function set_up(): void {
		parent::set_up();
		$library = Node_Library::instance();
		$this->set_permalink_structure( '/%postname%/' );
		$library->register_cpt();
		flush_rewrite_rules( false );
		Node_Library_Prices::install();
		Node_Library_Archive_Filters::init();
		if ( ! has_action( 'node_library_archive_toolbar_fields', array( Node_Library_Archive_Filters::class, 'render_fields' ) ) ) {
			add_action( 'node_library_archive_toolbar_fields', array( Node_Library_Archive_Filters::class, 'render_fields' ), 10, 1 );
		}
		if ( ! has_filter( 'node_library_archive_toolbar_query_args', array( Node_Library_Archive_Filters::class, 'filter_toolbar_query_args' ) ) ) {
			add_filter( 'node_library_archive_toolbar_query_args', array( Node_Library_Archive_Filters::class, 'filter_toolbar_query_args' ), 10, 2 );
		}
		if ( ! has_action( 'pre_get_posts', array( Node_Library_Archive_Filters::class, 'sync_post_id_filter' ) ) ) {
			add_action( 'pre_get_posts', array( Node_Library_Archive_Filters::class, 'sync_post_id_filter' ), 15 );
		}
	}

	public function tear_down(): void {
		foreach ( $this->param_keys as $key ) {
			unset( $_GET[ $key ] );
		}
		remove_filter( 'node_library_archive_filter_post_ids', array( Node_Library_Archive_Filters::class, 'filter_post_ids' ), 10 );
		parent::tear_down();
	}

	public function test_unfiltered_returns_ids_untouched_and_keeps_default_query(): void {
		$ids = array( 101, 202, 303 );
		$this->assertSame(
			$ids,
			Node_Library_Archive_Filters::filter_post_ids(
				$ids,
				array(
					'q'    => '',
					'tab'  => '',
					'sort' => '',
					'dir'  => '',
				)
			)
		);

		$this->go_to( home_url( '/library/' ) );
		global $wp_query;
		$this->assertTrue( $wp_query->is_post_type_archive( 'node_library' ) );
		$this->assertEmpty( $wp_query->get( 'post__in' ) );
	}

	public function test_meta_filters_single_and_combined(): void {
		$xbox_rpg = $this->create_item(
			'Xbox RPG ' . self::TOKEN,
			array(
				'links'         => array(
					array(
						'platform' => 'Xbox',
						'url'      => 'https://example.com/xbox',
						'category' => 'console',
						'hardware' => 'xbox-series',
					),
				),
				'genres'        => array( 'RPG', 'アクション' ),
				'capabilities'  => array(
					'crossplay'  => true,
					'crosssave'  => true,
					'online'     => true,
					'hdr'        => true,
					'max_fps'    => 120,
					'resolution' => '3840×2160',
				),
				'subscriptions' => array(
					'gamepass' => true,
				),
			)
		);

		$switch_action = $this->create_item(
			'Switch Action ' . self::TOKEN,
			array(
				'links'        => array(
					array(
						'platform' => 'Nintendo Switch',
						'url'      => 'https://example.com/switch',
						'category' => 'auto',
						'hardware' => 'auto',
					),
				),
				'genres'       => array( 'アクション' ),
				'capabilities' => array(
					'crossplay'  => false,
					'online'     => true,
					'max_fps'    => 60,
					'resolution' => '1920×1080',
				),
			)
		);

		$pc_indie = $this->create_item(
			'PC Indie ' . self::TOKEN,
			array(
				'links'         => array(
					array(
						'platform' => 'Steam',
						'url'      => 'https://example.com/steam',
						'category' => 'pc',
						'hardware' => 'windows-pc',
					),
				),
				'genres'        => array( 'RPG' ),
				'capabilities'  => array(
					'crossplay'  => true,
					'max_fps'    => 60,
					'resolution' => '2560×1440',
				),
				'subscriptions' => array(
					'gamepass' => true,
				),
			)
		);

		// Hardware (explicit and inferred from platform when hardware=auto).
		$this->go_to_library( array( 'q' => self::TOKEN, 'hw' => 'xbox-series' ) );
		$this->assertSame( array( $xbox_rpg ), $this->found_ids() );

		$this->go_to_library( array( 'q' => self::TOKEN, 'hw' => 'nintendo-switch' ) );
		$this->assertSame( array( $switch_action ), $this->found_ids() );

		// Genre.
		$this->go_to_library( array( 'q' => self::TOKEN, 'genre' => 'RPG' ) );
		$found_rpg = $this->found_ids();
		sort( $found_rpg );
		$expected_rpg = array( $xbox_rpg, $pc_indie );
		sort( $expected_rpg );
		$this->assertSame( $expected_rpg, $found_rpg );

		// Capability flags (crossplay, crosssave, hdr, min_fps, res).
		$this->go_to_library( array( 'q' => self::TOKEN, 'crossplay' => '1', 'min_fps' => '120', 'res' => '4k' ) );
		$this->assertSame( array( $xbox_rpg ), $this->found_ids() );

		$this->go_to_library( array( 'q' => self::TOKEN, 'hdr' => '1', 'crosssave' => '1' ) );
		$this->assertSame( array( $xbox_rpg ), $this->found_ids() );

		$this->go_to_library( array( 'q' => self::TOKEN, 'online' => '1', 'res' => '1080p' ) );
		$this->assertSame( array( $switch_action ), $this->found_ids() );

		// Subscription + existing tab AND combination.
		$this->go_to_library( array( 'q' => self::TOKEN, 'sub' => 'gamepass', 'tab' => 'console' ) );
		$this->assertSame( array( $xbox_rpg ), $this->found_ids() );

		$this->go_to_library( array( 'q' => self::TOKEN, 'sub' => 'gamepass', 'tab' => 'pc' ) );
		$this->assertSame( array( $pc_indie ), $this->found_ids() );
	}

	public function test_price_and_sale_batch_query_avoids_n_plus_one_and_filters_stale_records(): void {
		$now = time();

		// 1. Active sale (future sale_end), price 1500 / list 3000.
		$active_sale_id = $this->create_item(
			'Active Sale ' . self::TOKEN,
			array( 'store_ids' => array( 'microsoft' => '9ACTIVESALE1' ) )
		);
		Node_Library_Prices::record(
			$active_sale_id,
			'microsoft',
			'9ACTIVESALE1',
			array(
				'price'      => 1500,
				'list_price' => 3000,
				'currency'   => 'JPY',
				'sale_end'   => gmdate( 'Y-m-d H:i:s', $now + DAY_IN_SECONDS ),
			),
			$now - HOUR_IN_SECONDS
		);

		// 2. Expired sale (past sale_end), price 1200 / list 2400 -> excluded from on_sale, included in price range.
		$expired_sale_id = $this->create_item(
			'Expired Sale ' . self::TOKEN,
			array( 'store_ids' => array( 'microsoft' => '9EXPIREDSAL1' ) )
		);
		Node_Library_Prices::record(
			$expired_sale_id,
			'microsoft',
			'9EXPIREDSAL1',
			array(
				'price'      => 1200,
				'list_price' => 2400,
				'currency'   => 'JPY',
				'sale_end'   => gmdate( 'Y-m-d H:i:s', $now - MINUTE_IN_SECONDS ),
			),
			$now - DAY_IN_SECONDS
		);

		// 3. Old external_id had a cheap sale, but current external_id has regular high price 8000.
		$changed_id = $this->create_item(
			'Changed ID ' . self::TOKEN,
			array( 'store_ids' => array( 'microsoft' => '9NEWSTOREID1' ) )
		);
		Node_Library_Prices::record(
			$changed_id,
			'microsoft',
			'9OLDSTOREID1',
			array(
				'price'      => 500,
				'list_price' => 5000,
				'currency'   => 'JPY',
				'sale_end'   => gmdate( 'Y-m-d H:i:s', $now + DAY_IN_SECONDS ),
			),
			$now - HOUR_IN_SECONDS
		);
		Node_Library_Prices::record(
			$changed_id,
			'microsoft',
			'9NEWSTOREID1',
			array(
				'price'      => 8000,
				'list_price' => 8000,
				'currency'   => 'JPY',
				'sale_end'   => null,
			),
			$now - MINUTE_IN_SECONDS
		);

		// 4. Free item (price 0).
		$free_id = $this->create_item(
			'Free Item ' . self::TOKEN,
			array( 'store_ids' => array( 'microsoft' => '9FREEITEM001' ) )
		);
		Node_Library_Prices::record(
			$free_id,
			'microsoft',
			'9FREEITEM001',
			array(
				'price'      => 0,
				'list_price' => 0,
				'currency'   => 'JPY',
				'sale_end'   => null,
			),
			$now - HOUR_IN_SECONDS
		);

		// 5. Additional items to prove single batched SQL query (no N+1).
		$extra_ids = array();
		for ( $i = 1; $i <= 6; ++$i ) {
			$ext_id = sprintf( '9EXTRAITEM0%d', $i );
			$pid    = $this->create_item(
				'Extra ' . $i . ' ' . self::TOKEN,
				array( 'store_ids' => array( 'microsoft' => $ext_id ) )
			);
			Node_Library_Prices::record(
				$pid,
				'microsoft',
				$ext_id,
				array(
					'price'      => 4000 + ( $i * 100 ),
					'list_price' => 4000 + ( $i * 100 ),
					'currency'   => 'JPY',
					'sale_end'   => null,
				),
				$now - HOUR_IN_SECONDS
			);
			$extra_ids[] = $pid;
		}

		$all_candidates = array_merge( array( $active_sale_id, $expired_sale_id, $changed_id, $free_id ), $extra_ids );

		$price_queries = 0;
		$query_counter = static function ( string $sql ) use ( &$price_queries ): string {
			if ( false !== strpos( $sql, 'node_library_price_history' ) ) {
				++$price_queries;
			}
			return $sql;
		};
		add_filter( 'query', $query_counter );

		$sale_ids = Node_Library_Archive_Filters::filter_ids_by_price(
			$all_candidates,
			array(
				'price_min' => '',
				'price_max' => '',
				'on_sale'   => '1',
			),
			$now
		);

		remove_filter( 'query', $query_counter );

		$this->assertSame( array( $active_sale_id ), $sale_ids );
		$this->assertSame( 1, $price_queries, 'Price filtering must issue exactly one batched query per chunk.' );

		// Price range query via full archive request (expired sale normalizes to list_price per H-5).
		$this->go_to_library( array( 'q' => self::TOKEN, 'price_min' => '1000', 'price_max' => '2000' ) );
		$found_range = $this->found_ids();
		sort( $found_range );
		$expected_range = array( $active_sale_id );
		sort( $expected_range );
		$this->assertSame( $expected_range, $found_range );

		// Free-only price_max=0 works as a valid non-negative integer.
		$this->go_to_library( array( 'q' => self::TOKEN, 'price_max' => '0' ) );
		$this->assertSame( array( $free_id ), $this->found_ids() );

		// Missing price schema safely returns empty without SQL error.
		update_option( 'node_library_price_schema', '0', false );
		$this->assertSame(
			array(),
			Node_Library_Archive_Filters::filter_ids_by_price(
				$all_candidates,
				array(
					'price_min' => '',
					'price_max' => '5000',
					'on_sale'   => '',
				),
				$now
			)
		);
		update_option( 'node_library_price_schema', '1', false );
	}

	public function test_toolbar_query_args_and_form_fields_render_inside_form(): void {
		$_GET['q']         = 'halo';
		$_GET['hw']        = 'xbox-series';
		$_GET['genre']     = 'FPS';
		$_GET['crossplay'] = '1';
		$_GET['min_fps']   = '120';
		$_GET['res']       = '4k';
		$_GET['sub']       = 'gamepass';
		$_GET['price_min'] = '0';
		$_GET['price_max'] = '3000';
		$_GET['on_sale']   = '1';

		$args = node_library_archive_toolbar_query_args();
		$this->assertSame( 'halo', $args['q'] ?? '' );
		$this->assertSame( 'xbox-series', $args['hw'] ?? '' );
		$this->assertSame( 'FPS', $args['genre'] ?? '' );
		$this->assertSame( '1', $args['crossplay'] ?? '' );
		$this->assertSame( '120', $args['min_fps'] ?? '' );
		$this->assertSame( '4k', $args['res'] ?? '' );
		$this->assertSame( 'gamepass', $args['sub'] ?? '' );
		$this->assertSame( '0', $args['price_min'] ?? '' );
		$this->assertSame( '3000', $args['price_max'] ?? '' );
		$this->assertSame( '1', $args['on_sale'] ?? '' );

		$paginated = node_library_archive_with_toolbar_args( home_url( '/library/game/page/2/' ) );
		$this->assertStringContainsString( 'hw=xbox-series', $paginated );
		$this->assertStringContainsString( 'on_sale=1', $paginated );
		$this->assertStringContainsString( 'price_max=3000', $paginated );

		ob_start();
		node_library_render_archive_toolbar();
		$html = (string) ob_get_clean();

		$form_end = strpos( $html, '</form>' );
		$details  = strpos( $html, 'node-library-tools__advanced' );
		$this->assertNotFalse( $form_end );
		$this->assertNotFalse( $details );
		$this->assertLessThan( $form_end, $details );
		$this->assertStringContainsString( 'name="hw"', $html );
		$this->assertStringContainsString( 'name="genre"', $html );
		$this->assertStringContainsString( 'name="sub"', $html );
		$this->assertStringContainsString( 'name="min_fps"', $html );
		$this->assertStringContainsString( 'name="res"', $html );
		$this->assertStringContainsString( 'name="price_min"', $html );
		$this->assertStringContainsString( 'name="price_max"', $html );
		$this->assertStringContainsString( 'name="on_sale"', $html );
		$this->assertStringContainsString( 'name="crossplay"', $html );
		$this->assertStringContainsString( 'data-icon="tune"', $html );
		$this->assertStringContainsString( 'data-icon="filter_alt"', $html );
	}

	/**
	 * Navigate to /library/ with clean $_GET state for each assertion.
	 *
	 * @param array<string, string> $params Query params.
	 */
	private function go_to_library( array $params ): void {
		foreach ( $this->param_keys as $key ) {
			unset( $_GET[ $key ] );
		}
		foreach ( $params as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		$this->go_to( add_query_arg( $params, home_url( '/library/' ) ) );
	}

	/**
	 * @param array<string, mixed> $meta Meta payload.
	 */
	private function create_item( string $title, array $meta = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		update_post_meta( $post_id, '_node_library_summary', self::TOKEN );
		foreach ( array( 'links', 'genres', 'capabilities', 'subscriptions', 'store_ids' ) as $field ) {
			if ( isset( $meta[ $field ] ) ) {
				update_post_meta( $post_id, '_node_library_' . $field, $meta[ $field ] );
			}
		}

		return (int) $post_id;
	}

	/**
	 * @return array<int, int>
	 */
	private function found_ids(): array {
		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}
}
