<?php

declare(strict_types=1);
/**
 * Author archives share the editorial category archive layout.
 *
 * @package LunaFrontier
 */

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
