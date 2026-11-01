<?php
/**
 * node_resolve_vite_build() の契約テスト。
 *
 * dist / assets の2つのツリーから、契約を満たす manifest を「全体採用」で選ぶ
 * （新旧チャンクの混在を禁止する）。DB も ZIP も使わない。ファイル配置の mv だけで検証する。
 */

require_once dirname( __DIR__ ) . '/inc/theme-setup.php';

if ( ! defined( 'NODE_THEME_DIR' ) ) {
	define( 'NODE_THEME_DIR', dirname( __DIR__ ) );
}
if ( ! defined( 'NODE_THEME_URI' ) ) {
	define( 'NODE_THEME_URI', 'https://example.com/themes/node' );
}

class Node_Vite_Manifest_Test extends WP_UnitTestCase {
	private string $root;

	public function set_up(): void {
		parent::set_up();
		$this->root = sys_get_temp_dir() . '/node-vite-manifest-test-' . wp_generate_uuid4();
		wp_mkdir_p( $this->root );
	}

	public function tear_down(): void {
		$this->rrmdir( $this->root );
		parent::tear_down();
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir ) ?: array();
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	/**
	 * 指定 base（dist / assets）に、契約を満たす（または満たさない）manifest を書き込む。
	 *
	 * @param array{main?:string,vendor?:string|null,body?:array} $shape
	 */
	private function write_manifest( string $base, array $shape ): array {
		$manifest = $shape['body'] ?? array(
			'src/main.js'          => array( 'file' => $shape['main'] ?? 'js/main.BEC0I5pK.js', 'css' => array( 'css/main.css' ) ),
			'src/vendor.js'        => array( 'file' => $shape['vendor'] ?? 'js/vendor.CZWT1Kl-.js' ),
			'src/luna/luna.js'     => array( 'file' => 'js/luna.js' ),
			'src/styles/style.css' => array( 'file' => 'css/style.css' ),
		);

		$dir = $this->root . '/' . $base;
		wp_mkdir_p( $dir . '/.vite' );
		file_put_contents( $dir . '/.vite/manifest.json', wp_json_encode( $manifest ) );

		// main の実ファイルを置く（存在チェックの対象）。vendor 引きの指定も作る。
		$main = is_string( $manifest['src/main.js']['file'] ?? null ) ? $manifest['src/main.js']['file'] : null;
		if ( is_string( $main ) ) {
			wp_mkdir_p( dirname( $dir . '/' . $main ) );
			file_put_contents( $dir . '/' . $main, '// main' );
		}
		$vendor = is_string( $manifest['src/vendor.js']['file'] ?? null ) ? $manifest['src/vendor.js']['file'] : null;
		if ( is_string( $vendor ) ) {
			wp_mkdir_p( dirname( $dir . '/' . $vendor ) );
			file_put_contents( $dir . '/' . $vendor, '// vendor' );
		}

		return $manifest;
	}

	private function resolve(): ?array {
		return node_resolve_vite_build( $this->root );
	}

	public function test_dist_contract_is_selected_when_it_satisfies_the_contract(): void {
		$this->write_manifest( 'dist', array() );
		$this->write_manifest( 'assets', array() );

		$build = $this->resolve();
		$this->assertNotNull( $build );
		$this->assertSame( 'dist', $build['base'], 'dist と assets が共に契約を満たすときは新側（dist）だけを採用する' );
		$this->assertSame( 'js/main.BEC0I5pK.js', $build['manifest']['src/main.js']['file'] );
	}

	public function test_missing_dist_falls_back_to_valid_assets_manifest(): void {
		$this->write_manifest( 'assets', array() );
		// dist は作らない。

		$build = $this->resolve();
		$this->assertNotNull( $build );
		$this->assertSame( 'assets', $build['base'] );
	}

	public function test_broken_dist_and_valid_assets_select_assets(): void {
		$this->write_manifest(
			'dist',
			array( 'vendor' => null ) // vendor チャンクが無い壊れ manifest。
		);
		$assets = $this->write_manifest( 'assets', array() );

		$build = $this->resolve();
		$this->assertNotNull( $build );
		$this->assertSame( 'assets', $build['base'], 'dist が契約外なら assets フォールバックで配信する' );
		$this->assertSame( $assets['src/main.js']['file'], $build['manifest']['src/main.js']['file'] );
	}

	public function test_both_broken_manifests_resolve_to_null(): void {
		$this->write_manifest( 'dist', array( 'main' => 'js/luna.js' ) ); // main が契約形でない。
		$this->write_manifest( 'assets', array( 'vendor' => null ) );

		$this->assertNull( $this->resolve(), 'どちらも契約外なら null（enqueue を行わない）' );
	}

	public function test_selected_manifest_points_inside_its_own_base(): void {
		$this->write_manifest( 'dist', array() );
		$this->write_manifest( 'assets', array() );

		$build = $this->resolve();
		$this->assertNotNull( $build );

		$main = $build['manifest']['src/main.js']['file'];
		$this->assertTrue(
			is_file( $this->root . '/' . $build['base'] . '/' . $main ),
			'選ばれた main は選んだ base 配下に実在する'
		);
		$this->assertTrue(
			! is_file( $this->root . '/assets/' . $main ) || 'assets' === $build['base'],
			'dist 採用時に assets 側のツリーを参照していない'
		);
	}

	public function test_missing_main_file_in_selected_base_rejects_candidate(): void {
		$this->write_manifest( 'dist', array() );
		$this->write_manifest( 'assets', array() );
		// dist の main 実ファイルを消す（ディスク不一致）。manifest はあるが契約の実在条件を満たさない。
		$main = $this->root . '/dist/js/main.BEC0I5pK.js';
		unlink( $main );

		$build = $this->resolve();
		$this->assertNotNull( $build );
		$this->assertSame( 'assets', $build['base'], '実ファイルの無い manifest は契約外として捨てる' );
	}
}
