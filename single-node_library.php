<?php

declare(strict_types=1);
/**
 * Single template for Node Library items.
 *
 * @package Node
 */

get_header();
?>

<main id="primary" class="site-main node-library-single">
	<?php node_the_breadcrumbs(); ?>

	<?php while ( have_posts() ) : ?>
		<?php
			the_post();
			$library_id      = get_the_ID();
			$library_type    = get_post_meta( $library_id, '_node_library_type', true ) === 'app' ? 'app' : 'game';
			$linked_articles_title = 'app' === $library_type ? __( 'このアプリに関する記事', 'node' ) : __( 'このゲームに関する記事', 'node' );
			$library_summary = (string) get_post_meta( $library_id, '_node_library_summary', true );
		$library_links   = get_post_meta( $library_id, '_node_library_links', true );
		$library_links   = is_array( $library_links ) ? $library_links : array();
		$cero_rating     = 'game' === $library_type && function_exists( 'node_library_cero_rating_label' ) ? node_library_cero_rating_label( $library_id ) : '';
		$library_url     = get_post_type_archive_link( 'node_library' );
		$linked_article_ids = is_callable( array( 'Node_Library_Single_Extensions', 'find_linked_article_ids' ) )
			? Node_Library_Single_Extensions::find_linked_article_ids( array( (int) $library_id ), 12 )
			: array();
		$linked_articles = $linked_article_ids
			? new WP_Query(
				array(
					'post_type'           => 'post',
					'post_status'         => 'publish',
					'post__in'            => $linked_article_ids,
					'posts_per_page'      => count( $linked_article_ids ),
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
					'orderby'             => 'post__in',
				)
			)
			: new WP_Query( array( 'post__in' => array( 0 ) ) );
		if ( $linked_article_ids ) {
			update_post_thumbnail_cache( $linked_articles );
		}
		$game_info = array(
			'title'        => get_the_title(),
			'type'         => $library_type,
			'summary'      => $library_summary,
			'links'        => $library_links,
			'release_date' => trim( (string) get_post_meta( $library_id, '_node_library_release_date', true ) ),
		);
		if ( is_callable( array( 'Node_Library_Single_Extensions', 'store_offers' ) ) ) {
			$game_info['links'] = Node_Library_Single_Extensions::store_offers( $library_id, $library_links );
			$badge_items = is_callable( array( 'Node_Library_Subscriptions', 'get_display_badges' ) )
				? Node_Library_Subscriptions::get_display_badges( (int) $library_id )
				: array();
			if ( $badge_items ) {
				$game_info['subscription_badge_items'] = $badge_items;
				$game_info['subscription_badges']      = array_map( static fn( array $b ): string => $b['badge_text'], $badge_items );
			} else {
				$flags = (array) get_post_meta( $library_id, '_node_library_subscriptions', true );
				$game_info['subscription_badges'] = array_intersect_key( Node_Library_Structured_Meta::subscriptions(), array_filter( $flags ) );
			}
		}
		$linked_article_posts = array_values(
			array_filter(
				$linked_articles->posts,
				static function ( $linked_article ) {
					return $linked_article instanceof WP_Post && '' !== trim( $linked_article->post_title );
				}
			)
		);
		$visible_linked_article_posts = array_slice( $linked_article_posts, 0, 6 );
		$more_linked_article_posts    = array_slice( $linked_article_posts, 6 );
		?>

		<article <?php post_class( 'node-library-single__article' ); ?>>
			<?php
			if ( function_exists( 'node_library_render_product_jsonld' ) ) {
				node_library_render_product_jsonld( (int) $library_id );
			}
			?>
			<header class="node-library-single__hero">
				<?php get_template_part( 'template-parts/library/visual', null, array( 'id' => $library_id, 'size' => 'large', 'eager' => true ) ); ?>
				<div class="library-hero-copy">
					<?php get_template_part( 'template-parts/library/category' ); ?>
					<?php if ( '' !== $cero_rating ) : ?>
						<span class="node-library-single__type node-library-single__rating">
							<?php
							/* translators: %s: CERO rating and age classification. */
							echo esc_html( sprintf( __( 'レーティング：%s', 'node' ), $cero_rating ) );
							?>
						</span>
					<?php endif; ?>
					<h1><?php the_title(); ?></h1>
					<?php if ( function_exists( 'node_library_spec_hardware_labels' ) ) : ?>
						<div class="library-chips">
							<?php foreach ( node_library_spec_hardware_labels( $library_id ) as $hardware ) : ?>
								<span class="library-chip"><?php echo esc_html( $hardware ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<?php if ( $library_summary ) : ?><p><?php echo esc_html( $library_summary ); ?></p><?php endif; ?>
				</div>
				<div class="library-dates">
					<span><?php esc_html_e( '公開日', 'node' ); ?> <time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>"><?php echo esc_html( get_the_date( 'Y.m.d' ) ); ?></time></span>
					<span><?php esc_html_e( '更新日', 'node' ); ?> <time datetime="<?php echo esc_attr( get_the_modified_date( DATE_W3C ) ); ?>"><?php echo esc_html( get_the_modified_date( 'Y.m.d' ) ); ?></time></span>
				</div>
			</header>

			<div class="library-detail-body">
				<section class="node-library-single__stores" aria-labelledby="node-library-stores-title">
					<?php do_action( 'node_library_single_before_stores', (int) $library_id ); ?>
					<div class="node-library-single__section-heading">
						<span class="material-symbols-outlined" data-icon="shopping_bag" aria-hidden="true"></span>
						<h2 id="node-library-stores-title"><?php esc_html_e( '価格・入手先', 'node' ); ?></h2>
					</div>
					<?php if ( defined( 'NODE_LIBRARY_DIR' ) ) : ?>
						<?php include NODE_LIBRARY_DIR . 'templates/card-library.php'; ?>
					<?php else : ?>
						<p><?php esc_html_e( 'ストア情報を表示できません。', 'node' ); ?></p>
					<?php endif; ?>
				</section>
				<div class="library-detail-editorial">
					<?php do_action( 'node_library_single_after_specs', (int) $library_id, true ); ?>

					<?php do_action( 'node_library_single_after_stores', (int) $library_id ); ?>

					<?php if ( ! empty( $linked_article_posts ) ) : ?>
						<section class="node-library-single__articles" aria-labelledby="node-library-articles-title">
							<header class="node-library-single__section-heading">
								<span class="m3-date-group__icon" aria-hidden="true"><span class="material-symbols-outlined" data-icon="article"></span></span>
									<h2 class="m3-date-group__title" id="node-library-articles-title"><?php echo esc_html( $linked_articles_title ); ?></h2>
								<span class="m3-date-group__count"><?php echo esc_html( number_format_i18n( count( $linked_article_posts ) ) ); ?><?php esc_html_e( '件', 'node' ); ?></span>
							</header>
							<div class="node-library-single__article-list">
								<div class="library-stories">
								<?php foreach ( $visible_linked_article_posts as $linked_article ) : ?>
									<?php get_template_part( 'template-parts/library/story', null, array( 'post' => $linked_article ) ); ?>
									<?php endforeach; ?>
									</div>
									<?php if ( ! empty( $more_linked_article_posts ) ) : ?>
										<details class="node-library-single__more">
											<summary>
												<span class="node-library-single__more-label node-library-single__more-label--closed"><?php echo esc_html( sprintf( __( 'さらに%1$s件の記事を表示', 'node' ), number_format_i18n( count( $more_linked_article_posts ) ) ) ); ?></span>
												<span class="node-library-single__more-label node-library-single__more-label--open"><?php esc_html_e( '追加の記事を閉じる', 'node' ); ?></span>
												<span class="material-symbols-outlined node-library-single__more-icon" data-icon="expand_more" aria-hidden="true"></span>
											</summary>
											<div class="node-library-single__more-panel">
												<div class="library-stories">
												<?php foreach ( $more_linked_article_posts as $linked_article ) : ?>
													<?php get_template_part( 'template-parts/library/story', null, array( 'post' => $linked_article ) ); ?>
												<?php endforeach; ?>
												</div>
											</div>
										</details>
									<?php endif; ?>
								</div>
							</section>
					<?php endif; ?>
					<?php do_action( 'node_library_single_after_articles', (int) $library_id ); ?>

				</div>
			</div>

			<footer class="node-library-single__footer">
				<a href="<?php echo esc_url( $library_url ); ?>"><span class="material-symbols-outlined" data-icon="arrow_back" aria-hidden="true"></span><?php esc_html_e( 'Luna Library一覧へ戻る', 'node' ); ?></a>
			</footer>
		</article>
	<?php endwhile; ?>
</main>

<?php
get_footer();
