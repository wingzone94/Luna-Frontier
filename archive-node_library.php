<?php

declare(strict_types=1);
/**
 * Archive template for Node Library.
 *
 * @package Node
 */

get_header();

$selected_type = sanitize_key( (string) get_query_var( 'node_library_type' ) );
if ( ! $selected_type && isset( $_GET['type'] ) ) {
	$selected_type = sanitize_key( wp_unslash( $_GET['type'] ) );
}
$selected_type = in_array( $selected_type, array( 'game', 'app' ), true ) ? $selected_type : '';
$filter_items  = array(
	''     => __( 'すべて', 'node' ),
	'game' => __( 'ゲーム', 'node' ),
	'app'  => __( 'アプリ', 'node' ),
);
$archive_url   = get_post_type_archive_link( 'node_library' );
$library_toolbar_state = function_exists( 'node_library_archive_toolbar_state' )
	? node_library_archive_toolbar_state()
	: array(
		'q'    => '',
		'tab'  => '',
		'sort' => '',
		'dir'  => '',
	);
$archive_titles = array(
	''     => __( 'Luna Library', 'node' ),
	'game' => __( 'ゲームライブラリ', 'node' ),
	'app'  => __( 'アプリライブラリ', 'node' ),
);
$archive_descriptions = array(
	''     => __( 'Luminous Coreで紹介している作品を、対応ストアとともにまとめています。', 'node' ),
	'game' => __( 'Luminous Coreで紹介しているゲームを、対応ストアとともにまとめています。', 'node' ),
	'app'  => __( 'Luminous Coreで紹介しているアプリを、対応ストアとともにまとめています。', 'node' ),
);
?>

<main id="primary" class="site-main node-library-archive">
	<?php node_the_breadcrumbs(); ?>

	<section class="node-library-archive__showcase" aria-labelledby="node-library-title">
		<div class="node-library-archive__heading">
			<div>
				<h1 id="node-library-title"><?php echo esc_html( $archive_titles[ $selected_type ] ); ?></h1>
				<p><?php echo esc_html( $archive_descriptions[ $selected_type ] ); ?></p>
			</div>
		</div>
		<div class="library-toolbar">
			<p class="library-count" role="status"><strong><?php echo esc_html( number_format_i18n( (int) $wp_query->found_posts ) ); ?></strong> <?php esc_html_e( '件のタイトル', 'node' ); ?></p>

			<nav class="node-library-filter" aria-label="<?php esc_attr_e( 'ライブラリの種類で絞り込む', 'node' ); ?>">
				<?php foreach ( $filter_items as $filter_value => $filter_label ) : ?>
					<?php
					$filter_url = '' === $filter_value
						? $archive_url
						: trailingslashit( $archive_url . $filter_value );
					if ( is_string( $filter_url ) && function_exists( 'node_library_archive_with_toolbar_args' ) ) {
						$filter_url = node_library_archive_with_toolbar_args( $filter_url );
					}
					?>
					<a class="node-library-filter__item<?php echo $selected_type === $filter_value ? ' is-current' : ''; ?>" href="<?php echo esc_url( $filter_url ); ?>"<?php echo $selected_type === $filter_value ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $filter_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
		if ( function_exists( 'node_library_render_archive_toolbar' ) ) {
			node_library_render_archive_toolbar();
		}
		?>
	</section>

	<section class="node-library-archive__collection" aria-label="<?php esc_attr_e( 'ライブラリ一覧', 'node' ); ?>">
		<?php if ( have_posts() ) : ?>
			<div class="node-library-archive__grid">
				<?php while ( have_posts() ) : ?>
					<?php the_post(); ?>
					<?php get_template_part( 'template-parts/library/shelf-card' ); ?>
				<?php endwhile; ?>
			</div>
		<?php else : ?>
			<div class="node-library-empty-state">
				<span class="material-symbols-outlined" data-icon="inventory_2" aria-hidden="true"></span>
				<p><?php esc_html_e( '現在、該当するライブラリ項目はありません。', 'node' ); ?></p>
				<?php if ( $selected_type || '' !== $library_toolbar_state['q'] || '' !== $library_toolbar_state['tab'] ) : ?><a href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( 'すべての作品を見る', 'node' ); ?></a><?php endif; ?>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( $wp_query->max_num_pages > 1 ) : ?>
		<?php
		$pagination_current  = max( 1, (int) get_query_var( 'paged' ) );
		$pagination_total    = (int) $wp_query->max_num_pages;
			$pagination_base     = $selected_type
				? trailingslashit( $archive_url . $selected_type ) . 'page/%#%/'
				: str_replace( '999999999', '%#%', esc_url_raw( get_pagenum_link( 999999999 ) ) );
			$pagination_add_args = function_exists( 'node_library_archive_toolbar_query_args' )
				? node_library_archive_toolbar_query_args()
				: array();
			?>
			<nav class="node-library-pagination" aria-label="<?php esc_attr_e( 'ページ送り', 'node' ); ?>">
				<div class="node-library-pagination__navigation">
				<div class="node-library-pagination__summary">
					<span><?php echo esc_html( sprintf( __( '%1$s件中 %2$s / %3$s ページ', 'node' ), number_format_i18n( (int) $wp_query->found_posts ), number_format_i18n( $pagination_current ), number_format_i18n( $pagination_total ) ) ); ?></span>
				</div>
				<div class="node-library-pagination__links">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => $pagination_base,
								'format'    => '?paged=%#%',
								'current'   => $pagination_current,
								'total'     => $pagination_total,
								'type'      => 'list',
								'add_args'  => $pagination_add_args ? $pagination_add_args : false,
								'prev_text' => '<span class="screen-reader-text">' . esc_html__( '前へ', 'node' ) . '</span><span class="material-symbols-outlined" data-icon="chevron_left" aria-hidden="true"></span>',
								'next_text' => '<span class="screen-reader-text">' . esc_html__( '次へ', 'node' ) . '</span><span class="material-symbols-outlined" data-icon="chevron_right" aria-hidden="true"></span>',
							)
						)
					);
					?>
					</div>
				</div>
			</nav>
	<?php endif; ?>
</main>

<?php
get_footer();
