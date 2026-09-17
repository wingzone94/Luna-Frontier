<?php
/**
 * モバイルセクションナビ / 読了ゲージの回帰網。
 *
 * 2026-09-03: 初回閲覧で左上が展開されたまま出る・記事閲覧中にシークバーが
 * 隠れる、という実機報告への再発防止。
 *
 * @package Node
 */

class Node_Mobile_Section_Nav_Test extends WP_UnitTestCase {

	private function theme_path( string $relative ): string {
		return dirname( __DIR__ ) . '/' . $relative;
	}

	public function test_section_nav_is_gated_to_home_first_page(): void {
		$header = file_get_contents( $this->theme_path( 'header.php' ) );

		$this->assertNotFalse( $header );
		$this->assertMatchesRegularExpression(
			'/is_home\(\)\s*\|\|\s*is_front_page\(\)/',
			$header,
			'セクションナビはホーム（またはフロント）に限定する'
		);
		$this->assertStringContainsString(
			'! is_paged()',
			$header,
			'2ページ目以降のホームには出さない'
		);
		$this->assertStringContainsString(
			'm3-mobile-section-nav',
			$header
		);
	}

	public function test_peek_requires_real_scroll_and_closes_while_reading(): void {
		$header = file_get_contents( $this->theme_path( 'header.php' ) );

		$this->assertNotFalse( $header );
		$this->assertStringContainsString(
			'PEEK_SCROLL_MIN',
			$header,
			'読み込み直後の偽スクロールでは開かない'
		);
		$this->assertStringContainsString(
			'closeMenu',
			$header,
			'読み続けるスクロールでは畳む'
		);
		$this->assertStringNotContainsString(
			"nav.addEventListener('pointerdown', cancelPeek",
			$header,
			'展開中リストへのタップで自動クローズを打ち消さない'
		);
		$this->assertStringContainsString(
			'prefers-reduced-motion',
			$header,
			'動きを減らす設定では自動展開しない'
		);
		$css = file_get_contents( $this->theme_path( 'src/styles/_header.css' ) );
		$this->assertNotFalse( $css );
		$this->assertStringContainsString(
			'- 100%),',
			$css,
			'ヘッダー退避時はナビ全体を画面外へ移動する'
		);
	}


	public function test_mobile_category_pills_have_width_cap(): void {
		$css = file_get_contents( $this->theme_path( 'src/styles/_cards.css' ) );
		$this->assertNotFalse( $css );
		$this->assertStringContainsString(
			'max-inline-size: clamp(5rem, 26vw, 8rem);',
			$css,
			'長いカテゴリ名のピルをモバイル幅で制限する'
		);
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
