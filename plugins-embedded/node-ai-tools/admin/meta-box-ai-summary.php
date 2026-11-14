<?php
/**
 * AI 要約メタボックス UI（スタブ）
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function node_ai_render_summary_meta_box($post) {
    $summary       = get_post_meta($post->ID, '_node_ai_summary', true);
    $custom_prompt = get_post_meta($post->ID, '_node_ai_custom_prompt', true);
    $max_lines     = get_post_meta($post->ID, '_node_ai_max_lines', true) ?: 3;
    $max_chars     = get_post_meta($post->ID, '_node_ai_max_chars', true) ?: 120;
    $generated_at  = (string) get_post_meta( $post->ID, '_node_ai_summary_generated_at', true );
    
    wp_nonce_field('node_ai_generate_action', 'node_ai_generate_nonce');
    
    echo '<div class="m3-ai-meta-box">';
    
    // Model selection
    $user_id = get_current_user_id();
    $current_model = function_exists('node_get_user_gemini_model') ? node_get_user_gemini_model($user_id) : '';
        // 保存値は `<モデルID>@<思考量>` 形式。一覧はモデルIDのみのため分解して照合する
        $current_model = function_exists('node_split_gemini_model') ? node_split_gemini_model($current_model)['model'] : $current_model;
    // 実際に使えるモデルだけを出す（Pro / Preview / 提供終了 / 一時的に利用不可のものは除外）
    $models = class_exists('Node_AI_Fact_Check_Models')
        ? Node_AI_Fact_Check_Models::usable_options($user_id)
        : ( function_exists('node_get_gemini_model_options_for_user') ? node_get_gemini_model_options_for_user($user_id) : [] );
    
    $is_gemini = ! function_exists( 'node_ai_core' ) || 'gemini' === node_ai_core()->get_provider_id();
    if ( $is_gemini && ! empty( $models ) ) {
        echo '<p><strong>使用モデル:</strong></p>';
        echo '<select id="node_ai_gemini_model" name="node_ai_gemini_model" style="width:100%; margin-bottom:10px;">';
        foreach ( $models as $id => $label ) {
            echo '<option value="' . esc_attr( $id ) . '" ' . selected( $id, $current_model, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    echo '<p><strong>AIへの追加指示 (プロンプト):</strong></p>';
    echo '<textarea id="node_ai_custom_prompt" name="node_ai_custom_prompt" style="width:100%; height:60px; margin-bottom:10px;" placeholder="例: もっと情熱的に、専門用語を避けて、箇条書きで...">'.esc_textarea($custom_prompt).'</textarea>';
    
    echo '<div style="display: flex; gap: 10px; margin-bottom: 15px;">';
    echo '  <div style="flex: 1;">';
    echo '    <label><strong>最大行数:</strong></label>';
    echo '    <input type="number" id="node_ai_max_lines" name="node_ai_max_lines" value="'.esc_attr($max_lines).'" style="width:100%;" min="1" max="10" />';
    echo '  </div>';
    echo '  <div style="flex: 1;">';
    echo '    <label><strong>最大文字数:</strong></label>';
    echo '    <input type="number" id="node_ai_max_chars" name="node_ai_max_chars" value="'.esc_attr($max_chars).'" style="width:100%;" min="20" max="500" />';
    echo '  </div>';
    echo '</div>';
    
    echo '<p><strong>記事の要約:</strong></p>';
    if ( '' !== $summary && '' !== $generated_at && strtotime( $generated_at ) < strtotime( $post->post_modified ) ) {
        echo '<div class="notice notice-warning inline"><p>この記事は要約生成後に更新されています。内容を確認し、必要な場合のみ再生成してください。</p></div>';
    }
    echo '<textarea id="node_ai_summary_textarea" name="node_ai_summary" style="width:100%; height:100px;" placeholder="AIで生成、または手動入力してください...">'.esc_textarea($summary).'</textarea>';
    
    echo '<p style="margin-top:15px;"><button type="button" id="node_generate_ai_btn" class="button button-primary" data-post-id="'.esc_attr($post->ID).'">AIで要約を生成</button>';
    echo '<span id="node_ai_generate_status" style="margin-left: 10px; font-weight: bold; display: block; margin-top: 5px;"></span></p>';
    echo '</div>';
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('#node_generate_ai_btn').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var status = $('#node_ai_generate_status');
            var postId = btn.data('post-id');
            var nonce = $('#node_ai_generate_nonce').val();
            var customPrompt = $('#node_ai_custom_prompt').val();
            var maxLines = $('#node_ai_max_lines').val();
            var maxChars = $('#node_ai_max_chars').val();
            var geminiModel = $('#node_ai_gemini_model').length ? $('#node_ai_gemini_model').val() : '';

            if (btn.prop('disabled')) {
                return;
            }
            if ($.trim($('#node_ai_summary_textarea').val()) !== '' && !window.confirm('現在の要約を上書きして再生成しますか？')) {
                return;
            }
            
            btn.prop('disabled', true);
            status.text('生成中...').css('color', '#FF9900');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                timeout: 70000,
                data: {
                    action: 'node_generate_ai_summary',
                    post_id: postId,
                    custom_prompt: customPrompt,
                    max_lines: maxLines,
                    max_chars: maxChars,
                    gemini_model: geminiModel,
                    nonce: nonce
                }
            }).done(function(response) {
                if (response && response.success && response.data) {
                    $('#node_ai_summary_textarea').val(response.data.summary || '');
                    status.text('生成完了！').css('color', 'green');
                } else {
                    var message = (response && response.data && response.data.message) ? response.data.message : '不明なエラーが発生しました。';
                    status.text('エラー: ' + message).css('color', 'red');
                }
            }).fail(function(xhr, textStatus) {
                if (textStatus === 'timeout') {
                    status.text('タイムアウトしました。時間をおいて再試行してください。').css('color', 'red');
                } else {
                    status.text('通信エラーが発生しました。').css('color', 'red');
                }
            }).always(function() {
                btn.prop('disabled', false);
            });
        });
    });
    </script>
    <?php
}
