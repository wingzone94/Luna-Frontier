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
$tone_color = '#FF9800'; // AIエリアをオレンジで統一
$keywords   = $args['keywords'] ?? [];

// 空なら何も出さない
if ( empty( $summary ) ) return;

// カード用（短い要約 + ネイティブ折りたたみ）は article-card.php で実装済みのため、
// ここでは主にシングル用（詳細ページ用）の演出を強化します。
if ( $mode === 'single' ) :
?>
<div class="ai-summary-single is-collapsed" id="m3-ai-summary" style="--ai-vibe-color: <?php echo esc_attr( $tone_color ); ?>;">
    <button type="button" class="ai-summary-header" id="ai-summary-toggle" aria-expanded="false" aria-controls="m3-ai-summary-panel">
        <div class="ai-summary-label">
            <span class="material-symbols-outlined m3-expressive-icon" aria-hidden="true">auto_awesome</span>
            <span class="m3-expressive-title">Intelligence Summary</span>
        </div>
        <span class="material-symbols-outlined expand-icon" aria-hidden="true">expand_more</span>
    </button>

    <div class="ai-summary-collapsible" id="m3-ai-summary-panel" aria-labelledby="ai-summary-toggle" aria-hidden="true">
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

        const panel = document.getElementById('m3-ai-summary-panel');
        const setExpanded = (expanded) => {
            summary.classList.toggle('is-collapsed', !expanded);
            toggle.setAttribute('aria-expanded', String(expanded));
            panel?.setAttribute('aria-hidden', String(!expanded));
            const icon = toggle.querySelector('.expand-icon');
            if (icon) {
                icon.textContent = expanded ? 'expand_less' : 'expand_more';
            }
        };

        toggle.addEventListener('click', (e) => {
            e.preventDefault();
            setExpanded(summary.classList.contains('is-collapsed'));
        });

        summary.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !summary.classList.contains('is-collapsed')) {
                e.preventDefault();
                setExpanded(false);
                toggle.focus();
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
