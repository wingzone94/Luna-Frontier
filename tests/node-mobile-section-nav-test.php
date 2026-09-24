<?php
/**
 * 撤去済みのモバイルセクションナビと読了ゲージの回帰網。
 *
 * @package Node
 */

class Node_Mobile_Section_Nav_Test extends WP_UnitTestCase {

	private function theme_path( string $relative ): string {
		return dirname( __DIR__ ) . '/' . $relative;
	}

	public function test_removed_section_nav_does_not_return(): void {
		$header = file_get_contents( $this->theme_path( 'header.php' ) );

		$this->assertNotFalse( $header );
		$this->assertStringNotContainsString( 'm3-mobile-section-nav', $header );
		$this->assertStringNotContainsString( 'PEEK_SCROLL_MIN', $header );
	}

	public function test_mobile_category_pills_use_available_width(): void {
		$css = file_get_contents( $this->theme_path( 'src/styles/_cards.css' ) );
		$this->assertNotFalse( $css );
		$this->assertStringNotContainsString( 'max-inline-size: clamp(5rem, 26vw, 8rem);', $css );
		$this->assertStringContainsString( 'max-inline-size: 100%;', $css );
	}

	public function test_reading_progress_stays_visible_when_header_hides(): void {
		$header = file_get_contents( $this->theme_path( 'header.php' ) );
		$css    = file_get_contents( $this->theme_path( 'src/styles/_header.css' ) );

		$this->assertNotFalse( $header );
		$this->assertNotFalse( $css );
		$this->assertDoesNotMatchRegularExpression(
			'/<header[^>]*>[\s\S]*m3-reading-progress[\s\S]*<\/header>/',
			$header,
			'読了ゲージは header の transform の外に置く'
		);
		$this->assertStringContainsString(
			'.m3-header.is-hidden ~ .m3-header__progress-container.is-visible',
			$css,
			'ヘッダー退避後も読了ゲージの位置をノッチ下へ戻す'
		);
		$this->assertStringContainsString(
			'--safe-area-top',
			$css
		);

		$compiled = file_get_contents( $this->theme_path( 'assets/css/style.css' ) );
		$this->assertNotFalse( $compiled );
		$this->assertMatchesRegularExpression(
			'/\.m3-header\.is-hidden\s*~\s*\.m3-header__progress-container/',
			$compiled,
			'ビルド済み CSS にもヘッダー退避時のゲージ位置が入っていること'
		);
	}
}
