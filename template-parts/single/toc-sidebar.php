<?php

declare(strict_types=1);
/**
 * Template part for the sidebar-integrated Table of Contents (PC only)
 */
?>

<aside id="m3-toc-sidebar" class="m3-toc-sidebar" aria-labelledby="toc-sidebar-title">
    <div class="m3-toc-sidebar__inner m3-surface-container-low">
        <div class="m3-toc-sidebar__header">
            <span class="material-symbols-outlined m3-toc-sidebar__icon" data-icon="toc" aria-hidden="true"></span>
            <h2 id="toc-sidebar-title" class="m3-toc-sidebar__title">目次</h2>

            <button
                type="button"
                id="m3-toc-sidebar-toggle"
                class="m3-toc-sidebar__toggle"
                aria-expanded="true"
                aria-controls="m3-toc-sidebar-content"
                aria-label="<?php esc_attr_e( '目次を折りたたむ', 'node' ); ?>"
            >
                <span class="material-symbols-outlined" data-icon="expand_less" aria-hidden="true"></span>
            </button>
        </div>

        <nav id="m3-toc-sidebar-content" class="m3-toc-sidebar__nav" aria-label="ページ内目次">
            <div class="m3-toc-sidebar__placeholder" aria-hidden="true">
                <div class="m3-toc-sidebar__loading-line"></div>
                <div class="m3-toc-sidebar__loading-line" style="width: 80%;"></div>
                <div class="m3-toc-sidebar__loading-line" style="width: 60%;"></div>
            </div>
        </nav>

        <div id="m3-toc-sidebar-footer" class="m3-toc-sidebar__footer">
            <div class="m3-toc-sidebar__progress-track">
                <div id="m3-toc-progress-bar" class="m3-toc-sidebar__progress-bar"></div>
            </div>
        </div>
    </div>
</aside>
