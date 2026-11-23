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
