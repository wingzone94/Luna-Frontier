<?php

declare(strict_types=1);
/**
 * Template part for displaying the floating Table of Contents in single.php
 */
?>
<?php
$node_article_toc_items = function_exists( 'node_get_article_toc_export_items' )
    ? node_get_article_toc_export_items( get_the_ID() )
    : array();
?>
<script type="application/json" id="m3-article-toc-data">
<?php echo wp_json_encode( $node_article_toc_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>
</script>
<?php if ( function_exists( 'node_is_native_app' ) && node_is_native_app() ) { return; } ?>
<div id="m3-sticky-toc" class="m3-sticky-toc" aria-hidden="true">
    <div class="m3-sticky-toc__header">
        <span class="material-symbols-outlined m3-toc-icon" aria-hidden="true">toc</span>
        <span class="m3-sticky-toc__title">目次</span>
        <button type="button" id="m3-toc-close" class="m3-toc-close-btn" aria-label="目次を閉じる">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
    </div>

    <nav id="m3-toc-container" class="m3-toc-body" aria-label="ページ内目次"></nav>
</div>
