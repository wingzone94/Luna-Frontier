<?php

declare(strict_types=1);
$paged          = max( 1, (int) get_query_var( 'paged' ) );
$articles_query = new WP_Query( node_get_all_articles_page_query_args( $paged ) );
// 表示件数と総ページ数は、この一覧クエリの found_posts に合わせる。
$total_published = (int) $articles_query->found_posts;
$total_pages     = node_get_all_articles_total_pages( $total_published );

if ( $paged > $total_pages ) {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
	include get_404_template();
	exit;
}

get_header();

$archive_base = trailingslashit( node_get_all_articles_url() );
?>

<main id="primary" class="site-main m3-all-articles-layout">
	<?php node_the_breadcrumbs(); ?>

	<section class="m3-archive-header m3-surface m3-section-spacing" aria-labelledby="all-articles-title">
		<div class="m3-headlines__header" style="border-bottom: none; margin-bottom: 0;">
			<h1 id="all-articles-title" class="m3-headlines__title m3-section-title" style="margin-bottom: 0;">
				<span class="material-symbols-outlined" aria-hidden="true" style="font-size: 1.2em; vertical-align: middle;">article</span>
				ALL ARTICLES <span class="m3-section-title__sub">記事一覧</span>
			</h1>
		</div>
		<p class="m3-archive-header__desc" style="margin-top: 16px; color: var(--md-sys-color-on-surface-variant);">
			公開記事 <?php echo esc_html( number_format_i18n( $total_published ) ); ?> 件
		</p>
	</section>

	<div class="l-card-grid m3-post-grid">
		<?php if ( $articles_query->have_posts() ) : ?>
			<div class="l-card-grid__items l-card-grid--list l-card-grid--two-column-list m3-post-grid__container m3-post-grid--list m3-post-grid--2col-list">
				<?php
				while ( $articles_query->have_posts() ) :
					$articles_query->the_post();
					get_template_part(
						'template-parts/card',
						null,
						array(
							'card_class' => 'card-standard',
						)
					);
				endwhile;
				wp_reset_postdata();
				?>
			</div>
		<?php else : ?>
			<div class="m3-no-results">
				<p>現在、該当する記事はありません。</p>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $total_pages > 1 ) : ?>
		<nav class="m3-pager" aria-label="ページナビゲーション">
			<?php if ( $paged > 1 ) : ?>
				<a href="<?php echo esc_url( $archive_base . ( $paged > 2 ? 'page/' . ( $paged - 1 ) . '/' : '' ) ); ?>" class="m3-pager__btn">
					<span class="material-symbols-outlined">arrow_back</span>
					前のページ
				</a>
			<?php else : ?>
				<span class="m3-pager__btn m3-pager__btn--disabled" aria-hidden="true">
					<span class="material-symbols-outlined">arrow_back</span>
					前のページ
				</span>
			<?php endif; ?>

			<span class="m3-pager__indicator"><?php echo esc_html( $paged ); ?> / <?php echo esc_html( $total_pages ); ?></span>

			<?php if ( $paged < $total_pages ) : ?>
				<a href="<?php echo esc_url( $archive_base . 'page/' . ( $paged + 1 ) . '/' ); ?>" class="m3-pager__btn">
					次のページ
					<span class="material-symbols-outlined">arrow_forward</span>
				</a>
			<?php else : ?>
				<span class="m3-pager__btn m3-pager__btn--disabled" aria-hidden="true">
					次のページ
					<span class="material-symbols-outlined">arrow_forward</span>
				</span>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
</main>

<?php get_footer(); ?>
