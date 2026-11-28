<?php
/**
 * PR-10 フロントエンド性能改善テスト（H-9, M-7, L-2 前半）。
 *
 * @package Luna_Frontier
 */

class Node_Frontend_Perf_PR10_Test extends WP_UnitTestCase {

	public function test_code_font_loads_only_on_singular_posts_with_code_markup(): void {
		$plain_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Plain Post',
				'post_content' => '<p>Hello world</p>',
			)
		);
		$this->go_to( get_permalink( $plain_id ) );
		$this->assertFalse( node_should_load_code_font(), 'コード要素のない投稿ではコードフォントを読み込まないこと' );

		$code_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Code Post',
				'post_content' => '<p>Run <code>bun run build</code></p>',
			)
		);
		$this->go_to( get_permalink( $code_id ) );
		$this->assertTrue( node_should_load_code_font(), '<code> を含む単一投稿ではコードフォントを読み込むこと' );

		$this->go_to( home_url( '/' ) );
		$this->assertFalse( node_should_load_code_font(), 'トップページではコードフォントを読み込まないこと' );
	}

	public function test_blogcard_skips_http_when_manual_overrides_are_complete(): void {
		$http_called = 0;
		$guard       = static function ( $preempt ) use ( &$http_called ) {
			++$http_called;
			return new WP_Error( 'should_not_fetch', 'HTTP must be skipped' );
		};
		add_filter( 'pre_http_request', $guard, 10, 1 );
		try {
			$html = node_render_blogcard(
				'https://example.org/manual-card-test',
				false,
				array(
					'title'       => 'Manual Card Title',
					'description' => 'Manual Description',
				)
			);
		} finally {
			remove_filter( 'pre_http_request', $guard, 10 );
		}
		$this->assertSame( 0, $http_called, '手動オーバーライド充足時は外部 HTTP 取得をスキップすること' );
		$this->assertStringContainsString( 'Manual Card Title', $html );
	}
}
