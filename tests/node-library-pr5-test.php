<?php
/**
 * PR-5 テスト（H-4, H-6, H-14①, A-7, M-5）。
 *
 * @package Node_Library
 */

class Node_Library_PR5_Test extends WP_UnitTestCase {

	public function test_sync_item_flags_preserves_existing_leaving_at(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => 'Sub Leaving Test',
			)
		);
		$leaving = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
		$this->assertSame( 'joined', Node_Library_Subscriptions::observe( $id, 'gamepass', true, time() - DAY_IN_SECONDS, $leaving ) );

		update_post_meta( $id, '_node_library_subscriptions', array( 'gamepass' => true ) );
		$res = Node_Library_Subscriptions::sync_item_flags( $id );
		$this->assertSame( 'unchanged', $res['gamepass'] );

		$history = Node_Library_Subscriptions::get_item_history( $id );
		$this->assertSame( $leaving, $history[0]['leaving_at'] ?? null, '通常保存同期で leaving_at が NULL 上書きされないこと' );
	}

	public function test_store_id_normalization_and_no_duplicate_store_cards(): void {
		$sanitized = Node_Library_Structured_Meta::sanitize(
			'store_ids',
			array(
				'steam'     => '000620',
				'microsoft' => '9nblggh537bl',
				'ps'        => 'INVALID-SHORT',
				'nintendo'  => '70010000000026',
			)
		);
		$this->assertSame( '620', $sanitized['steam'] ?? null );
		$this->assertSame( '9NBLGGH537BL', $sanitized['microsoft'] ?? null );
		$this->assertArrayNotHasKey( 'ps', $sanitized, '規格外の PS ID は拒否されること' );
		$this->assertSame( '70010000000026', $sanitized['nintendo'] ?? null );

		// A-7: 既存リンクと同じストアの ID があるときに重複カードが生成されないこと
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => 'No Duplicate Card Test',
			)
		);
		update_post_meta( $id, '_node_library_store_ids', array( 'steam' => '3240220' ) );
		$links  = array( array( 'platform' => 'Steam', 'url' => 'https://store.steampowered.com/app/271590/' ) );
		$offers = Node_Library_Single_Extensions::store_offers( $id, $links );
		$this->assertCount( 1, $offers, '同一ストアのリンクが既にある場合に合成カードが重複生成されないこと' );
	}

	public function test_non_microsoft_windows_link_does_not_get_microsoft_brand_chip(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => 'Google Earth PC Test',
			)
		);
		update_post_meta(
			$id,
			'_node_library_links',
			array(
				array(
					'platform' => 'PC（公式サイト）',
					'url'      => 'https://www.google.com/earth/versions/',
				),
			)
		);
		$html = Node_Library::instance()->render_block( array( 'libraryId' => $id ) );
		$this->assertStringNotContainsString( 'microsoft-store-badge-ja.svg', $html, '公式 PC ダウンロードリンクに Microsoft Store バッジが付かないこと' );
	}
}
