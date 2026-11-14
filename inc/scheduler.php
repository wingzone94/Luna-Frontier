<?php

declare(strict_types=1);
/**
 * Node Custom Scheduler & Missed Schedule Fixer
 * 
 * 1. Missed Schedule Fix: 予約投稿の失敗を自動検知して公開
 * 2. AI Task on Publish: 予約投稿が公開された瞬間にAI要約を生成
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1. Missed Schedule Fixer
 * サイト訪問時や管理画面操作時に、公開時間を過ぎている予約投稿を強制的に公開します。
 */
function node_check_missed_schedules() {
    if ( wp_doing_ajax() || wp_is_json_request() ) {
        return;
    }

    // options の一意キーで初回取得し、期限切れは条件付きUPDATEで1リクエストだけ更新する。
    // 非永続の wp_cache_add() は別PHPプロセス間の排他にならないため使用しない。
    global $wpdb;
    $lock_name = 'node_missed_schedule_lock';
    $now_unix = time();
    $expires = $now_unix + 5 * MINUTE_IN_SECONDS;
    $inserted = $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
        $lock_name,
        (string) $expires
    ) );
    if ( false === $inserted ) {
        return;
    }
    if ( 1 !== $inserted ) {
        $acquired = $wpdb->query( $wpdb->prepare(
            "UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) <= %d",
            (string) $expires,
            $lock_name,
            $now_unix
        ) );
        if ( 1 !== $acquired ) {
            return;
        }
    }
    wp_cache_delete( $lock_name, 'options' );
    wp_cache_delete( 'notoptions', 'options' );
    // 実行後も期限まで保持して、従来の5分間隔の負荷制限を維持する。
    $now = current_time( 'mysql' );

    // 公開時間を過ぎている future ステータスの投稿を取得
    $missed_posts = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM $wpdb->posts WHERE post_status = 'future' AND post_date <= %s",
        $now
    ) );

    if ( ! empty( $missed_posts ) ) {
        foreach ( $missed_posts as $post_id ) {
            wp_publish_post( (int) $post_id );
        }
    }
}
add_action( 'init', 'node_check_missed_schedules' );

/**
 * 2. AI Task on Publish
 * 記事が公開された際、自動的にAI要約を生成します。
 *
 * X (Twitter) 自動投稿は node-connect プラグイン（Node_Connect_X_Poster）へ移管済み。
 * オプション名（node_x_*）と投稿済みメタ（_node_x_posted）は互換のまま引き継がれている。
 */
function node_on_post_published( $new_status, $old_status, $post ) {
    // 記事が公開ステータスになった時（新規公開または予約投稿の公開）
    if ( $new_status === 'publish' && $old_status !== 'publish' && $post instanceof WP_Post && $post->post_type === 'post' ) {

        // --- AI 要約の生成 ---
        $existing = get_post_meta( $post->ID, '_node_ai_summary', true );
        if ( empty( $existing ) && class_exists( 'Node_AI_Tools' ) ) {
            // プラグインの既存Cron実行口を再利用。REST保存でも公開リクエスト内でAIを呼ばない。
            $args = array( (int) $post->ID );
            if ( false === wp_next_scheduled( 'node_ai_auto_generate_summary', $args ) ) {
                wp_schedule_single_event( time(), 'node_ai_auto_generate_summary', $args );
            }
        }
    }
}
add_action( 'transition_post_status', 'node_on_post_published', 10, 3 );

/**
 * 3. X (Twitter) 連携用専用 RSS フィード (予備として維持)
 */
function node_add_x_rss_feed() {
    add_feed( 'x-post', 'node_render_x_rss_feed' );
}
add_action( 'init', 'node_add_x_rss_feed' );

function node_render_x_rss_feed() {
    $posts = get_posts( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 5,
    ] );

    $charset = (string) preg_replace( '/[\r\n]+/', '', (string) get_option( 'blog_charset', 'UTF-8' ) );
    if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9._-]*$/D', $charset ) ) {
        $charset = 'UTF-8';
    }
    if ( ! headers_sent() ) {
        header( 'Content-Type: application/rss+xml; charset=' . esc_attr( $charset ), true );
    }
    echo '<?xml version="1.0" encoding="' . esc_attr( $charset ) . '"?' . '>';
    ?>
    <rss version="2.0">
    <channel>
        <title><?php bloginfo_rss( 'name' ); ?> - X Auto Post Feed</title>
        <link><?php bloginfo_rss( 'url' ); ?></link>
        <description>Feed for X automation</description>
        <?php foreach ( $posts as $post ) : setup_postdata( $post ); ?>
        <item>
            <title><?php echo ent2ncr( esc_html( get_the_title( $post->ID ) ) ); ?></title>
            <link><?php echo esc_url( (string) get_permalink( $post->ID ) ); ?></link>
            <guid><?php echo esc_url( (string) get_permalink( $post->ID ) ); ?></guid>
            <pubDate><?php echo mysql2date( 'D, d M Y H:i:s +0000', $post->post_date_gmt, false ); ?></pubDate>
            <description>
                <![CDATA[
                <?php 
                $summary = get_post_meta( $post->ID, '_node_ai_summary', true );
                $trimmed = $summary ? wp_trim_words( (string) $summary, 50 ) : '';
                $trimmed = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $trimmed );
                echo str_replace( ']]>', ']]]]><![CDATA[>', $trimmed );
                ?>
                ]]>
            </description>
        </item>
        <?php endforeach; wp_reset_postdata(); ?>
    </channel>
    </rss>
    <?php
}
