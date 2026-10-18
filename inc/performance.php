<?php
/**
 * Narrow frontend delivery adjustments. Third-party features remain enabled.
 *
 * @package Luminous_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep offscreen images out of the preload scanner until JS needs them.
 * The original markup remains available to browsers with JavaScript disabled.
 *
 * @param string $html Responsive image markup.
 * @return string
 */
function node_defer_image_html( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$deferred  = false;
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		if ( ! in_array( $tag, array( 'IMG', 'SOURCE' ), true ) ) {
			continue;
		}
		foreach ( array( 'src', 'srcset', 'sizes' ) as $attribute ) {
			$value = $processor->get_attribute( $attribute );
			if ( is_string( $value ) && '' !== $value ) {
				$processor->set_attribute( 'data-lf-' . $attribute, $value );
				$processor->remove_attribute( $attribute );
				$deferred = true;
			}
		}
		if ( 'IMG' === $tag ) {
			$processor->set_attribute( 'data-lf-deferred-image', '' );
		}
	}

	return $deferred ? $processor->get_updated_html() . '<noscript><style>img[data-lf-deferred-image]{display:none!important}</style>' . $html . '</noscript>' : $html;
}

/**
 * News image slots mirror _home.css: 1/2/4 columns, 12/14px gaps, 1px borders,
 * clamp(16px, 4vw, 56px) gutters and the 1440px page width limit.
 *
 * @return string
 */
function node_get_home_news_image_sizes(): string {
	return '(max-width: 359px) calc(100vw - 34px), '
		. '(max-width: 600px) calc((100vw - 2 * clamp(16px, 4vw, 56px) - 16px) / 2), '
		. '(max-width: 1000px) calc((100vw - 2 * clamp(16px, 4vw, 56px) - 18px) / 2), '
		. 'calc((min(100vw, 1440px) - 2 * clamp(16px, 4vw, 56px) - 50px) / 4)';
}

/**
 * Defer Site Kit's ordered Google sign-in pair on content pages.
 *
 * Site Kit prints the client followed by its data-siwg-config bootstrap using
 * wp_print_script_tag(). Both classic scripts retain document order with defer.
 * An already-async client, login page, or admin page keeps its existing behavior.
 *
 * @param array $attributes Script attributes.
 * @return array
 */
function node_defer_sitekit_sign_in_scripts( array $attributes ): array {
	static $client_deferred = false;

	if ( is_admin() || ( function_exists( 'is_login' ) && is_login() ) ) {
		return $attributes;
	}

	$src = $attributes['src'] ?? '';
	if ( ! is_string( $src ) ) {
		return $attributes;
	}

	// WordPress 6.9 may add this classic type before wp_script_attributes runs.
	// Keep module/data scripts excluded and preserve the original type attribute.
	$is_classic_script = empty( $attributes['type'] )
		|| ( is_string( $attributes['type'] ) && 'text/javascript' === strtolower( trim( $attributes['type'] ) ) );

	if ( 'https://accounts.google.com/gsi/client' === $src ) {
		$client_deferred = empty( $attributes['async'] ) && $is_classic_script;
		if ( $client_deferred ) {
			$attributes['defer'] = true;
		}
		return $attributes;
	}

	$path = wp_parse_url( $src, PHP_URL_PATH );
	if ( $client_deferred && isset( $attributes['data-siwg-config'] ) && is_string( $path )
		&& preg_match( '~/google-site-kit/dist/assets/js/sign-in-with-google(?:-[^/]+)?\.js$~', $path )
		&& empty( $attributes['async'] ) && $is_classic_script ) {
		$attributes['defer'] = true;
	}

	return $attributes;
}
add_filter( 'wp_script_attributes', 'node_defer_sitekit_sign_in_scripts' );
