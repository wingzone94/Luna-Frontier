<?php
/** Optional catalogue metadata and backwards compatibility. @package Node */
require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_Structured_Meta_Test extends WP_UnitTestCase {
	private int $item_id;
	private array $previous_post;

	public function set_up(): void {
		parent::set_up();
		$this->previous_post = $_POST;
		$_POST = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Node_Library::instance()->register_cpt();
		Node_Library_Structured_Meta::register();
		$this->item_id = self::factory()->post->create( array( 'post_type' => 'node_library', 'post_title' => 'Catalogue test' ) );
	}

	public function tear_down(): void {
		$_POST = $this->previous_post;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function submit( array $values ): void {
		$_POST = wp_slash( array( 'node_library_structured_nonce' => wp_create_nonce( 'node_library_save_structured' ), 'node_library_structured' => $values ) );
		wp_update_post( array( 'ID' => $this->item_id, 'post_title' => 'Saved catalogue test' ) );
	}

	public function test_all_metadata_round_trips_and_is_rendered(): void {
		$ids = array_fill_keys( array_keys( Node_Library_Structured_Meta::stores() ), '000123' );
		$this->submit( array(
			'store_ids' => $ids, 'developer' => 'Studio \\ One', 'publisher' => 'Publisher',
			'release_date' => '2024-02-29', 'genres' => "Action\nRPG\nAction",
			'rating' => array( 'cero' => 'Z', 'esrb' => 'M' ),
			'japanese' => array( 'audio' => '0', 'subtitles' => '1', 'ui' => '' ),
			'capabilities' => array( 'crossplay' => '1', 'crosssave' => '0', 'online' => '', 'hdr' => '1', 'max_fps' => '120', 'resolution' => '3840×2160' ),
		) );
		$this->assertSame( $ids, get_post_meta( $this->item_id, '_node_library_store_ids', true ) );
		$this->assertSame( 'Studio \\ One', get_post_meta( $this->item_id, '_node_library_developer', true ) );
		$this->assertSame( 'Publisher', get_post_meta( $this->item_id, '_node_library_publisher', true ) );
		$this->assertSame( '2024-02-29', get_post_meta( $this->item_id, '_node_library_release_date', true ) );
		$this->assertSame( array( 'Action', 'RPG' ), get_post_meta( $this->item_id, '_node_library_genres', true ) );
		$this->assertSame( array( 'cero' => 'Z', 'esrb' => 'M' ), get_post_meta( $this->item_id, '_node_library_rating', true ) );
		$this->assertSame( array( 'audio' => false, 'subtitles' => true ), get_post_meta( $this->item_id, '_node_library_japanese', true ) );
		$this->assertSame( array( 'crossplay' => true, 'crosssave' => false, 'hdr' => true, 'max_fps' => 120, 'resolution' => '3840×2160' ), get_post_meta( $this->item_id, '_node_library_capabilities', true ) );
		ob_start();
		Node_Library_Structured_Meta::render( get_post( $this->item_id ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'value="000123"', $html );
		$this->assertStringContainsString( 'value="0" selected=', $html );
		$this->assertStringContainsString( 'value="2024-02-29"', $html );
	}

	public function test_absent_fields_are_preserved_and_explicit_empty_values_deleted(): void {
		$this->submit( array( 'developer' => 'Studio', 'japanese' => array( 'audio' => '0' ) ) );
		$this->submit( array( 'publisher' => 'Publisher' ) );
		$this->assertSame( 'Studio', get_post_meta( $this->item_id, '_node_library_developer', true ) );
		$this->assertSame( array( 'audio' => false ), get_post_meta( $this->item_id, '_node_library_japanese', true ) );
		$this->submit( array( 'developer' => '', 'japanese' => array( 'audio' => '' ) ) );
		$this->assertFalse( metadata_exists( 'post', $this->item_id, '_node_library_developer' ) );
		$this->assertFalse( metadata_exists( 'post', $this->item_id, '_node_library_japanese' ) );
		$this->assertSame( 'Publisher', get_post_meta( $this->item_id, '_node_library_publisher', true ) );
	}

	public function test_invalid_input_does_not_store_nested_payloads_or_unknown_flags(): void {
		$this->submit( array(
			'store_ids' => array( 'steam' => array( 'attack' ), 'microsoft' => 'https://example.com', 'extra' => '123' ),
			'developer' => array( 'attack' ), 'publisher' => '<b>Publisher</b>', 'release_date' => '2023-02-29',
			'genres' => array( array( 'attack' ), '<b>RPG</b>', 'RPG' ),
			'japanese' => array( 'audio' => 'false', 'ui' => array( 'attack' ), 'extra' => true ),
			'capabilities' => array( 'max_fps' => '-60', 'resolution' => array( 'attack' ) ),
			'rating' => array( 'cero' => 'X', 'esrb' => '<script>bad</script>' ),
		) );
		foreach ( array( 'store_ids', 'developer', 'release_date', 'japanese', 'capabilities', 'rating' ) as $field ) {
			$this->assertFalse( metadata_exists( 'post', $this->item_id, '_node_library_' . $field ), $field );
		}
		$this->assertSame( 'Publisher', get_post_meta( $this->item_id, '_node_library_publisher', true ) );
		$this->assertSame( array( 'RPG' ), get_post_meta( $this->item_id, '_node_library_genres', true ) );
	}

	public function test_nonce_and_capability_are_required(): void {
		$_POST = array( 'node_library_structured' => array( 'developer' => 'Blocked' ) );
		Node_Library_Structured_Meta::save( $this->item_id );
		$_POST['node_library_structured_nonce'] = 'invalid';
		Node_Library_Structured_Meta::save( $this->item_id );
		$_POST['node_library_structured_nonce'] = array( 'invalid' );
		Node_Library_Structured_Meta::save( $this->item_id );
		$this->assertFalse( metadata_exists( 'post', $this->item_id, '_node_library_developer' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->submit( array( 'developer' => 'Blocked' ) );
		$this->assertFalse( metadata_exists( 'post', $this->item_id, '_node_library_developer' ) );
	}

	public function test_autosaves_revisions_and_other_post_types_are_ignored(): void {
		$this->submit( array( 'developer' => 'Original' ) );
		$revision = self::factory()->post->create( array( 'post_type' => 'revision', 'post_parent' => $this->item_id, 'post_name' => $this->item_id . '-revision-v1' ) );
		$autosave = self::factory()->post->create( array( 'post_type' => 'revision', 'post_parent' => $this->item_id, 'post_name' => $this->item_id . '-autosave-v1' ) );
		$ordinary = self::factory()->post->create();
		foreach ( array( $revision, $autosave, $ordinary ) as $id ) {
			Node_Library_Structured_Meta::save( $id );
			$this->assertFalse( metadata_exists( 'post', $id, '_node_library_developer' ) );
		}
		$this->assertSame( 'Original', get_post_meta( $this->item_id, '_node_library_developer', true ) );
	}

	public function test_structured_fields_do_not_change_legacy_card_output(): void {
		$links = array( array( 'platform' => 'Steam', 'url' => 'https://store.steampowered.com/app/123/', 'category' => 'pc', 'hardware' => 'windows-pc' ) );
		update_post_meta( $this->item_id, '_node_library_links', $links );
		update_post_meta( $this->item_id, '_node_library_summary', 'Legacy summary' );
		$library = Node_Library::instance();
		$before = $library->render_block( array( 'libraryId' => $this->item_id ) );
		$this->assertNotEmpty( $before );
		$this->submit( array( 'store_ids' => array( 'steam' => '123' ), 'developer' => 'New Studio' ) );
		// Preserve title because the submit helper renames the item.
		wp_update_post( array( 'ID' => $this->item_id, 'post_title' => 'Catalogue test' ) );
		// Each render intentionally allocates unique DOM IDs. Compare everything else.
		$normalize_ids = static fn( $html ) => preg_replace( '/(node-library-(?:store-tabs|steam-panel)-)\d+/', '$1ID', $html );
		$this->assertSame( $normalize_ids( $before ), $normalize_ids( $library->render_block( array( 'libraryId' => $this->item_id ) ) ) );
		$this->assertSame( $links, get_post_meta( $this->item_id, '_node_library_links', true ) );
		$this->assertSame( 'Legacy summary', get_post_meta( $this->item_id, '_node_library_summary', true ) );
	}
	public function test_subscriptions_round_trip_unknown_keys_and_empty_deletion(): void {
		$this->submit( array(
			'subscriptions' => array(
				'_present'     => '1',
				'gamepass'     => '1',
				'psplus'       => '0',
				'nso'          => true,
				'ea_play'      => false,
				'ubisoft_plus' => array( 'attack' ),
				'unknown_sub'  => '1',
			),
		) );
		$this->assertSame(
			array( 'gamepass' => true, 'nso' => true ),
			get_post_meta( $this->item_id, '_node_library_subscriptions', true )
		);

		ob_start();
		Node_Library_Structured_Meta::render( get_post( $this->item_id ) );
		$html = ob_get_clean();
		$this->assertMatchesRegularExpression( '/id="node-library-structured-subscriptions-gamepass"[^>]*checked=\'checked\'/', $html );
		$this->assertMatchesRegularExpression( '/id="node-library-structured-subscriptions-nso"[^>]*checked=\'checked\'/', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="node-library-structured-subscriptions-psplus"[^>]*checked=/', $html );

		// Omitting subscriptions from another submission keeps the stored flags.
		$this->submit( array( 'developer' => 'Keep Subs' ) );
		$this->assertSame(
			array( 'gamepass' => true, 'nso' => true ),
			get_post_meta( $this->item_id, '_node_library_subscriptions', true )
		);

		// All false or unchecked submission removes the metadata like other fields.
		$this->submit( array( 'subscriptions' => array( 'gamepass' => false, 'psplus' => '0' ) ) );
		$this->assertFalse( metadata_exists( 'post', $this->item_id, '_node_library_subscriptions' ) );

		$this->submit( array( 'subscriptions' => array( 'apple_arcade' => '1' ) ) );
		$this->assertSame( array( 'apple_arcade' => true ), get_post_meta( $this->item_id, '_node_library_subscriptions', true ) );
		$this->submit( array( 'subscriptions' => array( '_present' => '1' ) ) );
		$this->assertFalse( metadata_exists( 'post', $this->item_id, '_node_library_subscriptions' ) );
	}

	public function test_standalone_package_matches_canonical_source(): void {
		$root = dirname( __DIR__ );
		$zip  = new ZipArchive();
		$this->assertTrue( true === $zip->open( $root . '/production_plugins/node-library.zip' ) );
		$source = $root . '/plugins-embedded/node-library';
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$relative = substr( $file->getPathname(), strlen( $source ) + 1 );
			$this->assertSame( file_get_contents( $file->getPathname() ), $zip->getFromName( 'node-library/' . $relative ), $relative );
		}
		$zip->close();
	}

}
