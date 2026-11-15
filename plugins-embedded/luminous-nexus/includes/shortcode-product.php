<?php
/**
 * Product-card shortcode adapter. Disclosure remains opt-in for legacy posts.
 *
 * @package Luminous_Nexus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/product-card.php';

/** Both [product_card] and [m3_product] use this compatibility entry point. */
function luminous_nexus_product_card_shortcode( $atts ): string {
	$atts = shortcode_atts(
		array(
			'title'                   => '',
			'price'                   => '',
			'image_url'               => '',
			'amazon_url'              => '',
			'rakuten_url'             => '',
			'nintendo_url'            => '',
			'asin'                    => '',
			'show_amazon_disclosure'  => '',
			'show_rakuten_disclosure' => '',
		),
		$atts,
		'product_card'
	);
	$atts['show_amazon_disclosure'] = '1' === $atts['show_amazon_disclosure'];
	$atts['show_rakuten_disclosure'] = '1' === $atts['show_rakuten_disclosure'];
	return luminous_nexus_render_product_card( $atts );
}
