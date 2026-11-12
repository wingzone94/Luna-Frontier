<?php
declare(strict_types=1);
?>
<div class="m3-action-stack <?php echo is_singular() ? 'is-singular' : ''; ?>">
    <!-- 1. Back to Top -->
    <button id="m3-back-to-top" class="m3-fab m3-fab--extended m3-fab--mobile-hidden" aria-label="ページ上部へ戻る">
        <span class="material-symbols-outlined" aria-hidden="true">arrow_upward</span>
        <span class="m3-fab-text">TOP</span>
        <span class="m3-fab-label-top">最上部へ戻る</span>
    </button>

    <?php if ( is_singular() ) : ?>
    <!-- 2. TOC Trigger -->
    <button id="m3-toc-trigger" class="m3-fab m3-fab--extended m3-fab--mobile-hidden toc-ready">
        <span class="material-symbols-outlined">list</span>
        <span class="m3-fab-text">目次</span>
        <span class="m3-fab-label-top">目次を表示</span>
    </button>
    <?php endif; ?>
    <!-- Mobile-only pull tab, positioned above the TOC action -->
    <button type="button" id="m3-mobile-actions-toggle" class="m3-mobile-actions-toggle" aria-expanded="true" aria-controls="m3-back-to-top m3-toc-trigger" aria-label="追従ボタンを折りたたむ" hidden>
        <span class="material-symbols-outlined" aria-hidden="true">keyboard_arrow_up</span>
    </button>
</div>
