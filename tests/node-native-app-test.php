<?php
/**
 * Flutter アプリ内表示（native=1）の判定・リンク引き継ぎ・canonical。
 *
 * @package Node
 */

class Node_Native_App_Test extends WP_UnitTestCase {

	public function test_query_enables_native_mode(): void {
		$this->assertTrue( node_native_app_resolve_mode( '1', null, false, false ) );
	}

	public function test_query_zero_exits_even_with_cookie(): void {
		$this->assertFalse( node_native_app_resolve_mode( '0', '1', false, false ) );
		$this->assertSame( 'clear', node_native_app_cookie_action( '0', false ) );
	}

	public function test_cookie_keeps_native_mode_without_query(): void {
		$this->assertTrue( node_native_app_resolve_mode( null, '1', false, false ) );
		$this->assertSame( 'none', node_native_app_cookie_action( null, false ) );
	}

	public function test_absent_query_and_cookie_stay_normal(): void {
		$this->assertFalse( node_native_app_resolve_mode( null, null, false, false ) );
	}

	public function test_exempt_requests_are_never_native(): void {
		$this->assertFalse( node_native_app_resolve_mode( '1', '1', true, false ) );
		$this->assertSame( 'none', node_native_app_cookie_action( '1', true ) );
		$this->assertTrue( node_native_app_path_is_exempt( '/wp-admin/' ) );
		$this->assertTrue( node_native_app_path_is_exempt( '/wp-login.php' ) );
		$this->assertTrue( node_native_app_path_is_exempt( '/wp-json/wp/v2/posts' ) );
		$this->assertTrue( node_native_app_path_is_exempt( '/wp-admin/admin-ajax.php' ) );
		$this->assertTrue( node_native_app_path_is_exempt( '/feed/' ) );
		$this->assertTrue( node_native_app_path_is_exempt( '/comments/feed/' ) );
		$this->assertTrue( node_native_app_path_is_exempt( '/wp-sitemap-posts-post-1.xml' ) );
		$this->assertFalse( node_native_app_path_is_exempt( '/sample-post/' ) );
	}

	public function test_contact_path_is_not_theme_native_output(): void {
		$this->assertTrue( node_native_app_is_contact_path( '/contact/', '/' ) );
		$this->assertTrue( node_native_app_is_contact_path( '/blog/contact', '/blog/' ) );
		$this->assertFalse( node_native_app_is_contact_path( '/privacy-policy/', '/' ) );
		$this->assertFalse( node_native_app_resolve_mode( '1', '1', false, true ) );
		$this->assertSame( 'set', node_native_app_cookie_action( '1', false ) );
	}

	public function test_internal_links_gain_native_and_external_links_do_not(): void {
		$home = 'https://luminous-core.net/';
		$html = '<a href="https://luminous-core.net/post/">in</a>'
			. '<a href="https://example.com/out/">out</a>'
			. '<a href="mailto:a@b.c">mail</a>'
			. '<a href="https://luminous-core.net/wp-admin/">admin</a>'
			. '<a href="https://luminous-core.net/wp-json/wp/v2/posts">api</a>'
			. '<a href="https://luminous-core.net/feed/">feed</a>'
			. '<link rel="canonical" href="https://luminous-core.net/post/">';

		$out = node_native_app_rewrite_markup( $html, $home );

		$this->assertStringContainsString( 'href="https://luminous-core.net/post/?native=1"', $out );
		$this->assertStringContainsString( 'href="https://example.com/out/"', $out );
		$this->assertStringContainsString( 'href="mailto:a@b.c"', $out );
		$this->assertStringContainsString( 'href="https://luminous-core.net/wp-admin/"', $out );
		$this->assertStringContainsString( 'href="https://luminous-core.net/wp-json/wp/v2/posts"', $out );
		$this->assertStringContainsString( 'href="https://luminous-core.net/feed/"', $out );
		$this->assertStringContainsString( '<link rel="canonical" href="https://luminous-core.net/post/">', $out );
	}

	public function test_contact_page_markup_is_not_rewritten_by_theme_templates(): void {
		$header = (string) file_get_contents( dirname( __DIR__ ) . '/header.php' );
		$this->assertStringContainsString( 'node_is_native_app()', $header );
		$this->assertFalse( node_native_app_resolve_mode( '1', null, false, true ) );
	}

	public function test_canonical_strips_native_only(): void {
		$this->assertSame(
			'https://luminous-core.net/post/',
			node_native_app_strip_native_query( 'https://luminous-core.net/post/?native=1' )
		);
		$this->assertSame(
			'https://luminous-core.net/post/?s=a#sec',
			node_native_app_strip_native_query( 'https://luminous-core.net/post/?native=1&s=a#sec' )
		);
		$this->assertSame(
			'https://luminous-core.net/post/',
			node_native_app_strip_native_query( 'https://luminous-core.net/post/' )
		);
	}

	public function test_dom_marker_and_chrome_templates(): void {
		$marked = node_native_app_mark_language_attributes( 'lang="ja" class="no-js"' );
		$this->assertStringContainsString( 'class="no-js is-native-app"', $marked );
		$this->assertStringContainsString( 'data-native-app="1"', $marked );

		$root   = dirname( __DIR__ );
		$footer = (string) file_get_contents( $root . '/footer.php' );
		$single = (string) file_get_contents( $root . '/single.php' );
		$toc    = (string) file_get_contents( $root . '/template-parts/single/toc.php' );
		$share  = (string) file_get_contents( $root . '/template-parts/single/footer.php' );

		$this->assertStringContainsString( 'floating-actions', $footer );
		$this->assertStringContainsString( '$node_native_app', $footer );
		$this->assertStringContainsString( 'id="m3-inline-toc"', $single );
		$this->assertStringContainsString( 'template-parts/single/related', $single );
		$this->assertStringContainsString( 'm3-article-toc-data', $toc );
		$this->assertStringContainsString( 'node_is_native_app()', $share );
	}
}
