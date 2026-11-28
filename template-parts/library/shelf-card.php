<?php
/** Archive price and store chips use the same product-matched offers as the detail page. */
$id      = get_the_ID();
$links   = get_post_meta( $id, '_node_library_links', true );
$offers  = is_callable( array( 'Node_Library_Single_Extensions', 'store_offers' ) )
	? Node_Library_Single_Extensions::store_offers( $id, is_array( $links ) ? $links : array() )
	: array();
$labels  = class_exists( 'Node_Library_Structured_Meta' ) ? Node_Library_Structured_Meta::stores() : array();
$labels['amazon_asin'] = 'Amazon';
$labels['nintendo']    = 'Nintendo eShop';
$stores  = array();
$price   = null;
foreach ( $offers as $offer ) {
	$key   = $offer['catalogue_store'];
	$label = $labels[ $key ] ?? $offer['platform'];
	$stores[ $label ] = $key;
	if ( '' !== $offer['price_label'] ) {
		if ( null === $price || ( is_numeric( $offer['price_numeric'] ?? null ) && is_numeric( $price['price_numeric'] ?? null ) && (float) $offer['price_numeric'] < (float) $price['price_numeric'] ) ) {
			$price = $offer;
		}
	}
}
$free = class_exists( 'Node_Library_Structured_Meta' ) && Node_Library_Structured_Meta::sanitize( 'is_free', get_post_meta( $id, '_node_library_is_free', true ) );
$discount = (int) ( $price['discount_percent'] ?? 0 );
$summary  = (string) get_post_meta( $id, '_node_library_summary', true );
?>
<article <?php post_class( 'node-library-shelf-card' ); ?>>
	<?php get_template_part( 'template-parts/library/visual', null, array( 'id' => $id ) ); ?>
	<div class="library-card-copy">
		<div class="library-chips">
			<?php get_template_part( 'template-parts/library/category' ); ?>
			<?php if ( $discount ) : ?><span class="library-chip library-chip--sale"><?php echo esc_html( sprintf( __( 'セール中 · %d%% OFF', 'node' ), $discount ) ); ?></span><?php endif; ?>
		</div>
		<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
		<?php if ( $summary ) : ?><p class="library-summary"><?php echo esc_html( wp_trim_words( $summary, 42 ) ); ?></p><?php endif; ?>
		<div class="library-chips library-card-stores">
			<?php foreach ( $stores as $label => $key ) : ?>
				<span class="library-chip library-chip--store" data-store="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></span>
			<?php endforeach; ?>
		</div>
		<div class="library-card-footer">
			<div class="library-money">
				<?php if ( $price ) : ?>
					<strong<?php echo $discount ? ' class="library-deal"' : ''; ?>><?php echo esc_html( $price['price_label'] ); ?></strong>
					<?php if ( $discount ) : ?><s><?php echo esc_html( $price['list_price_label'] ); ?></s><?php endif; ?>
					<small><?php echo esc_html( $labels[ $price['catalogue_store'] ] ?? $price['platform'] ); ?></small>
				<?php elseif ( $free ) : ?>
					<strong><?php esc_html_e( '無料', 'node' ); ?></strong>
				<?php else : ?>
					<small><?php esc_html_e( '価格データなし', 'node' ); ?></small>
				<?php endif; ?>
			</div>
			<span class="library-card-open" aria-hidden="true"><?php esc_html_e( '詳細', 'node' ); ?><span class="material-symbols-outlined" data-icon="chevron_right"></span></span>
		</div>
	</div>
</article>
