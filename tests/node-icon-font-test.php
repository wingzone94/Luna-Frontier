<?php
/**
 * Material Symbols のサブセット URL のテスト。
 *
 * 絞り込みが壊れると Google Fonts は全アイコン（実測 3,874KB）を返す。
 * 静かに 3.8MB へ戻るので、URL の形を固定しておく。
 *
 * @package Node
 */

if ( ! function_exists( 'node_get_icon_font_url' ) ) {
	require_once dirname( __DIR__ ) . '/inc/icon-font.php';
}

class Node_Icon_Font_Test extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'node_icon_font_names' );
		parent::tear_down();
	}

	public function test_icon_names_are_sorted_and_unique() {
		$names = node_get_icon_font_names();

		$this->assertNotEmpty( $names );
		$this->assertSame( array_values( array_unique( $names ) ), $names, '重複がある' );

		$sorted = $names;
		sort( $sorted );
		$this->assertSame( $sorted, $names, '昇順になっていない' );
	}

	public function test_url_requests_only_the_listed_icons() {
		$url = node_get_icon_font_url();

		$this->assertStringStartsWith( 'https://fonts.googleapis.com/css2?', $url );
		$this->assertStringContainsString( 'icon_names=', $url );

		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame(
			node_get_icon_font_names(),
			explode( ',', (string) ( $query['icon_names'] ?? '' ) ),
			'URL のアイコン一覧が関数の返り値と食い違っている'
		);
	}

	/**
	 * `:` `,` `@` がパーセントエンコードされると Google Fonts が解釈できず、
	 * 全アイコンのフォールバック（3,874KB）が返ってくる。
	 */
	public function test_axis_descriptor_is_not_percent_encoded() {
		$url = node_get_icon_font_url();

		$this->assertStringContainsString( 'family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200', $url );
		$this->assertStringNotContainsString( '%3A', $url );
		$this->assertStringNotContainsString( '%2C', $url );
		$this->assertStringNotContainsString( '%40', $url );
	}

	/**
	 * 可変軸は CSS が font-variation-settings で使っているので落とせない。
	 */
	public function test_variable_axes_are_kept() {
		foreach ( array( 'opsz', 'wght', 'FILL', 'GRAD' ) as $axis ) {
			$this->assertStringContainsString( $axis, node_get_icon_font_url(), "軸 {$axis} が落ちている" );
		}
	}

	public function test_display_block_is_kept() {
		$this->assertStringContainsString( 'display=block', node_get_icon_font_url() );
	}

	/**
	 * 静的ウェイトを並べると、同じ可変フォントの @font-face がウェイト数だけ増える。
	 * 範囲指定でも 400 / 500 / 700 / 800 は軸の内側に残る。
	 */
	public function test_text_font_css_uses_variable_ranges_without_dropping_families() {
		$url = node_get_text_font_stylesheet_url();

		$this->assertStringStartsWith( 'https://fonts.googleapis.com/css2?', $url );
		$this->assertStringContainsString( 'family=Manrope:wght@400..800', $url );
		$this->assertStringContainsString( 'family=Inter:wght@400..700', $url );
		$this->assertStringContainsString( 'family=Noto+Sans+JP:wght@400..700', $url );
		$this->assertStringContainsString( 'display=swap', $url );
		$this->assertStringNotContainsString( 'wght@400;500;700', $url );

		$header = (string) file_get_contents( dirname( __DIR__ ) . '/template-parts/legacy/header.php' );
		$this->assertStringContainsString( 'node_get_text_font_stylesheet_url()', $header );
		$this->assertStringNotContainsString( 'wght@400;500;700', $header );
	}

	public function test_filter_can_add_icons() {
		add_filter(
			'node_icon_font_names',
			static function ( array $names ): array {
				$names[] = 'rocket_launch';
				return $names;
			}
		);

		$this->assertContains( 'rocket_launch', node_get_icon_font_names() );
		$this->assertStringContainsString( 'rocket_launch', node_get_icon_font_url() );
	}

	public function test_invalid_names_are_dropped() {
		add_filter(
			'node_icon_font_names',
			static function ( array $names ): array {
				return array_merge( $names, array( 'Bad Name', 'x', '', 'ok_name', 'inject&display=swap' ) );
			}
		);

		$names = node_get_icon_font_names();

		$this->assertContains( 'ok_name', $names );
		$this->assertNotContains( 'Bad Name', $names );
		$this->assertNotContains( 'x', $names );
		$this->assertNotContains( 'inject&display=swap', $names );
		$this->assertStringNotContainsString( 'display=swap', node_get_icon_font_url() );
	}

	/**
	 * 一覧が空になったら絞り込みを諦めて全アイコンを出す。
	 * 巨大になるが、アイコンが文字化けするよりはまし。
	 */
	public function test_empty_list_falls_back_to_all_icons() {
		add_filter( 'node_icon_font_names', '__return_empty_array' );

		$url = node_get_icon_font_url();

		$this->assertStringNotContainsString( 'icon_names=', $url );
		$this->assertStringContainsString( 'family=Material+Symbols+Outlined', $url );
	}
}
