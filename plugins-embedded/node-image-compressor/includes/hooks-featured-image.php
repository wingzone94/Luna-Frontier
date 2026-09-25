<?php
/**
 * アイキャッチ設定を検知して非同期に WebP への置き換えを予約する。
 *
 * アップロード時点では「その画像がアイキャッチになるか」が分からないため、
 * _thumbnail_id の書き込みを監視する。保存時の体感を遅くしないよう、
 * 置き換えそのものは cron に逃がす（node-ai-tools の alt 自動生成と同じ方式）。
 *
 * @package Node_Image_Compressor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'node_ic_on_thumbnail_meta' ) ) {
	/**
	 * added_post_meta / updated_post_meta 共通のハンドラ。
	 *
	 * @param int    $meta_id    メタ ID（未使用）。
	 * @param int    $object_id  投稿 ID。
	 * @param string $meta_key   メタキー。
	 * @param mixed  $meta_value メタ値（アタッチメント ID）。
	 */
	function node_ic_on_thumbnail_meta( $meta_id, $object_id, $meta_key, $meta_value ): void {
		unset( $meta_id, $object_id );

		if ( '_thumbnail_id' !== $meta_key ) {
			return;
		}

		if ( ! Node_IC_Converter::is_auto_enabled() ) {
			return;
		}

		$attachment_id = (int) $meta_value;
		if ( $attachment_id <= 0 ) {
			return;
		}

		node_ic_schedule_conversion( $attachment_id );
	}
}

if ( ! function_exists( 'node_ic_schedule_conversion' ) ) {
	/**
	 * 置き換えを単発 cron に予約する。同じ ID が既に予約済みなら何もしない。
	 */
	function node_ic_schedule_conversion( int $attachment_id ): void {
		$args = array( $attachment_id );

		if ( wp_next_scheduled( Node_IC_Converter::CRON_HOOK, $args ) ) {
			return;
		}

		// 置き換え不可なものを毎回キューに積まないよう、この時点で弾いておく
		$reason = '';
		if ( ! Node_IC_Converter::can_convert( $attachment_id, $reason ) ) {
			return;
		}

		// 投稿保存直後は添付メタの整備が終わっていないことがあるので少し遅らせる
		wp_schedule_single_event( time() + 30, Node_IC_Converter::CRON_HOOK, $args );
	}
}

if ( ! function_exists( 'node_ic_run_scheduled_conversion' ) ) {
	/**
	 * cron 実体。
	 */
	function node_ic_run_scheduled_conversion( $attachment_id ): void {
		Node_IC_Converter::convert( (int) $attachment_id );
	}
}
