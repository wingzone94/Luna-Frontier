<?php
/** Library artwork stored in WordPress; omit the visual container when unset. */
$id        = (int) ( $args['id'] ?? 0 );
$size      = $args['size'] ?? 'medium_large';
$eager     = ! empty( $args['eager'] );
$img_attrs = array( 'alt' => '' );
if ( $eager ) {
	$img_attrs['loading']       = 'eager';
	$img_attrs['fetchpriority'] = 'high';
}
$thumbnail           = $id > 0 ? get_the_post_thumbnail( $id, $size, $img_attrs ) : '';
$image_url           = $id > 0 ? (string) get_post_meta( $id, '_node_library_image_url', true ) : '';
$home_host           = wp_parse_url( home_url(), PHP_URL_HOST );
$image_host          = '' !== $image_url ? wp_parse_url( $image_url, PHP_URL_HOST ) : '';
$has_local_image_url = '' !== $image_url
	&& is_string( $home_host )
	&& '' !== $home_host
	&& is_string( $image_host )
	&& $image_host === $home_host
	&& '' !== esc_url( $image_url );

if ( ! $thumbnail && ! $has_local_image_url ) {
	return;
}
?>
<figure class="library-visual" aria-hidden="true">
	<?php if ( $thumbnail ) : ?>
		<?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core image markup. ?>
	<?php else : ?>
		<img src="<?php echo esc_url( $image_url ); ?>" alt="" loading="<?php echo $eager ? 'eager' : 'lazy'; ?>"<?php echo $eager ? ' fetchpriority="high"' : ''; ?>>
	<?php endif; ?>
</figure>
