<?php
/** Library catalogue editing does not use the retired AI generation route. @package Node */
require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_Store_Only_Test extends WP_UnitTestCase {
	public function test_retired_generation_route_is_absent(): void {
		$server = rest_get_server();
		$this->assertArrayNotHasKey( '/node-library/v1/generate-game-info', $server->get_routes() );
		$this->assertArrayHasKey( '/node-library/v1/fetch-store', $server->get_routes() );
		$this->assertSame( 404, $server->dispatch( new WP_REST_Request( 'POST', '/node-library/v1/generate-game-info' ) )->get_status() );
		$this->assertFalse( method_exists( Node_Library::class, 'handle_generate_game_info' ) );
	}

	public function test_manual_summary_and_links_still_save_and_render(): void {
		$previous = $_POST;
		try {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$library = Node_Library::instance();
			$library->register_cpt();
			$id = self::factory()->post->create( array( 'post_type' => 'node_library', 'post_title' => 'Manual item' ) );
			$summary = "User's review and C:\\Games";
			$links = array( array( 'platform' => 'Steam', 'url' => 'https://store.steampowered.com/app/123/', 'category' => 'pc', 'hardware' => 'windows-pc' ) );
			$_POST = wp_slash( array( 'node_library_details_nonce' => wp_create_nonce( 'node_library_save_details' ), 'node_library_summary' => $summary, 'node_library_links' => $links ) );
			wp_update_post( array( 'ID' => $id ) );
			$this->assertSame( $summary, get_post_meta( $id, '_node_library_summary', true ) );
			$this->assertSame( $links, get_post_meta( $id, '_node_library_links', true ) );
			$this->assertStringContainsString( 'store.steampowered.com/app/123/', $library->render_block( array( 'libraryId' => $id ) ) );
			ob_start();
			$library->render_details_meta_box( get_post( $id ) );
			$form = ob_get_clean();
			$this->assertStringContainsString( 'node_library_summary', $form );
			$this->assertStringNotContainsString( 'node-library-generate-info', $form );
		} finally {
			$_POST = $previous;
			wp_set_current_user( 0 );
		}
	}

	public function test_library_source_has_no_gemini_dependency_or_generate_path(): void {
		$root = dirname( __DIR__ ) . '/plugins-embedded/node-library';
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( ! in_array( $file->getExtension(), array( 'php', 'js', 'txt' ), true ) ) {
				continue;
			}
			$contents = file_get_contents( $file->getPathname() );
			$this->assertStringNotContainsString( 'gemini', strtolower( $contents ), $file->getPathname() );
			$this->assertStringNotContainsString( 'generatePath', $contents, $file->getPathname() );
		}
	}
}
