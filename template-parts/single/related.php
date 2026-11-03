<?php

declare(strict_types=1);
/**
 * Template part for displaying related posts in single.php
 */
$post_id = get_the_ID();
$categories = get_the_category($post_id);
$args = array(
    'post__not_in'   => array($post_id),
    'posts_per_page' => 4,
    'orderby'        => 'rand',
);

if ($categories) {
    $cat_ids = array();
    foreach($categories as $cat) $cat_ids[] = $cat->term_id;
    $args['category__in'] = $cat_ids;
}

$related_query = new WP_Query($args);

// Fallback to recent posts if no related posts found in same category
if (!$related_query->have_posts()) {
    unset($args['category__in']);
    $args['orderby'] = 'date';
    $related_query = new WP_Query($args);
}

if ($related_query->have_posts()) :
?>
<section class="m3-related-posts m3-related-posts--simple">
    <div class="m3-related-posts__inner">
        <h2 class="m3-related-posts__title">RELATED POSTS</h2>
        <div class="m3-related-grid">
            <?php while ($related_query->have_posts()) : $related_query->the_post(); ?>
                <a href="<?php the_permalink(); ?>" class="m3-related-card">
                    <?php if (has_post_thumbnail()) : ?>
                        <div class="m3-related-card__image">
                            <?php
                            $lf_rel_thumb_id  = (int) get_post_thumbnail_id();
                            $lf_rel_saved_alt = $lf_rel_thumb_id ? trim( (string) get_post_meta( $lf_rel_thumb_id, '_wp_attachment_image_alt', true ) ) : '';
                            the_post_thumbnail(
                                'medium_large',
                                array(
                                    'alt' => esc_attr( '' !== $lf_rel_saved_alt ? $lf_rel_saved_alt : get_the_title() ),
                                )
                            );
                            ?>
                        </div>
                    <?php else : ?>
                        <div class="m3-related-card__image m3-related-card__image--placeholder" style="display: flex; align-items: center; justify-content: center; background: var(--md-sys-color-surface-container-high); aspect-ratio: 4/3;">
                            <span class="material-symbols-outlined" style="font-size: 48px; color: var(--md-sys-color-outline-variant);" aria-hidden="true">image</span>
                        </div>
                    <?php endif; ?>
                    <div class="m3-related-card__content">
                        <h3 class="m3-related-card__title"><?php the_title(); ?></h3>
                    </div>
                </a>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>
    </div>
</section>
<?php endif; ?>
