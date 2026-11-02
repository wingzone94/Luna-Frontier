<?php
declare(strict_types=1);
?>
<div class="m3-action-stack <?php echo is_singular() ? 'is-singular' : ''; ?>">
    <button type="button" id="m3-actions-toggle" class="m3-actions-toggle" aria-label="ページ操作を開く" aria-expanded="false" aria-controls="m3-actions-items" hidden>
        <span aria-hidden="true">＋</span>
    </button>
    <div id="m3-actions-items" class="m3-action-items">
    <!-- 1. Back to Top -->
    <button type="button" aria-label="最上部へ戻る" id="m3-back-to-top" class="m3-fab m3-fab--extended">
        <span class="material-symbols-outlined" aria-hidden="true">arrow_upward</span>
        <span class="m3-fab-text">TOP</span>
        <span class="m3-fab-label-top">最上部へ戻る</span>
    </button>

    <?php if ( is_singular() ) : ?>
    <!-- 2. TOC Trigger -->
    <button type="button" aria-label="目次を表示" id="m3-toc-trigger" class="m3-fab m3-fab--extended toc-ready">
        <span class="material-symbols-outlined" aria-hidden="true">list</span>
        <span class="m3-fab-text">目次</span>
        <span class="m3-fab-label-top">目次を表示</span>
    </button>
    <?php endif; ?>
    </div>
</div>
