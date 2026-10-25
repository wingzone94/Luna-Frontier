<?php
/** Contract tests for rendered article-heading wrapping units. */

require_once dirname( __DIR__ ) . '/inc/luna/heading-typography.php';

class Luna_Heading_Typography_Test extends WP_UnitTestCase {
	private function heading_text( string $html ): string {
		return html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	public function test_preserves_text_and_anchor_while_protecting_units(): void {
		$input  = '<h2 class="wp-block-heading" id="day1">1日目：GPT-6 AstraとGPT-6.1 Solを約50%高速化</h2>';
		$output = luna_frontier_wrap_heading_units( $input );
		$this->assertSame( $this->heading_text( $input ), $this->heading_text( $output ) );
		$this->assertStringContainsString( '>約50%高速化</span>', $output );
		$this->assertStringContainsString( '>GPT-6.1</span>', $output );
		$this->assertStringContainsString( 'id="day1"', $output );
		$this->assertSame( $output, luna_frontier_wrap_heading_units( $output ) );
	}

	public function test_preserves_rich_text_links_entities_and_attributes(): void {
		$input  = '<h3 title="約50%削減"><a href="/test/?a=1&amp;b=2"><strong>約60%削減</strong></a> &amp; GPT-6.1</h3>';
		$output = luna_frontier_wrap_heading_units( $input );
		$this->assertSame( $this->heading_text( $input ), $this->heading_text( $output ) );
		$this->assertStringContainsString( '<strong><span', $output );
		$this->assertStringContainsString( 'href="/test/?a=1&amp;b=2"', $output );
		$this->assertStringContainsString( 'title="約50%削減"', $output );
	}

	/** @dataProvider unchanged_heading_cases */
	public function test_preserves_excluded_content( string $input ): void {
		$this->assertSame( $input, luna_frontier_wrap_heading_units( $input ) );
	}

	public function unchanged_heading_cases(): array {
		return array(
			array( '<h2 class="m3-blogcard__title">GPT-6.1</h2>' ),
			array( '<h5>約50%</h5>' ),
			array( '<p>約50%</p>' ),
			array( '<h2><code>GPT-6.1</code></h2>' ),
			array( '<h2>普通の日本語見出し</h2>' ),
			array( '<h2>https://example.com/long-model-6.1?a=1&amp;b=2</h2>' ),
		);
	}

	public function test_frontend_block_rendering_keeps_saved_content_unchanged(): void {
		$content = '<!-- wp:heading --><h2 class="wp-block-heading">GPT-6.1を約50%高速化</h2><!-- /wp:heading -->';
		$post_id = self::factory()->post->create( array( 'post_content' => $content ) );
		$this->go_to( get_permalink( $post_id ) );
		$output = do_blocks( get_post_field( 'post_content', $post_id ) );
		$this->assertStringContainsString( 'lf-heading-unit', $output );
		$this->assertSame( $content, get_post_field( 'post_content', $post_id ) );
	}

	public function test_excludes_non_singular_and_feed_views(): void {
		$input = '<h2>GPT-6.1</h2>';
		$this->go_to( home_url( '/' ) );
		$this->assertSame( $input, luna_frontier_render_heading_units( $input ) );
		$this->go_to( home_url( '/?feed=rss2' ) );
		$this->assertSame( $input, luna_frontier_render_heading_units( $input ) );
	}

	public function test_excludes_admin_rendering(): void {
		$input = '<h2>GPT-6.1</h2>';
		set_current_screen( 'dashboard' );
		try {
			$this->assertSame( $input, luna_frontier_render_heading_units( $input ) );
		} finally {
			set_current_screen( 'front' );
		}
	}
}
