<?php
/**
 * node_register_settings() の名前付きサニタイズコールバックの固定テスト。
 *
 * option を DB へ書かない。関数を直接呼ぶ。
 */

require_once dirname( __DIR__ ) . '/inc/admin-settings.php';

if ( ! defined( 'NODE_PREFERRED_SOURCE_DEFAULT_URL' ) ) {
	define( 'NODE_PREFERRED_SOURCE_DEFAULT_URL', 'https://luminous-core.net/' );
}

class Node_Settings_Sanitize_Test extends WP_UnitTestCase {

	public function test_checkbox_flag_maps_only_string_one_to_one(): void {
		$this->assertSame( '1', node_sanitize_checkbox_flag( '1' ) );
		$this->assertSame( '0', node_sanitize_checkbox_flag( '0' ) );
		$this->assertSame( '0', node_sanitize_checkbox_flag( '' ) );
		$this->assertSame( '0', node_sanitize_checkbox_flag( 'true' ) );
		$this->assertSame( '0', node_sanitize_checkbox_flag( '2' ) );
		$this->assertSame( '0', node_sanitize_checkbox_flag( null ) );
	}

	public function test_single_line_text_drops_tags_and_line_breaks(): void {
		$this->assertSame( 'Steam で見る', node_sanitize_single_line_text( " Steam で見る\n" ) );
		$this->assertSame( 'alert(1)', node_sanitize_single_line_text( '<script>alert(1)</script>' ) );
		// 改行の扱いは WP 本体の sanitize_text_field に一致させる（実装が直呼びのため）。
		$this->assertSame( sanitize_text_field( "a\nb" ), node_sanitize_single_line_text( "a\nb" ) );
	}

	public function test_disclosure_text_never_keeps_markup_or_breaks(): void {
		$result = node_sanitize_disclosure_text( '<script>alert(1)</script>注記' );
		$this->assertStringNotContainsString( '<script', $result );
		$this->assertStringNotContainsString( "\n", $result );
		$this->assertStringContainsString( 'alert(1)', $result, '中身のテキストは単一行テキストとして残る（esc_html 出力前提）' );
		$this->assertSame( node_sanitize_single_line_text( '<script>alert(1)</script>注記' ), $result );
	}

	public function test_attachment_id_is_cast_to_non_negative_int(): void {
		$this->assertSame( 12, node_sanitize_attachment_id( '12abc' ) );
		$this->assertSame( 0, node_sanitize_attachment_id( '' ) );
		$this->assertSame( 0, node_sanitize_attachment_id( null ) );
		$this->assertSame( 0, node_sanitize_attachment_id( 0 ) );
	}

	public function test_ten_options_use_the_named_callbacks(): void {
		$expected = array(
			'node_library_auto_insert'         => 'node_sanitize_checkbox_flag',
			'node_library_header_text'         => 'node_sanitize_single_line_text',
			'node_library_button_text'         => 'node_sanitize_single_line_text',
			'luminous_nexus_amazon_id'         => 'node_sanitize_single_line_text',
			'luminous_nexus_rakuten_id'        => 'node_sanitize_single_line_text',
			'luminous_nexus_disclosure_amazon' => 'node_sanitize_disclosure_text',
			'luminous_nexus_disclosure_rakuten'=> 'node_sanitize_disclosure_text',
			'node_ogp_enabled'                 => 'node_sanitize_checkbox_flag',
			'node_ogp_bg_id'                   => 'node_sanitize_attachment_id',
			'node_ogp_logo_id'                 => 'node_sanitize_attachment_id',
		);

		node_register_settings();
		$registered = get_registered_settings();

		foreach ( $expected as $option => $callback ) {
			$this->assertArrayHasKey( $option, $registered, $option . ' が登録されていない' );
			$this->assertSame( $callback, $registered[ $option ]['sanitize_callback'], $option . ' の sanitize_callback が不正' );
		}
	}

	public function test_preferred_and_print_callbacks_are_not_affected(): void {
		node_register_settings();
		$registered = get_registered_settings();

		$this->assertSame( '1', $registered['node_preferred_source_enabled']['sanitize_callback']( '1' ) );
		$this->assertSame( 'article', $registered['node_preferred_source_placement']['sanitize_callback']( 'both' ) );

		if ( defined( 'NODE_PRINT_OPTION_POSITION' ) && isset( $registered[ NODE_PRINT_OPTION_POSITION ] ) ) {
			$this->assertSame( 'end', $registered[ NODE_PRINT_OPTION_POSITION ]['sanitize_callback']( 'end' ) );
		} else {
			$this->markTestSkipped( '印刷オプション定数がこのブートストラップでは未定義' );
		}
	}
}
