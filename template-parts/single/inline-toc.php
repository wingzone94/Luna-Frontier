<?php
declare(strict_types=1);

/**
 * Template part for the inline Table of Contents container in single.php
 * JS populates content into this container if headings are present.
 *
 * @package Node
 */
?>
<!-- 記事内目次コンテナ (JSでここに目次が挿入されます) -->
<div id="m3-inline-toc" class="m3-inline-toc" style="display: none;">
    <div class="m3-inline-toc__header">
        <span class="material-symbols-outlined" aria-hidden="true">toc</span> <?php esc_html_e( '目次', 'node' ); ?>
    </div>
    <nav id="m3-inline-toc-content" class="m3-inline-toc__content" aria-label="<?php esc_attr_e( '記事内目次', 'node' ); ?>"></nav>
</div>
