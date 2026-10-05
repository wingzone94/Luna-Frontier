<?php
/**
 * Luna Frontier Preview ZIPと同梱プラグイン配布物の静的ガード。
 *
 * @package Luna_Frontier
 */

class Luna_Release_Package_Test extends WP_UnitTestCase {
	private const THEME_ROOT = 'luna-frontier/';

	private function repo_dir(): string {
		return dirname( __DIR__ );
	}

	private function theme_zip(): string {
		return $this->repo_dir() . '/luna.zip';
	}

	private function contents( string $archive, string $path ): string {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $archive ), 'ZIPを開けません: ' . basename( $archive ) );
		$value = $zip->getFromName( $path );
		$zip->close();
		return is_string( $value ) ? $value : '';
	}

	public function test_preview_zip_has_expected_root_and_metadata(): void {
		$this->assertFileExists( $this->theme_zip() );
		$this->assertLessThan( 10 * 1024 * 1024, filesize( $this->theme_zip() ), 'Preview ZIPは重複フォントを避けて10MiB未満に保ちます' );
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $this->theme_zip() ) );
		$entries = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entries[] = (string) $zip->getNameIndex( $i );
		}
		$zip->close();
		foreach ( $entries as $entry ) {
			$this->assertStringStartsWith( self::THEME_ROOT, $entry, 'テーマZIPのルートが不正: ' . $entry );
			$this->assertDoesNotMatchRegularExpression( '#^luna-frontier/(vendor|tests|node_modules|\.git)/#', $entry );
		}
		foreach ( $entries as $entry ) {
			$this->assertDoesNotMatchRegularExpression( '#\.(?:ttf|otf|woff2?)$#i', $entry, 'フォントはCDNから取得するため配布ZIPに収録しません' );
		}

		$style = $this->contents( $this->theme_zip(), self::THEME_ROOT . 'style.css' );
		$this->assertSame( 1, preg_match( '/^Version:\s*(\S+)/m', $style, $version_match ) );
		$this->assertSame( '2.0.0', $version_match[1] );
		$build = json_decode( $this->contents( $this->theme_zip(), self::THEME_ROOT . 'build.json' ), true );
		$this->assertIsArray( $build );
		$this->assertSame( $version_match[1], (string) ( $build['version'] ?? '' ) );
		$repo_build = json_decode( (string) file_get_contents( $this->repo_dir() . '/build.json' ), true );
		$this->assertSame( (string) ( $repo_build['build_id'] ?? '' ), (string) ( $build['build_id'] ?? '' ) );
	}

	public function test_embedded_plugins_use_luna_brand_and_140_version(): void {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $this->theme_zip() ) );
		$found = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( ! preg_match( '#^luna-frontier/plugins-embedded/[^/]+/[^/]+\.php$#', $name ) ) {
				continue;
			}
			$header = (string) $zip->getFromIndex( $i );
			if ( ! preg_match( '/^\s*\*?\s*Plugin Name:/m', substr( $header, 0, 1200 ) ) ) {
				continue;
			}
			$this->assertMatchesRegularExpression( '/^\s*\*?\s*Plugin Name:\s*Luna\b/m', substr( $header, 0, 1200 ), $name );
			$this->assertMatchesRegularExpression( '/^\s*\*?\s*Version:\s*1\.4\.0\s*$/m', substr( $header, 0, 1200 ), $name );
			$found++;
		}
		$zip->close();
		$this->assertSame( 13, $found, 'Lunaブランド・1.4.0の埋め込みプラグイン数' );
	}

	public function test_standalone_plugin_archives_keep_slug_and_luna_140_header(): void {
		$archives = glob( $this->repo_dir() . '/production_plugins/*.zip' ) ?: [];
		$this->assertCount( 11, $archives );
		foreach ( $archives as $archive ) {
			$zip = new ZipArchive();
			$this->assertTrue( true === $zip->open( $archive ), 'ZIPを開けません: ' . basename( $archive ) );
			$main = null;
			$roots = [];
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );
				$roots[ strtok( $name, '/' ) ] = true;
				if ( str_ends_with( $name, '.php' ) && preg_match( '/^\s*\*?\s*Plugin Name:/m', substr( (string) $zip->getFromIndex( $i ), 0, 1200 ) ) ) {
					$main = (string) $zip->getFromIndex( $i );
				}
			}
			$zip->close();
			$this->assertSame( [ pathinfo( $archive, PATHINFO_FILENAME ) => true ], $roots, basename( $archive ) . ' のZIPルート/slug' );
			$this->assertNotNull( $main, basename( $archive ) . ' のメインファイル' );
			$this->assertMatchesRegularExpression( '/^\s*\*?\s*Plugin Name:\s*Luna\b/m', substr( (string) $main, 0, 1200 ), basename( $archive ) );
			$this->assertMatchesRegularExpression( '/^\s*\*?\s*Version:\s*1\.4\.0\s*$/m', substr( (string) $main, 0, 1200 ), basename( $archive ) );
		}
	}

	/** 画像修復と実行時PHPが、検証したソースと同じ内容で同梱されること。 */
	public function test_image_repair_and_runtime_php_match_source(): void {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $this->theme_zip() ) );
		$required = [
			'inc/image-repair.php',
			'inc/image-repair-admin.php',
			'assets/js/image-repair.js',
			'plugins-embedded/node-image-compressor/includes/class-webp-converter.php',
		];
		$source = $this->repo_dir() . '/src';
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$required[] = 'src/' . str_replace( $source . '/', '', $file->getPathname() );
			}
		}
		foreach ( $required as $relative ) {
			$this->assertSame(
				file_get_contents( $this->repo_dir() . '/' . $relative ),
				$zip->getFromName( self::THEME_ROOT . $relative ),
				$relative . ' がZIP内で欠損またはソースと不一致'
			);
		}
		$zip->close();
	}
}
