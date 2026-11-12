<?php
/**
 * Luna Frontier 2.0 — トップページ新着記事の日付別タブ機能
 *
 * @package LunaFrontier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 日付タブの定義一覧を取得する。
 *
 * @return array<string, string> タブキー => ラベル
 */
function luna_home_date_tab_definitions(): array {
	return array(
		'today'        => __( '今日', 'node' ),
		'yesterday'    => __( '昨日', 'node' ),
		'two_days_ago' => __( '一昨日', 'node' ),
		'older'        => __( '以前', 'node' ),
	);
}

/**
 * サイトタイムゾーンに基づく日付境界を計算する。
 *
 * @param DateTimeInterface|null $reference_time 基準日時（未指定時は現在日時）。
 * @return array{
 *     now: DateTimeImmutable,
 *     today_start: DateTimeImmutable,
 *     today_ymd: string,
 *     yesterday_start: DateTimeImmutable,
 *     yesterday_ymd: string,
 *     two_days_ago_start: DateTimeImmutable,
 *     two_days_ago_ymd: string
 * }
 */
function luna_home_calculate_date_bounds( ?DateTimeInterface $reference_time = null ): array {
	if ( null === $reference_time ) {
		$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now = function_exists( 'current_datetime' ) ? current_datetime() : new DateTimeImmutable( 'now', $tz );
	} else {
		$now = $reference_time instanceof DateTimeImmutable ? $reference_time : DateTimeImmutable::createFromMutable( $reference_time );
	}

	$today_start        = $now->setTime( 0, 0, 0 );
	$yesterday_start    = $now->modify( '-1 day' )->setTime( 0, 0, 0 );
	$two_days_ago_start = $now->modify( '-2 days' )->setTime( 0, 0, 0 );

	return array(
		'now'                => $now,
		'today_start'        => $today_start,
		'today_ymd'          => $today_start->format( 'Y-m-d' ),
		'yesterday_start'    => $yesterday_start,
		'yesterday_ymd'      => $yesterday_start->format( 'Y-m-d' ),
		'two_days_ago_start' => $two_days_ago_start,
		'two_days_ago_ymd'   => $two_days_ago_start->format( 'Y-m-d' ),
	);
}

/**
 * リクエストされたタブまたは最新記事の公開日に応じてアクティブなタブを解決する。
 *
 * 表示ルール:
 * 1. 明示的に指定された有効なタブがあればそれを優先する。
 * 2. 未指定または無効な場合、最新記事の公開日（サイトタイムゾーン）を確認し、
 *    存在する最新の日付へ自動的に切り替える（今日 -> 昨日 -> 一昨日 -> 以前）。
 * 3. 記事が存在しない場合は初期値として 'today' を返す。
 *
 * @param string                 $requested_tab    リクエストされたタブキー。
 * @param string|null            $newest_post_date 最新記事の公開日時（Y-m-d H:i:s）。
 * @param DateTimeInterface|null $reference_time  基準日時（テスト用）。
 * @return string アクティブなタブキー ('today'|'yesterday'|'two_days_ago'|'older')
 */
function luna_home_resolve_active_date_tab( string $requested_tab, ?string $newest_post_date = null, ?DateTimeInterface $reference_time = null ): string {
	$definitions = luna_home_date_tab_definitions();

	if ( '' !== $requested_tab && isset( $definitions[ $requested_tab ] ) ) {
		return $requested_tab;
	}

	if ( empty( $newest_post_date ) ) {
		return 'today';
	}

	$bounds   = luna_home_calculate_date_bounds( $reference_time );
	$post_ymd = substr( $newest_post_date, 0, 10 );

	if ( $post_ymd === $bounds['today_ymd'] ) {
		return 'today';
	}
	if ( $post_ymd === $bounds['yesterday_ymd'] ) {
		return 'yesterday';
	}
	if ( $post_ymd === $bounds['two_days_ago_ymd'] ) {
		return 'two_days_ago';
	}

	return 'older';
}

/**
 * 選択された日付タブに応じた WordPress date_query 配列を構築する。
 *
 * @param string                 $active_tab     アクティブなタブキー。
 * @param DateTimeInterface|null $reference_time 基準日時（テスト用）。
 * @return array WP_Query 用の date_query 配列。
 */
function luna_home_build_date_query( string $active_tab, ?DateTimeInterface $reference_time = null ): array {
	$bounds = luna_home_calculate_date_bounds( $reference_time );

	switch ( $active_tab ) {
		case 'today':
			return array(
				array(
					'year'  => (int) $bounds['today_start']->format( 'Y' ),
					'month' => (int) $bounds['today_start']->format( 'm' ),
					'day'   => (int) $bounds['today_start']->format( 'd' ),
				),
			);
		case 'yesterday':
			return array(
				array(
					'year'  => (int) $bounds['yesterday_start']->format( 'Y' ),
					'month' => (int) $bounds['yesterday_start']->format( 'm' ),
					'day'   => (int) $bounds['yesterday_start']->format( 'd' ),
				),
			);
		case 'two_days_ago':
			return array(
				array(
					'year'  => (int) $bounds['two_days_ago_start']->format( 'Y' ),
					'month' => (int) $bounds['two_days_ago_start']->format( 'm' ),
					'day'   => (int) $bounds['two_days_ago_start']->format( 'd' ),
				),
			);
		case 'older':
			return array(
				array(
					'before'    => $bounds['two_days_ago_start']->format( 'Y-m-d 00:00:00' ),
					'inclusive' => false,
				),
			);
		default:
			return array();
	}
}

/**
 * 日付タブ一覧表示用のナビゲーションデータを生成する。
 *
 * @param string      $active_date       選択中の日付キー。
 * @param int         $selected_category 選択中のカテゴリーID。
 * @param int         $period            人気記事期間。
 * @param string|null $home_url          ホームURL。
 * @return array<string, array{key: string, label: string, url: string, is_active: bool}>
 */
function luna_home_get_date_tabs_data( string $active_date, int $selected_category = 0, int $period = 7, ?string $home_url = null ): array {
	$definitions = luna_home_date_tab_definitions();
	$base_url    = $home_url ?: ( get_option( 'page_for_posts' ) ? get_permalink( (int) get_option( 'page_for_posts' ) ) : home_url( '/' ) );
	$tabs_data   = array();

	foreach ( $definitions as $tab_key => $tab_label ) {
		$query_args = array( 'lf_date' => $tab_key );
		if ( $selected_category > 0 ) {
			$query_args['lf_category'] = $selected_category;
		}
		if ( 30 === $period ) {
			$query_args['lf_period'] = 30;
		}

		$tabs_data[ $tab_key ] = array(
			'key'       => $tab_key,
			'label'     => $tab_label,
			'url'       => add_query_arg( $query_args, $base_url ) . '#latest',
			'is_active' => $tab_key === $active_date,
		);
	}

	return $tabs_data;
}
