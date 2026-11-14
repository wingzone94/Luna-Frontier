<?php
/**
 * Luna Frontier footer version display regression tests.
 *
 * @package LunaFrontier
 */

class Luna_Footer_Version_Test extends WP_UnitTestCase {

	private function read_style_version( string $repo_dir ): string {
		$style = (string) file_get_contents( $repo_dir . '/style.css' );
		$this->assertSame( 1, preg_match( '/^Version:\s*(\S+)/m', $style, $matches ) );
		return $matches[1];
	}

	public function test_display_version_matches_style_css_version_and_footer_output(): void {
		global $wp_theme_directories;

		$repo_dir          = dirname( __DIR__ );
		$expected_version  = $this->read_style_version( $repo_dir );
		$theme_root        = dirname( $repo_dir );
		$stylesheet        = basename( $repo_dir );
		$orig_directories  = $wp_theme_directories;
		$sheet_filter      = static fn(): string => $stylesheet;
		$root_opt_filter   = static fn(): string => $theme_root;
		$native_app_filter = static fn(): string => '0';

		register_theme_directory( $theme_root );
		add_filter( 'stylesheet', $sheet_filter );
		add_filter( 'template', $sheet_filter );
		add_filter( 'pre_option_stylesheet_root', $root_opt_filter );
		add_filter( 'pre_option_template_root', $root_opt_filter );
		add_filter( 'pre_option_node_native_app', $native_app_filter );
		search_theme_directories( true );

		try {
			$this->assertSame( $expected_version, luna_frontier_version() );
			$this->assertSame( luna_frontier_version(), luna_frontier_display_version() );
			$this->assertNotSame( '2.0', luna_frontier_display_version() );

			$this->go_to( home_url( '/' ) );
			ob_start();
			get_header();
			require $repo_dir . '/footer.php';
			$footer_html = (string) ob_get_clean();

			$this->assertStringContainsString(
				'<span class="lf-footer__version">Luna Frontier v' . $expected_version . '</span>',
				$footer_html
			);
			$this->assertStringNotContainsString(
				'<span class="lf-footer__version">Luna Frontier v2.0</span>',
				$footer_html
			);
		} finally {
			remove_filter( 'stylesheet', $sheet_filter );
			remove_filter( 'template', $sheet_filter );
			remove_filter( 'pre_option_stylesheet_root', $root_opt_filter );
			remove_filter( 'pre_option_template_root', $root_opt_filter );
			remove_filter( 'pre_option_node_native_app', $native_app_filter );
			$wp_theme_directories = $orig_directories;
			search_theme_directories( true );
		}
	}
}
