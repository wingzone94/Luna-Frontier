<?php
/**
 * Test case for Luna Frontier 2.0 home date tabs.
 *
 * @package LunaFrontier
 */

class Luna_Home_Date_Tabs_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__ ) . '/inc/luna/home-date-tabs.php';
	}

	/**
	 * 日付境界の計算（今日・昨日・一昨日）がタイムゾーンを正しく考慮して計算されること。
	 */
	public function test_calculate_date_bounds_standard(): void {
		$ref = new DateTimeImmutable( '2026-11-12 15:30:00', new DateTimeZone( 'Asia/Tokyo' ) );
		$bounds = luna_home_calculate_date_bounds( $ref );

		$this->assertSame( '2026-11-12', $bounds['today_ymd'] );
		$this->assertSame( '2026-11-12 00:00:00', $bounds['today_start']->format( 'Y-m-d H:i:s' ) );

		$this->assertSame( '2026-11-11', $bounds['yesterday_ymd'] );
		$this->assertSame( '2026-11-11 00:00:00', $bounds['yesterday_start']->format( 'Y-m-d H:i:s' ) );

		$this->assertSame( '2026-11-10', $bounds['two_days_ago_ymd'] );
		$this->assertSame( '2026-11-10 00:00:00', $bounds['two_days_ago_start']->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * 深夜帯（23:59:59 と 翌日 00:00:00）の日付境界が正確に切り替わること。
	 */
	public function test_midnight_boundary_transition(): void {
		$tz = new DateTimeZone( 'Asia/Tokyo' );
		$before_midnight = new DateTimeImmutable( '2026-11-12 23:59:59', $tz );
		$after_midnight  = new DateTimeImmutable( '2026-11-13 00:00:00', $tz );

		$bounds_before = luna_home_calculate_date_bounds( $before_midnight );
		$bounds_after  = luna_home_calculate_date_bounds( $after_midnight );

		// 23:59:59 時点
		$this->assertSame( '2026-11-12', $bounds_before['today_ymd'] );
		$this->assertSame( '2026-11-11', $bounds_before['yesterday_ymd'] );
		$this->assertSame( '2026-11-10', $bounds_before['two_days_ago_ymd'] );

		// 00:00:00 時点（日付が1日進む）
		$this->assertSame( '2026-11-13', $bounds_after['today_ymd'] );
		$this->assertSame( '2026-11-12', $bounds_after['yesterday_ymd'] );
		$this->assertSame( '2026-11-11', $bounds_after['two_days_ago_ymd'] );
	}

	/**
	 * 明示的なタブ指定がある場合、その指定が優先されること。
	 */
	public function test_resolve_active_date_tab_explicit(): void {
		$ref = new DateTimeImmutable( '2026-11-12 12:00:00', new DateTimeZone( 'Asia/Tokyo' ) );

		$this->assertSame( 'today', luna_home_resolve_active_date_tab( 'today', '2026-11-01 10:00:00', $ref ) );
		$this->assertSame( 'yesterday', luna_home_resolve_active_date_tab( 'yesterday', '2026-11-12 10:00:00', $ref ) );
		$this->assertSame( 'two_days_ago', luna_home_resolve_active_date_tab( 'two_days_ago', '2026-11-12 10:00:00', $ref ) );
		$this->assertSame( 'older', luna_home_resolve_active_date_tab( 'older', '2026-11-12 10:00:00', $ref ) );
	}

	/**
	 * 自動切り替えルール:
	 * - 今日の記事がある -> today
	 * - 今日の記事がなく、昨日の記事がある -> yesterday
	 * - 今日・昨日の記事がなく、一昨日の記事がある -> two_days_ago
	 * - 今日・昨日・一昨日の記事がなく、以前の記事がある -> older
	 * - 記事が1件もない -> today（初期状態）
	 */
	public function test_resolve_active_date_tab_auto_fallback(): void {
		$ref = new DateTimeImmutable( '2026-11-12 12:00:00', new DateTimeZone( 'Asia/Tokyo' ) );

		// 今日の記事がある場合
		$this->assertSame( 'today', luna_home_resolve_active_date_tab( '', '2026-11-12 09:15:00', $ref ) );

		// 今日の記事がなく、昨日の記事がある場合
		$this->assertSame( 'yesterday', luna_home_resolve_active_date_tab( '', '2026-11-11 23:45:00', $ref ) );

		// 今日・昨日の記事がなく、一昨日の記事がある場合
		$this->assertSame( 'two_days_ago', luna_home_resolve_active_date_tab( '', '2026-11-10 18:00:00', $ref ) );

		// 一昨日より前の記事がある場合
		$this->assertSame( 'older', luna_home_resolve_active_date_tab( '', '2026-11-09 23:59:59', $ref ) );
		$this->assertSame( 'older', luna_home_resolve_active_date_tab( '', '2026-10-01 12:00:00', $ref ) );

		// 記事が存在しない場合
		$this->assertSame( 'today', luna_home_resolve_active_date_tab( '', null, $ref ) );
		$this->assertSame( 'today', luna_home_resolve_active_date_tab( '', '', $ref ) );

		// 不正なクエリパラメータが指定された場合もフォールバック
		$this->assertSame( 'yesterday', luna_home_resolve_active_date_tab( 'invalid_tab', '2026-11-11 12:00:00', $ref ) );
	}

	/**
	 * date_query の構造と境界が正確であること。
	 */
	public function test_build_date_query_structure(): void {
		$ref = new DateTimeImmutable( '2026-11-12 12:00:00', new DateTimeZone( 'Asia/Tokyo' ) );

		// today
		$query_today = luna_home_build_date_query( 'today', $ref );
		$this->assertSame( array(
			array(
				'year'  => 2026,
				'month' => 11,
				'day'   => 12,
			),
		), $query_today );

		// yesterday
		$query_yesterday = luna_home_build_date_query( 'yesterday', $ref );
		$this->assertSame( array(
			array(
				'year'  => 2026,
				'month' => 11,
				'day'   => 11,
			),
		), $query_yesterday );

		// two_days_ago
		$query_two_days_ago = luna_home_build_date_query( 'two_days_ago', $ref );
		$this->assertSame( array(
			array(
				'year'  => 2026,
				'month' => 11,
				'day'   => 10,
			),
		), $query_two_days_ago );

		// older (一昨日の 00:00:00 より前、inclusive => false)
		$query_older = luna_home_build_date_query( 'older', $ref );
		$this->assertSame( array(
			array(
				'before'    => '2026-11-10 00:00:00',
				'inclusive' => false,
			),
		), $query_older );
	}

	/**
	 * ナビゲーションデータ生成とURLパラメータ（カテゴリとの併用、期間の保持）。
	 */
	public function test_get_date_tabs_data(): void {
		$tabs = luna_home_get_date_tabs_data( 'yesterday', 5, 30, 'https://example.com/' );

		$this->assertArrayHasKey( 'today', $tabs );
		$this->assertArrayHasKey( 'yesterday', $tabs );
		$this->assertArrayHasKey( 'two_days_ago', $tabs );
		$this->assertArrayHasKey( 'older', $tabs );

		// アクティブフラグ
		$this->assertFalse( $tabs['today']['is_active'] );
		$this->assertTrue( $tabs['yesterday']['is_active'] );
		$this->assertFalse( $tabs['two_days_ago']['is_active'] );
		$this->assertFalse( $tabs['older']['is_active'] );

		// URLに lf_date, lf_category, lf_period, #latest が含まれていること
		$this->assertStringContainsString( 'lf_date=today', $tabs['today']['url'] );
		$this->assertStringContainsString( 'lf_category=5', $tabs['today']['url'] );
		$this->assertStringContainsString( 'lf_period=30', $tabs['today']['url'] );
		$this->assertStringEndsWith( '#latest', $tabs['today']['url'] );

		$this->assertStringContainsString( 'lf_date=yesterday', $tabs['yesterday']['url'] );
		$this->assertStringContainsString( 'lf_category=5', $tabs['yesterday']['url'] );
	}

	/**
	 * カテゴリ未指定時（0または負数）は lf_category がURLに付与されないこと。
	 */
	public function test_get_date_tabs_data_without_category(): void {
		$tabs = luna_home_get_date_tabs_data( 'today', 0, 7, 'https://example.com/' );

		$this->assertStringNotContainsString( 'lf_category', $tabs['today']['url'] );
		$this->assertStringContainsString( 'lf_date=today', $tabs['today']['url'] );
	}

	/**
	 * 同一日や複数日に記事が存在する場合のフォールバック優先順位。
	 */
	public function test_multiple_dates_priority(): void {
		$ref = new DateTimeImmutable( '2026-11-12 12:00:00', new DateTimeZone( 'Asia/Tokyo' ) );

		// 今日の記事が最優先
		$this->assertSame( 'today', luna_home_resolve_active_date_tab( '', '2026-11-12 00:00:00', $ref ) );

		// 今日がなく昨日がある場合
		$this->assertSame( 'yesterday', luna_home_resolve_active_date_tab( '', '2026-11-11 23:59:59', $ref ) );

		// 昨日がなく一昨日がある場合
		$this->assertSame( 'two_days_ago', luna_home_resolve_active_date_tab( '', '2026-11-10 12:00:00', $ref ) );

		// 一昨日もなくそれ以前（3日前など）がある場合
		$this->assertSame( 'older', luna_home_resolve_active_date_tab( '', '2026-11-09 23:59:59', $ref ) );
	}

	/**
	 * WP_Query の date_query とカテゴリー絞込・タイムゾーン境界（00:00:00〜23:59:59）で漏れ・重複がないこと。
	 */
	public function test_date_tabs_wp_query_boundaries_and_category_filter(): void {
		$tz_orig = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Asia/Tokyo' );

		try {
			$ref = new DateTimeImmutable( '2026-11-12 00:05:00', new DateTimeZone( 'Asia/Tokyo' ) );

			$cat_tech = self::factory()->category->create( array( 'name' => 'TechTabs' ) );
			$cat_life = self::factory()->category->create( array( 'name' => 'LifeTabs' ) );

			$post_today_early = self::factory()->post->create(
				array(
					'post_status'   => 'publish',
					'post_date'     => '2026-11-12 00:00:00',
					'post_category' => array( $cat_tech ),
				)
			);
			$post_yesterday_late = self::factory()->post->create(
				array(
					'post_status'   => 'publish',
					'post_date'     => '2026-11-11 23:59:59',
					'post_category' => array( $cat_tech ),
				)
			);
			$post_two_days_early = self::factory()->post->create(
				array(
					'post_status'   => 'publish',
					'post_date'     => '2026-11-10 00:00:00',
					'post_category' => array( $cat_life ),
				)
			);
			$post_older_late = self::factory()->post->create(
				array(
					'post_status'   => 'publish',
					'post_date'     => '2026-11-09 23:59:59',
					'post_category' => array( $cat_tech ),
				)
			);

			$query_ids = static function ( string $tab, int $cat_id = 0 ) use ( $ref ): array {
				$q = new WP_Query(
					array(
						'post_type'      => 'post',
						'post_status'    => 'publish',
						'posts_per_page' => 20,
						'cat'            => $cat_id,
						'fields'         => 'ids',
						'date_query'     => luna_home_build_date_query( $tab, $ref ),
					)
				);
				return array_map( 'intval', $q->posts );
			};

			$this->assertContains( $post_today_early, $query_ids( 'today', $cat_tech ) );
			$this->assertNotContains( $post_yesterday_late, $query_ids( 'today', $cat_tech ) );

			$this->assertContains( $post_yesterday_late, $query_ids( 'yesterday', $cat_tech ) );
			$this->assertNotContains( $post_today_early, $query_ids( 'yesterday', $cat_tech ) );
			$this->assertNotContains( $post_two_days_early, $query_ids( 'yesterday', 0 ) );

			$this->assertContains( $post_two_days_early, $query_ids( 'two_days_ago', $cat_life ) );
			$this->assertNotContains( $post_two_days_early, $query_ids( 'two_days_ago', $cat_tech ) );
			$this->assertNotContains( $post_older_late, $query_ids( 'two_days_ago', 0 ) );

			$this->assertContains( $post_older_late, $query_ids( 'older', $cat_tech ) );
			$this->assertNotContains( $post_two_days_early, $query_ids( 'older', 0 ) );
		} finally {
			update_option( 'timezone_string', $tz_orig );
		}
	}

	/**
	 * home.php で人気記事期間切替リンク（$lf_popular_url）が選択中の lf_date を維持すること。
	 */
	public function test_home_template_preserves_lf_date_when_switching_popular_period(): void {
		$home = (string) file_get_contents( dirname( __DIR__ ) . '/home.php' );
		$this->assertStringContainsString( "\$lf_popular_base_args['lf_date'] = \$lf_date_requested;", $home );
	}
}
