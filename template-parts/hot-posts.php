<?php

declare(strict_types=1);
/**
 * HOT posts section for the home page.
 *
 * @package Node
 */

$hot_posts = function_exists( 'node_get_hot_posts' ) ? node_get_hot_posts( 3 ) : array();

if ( empty( $hot_posts ) ) {
	return;
}

$hot_ids = array_map(
	static fn( $post ): int => $post instanceof WP_Post ? (int) $post->ID : 0,
	$hot_posts
);
$hot_ids = array_values( array_filter( $hot_ids ) );

if ( function_exists( 'node_home_register_featured_post_ids' ) ) {
	node_home_register_featured_post_ids( $hot_ids );
}
?>
<section id="hot" class="m3-hot-posts m3-surface m3-section-spacing" aria-labelledby="hot-title">
	<div class="m3-headlines__header m3-hot-posts__header">
		<h2 id="hot-title" class="m3-section-title m3-headlines__title">
			<span class="material-symbols-outlined" data-icon="whatshot" aria-hidden="true"></span>
			HOT <span class="m3-section-title__sub">よく読まれています</span>
		</h2>
	</div>

	<div class="m3-hot-posts__grid">
		<?php
		foreach ( $hot_posts as $post ) :
			setup_postdata( $post );
			get_template_part(
				'template-parts/card',
				null,
				array(
					'card_class' => 'card-standard m3-hot-card',
				)
			);
		endforeach;
		wp_reset_postdata();
		?>
	</div>
</section>
