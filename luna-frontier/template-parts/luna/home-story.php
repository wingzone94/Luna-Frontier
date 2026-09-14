<?php
/** @package LunaFrontier */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$lf_story = $args['story'] ?? null;
if ( ! $lf_story instanceof WP_Post ) {
	return;
}
$lf_variant = in_array( $args['variant'] ?? '', array( 'news', 'latest', 'popular' ), true ) ? $args['variant'] : 'latest';
$lf_terms = function_exists( 'node_get_post_categories_for_display' ) ? node_get_post_categories_for_display( $lf_story->ID ) : get_the_category( $lf_story->ID );
$lf_title = get_the_title( $lf_story );
$lf_title = '' !== trim( $lf_title ) ? $lf_title : __( 'タイトルなし', 'luna-frontier' );
// Keep summaries plain text, including articles containing block markup.
$lf_excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( get_the_excerpt( $lf_story ) ) ), 46, '…' );
// Category wayfinding color on the pill. White text stays; a face too light for white is toned down instead.
// Uncolored categories emit nothing so CSS keeps --lf-home-badge (not the parent's #FF9900 fallback).
$lf_category_style = '';
if ( $lf_terms && function_exists( 'node_get_category_label_props' ) && function_exists( 'luna_frontier_oklch_tone_meeting_contrast' ) ) {
	$lf_face = node_get_category_label_props( $lf_terms[0] )['data_color'];
	$lf_rgb  = '' !== $lf_face ? luna_frontier_hex_to_rgb( $lf_face ) : null;
	if ( null !== $lf_rgb ) {
		if ( luna_frontier_contrast_ratio( '#ffffff', $lf_face ) < 4.5 ) {
			$lf_oklch = luna_frontier_rgb_to_oklch( $lf_rgb );
			$lf_face  = luna_frontier_oklch_tone_meeting_contrast( $lf_oklch['h'], $lf_oklch['c'], $lf_oklch['l'], array( '#ffffff' ), 4.5 );
		}
		$lf_category_style = '--lf-home-category:' . $lf_face;
	}
}
?>
<article class="lf-home-story lf-home-story--<?php echo esc_attr( $lf_variant ); ?>">
	<a class="lf-home-story__link" href="<?php echo esc_url( get_permalink( $lf_story ) ); ?>">
		<?php if ( 'popular' === $lf_variant ) : ?><span class="lf-home-story__rank" aria-label="<?php echo esc_attr( sprintf( __( '%d位', 'luna-frontier' ), (int) $args['rank'] ) ); ?>"><?php echo (int) $args['rank']; ?></span><?php endif; ?>
		<div class="lf-home-story__visual">
			<?php if ( has_post_thumbnail( $lf_story ) ) : ?>
				<?php echo get_the_post_thumbnail( $lf_story, 'news' === $lf_variant ? 'medium_large' : 'medium', array( 'alt' => '', 'loading' => 'news' === $lf_variant ? 'eager' : 'lazy', 'sizes' => 'news' === $lf_variant ? '(max-width: 600px) 90vw, (max-width: 1000px) 45vw, 23vw' : '(max-width: 600px) 100px, 160px' ) ); ?>
			<?php else : ?>
				<span class="lf-home-story__placeholder material-symbols-outlined" aria-hidden="true">article</span>
			<?php endif; ?>
		</div>
		<div class="lf-home-story__body">
			<?php if ( 'popular' !== $lf_variant ) : ?>
			<div class="lf-home-story__meta">
				<?php if ( 'latest' === $lf_variant && $lf_terms ) : ?><span class="lf-home-story__category"<?php echo $lf_category_style ? ' style="' . esc_attr( $lf_category_style ) . '"' : ''; ?>><?php echo esc_html( $lf_terms[0]->name ); ?></span><?php endif; ?>
				<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C, $lf_story ) ); ?>"><?php echo esc_html( get_the_date( 'Y.m.d', $lf_story ) ); ?></time>
			</div>
			<?php endif; ?>
			<h3><?php echo esc_html( $lf_title ); ?></h3>
			<?php if ( 'popular' !== $lf_variant && $lf_excerpt ) : ?><p class="lf-home-story__excerpt"><?php echo esc_html( $lf_excerpt ); ?></p><?php endif; ?>
			<?php if ( 'news' === $lf_variant && $lf_terms ) : ?><span class="lf-home-story__category"<?php echo $lf_category_style ? ' style="' . esc_attr( $lf_category_style ) . '"' : ''; ?>><?php echo esc_html( $lf_terms[0]->name ); ?></span><?php endif; ?>
		</div>
	</a>
</article>
