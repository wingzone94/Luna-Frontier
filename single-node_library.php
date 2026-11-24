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
			'title'        => get_the_title(),
			'type'         => $library_type,
			'summary'      => $library_summary,
			'links'        => $library_links,
			'release_date' => trim( (string) get_post_meta( $library_id, '_node_library_release_date', true ) ),
		);
		if ( is_callable( array( 'Node_Library_Single_Extensions', 'store_offers' ) ) ) {
			$game_info['links'] = Node_Library_Single_Extensions::store_offers( $library_id, $library_links );
			$flags = (array) get_post_meta( $library_id, '_node_library_subscriptions', true );
			$game_info['subscription_badges'] = array_intersect_key( Node_Library_Structured_Meta::subscriptions(), array_filter( $flags ) );
		}
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
			<?php
			if ( function_exists( 'node_library_render_product_jsonld' ) ) {
				node_library_render_product_jsonld( (int) $library_id );
			}
			?>
			<header class="node-library-single__hero">
				<div class="node-library-single__hero-grid">
					<?php
					if ( function_exists( 'node_library_render_visual' ) ) {
						node_library_render_visual( (int) $library_id, $library_type, 'node-library-single__media' );
					}
					$hardware_chips = function_exists( 'node_library_get_hardware_chips' )
						? node_library_get_hardware_chips( (int) $library_id )
						: array();
					$published_iso = get_the_date( 'c', $library_id );
					$modified_info = function_exists( 'node_get_post_modified_display' )
						? node_get_post_modified_display( $library_id )
						: null;
					?>
					<div class="node-library-single__hero-body">
						<div class="node-library-single__type node-library-single__type--<?php echo esc_attr( $library_type ); ?>">
							<span class="material-symbols-outlined" data-icon="<?php echo 'app' === $library_type ? 'smartphone' : 'sports_esports'; ?>" aria-hidden="true"></span>
							<?php echo esc_html( 'app' === $library_type ? __( 'アプリ', 'node' ) : __( 'ゲーム', 'node' ) ); ?>
						</div>
						<h1><?php the_title(); ?></h1>
						<?php if ( ! empty( $hardware_chips ) ) : ?>
							<ul class="node-library-single__platforms" aria-label="<?php esc_attr_e( '対応機種', 'node' ); ?>">
								<?php foreach ( $hardware_chips as $hardware_label ) : ?>
									<li class="node-library-single__platform-chip"><?php echo esc_html( $hardware_label ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php if ( '' !== trim( $library_summary ) ) : ?>
							<p class="node-library-single__summary"><?php echo esc_html( $library_summary ); ?></p>
						<?php endif; ?>
						<div class="lf-article-header__meta node-library-single__dates">
							<time class="lf-meta__item" datetime="<?php echo esc_attr( $published_iso ); ?>">
								<span class="material-symbols-outlined lf-meta__icon" data-icon="calendar_today" aria-hidden="true"></span>
								<span><?php esc_html_e( '公開日', 'node' ); ?>: <?php echo esc_html( get_the_date( '', $library_id ) ); ?></span>
							</time>
							<?php if ( is_array( $modified_info ) && ! empty( $modified_info['display'] ) ) : ?>
								<time class="lf-meta__item" datetime="<?php echo esc_attr( (string) $modified_info['datetime'] ); ?>">
									<span class="material-symbols-outlined lf-meta__icon" data-icon="update" aria-hidden="true"></span>
									<span><?php esc_html_e( '更新日', 'node' ); ?>: <?php echo esc_html( (string) $modified_info['display'] ); ?></span>
								</time>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</header>

			<section class="node-library-single__stores node-library-single__section" aria-labelledby="node-library-stores-title">
				<div class="node-library-single__section-heading">
					<span class="material-symbols-outlined" data-icon="shopping_bag" aria-hidden="true"></span>
					<h2 id="node-library-stores-title"><?php esc_html_e( '入手先', 'node' ); ?></h2>
				</div>
				<?php if ( defined( 'NODE_LIBRARY_DIR' ) ) : ?>
					<?php include NODE_LIBRARY_DIR . 'templates/card-library.php'; ?>
				<?php else : ?>
					<p><?php esc_html_e( 'ストア情報を表示できません。', 'node' ); ?></p>
				<?php endif; ?>
			</section>
			<?php do_action( 'node_library_single_after_specs', (int) $library_id ); ?>

			<?php do_action( 'node_library_single_after_stores', (int) $library_id ); ?>

			<?php if ( ! empty( $linked_article_posts ) ) : ?>
				<section class="node-library-single__articles node-library-single__section" aria-labelledby="node-library-articles-title">
					<div class="node-library-single__section-heading">
						<span class="material-symbols-outlined" data-icon="article" aria-hidden="true"></span>
						<h2 id="node-library-articles-title"><?php echo esc_html( $linked_articles_title ); ?></h2>
						<span class="node-library-single__section-count"><?php echo esc_html( sprintf( __( '%s件', 'node' ), number_format_i18n( count( $linked_article_posts ) ) ) ); ?></span>
					</div>
					<div class="node-library-archive__grid node-library-single__card-grid">
						<?php foreach ( $visible_linked_article_posts as $linked_article ) : ?>
							<?php
							if ( function_exists( 'node_library_render_article_card' ) ) {
								node_library_render_article_card( $linked_article, 'h3' );
							}
							?>
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
								<div class="node-library-archive__grid node-library-single__card-grid">
									<?php foreach ( $more_linked_article_posts as $linked_article ) : ?>
										<?php
										if ( function_exists( 'node_library_render_article_card' ) ) {
											node_library_render_article_card( $linked_article, 'h3' );
										}
										?>
									<?php endforeach; ?>
								</div>
							</div>
						</details>
					<?php endif; ?>
				</section>
			<?php endif; ?>
			<?php do_action( 'node_library_single_after_articles', (int) $library_id ); ?>

			<footer class="node-library-single__footer">
				<a href="<?php echo esc_url( $library_url ); ?>"><span class="material-symbols-outlined" data-icon="arrow_back" aria-hidden="true"></span><?php esc_html_e( 'Luna Library一覧へ戻る', 'node' ); ?></a>
			</footer>
		</article>
	<?php endwhile; ?>
</main>

<?php
get_footer();
