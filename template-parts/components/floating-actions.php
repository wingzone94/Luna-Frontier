<?php
declare(strict_types=1);
?>
<div id="m3-action-stack" class="m3-action-stack <?php echo is_singular() ? 'is-singular' : ''; ?>">
    <!-- 1. Back to Top -->
    <button type="button" aria-label="最上部へ戻る" id="m3-back-to-top" class="m3-fab m3-fab--extended m3-fab--mobile-hidden">
        <span class="material-symbols-outlined" aria-hidden="true">arrow_upward</span>
        <span class="m3-fab-text">TOP</span>
        <span class="m3-fab-label-top">最上部へ戻る</span>
    </button>

    <?php if ( is_singular() ) : ?>
    <!-- 2. TOC Trigger -->
    <button type="button" aria-label="目次を表示" id="m3-toc-trigger" class="m3-fab m3-fab--extended m3-fab--mobile-hidden toc-ready">
        <span class="material-symbols-outlined" aria-hidden="true">list</span>
        <span class="m3-fab-text">目次</span>
        <span class="m3-fab-label-top">目次を表示</span>
    </button>
    <?php endif; ?>
</div>
    <?php if ( is_singular() ) : ?>
    <!-- Mobile Action Pull Tab (Collapsible Toggle) -->
    <button type="button"
            id="m3-action-pull-tab"
            class="m3-action-pull-tab"
            aria-label="<?php esc_attr_e( '追従ボタンを折りたたむ', 'node' ); ?>"
            aria-expanded="true"
            aria-controls="m3-action-stack">
        <span class="m3-action-pull-tab__icon material-symbols-outlined" aria-hidden="true">chevron_right</span>
    </button>
    <?php endif; ?>
