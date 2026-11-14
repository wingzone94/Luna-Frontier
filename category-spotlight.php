<?php

declare(strict_types=1);
/**
 * 特集（SPOTLIGHT）カテゴリ専用アーカイブテンプレート
 *
 * @package Node
 */

get_header();
?>

<main id="primary" class="site-main m3-archive-layout">
	<?php node_the_breadcrumbs(); ?>

	<?php
	get_template_part(
		'template-parts/archive/header',
		null,
		array(
			'title'     => 'SPOTLIGHT',
			'subtitle'  => '特集',
			'header_id' => 'spotlight-archive-title',
			'type'      => 'category',
		)
	);

	$spotlight_cats = function_exists( 'node_get_spotlight_categories' ) ? node_get_spotlight_categories() : array();
	if ( ! empty( $spotlight_cats ) ) :
		?>
		<section class="m3-archive-spotlight-pills m3-surface m3-section-spacing" aria-labelledby="spotlight-pills-title">
			<h2 id="spotlight-pills-title" class="m3-archive-spotlight-pills__title">特集一覧</h2>
			<div class="special-features__pills">
				<?php foreach ( $spotlight_cats as $cat ) : ?>
					<a href="<?php echo esc_url( $cat['url'] ); ?>"
					   class="m3-spotlight-badge m3-ripple-host"
					   style="background-color: <?php echo esc_attr( $cat['color'] ); ?>; color: #ffffff;"
					   aria-label="<?php echo esc_attr( $cat['name'] ); ?>特集へ">
						<span class="material-symbols-outlined" data-icon="auto_awesome" aria-hidden="true"></span>
						<?php echo esc_html( $cat['name'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php
	get_template_part(
		'template-parts/archive/loop',
		null,
		array(
			'empty_message' => '現在、特集記事はありません。',
		)
	);
	?>

	<?php get_template_part( 'template-parts/archive/pagination' ); ?>
</main>

<?php
get_footer();
