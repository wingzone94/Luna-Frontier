<?php
/**
 * OGP regeneration must not run in the article request.
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-seo-tools/node-seo-tools.php';

class Node_SEO_OGP_Schedule_Test extends WP_UnitTestCase {
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_option( 'node_ogp_enabled', '1' );
		\Node\SEO\Tools\Share\Image_Generator::instance();
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( 'node_seo_regenerate_stale_ogp', array( $this->post_id ) );
		parent::tear_down();
	}

	public function test_article_request_schedules_one_regeneration_without_updating_meta(): void {
		$this->go_to( get_permalink( $this->post_id ) );
		$generator = \Node\SEO\Tools\Share\Image_Generator::instance();

		$generator->maybe_regenerate_stale();
		$first = wp_next_scheduled( 'node_seo_regenerate_stale_ogp', array( $this->post_id ) );
		$this->assertIsInt( $first );
		$this->assertEmpty( get_post_meta( $this->post_id, '_node_ogp_generator_version', true ) );

		$generator->maybe_regenerate_stale();
		$this->assertSame( $first, wp_next_scheduled( 'node_seo_regenerate_stale_ogp', array( $this->post_id ) ) );
	}

	public function test_background_job_skips_post_updated_since_scheduling(): void {
		update_post_meta( $this->post_id, '_node_ogp_generator_version', \Node\SEO\Tools\Share\Image_Generator::GENERATOR_VERSION );
		\Node\SEO\Tools\Share\Image_Generator::instance()->regenerate_stale_in_background( $this->post_id );
		$this->assertSame( \Node\SEO\Tools\Share\Image_Generator::GENERATOR_VERSION, get_post_meta( $this->post_id, '_node_ogp_generator_version', true ) );
	}
}
