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
$archive_titles = array(
	''     => __( 'ゲームとアプリのライブラリ', 'node' ),
	'game' => __( 'ゲームライブラリ', 'node' ),
	'app'  => __( 'アプリライブラリ', 'node' ),
);
$archive_descriptions = array(
	''     => __( 'Luminous Coreで紹介している作品を、対応ストアとともにまとめています。', 'node' ),
	'game' => __( 'Luminous Coreで紹介しているゲームを、対応ストアとともにまとめています。', 'node' ),
	'app'  => __( 'Luminous Coreで紹介しているアプリを、対応ストアとともにまとめています。', 'node' ),
);
$archive_library_ids     = array_map( 'absint', wp_list_pluck( $wp_query->posts, 'ID' ) );
$library_article_counts  = array_fill_keys( $archive_library_ids, 0 );

if ( ! empty( $archive_library_ids ) ) {
	$related_article_ids = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'meta_query'             => array(
				'relation' => 'OR',
				array(
					'key'     => '_node_linked_library_id',
					'value'   => $archive_library_ids,
					'compare' => 'IN',
				),
				array(
					'key'     => '_node_library_card_reference',
					'value'   => $archive_library_ids,
					'compare' => 'IN',
				),
			),
		)
	);

	foreach ( $related_article_ids as $related_article_id ) {
		$related_library_ids = array_merge(
			array( absint( get_post_meta( $related_article_id, '_node_linked_library_id', true ) ) ),
			array_map( 'absint', get_post_meta( $related_article_id, '_node_library_card_reference', false ) )
		);

		foreach ( array_unique( array_filter( $related_library_ids ) ) as $related_library_id ) {
			if ( isset( $library_article_counts[ $related_library_id ] ) ) {
				$library_article_counts[ $related_library_id ]++;
			}
		}
	}
}
?>

<main id="primary" class="site-main node-library-archive">
	<?php node_the_breadcrumbs(); ?>

	<section class="node-library-archive__showcase" aria-labelledby="node-library-title">
		<div class="node-library-archive__eyebrow">
			<span class="material-symbols-outlined" aria-hidden="true">grid_view</span>
			<?php esc_html_e( 'Luna Library', 'node' ); ?>
		</div>
		<div class="node-library-archive__heading">
			<div>
				<h1 id="node-library-title"><?php echo esc_html( $archive_titles[ $selected_type ] ); ?></h1>
				<p><?php echo esc_html( $archive_descriptions[ $selected_type ] ); ?></p>
			</div>
			<div class="node-library-archive__count" aria-label="<?php esc_attr_e( '表示件数', 'node' ); ?>">
				<span><?php echo esc_html( sprintf( __( '%s件', 'node' ), number_format_i18n( (int) $wp_query->found_posts ) ) ); ?></span>
			</div>
		</div>

		<nav class="node-library-filter" aria-label="<?php esc_attr_e( 'ライブラリの種類で絞り込む', 'node' ); ?>">
			<?php foreach ( $filter_items as $filter_value => $filter_label ) : ?>
				<?php
				$filter_url = '' === $filter_value
					? $archive_url
					: trailingslashit( $archive_url . $filter_value );
				?>
				<a class="node-library-filter__item<?php echo $selected_type === $filter_value ? ' is-current' : ''; ?>" href="<?php echo esc_url( $filter_url ); ?>"<?php echo $selected_type === $filter_value ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $filter_label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	</section>

	<section class="node-library-archive__collection" aria-label="<?php esc_attr_e( 'ライブラリ一覧', 'node' ); ?>">
		<?php if ( have_posts() ) : ?>
			<div class="node-library-archive__grid">
				<?php while ( have_posts() ) : ?>
					<?php
					the_post();
						$library_id    = get_the_ID();
						$library_type  = get_post_meta( $library_id, '_node_library_type', true ) === 'app' ? 'app' : 'game';
						$library_links = get_post_meta( $library_id, '_node_library_links', true );
						$platform_names      = array();
						$hardware_name_map   = array(
							'nintendo-switch'   => 'Nintendo Switch',
							'nintendo-switch-2' => 'Nintendo Switch 2',
							'playstation-4'     => 'PS4',
							'playstation-5'     => 'PS5',
							'playstation-crossgen' => 'PS4・PS5',
							'xbox-one'          => 'Xbox One',
							'xbox-series'       => 'Xbox Series X|S',
							'xbox-crossgen'     => 'Xbox One・Xbox Series X|S',
						);
						if ( is_array( $library_links ) ) {
							foreach ( $library_links as $library_link ) {
								if ( ! is_array( $library_link ) ) {
									continue;
								}

								$platform_name = trim( (string) ( $library_link['platform'] ?? '' ) );
								$hardware      = sanitize_key( (string) ( $library_link['hardware'] ?? '' ) );
								if ( isset( $hardware_name_map[ $hardware ] ) ) {
									$platform_name = $hardware_name_map[ $hardware ];
								} else {
									$platform_key = strtolower( preg_replace( '/[\\s\\/・|]/u', '', $platform_name ) );
									if ( false !== strpos( $platform_key, 'ps45' ) || ( false !== strpos( $platform_key, 'ps4' ) && false !== strpos( $platform_key, 'ps5' ) ) || ( false !== strpos( $platform_key, 'playstation4' ) && false !== strpos( $platform_key, 'playstation5' ) ) ) {
										$platform_name = 'PS4・PS5';
									} elseif ( false !== strpos( $platform_key, 'nintendoswitchnintendoswitch2' ) || false !== strpos( $platform_key, 'switchswitch2' ) ) {
										$platform_name = 'Nintendo Switch・Nintendo Switch 2';
									} elseif ( false !== strpos( $platform_key, 'switch2' ) ) {
										$platform_name = 'Nintendo Switch 2';
									} elseif ( false !== strpos( $platform_key, 'switch' ) ) {
										$platform_name = 'Nintendo Switch';
									} elseif ( false !== strpos( $platform_key, 'xboxone' ) && ( false !== strpos( $platform_key, 'xboxseries' ) || false !== strpos( $platform_key, 'seriesxs' ) ) ) {
										$platform_name = 'Xbox One・Xbox Series X|S';
									} elseif ( false !== strpos( $platform_key, 'xboxseries' ) || false !== strpos( $platform_key, 'seriesxs' ) ) {
										$platform_name = 'Xbox Series X|S';
									} elseif ( false !== strpos( $platform_key, 'xboxone' ) ) {
										$platform_name = 'Xbox One';
									}
								}

								if ( '' !== $platform_name && ! in_array( $platform_name, $platform_names, true ) ) {
									$platform_names[] = $platform_name;
								}
							}
						}
						$visible_platform_names = array_slice( $platform_names, 0, 3 );
						$hidden_platform_count  = max( 0, count( $platform_names ) - count( $visible_platform_names ) );
						$app_devices   = array();
						if ( 'app' === $library_type && is_array( $library_links ) ) {
							$device_map = array(
								'windows-pc'  => array( 'key' => 'windows', 'icon' => 'desktop_windows', 'label' => __( 'Windows', 'node' ) ),
								'mac'         => array( 'key' => 'mac', 'icon' => 'laptop_mac', 'label' => __( 'Mac', 'node' ) ),
								'iphone-ipad' => array( 'key' => 'mobile', 'icon' => 'smartphone', 'label' => __( 'iPhone・iPad', 'node' ) ),
								'android'     => array( 'key' => 'mobile', 'icon' => 'smartphone', 'label' => __( 'Android', 'node' ) ),
								'amazon-fire' => array( 'key' => 'mobile', 'icon' => 'smartphone', 'label' => __( 'Fire タブレット', 'node' ) ),
							);

							foreach ( $library_links as $library_link ) {
								if ( ! is_array( $library_link ) ) {
									continue;
								}

								$hardware = sanitize_key( (string) ( $library_link['hardware'] ?? '' ) );
								if ( ! isset( $device_map[ $hardware ] ) ) {
									$platform = strtolower( (string) ( $library_link['platform'] ?? '' ) );
									if ( false !== strpos( $platform, 'mac' ) ) {
										$hardware = 'mac';
									} elseif ( false !== strpos( $platform, 'windows' ) || ( false !== strpos( $platform, 'microsoft' ) && false === strpos( $platform, 'xbox' ) ) ) {
										$hardware = 'windows-pc';
									} elseif ( false !== strpos( $platform, 'android' ) || false !== strpos( $platform, 'google play' ) ) {
										$hardware = 'android';
									} elseif ( false !== strpos( $platform, 'amazon' ) ) {
										$hardware = 'amazon-fire';
									} elseif ( false !== strpos( $platform, 'ios' ) || false !== strpos( $platform, 'ipad' ) || false !== strpos( $platform, 'iphone' ) || false !== strpos( $platform, 'app store' ) || false !== strpos( $platform, 'apple' ) ) {
										$hardware = 'iphone-ipad';
									}
								}

								if ( isset( $device_map[ $hardware ] ) ) {
									$device = $device_map[ $hardware ];
									if ( isset( $app_devices[ $device['key'] ] ) ) {
										$app_devices[ $device['key'] ]['label'] .= '、' . $device['label'];
									} else {
										$app_devices[ $device['key'] ] = $device;
									}
								}
							}
						}
						?>
						<article <?php post_class( 'node-library-shelf-card' ); ?>>
							<a class="node-library-shelf-card__link node-library-shelf-card__link--main" href="<?php the_permalink(); ?>">
								<span class="node-library-shelf-card__meta">
									<span class="node-library-shelf-card__type node-library-shelf-card__type--<?php echo esc_attr( $library_type ); ?>">
										<span class="material-symbols-outlined" aria-hidden="true"><?php echo 'app' === $library_type ? 'smartphone' : 'sports_esports'; ?></span>
										<?php echo esc_html( 'app' === $library_type ? __( 'アプリ', 'node' ) : __( 'ゲーム', 'node' ) ); ?>
									</span>
									<?php if ( ! empty( $app_devices ) ) : ?>
										<span class="node-library-shelf-card__devices" role="img" aria-label="<?php echo esc_attr( implode( '、', wp_list_pluck( $app_devices, 'label' ) ) ); ?>">
											<?php foreach ( $app_devices as $app_device ) : ?>
												<span class="material-symbols-outlined" aria-hidden="true"><?php echo esc_html( $app_device['icon'] ); ?></span>
											<?php endforeach; ?>
										</span>
									<?php endif; ?>
								</span>
								<h2><?php the_title(); ?></h2>
								<?php if ( get_post_meta( $library_id, '_node_library_summary', true ) ) : ?>
									<p><?php echo esc_html( wp_trim_words( (string) get_post_meta( $library_id, '_node_library_summary', true ), 42 ) ); ?></p>
								<?php endif; ?>
							</a>
							<?php if ( ! empty( $visible_platform_names ) ) : ?>
								<?php if ( $hidden_platform_count > 0 ) : ?>
									<details class="node-library-shelf-card__platform-details">
										<summary aria-label="<?php esc_attr_e( '対応プラットフォームをすべて表示', 'node' ); ?>">
											<span class="node-library-shelf-card__platforms">
												<?php foreach ( $visible_platform_names as $platform_name ) : ?>
													<span class="node-library-shelf-card__platform-pill"><?php echo esc_html( $platform_name ); ?></span>
												<?php endforeach; ?>
												<span class="node-library-shelf-card__platform-pill node-library-shelf-card__platform-pill--more">+<?php echo esc_html( $hidden_platform_count ); ?></span>
											</span>
										</summary>
										<span class="node-library-shelf-card__platforms node-library-shelf-card__platforms--expanded">
											<?php foreach ( array_slice( $platform_names, count( $visible_platform_names ) ) as $platform_name ) : ?>
												<span class="node-library-shelf-card__platform-pill"><?php echo esc_html( $platform_name ); ?></span>
											<?php endforeach; ?>
										</span>
									</details>
								<?php else : ?>
									<span class="node-library-shelf-card__platforms" aria-label="<?php esc_attr_e( '対応プラットフォーム', 'node' ); ?>">
										<?php foreach ( $visible_platform_names as $platform_name ) : ?>
											<span class="node-library-shelf-card__platform-pill"><?php echo esc_html( $platform_name ); ?></span>
										<?php endforeach; ?>
									</span>
								<?php endif; ?>
							<?php endif; ?>
							<a class="node-library-shelf-card__link node-library-shelf-card__link--footer" href="<?php the_permalink(); ?>">
								<span class="node-library-shelf-card__footer">
									<span class="node-library-shelf-card__stats">
										<span><span class="material-symbols-outlined" aria-hidden="true">article</span><?php echo esc_html( sprintf( __( '記事 %s件', 'node' ), number_format_i18n( $library_article_counts[ $library_id ] ?? 0 ) ) ); ?></span>
									</span>
									<span class="node-library-shelf-card__action"><?php esc_html_e( '詳細を見る', 'node' ); ?><span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></span>
							</span>
						</a>
					</article>
				<?php endwhile; ?>
			</div>
		<?php else : ?>
			<div class="node-library-empty-state">
				<span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
				<p><?php esc_html_e( '現在、該当するライブラリ項目はありません。', 'node' ); ?></p>
				<?php if ( $selected_type ) : ?><a href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( 'すべての作品を見る', 'node' ); ?></a><?php endif; ?>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( $wp_query->max_num_pages > 1 ) : ?>
		<?php
		$pagination_current  = max( 1, (int) get_query_var( 'paged' ) );
		$pagination_total    = (int) $wp_query->max_num_pages;
			$pagination_base     = $selected_type
				? trailingslashit( $archive_url . $selected_type ) . 'page/%#%/'
				: str_replace( 999999999, '%#%', esc_url_raw( get_pagenum_link( 999999999 ) ) );
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
								'add_args'  => false,
								'prev_text' => '<span class="screen-reader-text">' . esc_html__( '前へ', 'node' ) . '</span><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>',
								'next_text' => '<span class="screen-reader-text">' . esc_html__( '次へ', 'node' ) . '</span><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>',
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
