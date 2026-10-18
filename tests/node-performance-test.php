<?php
/** Delivery contracts for hero images and the Site Kit sign-in pair. */

require_once dirname( __DIR__ ) . '/inc/hero-slider.php';
require_once dirname( __DIR__ ) . '/inc/performance.php';

class Node_Performance_Test extends WP_UnitTestCase {
	private $script_name;

	public function set_up() {
		parent::set_up();
		$this->script_name = $_SERVER['SCRIPT_NAME'];
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		set_current_screen( 'front' );
		$GLOBALS['pagenow'] = 'index.php';
		node_defer_sitekit_sign_in_scripts( array( 'src' => 'https://accounts.google.com/gsi/client', 'async' => true ) );
	}

	public function tear_down() {
		set_current_screen( 'front' );
		$_SERVER['SCRIPT_NAME'] = $this->script_name;
		$GLOBALS['pagenow'] = 'index.php';
		parent::tear_down();
	}

	private function bootstrap_attributes(): array {
		return array(
			'src' => 'https://example.org/wp-content/plugins/google-site-kit/dist/assets/js/sign-in-with-google-123abc.js',
			'data-siwg-config' => '{"clientID":"fixture","shouldShowOneTapPrompt":false}',
		);
	}

	public function test_deferred_image_keeps_responsive_selection_and_noscript_markup() {
		$html = '<picture><source media="(max-width:767px)" srcset="/small.webp 400w, /medium.webp 800w" sizes="100vw"><img src="/large.webp" srcset="/large.webp 1024w" sizes="1024px" width="1024" height="576" alt="A &amp; B" loading="lazy" decoding="async"></picture>';
		$out  = node_defer_image_html( $html );
		$this->assertStringContainsString( '<noscript><style>img[data-lf-deferred-image]{display:none!important}</style>' . $html . '</noscript>', $out );
		$active = explode( '<noscript>', $out )[0];
		$tags = new WP_HTML_Tag_Processor( $active );
		while ( $tags->next_tag() ) {
			if ( in_array( $tags->get_tag(), array( 'IMG', 'SOURCE' ), true ) ) {
				$this->assertNull( $tags->get_attribute( 'src' ) );
				$this->assertNull( $tags->get_attribute( 'srcset' ) );
				$this->assertNotNull( $tags->get_attribute( 'data-lf-srcset' ) );
			}
			if ( 'IMG' === $tags->get_tag() ) {
				$this->assertSame( '/large.webp', $tags->get_attribute( 'data-lf-src' ) );
				$this->assertSame( '1024', $tags->get_attribute( 'width' ) );
				$this->assertSame( '576', $tags->get_attribute( 'height' ) );
				$this->assertSame( 'A & B', $tags->get_attribute( 'alt' ) );
				$this->assertSame( 'async', $tags->get_attribute( 'decoding' ) );
			}
		}
	}

	public function test_non_image_markup_is_unchanged() {
		foreach ( array( '', '<span>fallback</span>' ) as $html ) {
			$this->assertSame( $html, node_defer_image_html( $html ) );
		}
	}

	public function test_news_sizes_keep_all_layout_breakpoints() {
		$sizes = node_get_home_news_image_sizes();
		foreach ( array( '359px', '600px', '1000px', '1440px', 'clamp(16px, 4vw, 56px)' ) as $token ) {
			$this->assertStringContainsString( $token, $sizes );
		}
		$this->assertStringNotContainsString( '90vw', $sizes );
	}

	public function test_news_template_outputs_the_deferred_image_and_noscript_fallback() {
		$post_id = self::factory()->post->create();
		$image_id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/webp' ) );
		update_post_meta( $image_id, '_wp_attached_file', 'phase4-fixture.webp' );
		wp_update_attachment_metadata( $image_id, array( 'file' => 'phase4-fixture.webp', 'width' => 1024, 'height' => 576,
			'sizes' => array( 'medium_large' => array( 'file' => 'phase4-fixture-768.webp', 'width' => 768, 'height' => 432, 'mime-type' => 'image/webp' ) ) ) );
		set_post_thumbnail( $post_id, $image_id );
		$args = array( 'story' => get_post( $post_id ), 'variant' => 'news' );
		ob_start();
		include dirname( __DIR__ ) . '/template-parts/luna/home-story.php';
		$html = ob_get_clean();
		$this->assertStringContainsString( 'data-lf-src=', $html );
		$this->assertStringContainsString( 'data-lf-sizes=', $html );
		$this->assertStringContainsString( '<noscript>', $html );
		$this->assertStringContainsString( 'fetchpriority="auto"', $html );
	}

	public function test_wordpress_renders_both_scripts_as_defer_without_changing_config() {
		$client = wp_get_script_tag( array( 'src' => 'https://accounts.google.com/gsi/client' ) );
		$config = $this->bootstrap_attributes();
		$bootstrap = wp_get_script_tag( $config );
		$this->assertStringContainsString( ' defer', $client );
		$this->assertStringContainsString( ' defer', $bootstrap );
		$this->assertSame( $config + array( 'defer' => true ), node_defer_sitekit_sign_in_scripts( $config ) );
	}

	public function test_async_client_and_its_bootstrap_are_unchanged() {
		$client = array( 'src' => 'https://accounts.google.com/gsi/client', 'async' => true );
		$this->assertSame( $client, node_defer_sitekit_sign_in_scripts( $client ) );
		$config = $this->bootstrap_attributes();
		$this->assertSame( $config, node_defer_sitekit_sign_in_scripts( $config ) );
	}

	public function test_bootstrap_without_the_client_is_unchanged() {
		$config = $this->bootstrap_attributes();
		$this->assertSame( $config, node_defer_sitekit_sign_in_scripts( $config ) );
	}

	public function test_login_and_admin_scripts_are_unchanged() {
		$client = array( 'src' => 'https://accounts.google.com/gsi/client' );
		$_SERVER['SCRIPT_NAME'] = '/wp-login.php';
		$this->assertSame( $client, node_defer_sitekit_sign_in_scripts( $client ) );
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		set_current_screen( 'dashboard' );
		$this->assertSame( $client, node_defer_sitekit_sign_in_scripts( $client ) );
	}

	public function test_analytics_consent_and_unrelated_scripts_are_unchanged() {
		node_defer_sitekit_sign_in_scripts( array( 'src' => 'https://accounts.google.com/gsi/client' ) );
		foreach ( array(
			array( 'src' => 'https://www.googletagmanager.com/gtag/js?id=fixture', 'async' => true ),
			array( 'src' => '/wp-content/plugins/google-site-kit/dist/assets/js/googlesitekit-consent-mode-123.js' ),
			array( 'src' => '/other/sign-in-with-google.js', 'data-siwg-config' => '{}' ),
		) as $attributes ) {
			$this->assertSame( $attributes, node_defer_sitekit_sign_in_scripts( $attributes ) );
		}
	}
}
