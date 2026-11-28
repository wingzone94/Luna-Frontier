<?php
/** A linked article; the parent template owns its ordering and disclosure. */
$story = $args['post'];
ob_start();
get_template_part( 'template-parts/library/visual', null, array( 'id' => $story->ID, 'size' => 'medium' ) );
$visual_html = trim( (string) ob_get_clean() );
?>
<a class="library-story<?php echo '' !== $visual_html ? ' has-visual' : ''; ?>" href="<?php echo esc_url( get_permalink( $story ) ); ?>">
	<?php echo $visual_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered visual template part. ?>
	<span><strong><?php echo esc_html( get_the_title( $story ) ); ?></strong><small><?php echo esc_html( get_the_date( 'Y.m.d', $story ) ); ?></small></span>
</a>
