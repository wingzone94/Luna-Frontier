<?php

declare(strict_types=1);

/**
 * Featured article slideshow — lives under the existing header, never inside it.
 *
 * @package Luminous_Core
 */

if ( ! function_exists( 'lf_hero_slider_get_slides' ) ) {
	return;
}

$slides   = lf_hero_slider_get_slides();
$settings = lf_hero_slider_get_settings();

if ( empty( $slides ) ) {
	return;
}

$total      = count( $slides );
$slider_id  = wp_unique_id( 'lf-hero-slider-' );
$fallback   = function_exists( 'get_theme_file_uri' )
	? get_theme_file_uri( 'assets/images/luminous-core-card-image.png' )
	: '';
?>
<section
	id="<?php echo esc_attr( $slider_id ); ?>"
	class="lf-hero-slider"
	aria-roledescription="carousel"
	aria-labelledby="<?php echo esc_attr( $slider_id ); ?>-title"
	data-lf-hero-slider
	data-autoplay="<?php echo $settings['autoplay'] ? 'true' : 'false'; ?>"
	data-interval="<?php echo esc_attr( (string) $settings['interval'] ); ?>"
>
	<h2 id="<?php echo esc_attr( $slider_id ); ?>-title" class="lf-hero-sr-only">注目記事</h2>

	<div class="lf-hero-slider__stage">
		<div
			class="lf-hero-slider__viewport"
			data-lf-viewport
			tabindex="0"
			role="group"
			aria-label="注目記事（左右キーまたはスワイプで切り替え）"
		>
			<ul class="lf-hero-slider__track" data-lf-track>
				<?php foreach ( $slides as $index => $slide ) : ?>
					<?php
					$is_current = 0 === $index;
					$slide_id   = $slider_id . '-slide-' . $index;
					?>
					<li
						class="lf-hero-slide<?php echo $slide['has_image'] ? '' : ' lf-hero-slide--no-image'; ?><?php echo $is_current ? ' lf-hero-is-current' : ''; ?>"
						id="<?php echo esc_attr( $slide_id ); ?>"
						data-lf-slide
						data-index="<?php echo esc_attr( (string) $index ); ?>"
					>
						<article class="lf-hero-slide__card">
							<div class="lf-hero-slide__media" aria-hidden="true">
								<?php if ( $slide['has_image'] && $slide['image_html'] ) : ?>
									<?php echo $slide['image_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php elseif ( $fallback ) : ?>
									<img class="lf-hero-slide__image lf-hero-slide__image--fallback" src="<?php echo esc_url( $fallback ); ?>" alt="" loading="<?php echo $is_current ? 'eager' : 'lazy'; ?>">
								<?php else : ?>
									<div class="lf-hero-slide__fallback"></div>
								<?php endif; ?>
							</div>

							<div class="lf-hero-slide__overlay">
								<div class="lf-hero-slide__copy">
									<?php if ( $slide['category_html'] ) : ?>
										<?php echo $slide['category_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php endif; ?>

									<h3 class="lf-hero-slide__title">
										<a class="lf-hero-slide__title-link" href="<?php echo esc_url( $slide['url'] ); ?>">
											<?php echo esc_html( $slide['title'] ); ?>
										</a>
									</h3>


									<div class="lf-hero-slide__meta">
										<time class="lf-hero-slide__date" datetime="<?php echo esc_attr( $slide['datetime'] ); ?>">
											<?php echo esc_html( $slide['date'] ); ?>
										</time>
									</div>

									<a class="lf-hero-slide__cta" href="<?php echo esc_url( $slide['url'] ); ?>">
										記事を読む
										<span class="lf-hero-icon" aria-hidden="true">→</span>
									</a>
								</div>
							</div>
						</article>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="lf-hero-slider__controls" data-lf-controls hidden>
			<button
				type="button"
				class="lf-hero-slider__nav lf-hero-slider__nav--prev"
				data-lf-prev
				aria-controls="<?php echo esc_attr( $slider_id ); ?>-slide-0"
				aria-label="前の注目記事"
			>
				<span class="lf-hero-icon" aria-hidden="true">‹</span>
			</button>
			<button
				type="button"
				class="lf-hero-slider__nav lf-hero-slider__nav--next"
				data-lf-next
				aria-controls="<?php echo esc_attr( $slider_id ); ?>-slide-1"
				aria-label="次の注目記事"
			>
				<span class="lf-hero-icon" aria-hidden="true">›</span>
			</button>
		</div>

		<div class="lf-hero-slider__pagination" data-lf-pagination hidden role="group" aria-label="スライド位置">
			<?php foreach ( $slides as $index => $slide ) : ?>
				<button
					type="button"
					class="lf-hero-slider__dot<?php echo 0 === $index ? ' lf-hero-is-active' : ''; ?>"
					data-lf-dot
					data-index="<?php echo esc_attr( (string) $index ); ?>"
					aria-pressed="<?php echo 0 === $index ? 'true' : 'false'; ?>"
					aria-controls="<?php echo esc_attr( $slider_id . '-slide-' . $index ); ?>"
					aria-label="<?php echo esc_attr( sprintf( 'スライド %d: %s', $index + 1, $slide['title'] ) ); ?>"
				></button>
			<?php endforeach; ?>
			<span class="lf-hero-slider__counter" aria-live="polite" aria-atomic="true" data-lf-live>1 / <?php echo esc_html( (string) $total ); ?></span>
			<button type="button" class="lf-hero-slider__playback" data-lf-playback hidden>一時停止</button>
		</div>
	</div>

	<div class="lf-hero-slider__thumbs" data-lf-thumbs hidden role="group" aria-label="注目記事のサムネイル">
		<?php foreach ( $slides as $index => $slide ) : ?>
			<button
				type="button"
				class="lf-hero-thumb<?php echo 0 === $index ? ' lf-hero-is-active' : ''; ?>"
				data-lf-thumb
				data-index="<?php echo esc_attr( (string) $index ); ?>"
				aria-pressed="<?php echo 0 === $index ? 'true' : 'false'; ?>"
				aria-controls="<?php echo esc_attr( $slider_id . '-slide-' . $index ); ?>"
				aria-label="<?php echo esc_attr( $slide['title'] ); ?>"
			>
				<span class="lf-hero-thumb__media" aria-hidden="true">
					<?php if ( $slide['image_url'] ) : ?>
						<img src="<?php echo esc_url( $slide['image_url'] ); ?>" alt="" loading="lazy">
					<?php elseif ( $fallback ) : ?>
						<img src="<?php echo esc_url( $fallback ); ?>" alt="" loading="lazy">
					<?php endif; ?>
				</span>
				<span class="lf-hero-thumb__label"><?php echo esc_html( $slide['title'] ); ?></span>
			</button>
		<?php endforeach; ?>
	</div>
</section>
