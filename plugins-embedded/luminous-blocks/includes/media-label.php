<?php
/**
 * メディアラベル表示
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'node_render_media_label' ) ) {
    function node_render_media_label($image_url, $label_text) {
        if (empty($image_url)) return '';
        ob_start();
        ?>
        <div class="m3-media-label-wrapper">
            <img src="<?php echo esc_url($image_url); ?>" alt="" loading="lazy">
            <span class="m3-media-badge">
                <span class="material-symbols-outlined" data-icon="auto_awesome"></span>
                <?php echo esc_html($label_text); ?>
            </span>
        </div>
        <?php
        return ob_get_clean();
    }
}
