<?php

declare(strict_types=1);
/**
 * Archive page hero header (Material 3 Expressive).
 *
 * @package Node
 *
 * @var array $args Template arguments.
 */

$context = function_exists( 'node_merge_archive_context' )
	? node_merge_archive_context( isset( $args ) ? $args : array() )
	: array(
		'title'        => '',
		'subtitle'     => __( 'アーカイブ', 'node' ),
		'description'  => '',
		'count'        => 0,
		'accent_color' => '',
		'header_id'    => 'archive-title',
		'type'         => 'archive',
	);

$header_id  = $context['header_id'];
$icon       = ! empty( $context['icon'] ) ? $context['icon'] : 'folder';
$mark_style = function_exists( 'node_get_archive_mark_style' )
	? node_get_archive_mark_style( $context )
	: '';
?>
<header
	class="m3-archive-header m3-archive-header--expressive m3-archive-header--<?php echo esc_attr( $context['type'] ); ?>"
	aria-labelledby="<?php echo esc_attr( $header_id ); ?>"
	<?php echo $mark_style ? 'style="' . esc_attr( $mark_style ) . '"' : ''; ?>
>
	<div class="m3-archive-header__glow" aria-hidden="true"></div>
	<div class="m3-archive-header__inner">
		<div class="m3-archive-header__hero">
			<div class="m3-archive-header__icon" aria-hidden="true">
				<span class="material-symbols-outlined" data-icon="<?php echo esc_attr( $icon ); ?>"></span>
			</div>
			<div class="m3-archive-header__copy">
				<p class="m3-archive-header__eyebrow">
					<?php echo esc_html( $context['subtitle'] ); ?>
				</p>
				<h1 id="<?php echo esc_attr( $header_id ); ?>" class="m3-archive-header__title">
					<?php echo esc_html( $context['title'] ); ?>
				</h1>
				<?php if ( ! empty( $context['count'] ) ) : ?>
					<div class="m3-archive-header__meta">
						<p class="m3-archive-header__count">
							<span class="material-symbols-outlined" data-icon="article" aria-hidden="true"></span>
							<?php
							printf(
								/* translators: %d: number of posts */
								esc_html( _n( '%d 件の記事', '%d 件の記事', (int) $context['count'], 'node' ) ),
								(int) $context['count']
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( ! empty( $context['description'] ) ) : ?>
			<div class="m3-archive-header__desc">
				<?php echo wp_kses_post( $context['description'] ); ?>
			</div>
		<?php endif; ?>
	</div>
</header>
