<?php
/** Steam integration in the existing node/embed block. */
require_once dirname( __DIR__ ) . '/plugins-embedded/luminous-blocks/includes/blocks/embed.php';
class Node_Steam_Embed_Test extends WP_UnitTestCase {
	public function test_steam_widget_accepts_only_official_app_and_widget_urls(): void {
		$this->assertSame( '570', node_steam_widget_app_id( 'https://store.steampowered.com/app/570/Dota_2/' ) );
		$this->assertSame( '500', node_steam_widget_app_id( 'https://store.steampowered.com/widget/500/' ) );
		foreach ( array(
			'https://store.steampowered.com.evil.test/app/570/',
			'https://steamcommunity.com/app/570/',
			'https://store.steampowered.com/bundle/570/',
			'https://store.steampowered.com/app/570evil/',
			'javascript:alert(1)',
		) as $url ) {
			$this->assertSame( '', node_steam_widget_app_id( $url ), $url );
		}
	}

	public function test_existing_embed_block_renders_steam_widget(): void {
		$html = node_render_embed_block( array( 'url' => 'https://store.steampowered.com/app/570/Dota_2/' ) );
		$this->assertStringContainsString( 'node-embed--steam', $html );
		$this->assertStringContainsString( 'https://store.steampowered.com/widget/570/', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( 'width="646" height="190"', $html );
	}

	public function test_render_block_uses_widget_with_existing_embed_registration(): void {
		$block = array(
			'blockName'    => 'node/embed',
			'attrs'        => array( 'url' => 'https://store.steampowered.com/app/570/Dota_2/' ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		$this->assertStringContainsString( 'https://store.steampowered.com/widget/570/', render_block( $block ) );
	}

	public function test_theme_filter_supports_older_standalone_block_callback(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'node/embed' );
		$original = $type->render_callback;
		$type->render_callback = static function () { return '<p>Old plugin fallback</p>'; };
		try {
			$block = array(
				'blockName'    => 'node/embed',
				'attrs'        => array( 'url' => 'https://store.steampowered.com/app/570/Dota_2/' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
			$this->assertStringContainsString( 'https://store.steampowered.com/widget/570/', render_block( $block ) );
		} finally {
			$type->render_callback = $original;
		}
	}

	public function test_pasted_steam_url_keeps_blogcard_path(): void {
		$this->assertSame( '', node_special_embed( 'https://store.steampowered.com/app/570/Dota_2/' ) );
	}

	public function test_other_embed_providers_remain_available(): void {
		$this->assertStringContainsString( 'node-embed--x', node_render_embed_block( array( 'url' => 'https://x.com/jack/status/20' ) ) );
		$this->assertStringContainsString( 'node-embed--video', node_render_embed_block( array( 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) ) );
		$this->assertStringContainsString( 'node-embed--map', node_render_embed_block( array( 'url' => 'https://www.google.com/maps/place/Tokyo' ) ) );
	}
}
