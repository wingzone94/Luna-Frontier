<?php
/**
 * 管理画面からの置き換え・復元 AJAX。
 *
 * node-ai-tools/includes/ajax-handlers.php の流儀に合わせる
 * （function_exists ラップ / check_ajax_referer( $action, 'nonce' ) / wp_send_json_*）。
 *
 * @package Node_Image_Compressor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'node_ic_ajax_guard' ) ) {
	/**
	 * 共通の nonce・権限チェックを済ませ、対象のアタッチメント ID を返す。
	 */
	function node_ic_ajax_guard(): int {
		check_ajax_referer( 'node_ic_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '権限がありません。' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( $attachment_id <= 0 ) {
			wp_send_json_error( array( 'message' => '対象の画像が指定されていません。' ) );
		}

		// 自分がアップロードした画像だけを操作できるようにする
		if ( ! Node_IC_Converter::is_owned_by_current_user( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => '他の人がアップロードした画像は操作できません。' ) );
		}

		return $attachment_id;
	}
}

if ( ! function_exists( 'node_ic_ajax_payload' ) ) {
	/**
	 * 置き換え・復元の結果をフロントの表示更新用に整形する。
	 */
	function node_ic_ajax_payload( int $attachment_id, array $result ): array {
		$status = node_ic_get_status( $attachment_id );

		return array(
			'attachment_id' => $attachment_id,
			'message'       => $result['message'],
			'state'         => $status['state'],
			'label'         => $status['label'],
			'reason'        => $status['reason'],
			'before'        => $status['before'],
			'after'         => $status['after'],
			'beforeText'    => $status['before'] > 0 ? size_format( $status['before'] ) : '',
			'afterText'     => $status['after'] > 0 ? size_format( $status['after'] ) : '',
			'rate'          => round( $status['rate'], 1 ),
			'restorable'    => (bool) $status['restorable'],
			'thumbnail'     => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
		);
	}
}

if ( ! function_exists( 'node_ic_ajax_convert_one' ) ) {
	/**
	 * 1 件置き換える。一括置き換えは JS 側からこれを逐次呼ぶ。
	 */
	function node_ic_ajax_convert_one(): void {
		$attachment_id = node_ic_ajax_guard();

		$result = Node_IC_Converter::convert( $attachment_id );

		if ( ! $result['ok'] ) {
			wp_send_json_error( node_ic_ajax_payload( $attachment_id, $result ) );
		}

		wp_send_json_success( node_ic_ajax_payload( $attachment_id, $result ) );
	}
}

if ( ! function_exists( 'node_ic_ajax_restore_one' ) ) {
	/**
	 * 1 件を元の JPEG/PNG に戻す。
	 */
	function node_ic_ajax_restore_one(): void {
		$attachment_id = node_ic_ajax_guard();

		$result = Node_IC_Converter::restore( $attachment_id );

		if ( ! $result['ok'] ) {
			wp_send_json_error( node_ic_ajax_payload( $attachment_id, $result ) );
		}

		wp_send_json_success( node_ic_ajax_payload( $attachment_id, $result ) );
	}
}

if ( ! function_exists( 'node_ic_ajax_skip_toggle' ) ) {
	/**
	 * スキップ指定の付け外し。
	 */
	function node_ic_ajax_skip_toggle(): void {
		$attachment_id = node_ic_ajax_guard();

		if ( '' !== Node_IC_Converter::get_skip_reason( $attachment_id ) ) {
			delete_post_meta( $attachment_id, Node_IC_Converter::META_SKIP );
			$message = 'スキップを解除しました。';
		} else {
			update_post_meta( $attachment_id, Node_IC_Converter::META_SKIP, '手動でスキップ指定されています。' );
			$message = 'この画像をスキップします。';
		}

		node_ic_flush_target_cache();

		wp_send_json_success(
			node_ic_ajax_payload(
				$attachment_id,
				array(
					'ok'      => true,
					'before'  => 0,
					'after'   => 0,
					'message' => $message,
				)
			)
		);
	}
}
