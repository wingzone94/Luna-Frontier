<?php

declare(strict_types=1);

/**
 * Keep original uploads and create WebP companions only for featured images.
 */
function node_featured_webp_generate( int $attachment_id, ?array $metadata = null ): void {
	if ( ! in_array( get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true )
		|| ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
		return;
	}

	$full = get_attached_file( $attachment_id );
	if ( ! is_string( $full ) || ! is_file( $full ) ) {
		return;
	}

	$metadata = $metadata ?? wp_get_attachment_metadata( $attachment_id );
	if ( ! is_array( $metadata ) ) {
		return;
	}

	$files = array( $full );
	foreach ( $metadata['sizes'] ?? array() as $size ) {
		if ( ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
			$files[] = dirname( $full ) . '/' . wp_basename( $size['file'] );
		}
	}

	$map = array();
	foreach ( array_unique( $files ) as $source ) {
		if ( ! is_file( $source ) || ! in_array( wp_check_filetype( $source )['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
			continue;
		}
		$target = dirname( $source ) . '/' . pathinfo( $source, PATHINFO_FILENAME ) . '-node-featured.webp';
		$ready = is_file( $target ) && filemtime( $target ) >= filemtime( $source );
		if ( ! $ready ) {
			$editor = wp_get_image_editor( $source );
			if ( is_wp_error( $editor ) ) {
				continue;
			}
			$editor->set_quality( 82 );
			$saved = $editor->save( $target, 'image/webp' );
			if ( is_wp_error( $saved ) ) {
				continue;
			}
			$ready = true;
		}
		if ( $ready && is_file( $target ) ) {
			$map[ wp_basename( $source ) ] = wp_basename( $target );
		}
	}

	if ( $map ) {
		update_post_meta( $attachment_id, '_node_featured_webp_map', $map );
	}
}

function node_featured_webp_on_thumbnail( int $meta_id, int $post_id, string $key, $value ): void {
	if ( '_thumbnail_id' !== $key ) {
		return;
	}
	$attachment_id = (int) $value;
	if ( $attachment_id > 0 ) {
		update_post_meta( $attachment_id, '_node_featured_webp_requested', '1' );
		node_featured_webp_generate( $attachment_id );
	}
}
add_action( 'added_post_meta', 'node_featured_webp_on_thumbnail', 10, 4 );
add_action( 'updated_post_meta', 'node_featured_webp_on_thumbnail', 10, 4 );

function node_featured_webp_on_metadata( array $metadata, int $attachment_id ): array {
	if ( get_post_meta( $attachment_id, '_node_featured_webp_requested', true ) ) {
		node_featured_webp_generate( $attachment_id, $metadata );
	}
	return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'node_featured_webp_on_metadata', 10, 2 );

function node_featured_webp_map( int $attachment_id ): array {
	$map = get_post_meta( $attachment_id, '_node_featured_webp_map', true );
	return is_array( $map ) ? $map : array();
}

function node_featured_webp_image_src( $image, int $attachment_id, $size, bool $icon ) {
	if ( ! is_array( $image ) || $icon || empty( $image[0] ) ) {
		return $image;
	}
	$map      = node_featured_webp_map( $attachment_id );
	$filename = wp_basename( rawurldecode( (string) wp_parse_url( $image[0], PHP_URL_PATH ) ) );
	$full     = get_attached_file( $attachment_id );
	$webp_file   = is_string( $full ) && isset( $map[ $filename ] ) ? dirname( $full ) . '/' . $map[ $filename ] : '';
	if ( ! isset( $map[ $filename ] ) || ! is_file( $webp_file ) ) {
		return $image;
	}
	$path = (string) wp_parse_url( $image[0], PHP_URL_PATH );
	$last_slash = strrpos( $image[0], '/' );
	if ( $path && false !== $last_slash ) {
		$image[0] = substr( $image[0], 0, $last_slash + 1 ) . rawurlencode( $map[ $filename ] );
	}
	return $image;
}
add_filter( 'wp_get_attachment_image_src', 'node_featured_webp_image_src', 10, 4 );

function node_featured_webp_srcset_meta( array $metadata, array $size, string $image_src, int $attachment_id ): array {
	$map = node_featured_webp_map( $attachment_id );
	if ( ! in_array( wp_basename( rawurldecode( (string) wp_parse_url( $image_src, PHP_URL_PATH ) ) ), $map, true ) ) {
		return $metadata;
	}
	$full_name = wp_basename( $metadata['file'] ?? '' );
	if ( isset( $map[ $full_name ] ) ) {
		$metadata['file'] = trailingslashit( dirname( $metadata['file'] ) ) . $map[ $full_name ];
	}
	if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
		foreach ( $metadata['sizes'] as &$variant ) {
			if ( isset( $variant['file'], $map[ $variant['file'] ] ) ) {
				$variant['file']      = $map[ $variant['file'] ];
				$variant['mime-type'] = 'image/webp';
			}
		}
		unset( $variant );
	}
	return $metadata;
}
add_filter( 'wp_calculate_image_srcset_meta', 'node_featured_webp_srcset_meta', 10, 4 );

function node_featured_webp_delete( int $attachment_id ): void {
	$full = get_attached_file( $attachment_id );
	if ( ! is_string( $full ) ) {
		return;
	}
	foreach ( node_featured_webp_map( $attachment_id ) as $filename ) {
		if ( is_string( $filename ) && wp_basename( $filename ) === $filename && str_ends_with( $filename, '-node-featured.webp' ) ) {
			$file = dirname( $full ) . '/' . $filename;
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
add_action( 'delete_attachment', 'node_featured_webp_delete' );
