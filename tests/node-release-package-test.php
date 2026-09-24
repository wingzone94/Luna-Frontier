<?php
/**
 * 配布 ZIP（node.zip）の静的ガード。
 *
 * 1.3.0 の準備中、HOW_TO_RELEASE.md の除外リストが 1.2.x 時代のまま
 * `--exclude='src/'` だったため、`src/Setup` `src/Hooks` `src/Controllers` に
 * 置かれたテーマ本体クラス（NodeTheme\ 名前空間）が ZIP から丸ごと抜け、
 * 配布すると有効化時に
 *   Fatal error: Class "NodeTheme\Setup\ThemeSupport" not found
 * で全サイトが落ちる状態のままコミットされていた。
 *
 * 手順書を直すだけでは再発を防げないため、ZIP の中身を機械的に検証する。
 *
 * @package Node_Theme
 */

class Node_Release_Package_Test extends WP_UnitTestCase {

	/**
	 * ZIP 内のテーマルート。
	 */
	private const ZIP_ROOT = 'Node/';

	/**
	 * 配布 ZIP に必ず入っていなければならないファイル。
	 */
	private const REQUIRED_FILES = [
		'style.css',
		'functions.php',
		'build.json',
		'index.php',
		'assets/css/style.css',
		'inc/featured-webp.php',
		'plugins-embedded/luna-interactive/luna-interactive.php',
		'plugins-embedded/luna-interactive/assets/vendor/chart.umd.min.js',
		'plugins-embedded/luna-interactive/assets/vendor/xlsx.full.min.js',
	];

	/**
	 * 配布 ZIP に入ってはいけないパス接頭辞（開発用の成果物）。
	 *
	 * 混入すると ZIP が肥大化する。1.2 の準備時に 8.3MB → 46MB へ膨張した実績がある。
	 */
	private const FORBIDDEN_PREFIXES = [
		'vendor/',
		'tests/',
		'node_modules/',
		'src/styles/',
		'src/scripts/',
		'.git/',
		'.claude/',
		'luna-interactive/',
	];

	private function repo_dir(): string {
		return dirname( __DIR__ );
	}

	private function zip_path(): string {
		return $this->repo_dir() . '/node.zip';
	}

	/**
	 * ZIP 内のエントリ一覧（テーマルートからの相対パス）を返す。
	 *
	 * @return list<string>
	 */
	private function zip_entries(): array {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $this->zip_path() ), 'node.zip を開けない' );

		$entries = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( str_starts_with( $name, self::ZIP_ROOT ) ) {
				$entries[] = substr( $name, strlen( self::ZIP_ROOT ) );
			}
		}
		$zip->close();

		return $entries;
	}

	private function zip_contents( string $relative ): string {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $this->zip_path() ) );
		$body = $zip->getFromName( self::ZIP_ROOT . $relative );
		$zip->close();

		return is_string( $body ) ? $body : '';
	}

	public function test_release_zip_exists(): void {
		$this->assertFileExists( $this->zip_path(), 'node.zip が無い。リリース手順 4-b を実行すること。' );
	}

	/**
	 * オートロード対象の PHP がすべて同梱されていること。
	 *
	 * これが欠けると有効化時に致命的エラーになる（1.3.0 で実際に発生）。
	 */
	public function test_autoloaded_source_classes_are_bundled(): void {
		$src_dir = $this->repo_dir() . '/src';
		if ( ! is_dir( $src_dir ) ) {
			$this->markTestSkipped( 'src/ が無い構成' );
		}

		$expected = [];
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src_dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$expected[] = 'src/' . str_replace( $src_dir . '/', '', $file->getPathname() );
		}

		if ( empty( $expected ) ) {
			$this->markTestSkipped( 'src/ に PHP が無い構成' );
		}

		$entries = $this->zip_entries();
		foreach ( $expected as $relative ) {
			$this->assertContains(
				$relative,
				$entries,
				sprintf(
					'%s が node.zip に入っていない。src/ を丸ごと除外すると有効化時に落ちる（HOW_TO_RELEASE.md 4-b 参照）。',
					$relative
				)
			);
		}
	}

	public function test_required_files_are_bundled(): void {
		$entries = $this->zip_entries();
		foreach ( self::REQUIRED_FILES as $relative ) {
			$this->assertContains( $relative, $entries, $relative . ' が node.zip に入っていない' );
		}
	}

	public function test_development_artifacts_are_not_bundled(): void {
		$entries = $this->zip_entries();
		foreach ( self::FORBIDDEN_PREFIXES as $prefix ) {
			$hit = array_values(
				array_filter(
					$entries,
					static fn( string $e ): bool => str_starts_with( $e, $prefix )
				)
			);
			$this->assertSame( [], $hit, $prefix . ' が node.zip に混入している' );
		}
	}

	/**
	 * 更新チェックはバージョンとビルド ID の両方を見るため、ZIP 内・リポジトリ双方で
	 * style.css と build.json のバージョンが一致していること。
	 */
	public function test_zip_version_matches_stylesheet_and_build_json(): void {
		$style = $this->zip_contents( 'style.css' );
		$this->assertNotSame( '', $style, 'ZIP 内の style.css が読めない' );
		$this->assertSame( 1, preg_match( '/^Version:\s*(\S+)/m', $style, $m ), 'style.css に Version 行が無い' );
		$zip_version = $m[1];

		$build = json_decode( $this->zip_contents( 'build.json' ), true );
		$this->assertIsArray( $build, 'ZIP 内の build.json が読めない' );
		$this->assertSame( $zip_version, (string) ( $build['version'] ?? '' ), 'ZIP 内の style.css と build.json のバージョンが不一致' );

		$repo_build = json_decode( (string) file_get_contents( $this->repo_dir() . '/build.json' ), true );
		$this->assertIsArray( $repo_build );
		$this->assertSame(
			(string) ( $repo_build['build_id'] ?? '' ),
			(string) ( $build['build_id'] ?? '' ),
			'リポジトリの build.json と ZIP 内の build.json の build_id が不一致。ZIP 生成後に build.json を作り直していないか、その逆。'
		);
	}
}
