<?php
/**
 * PR-4 価格評価統一・初回 NULL 防止・無料フィルタテスト（A-6, A-8, A-10, H-5, M-4①②）。
 *
 * @package Node_Library
 */

class Node_Library_Prices_PR4_Test extends WP_UnitTestCase {

	public function test_initial_null_offer_is_not_recorded_and_lowest_includes_regular_price_drop(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => 'Price PR4 Test',
			)
		);
		$ext = '9PR4TEST0001';
		update_post_meta( $id, '_node_library_store_ids', array( 'microsoft' => $ext ) );

		// A-6 / M-4①: 初回 null は INSERT されない
		$this->assertFalse( Node_Library_Prices::record( $id, 'microsoft', $ext, null, time() - 3600 ) );
		$this->assertNull( Node_Library_Prices::current( $id, 'microsoft' ) );

		// M-4②: セール 5000 → 定価改定 4000 で lowest が 4000.0000 になり逆転しない
		$this->assertTrue(
			Node_Library_Prices::record(
				$id,
				'microsoft',
				$ext,
				array( 'price' => 5000, 'list_price' => 6000, 'currency' => 'JPY', 'sale_end' => null ),
				time() - 2000
			)
		);
		$this->assertTrue(
			Node_Library_Prices::record(
				$id,
				'microsoft',
				$ext,
				array( 'price' => 4000, 'list_price' => 4000, 'currency' => 'JPY', 'sale_end' => null ),
				time() - 1000
			)
		);
		$stats = Node_Library_Prices::stats( $id, 'microsoft' );
		$this->assertSame( '4000.0000', $stats['current']['price'] );
		$this->assertSame( '4000.0000', $stats['lowest'], '定価値下げ後の過去最安値が現在価格を上回らないこと' );
	}

	public function test_archive_price_filter_includes_is_free_items_and_uses_effective_offer(): void {
		$free_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => 'Free Title',
			)
		);
		update_post_meta( $free_id, '_node_library_is_free', '1' );

		$expired_sale_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => 'Expired Sale Title',
			)
		);
		$ext = '9PR4TEST0002';
		update_post_meta( $expired_sale_id, '_node_library_store_ids', array( 'microsoft' => $ext ) );
		$now = time();
		Node_Library_Prices::record(
			$expired_sale_id,
			'microsoft',
			$ext,
			array(
				'price'      => 1500,
				'list_price' => 5000,
				'currency'   => 'JPY',
				'sale_end'   => gmdate( 'Y-m-d H:i:s', $now - 600 ),
			),
			$now - 3600
		);

		$matched = Node_Library_Archive_Filters::filter_ids_by_price(
			array( $free_id, $expired_sale_id ),
			array(
				'price_min' => '',
				'price_max' => '2000',
				'on_sale'   => '',
			),
			$now
		);

		$this->assertContains( $free_id, $matched, 'price_max フィルタに is_free 作品が含まれること' );
		$this->assertNotContains( $expired_sale_id, $matched, 'sale_end 経過後は定価 5000 として price_max=2000 から除外されること' );
	}
}
