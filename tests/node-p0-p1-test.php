<?php
/** Regressions for truthful dates and responsive cover delivery. */

use NodeTheme\Controllers\TemplateController;

class Node_P0_P1_Test extends WP_UnitTestCase {
	private function dated_post( string $published, string $modified ): int {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array(
			'post_date' => $published, 'post_date_gmt' => get_gmt_from_date( $published ),
			'post_modified' => $modified, 'post_modified_gmt' => get_gmt_from_date( $modified ),
		), array( 'ID' => $id ) );
		clean_post_cache( $id );
		return $id;
	}

	public function test_scheduled_publication_and_moved_publication_do_not_show_earlier_update(): void {
		$id = $this->dated_post( '2025-02-10 12:00:00', '2025-02-08 11:00:00' );
		$this->assertNull( TemplateController::getPostModifiedDisplay( $id ) );
		update_post_meta( $id, '_node_manual_modified_date', '2025-02-09' );
		$this->assertNull( TemplateController::getPostModifiedDisplay( $id ) );
	}

	public function test_real_later_update_and_same_day_manual_disclosure_are_kept(): void {
		$id = $this->dated_post( '2025-02-10 12:00:00', '2025-02-11 14:00:00' );
		$this->assertSame( '2025/02/11', TemplateController::getPostModifiedDisplay( $id )['display'] );
		update_post_meta( $id, '_node_manual_modified_date', '2025-02-10' );
		$this->assertSame( '2025-02-10', TemplateController::getPostModifiedDisplay( $id )['datetime'] );
	}

	public function test_invalid_or_future_manual_dates_fall_back_to_real_update(): void {
		$id = $this->dated_post( '2025-02-10 12:00:00', '2025-02-11 14:00:00' );
		foreach ( array( '2025-02-30', '2099-01-01', '2025-13-01', 'invalid' ) as $date ) {
			update_post_meta( $id, '_node_manual_modified_date', $date );
			$this->assertSame( '2025/02/11', TemplateController::getPostModifiedDisplay( $id )['display'] );
		}
	}

	public function test_unpublished_and_future_publication_do_not_claim_update(): void {
		$id = $this->dated_post( '2099-02-10 12:00:00', '2099-02-11 14:00:00' );
		$this->assertNull( TemplateController::getPostModifiedDisplay( $id ) );
		$id = self::factory()->post->create( array( 'post_status' => 'future', 'post_date' => '2099-01-01 00:00:00' ) );
		$this->assertNull( TemplateController::getPostModifiedDisplay( $id ) );
	}

	public function test_preload_uses_filtered_rendered_large_image_attributes(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$image_id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $image_id, '_wp_attached_file', 'cover.jpg' );
		wp_update_attachment_metadata( $image_id, array( 'file' => 'cover.jpg', 'width' => 1800, 'height' => 1000,
			'sizes' => array( 'large' => array( 'file' => 'cover-1024.jpg', 'width' => 1024, 'height' => 569, 'mime-type' => 'image/jpeg' ) ) ) );
		set_post_thumbnail( $id, $image_id );
		$this->go_to( get_permalink( $id ) );
		$filter = static function( $attrs ) {
			$attrs['src'] = '/filtered-large.jpg';
			$attrs['srcset'] = '/filtered-small.jpg 640w, /filtered-large.jpg 1024w';
			$attrs['sizes'] = '(max-width: 1024px) 100vw, 1024px';
			return $attrs;
		};
		add_filter( 'wp_get_attachment_image_attributes', $filter, 99 );
		ob_start();
		node_preload_hero_image();
		$html = ob_get_clean();
		remove_filter( 'wp_get_attachment_image_attributes', $filter, 99 );
		$this->assertStringContainsString( 'as="image"', $html );
		$this->assertStringContainsString( 'href="/filtered-large.jpg"', $html );
		$this->assertStringContainsString( 'imagesrcset="/filtered-small.jpg 640w, /filtered-large.jpg 1024w"', $html );
		$this->assertStringContainsString( 'imagesizes="(max-width: 1024px) 100vw, 1024px"', $html );
		$this->assertStringContainsString( 'fetchpriority="high"', $html );
	}
	public function test_hero_category_keeps_ink_and_adjusts_only_insufficient_faces(): void {
		foreach ( array( '#d06060', '#808080', '#da8585', '#eeee22', '#FF9900', '#000000', '#ffffff', '#0078d4' ) as $face ) {
			$ink = node_get_readable_text_color( $face );
			$tone = luna_frontier_hero_category_face( $face, $ink );
			$this->assertGreaterThanOrEqual( 4.5, luna_frontier_contrast_ratio( $ink, $tone ), $face );
			if ( luna_frontier_contrast_ratio( $ink, $face ) >= 4.5 ) {
				$this->assertSame( $face, $tone, 'Already readable category colors must not change.' );
			}
		}
		$this->assertSame( '#ffffff', node_get_readable_text_color( '#d06060' ) );
		$this->assertNotSame( '#d06060', luna_frontier_hero_category_face( '#d06060', '#ffffff' ) );
	}

}
