<?php

declare(strict_types=1);

function node_featured_webp_valid( string $file ): bool {
	if ( ! is_file( $file ) || 0 === wp_filesize( $file ) ) {
		return false;
	}
	$image = @getimagesize( $file );
	return is_array( $image ) && 'image/webp' === ( $image['mime'] ?? '' );
}

function node_featured_webp_save( string $source, string $target ): bool {
	// GD cannot write a palette PNG as WebP without converting it to true color.
	if ( 'image/png' === wp_check_filetype( $source )['type']
		&& function_exists( 'imagepalettetotruecolor' ) && function_exists( 'imagewebp' ) ) {
		$image = @imagecreatefrompng( $source );
		if ( $image && ! imageistruecolor( $image ) ) {
			imagepalettetotruecolor( $image );
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
			$written = @imagewebp( $image, $target, 82 );
			imagedestroy( $image );
			return $written && node_featured_webp_valid( $target );
		}
		if ( $image ) {
			imagedestroy( $image );
		}
	}

	$editor = wp_get_image_editor( $source );
	if ( is_wp_error( $editor ) ) {
		return false;
	}
	$editor->set_quality( 82 );
	$saved = $editor->save( $target, 'image/webp' );
	return ! is_wp_error( $saved ) && node_featured_webp_valid( $target );
}

/**
 * Replace a newly selected JPEG/PNG featured image and its registered sizes with WebP.
 * Originals are removed only after WordPress points to every converted file.
 */
function node_featured_webp_replace( int $attachment_id ): bool {
	if ( ! in_array( get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true )
		|| ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
		return false;
	}

	$full     = get_attached_file( $attachment_id );
	$metadata = wp_get_attachment_metadata( $attachment_id );
	if ( ! is_string( $full ) || ! is_file( $full ) || ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
		return false;
	}

	$directory = dirname( $full );
	$sources   = array( $full );
	foreach ( $metadata['sizes'] ?? array() as $size ) {
		if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
			return false;
		}
		$sources[] = $directory . '/' . wp_basename( $size['file'] );
	}
	foreach ( array( 'original_image', 'thumb' ) as $field ) {
		if ( ! empty( $metadata[ $field ] ) && is_string( $metadata[ $field ] ) ) {
			$sources[] = $directory . '/' . wp_basename( $metadata[ $field ] );
		}
	}

	$converted = array();
	$created   = array();
	foreach ( array_unique( $sources ) as $source ) {
		if ( ! is_file( $source ) || ! in_array( wp_check_filetype( $source )['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
			break;
		}

		$target = $directory . '/' . pathinfo( $source, PATHINFO_FILENAME ) . '-node-featured.webp';
		$exists = is_file( $target );
		if ( ! $exists ) {
			$created[] = $target;
		}
		if ( ! node_featured_webp_valid( $target ) || filemtime( $target ) < filemtime( $source ) ) {
			if ( ! node_featured_webp_save( $source, $target ) ) {
				break;
			}
		}
		$converted[ $source ] = $target;
	}

	if ( count( $converted ) !== count( array_unique( $sources ) ) ) {
		foreach ( $created as $file ) {
			wp_delete_file( $file );
		}
		return false;
	}

	$new_metadata = $metadata;
	$relative_dir = dirname( $metadata['file'] );
	$new_metadata['file']     = ( '.' === $relative_dir ? '' : trailingslashit( $relative_dir ) ) . wp_basename( $converted[ $full ] );
	$new_metadata['filesize'] = wp_filesize( $converted[ $full ] );
	foreach ( $new_metadata['sizes'] ?? array() as $name => $size ) {
		$source = $directory . '/' . wp_basename( $size['file'] );
		$new_metadata['sizes'][ $name ]['file']      = wp_basename( $converted[ $source ] );
		$new_metadata['sizes'][ $name ]['mime-type'] = 'image/webp';
		$new_metadata['sizes'][ $name ]['filesize']  = wp_filesize( $converted[ $source ] );
	}
	foreach ( array( 'original_image', 'thumb' ) as $field ) {
		if ( ! empty( $metadata[ $field ] ) ) {
			$source = $directory . '/' . wp_basename( $metadata[ $field ] );
			$new_metadata[ $field ] = wp_basename( $converted[ $source ] );
		}
	}

	$old_mime = get_post_mime_type( $attachment_id );
	update_attached_file( $attachment_id, $converted[ $full ] );
	wp_update_attachment_metadata( $attachment_id, $new_metadata );
	$updated = wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => 'image/webp' ), true );
	$stored_metadata = wp_get_attachment_metadata( $attachment_id );
	if ( is_wp_error( $updated ) || get_attached_file( $attachment_id ) !== $converted[ $full ]
		|| ! is_array( $stored_metadata ) || ( $stored_metadata['file'] ?? '' ) !== $new_metadata['file']
		|| 'image/webp' !== get_post_mime_type( $attachment_id ) ) {
		update_attached_file( $attachment_id, $full );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => $old_mime ) );
		foreach ( $created as $file ) {
			wp_delete_file( $file );
		}
		return false;
	}

	foreach ( array_keys( $converted ) as $source ) {
		wp_delete_file( $source );
	}
	delete_post_meta( $attachment_id, '_node_featured_webp_map' );
	delete_post_meta( $attachment_id, '_node_featured_webp_requested' );
	return true;
}

function node_featured_webp_on_thumbnail( int $meta_id, int $post_id, string $key, $value ): void {
	if ( '_thumbnail_id' === $key && (int) $value > 0 ) {
		node_featured_webp_replace( (int) $value );
	}
}
add_action( 'added_post_meta', 'node_featured_webp_on_thumbnail', 10, 4 );
add_action( 'updated_post_meta', 'node_featured_webp_on_thumbnail', 10, 4 );
