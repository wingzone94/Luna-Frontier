<?php
/**
 * Library visual omission when no image is configured.
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

/**
 * Verifies that visual containers are omitted for image-less library items
 * and preserved when a featured image or local image URL is present.
 */
class Node_Library_Visual_Test extends WP_UnitTestCase {
	/** @var int[] */
	private array $attachment_ids = array();

	public function set_up(): void {
		parent::set_up();
		$library = Node_Library::instance();
		$this->set_permalink_structure( '/%postname%/' );
		$library->register_cpt();
		flush_rewrite_rules( false );
		Node_Library_Store::install();
		Node_Library_Prices::install();
		Node_Library_Single_Extensions::init();
	}

	public function tear_down(): void {
		foreach ( $this->attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		$this->attachment_ids = array();
		parent::tear_down();
	}

	public function test_visual_template_omits_container_when_no_image(): void {
		$item_id = $this->create_library_item( 'No Image Game' );

		ob_start();
		get_template_part( 'template-parts/library/visual', null, array( 'id' => $item_id ) );
		$html = (string) ob_get_clean();

		$this->assertSame( '', trim( $html ) );
		$this->assertStringNotContainsString( 'library-visual', $html );
		$this->assertStringNotContainsString( '<figure', $html );
		$this->assertStringNotContainsString( 'No Image Game', $html );
	}

	public function test_visual_template_omits_container_for_external_image_url(): void {
		$item_id = $this->create_library_item( 'External URL Only Game' );
		update_post_meta( $item_id, '_node_library_image_url', 'https://cdn.example.com/external-cover.jpg' );

		ob_start();
		get_template_part( 'template-parts/library/visual', null, array( 'id' => $item_id ) );
		$html = (string) ob_get_clean();

		$this->assertSame( '', trim( $html ) );
		$this->assertStringNotContainsString( 'library-visual', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_visual_template_renders_container_for_featured_image(): void {
		$item_id       = $this->create_library_item( 'Featured Image Game' );
		$attachment_id = $this->create_local_attachment( 'featured-cover.png' );
		set_post_thumbnail( $item_id, $attachment_id );

		ob_start();
		get_template_part(
			'template-parts/library/visual',
			null,
			array(
				'id'    => $item_id,
				'size'  => 'large',
				'eager' => true,
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<figure class="library-visual" aria-hidden="true">', $html );
		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'loading="eager"', $html );
		$this->assertStringContainsString( 'fetchpriority="high"', $html );
	}

	public function test_visual_template_renders_container_for_local_image_url(): void {
		$item_id   = $this->create_library_item( 'Local URL Game' );
		$local_url = home_url( '/wp-content/uploads/2026/11/local-cover.jpg' );
		update_post_meta( $item_id, '_node_library_image_url', $local_url );

		ob_start();
		get_template_part( 'template-parts/library/visual', null, array( 'id' => $item_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<figure class="library-visual" aria-hidden="true">', $html );
		$this->assertStringContainsString( '<img src="' . esc_url( $local_url ) . '"', $html );
	}

	public function test_shelf_card_omits_visual_container_when_no_image_and_renders_when_present(): void {
		$no_img_id = $this->create_library_item( 'Text Only Card Game' );
		update_post_meta( $no_img_id, '_node_library_summary', '文字のみ表示のテスト作品です。' );
		update_post_meta( $no_img_id, '_node_library_is_free', true );

		$with_img_id   = $this->create_library_item( 'Visual Card Game' );
		$attachment_id = $this->create_local_attachment( 'shelf-cover.png' );
		set_post_thumbnail( $with_img_id, $attachment_id );

		$no_img_html   = $this->render_shelf_card( $no_img_id );
		$with_img_html = $this->render_shelf_card( $with_img_id );

		$this->assertStringNotContainsString( 'library-visual', $no_img_html );
		$this->assertStringNotContainsString( '<figure', $no_img_html );
		$this->assertStringNotContainsString( 'has-visual', $no_img_html );
		$this->assertStringContainsString( 'Text Only Card Game', $no_img_html );
		$this->assertStringContainsString( '文字のみ表示のテスト作品です。', $no_img_html );
		$this->assertStringContainsString( '無料', $no_img_html );

		$this->assertStringContainsString( 'has-visual', $with_img_html );
		$this->assertStringContainsString( '<figure class="library-visual" aria-hidden="true">', $with_img_html );
		$this->assertStringContainsString( '<img', $with_img_html );
		$this->assertStringContainsString( 'Visual Card Game', $with_img_html );
	}

	public function test_story_card_omits_visual_container_when_no_image_and_renders_when_present(): void {
		$no_img_post = self::factory()->post->create_and_get(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Text Only Story Post',
			)
		);
		$with_img_post = self::factory()->post->create_and_get(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Visual Story Post',
			)
		);
		$attachment_id = $this->create_local_attachment( 'story-cover.png' );
		set_post_thumbnail( $with_img_post->ID, $attachment_id );

		ob_start();
		get_template_part( 'template-parts/library/story', null, array( 'post' => $no_img_post ) );
		$no_img_html = (string) ob_get_clean();

		ob_start();
		get_template_part( 'template-parts/library/story', null, array( 'post' => $with_img_post ) );
		$with_img_html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'library-visual', $no_img_html );
		$this->assertStringNotContainsString( 'has-visual', $no_img_html );
		$this->assertStringContainsString( 'Text Only Story Post', $no_img_html );

		$this->assertStringContainsString( 'has-visual', $with_img_html );
		$this->assertStringContainsString( '<figure class="library-visual" aria-hidden="true">', $with_img_html );
		$this->assertStringContainsString( 'Visual Story Post', $with_img_html );
	}

	public function test_single_template_omits_hero_visual_when_no_image_and_renders_when_present(): void {
		$no_img_id = $this->create_library_item( 'Single Text Only Game' );
		update_post_meta( $no_img_id, '_node_library_summary', '詳細ページ文字のみ表示テスト。' );

		$with_img_id   = $this->create_library_item( 'Single Visual Game' );
		$attachment_id = $this->create_local_attachment( 'single-hero.png' );
		set_post_thumbnail( $with_img_id, $attachment_id );

		$no_img_html   = $this->render_single_template( $no_img_id );
		$with_img_html = $this->render_single_template( $with_img_id );

		$this->assertStringContainsString( '<header class="node-library-single__hero">', $no_img_html );
		$this->assertStringNotContainsString( 'library-visual', $no_img_html );
		$this->assertStringNotContainsString( '<figure', $no_img_html );
		$this->assertStringContainsString( 'Single Text Only Game', $no_img_html );
		$this->assertStringContainsString( '詳細ページ文字のみ表示テスト。', $no_img_html );

		$this->assertStringContainsString( '<header class="node-library-single__hero has-visual">', $with_img_html );
		$this->assertStringContainsString( '<figure class="library-visual" aria-hidden="true">', $with_img_html );
		$this->assertStringContainsString( '<img', $with_img_html );
		$this->assertStringContainsString( 'Single Visual Game', $with_img_html );
	}

	private function create_library_item( string $title ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		update_post_meta( $post_id, '_node_library_type', 'game' );
		return (int) $post_id;
	}

	private function create_local_attachment( string $filename ): int {
		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['path'] );
		$filepath = trailingslashit( $upload_dir['path'] ) . wp_unique_filename( $upload_dir['path'], $filename );
		// 1x1 transparent PNG.
		file_put_contents(
			$filepath,
			base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' )
		);

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => sanitize_file_name( $filename ),
				'post_status'    => 'inherit',
			),
			$filepath
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 1,
				'height' => 1,
				'file'   => _wp_relative_upload_path( $filepath ),
			)
		);
		$this->attachment_ids[] = (int) $attachment_id;

		return (int) $attachment_id;
	}

	private function render_shelf_card( int $post_id ): string {
		$query = new WP_Query(
			array(
				'post_type' => 'node_library',
				'post__in'  => array( $post_id ),
			)
		);
		$this->assertTrue( $query->have_posts() );
		$query->the_post();

		ob_start();
		get_template_part( 'template-parts/library/shelf-card' );
		$html = (string) ob_get_clean();
		wp_reset_postdata();

		return $html;
	}

	private function render_single_template( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular( 'node_library' ) );

		ob_start();
		include dirname( __DIR__ ) . '/single-node_library.php';
		return (string) ob_get_clean();
	}
}
