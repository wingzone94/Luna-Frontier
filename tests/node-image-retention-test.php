<?php
/** Shared regression suite for the independently packaged compressor. */
require_once dirname( __DIR__ ) . '/plugins-embedded/node-image-compressor/includes/class-webp-converter.php';
require_once dirname( __DIR__ ) . '/plugins-embedded/node-image-compressor/includes/query.php';
class Node_Image_Retention_Test extends WP_UnitTestCase {
	public function test_conversion_and_restore_retain_all_existing_urls_and_metadata(): void {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$upload = wp_upload_dir();
		$file = $upload['path'] . '/' . wp_unique_filename( $upload['path'], 'retention-source.png' );
		$image = imagecreatetruecolor( 800, 500 );
		for ( $x = 0; $x < 800; ++$x ) { imageline( $image, $x, 0, $x, 499, imagecolorallocate( $image, $x % 256, ( $x * 3 ) % 256, ( $x * 7 ) % 256 ) ); }
		imagepng( $image, $file, 0 ); imagedestroy( $image );
		$id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/png', 'post_author' => $user ) );
		update_attached_file( $id, $file );
		$meta = wp_generate_attachment_metadata( $id, $file ); wp_update_attachment_metadata( $id, $meta );
		$files = array( $file );
		foreach ( $meta['sizes'] as $size ) { $files[] = dirname( $file ) . '/' . $size['file']; }
		$hashes = array_map( static fn( $path ) => hash_file( 'sha256', $path ), $files );
		update_option( 'node_ic_keep_original', '0' );
		update_option( 'node_ic_quality_mode', 'manual' );
		try {
			$this->assertTrue( Node_IC_Converter::keeps_original() );
			$result = Node_IC_Converter::convert( $id );
			$this->assertTrue( $result['ok'], $result['message'] );
			$webp = get_attached_file( $id );
			$this->assertNotSame( $file, $webp );
			$webp_hash = hash_file( 'sha256', $webp );
			$this->assertSame( $hashes, array_map( static fn( $path ) => hash_file( 'sha256', $path ), $files ) );
			$converted_meta = wp_get_attachment_metadata( $id );
			$edited_meta = $converted_meta; $edited_meta['width'] = 799;
			wp_update_attachment_metadata( $id, $edited_meta );
			$this->assertFalse( Node_IC_Converter::restore( $id )['ok'] );
			$this->assertSame( $edited_meta, wp_get_attachment_metadata( $id ) );
			wp_update_attachment_metadata( $id, $converted_meta );
			$result = Node_IC_Converter::restore( $id );
			$this->assertTrue( $result['ok'], $result['message'] );
			$this->assertSame( $file, get_attached_file( $id ) );
			$this->assertSame( $meta, wp_get_attachment_metadata( $id ) );
			$this->assertSame( $webp_hash, hash_file( 'sha256', $webp ) );
			$this->assertSame( $hashes, array_map( static fn( $path ) => hash_file( 'sha256', $path ), $files ) );
		} finally {
			foreach ( glob( dirname( $file ) . '/' . pathinfo( $file, PATHINFO_FILENAME ) . '*' ) as $path ) { if ( is_file( $path ) ) { unlink( $path ); } }
		}
	}
}
