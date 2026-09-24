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
 * Build same-site URL replacements for all files converted with an attachment.
 * Returns false when an attachment file is outside the configured uploads directory.
 */
function node_featured_webp_content_url_replacements( array $converted ) {
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
		return false;
	}
	$base_dir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
	$base_url = trailingslashit( $uploads['baseurl'] );
	$replacements = array();
	foreach ( $converted as $source => $target ) {
		$source = wp_normalize_path( $source );
		$target = wp_normalize_path( $target );
		if ( 0 !== strpos( $source, $base_dir ) || 0 !== strpos( $target, $base_dir ) ) {
			return false;
		}
		$source_path = substr( $source, strlen( $base_dir ) );
		$target_path = substr( $target, strlen( $base_dir ) );
		$source_url = $base_url . $source_path;
		$target_url = $base_url . $target_path;
		$source_encoded_url = $base_url . implode( '/', array_map( 'rawurlencode', explode( '/', $source_path ) ) );
		$target_encoded_url = $base_url . implode( '/', array_map( 'rawurlencode', explode( '/', $target_path ) ) );
		foreach ( array_unique( array( $source_url, $source_encoded_url ) ) as $old_url ) {
			$new_url = $source_url === $old_url ? $target_url : $target_encoded_url;
			foreach ( array( $old_url, set_url_scheme( $old_url, 'http' ), set_url_scheme( $old_url, 'https' ) ) as $url_variant ) {
				$replacements[ $url_variant ] = str_replace( $old_url, $new_url, $url_variant );
			}
		}
	}
	return $replacements;
}

/**
 * Update exact old image URLs in stored post content. Keep a rollback journal
 * so source files remain safe if any content update fails.
 */
function node_featured_webp_rewrite_post_content( array $replacements ) {
	global $wpdb;
	if ( ! $replacements ) {
		return array();
	}
	$conditions = array();
	foreach ( array_keys( $replacements ) as $old_url ) {
		$conditions[] = $wpdb->prepare( 'post_content LIKE %s', '%' . $wpdb->esc_like( $old_url ) . '%' );
	}
	$post_ids = $wpdb->get_col( 'SELECT ID FROM ' . $wpdb->posts . ' WHERE ' . implode( ' OR ', $conditions ) );
	$updated_posts = array();
	foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			continue;
		}
		$new_content = strtr( $post->post_content, $replacements );
		if ( $new_content === $post->post_content ) {
			continue;
		}
		$result = wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ), true );
		$stored_content = get_post_field( 'post_content', $post_id, 'raw' );
		if ( $stored_content !== $post->post_content ) {
			$updated_posts[] = array( 'ID' => $post_id, 'post_content' => $post->post_content );
		}
		$has_old_url = false;
		foreach ( array_keys( $replacements ) as $old_url ) {
			if ( false !== strpos( (string) $stored_content, $old_url ) ) {
				$has_old_url = true;
				break;
			}
		}
		if ( is_wp_error( $result ) || ! $result || $has_old_url ) {
			foreach ( array_reverse( $updated_posts ) as $previous ) {
				wp_update_post( array( 'ID' => $previous['ID'], 'post_content' => $previous['post_content'] ) );
			}
			return false;
		}
	}
	return $updated_posts;
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
	$backup_sizes = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
	if ( is_array( $backup_sizes ) ) {
		foreach ( $backup_sizes as $backup ) {
			if ( ! is_array( $backup ) || empty( $backup['file'] ) || ! is_string( $backup['file'] ) ) {
				return false;
			}
			$sources[] = $directory . '/' . wp_basename( $backup['file'] );
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
	if ( is_array( $backup_sizes ) ) {
		foreach ( $backup_sizes as $name => $backup ) {
			$source = $directory . '/' . wp_basename( $backup['file'] );
			$new_metadata_backup_sizes[ $name ] = $backup;
			$new_metadata_backup_sizes[ $name ]['file'] = wp_basename( $converted[ $source ] );
			$new_metadata_backup_sizes[ $name ]['filesize'] = wp_filesize( $converted[ $source ] );
		}
	}
	$content_replacements = node_featured_webp_content_url_replacements( $converted );
	if ( false === $content_replacements ) {
		foreach ( $created as $file ) {
			wp_delete_file( $file );
		}
		return false;
	}

	$old_mime = get_post_mime_type( $attachment_id );
	update_attached_file( $attachment_id, $converted[ $full ] );
	wp_update_attachment_metadata( $attachment_id, $new_metadata );
	if ( is_array( $backup_sizes ) ) {
		update_post_meta( $attachment_id, '_wp_attachment_backup_sizes', $new_metadata_backup_sizes );
	}
	$updated = wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => 'image/webp' ), true );
	$stored_metadata = wp_get_attachment_metadata( $attachment_id );
	if ( is_wp_error( $updated ) || get_attached_file( $attachment_id ) !== $converted[ $full ]
		|| ! is_array( $stored_metadata ) || ( $stored_metadata['file'] ?? '' ) !== $new_metadata['file']
		|| 'image/webp' !== get_post_mime_type( $attachment_id ) ) {
		update_attached_file( $attachment_id, $full );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		if ( is_array( $backup_sizes ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_backup_sizes', $backup_sizes );
		}
		wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => $old_mime ) );
		foreach ( $created as $file ) {
			wp_delete_file( $file );
		}
		return false;
	}
	if ( false === node_featured_webp_rewrite_post_content( $content_replacements ) ) {
		update_attached_file( $attachment_id, $full );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		if ( is_array( $backup_sizes ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_backup_sizes', $backup_sizes );
		}
		wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => $old_mime ) );
		foreach ( $created as $file ) {
			wp_delete_file( $file );
		}
		return false;
	}

	$pending = array();
	foreach ( array_keys( $converted ) as $source ) {
		wp_delete_file( $source );
		if ( is_file( $source ) ) {
			$pending[] = wp_basename( $source );
		}
	}
	if ( $pending ) {
		update_post_meta( $attachment_id, '_node_featured_webp_pending_delete', array_values( array_unique( $pending ) ) );
		update_post_meta( $attachment_id, '_node_featured_webp_delete_attempts', 0 );
		node_featured_webp_schedule_delete_retry( $attachment_id );
	} else {
		delete_post_meta( $attachment_id, '_node_featured_webp_pending_delete' );
		delete_post_meta( $attachment_id, '_node_featured_webp_delete_attempts' );
	}
	delete_post_meta( $attachment_id, '_node_featured_webp_map' );
	delete_post_meta( $attachment_id, '_node_featured_webp_requested' );
	return true;
}

function node_featured_webp_schedule_delete_retry( int $attachment_id ): void {
	if ( ! wp_next_scheduled( 'node_featured_webp_retry_delete', array( $attachment_id ) ) ) {
		wp_schedule_single_event( time() + 300, 'node_featured_webp_retry_delete', array( $attachment_id ) );
	}
}

function node_featured_webp_retry_delete( int $attachment_id ): void {
	$pending = get_post_meta( $attachment_id, '_node_featured_webp_pending_delete', true );
	$full = get_attached_file( $attachment_id );
	if ( ! is_array( $pending ) || ! $pending || ! is_string( $full ) ) {
		return;
	}
	$directory = dirname( $full );
	$remaining = array();
	foreach ( $pending as $filename ) {
		if ( ! is_string( $filename ) || wp_basename( $filename ) !== $filename ) {
			continue;
		}
		$path = $directory . '/' . $filename;
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
		if ( is_file( $path ) ) {
			$remaining[] = $filename;
		}
	}
	if ( ! $remaining ) {
		delete_post_meta( $attachment_id, '_node_featured_webp_pending_delete' );
		delete_post_meta( $attachment_id, '_node_featured_webp_delete_attempts' );
		return;
	}
	$attempts = (int) get_post_meta( $attachment_id, '_node_featured_webp_delete_attempts', true ) + 1;
	update_post_meta( $attachment_id, '_node_featured_webp_pending_delete', $remaining );
	update_post_meta( $attachment_id, '_node_featured_webp_delete_attempts', $attempts );
	if ( $attempts < 5 ) {
		node_featured_webp_schedule_delete_retry( $attachment_id );
	}
}
add_action( 'node_featured_webp_retry_delete', 'node_featured_webp_retry_delete' );

function node_featured_webp_delete_pending_files( int $attachment_id ): void {
	$pending = get_post_meta( $attachment_id, '_node_featured_webp_pending_delete', true );
	$full = get_attached_file( $attachment_id );
	if ( ! is_array( $pending ) || ! is_string( $full ) ) {
		return;
	}
	foreach ( $pending as $filename ) {
		if ( is_string( $filename ) && wp_basename( $filename ) === $filename ) {
			wp_delete_file( dirname( $full ) . '/' . $filename );
		}
	}
}
add_action( 'delete_attachment', 'node_featured_webp_delete_pending_files' );

function node_featured_webp_admin_notice(): void {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}
	$attachments = get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 20,
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'     => '_node_featured_webp_delete_attempts',
				'value'   => 5,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			),
		),
	) );
	if ( $attachments ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( '一部のアイキャッチ画像をWebPへ置き換えましたが、元画像を削除できません。メディアライブラリで対象ファイルを確認してください。', 'node' ) . '</p></div>';
	}
}
add_action( 'admin_notices', 'node_featured_webp_admin_notice' );

function node_featured_webp_on_thumbnail( int $meta_id, int $post_id, string $key, $value ): void {
	if ( '_thumbnail_id' === $key && (int) $value > 0 ) {
		node_featured_webp_replace( (int) $value );
	}
}
add_action( 'added_post_meta', 'node_featured_webp_on_thumbnail', 10, 4 );
add_action( 'updated_post_meta', 'node_featured_webp_on_thumbnail', 10, 4 );
