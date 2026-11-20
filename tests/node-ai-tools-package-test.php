<?php
/**
 * 単体配布 ZIP（production_plugins/node-ai-tools.zip）の静的ガード。
 *
 * 単体プラグインとして入れる ZIP は、トップレベルが `node-ai-tools/` でなければ
 * WordPress がプラグインとして認識しない（wp-content/plugins 直下1階層のみを走査するため）。
 * また、テーマ側の同梱ロジック（functions.php の $embedded_plugins）は
 * active_plugins に `node-ai-tools/node-ai-tools.php` があるときだけ同梱版の読み込みを止めるので、
 * この構造が崩れると二重読み込みで Cannot redeclare の致命的エラーになる。
 *
 * @package Node_AI_Tools
 */

class Node_AI_Tools_Package_Test extends WP_UnitTestCase {

	private const ZIP_ROOT = 'node-ai-tools/';

	/**
	 * 入っていなければならないファイル（ファクトチェックの新規ファイルを含む）。
	 */
	private const REQUIRED_FILES = array(
		'node-ai-tools.php',
		'includes/class-ai-core.php',
		'includes/providers/class-provider-openai.php',
		'includes/providers/class-provider-qwen.php',
		'includes/class-gemini-api.php',
		'includes/class-fact-check-models.php',
		'includes/class-fact-check-runner.php',
		'includes/class-fact-check-sources.php',
		'includes/class-fact-check-discovery.php',
		'includes/fact-check-verdict.php',
		'includes/fact-check-render.php',
		'includes/auto-check.php',
		'includes/ajax-handlers.php',
		'admin/settings-page-ai.php',
		'admin/meta-box-fact-check.php',
		'assets/js/editor-article-check.js',
	);

	/**
	 * 入ってはいけないもの（開発用の混入物）。
	 */
	private const FORBIDDEN_PATTERNS = array(
		'#(^|/)\.DS_Store$#',
		'#(^|/)node_modules/#',
		'#(^|/)tests/#',
		'#(^|/)\.git#',
	);

	private function zip_path(): string {
		return dirname( __DIR__ ) . '/production_plugins/node-ai-tools.zip';
	}

	/**
	 * @return array<int, string> ZIP 内のエントリ一覧。
	 */
	private function entries(): array {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $this->zip_path() ), 'ZIP を開けませんでした。' );

		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entries[] = (string) $zip->getNameIndex( $i );
		}
		$zip->close();

		return $entries;
	}

	public function test_zip_exists() {
		$this->assertFileExists( $this->zip_path() );
	}

	public function test_zip_root_is_plugin_folder() {
		foreach ( $this->entries() as $entry ) {
			$this->assertStringStartsWith(
				self::ZIP_ROOT,
				$entry,
				'ZIP のトップレベルは node-ai-tools/ でなければなりません: ' . $entry
			);
		}
	}

	public function test_required_files_are_present() {
		$entries = $this->entries();

		foreach ( self::REQUIRED_FILES as $file ) {
			$this->assertContains(
				self::ZIP_ROOT . $file,
				$entries,
				$file . ' が配布 ZIP に入っていません。'
			);
		}
	}

	public function test_no_development_artifacts() {
		foreach ( $this->entries() as $entry ) {
			foreach ( self::FORBIDDEN_PATTERNS as $pattern ) {
				$this->assertDoesNotMatchRegularExpression(
					$pattern,
					$entry,
					'配布 ZIP に開発用ファイルが混入しています: ' . $entry
				);
			}
		}
	}

	/**
	 * ZIP 内のプラグイン本体が、リポジトリの現在のバージョンと一致すること。
	 */
	public function test_zip_is_in_sync_with_repository_version() {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $this->zip_path() ) );
		$packaged = (string) $zip->getFromName( self::ZIP_ROOT . 'node-ai-tools.php' );
		$zip->close();

		$source = (string) file_get_contents(
			dirname( __DIR__ ) . '/plugins-embedded/node-ai-tools/node-ai-tools.php'
		);

		$this->assertNotSame( '', $packaged );
		$this->assertTrue(
			(bool) preg_match( '/Version:\s*([0-9][0-9.a-z-]*)/i', $packaged, $zip_version )
		);
		$this->assertTrue(
			(bool) preg_match( '/Version:\s*([0-9][0-9.a-z-]*)/i', $source, $src_version )
		);
		$this->assertSame( $src_version[1], $zip_version[1] );

		// 中身が古いまま配布されないよう、本体ファイルの一致まで見る
		$this->assertSame(
			md5( $source ),
			md5( $packaged ),
			'ZIP 内の node-ai-tools.php がリポジトリと一致しません。ZIP を作り直してください。'
		);
	}
	public function test_all_packaged_source_files_match_the_single_source(): void {
		$root = dirname( __DIR__ ) . '/plugins-embedded/node-ai-tools';
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $this->zip_path() ) );
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && '.DS_Store' !== $file->getFilename() ) {
				$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
				$this->assertSame( file_get_contents( $file->getPathname() ), $zip->getFromName( self::ZIP_ROOT . $relative ), $relative );
			}
		}
		$zip->close();
	}

}
