<?php
/**
 * plugins-embedded/luminous-blocks/includes/oembed-handlers.php の
 * node/notice ブロック登録（attributes 定義）を検証する。
 *
 * node/notice は静的ブロック（render_callback なし）のため、ここでは
 * 登録の存在と attributes の既定値のみを確認する。
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/luminous-blocks/includes/oembed-handlers.php';

class Node_Notice_Block_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$registry = WP_Block_Type_Registry::get_instance();
		if ( ! $registry->is_registered( 'node/notice' ) ) {
			node_register_m3_blocks();
		}
	}

	public function test_notice_block_is_registered(): void {
		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( 'node/notice' )
		);
	}

	public function test_notice_block_declares_type_and_title_attributes(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'node/notice' );

		$this->assertArrayHasKey( 'type', $block->attributes );
		$this->assertArrayHasKey( 'title', $block->attributes );

		$this->assertSame( 'string', $block->attributes['type']['type'] );
		$this->assertSame( 'info', $block->attributes['type']['default'] );

		$this->assertSame( 'string', $block->attributes['title']['type'] );
		$this->assertSame( '', $block->attributes['title']['default'] );

		$this->assertArrayHasKey( 'shape', $block->attributes );
		$this->assertSame( 'string', $block->attributes['shape']['type'] );
		$this->assertSame( 'rounded', $block->attributes['shape']['default'] );
	}

	public function test_notice_block_has_no_render_callback(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'node/notice' );

		// 静的ブロック（保存 HTML をそのまま出力）なので動的レンダラを持たない。
		$this->assertNull( $block->render_callback );
	}

	public function test_notice_block_is_in_node_category(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'node/notice' );

		// インサーターの「Node」カテゴリーに分類される。
		$this->assertSame( 'node', $block->category );
	}

	public function test_legacy_notice_block_ligature_icon_is_normalized_to_data_icon_on_render(): void {
		$legacy_markup = '<!-- wp:node/notice {"type":"alert"} -->'
			. '<blockquote class="wp-block-node-notice m3-notice m3-notice--alert" role="note" aria-live="polite">'
			. '<div class="m3-notice__header"><span class="material-symbols-outlined m3-notice__icon" aria-hidden="true">campaign</span><span class="m3-notice__title">重要</span></div>'
			. '<div class="m3-notice__body"><p>本文</p></div>'
			. '</blockquote><!-- /wp:node/notice -->';

		$rendered = do_blocks( $legacy_markup );

		$this->assertStringContainsString( 'data-icon="campaign"', $rendered );
		$this->assertStringContainsString( 'aria-hidden="true"', $rendered );
		$this->assertStringNotContainsString( '>campaign<', $rendered );
	}
}
