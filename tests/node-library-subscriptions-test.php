<?php
/**
 * Subscription membership history tracking, admin change list, and single-page display tests.
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_Subscriptions_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Node_Library::instance();
		Node_Library_Store::install();
		Node_Library_Subscriptions::install();
		Node_Library_Subscriptions::init();
	}

	public function test_join_leave_idempotency_and_single_open_row_per_service(): void {
		$item_id = $this->create_item( 'Halo Infinite' );
		$t1      = strtotime( '2026-01-10 00:00:00 UTC' );
		$t2      = strtotime( '2026-01-15 00:00:00 UTC' );
		$t3      = strtotime( '2026-02-01 00:00:00 UTC' );
		$t4      = strtotime( '2026-03-01 00:00:00 UTC' );

		// Absence before any join is unchanged.
		$this->assertSame( 'unchanged', Node_Library_Subscriptions::observe( $item_id, 'gamepass', false, $t1 ) );
		$this->assertSame( array(), Node_Library_Subscriptions::get_item_history( $item_id ) );

		// Join once.
		$this->assertSame( 'joined', Node_Library_Subscriptions::observe( $item_id, 'gamepass', true, $t1 ) );
		// Repeated join is idempotent (no duplicate open row).
		$this->assertSame( 'unchanged', Node_Library_Subscriptions::observe( $item_id, 'gamepass', true, $t2 ) );
		$rows = Node_Library_Subscriptions::get_item_history( $item_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( '2026-01-10 00:00:00', $rows[0]['joined_at'] );
		$this->assertNull( $rows[0]['left_at'] );

		// Leave closes the open row.
		$this->assertSame( 'left', Node_Library_Subscriptions::observe( $item_id, 'gamepass', false, $t3 ) );
		// Repeated leave is idempotent.
		$this->assertSame( 'unchanged', Node_Library_Subscriptions::observe( $item_id, 'gamepass', false, $t4 ) );

		$rows = Node_Library_Subscriptions::get_item_history( $item_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( '2026-02-01 00:00:00', $rows[0]['left_at'] );

		// Re-join creates a second row while previous closed row stays intact.
		$this->assertSame( 'joined', Node_Library_Subscriptions::observe( $item_id, 'gamepass', true, $t4 ) );
		$rows = Node_Library_Subscriptions::get_item_history( $item_id );
		$this->assertCount( 2, $rows );
		$this->assertNull( $rows[0]['left_at'] );
		$this->assertSame( '2026-03-01 00:00:00', $rows[0]['joined_at'] );
		$this->assertSame( '2026-02-01 00:00:00', $rows[1]['left_at'] );
	}

	public function test_services_are_tracked_independently_and_initial_flagged_item_records_observation_time(): void {
		$item_id = $this->create_item( 'Multi-Service Title' );
		$t1      = strtotime( '2026-04-01 03:00:00 UTC' );
		$t2      = strtotime( '2026-05-01 03:00:00 UTC' );

		// Pre-existing L1 flag without prior history rows.
		update_post_meta( $item_id, '_node_library_subscriptions', array( 'gamepass' => true, 'psplus' => true ) );

		$first = Node_Library_Subscriptions::sync_item_flags( $item_id, $t1 );
		$this->assertSame( 'joined', $first['gamepass'] );
		$this->assertSame( 'joined', $first['psplus'] );
		$this->assertSame( 'unchanged', $first['nso'] );

		// Leaving gamepass must not affect psplus.
		$this->assertSame( 'left', Node_Library_Subscriptions::observe( $item_id, 'gamepass', false, $t2 ) );

		$rows    = Node_Library_Subscriptions::get_item_history( $item_id );
		$by_svc  = array();
		foreach ( $rows as $row ) {
			$by_svc[ $row['service'] ] = $row;
		}
		$this->assertSame( '2026-04-01 03:00:00', $by_svc['gamepass']['joined_at'] );
		$this->assertSame( '2026-05-01 03:00:00', $by_svc['gamepass']['left_at'] );
		$this->assertSame( '2026-04-01 03:00:00', $by_svc['psplus']['joined_at'] );
		$this->assertNull( $by_svc['psplus']['left_at'] );
	}

	public function test_catalog_observation_tracks_microsoft_gamepass_and_leaving_date_only(): void {
		$item_id = $this->create_item( 'Forza Horizon 5' );
		$t1      = strtotime( '2026-06-01 00:00:00 UTC' );

		// Non-Microsoft store must not fabricate subscription observations.
		$steam_res = Node_Library_Subscriptions::observe_from_catalog(
			$item_id,
			array(
				'store'         => 'steam',
				'subscriptions' => array(),
			),
			$t1
		);
		$this->assertSame( array(), $steam_res );

		// Microsoft catalogue with Game Pass + leavingDate.
		$ms_catalog = array(
			'store'                 => 'microsoft',
			'id'                    => '9NNX1VBB3CD8',
			'subscriptions'         => array( 'CFQ7TTC0K6L8' ),
			'subscription_metadata' => array(
				'CFQ7TTC0K6L8' => array(
					'leavingDate' => '2026-07-15T14:59:59Z',
				),
			),
			'relations'             => array(),
		);
		$res = Node_Library_Subscriptions::observe_from_catalog( $item_id, $ms_catalog, $t1 );
		$this->assertSame( 'joined', $res['gamepass'] );

		$meta = get_post_meta( $item_id, '_node_library_subscriptions', true );
		$this->assertSame( array( 'gamepass' => true ), $meta );

		$rows = Node_Library_Subscriptions::get_item_history( $item_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( '2026-07-15 14:59:59', $rows[0]['leaving_at'] );
	}

	public function test_admin_recent_changes_and_single_page_history_rendering(): void {
		$empty_id = $this->create_item( 'No Sub History' );
		ob_start();
		Node_Library_Subscriptions::render_single_history( $empty_id );
		$this->assertSame( '', trim( (string) ob_get_clean() ) );

		$item_a = $this->create_item( 'Joined Game' );
		$item_b = $this->create_item( 'Leaving Game' );
		$item_c = $this->create_item( 'Left Game' );

		Node_Library_Subscriptions::observe( $item_a, 'gamepass', true, strtotime( '2026-05-01 00:00:00 UTC' ) );
		Node_Library_Subscriptions::observe( $item_b, 'gamepass', true, strtotime( '2026-05-02 00:00:00 UTC' ), '2026-06-30 14:59:59' );
		Node_Library_Subscriptions::observe( $item_c, 'psplus', true, strtotime( '2026-04-01 00:00:00 UTC' ) );
		Node_Library_Subscriptions::observe( $item_c, 'psplus', false, strtotime( '2026-05-10 00:00:00 UTC' ) );

		$changes = Node_Library_Subscriptions::get_recent_changes( 10 );
		$this->assertCount( 3, $changes );
		$this->assertSame( 'left', $changes[0]['status'] );
		$this->assertSame( 'Left Game', $changes[0]['title'] );
		$this->assertSame( 'leaving', $changes[1]['status'] );
		$this->assertSame( 'Leaving Game', $changes[1]['title'] );
		$this->assertSame( 'joined', $changes[2]['status'] );
		$this->assertSame( 'Joined Game', $changes[2]['title'] );

		// Single page display for leaving item.
		ob_start();
		Node_Library_Subscriptions::render_single_history( $item_b );
		$single_html = (string) ob_get_clean();
		$this->assertStringContainsString( 'node-library-single__subscriptions', $single_html );
		$this->assertStringContainsString( 'Xbox / PC Game Pass', $single_html );
		$this->assertStringContainsString( '終了予定', $single_html );
		$this->assertStringContainsString( 'data-icon="sports_esports"', $single_html );

		// Single page display for left item shows period.
		ob_start();
		Node_Library_Subscriptions::render_single_history( $item_c );
		$left_html = (string) ob_get_clean();
		$this->assertStringContainsString( 'PlayStation Plus', $left_html );
		$this->assertStringContainsString( '2026/04/01 〜 2026/05/10（終了）', $left_html );
	}

	public function test_manual_giveaway_period_creation_backdating_update_and_close(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$previous_post = $_POST;

		try {
			$item_id = $this->create_item( 'Epic Giveaway Game' );

			// Save via meta box POST with backdated start/end dates for epic_free and optional leaving_at for psplus.
			$_POST = wp_slash(
				array(
					'node_library_structured_nonce'    => wp_create_nonce( 'node_library_save_structured' ),
					'node_library_structured'          => array(
						'subscriptions' => array(
							'_present'  => '1',
							'epic_free' => '1',
							'psplus'    => '1',
						),
					),
					'node_library_subscription_periods' => array(
						'epic_free' => array(
							'joined_date'       => '2025-12-19',
							'leaving_date'      => '2025-12-26',
							'prev_leaving_date' => '',
						),
						'psplus'    => array(
							'leaving_date'      => '2027-01-15',
							'prev_leaving_date' => '',
						),
					),
				)
			);
			wp_update_post( array( 'ID' => $item_id, 'post_title' => 'Epic Giveaway Game' ) );

			$rows   = Node_Library_Subscriptions::get_item_history( $item_id );
			$by_svc = array();
			foreach ( $rows as $row ) {
				$by_svc[ $row['service'] ] = $row;
			}
			$this->assertArrayHasKey( 'epic_free', $by_svc );
			$this->assertArrayHasKey( 'psplus', $by_svc );
			// 2025-12-19 00:00:00 JST -> 2025-12-18 15:00:00 UTC.
			$this->assertSame( '2025-12-18 15:00:00', $by_svc['epic_free']['joined_at'] );
			// 2025-12-26 23:59:59 JST -> 2025-12-26 14:59:59 UTC.
			$this->assertSame( '2025-12-26 14:59:59', $by_svc['epic_free']['leaving_at'] );
			$this->assertNull( $by_svc['epic_free']['left_at'] );
			$this->assertTrue( Node_Library_Subscriptions::is_period_ended( $by_svc['epic_free'], strtotime( '2026-01-01 00:00:00 UTC' ) ) );
			$this->assertSame( '2027-01-15 14:59:59', $by_svc['psplus']['leaving_at'] );

			// Expired open giveaway row must display as 配布終了 in single history and ended in recent changes.
			ob_start();
			Node_Library_Subscriptions::render_single_history( $item_id );
			$history_html = (string) ob_get_clean();
			$this->assertStringContainsString( 'Epic Games Store 無料配布', $history_html );
			$this->assertStringContainsString( '2025/12/19 〜 2025/12/26（配布終了）', $history_html );

			$changes = Node_Library_Subscriptions::get_recent_changes( 10 );
			$epic_change = null;
			foreach ( $changes as $change ) {
				if ( 'epic_free' === $change['service'] && $item_id === $change['item_id'] ) {
					$epic_change = $change;
					break;
				}
			}
			$this->assertNotNull( $epic_change );
			$this->assertSame( 'ended', $epic_change['status'] );
			$this->assertSame( 'Epic Games Store 無料配布', $epic_change['service_label'] );

			// Update dates in place on the open row, and clear psplus leaving_at explicitly.
			$_POST = wp_slash(
				array(
					'node_library_structured_nonce'    => wp_create_nonce( 'node_library_save_structured' ),
					'node_library_structured'          => array(
						'subscriptions' => array(
							'_present'  => '1',
							'epic_free' => '1',
							'psplus'    => '1',
						),
					),
					'node_library_subscription_periods' => array(
						'epic_free' => array(
							'joined_date'       => '2026-11-01',
							'leaving_date'      => '2099-11-08',
							'prev_leaving_date' => '2025-12-26',
						),
						'psplus'    => array(
							'leaving_date'      => '',
							'prev_leaving_date' => '2027-01-15',
						),
					),
				)
			);
			wp_update_post( array( 'ID' => $item_id, 'post_title' => 'Epic Giveaway Game' ) );

			$updated_periods = Node_Library_Subscriptions::get_open_periods( $item_id );
			$this->assertSame( '2026-11-01', $updated_periods['epic_free']['joined_date'] );
			$this->assertSame( '2099-11-08', $updated_periods['epic_free']['leaving_date'] );
			$this->assertSame( '', $updated_periods['psplus']['leaving_date'] );

			// Unchecking epic_free closes the open row.
			$_POST = wp_slash(
				array(
					'node_library_structured_nonce'    => wp_create_nonce( 'node_library_save_structured' ),
					'node_library_structured'          => array(
						'subscriptions' => array(
							'_present' => '1',
							'psplus'   => '1',
						),
					),
					'node_library_subscription_periods' => array(
						'epic_free' => array(
							'joined_date'       => '2026-11-01',
							'leaving_date'      => '2099-11-08',
							'prev_leaving_date' => '2099-11-08',
						),
					),
				)
			);
			wp_update_post( array( 'ID' => $item_id, 'post_title' => 'Epic Giveaway Game' ) );

			$after_close = Node_Library_Subscriptions::get_open_periods( $item_id );
			$this->assertArrayNotHasKey( 'epic_free', $after_close );
			$this->assertArrayHasKey( 'psplus', $after_close );
		} finally {
			$_POST = $previous_post;
			wp_set_current_user( 0 );
		}
	}

	public function test_manual_period_rejects_invalid_range_and_preserves_gamepass_timestamp(): void {
		$item_id = $this->create_item( 'Game Pass Timestamp Preservation' );
		$t1      = strtotime( '2026-06-01 00:00:00 UTC' );

		Node_Library_Subscriptions::observe_from_catalog(
			$item_id,
			array(
				'store'                 => 'microsoft',
				'id'                    => '9GPPRESERVE1',
				'subscriptions'         => array( 'CFQ7TTC0K6L8' ),
				'subscription_metadata' => array(
					'CFQ7TTC0K6L8' => array(
						'leavingDate' => '2026-07-15T10:30:00Z',
					),
				),
				'relations'             => array(),
			),
			$t1
		);

		// Unchanged JST date input must keep the exact UTC leaving_at timestamp from Microsoft.
		$res = Node_Library_Subscriptions::record_manual_period(
			$item_id,
			'gamepass',
			true,
			null,
			'2026-07-15',
			$t1,
			'2026-07-15'
		);
		$this->assertSame( 'unchanged', $res );
		$rows = Node_Library_Subscriptions::get_item_history( $item_id );
		$this->assertSame( '2026-07-15 10:30:00', $rows[0]['leaving_at'] );

		// Inverted range (start > end) must return invalid and not insert a row.
		$invalid_res = Node_Library_Subscriptions::record_manual_period(
			$item_id,
			'psplus_freeplay',
			true,
			'2026-12-10',
			'2026-12-01'
		);
		$this->assertSame( 'invalid', $invalid_res );
	}

	private function create_item( string $title ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		return (int) $post_id;
	}
}
