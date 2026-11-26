<?php
/** Library artwork stored in WordPress, with a decorative title fallback. */
$id        = (int) $args['id'];
$size      = $args['size'] ?? 'medium_large';
$thumbnail = get_the_post_thumbnail( $id, $size, array( 'alt' => '' ) );
$image_url = (string) get_post_meta( $id, '_node_library_image_url', true );
?>
<figure class="library-visual" aria-hidden="true">
	<?php if ( $thumbnail ) : ?>
		<?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core image markup. ?>
	<?php elseif ( $image_url && wp_parse_url( home_url(), PHP_URL_HOST ) === wp_parse_url( $image_url, PHP_URL_HOST ) ) : ?>
		<img src="<?php echo esc_url( $image_url ); ?>" alt="" loading="lazy">
	<?php else : ?>
		<span><?php echo esc_html( get_the_title( $id ) ); ?></span>
	<?php endif; ?>
</figure>
