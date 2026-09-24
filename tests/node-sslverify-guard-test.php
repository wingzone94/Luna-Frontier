<?php
/**
 * sslverify 静的ガード（T-8 / F-2）。
 *
 * リポジトリ内PHPの 'sslverify' => false の出現箇所を既存許容2件に凍結し、
 * 「SSL検証無効化の新規追加は禁止」の原則を機械的に強制する。
 * あわせて node_resolve_redirect() が既定（sslverify=true）でHTTPリクエストを
 * 発行することを pre_http_request フィルタの捕捉で検証する。
 *
 * @package Node_Theme
 */

class Node_Sslverify_Guard_Test extends WP_UnitTestCase {

	/**
	 * 許容リスト: 相対パス => 'sslverify' => false の出現回数。
	 * 既存2件のみ。新規追加は禁止（AGENTS.md / STRUCTURAL-REVIEW-1.2.md F-2）。
	 */
	private const ALLOWED_OCCURRENCES = [
		'plugins-embedded/luminous-nexus/includes/shortcode-blogcard.php' => 1,
		'plugins-embedded/node-library/node-library.php'                 => 1,
	];

	/**
	 * 走査から除外するリポジトリ直下のディレクトリ。
	 */
	private const EXCLUDED_DIRS = [ 'node_modules', 'vendor', 'scratch', '.tmp', 'tests', '.git', '.claude' ];

	public function test_sslverify_false_occurrences_are_frozen(): void {
		$root  = dirname( __DIR__ );
		$found = [];

		$directory = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );
		$filter    = new RecursiveCallbackFilterIterator(
			$directory,
			function ( SplFileInfo $file ) use ( $root ) {
				$relative = ltrim( substr( $file->getPathname(), strlen( $root ) ), '/' );
				foreach ( self::EXCLUDED_DIRS as $skip ) {
					if ( $relative === $skip || 0 === strpos( $relative, $skip . '/' ) ) {
						return false;
					}
				}
				return true;
			}
		);

		foreach ( new RecursiveIteratorIterator( $filter ) as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$count = preg_match_all(
				'/[\'"]sslverify[\'"]\s*=>\s*false/i',
				(string) file_get_contents( $file->getPathname() )
			);

			if ( $count > 0 ) {
				$relative           = ltrim( substr( $file->getPathname(), strlen( $root ) ), '/' );
				$found[ $relative ] = $count;
			}
		}

		$expected = self::ALLOWED_OCCURRENCES;
		ksort( $expected );
		ksort( $found );

		$this->assertSame(
			$expected,
			$found,
			"'sslverify' => false の出現箇所が許容リストと一致しません。新規のSSL検証無効化は禁止です（後退させない原則）。"
		);
	}

	public function test_node_resolve_redirect_uses_default_sslverify(): void {
		$captured_args = null;

		$preempt = function ( $preempt, $parsed_args, $url ) use ( &$captured_args ) {
			$captured_args = $parsed_args;
			return [
				'headers'  => [ 'location' => 'https://example.com/final' ],
				'body'     => '',
				'response' => [
					'code'    => 301,
					'message' => 'Moved Permanently',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $preempt, 10, 3 );

		// transientキャッシュを避けるため毎回ユニークなURLを使う。
		$resolved = node_resolve_redirect( 'https://example.com/sslverify-guard-' . uniqid() );

		remove_filter( 'pre_http_request', $preempt, 10 );

		$this->assertIsArray( $captured_args, 'pre_http_request でリクエスト引数を捕捉できること' );
		$this->assertArrayHasKey( 'sslverify', $captured_args );
		$this->assertTrue( $captured_args['sslverify'], 'node_resolve_redirect() は sslverify 既定(true)でリクエストすること（F-2撤回の凍結）' );
		$this->assertSame( 'https://example.com/final', $resolved );
	}
}
