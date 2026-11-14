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
		// 公開済みアップデーターは、有効なテーマメタデータを持つ配置だけを交換する。
		$this->assertTrue( copy_dir( $source, $theme ) );
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
		$this->assertTrue( copy_dir( $source, $theme ) );
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

	public function test_release_meta_validation_and_url_allowlist(): void {
		$tag = 'luna-frontier-v2.0.4-20261113T070327Z-70a6808';
		$zip_url = "https://github.com/wingzone94/Luna-Frontier/releases/download/{$tag}/luna.zip";
		$json_url = "https://github.com/wingzone94/Luna-Frontier/releases/download/{$tag}/luna-release.json";

		$this->assertTrue( node_is_luna_release_download_url( $zip_url, 'luna.zip' ) );
		$this->assertTrue( node_is_luna_release_download_url( $json_url, 'luna-release.json' ) );
		$this->assertFalse( node_is_luna_release_download_url( 'https://raw.githubusercontent.com/wingzone94/Luna-Frontier/luna-frontier-2.0-skyalow/style.css', 'style.css' ) );
		$this->assertFalse( node_is_luna_release_download_url( $zip_url . '?token=1', 'luna.zip' ) );
		$this->assertFalse( node_is_luna_release_download_url( $zip_url, 'other.zip' ) );

		$valid_meta = array(
			'tag'      => $tag,
			'version'  => '2.0.4',
			'build_id' => '20261113T070327Z-70a6808',
			'asset'    => 'luna.zip',
			'sha256'   => str_repeat( 'a', 64 ),
		);
		$release = array( 'tag_name' => $tag );
		$this->assertTrue( node_validate_luna_release_meta( $valid_meta, $release ) );

		$bad_tag = $valid_meta;
		$bad_tag['version'] = '2.0.5';
		$this->assertWPError( node_validate_luna_release_meta( $bad_tag, $release ) );

		$zip_file = $this->root . '/sample.zip';
		file_put_contents( $zip_file, 'luna-zip-payload' );
		$expected_sha = hash_file( 'sha256', $zip_file );
		$this->assertTrue( node_verify_zip_sha256( $zip_file, $expected_sha ) );
		$this->assertWPError( node_verify_zip_sha256( $zip_file, str_repeat( '0', 64 ) ) );
	}

	public function test_select_luna_release_picks_newer_build_within_same_version_offline(): void {
		$older_tag = 'luna-frontier-v2.0.4-20261112T132528Z-a0afbd3';
		$newer_tag = 'luna-frontier-v2.0.4-20261113T070327Z-70a6808';
		$draft_tag = 'luna-frontier-v2.0.5-20261114T000000Z-1234567';

		$releases = array(
			array(
				'tag_name' => $draft_tag,
				'draft'    => true,
				'assets'   => array(),
			),
			array(
				'tag_name' => 'node-v1.4.0',
				'draft'    => false,
				'assets'   => array(),
			),
			array(
				'tag_name' => $older_tag,
				'draft'    => false,
				'assets'   => array(
					array(
						'name'                 => 'luna-release.json',
						'browser_download_url' => "https://github.com/wingzone94/Luna-Frontier/releases/download/{$older_tag}/luna-release.json",
					),
				),
			),
			array(
				'tag_name' => $newer_tag,
				'draft'    => false,
				'assets'   => array(
					array(
						'name'                 => 'luna-release.json',
						'browser_download_url' => "https://github.com/wingzone94/Luna-Frontier/releases/download/{$newer_tag}/luna-release.json",
					),
				),
			),
		);

		$meta_by_url = array(
			"https://github.com/wingzone94/Luna-Frontier/releases/download/{$older_tag}/luna-release.json" => array(
				'tag'      => $older_tag,
				'version'  => '2.0.4',
				'build_id' => '20261112T132528Z-a0afbd3',
				'asset'    => 'luna.zip',
				'sha256'   => str_repeat( 'b', 64 ),
			),
			"https://github.com/wingzone94/Luna-Frontier/releases/download/{$newer_tag}/luna-release.json" => array(
				'tag'      => $newer_tag,
				'version'  => '2.0.4',
				'build_id' => '20261113T070327Z-70a6808',
				'asset'    => 'luna.zip',
				'sha256'   => str_repeat( 'c', 64 ),
			),
		);

		$filter = static function ( $preempt, array $args, string $url ) use ( $releases, $meta_by_url ) {
			if ( LUNA_UPDATE_API . '/releases?per_page=20' === $url ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $releases ),
				);
			}
			if ( isset( $meta_by_url[ $url ] ) && ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], wp_json_encode( $meta_by_url[ $url ] ) );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		try {
			$selected = node_select_luna_release( '2.0.4', '20261112T132528Z-a0afbd3' );
			$this->assertIsArray( $selected );
			$this->assertSame( $newer_tag, $selected['meta']['tag'] );

			$none = node_select_luna_release( '2.0.4', '20261113T070327Z-70a6808' );
			$this->assertWPError( $none );
			$this->assertSame( 'luna_update_none', $none->get_error_code() );
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}
	}
}
