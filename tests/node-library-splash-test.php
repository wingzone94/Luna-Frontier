<?php
/**
 * Luna Library splash screen conditional loading and asset tests.
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_Splash_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		$library = Node_Library::instance();
		$this->set_permalink_structure( '/%postname%/' );
		$library->register_cpt();
		flush_rewrite_rules( false );
		wp_dequeue_style( 'luna-library-splash' );
		wp_dequeue_script( 'luna-library-splash' );
	}

	public function tear_down(): void {
		remove_all_filters( 'luna_library_splash_enabled' );
		wp_dequeue_style( 'luna-library-splash' );
		wp_dequeue_script( 'luna-library-splash' );
		parent::tear_down();
	}

	public function test_assets_and_constant_match_plugin_directory(): void {
		$base_dir = NODE_LIBRARY_DIR . 'assets/luna-library-splash/';
		$this->assertFileExists( $base_dir . 'splash.css' );
		$this->assertFileExists( $base_dir . 'splash.js' );
		$this->assertFileExists( $base_dir . 'luna-library-logo-white.webp' );
		$this->assertFileExists( $base_dir . 'luna-library-logo-white.png' );
		$this->assertSame(
			untrailingslashit( NODE_LIBRARY_URL ) . '/assets/luna-library-splash',
			LL_SPLASH_ASSET_BASE
		);
	}

	public function test_splash_loads_only_on_library_archive_and_single(): void {
		$library_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => 'Splash Sample Game',
			)
		);
		$normal_post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Normal Blog Post',
			)
		);

		// 1. Home page: must NOT show or enqueue.
		$this->go_to( home_url( '/' ) );
		$this->assertFalse( ll_splash_should_show() );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_style_is( 'luna-library-splash', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'luna-library-splash', 'enqueued' ) );
		$this->assertSame( '', $this->capture_splash_footer() );

		// 2. Normal single post: must NOT show or enqueue.
		$this->go_to( get_permalink( $normal_post_id ) );
		$this->assertFalse( ll_splash_should_show() );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_style_is( 'luna-library-splash', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'luna-library-splash', 'enqueued' ) );
		$this->assertSame( '', $this->capture_splash_footer() );

		// 3. Library archive: MUST show, enqueue, and render markup.
		$this->go_to( home_url( '/library/' ) );
		$this->assertTrue( ll_splash_should_show() );
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_style_is( 'luna-library-splash', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'luna-library-splash', 'enqueued' ) );
		$footer_html = $this->capture_splash_footer();
		$this->assertStringContainsString( 'id="ll-splash"', $footer_html );
		$this->assertStringContainsString( 'aria-hidden="true"', $footer_html );
		$this->assertStringContainsString( 'luna-library-logo-white.webp', $footer_html );
		$this->assertStringContainsString( 'luna-library-logo-white.png', $footer_html );
		$this->assertStringContainsString( 'Luminous Core のゲーム・アプリライブラリ', $footer_html );

		// Reset handles before single check.
		wp_dequeue_style( 'luna-library-splash' );
		wp_dequeue_script( 'luna-library-splash' );

		// 4. Library single: MUST show and enqueue.
		$this->go_to( get_permalink( $library_id ) );
		$this->assertTrue( ll_splash_should_show() );
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_style_is( 'luna-library-splash', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'luna-library-splash', 'enqueued' ) );

		// 5. Filter disable: MUST stop completely.
		wp_dequeue_style( 'luna-library-splash' );
		wp_dequeue_script( 'luna-library-splash' );
		add_filter( 'luna_library_splash_enabled', '__return_false' );
		$this->assertFalse( ll_splash_should_show() );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_style_is( 'luna-library-splash', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'luna-library-splash', 'enqueued' ) );
		$this->assertSame( '', $this->capture_splash_footer() );
	}

	private function capture_splash_footer(): string {
		ob_start();
		do_action( 'wp_footer' );
		$html = (string) ob_get_clean();
		return false !== strpos( $html, 'id="ll-splash"' ) ? $html : '';
	}
}
