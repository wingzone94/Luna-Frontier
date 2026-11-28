<?php
/**
 * メタデータハンドラ
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'node_ai_calculate_reading_time' ) ) {
    /**
     * 読了時間の自動計算
     */
    function node_ai_calculate_reading_time( int $post_id, \WP_Post $post, bool $update ): void {
        // 自動保存時はスキップ
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // 投稿タイプとステータスのチェック
        if ($post->post_type !== 'post' || $post->post_status !== 'publish') {
            return;
        }

        // 本文の取得（ショートコードとタグを除去）
        $content = strip_shortcodes(strip_tags($post->post_content));
        $hash = md5($content);

        // 内容に変更がある場合のみ計算を実行
        if ($hash !== get_post_meta($post_id, '_node_content_hash', true)) {
            // 文字数カウント（空白を除去）
            $char_count = mb_strlen(preg_replace('/\s+/', '', $content));
            
            // 1. 文字数ベースの読了時間計算 (分速500文字換算)
            $total_seconds = ceil(($char_count / 500) * 60);
            
            // 2. 「〇分〇秒」または「〇分」形式で整形
            $minutes = floor($total_seconds / 60);
            $seconds = $total_seconds % 60;
            $time_string = ($minutes > 0) ? $minutes . '分' . sprintf('%02d', $seconds) . '秒' : $seconds . '秒';
            
            update_post_meta($post_id, '_node_reading_time', $time_string);
            update_post_meta($post_id, '_node_content_hash', $hash);
        }
    }
}

if ( ! function_exists( 'node_ai_save_meta' ) ) {
    /**
     * AI Core 関連のメタデータ保存処理
     */
    function node_ai_save_meta($post_id) {
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        // AI要約用 Nonce チェック
        if ( isset( $_POST['node_ai_generate_nonce'] ) && is_string( $_POST['node_ai_generate_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['node_ai_generate_nonce'] ), 'node_ai_generate_action' ) ) {
            if ( isset( $_POST['node_ai_summary'] ) ) {
                update_post_meta( $post_id, '_node_ai_summary', sanitize_textarea_field( wp_unslash( (string) $_POST['node_ai_summary'] ) ) );
            }
            if ( isset( $_POST['node_ai_custom_prompt'] ) ) {
                update_post_meta( $post_id, '_node_ai_custom_prompt', sanitize_text_field( wp_unslash( (string) $_POST['node_ai_custom_prompt'] ) ) );
            }
            if ( isset( $_POST['node_ai_max_lines'] ) ) {
                update_post_meta( $post_id, '_node_ai_max_lines', intval( wp_unslash( $_POST['node_ai_max_lines'] ) ) );
            }
            if ( isset( $_POST['node_ai_max_chars'] ) ) {
                update_post_meta( $post_id, '_node_ai_max_chars', intval( wp_unslash( $_POST['node_ai_max_chars'] ) ) );
            }
        }

        if ( isset( $_POST['node_ai_fact_check_nonce'] ) && is_string( $_POST['node_ai_fact_check_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['node_ai_fact_check_nonce'] ), 'node_ai_fact_check_action' ) ) {
            $approved = ! empty( $_POST['node_ai_fact_check_approved'] ) ? '1' : '';
            update_post_meta( $post_id, '_node_ai_fact_check_approved', $approved );
        }
    }
}
