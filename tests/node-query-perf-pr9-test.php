<?php
/**
 * PR-9 クエリ性能改善テスト（H-8, H-14③, M-6）。
 *
 * @package Luna_Frontier
 */

class Node_Query_Perf_PR9_Test extends WP_UnitTestCase {

	public function test_prime_current_cache_avoids_n_plus_one_queries(): void {
		$ids = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$id    = self::factory()->post->create(
				array(
					'post_type'   => 'node_library',
					'post_status' => 'publish',
					'post_title'  => 'Prime Price Item ' . $i,
				)
			);
			$ext   = sprintf( '9PRIME0000%02d', $i );
			$ids[] = $id;
			update_post_meta( $id, '_node_library_store_ids', array( 'microsoft' => $ext ) );
			Node_Library_Prices::record(
				$id,
				'microsoft',
				$ext,
				array( 'price' => 1000 * $i, 'list_price' => 1000 * $i, 'currency' => 'JPY', 'sale_end' => null )
			);
		}

		global $wpdb;
		Node_Library_Prices::prime_current_cache( $ids );
		$before = $wpdb->num_queries;
		foreach ( $ids as $idx => $id ) {
			$row = Node_Library_Prices::current( $id, 'microsoft' );
			$this->assertSame( number_format( 1000 * ( $idx + 1 ), 4, '.', '' ), $row['price'] ?? null );
		}
		$this->assertSame( $before, $wpdb->num_queries, 'prime_current_cache 後の current() 呼び出しで追加 SQL が発行されないこと' );
	}

	public function test_find_linked_article_ids_uses_single_sql_and_excludes_primary(): void {
		$lib_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => 'Linked Article Query Target',
			)
		);
		$p1 = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Primary 1' ) );
		$p2 = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Primary 2' ) );
		update_post_meta( $p1, '_node_linked_library_id', (string) $lib_id );
		update_post_meta( $p2, '_node_library_card_reference', (string) $lib_id );

		$found = Node_Library_Single_Extensions::find_linked_article_ids( array( $lib_id ), 12 );
		$this->assertContains( $p1, $found );
		$this->assertContains( $p2, $found );

		$excluded = Node_Library_Single_Extensions::find_linked_article_ids( array( $lib_id ), 12, array( $p1 ) );
		$this->assertNotContains( $p1, $excluded );
		$this->assertContains( $p2, $excluded );
	}
}
