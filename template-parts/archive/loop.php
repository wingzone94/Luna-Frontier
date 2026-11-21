<?php

declare(strict_types=1);
/**
 * Archive post loop.
 *
 * @package Node
 *
 * @var array $args Template arguments.
 */

$card_template    = isset( $args['card_template'] ) ? $args['card_template'] : 'template-parts/card';
$empty_message    = isset( $args['empty_message'] ) ? $args['empty_message'] : __( '現在、該当する記事はありません。', 'node' );
$list_class       = isset( $args['list_class'] ) ? $args['list_class'] : 'm3-post-grid--list';
$featured_first   = ! empty( $args['featured_first'] );
$wrapper_class    = isset( $args['wrapper_class'] ) ? $args['wrapper_class'] : '';
$grid_extra_class = isset( $args['grid_extra_class'] ) ? $args['grid_extra_class'] : '';
$feed_label       = isset( $args['feed_label'] ) ? $args['feed_label'] : __( '投稿一覧', 'node' );
$grid_bem_classes = array( 'l-card-grid__items' );

if ( false !== strpos( $list_class, 'm3-post-grid--list' ) ) {
	$grid_bem_classes[] = 'l-card-grid--list';
}

if ( false !== strpos( $list_class, 'm3-post-grid--2col-list' ) ) {
	$grid_bem_classes[] = 'l-card-grid--two-column-list';
}
?>
<section class="m3-archive-feed <?php echo esc_attr( $wrapper_class ); ?>" aria-label="<?php echo esc_attr( $feed_label ); ?>">
	<?php if ( have_posts() ) : ?>
		<div class="l-card-grid m3-post-grid m3-archive-post-grid">
			<div class="<?php echo esc_attr( implode( ' ', $grid_bem_classes ) ); ?> m3-post-grid__container m3-archive-post-grid__cards <?php echo esc_attr( trim( $list_class . ' ' . $grid_extra_class ) ); ?>">
				<?php
				while ( have_posts() ) :
					the_post();
					$card_args = array(
						'card_class' => 'card-standard',
					);
					if ( $wp_query->current_post < 6 ) {
						$card_args['image_loading'] = 'eager';
						if ( $wp_query->current_post < 3 ) {
							$card_args['image_fetchpriority'] = 'high';
						}
					}
					if ( $featured_first && $wp_query->current_post < 4 && ! is_paged() ) {
						$card_args['card_class'] = 'card-featured';
					}
					get_template_part( $card_template, null, $card_args );
				endwhile;
				?>
			</div>
		</div>
	<?php else : ?>
		<div class="m3-no-results m3-archive-no-results">
			<div class="m3-no-results__inner">
				<div class="m3-no-results__icon">
					<span class="material-symbols-outlined" data-icon="inbox"></span>
				</div>
				<p class="m3-no-results__text"><?php echo esc_html( $empty_message ); ?></p>
			</div>
		</div>
	<?php endif; ?>
</section>
