<?php
/**
 * Node チャンネル配布物の終了ガード（luna-frontier-2.0-skyalow）。
 *
 * 以前このファイルは、このブランチに同梱する Node 安定版 node.zip（1.4.0）の
 * 中身を検証していた。Node チャンネルは終了し、node.zip は配布しない。
 * Luna のパッケージング用 build.json と、リリース時に生成する luna.zip は残す。
 * luna.zip の中身は Luna_Release_Package_Test が検証する。
 *
 * @package Luna_Frontier
 */

class Node_Release_Package_Test extends WP_UnitTestCase {

	/**
	 * Node チャンネル用 ZIP は置かず、Luna の build.json は残っていること。
	 */
	public function test_node_channel_zip_is_retired_and_luna_build_json_remains(): void {
		$repo = dirname( __DIR__ );

		$this->assertFileDoesNotExist(
			$repo . '/node.zip',
			'Node チャンネルは終了した。このブランチに node.zip は置かない。'
		);
		$this->assertFileExists(
			$repo . '/build.json',
			'Luna のパッケージングが使う build.json は luna-frontier-2.0-skyalow に残す。'
		);
		$build = json_decode( (string) file_get_contents( $repo . '/build.json' ), true );
		$this->assertIsArray( $build, 'build.json が読めない' );
		$this->assertNotSame( '', (string) ( $build['build_id'] ?? '' ), 'build.json に build_id が無い' );
		$this->assertNotSame( '', (string) ( $build['version'] ?? '' ), 'build.json に version が無い' );
	}
}
