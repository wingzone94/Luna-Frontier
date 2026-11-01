<?php
/**
 * Staged theme updater tests.
 */

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
require_once dirname( __DIR__ ) . '/inc/theme-setup.php';

if ( ! defined( 'FS_CHMOD_DIR' ) ) {
	define( 'FS_CHMOD_DIR', 0755 );
}
if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}

class Node_Theme_Update_Test extends WP_UnitTestCase {
	private string $root;
	private WP_Filesystem_Direct $filesystem;
	private $previous_filesystem;

	public function set_up(): void {
		parent::set_up();
		$this->root = sys_get_temp_dir() . '/node-update-test-' . wp_generate_uuid4();
		wp_mkdir_p( $this->root );
		$this->filesystem = new WP_Filesystem_Direct( false );
		global $wp_filesystem;
		$this->previous_filesystem = $wp_filesystem;
		$wp_filesystem = $this->filesystem;
	}

	public function tear_down(): void {
		global $wp_filesystem;
		$wp_filesystem = $this->previous_filesystem;
		$this->filesystem->delete( $this->root, true );
		parent::tear_down();
	}

	private function create_package( string $version, string $build_id ): string {
		$source = $this->root . '/package';
		wp_mkdir_p( $source );
		file_put_contents( $source . '/style.css', "/*\nTheme Name: Luna Frontier\nVersion: {$version}\n*/\n" );
		file_put_contents( $source . '/index.php', '<?php' );
		file_put_contents( $source . '/functions.php', '<?php' );
		file_put_contents( $source . '/build.json', wp_json_encode( array( 'version' => $version, 'build_id' => $build_id ) ) );
		return $source;
	}

	public function test_package_validation_rejects_older_and_same_build(): void {
		$source = $this->create_package( '2.0.0-preview.6', 'new-build' );
		$this->assertWPError( node_validate_theme_update_package( $source, '2.0.0-preview.7', 'old-build' ) );
		$this->assertWPError( node_validate_theme_update_package( $source, '2.0.0-preview.6', 'new-build' ) );
		$this->assertSame( 'new-build', node_validate_theme_update_package( $source, '2.0.0-preview.6', 'old-build' )['build_id'] );
	}

	public function test_cached_older_build_cannot_trigger_update_or_downgrade(): void {
		$old = '20261024T093840Z-1e0d61e';
		$new = '20261024T094728Z-5360029';
		$this->assertFalse( node_is_newer_theme_build( $old, $new ) );
		$this->assertFalse( node_is_newer_theme_build( $new, $new ) );
		$this->assertTrue( node_is_newer_theme_build( $new, $old ) );
		$this->assertFalse( node_is_newer_theme_build( null, $new ) );
		$source = $this->create_package( '2.0.1', $old );
		$result = node_validate_theme_update_package( $source, '2.0.1', $new );
		$this->assertWPError( $result );
		$this->assertSame( 'older_theme_build', $result->get_error_code() );
	}

	public function test_luna_zip_root_is_resolved(): void {
		$package = $this->create_package( '2.0.0-preview.6', 'preview-build' );
		$archive = $this->root . '/archive';
		wp_mkdir_p( $archive );
		$this->assertTrue( rename( $package, $archive . '/luna-frontier' ) );
		$this->assertSame( trailingslashit( $archive . '/luna-frontier' ), node_resolve_theme_update_source_dir( $archive ) );
	}

	public function test_swap_removes_obsolete_files_after_success(): void {
		$source = $this->create_package( '2.0.0-preview.7', 'new-build' );
		$theme  = $this->root . '/Node';
		wp_mkdir_p( $theme );
		file_put_contents( $theme . '/obsolete.php', '<?php' );

		$this->assertTrue( node_swap_theme_update_directory( $this->filesystem, $source, $theme ) );
		$this->assertFileDoesNotExist( $theme . '/obsolete.php' );
		$this->assertFileExists( $theme . '/build.json' );
		$this->assertDirectoryDoesNotExist( $theme . '_previous_update' );
	}

	public function test_failed_switch_restores_original_theme(): void {
		$source = $this->create_package( '2.0.0-preview.7', 'new-build' );
		$theme  = $this->root . '/Node';
		wp_mkdir_p( $theme );
		file_put_contents( $theme . '/original.php', '<?php' );

		$filesystem = new class( false ) extends WP_Filesystem_Direct {
			private int $moves = 0;
			public function move( $source, $destination, $overwrite = false ) {
				++$this->moves;
				return 2 === $this->moves ? false : parent::move( $source, $destination, $overwrite );
			}
		};
		global $wp_filesystem;
		$wp_filesystem = $filesystem;

		$this->assertWPError( node_swap_theme_update_directory( $filesystem, $source, $theme ) );
		$this->assertFileExists( $theme . '/original.php' );
		$this->assertDirectoryDoesNotExist( $theme . '_previous_update' );
	}

	/** Release asset の browser_download_url だけを許し、raw / refs/heads / 他ホスト / クエリ / 別asset名を拒否する。 */
	public function test_luna_release_download_url_accepts_only_release_assets(): void {
		$base = 'https://github.com/wingzone94/Luna-Frontier/releases/download/';
		$tag  = 'luna-frontier-v2.0.1-20261029T102715Z-1d1b5d2';

		$this->assertTrue( node_is_luna_release_download_url( $base . $tag . '/luna.zip', 'luna.zip' ) );

		$this->assertFalse( node_is_luna_release_download_url( $base . $tag . '/node.zip', 'luna.zip' ) );
		$this->assertFalse( node_is_luna_release_download_url( $base . $tag . '/luna-release.json', 'luna.zip' ) );

		$this->assertFalse(
			node_is_luna_release_download_url( 'https://github.com/wingzone94/Luna-Frontier/raw/refs/heads/luna-frontier-2.0-skyalow/luna.zip', 'luna.zip' )
		);
		$this->assertFalse(
			node_is_luna_release_download_url( 'https://raw.githubusercontent.com/wingzone94/Luna-Frontier/luna-frontier-2.0-skyalow/luna.zip', 'luna.zip' )
		);
		$this->assertFalse(
			node_is_luna_release_download_url( 'https://example.com/wingzone94/Luna-Frontier/releases/download/' . $tag . '/luna.zip', 'luna.zip' )
		);
		$this->assertFalse( node_is_luna_release_download_url( $base . $tag . '/luna.zip?cb=1', 'luna.zip' ) );
		$this->assertFalse( node_is_luna_release_download_url( $base . 'v2.0.1/luna.zip', 'luna.zip' ) );
	}

	/** sha256 照合: 一致で true、1桁違いで luna_update_sha256。 */
	public function test_zip_sha256_is_verified_against_expected_digest(): void {
		$path = $this->root . '/payload.zip';
		file_put_contents( $path, 'luna-zip-payload' );

		$valid = hash_file( 'sha256', $path );
		$this->assertTrue( node_verify_zip_sha256( $path, (string) $valid ) );

		$forged = str_repeat( '0', 64 );
		$error  = node_verify_zip_sha256( $path, $forged );
		$this->assertWPError( $error );
		$this->assertSame( 'luna_update_sha256', $error->get_error_code() );
	}

	/** luna-release.json の内容は Release 自身（tag_name）と type/ハッシュ/タグ形状のすべてで検証される。 */
	public function test_release_meta_is_validated_against_release_object(): void {
		$tag      = 'luna-frontier-v2.0.1-20261029T102715Z-1d1b5d2';
		$release  = array( 'tag_name' => $tag );
		$build_id = '20261029T102715Z-1d1b5d2';

		$meta = array(
			'tag'      => $tag,
			'version'  => '2.0.1',
			'build_id' => $build_id,
			'asset'    => 'luna.zip',
			'sha256'   => str_repeat( 'a', 64 ),
		);
		$this->assertTrue( node_validate_luna_release_meta( $meta, $release ) );

		// tag が Release の tag_name と違う。
		$tag_mismatch  = $meta;
		$tag_mismatch['tag'] = 'luna-frontier-v2.0.0-20261029T000000Z-0000000';
		$error          = node_validate_luna_release_meta( $tag_mismatch, $release );
		$this->assertWPError( $error );
		$this->assertSame( 'luna_update_meta_tag', $error->get_error_code() );

		// asset 名が luna.zip でない。
		$asset_mismatch = $meta;
		$asset_mismatch['asset'] = 'node.zip';
		$this->assertSame( 'luna_update_meta_asset', node_validate_luna_release_meta( $asset_mismatch, $release )->get_error_code() );

		// sha256 が64桁hexでない。
		$sha_mismatch = $meta;
		$sha_mismatch['sha256'] = 'abc123';
		$this->assertSame( 'luna_update_meta_sha', node_validate_luna_release_meta( $sha_mismatch, $release )->get_error_code() );

		// タグ形状が luna-frontier-v{version}-{build_id} でない（version を書き換えた偽メタ）。
		$shape_mismatch = $meta;
		$shape_mismatch['version'] = '2.0.2';
		$this->assertSame( 'luna_update_meta_tag_shape', node_validate_luna_release_meta( $shape_mismatch, $release )->get_error_code() );

		// version / build_id が空。
		$empty_mismatch = $meta;
		$empty_mismatch['build_id'] = '';
		$this->assertSame( 'luna_update_meta_build', node_validate_luna_release_meta( $empty_mismatch, $release )->get_error_code() );
	}

	/** ライブが runnable で _next_update だけがあるとき、swap が成功し _next_update が消える。 */
	public function test_swap_cleans_next_update_when_live_is_runnable(): void {
		$source = $this->create_package( '2.0.0-preview.7', 'new-build' );
		$theme  = $this->root . '/Node';
		wp_mkdir_p( $theme );

		// ライブを runnable 化（create_package と同じ構成）。
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: Luna Frontier\nVersion: 2.0.0-preview.6\n*/\n" );
		file_put_contents( $theme . '/index.php', '<?php' );
		file_put_contents( $theme . '/functions.php', '<?php' );
		file_put_contents( $theme . '/build.json', wp_json_encode( array( 'version' => '2.0.0-preview.6', 'build_id' => '20261028T000000Z-live000' ) ) );

		wp_mkdir_p( $theme . '_next_update' );
		file_put_contents( $theme . '_next_update/junk.php', '<?php' );

		$this->assertTrue( node_swap_theme_update_directory( $this->filesystem, $source, $theme ) );
		$this->assertDirectoryDoesNotExist( $theme . '_next_update' );
		$this->assertFileDoesNotExist( $theme . '_update.lock' );
	}

	/** ライブの functions.php が無く _next_update があるとき、leftovers で _next_update が残る。 */
	public function test_swap_keeps_leftovers_when_live_is_not_runnable(): void {
		$source = $this->create_package( '2.0.0-preview.7', 'new-build' );
		$theme  = $this->root . '/Node';
		wp_mkdir_p( $theme );

		file_put_contents( $theme . '/style.css', "/*\nTheme Name: Luna Frontier\nVersion: 2.0.0-preview.6\n*/\n" );
		file_put_contents( $theme . '/index.php', '<?php' );
		// functions.php を意図的に作らない。

		wp_mkdir_p( $theme . '_next_update' );
		file_put_contents( $theme . '_next_update/junk.php', '<?php' );

		$error = node_swap_theme_update_directory( $this->filesystem, $source, $theme );
		$this->assertWPError( $error );
		$this->assertSame( 'theme_update_leftovers', $error->get_error_code() );
		$this->assertDirectoryExists( $theme . '_next_update' );
	}

	/** ライブがバックアップ(_previous_update)より新しい場合、削除して swap が成功する。 */
	public function test_swap_removes_previous_update_when_live_is_newer(): void {
		$source = $this->create_package( '2.0.0-preview.8', 'new-build' );
		$theme  = $this->root . '/Node';
		wp_mkdir_p( $theme );

		file_put_contents( $theme . '/style.css', "/*\nTheme Name: Luna Frontier\nVersion: 2.0.0-preview.7\n*/\n" );
		file_put_contents( $theme . '/index.php', '<?php' );
		file_put_contents( $theme . '/functions.php', '<?php' );
		file_put_contents( $theme . '/build.json', wp_json_encode( array( 'version' => '2.0.0-preview.7', 'build_id' => '20261029T000000Z-live001' ) ) );

		// 退避残骸はライブより古い構成。
		wp_mkdir_p( $theme . '_previous_update' );
		file_put_contents( $theme . '_previous_update/style.css', "/*\nTheme Name: Luna Frontier\nVersion: 2.0.0-preview.6\n*/\n" );
		file_put_contents( $theme . '_previous_update/index.php', '<?php' );
		file_put_contents( $theme . '_previous_update/functions.php', '<?php' );
		file_put_contents( $theme . '_previous_update/build.json', wp_json_encode( array( 'version' => '2.0.0-preview.6', 'build_id' => '20261028T000000Z-old0001' ) ) );

		$this->assertTrue( node_swap_theme_update_directory( $this->filesystem, $source, $theme ) );
		$this->assertDirectoryDoesNotExist( $theme . '_previous_update' );
	}

	/** ライブの版が退籍残骸より古いとき、両方残して leftovers。 */
	public function test_swap_keeps_previous_update_when_live_is_older(): void {
		$source = $this->create_package( '2.0.0-preview.8', 'new-build' );
		$theme  = $this->root . '/Node';
		wp_mkdir_p( $theme );

		file_put_contents( $theme . '/style.css', "/*\nTheme Name: Luna Frontier\nVersion: 2.0.0-preview.6\n*/\n" );
		file_put_contents( $theme . '/index.php', '<?php' );
		file_put_contents( $theme . '/functions.php', '<?php' );
		file_put_contents( $theme . '/build.json', wp_json_encode( array( 'version' => '2.0.0-preview.6', 'build_id' => '20261028T000000Z-live000' ) ) );

		// 退避残骸はライブより新しい構成（ロールバック後の世界）。
		wp_mkdir_p( $theme . '_previous_update' );
		file_put_contents( $theme . '_previous_update/style.css', "/*\nTheme Name: Luna Frontier\nVersion: 2.0.0-preview.7\n*/\n" );
		file_put_contents( $theme . '_previous_update/index.php', '<?php' );
		file_put_contents( $theme . '_previous_update/functions.php', '<?php' );
		file_put_contents( $theme . '_previous_update/build.json', wp_json_encode( array( 'version' => '2.0.0-preview.7', 'build_id' => '20261029T000000Z-new001' ) ) );

		$error = node_swap_theme_update_directory( $this->filesystem, $source, $theme );
		$this->assertWPError( $error );
		$this->assertSame( 'theme_update_leftovers', $error->get_error_code() );
		$this->assertDirectoryExists( $theme . '_previous_update' );
		$this->assertDirectoryDoesNotExist( $theme . '_update.lock' );
	}

	/** ロック mtime が900秒未満のとき、in_progress でディレクトリを消さない。 */
	public function test_fresh_lock_blocks_swap_and_keeps_leftovers(): void {
		$source = $this->create_package( '2.0.0-preview.7', 'new-build' );
		$theme  = $this->root . '/Node';
		wp_mkdir_p( $theme );

		file_put_contents( $theme . '/style.css', "/*\nTheme Name: Luna Frontier\nVersion: 2.0.0-preview.6\n*/\n" );
		file_put_contents( $theme . '/index.php', '<?php' );
		file_put_contents( $theme . '/functions.php', '<?php' );
		file_put_contents( $theme . '/build.json', wp_json_encode( array( 'version' => '2.0.0-preview.6', 'build_id' => '20261028T000000Z-live000' ) ) );

		wp_mkdir_p( $theme . '_next_update' );
		file_put_contents( $theme . '_update.lock', (string) time() );

		$error = node_swap_theme_update_directory( $this->filesystem, $source, $theme );
		$this->assertWPError( $error );
		$this->assertSame( 'theme_update_in_progress', $error->get_error_code() );
		$this->assertDirectoryExists( $theme . '_next_update' );
	}
}
