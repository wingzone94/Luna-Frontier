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
	require get_template_directory() . '/index.php';
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
$lf_latest = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 5, 'ignore_sticky_posts' => true, 'category' => $lf_selected ) );
$lf_news_category = function_exists( 'node_get_news_category' ) ? node_get_news_category() : get_category_by_slug( 'news' );
// News is chronological: sticky posts and thumbnail availability never change order.
$lf_news = $lf_news_category instanceof WP_Term ? get_posts( array(
	'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 4,
	'cat' => $lf_news_category->term_id, 'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ),
	'ignore_sticky_posts' => true,
) ) : array();
$lf_popular = defined( 'NODE_HOT_SCORE_META' ) ? get_posts( array(
	'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 5, 'ignore_sticky_posts' => true,
	'meta_key' => NODE_HOT_SCORE_META, 'orderby' => array( 'meta_value_num' => 'DESC', 'date' => 'DESC' ),
	'meta_query' => array( array( 'key' => NODE_HOT_SCORE_META, 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ) ),
) ) : array();

get_header();
?>
<main id="primary" class="site-main lf-home">
	<h1 class="screen-reader-text"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
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
				<nav class="lf-home__filters" aria-label="新着記事のカテゴリー">
					<a href="<?php echo esc_url( $lf_home_url . '#latest' ); ?>"<?php echo 0 === $lf_selected ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'すべて', 'node' ); ?></a>
					<?php foreach ( array_slice( $lf_categories, 0, 4 ) as $lf_category ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'lf_category', $lf_category->term_id, $lf_home_url ) . '#latest' ); ?>"<?php echo $lf_selected === $lf_category->term_id ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $lf_category->name ); ?></a>
					<?php endforeach; ?>
				</nav>
				<a class="lf-home__more" href="<?php echo esc_url( $lf_more_url ); ?>"><?php esc_html_e( 'もっと見る', 'node' ); ?><span aria-hidden="true">→</span></a>
			</div>
			<div class="lf-home__story-list">
				<?php foreach ( $lf_latest as $lf_story ) { get_template_part( 'template-parts/luna/home-story', null, array( 'story' => $lf_story, 'variant' => 'latest' ) ); } ?>
				<?php if ( ! $lf_latest ) : ?><p class="lf-home__empty"><?php esc_html_e( '記事がまだありません。', 'node' ); ?></p><?php endif; ?>
			</div>
		</section>

		<aside class="lf-home__aside" aria-label="記事を探す">
			<section class="lf-home__panel" aria-labelledby="lf-popular-title">
				<div class="lf-home__section-head">
					<h2 id="lf-popular-title"><span class="material-symbols-outlined" aria-hidden="true">local_fire_department</span><?php esc_html_e( '人気記事', 'node' ); ?></h2>
					<span class="lf-home__period"><?php esc_html_e( '過去7日間', 'node' ); ?></span>
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
					<button type="submit"><?php esc_html_e( '表示', 'node' ); ?></button>
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
