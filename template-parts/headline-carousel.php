<?php

declare(strict_types=1);
/**
 * HEADLINE（ニュース）カルーセル
 *
 * 縦積みのリストだとトップページで縦幅を大きく占有し、直下の LATEST が
 * ファーストビューから押し出されるため、横スクロール1段に固定する。
 *
 * @package Node
 */

$headline_ids = function_exists( 'node_get_headline_post_ids' ) ? node_get_headline_post_ids( 5 ) : array();

if ( empty( $headline_ids ) ) {
	$news_cat      = get_term_by( 'name', 'ニュース', 'category' );
	$headline_args = array(
		// ニュースは最大5件（2026-08-01 ユーザー指示）。増やすと横スクロールが長くなり、
		// トップの回遊がニュースに偏る。
		'posts_per_page'      => 5,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	);
	if ( $news_cat ) {
		$headline_args['cat'] = $news_cat->term_id;
	}
} else {
	$headline_args = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => count( $headline_ids ),
		'post__in'            => $headline_ids,
		'orderby'             => 'post__in',
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	);
}

$headline_query = new WP_Query( $headline_args );

if ( ! $headline_query->have_posts() ) {
	wp_reset_postdata();
	return;
}

$headline_priority_assigned = false;
?>
<section class="c-headline-carousel m3-headlines m3-surface m3-section-spacing"
	role="region"
	aria-roledescription="カルーセル"
	aria-labelledby="headline-title"
	data-view="carousel"
	data-headline-carousel>
	<?php
	/*
	 * 保存済みの表示モードを描画前に反映する。DOMContentLoaded 後に切り替えると
	 * カード列からリストへ組み替わる分だけレイアウトシフトになるため。
	 * キー名は src/storage.js の STORE_KEY_PREFIX + 'headline-view' と対応。
	 */
	?>
	<script>
		(function () {
			try {
				var saved = JSON.parse(localStorage.getItem('m3_store_headline-view'));
				if (saved === 'list') document.currentScript.parentElement.dataset.view = 'list';
			} catch (e) {}
		})();
	</script>

	<div class="m3-headlines__header c-headline-carousel__header">
		<h2 id="headline-title" class="m3-headlines__title m3-section-title">
			<span class="material-symbols-outlined" aria-hidden="true">campaign</span>
			HEADLINE <span class="m3-section-title__sub">ニュース</span>
		</h2>

		<div class="c-headline-carousel__actions">
			<div class="c-headline-carousel__views" role="group" aria-label="ニュースの表示切替">
				<button type="button"
					class="c-headline-carousel__view is-active"
					data-headline-view="carousel"
					aria-pressed="true"
					aria-label="カード表示">
					<span class="material-symbols-outlined" aria-hidden="true">grid_view</span>
				</button>
				<button type="button"
					class="c-headline-carousel__view"
					data-headline-view="list"
					aria-pressed="false"
					aria-label="リスト表示">
					<span class="material-symbols-outlined" aria-hidden="true">reorder</span>
				</button>
			</div>
		</div>
	</div>

	<div class="c-headline-carousel__stage">
		<div class="c-headline-carousel__nav" data-headline-nav>
			<button type="button"
				class="c-headline-carousel__button c-headline-carousel__button--prev"
				data-headline-prev
				aria-label="前のニュースを表示"
				aria-disabled="true"
				disabled>
				<span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
			</button>
			<button type="button"
				class="c-headline-carousel__button c-headline-carousel__button--next"
				data-headline-next
				aria-label="次のニュースを表示">
				<span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
			</button>
		</div>

		<div class="c-headline-carousel__viewport"
			data-headline-viewport
			tabindex="0"
			role="group"
			aria-label="ニュース一覧（左右にスクロールできます）">
			<ul class="c-headline-carousel__track" role="list">
			<?php
			while ( $headline_query->have_posts() ) :
				$headline_query->the_post();

				$post_id   = get_the_ID();
				$has_image = has_post_thumbnail( $post_id );
				$is_lcp    = ! $headline_priority_assigned && $has_image;

				$thumb_id   = $has_image ? (int) get_post_thumbnail_id( $post_id ) : 0;
				$saved_alt  = $thumb_id ? trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ) : '';
				$thumb_attr = array(
					'alt'     => esc_attr( '' !== $saved_alt ? $saved_alt : get_the_title( $post_id ) ),
					'class'   => 'c-headline-card__image',
					'loading' => $is_lcp ? 'eager' : 'lazy',
				);
				if ( $is_lcp ) {
					$thumb_attr['fetchpriority'] = 'high';
					$headline_priority_assigned  = true;
				}

				$categories = function_exists( 'node_get_post_categories_for_display' )
					? node_get_post_categories_for_display( $post_id )
					: get_the_category( $post_id );
				?>
				<li class="c-headline-carousel__item<?php echo $has_image ? '' : ' c-headline-carousel__item--no-image'; ?>">
					<article <?php post_class( 'c-headline-card' . ( $has_image ? ' c-headline-card--has-image' : ' c-headline-card--no-image' ) ); ?>>
						<a class="c-headline-card__link m3-ripple-host" href="<?php the_permalink(); ?>">
							<?php if ( $has_image ) : ?>
								<div class="c-headline-card__visual" aria-hidden="true">
									<?php echo get_the_post_thumbnail( $post_id, 'medium_large', $thumb_attr ); ?>
								</div>
							<?php endif; ?>

							<div class="c-headline-card__body">
								<div class="c-headline-card__meta">
									<?php
									/*
									 * リスト表示は [バッジ][タイトル][日付][サムネ] の並びなので、
									 * バッジをここに残す（カード表示では CSS で隠す）。
									 * カード表示のバッジはタイトル下の __categories 側。
									 */
									if ( ! empty( $categories ) ) {
										echo node_render_category_label(
											$categories[0],
											array(
												'tag'   => 'span',
												'class' => 'c-headline-card__category c-headline-card__category--meta',
												'href'  => false,
											)
										);
									}
									?>
									<time class="c-headline-card__date" datetime="<?php echo esc_attr( get_the_date( DATE_W3C, $post_id ) ); ?>">
										<?php echo esc_html( get_the_date( 'Y.m.d', $post_id ) ); ?>
									</time>
								</div>

								<h3 class="c-headline-card__title"><?php the_title(); ?></h3>

								<?php
								/*
								 * カード表示のカテゴリはタイトルの下（2026-08-08 ユーザー指示）。
								 * 長い名前は2段で折り返し、そのぶん行数が増えるので、
								 * 2段に収まらないバッジは headline-carousel.js が隠す。
								 * PHP 側は上限4件までに絞ってから渡す。
								 */
								if ( ! empty( $categories ) ) :
									$headline_cat_limit = 4;
									// PHP 側で切った件数。JS が枠に入り切らない分を隠したら、その数を足して +N に反映する。
									$headline_cat_extra = max( 0, count( $categories ) - $headline_cat_limit );
									?>
									<div class="c-headline-card__categories" data-headline-categories>
										<?php
										foreach ( array_slice( $categories, 0, $headline_cat_limit ) as $headline_category ) {
											echo node_render_category_label(
												$headline_category,
												array(
													'tag'   => 'span',
													'class' => 'c-headline-card__category c-headline-card__category--tag',
													'href'  => false,
												)
											);
										}
										?>
										<span class="m3-label--category-more c-headline-card__category-more"
											data-headline-more
											data-extra="<?php echo (int) $headline_cat_extra; ?>"
											title="さらに <?php echo (int) $headline_cat_extra; ?> 件のカテゴリがあります"
											<?php echo $headline_cat_extra > 0 ? '' : 'hidden'; ?>>+<?php echo (int) $headline_cat_extra; ?></span>
									</div>
								<?php endif; ?>

								<div class="c-headline-card__author">
									<?php echo get_avatar( get_the_author_meta( 'ID' ), 20, '', '', array( 'class' => 'c-headline-card__avatar' ) ); ?>
									<span class="c-headline-card__author-name"><?php the_author(); ?></span>
								</div>
							</div>
						</a>
					</article>
				</li>
				<?php
			endwhile;
			wp_reset_postdata();
			?>
			</ul>
		</div>
	</div>
</section>
