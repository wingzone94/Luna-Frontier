<?php

declare(strict_types=1);
/**
 * 簡略化された記事カード (HEADLINES用)
 */
$post_id = get_the_ID();
?>
<a href="<?php the_permalink(); ?>" class="m3-headline-card m3-ripple-host">
    <div class="m3-headline-card__meta">
        <span class="m3-headline-card__date"><?php echo get_the_date('m.d'); ?></span>
        <?php
        $categories = function_exists( 'node_get_post_categories_for_display' )
            ? node_get_post_categories_for_display( $post_id )
            : get_the_category( $post_id );
        if (!empty($categories)) :
            $cat = $categories[0];
            echo node_render_category_label(
                $cat,
                array(
                    'tag'   => 'span',
                    'class' => 'm3-headline-card__category',
                    'href'  => false,
                )
            );
        endif;
        ?>
    </div>
    <h3 class="m3-headline-card__title"><?php the_title(); ?></h3>
    <div class="m3-headline-card__icon">
        <span class="material-symbols-outlined" data-icon="chevron_right"></span>
    </div>
</a>
