<?php
/**
 * Luna Library rewrite base and legacy redirects.
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

/**
 * URL migration for /node-library/ -> /library/.
 */
class Node_Library_Url_Test extends WP_UnitTestCase {
	private Node_Library $library;

	public function set_up(): void {
		parent::set_up();
		$this->library = Node_Library::instance();
		$this->set_permalink_structure( '/%postname%/' );
		$this->library->register_cpt();
		flush_rewrite_rules( false );
	}

	public function test_rewrite_rules_follow_the_library_base(): void {
		$rules = $GLOBALS['wp_rewrite']->wp_rewrite_rules();

		$this->assertIsArray( $rules );
		$this->assertSame(
			'index.php?post_type=node_library&node_library_type=$matches[1]&paged=$matches[2]',
			$rules['^library/(game|app)/page/([0-9]{1,})/?$']
		);
		$this->assertSame(
			'index.php?post_type=node_library&node_library_type=$matches[1]',
			$rules['^library/(game|app)/?$']
		);
		$this->assertArrayNotHasKey( '^node-library/(game|app)/?$', $rules );
		$this->assertArrayNotHasKey( '^node-library/(game|app)/page/([0-9]{1,})/?$', $rules );
		$this->assertSame(
			'index.php?post_type=node_library&paged=$matches[1]',
			$rules['library/page/([0-9]{1,})/?$']
		);
	}

	public function test_new_routes_resolve_archive_type_pagination_and_single(): void {
		$item_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => 'Sample Title',
				'post_name'   => 'sample-title',
			)
		);

		$permalink = get_permalink( $item_id );
		$this->assertIsString( $permalink );
		$this->assertStringContainsString( '/library/sample-title/', $permalink );
		$this->assertStringNotContainsString( '/node-library/', $permalink );

		$this->go_to( home_url( '/library/' ) );
		$this->assertSame( 'node_library', get_query_var( 'post_type' ) );
		$this->assertTrue( is_post_type_archive( 'node_library' ) );

		$this->go_to( home_url( '/library/page/2/' ) );
		$this->assertSame( 'node_library', get_query_var( 'post_type' ) );
		$this->assertSame( 2, (int) get_query_var( 'paged' ) );

		$this->go_to( home_url( '/library/game/' ) );
		$this->assertSame( 'game', get_query_var( 'node_library_type' ) );
		$this->assertSame( 'node_library', get_query_var( 'post_type' ) );

		$this->go_to( home_url( '/library/app/' ) );
		$this->assertSame( 'app', get_query_var( 'node_library_type' ) );

		$this->go_to( home_url( '/library/game/page/2/' ) );
		$this->assertSame( 'game', get_query_var( 'node_library_type' ) );
		$this->assertSame( 2, (int) get_query_var( 'paged' ) );

		$this->go_to( home_url( '/library/app/page/3/' ) );
		$this->assertSame( 'app', get_query_var( 'node_library_type' ) );
		$this->assertSame( 3, (int) get_query_var( 'paged' ) );

		$this->go_to( $permalink );
		$this->assertTrue( is_singular( 'node_library' ) );
		$this->assertSame( $item_id, get_queried_object_id() );
	}

	public function test_template_redirect_uses_default_exit_before_canonical_redirect(): void {
		$callback = array( $this->library, 'redirect_legacy_library_urls' );
		$hook     = $GLOBALS['wp_filter']['template_redirect'];
		$key      = _wp_filter_build_unique_id( 'template_redirect', $callback, 0 );

		$this->assertSame( 0, has_action( 'template_redirect', $callback ) );
		// An argument-less WP action supplies an empty string when the callback accepts one.
		$this->assertSame( 0, $hook->callbacks[0][ $key ]['accepted_args'] );
	}

	public function test_legacy_patterns_redirect_with_301(): void {
		$cases = array(
			'/node-library/'                    => '/library/',
			'/node-library'                     => '/library/',
			'/node-library/page/2/'             => '/library/page/2/',
			'/node-library/game/'               => '/library/game/',
			'/node-library/game'                => '/library/game/',
			'/node-library/app/'                => '/library/app/',
			'/node-library/game/page/2/'        => '/library/game/page/2/',
			'/node-library/app/page/3/'         => '/library/app/page/3/',
			'/node-library/sample-title/'       => '/library/sample-title/',
			'/node-library/sample-title'        => '/library/sample-title/',
			'/node-library/sample-title/?utm=1' => '/library/sample-title/?utm=1',
		);

		foreach ( $cases as $from => $to ) {
			$expected = untrailingslashit( home_url( '/' ) ) . $to;
			$this->assertSame( $expected, $this->library->legacy_library_redirect_url( $from ), $from );

			$captured = null;
			$filter   = static function ( $location, $status ) use ( &$captured ) {
				$captured = array( $location, (int) $status );
				return false;
			};
			add_filter( 'wp_redirect', $filter, 10, 2 );
			$_SERVER['REQUEST_URI'] = $from;
			$returned               = $this->library->redirect_legacy_library_urls( false );
			remove_filter( 'wp_redirect', $filter, 10 );

			$this->assertSame( $expected, $returned, $from );
			$this->assertSame( array( $expected, 301 ), $captured, $from );
		}
	}

	public function test_legacy_redirect_ignores_new_urls_rest_and_lookalikes(): void {
		$this->assertNull( $this->library->legacy_library_redirect_url( '/library/' ) );
		$this->assertNull( $this->library->legacy_library_redirect_url( '/library/game/' ) );
		$this->assertNull( $this->library->legacy_library_redirect_url( '/library/sample-title/' ) );
		$this->assertNull( $this->library->legacy_library_redirect_url( '/wp-json/node-library/v1/items' ) );
		$this->assertNull( $this->library->legacy_library_redirect_url( '/node-library-extra/' ) );

		$_SERVER['REQUEST_URI'] = '/library/game/';
		$this->assertNull( $this->library->redirect_legacy_library_urls( false ) );
	}
}
