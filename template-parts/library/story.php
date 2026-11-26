<?php
/** A linked article; the parent template owns its ordering and disclosure. */
$story = $args['post'];
?>
<a class="library-story" href="<?php echo esc_url( get_permalink( $story ) ); ?>">
	<?php get_template_part( 'template-parts/library/visual', null, array( 'id' => $story->ID, 'size' => 'medium' ) ); ?>
	<span><strong><?php echo esc_html( get_the_title( $story ) ); ?></strong><small><?php echo esc_html( get_the_date( 'Y.m.d', $story ) ); ?></small></span>
</a>
