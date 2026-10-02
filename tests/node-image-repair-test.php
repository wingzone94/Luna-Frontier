<?php
require_once dirname( __DIR__ ) . '/inc/image-repair.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

class Node_Image_Repair_Test extends WP_UnitTestCase {
	private array $files = array();
	private array $keys = array();
	private function fixture( string $name = 'repair-original.png', string $mime = 'image/png' ): array {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$uploads = wp_upload_dir();
		$file = $uploads['path'] . '/' . wp_unique_filename( $uploads['path'], $name );
		$image = imagecreatetruecolor( 2560, 1600 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 51, 125, 219 ) );
		if ( 'image/webp' === $mime ) { imagewebp( $image, $file ); } else { imagepng( $image, $file ); }
		imagedestroy( $image ); $this->files[] = $file;
		$id = self::factory()->attachment->create( array( 'post_mime_type' => $mime ) );
		update_attached_file( $id, $file );
		$relative = _wp_relative_upload_path( $file );
		$scaled = preg_replace( '/\.[^.]+$/', '-scaled.' . pathinfo( $file, PATHINFO_EXTENSION ), $file );
		$editor = wp_get_image_editor( $file ); $editor->resize( 2000, 1250 ); $editor->save( $scaled, $mime ); $this->files[] = $scaled;
		update_attached_file( $id, $scaled );
		wp_update_attachment_metadata( $id, array( 'file' => _wp_relative_upload_path( $scaled ), 'width' => 2000, 'height' => 1250, 'original_image' => wp_basename( $file ), 'sizes' => array( 'large' => array( 'file' => pathinfo( $scaled, PATHINFO_FILENAME ) . '-1024x640.' . pathinfo( $file, PATHINFO_EXTENSION ), 'width' => 1024, 'height' => 640, 'mime-type' => $mime ) ) ) );
		$missing = preg_replace( '/\.[^.]+$/', '-1024x640.' . pathinfo( $file, PATHINFO_EXTENSION ), $relative );
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>本文を保持</p><a href="' . Node_Image_Repair::url( $relative ) . '"><img class="wp-image-' . $id . '" src="' . Node_Image_Repair::url( $missing ) . '" alt="日本語の代替テキスト"></a>' ) );
		return compact( 'id', 'post', 'file', 'scaled', 'relative', 'missing' );
	}
	private function row( array $f, ?string $relative = null ): array {
		$row = Node_Image_Repair::inspect( array( 'id' => $f['id'], 'url' => Node_Image_Repair::url( $relative ?? $f['missing'] ), 'context' => 'src' ) );
		$row['post_id'] = $f['post']; $row['snapshot'] = Node_Image_Repair::snapshot( $f['post'], $f['id'] );
		return $row;
	}
	private function repair( array $row ): string {
		$key = 'node_ir_test_' . wp_generate_uuid4(); $this->keys[] = $key;
		$result = Node_Image_Repair::repair( $row, $key );
		$this->assertSame( 'done', $result['state'] );
		$this->files[] = Node_Image_Repair::path( $row['relative'] );
		return $key;
	}
	public function tear_down(): void {
		foreach ( $this->files as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
		foreach ( $this->keys as $key ) { delete_option( $key ); }
		parent::tear_down();
	}
	public function test_legacy_scaled_mismatch_is_repaired_at_exact_old_url_without_content_changes(): void {
		$f = $this->fixture(); $row = $this->row( $f );
		$this->assertSame( '修復可能', $row['status'] ); $this->assertTrue( $row['legacy'] );
		$before = $row['snapshot']; $key = $this->repair( $row );
		$this->assertSame( $before, Node_Image_Repair::snapshot( $f['post'], $f['id'] ) );
		$this->assertSame( 1024, Node_Image_Repair::image( $f['missing'] )[0] );
		$this->assertSame( 'image/png', Node_Image_Repair::image( $f['missing'] )['mime'] );
		$this->assertSame( 'already_ok', Node_Image_Repair::repair( $row, $key )['state'] );
		$this->assertSame( 'restored', Node_Image_Repair::restore( $key )['state'] );
		$this->assertFileExists( Node_Image_Repair::path( $f['missing'] ) );
	}
	public function test_metadata_only_missing_size_and_scaled_only_source(): void {
		$f = $this->fixture(); unlink( $f['file'] );
		$m = wp_get_attachment_metadata( $f['id'] );
		$relative = dirname( $m['file'] ) . '/' . $m['sizes']['large']['file'];
		$row = $this->row( $f, $relative ); $this->assertFalse( $row['legacy'] );
		$this->repair( $row ); $this->assertNotNull( Node_Image_Repair::image( $relative ) );
	}
	public function test_no_source_requires_restoration_and_wrong_id_is_not_used(): void {
		$f = $this->fixture(); unlink( $f['file'] ); unlink( $f['scaled'] );
		$this->assertSame( '復元元が必要', $this->row( $f )['status'] );
		$this->assertSame( '判定不能', $this->row( $f, dirname( $f['relative'] ) . '/unrelated-1024x640.png' )['status'] );
	}
	public function test_japanese_webp_filename_and_srcset_html_gutenberg_gallery(): void {
		$f = $this->fixture( '日本語画像.webp', 'image/webp' ); $url = Node_Image_Repair::url( $f['missing'] );
		$content = '<!-- wp:gallery --><!-- wp:image {"id":' . $f['id'] . ',"url":"' . $url . '"} --><figure><img class="wp-image-' . $f['id'] . '" src="' . $url . '" srcset="' . $url . ' 1024w" alt="保持"><figcaption>説明</figcaption></figure><!-- /wp:image --><!-- /wp:gallery -->';
		wp_update_post( wp_slash( array( 'ID' => $f['post'], 'post_content' => $content ) ) );
		$refs = Node_Image_Repair::references( get_post( $f['post'] ) );
		$this->assertContains( 'srcset', array_column( $refs, 'context' ) );
		$this->assertContains( 'block:url', array_column( $refs, 'context' ) );
		$this->repair( $this->row( $f ) );
		$this->assertSame( $content, get_post_field( 'post_content', $f['post'], 'raw' ) );
		$this->assertSame( 'image/webp', Node_Image_Repair::image( $f['missing'] )['mime'] );
	}
	public function test_html_response_is_not_an_image_and_existing_file_is_never_overwritten(): void {
		$f = $this->fixture(); $path = Node_Image_Repair::path( $f['missing'] );
		file_put_contents( $path, '<html>404 Not Found</html>' ); $this->files[] = $path;
		$this->assertSame( '判定不能', $this->row( $f )['status'] );
		$this->assertNull( Node_Image_Repair::image( $f['missing'] ) );
	}
	public function test_conflicts_before_repair_and_restore_preserve_user_edits(): void {
		$f = $this->fixture(); $row = $this->row( $f ); $key = $this->repair( $row );
		wp_update_post( array( 'ID' => $f['post'], 'post_content' => '修復後の編集' ) );
		try { Node_Image_Repair::restore( $key ); $this->fail( 'Expected conflict' ); } catch ( RuntimeException $e ) { $this->assertStringContainsString( '競合', $e->getMessage() ); }
		$this->assertSame( '修復後の編集', get_post_field( 'post_content', $f['post'], 'raw' ) );
		unlink( Node_Image_Repair::path( $f['missing'] ) );
		$this->expectException( RuntimeException::class ); Node_Image_Repair::repair( $row, $key . '_retry' );
	}
	public function test_paths_external_urls_and_symlinks_are_rejected(): void {
		$this->assertNull( Node_Image_Repair::path( '../wp-config.php' ) );
		$this->assertNull( Node_Image_Repair::relative( 'http://169.254.169.254/latest/meta-data/' ) );
		$this->assertNull( Node_Image_Repair::relative( wp_get_upload_dir()['baseurl'] . '/%2e%2e/wp-config.php' ) );
		$f = $this->fixture(); $link = dirname( $f['file'] ) . '/node-repair-link.png'; symlink( __FILE__, $link );
		try { $this->assertNull( Node_Image_Repair::path( _wp_relative_upload_path( $link ) ) ); } finally { unlink( $link ); }
	}
	public function test_unknown_crop_is_not_synthesized_and_normal_image_unchanged(): void {
		$f = $this->fixture(); $hash = hash_file( 'sha256', $f['file'] );
		$this->assertSame( '正常', $this->row( $f, $f['relative'] )['status'] );
		$crop = str_replace( '-1024x640', '-150x150', $f['missing'] );
		$this->assertSame( '判定不能', $this->row( $f, $crop )['status'] );
		$this->assertSame( $hash, hash_file( 'sha256', $f['file'] ) );
	}
}
