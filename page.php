<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 / SkyAlow — 固定ページ（Phase 6）
 *
 * 指示書 §27。
 * - PC は 2 カラム（Page Content / Page Navigation・Context）。
 * - 記事用 Reading Aside をそのまま流用しない。固定ページ向けの中身にする。
 * - 見出しがほとんど無い固定ページでは無理に 2 カラムを成立させない。
 *
 * @package LunaFrontier
 */

get_header();
?>

<main id="primary" class="site-main article-view">

	<?php
	while ( have_posts() ) :
		the_post();

		$lf_page_id  = get_the_ID();
		$lf_toc      = function_exists( 'node_get_article_toc_items' ) ? node_get_article_toc_items( $lf_page_id ) : array();
		$lf_siblings = get_pages(
			array(
				'parent'      => (int) get_post_field( 'post_parent', $lf_page_id ),
				'sort_column' => 'menu_order,post_title',
			)
		);
		if ( ! is_array( $lf_siblings ) ) {
			$lf_siblings = array();
		}

		// 見出しが 3 つ未満なら 2 カラムにしない（§27）。
		$lf_has_aside = ( count( $lf_toc ) >= 3 ) || ( count( $lf_siblings ) > 1 );
		?>

		<article id="post-<?php the_ID(); ?>" <?php post_class( 'm3-article m3-page-template' . ( $lf_has_aside ? ' lf-page--has-aside' : '' ) ); ?>>

			<header class="lf-page-header">
				<?php if ( has_post_thumbnail() ) : ?>
					<div class="lf-page-header__media"><?php the_post_thumbnail( 'large', array( 'class' => 'lf-page-header__image' ) ); ?></div>
				<?php endif; ?>
				<div class="lf-page-header__inner">
					<h1 class="lf-page-header__title"><?php the_title(); ?></h1>
					<p class="lf-page-header__meta">
						<span class="lf-meta__label">最終更新</span>
						<time datetime="<?php echo esc_attr( get_the_modified_date( 'c' ) ); ?>"><?php echo esc_html( get_the_modified_date( '' ) ); ?></time>
					</p>
				</div>
			</header>

			<?php if ( $lf_has_aside ) : ?>
				<aside class="lf-page-aside" aria-label="このページの案内">
					<?php if ( count( $lf_toc ) >= 3 ) : ?>
						<nav class="lf-aside-block lf-aside-block--toc" aria-labelledby="lf-page-toc-title">
							<h2 class="lf-aside-block__title lf-system-label" id="lf-page-toc-title">On this page</h2>
							<ol class="lf-toc">
								<?php foreach ( $lf_toc as $lf_item ) : ?>
									<li class="lf-toc__item" data-level="<?php echo esc_attr( (string) $lf_item['level'] ); ?>">
										<a class="lf-toc__link" href="#<?php echo esc_attr( ltrim( (string) $lf_item['id'], '#' ) ); ?>"><?php echo esc_html( (string) $lf_item['text'] ); ?></a>
									</li>
								<?php endforeach; ?>
							</ol>
						</nav>
					<?php endif; ?>

					<?php if ( count( $lf_siblings ) > 1 ) : ?>
						<nav class="lf-aside-block" aria-labelledby="lf-page-nav-title">
							<h2 class="lf-aside-block__title lf-system-label" id="lf-page-nav-title">Pages</h2>
							<ul class="lf-aside-list">
								<?php foreach ( $lf_siblings as $lf_sibling ) : ?>
									<li class="lf-aside-list__item<?php echo ( (int) $lf_sibling->ID === (int) $lf_page_id ) ? ' is-current' : ''; ?>">
										<?php if ( (int) $lf_sibling->ID === (int) $lf_page_id ) : ?>
											<span aria-current="page"><?php echo esc_html( get_the_title( $lf_sibling ) ); ?></span>
										<?php else : ?>
											<a href="<?php echo esc_url( (string) get_permalink( $lf_sibling ) ); ?>"><?php echo esc_html( get_the_title( $lf_sibling ) ); ?></a>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</nav>
					<?php endif; ?>
				</aside>
			<?php endif; ?>

			<div class="m3-article__body entry-content">
				<?php the_content(); ?>

				<?php
				wp_link_pages(
					array(
						'before'      => '<nav class="m3-navigation split-post-navigation"><div class="nav-links">',
						'after'       => '</div></nav>',
						'link_before' => '<span class="page-numbers">',
						'link_after'  => '</span>',
						'separator'   => '',
					)
				);
				?>
			</div>

		</article>

	<?php endwhile; ?>

</main>

<?php get_footer(); ?>
