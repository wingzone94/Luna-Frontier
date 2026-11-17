<?php
/**
 * アイキャッチ画像の代替テキスト（alt）自動付与
 *
 * 画像の内容チェック（誤字・本文との相違）は精度が実用に届かなかったため 1.3 で撤去した。
 * 画像の「出典」も特定しない（Gemini のグラウンディングは逆画像検索ではなく、
 * 自作画像に他社の記事URLを出典として提示してしまうため）。
 * ここに残すのは alt の生成・設定だけ。
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'node_ai_set_image_alt' ) ) {
	/**
	 * 添付画像の alt を設定する。既存の alt は上書きしない。
	 *
	 * @param int    $attachment_id 添付ID。
	 * @param string $alt           設定する alt。
	 * @param bool   $force         既存があっても上書きするか。
	 * @return true|WP_Error
	 */
	function node_ai_set_image_alt( int $attachment_id, string $alt, bool $force = false ) {
		$alt = sanitize_text_field( trim( $alt ) );

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'invalid_attachment', '対象の画像が見つかりません。' );
		}

		if ( '' === $alt ) {
			return new WP_Error( 'empty_alt', '設定する代替テキストが空です。' );
		}

		$current = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' !== trim( $current ) && ! $force ) {
			return new WP_Error( 'alt_exists', 'すでに代替テキストが設定されています。' );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

		return true;
	}
}

/**
 * アイキャッチの alt 自動生成が有効か。既定は有効。
 */
if ( ! function_exists( 'node_ai_auto_alt_enabled' ) ) {
	function node_ai_auto_alt_enabled(): bool {
		return '1' === (string) get_option( 'node_ai_auto_alt', '1' );
	}
}

if ( ! function_exists( 'node_ai_maybe_schedule_alt_generation' ) ) {
	/**
	 * 保存時、アイキャッチに alt が無ければ生成を予約する。
	 *
	 * 保存処理の中で API を呼ぶと公開が遅れるうえ失敗が公開を巻き込むため、
	 * ファクトチェックと同じく cron へ逃がす。
	 *
	 * @param int     $post_id 投稿ID。
	 * @param WP_Post $post    投稿。
	 */
	function node_ai_maybe_schedule_alt_generation( $post_id, $post ): void {
		if ( ! node_ai_auto_alt_enabled() || ! function_exists( 'node_ai_core' ) || ! node_ai_core()->is_enabled() ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return;
		}

		// すでに alt があるなら何もしない（人が書いた alt を尊重する）
		$current = (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
		if ( '' !== trim( $current ) ) {
			return;
		}

		if ( wp_next_scheduled( 'node_ai_auto_alt', array( $thumbnail_id ) ) ) {
			return;
		}

		wp_schedule_single_event( time() + 30, 'node_ai_auto_alt', array( $thumbnail_id ) );
	}
}

if ( ! function_exists( 'node_ai_run_auto_alt' ) ) {
	/**
	 * cron 実行: アイキャッチの alt を生成して設定する。
	 *
	 * @param int $attachment_id 添付ID。
	 */
	function node_ai_run_auto_alt( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! node_ai_auto_alt_enabled() || ! function_exists( 'node_ai_core' ) || ! node_ai_core()->is_enabled() ) {
			return;
		}

		$current = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' !== trim( $current ) ) {
			return;
		}

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return;
		}

		$result = node_ai_core()->analyze_image( (string) $path, (int) get_post_field( 'post_author', $attachment_id ), $attachment_id );

		if ( is_wp_error( $result ) ) {
			update_post_meta( $attachment_id, '_node_ai_alt_error', sanitize_text_field( $result->get_error_message() ) );
			return;
		}

		$alt = (string) ( $result['alt_suggestion'] ?? '' );
		if ( '' === trim( $alt ) ) {
			return;
		}

		node_ai_set_image_alt( $attachment_id, $alt );
		delete_post_meta( $attachment_id, '_node_ai_alt_error' );
	}
}
