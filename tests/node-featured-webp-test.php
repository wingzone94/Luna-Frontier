<?php

/**
 * Destructive featured-image conversion must leave a complete WebP attachment.
 */
class Node_Featured_Webp_Test extends WP_UnitTestCase {
	public function test_new_featured_images_replace_every_registered_file(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$this->markTestSkipped( 'GD or WebP support is unavailable.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		foreach ( array( 'jpeg', 'png' ) as $format ) {
			$upload = wp_upload_dir();
			$file   = trailingslashit( $upload['path'] ) . wp_unique_filename( $upload['path'], 'node-featured-test.' . ( 'jpeg' === $format ? 'jpg' : 'png' ) );
			$image  = imagecreatetruecolor( 1200, 600 );
			imagefill( $image, 0, 0, imagecolorallocate( $image, 20, 120, 180 ) );
			if ( 'jpeg' === $format ) {
				imagejpeg( $image, $file );
			} else {
				imagepng( $image, $file );
			}
			imagedestroy( $image );

			$post_id       = self::factory()->post->create();
			$attachment_id = wp_insert_attachment(
				array( 'post_mime_type' => 'image/' . $format, 'post_title' => 'WebP test' ),
				$file,
				$post_id
			);
			try {
				$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
				wp_update_attachment_metadata( $attachment_id, $metadata );
				$originals = array( $file );
				foreach ( $metadata['sizes'] as $size ) {
					$originals[] = dirname( $file ) . '/' . $size['file'];
				}
				$backup_file = dirname( $file ) . '/node-featured-backup.' . ( 'jpeg' === $format ? 'jpg' : 'png' );
				copy( $file, $backup_file );
				update_post_meta( $attachment_id, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => basename( $backup_file ), 'width' => 1200, 'height' => 600 ) ) );
				$originals[] = $backup_file;

				set_post_thumbnail( $post_id, $attachment_id );
				$this->assertSame( 'image/webp', get_post_mime_type( $attachment_id ) );
				$this->assertFileExists( get_attached_file( $attachment_id ) );
				$this->assertSame( 'image/webp', getimagesize( get_attached_file( $attachment_id ) )['mime'] );
				foreach ( $originals as $original ) {
					$this->assertFileDoesNotExist( $original );
				}
				foreach ( wp_get_attachment_metadata( $attachment_id )['sizes'] as $size ) {
					$this->assertSame( 'image/webp', $size['mime-type'] );
					$this->assertFileExists( dirname( $file ) . '/' . $size['file'] );
				}
				$backup = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true )['full-orig'];
				$this->assertSame( 'image/webp', getimagesize( dirname( $file ) . '/' . $backup['file'] )['mime'] );
				$this->assertFileExists( dirname( $file ) . '/' . $backup['file'] );
			} finally {
				wp_delete_attachment( $attachment_id, true );
				wp_delete_post( $post_id, true );
			}
		}
	}

	public function test_failed_original_deletion_is_tracked_and_retried(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$this->markTestSkipped( 'GD or WebP support is unavailable.' );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$upload = wp_upload_dir();
		$file = trailingslashit( $upload['path'] ) . wp_unique_filename( $upload['path'], 'node-featured-delete-retry.jpg' );
		$image = imagecreatetruecolor( 800, 400 );
		imagejpeg( $image, $file );
		imagedestroy( $image );
		$post_id = self::factory()->post->create();
		$attachment_id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg' ), $file, $post_id );
		$block_jpeg_delete = static function ( $path ) {
			return '.jpg' === substr( (string) $path, -4 ) ? null : $path;
		};
		try {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
			wp_update_attachment_metadata( $attachment_id, $metadata );
			add_filter( 'wp_delete_file', $block_jpeg_delete );
			set_post_thumbnail( $post_id, $attachment_id );
			$this->assertSame( 'image/webp', get_post_mime_type( $attachment_id ) );
			$this->assertFileExists( $file );
			$this->assertCount( count( $metadata['sizes'] ) + 1, get_post_meta( $attachment_id, '_node_featured_webp_pending_delete', true ) );
			remove_filter( 'wp_delete_file', $block_jpeg_delete );
			node_featured_webp_retry_delete( $attachment_id );
			$this->assertFileDoesNotExist( $file );
			foreach ( $metadata['sizes'] as $size ) {
				$this->assertFileDoesNotExist( dirname( $file ) . '/' . $size['file'] );
			}
			$this->assertEmpty( get_post_meta( $attachment_id, '_node_featured_webp_pending_delete', true ) );
		} finally {
			remove_filter( 'wp_delete_file', $block_jpeg_delete );
			wp_delete_attachment( $attachment_id, true );
			wp_delete_post( $post_id, true );
		}
	}

	public function test_missing_size_keeps_the_original(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$this->markTestSkipped( 'GD or WebP support is unavailable.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$upload = wp_upload_dir();
		$file   = trailingslashit( $upload['path'] ) . wp_unique_filename( $upload['path'], 'node-featured-failure.jpg' );
		$image  = imagecreatetruecolor( 800, 400 );
		imagejpeg( $image, $file );
		imagedestroy( $image );
		$post_id       = self::factory()->post->create();
		$attachment_id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg' ), $file, $post_id );
		try {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
			$metadata['sizes']['missing'] = array( 'file' => 'node-featured-missing.jpg', 'width' => 50, 'height' => 50, 'mime-type' => 'image/jpeg' );
			wp_update_attachment_metadata( $attachment_id, $metadata );
			set_post_thumbnail( $post_id, $attachment_id );
			$this->assertSame( 'image/jpeg', get_post_mime_type( $attachment_id ) );
			$this->assertSame( $file, get_attached_file( $attachment_id ) );
			$this->assertFileExists( $file );
		} finally {
			wp_delete_attachment( $attachment_id, true );
			wp_delete_post( $post_id, true );
		}
	}
}
