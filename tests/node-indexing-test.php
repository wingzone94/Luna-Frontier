<?php
/**
 * 検索インデックス制御（inc/indexing.php）の自動テスト。
 *
 * GSC「ページがインデックスに登録されなかった理由」への対応を回帰から守る。
 * NODE-1.3.md §22 参照。
 *
 * @package Node
 */

class Node_Indexing_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%year%/%monthnum%/%postname%/' );
		update_option( 'blog_public', '1' );
	}

	/**
	 * 指定件数の記事を紐づけたタグを作る。
	 */
	private function make_tag_with_posts( string $slug, int $post_count ): int {
		$term_id = $this->factory->term->create(
			array(
				'taxonomy' => 'post_tag',
				'slug'     => $slug,
				'name'     => $slug,
			)
		);

		for ( $i = 0; $i < $post_count; $i++ ) {
			$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
			wp_set_object_terms( $post_id, array( (int) $term_id ), 'post_tag' );
		}

		wp_update_term_count_now( array( (int) $term_id ), 'post_tag' );

		return (int) $term_id;
	}

	public function test_thin_tag_archive_is_noindex_follow(): void {
		$term_id = $this->make_tag_with_posts( 'thin-tag', 1 );
		$term    = get_term( $term_id, 'post_tag' );

		$this->go_to( get_term_link( $term ) );

		$this->assertTrue( is_tag() );

		$robots = apply_filters( 'wp_robots', array() );

		$this->assertArrayHasKey( 'noindex', $robots );
		$this->assertTrue( $robots['noindex'] );
		// リンク先の記事は辿ってほしいので follow は残す。
		$this->assertArrayHasKey( 'follow', $robots );
		$this->assertArrayNotHasKey( 'nofollow', $robots );
	}

	public function test_tag_archive_at_threshold_stays_indexable(): void {
		$term_id = $this->make_tag_with_posts( 'fat-tag', 2 );
		$term    = get_term( $term_id, 'post_tag' );

		$this->go_to( get_term_link( $term ) );

		$robots = apply_filters( 'wp_robots', array() );

		$this->assertArrayNotHasKey( 'noindex', $robots );
	}

	public function test_category_archive_is_not_thinned(): void {
		// カテゴリはサイトの構造なので、記事1件でも索引対象のまま残す。
		$term_id = $this->factory->term->create(
			array(
				'taxonomy' => 'category',
				'slug'     => 'lonely-category',
			)
		);
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		wp_set_object_terms( $post_id, array( (int) $term_id ), 'category' );
		wp_update_term_count_now( array( (int) $term_id ), 'category' );

		$this->go_to( get_term_link( get_term( $term_id, 'category' ) ) );

		$robots = apply_filters( 'wp_robots', array() );

		$this->assertArrayNotHasKey( 'noindex', $robots );
	}

	public function test_date_archive_is_noindex_follow(): void {
		$this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2026-08-08 10:00:00',
			)
		);

		$this->go_to( '/2026/08/' );

		$this->assertTrue( is_date() );

		$robots = apply_filters( 'wp_robots', array() );

		$this->assertArrayHasKey( 'noindex', $robots );
		$this->assertTrue( $robots['noindex'] );
		$this->assertArrayHasKey( 'follow', $robots );
	}

	public function test_single_post_stays_indexable(): void {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

		$this->go_to( get_permalink( $post_id ) );

		$robots = apply_filters( 'wp_robots', array() );

		$this->assertArrayNotHasKey( 'noindex', $robots );
	}

	public function test_thin_tags_are_excluded_from_sitemap_query(): void {
		$thin = $this->make_tag_with_posts( 'sitemap-thin', 1 );
		$fat  = $this->make_tag_with_posts( 'sitemap-fat', 3 );

		$args = node_indexing_sitemap_taxonomies_query_args( array(), 'post_tag' );

		$this->assertArrayHasKey( 'exclude', $args );
		$this->assertContains( $thin, $args['exclude'] );
		$this->assertNotContains( $fat, $args['exclude'] );
	}

	public function test_spotlight_category_is_excluded_from_sitemap_but_children_are_not(): void {
		$parent = $this->factory->term->create(
			array(
				'taxonomy' => 'category',
				'slug'     => 'spotlight',
				'name'     => 'SPOTLIGHT',
			)
		);
		$child  = $this->factory->term->create(
			array(
				'taxonomy' => 'category',
				'slug'     => 'spotlight-child',
				'parent'   => (int) $parent,
			)
		);

		$excluded = node_indexing_sitemap_excluded_term_ids( 'category' );

		// 親は /spotlight/ へ301するのでサイトマップから外す。
		$this->assertContains( (int) $parent, $excluded );
		// 子は301しないので残す。
		$this->assertNotContains( (int) $child, $excluded );
	}

	public function test_sitemap_entry_filter_does_not_emit_empty_entries(): void {
		// 除外はクエリ引数側で行う方針。entry フィルタで空配列を返すと
		// WP_Sitemaps_Taxonomies が空項目を push してしまうため、
		// テーマ側が entry フィルタを使っていないことを保証する。
		$this->assertFalse(
			has_filter( 'wp_sitemaps_taxonomies_entry', 'node_indexing_sitemap_taxonomies_entry' )
		);
	}

	public function test_custom_archives_get_their_own_title(): void {
		$parts = node_indexing_document_title_parts( array( 'title' => 'ignored' ) );
		// 通常のページでは何も変えない。
		$this->assertSame( 'ignored', $parts['title'] );

		set_query_var( 'node_spotlight', '1' );
		$spotlight = node_indexing_document_title_parts( array( 'title' => 'ignored' ) );
		$this->assertSame( 'SPOTLIGHT 特集一覧', $spotlight['title'] );
		set_query_var( 'node_spotlight', '' );

		set_query_var( 'node_all_articles', '1' );
		$all = node_indexing_document_title_parts( array( 'title' => 'ignored' ) );
		$this->assertSame( '記事一覧', $all['title'] );
		set_query_var( 'node_all_articles', '' );
	}

	public function test_custom_archives_emit_self_referencing_canonical(): void {
		set_query_var( 'node_all_articles', '1' );

		ob_start();
		node_indexing_render_canonical();
		$output = (string) ob_get_clean();

		set_query_var( 'node_all_articles', '' );

		$this->assertStringContainsString( 'rel="canonical"', $output );
		$this->assertStringContainsString(
			home_url( '/' . NODE_ALL_ARTICLES_SLUG . '/' ),
			$output
		);
	}

	public function test_no_canonical_on_regular_pages(): void {
		ob_start();
		node_indexing_render_canonical();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * トップページの WebSite スキーマを取り出す。
	 */
	private function get_website_schema(): array {
		$this->go_to( home_url( '/' ) );

		ob_start();
		node_seo_json_ld();
		$html = (string) ob_get_clean();

		preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches );

		foreach ( $matches[1] as $json ) {
			$decoded = json_decode( trim( $json ), true );
			if ( isset( $decoded['@type'] ) && 'WebSite' === $decoded['@type'] ) {
				return $decoded;
			}
		}

		return array();
	}

	public function test_search_action_target_is_an_entry_point(): void {
		$schema = $this->get_website_schema();

		$this->assertNotEmpty( $schema );

		$target = $schema['potentialAction']['target'];

		// 素の文字列だと Google がテンプレートをそのままクロールし、
		// `/?s={search_term_string}` が GSC の noindex 欄に積み上がる。
		$this->assertIsArray( $target, 'target は EntryPoint オブジェクトでなければならない' );
		$this->assertSame( 'EntryPoint', $target['@type'] );
		$this->assertArrayHasKey( 'urlTemplate', $target );
	}

	public function test_search_action_placeholder_survives_escaping(): void {
		$schema = $this->get_website_schema();

		$this->assertStringContainsString(
			'{search_term_string}',
			$schema['potentialAction']['target']['urlTemplate'],
			'プレースホルダの波括弧がエスケープで壊れていない'
		);
		$this->assertSame(
			'required name=search_term_string',
			$schema['potentialAction']['query-input']
		);
	}

	public function test_search_sort_links_are_nofollow(): void {
		// 検索結果は noindex, follow なので、並び替えリンクを辿られると
		// 同じ結果の並び違いだけがクロールされ続ける。
		$template = (string) file_get_contents( dirname( __DIR__ ) . '/search.php' );

		if ( preg_match( '#add_query_arg\(\s*[\'"]m3_sort[\'"]#', $template ) ) {
			$this->assertStringContainsString( 'rel="nofollow"', $template );
			return;
		}

		// Lunaの検索では並び替えをGETフォームにし、クロール可能なsortリンクを出さない。
		$this->assertStringContainsString( 'method="get"', $template );
		$this->assertStringContainsString( 'name="m3_sort"', $template );
		$this->assertDoesNotMatchRegularExpression( '/<a[^>]+[?&]m3_sort=/', $template );
	}
}
