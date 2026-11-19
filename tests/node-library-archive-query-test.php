<?php
/**
 * Library archive search, tab filters, and sort.
 *
 * @package Node
 */

require_once dirname( __DIR__ ) . '/plugins-embedded/node-library/node-library.php';

/**
 * Front-end archive query for /library/.
 */
class Node_Library_Archive_Query_Test extends WP_UnitTestCase {
	private const TOKEN = 'nlarch-8c2e';

	public function set_up(): void {
		parent::set_up();
		$library = Node_Library::instance();
		$this->set_permalink_structure( '/%postname%/' );
		$library->register_cpt();
		flush_rewrite_rules( false );
	}

	public function test_plain_archive_keeps_the_default_query(): void {
		$this->go_to( home_url( '/library/' ) );

		global $wp_query;
		$this->assertTrue( $wp_query->is_post_type_archive( 'node_library' ) );
		$this->assertFalse( $wp_query->is_search() );
		$this->assertSame( '', (string) $wp_query->get( 'node_library_keyword' ) );
		$this->assertSame( '', (string) $wp_query->get( 'node_library_sort' ) );
		$this->assertEmpty( $wp_query->get( 'post__in' ) );
	}

	public function test_keyword_matches_title_or_summary_without_switching_to_search(): void {
		$title_id = $this->create_item( self::TOKEN . '-title', '別の紹介' );
		$summary_id = $this->create_item( '別タイトル', '紹介文に ' . self::TOKEN . '-summary を含む' );
		$other_id = $this->create_item( '無関係', '一致しない紹介文' );

		$this->go_to( home_url( '/library/?q=' . rawurlencode( self::TOKEN . '-title' ) ) );
		global $wp_query;
		$found = wp_list_pluck( $wp_query->posts, 'ID' );
		$this->assertContains( $title_id, $found );
		$this->assertNotContains( $summary_id, $found );
		$this->assertNotContains( $other_id, $found );
		$this->assertFalse( $wp_query->is_search() );
		$this->assertTrue( $wp_query->is_post_type_archive( 'node_library' ) );

		$this->go_to( home_url( '/library/?q=' . rawurlencode( self::TOKEN . '-summary' ) ) );
		$found = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );
		$this->assertContains( $summary_id, $found );
		$this->assertNotContains( $title_id, $found );
	}

	public function test_tab_filter_uses_saved_categories_and_auto_detection(): void {
		$pc_id = $this->create_item(
			'PC作品',
			self::TOKEN . '-tabs',
			array(
				array(
					'platform' => 'Steam',
					'url'      => 'https://example.com/steam',
					'category' => 'auto',
					'hardware' => 'windows-pc',
				),
			)
		);
		$console_id = $this->create_item(
			'コンソール作品',
			self::TOKEN . '-tabs',
			array(
				array(
					'platform' => 'Nintendo Switch',
					'url'      => 'https://example.com/switch',
					'category' => 'auto',
					'hardware' => 'nintendo-switch',
				),
			)
		);
		$mobile_id = $this->create_item(
			'スマホ作品',
			self::TOKEN . '-tabs',
			array(
				array(
					'platform' => 'Steam',
					'url'      => 'https://example.com/manual-mobile',
					'category' => 'mobile',
					'hardware' => 'auto',
				),
			)
		);

		$this->go_to( home_url( '/library/?q=' . rawurlencode( self::TOKEN . '-tabs' ) . '&tab=pc' ) );
		$found = $this->found_ids();
		$this->assertSame( array( $pc_id ), $found );

		$this->go_to( home_url( '/library/?q=' . rawurlencode( self::TOKEN . '-tabs' ) . '&tab=console' ) );
		$this->assertSame( array( $console_id ), $this->found_ids() );

		$this->go_to( home_url( '/library/?q=' . rawurlencode( self::TOKEN . '-tabs' ) . '&tab=auto' ) );
		$auto_found = $this->found_ids();
		sort( $auto_found );
		$expected_auto = array( $pc_id, $console_id );
		sort( $expected_auto );
		$this->assertSame( $expected_auto, $auto_found );
		$this->assertNotContains( $mobile_id, $auto_found );

		$this->go_to( home_url( '/library/game/?q=' . rawurlencode( self::TOKEN . '-tabs' ) ) );
		$this->assertSame( 'game', get_query_var( 'node_library_type' ) );
		$this->assertFalse( $GLOBALS['wp_query']->is_search() );
	}

	public function test_title_and_release_date_sort_keep_items_without_a_date(): void {
		$bravo = $this->create_item( 'Bravo ' . self::TOKEN, self::TOKEN . '-sort', array(), '2020-01-02' );
		$alpha = $this->create_item( 'Alpha ' . self::TOKEN, self::TOKEN . '-sort', array(), '2024-06-01' );
		$plain = $this->create_item( 'Zulu ' . self::TOKEN, self::TOKEN . '-sort' );

		$this->go_to( home_url( '/library/?q=' . rawurlencode( self::TOKEN . '-sort' ) . '&sort=title&dir=asc' ) );
		$this->assertSame( array( $alpha, $bravo, $plain ), $this->found_ids() );

		$this->go_to( home_url( '/library/?q=' . rawurlencode( self::TOKEN . '-sort' ) . '&sort=date&dir=desc' ) );
		$this->assertSame( array( $alpha, $bravo, $plain ), $this->found_ids() );

		$this->go_to( home_url( '/library/?q=' . rawurlencode( self::TOKEN . '-sort' ) . '&sort=date&dir=asc' ) );
		$this->assertSame( array( $bravo, $alpha, $plain ), $this->found_ids() );
	}

	public function test_toolbar_lists_the_existing_tab_categories(): void {
		$tabs = node_library_tab_categories();
		$seen = false;
		add_action(
			'node_library_archive_toolbar_after',
			static function () use ( &$seen ): void {
				$seen = true;
			}
		);

		ob_start();
		node_library_render_archive_toolbar();
		$html = (string) ob_get_clean();

		$this->assertTrue( $seen );
		$this->assertStringContainsString( 'name="q"', $html );
		$this->assertStringContainsString( 'name="tab"', $html );
		$this->assertStringContainsString( 'name="sort"', $html );
		$this->assertStringContainsString( 'name="dir"', $html );
		$this->assertStringContainsString( '発売日', $html );
		$this->assertStringContainsString( 'タイトル', $html );
		foreach ( $tabs as $value => $label ) {
			$this->assertStringContainsString( 'value="' . $value . '"', $html );
			$this->assertStringContainsString( $label, $html );
		}
	}

	/**
	 * @param array<int,array<string,string>> $links Link rows.
	 */
	private function create_item( string $title, string $summary, array $links = array(), string $release_date = '' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		update_post_meta( $post_id, '_node_library_summary', $summary );
		if ( $links ) {
			update_post_meta( $post_id, '_node_library_links', $links );
		}
		if ( '' !== $release_date ) {
			update_post_meta( $post_id, '_node_library_release_date', $release_date );
		}

		return (int) $post_id;
	}

	/**
	 * @return array<int,int>
	 */
	private function found_ids(): array {
		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}
}
