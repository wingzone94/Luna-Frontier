<?php
/**
 * 投票ブロック
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'node_render_voting_refined' ) ) {
    function node_render_voting_refined($attributes) {
        $question = $attributes['question'] ?? 'この内容は参考になりましたか？';
        ob_start();
        ?>
        <div class="m3-voting-card">
            <h4 class="m3-voting-card__question"><?php echo esc_html($question); ?></h4>
            <div class="m3-voting-card__actions">
                <button class="m3-button m3-button--tonal" data-vote="yes">
                    <span class="material-symbols-outlined" data-icon="thumb_up"></span>
                    はい
                </button>
                <button class="m3-button m3-button--tonal" data-vote="no">
                    <span class="material-symbols-outlined" data-icon="thumb_down"></span>
                    いいえ
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
