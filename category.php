<?php

declare(strict_types=1);
/**
 * Editorial topic categories use image-first archive cards.
 *
 * @package LunaFrontier
 */

$topic = get_queried_object();
if ( ! $topic instanceof WP_Term || ! in_array( rawurldecode( $topic->slug ), array( 'ai生成', 'ゲーム', 'ガジェット', 'ニュース' ), true ) ) {
	require get_template_directory() . '/archive.php';
	return;
}

get_header();
?>
<main id="primary" class="site-main m3-archive-layout lf-topic-archive">
	<?php node_the_breadcrumbs(); ?>
	<?php get_template_part( 'template-parts/archive/header' ); ?>
	<?php
	get_template_part(
		'template-parts/archive/loop',
		null,
		array(
			'list_class' => '',
			'grid_extra_class' => 'lf-topic-archive__grid',
		)
	);
	get_template_part( 'template-parts/archive/pagination' );
	?>
</main>
<?php get_footer(); ?>
