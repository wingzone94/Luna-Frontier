<?php
/**
 * Shared product-card normalization, links and rendering.
 *
 * @package Luminous_Nexus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Accept absolute HTTP(S) URLs only; never render an empty/unsafe button. */
function luminous_nexus_product_url( string $url ): string {
	$url = trim( $url );
	if ( ! preg_match( '~^https?://~i', $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return '';
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return '';
	}
	return esc_url_raw( $url, array( 'http', 'https' ) );
}

/** Normalize the shared input; callers retain their own disclosure defaults. */
function luminous_nexus_normalize_product_card( array $attributes ): array {
	$normalized = array();
	foreach ( array( 'title', 'price', 'image_url', 'amazon_url', 'rakuten_url', 'asin' ) as $key ) {
		$normalized[ $key ] = isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] )
			? (string) $attributes[ $key ] : '';
	}
	foreach ( array( 'show_amazon_disclosure', 'show_rakuten_disclosure' ) as $key ) {
		$value = $attributes[ $key ] ?? false;
		$normalized[ $key ] = true === $value || '1' === $value;
	}
	return $normalized;
}

/** Resolve ASIN precedence and add a missing Amazon tag without losing fragments. */
function luminous_nexus_product_card_links( array $attributes ): array {
	$amazon = luminous_nexus_product_url( $attributes['amazon_url'] );
	$asin   = trim( $attributes['asin'] );
	if ( preg_match( '/^[a-zA-Z0-9]{10}$/D', $asin ) ) {
		$amazon = 'https://www.amazon.co.jp/dp/' . $asin;
	}
	$amazon_id = (string) get_option( 'luminous_nexus_amazon_id', '' );
	if ( '' !== $amazon && '' !== $amazon_id ) {
		$query = array();
		wp_parse_str( (string) wp_parse_url( $amazon, PHP_URL_QUERY ), $query );
		if ( ! array_key_exists( 'tag', $query ) ) {
			$amazon = add_query_arg( 'tag', $amazon_id, $amazon );
		}
	}
	return array(
		'amazon_url'  => $amazon,
		'rakuten_url' => luminous_nexus_product_url( $attributes['rakuten_url'] ),
	);
}

/** Render all product-card entry points through the same markup. */
function luminous_nexus_render_product_card( array $attributes ): string {
	$atts = luminous_nexus_normalize_product_card( $attributes );
	$atts = array_merge( $atts, luminous_nexus_product_card_links( $atts ) );
	$atts['image_url'] = luminous_nexus_product_url( $atts['image_url'] );
	$has_amazon = '' !== $atts['amazon_url'];
	$has_rakuten = '' !== $atts['rakuten_url'];
	if ( ! $has_amazon && ! $has_rakuten ) {
		return '';
	}
	$disclosure_amazon = get_option( 'luminous_nexus_disclosure_amazon', 'Amazonのアソシエイトとして、当メディアは適格販売により収入を得ています。' );
	$disclosure_rakuten = get_option( 'luminous_nexus_disclosure_rakuten', '当メディアは、楽天アフィリエイト・プログラムの参加者です。' );

	ob_start();
	?>
	<div class="m3-product-card-container m3-product-card-container--nexus">
		<div class="m3-product-card">
			<?php if ( ! empty( $atts['image_url'] ) ) : ?>
			<div class="m3-product-card__image">
				<img src="<?php echo esc_url( $atts['image_url'] ); ?>"
					 alt="<?php echo esc_attr( $atts['title'] ); ?>"
					 loading="lazy" decoding="async">
			</div>
			<?php endif; ?>

			<div class="m3-product-card__content">
				<?php if ( ! empty( $atts['title'] ) ) : ?>
				<h4 class="m3-product-card__title"><?php echo esc_html( $atts['title'] ); ?></h4>
				<?php endif; ?>

				<?php if ( ! empty( $atts['price'] ) ) : ?>
				<div class="m3-product-card__price-wrapper">
					<span class="m3-product-card__price-label"><?php esc_html_e( '参考価格:', 'luminous-nexus' ); ?></span>
					<span class="m3-product-card__price"><?php echo esc_html( $atts['price'] ); ?></span>
				</div>
				<?php endif; ?>

				<div class="m3-product-card__actions">
					<?php if ( $has_amazon ) : ?>
					<a href="<?php echo esc_url( $atts['amazon_url'] ); ?>"
					   class="m3-product-btn m3-product-btn--amazon m3-ripple-host"
					   target="_blank"
					   rel="noopener noreferrer sponsored">
						<img src="https://www.google.com/s2/favicons?domain=amazon.co.jp&sz=32" alt="" class="m3-product-btn__icon">
						<?php esc_html_e( 'Amazonで見る', 'luminous-nexus' ); ?>
					</a>
					<?php endif; ?>

					<?php if ( $has_rakuten ) : ?>
					<a href="<?php echo esc_url( $atts['rakuten_url'] ); ?>"
					   class="m3-product-btn m3-product-btn--rakuten m3-ripple-host"
					   target="_blank"
					   rel="noopener noreferrer sponsored">
						<img src="https://www.google.com/s2/favicons?domain=rakuten.co.jp&sz=32" alt="" class="m3-product-btn__icon">
						<?php esc_html_e( '楽天市場で見る', 'luminous-nexus' ); ?>
					</a>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="m3-product-card__disclosures">
			<?php if ( $atts['show_amazon_disclosure'] && ! empty( $disclosure_amazon ) ) : ?>
				<div class="m3-product-card__disclosure">
					<span class="material-symbols-outlined" data-icon="info" aria-hidden="true"></span>
					<span><?php echo esc_html( $disclosure_amazon ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( $atts['show_rakuten_disclosure'] && ! empty( $disclosure_rakuten ) ) : ?>
				<div class="m3-product-card__disclosure">
					<span class="material-symbols-outlined" data-icon="info" aria-hidden="true"></span>
					<span><?php echo esc_html( $disclosure_rakuten ); ?></span>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
