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
		file_put_contents( $source . '/style.css', "/*\nTheme Name: Node\nVersion: {$version}\n*/\n" );
		file_put_contents( $source . '/index.php', '<?php' );
		file_put_contents( $source . '/functions.php', '<?php' );
		file_put_contents( $source . '/build.json', wp_json_encode( array( 'version' => $version, 'build_id' => $build_id ) ) );
		return $source;
	}

	public function test_package_validation_rejects_older_and_same_build(): void {
		$source = $this->create_package( '1.4.0', 'new-build' );
		$this->assertWPError( node_validate_theme_update_package( $source, '1.4.1', 'old-build' ) );
		$this->assertWPError( node_validate_theme_update_package( $source, '1.4.0', 'new-build' ) );
		$this->assertSame( 'new-build', node_validate_theme_update_package( $source, '1.4.0', 'old-build' )['build_id'] );
	}

	public function test_swap_removes_obsolete_files_after_success(): void {
		$source = $this->create_package( '1.4.1', 'new-build' );
		$theme  = $this->root . '/Node';
		wp_mkdir_p( $theme );
		file_put_contents( $theme . '/obsolete.php', '<?php' );

		$this->assertTrue( node_swap_theme_update_directory( $this->filesystem, $source, $theme ) );
		$this->assertFileDoesNotExist( $theme . '/obsolete.php' );
		$this->assertFileExists( $theme . '/build.json' );
		$this->assertDirectoryDoesNotExist( $theme . '_previous_update' );
	}

	public function test_failed_switch_restores_original_theme(): void {
		$source = $this->create_package( '1.4.1', 'new-build' );
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
}
