<?php
/**
 * 脚注の既存HTML出力を固定するテスト。
 *
 * @package Node
 */

class Node_Footnotes_Test extends WP_UnitTestCase {

	public function test_extract_empty_input(): void {
		$this->assertSame( array( 'ids' => array(), 'numbers' => array() ), node_extract_footnote_references_from_html( '' ) );
	}

	public function test_extract_deduplicates_ids_and_keeps_last_number(): void {
		$html = '<sup data-fn="a&amp;b"><a>2</a></sup><sup data-fn="a&amp;b"><a>3</a></sup>';
		$this->assertSame(
			array( 'ids' => array( 'a&b' ), 'numbers' => array( 'a&b' => 3 ) ),
			node_extract_footnote_references_from_html( $html )
		);
	}

	private function expected_section( string $item ): string {
		return '<section class="node-footnotes node-footnotes--single" data-node-footnotes aria-label="脚注"><div class="node-footnotes__header"><span class="node-footnotes__info-label">脚注について</span><button class="node-footnotes__info-toggle" type="button" aria-label="脚注の説明を表示" aria-expanded="false" aria-controls="node-footnotes-123-info" data-footnote-info-toggle><span class="material-symbols-outlined" aria-hidden="true">info</span></button><span class="node-footnotes__info-panel" id="node-footnotes-123-info" hidden>脚注は本文の補足です。番号で切替、↩︎で戻ります。</span><div class="node-footnotes__meta" data-footnote-count="1" data-footnote-total-count="1"><span class="node-footnotes__meta-desktop">このページの脚注：1件 / 記事全体：1件</span><span class="node-footnotes__meta-mobile">ページ 1件 / 全体 1件</span></div></div><div class="node-footnotes__panels"><div class="node-footnotes__panel"><ol class="wp-block-footnotes node-current-page-footnotes" data-footnote-page="1">' . $item . '</ol></div></div></section>';
	}

	public function test_render_empty_groups(): void {
		$this->assertSame( '', node_render_footnote_section( array(), 1, 1 ) );
	}

	public function test_render_single_group_exact_html(): void {
		$original_post = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = new WP_Post( (object) array( 'ID' => 123, 'filter' => 'raw' ) );
		try {
			$groups = array( 1 => array( 'items' => array( '<li id="n">Note</li>' ), 'count' => 1, 'first_number' => 1 ) );
			$this->assertSame( $this->expected_section( '<li id="n">Note</li>' ), node_render_footnote_section( $groups, 1, 1 ) );
		} finally {
			$GLOBALS['post'] = $original_post;
		}
	}

	public function test_reposition_preserves_plain_content(): void {
		$this->assertSame( '<p>Text</p>', node_reposition_current_page_footnotes( '<p>Text</p>' ) );
	}

	public function test_reposition_existing_note_exact_html(): void {
		$originals = array();
		foreach ( array( 'post', 'wp_query', 'page', 'numpages' ) as $name ) {
			$originals[ $name ] = $GLOBALS[ $name ] ?? null;
		}
		$GLOBALS['post'] = new WP_Post( (object) array( 'ID' => 123, 'filter' => 'raw' ) );
		$GLOBALS['wp_query'] = new WP_Query();
		$GLOBALS['wp_query']->is_singular = true;
		$GLOBALS['page'] = 1;
		$GLOBALS['numpages'] = 1;
		try {
			$reference = '<p>Text<sup data-fn="n"><a>1</a></sup></p>';
			$content = $reference . '<ol class="wp-block-footnotes"><li id="n">Note <a href="#n-link">old</a></li></ol>';
			$item = '<li id="n">Note <a href="#n-link" aria-label="脚注参照1にジャンプ">↩︎</a></li>';
			$this->assertSame( $reference . "\n\n" . $this->expected_section( $item ), node_reposition_current_page_footnotes( $content ) );
		} finally {
			foreach ( $originals as $name => $value ) {
				$GLOBALS[ $name ] = $value;
			}
		}
	}
}
