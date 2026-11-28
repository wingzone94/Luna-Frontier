<?php
/**
 * PR-6 テスト（H-7 shutdown 一括キャッシュ失効キュー）。
 *
 * @package Node_Library
 */

class Node_Library_Cache_PR6_Test extends WP_UnitTestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_shutdown_queue_invalidates_archives_and_referencing_posts(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$urls = array();

		function wpsc_delete_url_cache( $url ) {
			do_action( 'node_test_pr6_wpsc_delete', $url );
		}
		add_action(
			'node_test_pr6_wpsc_delete',
			static function ( $url ) use ( &$urls ) {
				$urls[] = $url;
			}
		);

		$lib_id = self::factory()->post->create(
			array(
				'post_type'   => 'node_library',
				'post_status' => 'publish',
				'post_title'  => 'Cache Target Game',
			)
		);
		$article_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Referencing Article',
			)
		);
		update_post_meta( $article_id, '_node_linked_library_id', $lib_id );

		node_library_delete_detail_cache( $lib_id );
		node_library_delete_detail_cache( $lib_id );
		node_library_flush_cache_invalidation_queue();

		$this->assertContains( get_permalink( $lib_id ), $urls );
		$this->assertContains( home_url( '/library/' ), $urls );
		$this->assertContains( home_url( '/library/game/' ), $urls );
		$this->assertContains( home_url( '/library/app/' ), $urls );
		$this->assertContains( get_permalink( $article_id ), $urls );
		$this->assertSame( 1, count( array_keys( $urls, home_url( '/library/' ), true ) ), '/library/ は重複排除されて1回だけ失効されること' );
	}
}
