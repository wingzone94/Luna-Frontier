<?php
/**
 * Keep model identifiers and percentages together in article headings.
 *
 * Only rendered heading blocks change; stored content, anchors and TOC text stay intact.
 *
 * @package LunaFrontier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add inline wrapping units without changing heading text or existing inline markup.
 *
 * @param string $html Rendered heading block.
 * @return string
 */
function luna_frontier_wrap_heading_units( string $html ): string {
	if ( ! class_exists( 'DOMDocument' ) ) {
		return $html;
	}

	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	try {
		$loaded = $document->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET );
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}
	if ( ! $loaded ) {
		return $html;
	}

	$xpath    = new DOMXPath( $document );
	$headings = $xpath->query( '/html/body/*[self::h2 or self::h3 or self::h4]' );
	if ( 1 !== $headings->length ) {
		return $html;
	}
	$heading = $headings->item( 0 );
	if ( preg_match( '/__title|(?:^|\s)m3-(?:notice|blogcard|product-card|game-card)(?:\s|$)/', $heading->getAttribute( 'class' ) ) ) {
		return $html;
	}

	// URLs keep their ordinary emergency wrapping, even when they contain hyphens.
	$pattern = '~https?://[^\s]+|(?:約|最大|最小|およそ)?[0-9０-９]+(?:[.,．][0-9０-９]+)?[ \t\x{00A0}]?[%％][\p{Han}]{0,6}|(?<![A-Za-z0-9])[A-Za-z][A-Za-z0-9]*(?:[-.][A-Za-z0-9]+)+(?![A-Za-z0-9])~u';
	$nodes   = iterator_to_array( $xpath->query( './/text()', $heading ) );
	$changed = false;
	foreach ( $nodes as $node ) {
		for ( $parent = $node->parentNode; $parent !== $heading; $parent = $parent->parentNode ) {
			if ( in_array( strtolower( $parent->nodeName ), array( 'code', 'pre', 'script', 'style', 'svg', 'math', 'kbd', 'samp' ), true )
				|| preg_match( '/(?:^|\s)lf-heading-unit(?:\s|$)/', $parent->getAttribute( 'class' ) ) ) {
				continue 2;
			}
		}
		$text = $node->nodeValue;
		if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}
		$fragment = $document->createDocumentFragment();
		$offset   = 0;
		foreach ( $matches[0] as $match ) {
			$fragment->appendChild( $document->createTextNode( substr( $text, $offset, $match[1] - $offset ) ) );
			if ( preg_match( '~^https?://~i', $match[0] ) || strlen( $match[0] ) > 128 ) {
				$fragment->appendChild( $document->createTextNode( $match[0] ) );
			} else {
				$unit = $document->createElement( 'span' );
				$unit->setAttribute( 'class', 'lf-heading-unit' );
				$unit->appendChild( $document->createTextNode( $match[0] ) );
				$fragment->appendChild( $unit );
				$changed = true;
			}
			$offset = $match[1] + strlen( $match[0] );
		}
		$fragment->appendChild( $document->createTextNode( substr( $text, $offset ) ) );
		$node->parentNode->replaceChild( $fragment, $node );
	}
	if ( ! $changed ) {
		return $html;
	}

	$result = '';
	foreach ( $document->getElementsByTagName( 'body' )->item( 0 )->childNodes as $child ) {
		$result .= $document->saveHTML( $child );
	}
	return $result;
}

/** Apply only to front-end singular content, not editor previews or feeds. */
function luna_frontier_render_heading_units( string $html ): string {
	if ( is_admin() || is_feed() || ! is_singular() ) {
		return $html;
	}
	return luna_frontier_wrap_heading_units( $html );
}
add_filter( 'render_block_core/heading', 'luna_frontier_render_heading_units' );
