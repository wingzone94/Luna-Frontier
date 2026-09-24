<?php

declare(strict_types=1);
/**
 * AI Summary Component
 *
 * @param array $args {
 *     @type string $summary    要約テキスト
 *     @type string $mode       'card' または 'single'
 *     @type string $tone_color AI判定のカラー
 *     @type array  $keywords   AI抽出のキーワード
 * }
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$summary    = $args['summary'] ?? '';
$mode       = $args['mode'] ?? 'card';
$tone_color = sanitize_hex_color( (string) ( $args['tone_color'] ?? '' ) ) ?: '#FF9800';
$keywords   = $args['keywords'] ?? [];

// 空なら何も出さない
if ( empty( $summary ) ) return;

// カード用（短い要約 + ネイティブ折りたたみ）は article-card.php で実装済みのため、
// ここでは主にシングル用（詳細ページ用）の演出を強化します。
if ( $mode === 'single' ) :
?>
<div class="ai-summary-single is-collapsed" id="m3-ai-summary" style="--ai-vibe-color: <?php echo esc_attr( $tone_color ); ?>;">
    <div class="ai-summary-header" id="ai-summary-toggle">
        <div class="ai-summary-label">
            <span class="material-symbols-outlined m3-expressive-icon">auto_awesome</span>
            <span class="m3-expressive-title">Intelligence Summary</span>
        </div>
        <span class="material-symbols-outlined expand-icon">unfold_more</span>
    </div>

    <div class="ai-summary-collapsible">
        <div class="ai-summary-inner">
            <div class="ai-summary-content">
                <p class="ai-summary-text"><?php echo esc_html( strip_tags( $summary ) ); ?></p>
            </div>

            <div class="ai-summary-footer">
                <div class="ai-summary-credit">
                    <span class="m3-credit-text">by Gemini</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const init = () => {
        const summary = document.getElementById('m3-ai-summary');
        const toggle = document.getElementById('ai-summary-toggle');
        if (!summary || !toggle || summary.dataset.initialized) return;

        toggle.addEventListener('click', (e) => {
            e.preventDefault();
            summary.classList.toggle('is-collapsed');
            const icon = toggle.querySelector('.expand-icon');
            if (icon) {
                icon.textContent = summary.classList.contains('is-collapsed') ? 'unfold_more' : 'unfold_less';
            }
        });
        summary.dataset.initialized = "true";
    };
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
</script>
<?php endif; ?>
