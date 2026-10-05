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
		$library_url     = get_post_type_archive_link( 'node_library' );
		$linked_articles = new WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => 12,
				'ignore_sticky_posts' => true,
				'meta_query'          => array(
					'relation' => 'OR',
					array(
						'key'   => '_node_linked_library_id',
						'value' => (string) $library_id,
					),
					array(
						'key'   => '_node_library_card_reference',
						'value' => (string) $library_id,
					),
				),
				'orderby'             => 'date',
				'order'               => 'DESC',
			)
		);
		$game_info = array(
			'title'   => get_the_title(),
			'type'    => $library_type,
			'summary' => $library_summary,
			'links'   => $library_links,
		);
		$linked_article_posts = array_values(
			array_filter(
				$linked_articles->posts,
				static function ( $linked_article ) {
					return $linked_article instanceof WP_Post && '' !== trim( $linked_article->post_title );
				}
			)
		);
		$visible_linked_article_posts = array_slice( $linked_article_posts, 0, 5 );
		$more_linked_article_posts    = array_slice( $linked_article_posts, 5 );
		?>

		<article <?php post_class( 'node-library-single__article' ); ?>>
			<header class="node-library-single__hero">
				<div class="node-library-single__type node-library-single__type--<?php echo esc_attr( $library_type ); ?>">
					<span class="material-symbols-outlined" aria-hidden="true"><?php echo 'app' === $library_type ? 'smartphone' : 'sports_esports'; ?></span>
					<?php echo esc_html( 'app' === $library_type ? __( 'アプリ', 'node' ) : __( 'ゲーム', 'node' ) ); ?>
				</div>
				<h1><?php the_title(); ?></h1>
				<?php if ( $library_summary ) : ?><p><?php echo esc_html( $library_summary ); ?></p><?php endif; ?>
			</header>

			<section class="node-library-single__stores" aria-labelledby="node-library-stores-title">
				<div class="node-library-single__section-heading">
					<span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span>
					<h2 id="node-library-stores-title"><?php esc_html_e( '入手先', 'node' ); ?></h2>
				</div>
				<?php if ( defined( 'NODE_LIBRARY_DIR' ) ) : ?>
					<?php include NODE_LIBRARY_DIR . 'templates/card-library.php'; ?>
				<?php else : ?>
					<p><?php esc_html_e( 'ストア情報を表示できません。', 'node' ); ?></p>
				<?php endif; ?>
			</section>

			<?php if ( ! empty( $linked_article_posts ) ) : ?>
				<section class="node-library-single__articles" aria-labelledby="node-library-articles-title">
					<header class="m3-date-group__header">
						<span class="m3-date-group__icon" aria-hidden="true"><span class="material-symbols-outlined">article</span></span>
							<h2 class="m3-date-group__title" id="node-library-articles-title"><?php echo esc_html( $linked_articles_title ); ?></h2>
						<span class="m3-date-group__count"><?php echo esc_html( number_format_i18n( count( $linked_article_posts ) ) ); ?><?php esc_html_e( '件', 'node' ); ?></span>
					</header>
					<div class="node-library-single__article-list">
						<div class="m3-date-group__items">
						<?php foreach ( $visible_linked_article_posts as $linked_article ) : ?>
							<a class="m3-date-item" href="<?php echo esc_url( get_permalink( $linked_article ) ); ?>">
								<span class="m3-date-item__body"><span class="m3-date-item__title"><?php echo esc_html( get_the_title( $linked_article ) ); ?></span></span>
								<span class="material-symbols-outlined m3-date-item__chevron" aria-hidden="true">chevron_right</span>
							</a>
							<?php endforeach; ?>
							</div>
							<?php if ( ! empty( $more_linked_article_posts ) ) : ?>
								<details class="node-library-single__more">
									<summary>
										<span class="node-library-single__more-label node-library-single__more-label--closed"><?php echo esc_html( sprintf( __( 'さらに%1$s件の記事を表示', 'node' ), number_format_i18n( count( $more_linked_article_posts ) ) ) ); ?></span>
										<span class="node-library-single__more-label node-library-single__more-label--open"><?php esc_html_e( '追加の記事を閉じる', 'node' ); ?></span>
										<span class="material-symbols-outlined node-library-single__more-icon" aria-hidden="true">expand_more</span>
									</summary>
									<div class="node-library-single__more-panel">
										<div class="m3-date-group__items">
										<?php foreach ( $more_linked_article_posts as $linked_article ) : ?>
											<a class="m3-date-item" href="<?php echo esc_url( get_permalink( $linked_article ) ); ?>">
											<span class="m3-date-item__body"><span class="m3-date-item__title"><?php echo esc_html( get_the_title( $linked_article ) ); ?></span></span>
											<span class="material-symbols-outlined m3-date-item__chevron" aria-hidden="true">chevron_right</span>
											</a>
										<?php endforeach; ?>
										</div>
									</div>
								</details>
							<?php endif; ?>
						</div>
					</section>
			<?php endif; ?>

			<footer class="node-library-single__footer">
				<a href="<?php echo esc_url( $library_url ); ?>"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span><?php esc_html_e( 'Luna Library一覧へ戻る', 'node' ); ?></a>
			</footer>
		</article>
	<?php endwhile; ?>
</main>

<?php
get_footer();
