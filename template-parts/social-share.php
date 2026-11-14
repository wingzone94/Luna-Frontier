<?php

declare(strict_types=1);
/**
 * Social Share Buttons Template
 */
$permalink = get_permalink();
$title = wp_strip_all_tags(get_the_title());
$share_text = trim($title . ' ' . $permalink);
$encoded_url = rawurlencode($permalink);
$encoded_title = rawurlencode($title);
$x_share_url = add_query_arg(
    [
        'text' => $share_text,
    ],
    'https://x.com/intent/tweet'
);
$facebook_share_url = 'https://www.facebook.com/sharer/sharer.php?u=' . $encoded_url;
$line_share_url = 'https://social-plugins.line.me/lineit/share?url=' . $encoded_url;
$hatebu_share_url = 'https://b.hatena.ne.jp/add?mode=confirm&url=' . $encoded_url;
$threads_share_url = 'https://www.threads.net/intent/post?text=' . rawurlencode($share_text);
$bluesky_share_url = 'https://bsky.app/intent/compose?text=' . rawurlencode($share_text);
$misskey_share_url = 'https://misskey-hub.net/share/?text=' . $encoded_title . '&url=' . $encoded_url;

// 印刷ボタン（Node 1.3 第5段階）。設定で位置（先頭/末尾）を選べる
$print_button_html     = ( is_singular( 'post' ) && function_exists( 'node_print_is_enabled' ) && node_print_is_enabled() )
    ? node_print_get_button_html()
    : '';
$print_button_position = function_exists( 'node_print_button_position' ) ? node_print_button_position() : 'end';
?>

<div class="m3-share-section">
    <div class="m3-share-heading">
        <h3 class="m3-share-title">この記事をシェアする</h3>
        <button type="button"
                class="m3-share-view-toggle"
                data-share-view-toggle
                role="switch"
                aria-checked="false"
                aria-controls="m3-share-buttons">
            <span class="m3-share-view-toggle__text">最小表示</span>
            <span class="m3-share-view-toggle__track" aria-hidden="true">
                <span class="m3-share-view-toggle__thumb"></span>
            </span>
        </button>
    </div>
    <div class="m3-share-buttons" id="m3-share-buttons" data-share-buttons>
        <?php
        if ( $print_button_html && 'start' === $print_button_position ) {
            echo $print_button_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        ?>
        <!-- X (Twitter) -->
        <a href="<?php echo esc_url($x_share_url); ?>"
           class="m3-share-btn m3-share-btn--x" 
           data-url="<?php echo esc_url($permalink); ?>"
           data-share-title="<?php echo esc_attr($title); ?>"
           data-share-popup="true"
           title="Xでシェア" 
           aria-label="Xでシェアする" 
           target="_blank" 
           rel="nofollow noopener noreferrer">
            <svg class="m3-share-btn__icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="currentColor">
                <path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932Zm-1.291 19.491h2.039L6.486 3.24H4.298Z"/>
            </svg>
            <span class="m3-share-btn__label">X</span>
        </a>

        <!-- Facebook -->
        <a href="<?php echo esc_url($facebook_share_url); ?>"
           class="m3-share-btn m3-share-btn--facebook" 
           data-url="<?php echo esc_url($permalink); ?>"
           data-share-title="<?php echo esc_attr($title); ?>"
           data-share-popup="true"
           title="Facebookでシェア" 
           aria-label="Facebookでシェアする" 
           target="_blank" 
           rel="nofollow noopener noreferrer">
            <svg class="m3-share-btn__icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="currentColor">
                <path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.76 0 2.072.149 2.608.298v3.325c-.283-.03-.775-.045-1.386-.045-1.967 0-2.728.745-2.728 2.683v1.297h3.92l-.673 3.667h-3.247v7.98Z"/>
            </svg>
            <span class="m3-share-btn__label">Facebook</span>
        </a>

        <!-- LINE -->
        <a href="<?php echo esc_url($line_share_url); ?>"
           class="m3-share-btn m3-share-btn--line" 
           data-url="<?php echo esc_url($permalink); ?>"
           data-share-title="<?php echo esc_attr($title); ?>"
           data-share-popup="true"
           title="LINEで送る" 
           aria-label="LINEで送る" 
           target="_blank" 
           rel="nofollow noopener noreferrer">
            <svg class="m3-share-btn__icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="currentColor">
                <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755Zm-3.855 3.016c0 .27-.174.51-.432.596a.6.6 0 0 1-.199.031c-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595a.6.6 0 0 1 .194-.033c.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771Zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771Zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.63.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314Z"/>
            </svg>
            <span class="m3-share-btn__label">LINE</span>
        </a>

        <!-- はてなブックマーク -->
        <a href="<?php echo esc_url($hatebu_share_url); ?>"
           class="m3-share-btn m3-share-btn--hatebu" 
           data-url="<?php echo esc_url($permalink); ?>"
           data-share-title="<?php echo esc_attr($title); ?>"
           data-share-popup="true"
           title="はてなブックマークに追加" 
           aria-label="はてなブックマークに追加する" 
           target="_blank" 
           rel="nofollow noopener noreferrer">
            <span class="m3-share-btn__icon" aria-hidden="true">B!</span>
            <span class="m3-share-btn__label">はてなブックマーク</span>
        </a>

        <!-- Threads -->
        <a href="<?php echo esc_url($threads_share_url); ?>"
           class="m3-share-btn m3-share-btn--threads" 
           data-url="<?php echo esc_url($permalink); ?>"
           data-share-title="<?php echo esc_attr($title); ?>"
           data-share-popup="true"
           title="Threadsでシェア" 
           aria-label="Threadsでシェアする" 
           target="_blank" 
           rel="nofollow noopener noreferrer">
            <svg class="m3-share-btn__icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="currentColor">
                <path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.027-3.575.878-6.43 2.523-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.902.02 5.297.793 7.12 2.298 1.757 1.45 2.867 3.523 3.299 6.162l-2.003.333c-.748-4.579-3.584-6.755-8.423-6.787-2.95.021-5.196.94-6.676 2.733-1.34 1.625-2.034 4.066-2.058 7.258.024 3.192.718 5.633 2.058 7.258 1.48 1.793 3.727 2.713 6.676 2.733 2.653-.019 4.455-.637 5.842-2.003 1.578-1.553 1.95-3.611 1.008-5.506-.539-1.087-1.489-1.888-2.786-2.371-.316 2.292-1.03 4.04-2.14 5.204-.993 1.04-2.33 1.594-3.975 1.647-1.25.04-2.448-.263-3.372-.852-1.095-.698-1.72-1.748-1.76-2.955-.079-2.379 1.822-4.09 4.733-4.264 1.034-.061 2.004-.017 2.893.129-.118-.696-.355-1.236-.707-1.617-.482-.521-1.214-.788-2.177-.793h-.029c-.774 0-1.835.22-2.504 1.262L7.88 7.823c.915-1.426 2.406-2.211 4.203-2.211h.045c2.886.018 4.741 1.76 5.119 4.793.084.034.168.07.25.107 1.832.823 3.13 2.077 3.859 3.729 1.333 3.019-.176 5.885-1.862 7.543C17.707 23.543 15.437 23.977 12.186 24Zm.541-11.1c-.281 0-.57.009-.866.026-1.663.096-2.66.846-2.626 1.955.026.774.924 1.608 2.446 1.558 1.537-.05 2.852-.953 3.199-3.358-.627-.12-1.35-.181-2.153-.181Z"/>
            </svg>
            <span class="m3-share-btn__label">Threads</span>
        </a>

        <!-- BlueSky -->
        <a href="<?php echo esc_url($bluesky_share_url); ?>"
           class="m3-share-btn m3-share-btn--bluesky" 
           data-url="<?php echo esc_url($permalink); ?>"
           data-share-title="<?php echo esc_attr($title); ?>"
           data-share-popup="true"
           title="BlueSkyでシェア" 
           aria-label="BlueSkyでシェアする" 
           target="_blank" 
           rel="nofollow noopener noreferrer">
            <span class="m3-share-btn__icon">
                <!-- Bluesky Butterfly Logo (Latest) -->
                <svg viewBox="0 0 600 530" width="24" height="24" fill="currentColor" aria-hidden="true" focusable="false">
                    <path d="m135.72 44.03c95.596 6.581 148.85 168.42 164.28 215.39 15.427-46.973 68.682-208.81 164.28-215.39 92.354-6.353 148.1 49.988 116.14 135.53-27.135 72.63-107.03 126.9-172.46 122.84 81.332 4.062 165.73 54.588 165.73 158.5 0 94.707-73.125 146.4-152.03 120.48-69.213-22.734-111.45-103.77-121.65-123.63-10.198 19.855-52.434 100.89-121.65 123.63-78.905 25.922-152.03-25.77-152.03-120.48 0-103.92 84.402-154.44 165.73-158.5-65.433 4.062-145.33-50.211-172.46-122.84-31.956-85.539 23.784-141.88 116.14-135.53z"/>
                </svg>
            </span>
            <span class="m3-share-btn__label">BlueSky</span>
        </a>

        <!-- Misskey.io -->
        <a href="<?php echo esc_url($misskey_share_url); ?>"
           class="m3-share-btn m3-share-btn--misskey" 
           data-url="<?php echo esc_url($permalink); ?>"
           data-share-title="<?php echo esc_attr($title); ?>"
           data-share-popup="true"
           title="Misskeyでシェア" 
           aria-label="Misskeyでシェアする" 
           target="_blank" 
           rel="nofollow noopener noreferrer">
            <span class="m3-share-btn__icon" aria-hidden="true">Mi</span>
            <span class="m3-share-btn__label">Misskey</span>
        </a>

        <!-- システムシェア: wp_is_mobile() はキャッシュ済みHTMLでUAと食い違うため、
             常に出力して navigator.share 対応ブラウザのみJSで表示する -->
        <button class="m3-share-btn m3-share-btn--system"
                id="m3-system-share-trigger"
                data-url="<?php echo esc_url($permalink); ?>"
                data-share-title="<?php echo esc_attr($title); ?>"
                title="システムシェア"
                aria-label="システムのシェア機能を開く"
                hidden>
            <span class="material-symbols-outlined" data-icon="share" aria-hidden="true"></span>
            <span class="m3-share-btn__label">シェア</span>
        </button>

        <!-- リンクをコピー -->
        <button class="m3-share-btn m3-share-btn--copy" 
                id="m3-copy-trigger" 
                data-url="<?php echo esc_url($permalink); ?>"
                data-share-title="<?php echo esc_attr($title); ?>"
                title="タイトルとURLをコピー" 
                aria-label="この記事のタイトルとURLをコピーする">
            <span class="material-symbols-outlined m3-copy-icon" data-icon="content_copy" aria-hidden="true"></span>
            <span class="m3-share-btn__label m3-copy-label">コピー</span>
        </button>

        <?php
        if ( $print_button_html && 'end' === $print_button_position ) {
            echo $print_button_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        ?>
    </div>
    <?php get_template_part( 'template-parts/preferred-source', null, array( 'context' => 'article' ) ); ?>
</div>
