<?php
/**
 * Featured image selection for article share metadata.
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-seo-tools/node-seo-tools.php';

class Node_SEO_OGP_Featured_Test extends WP_UnitTestCase {
	private int $post_id;
	private int $attachment_id = 0;
	private string $image_file = '';

	public function set_up(): void {
		parent::set_up();
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $this->post_id, '_node_ogp_image_url', 'https://example.org/ogp/generated.png' );
		\Node\SEO\Tools\Share\Meta_Output::instance()->register_featured_image_meta();
		$this->go_to( get_permalink( $this->post_id ) );
		$GLOBALS['post'] = get_post( $this->post_id );
	}

	public function tear_down(): void {
		if ( $this->attachment_id > 0 ) {
			wp_delete_attachment( $this->attachment_id, true );
		}
		if ( '' !== $this->image_file && is_file( $this->image_file ) ) {
			unlink( $this->image_file );
		}
		parent::tear_down();
	}

	private function render_meta(): string {
		ob_start();
		\Node\SEO\Tools\Share\Meta_Output::instance()->inject_ogp_tags();
		return (string) ob_get_clean();
	}

	public function test_toggle_off_uses_generated_ogp(): void {
		$html = $this->render_meta();
		$this->assertStringContainsString( 'content="https://example.org/ogp/generated.png"', $html );
		$this->assertStringContainsString( 'property="og:image:type" content="image/png"', $html );
	}

	public function test_toggle_on_uses_featured_image_dimensions_and_type(): void {
		$image = imagecreatetruecolor( 600, 315 );
		$this->image_file = tempnam( sys_get_temp_dir(), 'ogp-featured-' ) . '.jpg';
		imagejpeg( $image, $this->image_file );
		imagedestroy( $image );

		$this->attachment_id = self::factory()->attachment->create_upload_object( $this->image_file, $this->post_id );
		$this->assertGreaterThan( 0, $this->attachment_id );
		set_post_thumbnail( $this->post_id, $this->attachment_id );
		update_post_meta( $this->post_id, '_node_ogp_use_featured_image', true );

		$html = $this->render_meta();
		$this->assertStringContainsString( 'property="og:image:width" content="600"', $html );
		$this->assertStringContainsString( 'property="og:image:height" content="315"', $html );
		$this->assertStringContainsString( 'property="og:image:type" content="' . get_post_mime_type( $this->attachment_id ) . '"', $html );
		$this->assertStringContainsString( 'name="twitter:image" content="https://', $html );
		$this->assertStringNotContainsString( 'generated.png', $html );
	}

	public function test_toggle_on_without_featured_image_falls_back(): void {
		update_post_meta( $this->post_id, '_node_ogp_use_featured_image', true );
		$this->assertStringContainsString( 'content="https://example.org/ogp/generated.png"', $this->render_meta() );
	}

	public function test_editor_can_save_toggle_through_rest(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
		$request->set_param( 'meta', array( '_node_ogp_use_featured_image' => true ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( (bool) get_post_meta( $this->post_id, '_node_ogp_use_featured_image', true ) );
	}
}
