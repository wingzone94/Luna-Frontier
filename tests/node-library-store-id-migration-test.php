<?php
/** Legacy URL migration safety and complete reporting. @package Node */
require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

class Node_Library_Store_ID_Migration_Test extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Node_Library::instance()->register_cpt();
		Node_Library_Structured_Meta::register();
	}

	private function item( array $urls, string $status = 'publish' ): int {
		$id = self::factory()->post->create( array( 'post_type' => 'node_library', 'post_status' => $status ) );
		$links = array_map( static fn( $url ) => array( 'platform' => 'Store', 'url' => $url, 'category' => 'auto', 'hardware' => 'auto' ), $urls );
		update_post_meta( $id, '_node_library_links', $links );
		return $id;
	}

	/** @dataProvider valid_urls */
	public function test_extracts_only_known_catalogue_ids( string $url, string $store, string $id ): void {
		$this->assertSame( array( 'store' => $store, 'id' => $id ), Node_Library_Store_ID_Migration::extract( $url ) );
	}

	public static function valid_urls(): array {
		return array(
			array( 'https://www.xbox.com/ja-JP/games/store/minecraft/9NBLGGH537BL', 'microsoft', '9NBLGGH537BL' ),
			array( 'https://apps.microsoft.com/detail/9nblggh537bl?hl=ja-JP', 'microsoft', '9NBLGGH537BL' ),
			array( 'https://www.microsoft.com/ja-jp/p/minecraft/9NBLGGH537BL', 'microsoft', '9NBLGGH537BL' ),
			array( 'https://store.steampowered.com/app/000123/title/?snr=test', 'steam', '000123' ),
			array( 'https://www.amazon.co.jp/name/dp/b0hj888pk1/ref=test', 'amazon_asin', 'B0HJ888PK1' ),
			array( 'https://www.amazon.com/gp/aw/d/B0HJ888PK1?tag=test', 'amazon_asin', 'B0HJ888PK1' ),
			array( 'https://store-jp.nintendo.com/item/software/D_70010000000001', 'nintendo', '70010000000001' ),
			array( 'https://store-jp.nintendo.com/item/software/BEE_A_AAAAA', 'nintendo', 'BEE_A_AAAAA' ),
			array( 'https://store.playstation.com/ja-jp/product/JP0000-PPSA00000_00-EXAMPLE', 'ps', 'JP0000-PPSA00000_00-EXAMPLE' ),
			array( 'https://apps.apple.com/jp/app/example/id1234567890', 'apple', '1234567890' ),
			array( 'https://play.google.com/store/apps/details?id=com.example.app&hl=ja', 'googleplay', 'com.example.app' ),
		);
	}

	/** @dataProvider manual_urls */
	public function test_ambiguous_or_deceptive_urls_require_manual_work( string $url ): void {
		$this->assertArrayHasKey( 'reason', Node_Library_Store_ID_Migration::extract( $url ) );
	}

	public static function manual_urls(): array {
		return array_map( static fn( $url ) => array( $url ), array(
			'https://amazon.co.jp.evil.example/dp/B0HJ888PK1',
			'https://example.org/?url=https://www.amazon.co.jp/dp/B0HJ888PK1',
			'https://www.amazon.co.jp/dp/B0HJ888PK1EXTRA',
			'https://user:password@www.amazon.co.jp/dp/B0HJ888PK1',
			'javascript:alert(1)', 'https://amzn.to/short',
			'https://www.microsoft.com/news/abcdefghijkl',
			'https://store.steampowered.com/bundle/123/',
			'https://store.playstation.com/ja-jp/concept/10001234',
			'https://store.epicgames.com/ja/p/example',
			'https://www.gog.com/en/game/example',
			'https://apps.apple.com/jp/developer/nintendo-co-ltd/id1062496488',
			'https://itunes.apple.com/jp/artist/example/id123456',
			'https://play.google.com/store/apps/details?id[]=com.example.app',
		) );
	}

	public function test_preview_reports_every_item_without_writes_and_apply_preserves_links(): void {
		$one = $this->item( array( 'https://store.steampowered.com/app/123/' ) );
		$two = $this->item( array( 'https://amzn.to/manual' ), 'draft' );
		$three = $this->item( array(), 'trash' );
		$links = get_post_meta( $one, '_node_library_links', true );
		$preview = Node_Library_Store_ID_Migration::run();
		$this->assertSame( 3, $preview['total'] );
		$this->assertSame( 1, $preview['success'] );
		$this->assertSame( 2, $preview['needs_manual'] );
		$this->assertSame( array( $one, $two, $three ), array_column( $preview['items'], 'item_id' ) );
		$this->assertFalse( metadata_exists( 'post', $one, '_node_library_store_ids' ) );
		$this->assertFalse( get_option( 'node_library_store_id_migration' ) );
		$applied = Node_Library_Store_ID_Migration::run( true );
		$this->assertSame( 1, $applied['updated'] );
		$this->assertSame( array( 'steam' => '123' ), get_post_meta( $one, '_node_library_store_ids', true ) );
		$this->assertSame( $links, get_post_meta( $one, '_node_library_links', true ) );
		$this->assertSame( 3, get_option( 'node_library_store_id_migration' )['total'] );
		$this->assertSame( 0, Node_Library_Store_ID_Migration::run( true, true )['updated'] );
	}

	public function test_conflicting_urls_do_not_arbitrarily_select_an_edition(): void {
		$id = $this->item( array( 'https://store.steampowered.com/app/123/', 'https://store.steampowered.com/app/456/' ) );
		$result = Node_Library_Store_ID_Migration::run( true );
		$this->assertSame( 1, $result['needs_manual'] );
		$this->assertCount( 2, $result['items'][0]['issues'] );
		$this->assertFalse( metadata_exists( 'post', $id, '_node_library_store_ids' ) );
	}

	public function test_existing_ids_win_and_duplicate_links_are_not_conflicts(): void {
		$id = $this->item( array( 'https://store.steampowered.com/app/123/', 'https://store.steampowered.com/app/123/?x=1' ) );
		$this->assertSame( 'success', Node_Library_Store_ID_Migration::inspect( $id )['status'] );
		update_post_meta( $id, '_node_library_store_ids', array( 'steam' => '999', 'apple' => '00001' ) );
		Node_Library_Store_ID_Migration::run( true );
		$this->assertSame( array( 'steam' => '999', 'apple' => '00001' ), get_post_meta( $id, '_node_library_store_ids', true ) );
		$this->assertSame( 'needs_manual', Node_Library_Store_ID_Migration::inspect( $id )['status'] );
	}

	public function test_completion_guard_requires_explicit_force(): void {
		Node_Library_Store_ID_Migration::run( true );
		$this->expectException( RuntimeException::class );
		Node_Library_Store_ID_Migration::run( true );
	}

	public function test_running_lock_prevents_parallel_application(): void {
		add_option( 'node_library_store_id_migration_lock', time(), '', false );
		$this->expectException( RuntimeException::class );
		Node_Library_Store_ID_Migration::run( true, true );
	}

	public function test_write_failure_is_reported_and_does_not_mark_completed(): void {
		$this->item( array( 'https://store.steampowered.com/app/123/' ) );
		$block = static fn( $check, $id, $key ) => '_node_library_store_ids' === $key ? false : $check;
		add_filter( 'update_post_metadata', $block, 10, 3 );
		try {
			$result = Node_Library_Store_ID_Migration::run( true );
		} finally {
			remove_filter( 'update_post_metadata', $block, 10 );
		}
		$this->assertSame( 1, $result['write_errors'] );
		$this->assertSame( 'needs_manual', $result['items'][0]['status'] );
		$this->assertFalse( get_option( 'node_library_store_id_migration' ) );
		$this->assertFalse( get_option( 'node_library_store_id_migration_lock' ) );
	}

	public function test_cursor_processes_more_than_one_batch_and_all_statuses(): void {
		for ( $i = 0; $i < 105; ++$i ) {
			$this->item( array( 'https://store.steampowered.com/app/' . ( 1000 + $i ) . '/' ), $i % 2 ? 'private' : 'draft' );
		}
		$this->assertSame( 105, Node_Library_Store_ID_Migration::run()['total'] );
	}
	public function test_multiple_stores_in_reverse_link_order_apply_and_rerun_cleanly(): void {
		$id = $this->item( array(
			'https://play.google.com/store/apps/details?id=com.example.app',
			'https://store.steampowered.com/app/123/',
		) );
		$applied = Node_Library_Store_ID_Migration::run( true );
		$this->assertSame( 0, $applied['write_errors'] );
		$this->assertSame( 1, $applied['updated'] );
		$this->assertSame( 1, $applied['success'] );
		$this->assertSame( 0, $applied['needs_manual'] );
		$this->assertIsArray( get_option( 'node_library_store_id_migration' ) );
		$this->assertSame(
			array( 'steam' => '123', 'googleplay' => 'com.example.app' ),
			get_post_meta( $id, '_node_library_store_ids', true )
		);

		$rerun = Node_Library_Store_ID_Migration::run( true, true );
		$this->assertSame( 0, $rerun['write_errors'] );
		$this->assertSame( 0, $rerun['updated'] );
		$this->assertSame( 1, $rerun['success'] );
	}

	public function test_invalid_existing_ids_are_reported_without_rewriting_them(): void {
		global $wpdb;
		$id = $this->item( array( 'https://store.steampowered.com/app/123/' ) );
		// Reproduce pre-existing malformed metadata without the registered sanitizer.
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => '_node_library_store_ids', 'meta_value' => 'malformed legacy data' ) );
		wp_cache_delete( $id, 'post_meta' );
		$report = Node_Library_Store_ID_Migration::run( true );
		$this->assertFalse( $report['items'][0]['can_apply'] );
		$this->assertSame( 'needs_manual', $report['items'][0]['status'] );
		$this->assertSame( 'malformed legacy data', get_post_meta( $id, '_node_library_store_ids', true ) );
	}

}
