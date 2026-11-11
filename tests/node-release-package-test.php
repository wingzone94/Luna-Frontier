<?php
/**
 * Node チャンネル配布物の終了ガード。
 *
 * 以前このファイルは master の node.zip の中身を検証していた。
 * 対象は、オートロード対象 PHP の同梱、開発成果物の非混入、
 * style.css と build.json のバージョン一致だった。
 *
 * Node チャンネルは終了し、正規配布は Luna Frontier の GitHub Releases になった。
 * master に残っていた node.zip / luna.zip / build.json はそのチャンネルの成果物
 * （luna.zip は master 上の未使用コピー）なので追跡しない。
 * luna-frontier-2.0-skyalow の build.json と Releases の luna.zip は別物であり、
 * このテストでは見ない。
 *
 * @package Node_Theme
 */

class Node_Release_Package_Test extends WP_UnitTestCase {

	/**
	 * Node チャンネル終了後、master に旧配布物を戻していないこと。
	 */
	public function test_retired_node_channel_artifacts_are_absent(): void {
		$repo = dirname( __DIR__ );

		$this->assertFileDoesNotExist(
			$repo . '/node.zip',
			'Node チャンネルは終了した。master の node.zip は配布に使わない。'
		);
		$this->assertFileDoesNotExist(
			$repo . '/luna.zip',
			'master の luna.zip は node.zip の未使用コピーだった。正規配布は GitHub Releases。'
		);
		$this->assertFileDoesNotExist(
			$repo . '/build.json',
			'master の build.json は Node チャンネル用。Luna のパッケージング用 build.json は luna-frontier-2.0-skyalow に残す。'
		);
	}
}
