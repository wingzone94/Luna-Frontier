<?php
/**
 * Editorial home: breaking news, latest stories and discovery.
 * Paged blog archives retain their existing template and pagination.
 *
 * @package LunaFrontier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( is_paged() ) {
	require get_stylesheet_directory() . '/index.php';
	return;
}

$lf_categories = get_categories( array( 'parent' => 0, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 6, 'exclude' => array( (int) get_option( 'default_category' ) ) ) );
$lf_category_ids = wp_list_pluck( array_slice( $lf_categories, 0, 4 ), 'term_id' );
// This parameter only filters the home list; it does not alter archive queries.
$lf_selected = isset( $_GET['lf_category'] ) && is_scalar( $_GET['lf_category'] ) ? absint( wp_unslash( $_GET['lf_category'] ) ) : 0;
$lf_selected = in_array( $lf_selected, $lf_category_ids, true ) ? $lf_selected : 0;
$lf_home_url = get_option( 'page_for_posts' ) ? get_permalink( (int) get_option( 'page_for_posts' ) ) : home_url( '/' );
$lf_all_url = function_exists( 'node_get_all_articles_url' ) ? node_get_all_articles_url() : get_pagenum_link( 2 );
$lf_more_url = $lf_selected ? get_category_link( $lf_selected ) : $lf_all_url;

// 日付別タブの解決（初期状態は「今日」。今日の記事が0件なら最新記事のある日付へ自動切り替え）
$lf_valid_dates     = array_keys( function_exists( 'luna_home_date_tab_definitions' ) ? luna_home_date_tab_definitions() : array( 'today' => '', 'yesterday' => '', 'two_days_ago' => '', 'older' => '' ) );
$lf_date_requested  = isset( $_GET['lf_date'] ) && is_string( $_GET['lf_date'] ) ? sanitize_key( wp_unslash( $_GET['lf_date'] ) ) : '';
$lf_date_requested  = in_array( $lf_date_requested, $lf_valid_dates, true ) ? $lf_date_requested : '';

$lf_newest_date_str = null;
if ( '' === $lf_date_requested ) {
	$lf_anchor_posts = get_posts( array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'posts_per_page'         => 1,
		'ignore_sticky_posts'    => true,
		'category'               => $lf_selected,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );
	if ( ! empty( $lf_anchor_posts ) ) {
		$lf_newest_date_str = $lf_anchor_posts[0]->post_date;
	}
}

$lf_active_date = function_exists( 'luna_home_resolve_active_date_tab' )
	? luna_home_resolve_active_date_tab( $lf_date_requested, $lf_newest_date_str )
	: ( $lf_date_requested ?: 'today' );

// 選択されたタブに応じた date_query で新着記事を取得
$lf_latest_query_args = array(
	'post_type'           => 'post',
	'post_status'         => 'publish',
	'posts_per_page'      => 6,
	'ignore_sticky_posts' => true,
	'category'            => $lf_selected,
	'orderby'             => array( 'date' => 'DESC', 'ID' => 'DESC' ),
);
$lf_date_query = function_exists( 'luna_home_build_date_query' ) ? luna_home_build_date_query( $lf_active_date ) : array();
if ( ! empty( $lf_date_query ) ) {
	$lf_latest_query_args['date_query'] = $lf_date_query;
}
$lf_latest = get_posts( $lf_latest_query_args );

$lf_news_category = function_exists( 'node_get_news_category' ) ? node_get_news_category() : get_category_by_slug( 'news' );
// News is chronological: sticky posts and thumbnail availability never change order.
$lf_news = $lf_news_category instanceof WP_Term ? get_posts( array(
	'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 4,
	'cat' => $lf_news_category->term_id, 'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ),
	'ignore_sticky_posts' => true,
) ) : array();
$lf_period = isset( $_GET['lf_period'] ) && is_string( $_GET['lf_period'] ) && '30' === wp_unslash( $_GET['lf_period'] ) ? 30 : 7;
$lf_popular = function_exists( 'node_get_popular_posts_for_period' ) ? node_get_popular_posts_for_period( $lf_period ) : array();
$lf_popular_base_args = array();
if ( $lf_selected ) {
	$lf_popular_base_args['lf_category'] = $lf_selected;
}
if ( '' !== $lf_date_requested ) {
	$lf_popular_base_args['lf_date'] = $lf_date_requested;
}
$lf_popular_url = $lf_popular_base_args ? add_query_arg( $lf_popular_base_args, $lf_home_url ) : $lf_home_url;

$lf_filter_base_args = array( 'lf_period' => $lf_period );
if ( '' !== $lf_date_requested ) {
	$lf_filter_base_args['lf_date'] = $lf_date_requested;
}
$lf_filter_url = add_query_arg( $lf_filter_base_args, $lf_home_url );
$lf_date_tabs  = function_exists( 'luna_home_get_date_tabs_data' )
	? luna_home_get_date_tabs_data( $lf_active_date, $lf_selected, $lf_period, $lf_home_url )
	: array();

get_header();
?>
<main id="primary" class="site-main lf-home">
	<h1 class="screen-reader-text"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
	<?php get_template_part( 'template-parts/hero-slider' ); ?>
	<section class="lf-home__news" aria-labelledby="lf-news-title">
		<div class="lf-home__section-head">
			<h2 id="lf-news-title"><span class="material-symbols-outlined" aria-hidden="true">campaign</span><?php esc_html_e( '速報', 'node' ); ?></h2>
			<p class="lf-home__intro"><?php esc_html_e( '最新のニュースをお届け', 'node' ); ?></p>
			<?php if ( $lf_news_category instanceof WP_Term ) : ?>
			<a class="lf-home__more" href="<?php echo esc_url( get_category_link( $lf_news_category ) ); ?>"><?php esc_html_e( 'ニュース一覧を見る', 'node' ); ?><span aria-hidden="true">→</span></a>
			<?php endif; ?>
		</div>
		<div class="lf-home__news-grid">
			<?php foreach ( $lf_news as $lf_story ) { get_template_part( 'template-parts/luna/home-story', null, array( 'story' => $lf_story, 'variant' => 'news' ) ); } ?>
		</div>
		<?php if ( ! $lf_news ) : ?><p class="lf-home__empty"><?php esc_html_e( 'ニュース記事がまだありません。', 'node' ); ?></p><?php endif; ?>
	</section>

	<div class="lf-home__columns">
		<section id="latest" class="lf-home__latest" aria-labelledby="lf-latest-title">
			<div class="lf-home__section-head">
				<h2 id="lf-latest-title"><?php esc_html_e( '新着記事', 'node' ); ?></h2>
				<nav class="lf-home__filters" aria-label="<?php esc_attr_e( '新着記事のカテゴリー', 'node' ); ?>">
					<a href="<?php echo esc_url( remove_query_arg( 'lf_category', $lf_filter_url ) . '#latest' ); ?>"<?php echo 0 === $lf_selected ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'すべて', 'node' ); ?></a>
					<?php foreach ( array_slice( $lf_categories, 0, 4 ) as $lf_category ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'lf_category', $lf_category->term_id, $lf_filter_url ) . '#latest' ); ?>"<?php echo $lf_selected === $lf_category->term_id ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $lf_category->name ); ?></a>
					<?php endforeach; ?>
				</nav>
				<a class="lf-home__more" href="<?php echo esc_url( $lf_more_url ); ?>"><?php esc_html_e( 'もっと見る', 'node' ); ?><span aria-hidden="true">→</span></a>
			</div>
			<?php if ( ! empty( $lf_date_tabs ) ) : ?>
			<nav class="lf-home__date-tabs" aria-label="<?php esc_attr_e( '新着記事の公開日', 'node' ); ?>">
				<div class="lf-home__date-tabs-track" role="list">
					<?php foreach ( $lf_date_tabs as $lf_tab_key => $lf_tab_item ) : ?>
					<div class="lf-home__date-tab-item" role="listitem">
						<a class="lf-home__date-tab<?php echo $lf_tab_item['is_active'] ? ' is-active' : ''; ?>"
						   href="<?php echo esc_url( $lf_tab_item['url'] ); ?>"
						   <?php echo $lf_tab_item['is_active'] ? ' aria-current="page"' : ''; ?>>
							<?php echo esc_html( $lf_tab_item['label'] ); ?>
						</a>
					</div>
					<?php endforeach; ?>
				</div>
			</nav>
			<?php endif; ?>
			<div class="lf-home__story-list">
				<?php foreach ( $lf_latest as $lf_story ) { get_template_part( 'template-parts/luna/home-story', null, array( 'story' => $lf_story, 'variant' => 'latest' ) ); } ?>
				<?php if ( empty( $lf_latest ) ) : ?>
				<div class="lf-home__empty-state">
					<span class="material-symbols-outlined lf-home__empty-icon" aria-hidden="true">calendar_today</span>
					<p class="lf-home__empty-text">
						<?php
						$lf_tab_label = $lf_date_tabs[ $lf_active_date ]['label'] ?? __( '指定された期間', 'node' );
						echo esc_html( sprintf( __( '「%s」の記事はまだありません。', 'node' ), $lf_tab_label ) );
						?>
					</p>
					<?php if ( ! empty( $lf_date_tabs ) ) : ?>
					<div class="lf-home__empty-nav">
						<span class="lf-home__empty-nav-label"><?php esc_html_e( '他の日付を見る：', 'node' ); ?></span>
						<div class="lf-home__empty-nav-links">
							<?php foreach ( $lf_date_tabs as $lf_other_key => $lf_other_tab ) : ?>
								<?php if ( $lf_other_key !== $lf_active_date ) : ?>
								<a class="lf-home__empty-link" href="<?php echo esc_url( $lf_other_tab['url'] ); ?>">
									<?php echo esc_html( $lf_other_tab['label'] ); ?>
								</a>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			</div>
			<?php if ( 'older' === $lf_active_date || count( $lf_latest ) >= 6 ) : ?>
			<div class="lf-home__list-footer">
				<a class="lf-home__more-button" href="<?php echo esc_url( $lf_more_url ); ?>">
					<span><?php esc_html_e( '過去の記事をもっと見る', 'node' ); ?></span>
					<span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
				</a>
			</div>
			<?php endif; ?>
		</section>

		<aside class="lf-home__aside" aria-label="記事を探す">
			<section id="popular" class="lf-home__panel" aria-labelledby="lf-popular-title">
				<div class="lf-home__section-head">
					<h2 id="lf-popular-title"><span class="material-symbols-outlined" aria-hidden="true">local_fire_department</span><?php esc_html_e( '人気記事', 'node' ); ?></h2>
					<nav class="lf-home__period" aria-label="<?php esc_attr_e( '人気記事の集計期間', 'node' ); ?>">
						<?php foreach ( array( 7 => __( '過去7日間', 'node' ), 30 => __( '過去30日間', 'node' ) ) as $lf_days => $lf_label ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'lf_period', $lf_days, $lf_popular_url ) . '#popular' ); ?>"<?php echo $lf_period === $lf_days ? ' aria-current="true"' : ''; ?>><span><?php echo esc_html( $lf_label ); ?></span></a>
						<?php endforeach; ?>
					</nav>
				</div>
				<ol class="lf-home__ranking">
					<?php foreach ( $lf_popular as $lf_rank => $lf_story ) : ?>
					<li><?php get_template_part( 'template-parts/luna/home-story', null, array( 'story' => $lf_story, 'variant' => 'popular', 'rank' => $lf_rank + 1 ) ); ?></li>
					<?php endforeach; ?>
				</ol>
				<?php if ( ! $lf_popular ) : ?><p class="lf-home__empty"><?php esc_html_e( '閲覧データが集まると、人気記事を表示します。', 'node' ); ?></p><?php endif; ?>
			</section>
			<?php if ( $lf_categories ) : ?>
			<section class="lf-home__panel" aria-labelledby="lf-categories-title">
				<div class="lf-home__section-head"><h2 id="lf-categories-title"><?php esc_html_e( 'カテゴリー', 'node' ); ?></h2></div>
				<form class="lf-home__category-form" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
					<label class="screen-reader-text" for="lf-home-category"><?php esc_html_e( 'カテゴリーを選択', 'node' ); ?></label>
					<?php wp_dropdown_categories( array(
						'name' => 'cat', 'id' => 'lf-home-category', 'hierarchical' => true,
						'hide_empty' => true, 'orderby' => 'name', 'show_option_none' => __( 'カテゴリーを選択', 'node' ),
						'option_none_value' => '0', 'selected' => 0,
						'exclude_tree' => ( get_category_by_slug( 'spotlight' ) ?: (object) array( 'term_id' => 0 ) )->term_id,
					) ); ?>
					<button type="submit" aria-label="<?php esc_attr_e( '選択したカテゴリーを表示', 'node' ); ?>"><span class="material-symbols-outlined" aria-hidden="true">send</span></button>
				</form>
			</section>
			<?php endif; ?>
			<section class="lf-home__panel lf-home__calendar" aria-labelledby="lf-calendar-title">
				<div class="lf-home__section-head"><h2 id="lf-calendar-title"><?php esc_html_e( 'カレンダー', 'node' ); ?></h2></div>
				<?php get_template_part( 'template-parts/luna/home-calendar', null, array( 'home_url' => $lf_home_url, 'category' => $lf_selected ) ); ?>
			</section>
		</aside>
	</div>
</main>
<?php get_footer(); ?>
