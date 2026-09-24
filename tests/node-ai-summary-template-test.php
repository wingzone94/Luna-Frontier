<?php
/**
 * AI要約の色を投稿メタから引き継ぐ表示テスト。
 */
class Node_AI_Summary_Template_Test extends WP_UnitTestCase {
	public function test_single_summary_uses_valid_tone_color_and_falls_back_for_invalid_value(): void {
		ob_start();
		get_template_part(
			'template-parts/ai-summary',
			null,
			array( 'summary' => '要約', 'mode' => 'single', 'tone_color' => '#123abc' )
		);
		$valid_html = ob_get_clean();
		$this->assertStringContainsString( '--ai-vibe-color: #123abc;', $valid_html );

		ob_start();
		get_template_part(
			'template-parts/ai-summary',
			null,
			array( 'summary' => '要約', 'mode' => 'single', 'tone_color' => 'red; color: black' )
		);
		$invalid_html = ob_get_clean();
		$this->assertStringContainsString( '--ai-vibe-color: #FF9800;', $invalid_html );
	}
}
