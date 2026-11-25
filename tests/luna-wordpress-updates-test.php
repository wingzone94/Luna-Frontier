<?php
/**
 * Tests for GitHub releases in the WordPress update flow.
 */

class Luna_WordPress_Updates_Test extends WP_UnitTestCase {
	private const REPOSITORY = 'https://github.com/wingzone94/Luna-Frontier';
	private const API = 'https://api.github.com/repos/wingzone94/Luna-Frontier/releases/latest';
	private const TAG = 'luna-frontier-v2.0.6-20261125T103000Z-123abcd';
	private const DOWNLOAD = self::REPOSITORY . '/releases/download/' . self::TAG . '/';
	private array $responses = array();
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		$this->clear_release_cache();
		add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
		$this->clear_release_cache();
		parent::tear_down();
	}

	private function clear_release_cache(): void {
		delete_site_transient( 'luna_github_latest_release' );
		delete_site_transient( 'luna_github_release_meta_' . md5( self::DOWNLOAD . 'luna-release.json' ) );
	}

	public function stub_http( $preempt, array $args, string $url ) {
		$this->requests[] = $url;
		return $this->responses[ $url ] ?? new WP_Error( 'http_request_failed', 'Offline' );
	}

	private function stub_release(): void {
		$assets = array();
		foreach ( array( 'luna.zip', 'node-library.zip', 'luna-release.json' ) as $name ) {
			$assets[] = array( 'name' => $name, 'browser_download_url' => self::DOWNLOAD . $name );
		}
		$this->responses[ self::API ] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'tag_name' => self::TAG, 'assets' => $assets ) ),
		);
	}

	private function theme_update( string $version, string $slug = 'luna-frontier' ) {
		$data = array( 'Version' => $version, 'UpdateURI' => self::REPOSITORY );
		return apply_filters( 'update_themes_github.com', false, $data, $slug, array() );
	}

	private function plugin_update( string $version, string $file = 'node-library/node-library.php' ) {
		$data = array( 'Version' => $version, 'UpdateURI' => self::REPOSITORY );
		return apply_filters( 'update_plugins_github.com', false, $data, $file, array() );
	}

	public function test_extracts_version_from_release_tag(): void {
		$this->assertSame( '2.0.6', Luna_GitHub_Release::theme_version( self::TAG ) );
		$this->assertSame( '2.0.0-preview.6', Luna_GitHub_Release::theme_version( 'luna-frontier-v2.0.0-preview.6-20261125T103000Z-123abcd' ) );
		$this->assertNull( Luna_GitHub_Release::theme_version( 'luna-frontier-v2.0.6' ) );
		$this->assertNull( Luna_GitHub_Release::theme_version( 'node-v2.0.6-20261125T103000Z-123abcd' ) );
	}

	public function test_theme_and_plugin_only_offer_newer_releases_and_cache_responses(): void {
		$this->stub_release();
		$metadata_url = self::DOWNLOAD . 'luna-release.json';
		$this->responses[ $metadata_url ] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'tag' => self::TAG, 'plugin_version' => '1.7.2' ) ),
		);

		$theme = $this->theme_update( '2.0.5' );
		$this->assertSame( '2.0.6', $theme['version'] );
		$this->assertSame( self::DOWNLOAD . 'luna.zip', $theme['package'] );
		$this->assertFalse( $this->theme_update( '2.0.6' ) );
		$this->assertFalse( $this->theme_update( '2.0.10' ) );

		$plugin = $this->plugin_update( '1.7.1' );
		$this->assertSame( '1.7.2', $plugin['version'] );
		$this->assertSame( self::DOWNLOAD . 'node-library.zip', $plugin['package'] );
		$this->assertFalse( $this->plugin_update( '1.7.2' ) );
		$this->assertFalse( $this->plugin_update( '1.7.10' ) );

		$this->assertSame( array( self::API, $metadata_url ), $this->requests );
	}

	public function test_unrelated_theme_and_plugin_do_not_query_github(): void {
		$this->assertFalse( $this->theme_update( '2.0.5', 'other-theme' ) );
		$this->assertFalse( $this->plugin_update( '1.7.1', 'other-plugin/node-library.php' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_api_failure_is_cached_without_offering_updates(): void {
		$this->assertFalse( $this->theme_update( '2.0.5' ) );
		$this->assertFalse( $this->plugin_update( '1.7.1' ) );
		$this->assertSame( array( self::API ), $this->requests );
	}

	public function test_metadata_failure_does_not_offer_plugin_update(): void {
		$this->stub_release();
		$this->assertFalse( $this->plugin_update( '1.7.1' ) );
		$this->assertFalse( $this->plugin_update( '1.7.1' ) );
		$this->assertSame( array( self::API, self::DOWNLOAD . 'luna-release.json' ), $this->requests );
	}
}
