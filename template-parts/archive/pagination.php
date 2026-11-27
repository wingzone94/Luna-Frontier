<?php

declare(strict_types=1);
/**
 * Archive pagination.
 *
 * @package Node
 */

global $wp_query;

if ( (int) $wp_query->max_num_pages <= 1 ) {
	return;
}

$pagination = get_the_posts_pagination(
	array(
		'mid_size'  => 2,
		'prev_text' => '<span class="material-symbols-outlined" data-icon="chevron_left" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( '前のページ', 'node' ) . '</span>',
		'next_text' => '<span class="material-symbols-outlined" data-icon="chevron_right" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( '次のページ', 'node' ) . '</span>',
	)
);

if ( '' === $pagination ) {
	return;
}
?>
<nav class="m3-navigation m3-archive-navigation" aria-label="<?php esc_attr_e( 'ページ送り', 'node' ); ?>">
	<?php echo $pagination; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core pagination markup. ?>
</nav>
