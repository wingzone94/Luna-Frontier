<?php

declare(strict_types=1);

/**
 * Featured article hero slider for the Luminous Core top page.
 *
 * Isolated from header / navigation. Load from functions.php and render
 * from index.php with get_template_part( 'template-parts/hero-slider' ).
 *
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LF_HERO_SLIDER_OPTION_ENABLED  = 'lf_hero_slider_enabled';
const LF_HERO_SLIDER_OPTION_SOURCE   = 'lf_hero_slider_source';
const LF_HERO_SLIDER_OPTION_CATEGORY = 'lf_hero_slider_category';
const LF_HERO_SLIDER_OPTION_IDS      = 'lf_hero_slider_ids';
const LF_HERO_SLIDER_OPTION_COUNT    = 'lf_hero_slider_count';
const LF_HERO_SLIDER_OPTION_AUTOPLAY = 'lf_hero_slider_autoplay';
const LF_HERO_SLIDER_OPTION_INTERVAL = 'lf_hero_slider_interval';

/**
 * Resolved settings. Filters let operators change source without editing markup.
 *
 * @return array{
 *   enabled: bool,
 *   source: string,
 *   category: string,
 *   ids: int[],
 *   count: int,
 *   autoplay: bool,
 *   interval: int
 * }
 */
function lf_hero_slider_get_settings(): array {
	$source = (string) get_option( LF_HERO_SLIDER_OPTION_SOURCE, 'latest' );
	$allowed_sources = array( 'latest', 'category', 'ids', 'sticky' );
	if ( ! in_array( $source, $allowed_sources, true ) ) {
		$source = 'latest';
	}

	$ids_raw = (string) get_option( LF_HERO_SLIDER_OPTION_IDS, '' );
	$ids     = array();
	if ( '' !== $ids_raw ) {
		foreach ( preg_split( '/[\s,]+/', $ids_raw ) as $piece ) {
			$id = absint( $piece );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
	}

	$count    = (int) get_option( LF_HERO_SLIDER_OPTION_COUNT, 6 );
	$count    = max( 1, min( 8, $count ) );
	$interval = (int) get_option( LF_HERO_SLIDER_OPTION_INTERVAL, 6000 );
	$interval = max( 4000, min( 12000, $interval ) );

	$settings = array(
		'enabled'  => '0' !== (string) get_option( LF_HERO_SLIDER_OPTION_ENABLED, '1' ),
		'source'   => $source,
		'category' => (string) get_option( LF_HERO_SLIDER_OPTION_CATEGORY, '' ),
		'ids'      => $ids,
		'count'    => $count,
		'autoplay' => '1' === (string) get_option( LF_HERO_SLIDER_OPTION_AUTOPLAY, '1' ),
		'interval' => $interval,
	);

	/**
	 * Filter hero slider settings.
	 *
	 * @param array $settings Settings array.
	 */
	return apply_filters( 'lf_hero_slider_settings', $settings );
}

/**
 * Build WP_Query args for the slider.
 *
 * @return array<string, mixed>
 */
function lf_hero_slider_get_query_args(): array {
	$settings = lf_hero_slider_get_settings();

	$args = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => $settings['count'],
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	);

	switch ( $settings['source'] ) {
		case 'sticky':
			$sticky = get_option( 'sticky_posts', array() );
			if ( ! empty( $sticky ) ) {
				$args['post__in'] = array_map( 'absint', (array) $sticky );
				$args['orderby']  = 'post__in';
			}
			break;

		case 'ids':
			if ( ! empty( $settings['ids'] ) ) {
				$args['post__in'] = $settings['ids'];
				$args['orderby']  = 'post__in';
				$args['posts_per_page'] = count( $settings['ids'] );
			}
			break;

		case 'category':
			$category = $settings['category'];
			if ( '' !== $category ) {
				if ( ctype_digit( $category ) ) {
					$args['cat'] = absint( $category );
				} else {
					$args['category_name'] = sanitize_title( $category );
				}
			}
			break;

		case 'latest':
		default:
			break;
	}

	/**
	 * Filter query args (category, IDs, featured, latest, etc.).
	 *
	 * @param array $args     WP_Query args.
	 * @param array $settings Resolved settings.
	 */
	return apply_filters( 'lf_hero_slider_query_args', $args, $settings );
}

/**
 * Collect slide payload from WordPress posts.
 *
 * @return list<array<string, mixed>>
 */
function lf_hero_slider_get_slides(): array {
	$settings = lf_hero_slider_get_settings();
	if ( ! $settings['enabled'] ) {
		return array();
	}

	$query  = new WP_Query( lf_hero_slider_get_query_args() );
	$slides = array();

	if ( $query->have_posts() ) {
		$priority_assigned = false;

		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id   = (int) get_the_ID();
			$has_image = has_post_thumbnail( $post_id );
			$is_lcp    = ! $priority_assigned && $has_image;

			$image_html = '';
			$image_url  = '';
			if ( $has_image ) {
				$thumb_attr = array(
					'alt'     => '',
					'class'   => 'lf-hero-slide__image',
					'loading' => $is_lcp ? 'eager' : 'lazy',
				);
				if ( $is_lcp ) {
					$thumb_attr['fetchpriority'] = 'high';
					$priority_assigned           = true;
				}
				$image_html = get_the_post_thumbnail( $post_id, 'large', $thumb_attr );
				$image_src  = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'medium' );
				$image_url  = is_array( $image_src ) ? (string) $image_src[0] : '';
			}

			$categories = function_exists( 'node_get_post_categories_for_display' )
				? node_get_post_categories_for_display( $post_id )
				: get_the_category( $post_id );

			$category_name = '';
			$category_html = '';
			if ( ! empty( $categories ) ) {
				$primary         = $categories[0];
				$category_name   = is_object( $primary ) ? (string) $primary->name : '';
				$category_html   = function_exists( 'node_render_category_label' )
					? node_render_category_label(
						$primary,
						array(
							'tag'   => 'span',
							'class' => 'lf-hero-slide__category',
							'href'  => false,
						)
					)
					: '<span class="lf-hero-slide__category">' . esc_html( $category_name ) . '</span>';
			}

			$excerpt = get_the_excerpt( $post_id );
			$excerpt = wp_strip_all_tags( (string) $excerpt );
			$excerpt = wp_trim_words( $excerpt, 28, '…' );

			$slides[] = array(
				'id'            => $post_id,
				'url'           => get_permalink( $post_id ),
				'title'         => get_the_title( $post_id ),
				'excerpt'       => $excerpt,
				'date'          => get_the_date( 'Y.m.d', $post_id ),
				'datetime'      => get_the_date( DATE_W3C, $post_id ),
				'category_name' => $category_name,
				'category_html' => $category_html,
				'image_html'    => $image_html,
				'image_url'     => $image_url,
				'has_image'     => $has_image,
			);
		}
		wp_reset_postdata();
	}

	/**
	 * Filter assembled slides.
	 *
	 * @param array $slides Slide payloads.
	 */
	return apply_filters( 'lf_hero_slider_slides', $slides );
}

/**
 * Register / enqueue isolated assets. Does not touch header scripts.
 */
function lf_hero_slider_enqueue_assets(): void {
	if ( ! is_front_page() && ! is_home() ) {
		return;
	}
	if ( is_paged() ) {
		return;
	}

	$settings = lf_hero_slider_get_settings();
	if ( ! $settings['enabled'] ) {
		return;
	}

	$css = get_theme_file_path( 'assets/css/lf-hero-slider.css' );
	$js  = get_theme_file_path( 'assets/js/lf-hero-slider.js' );
	if ( ! is_readable( $css ) || ! is_readable( $js ) ) {
		return;
	}

	$ver = function_exists( 'node_get_theme_version' ) ? node_get_theme_version() : '1.0.0';

	wp_enqueue_style(
		'lf-hero-slider',
		get_theme_file_uri( 'assets/css/lf-hero-slider.css' ),
		array(),
		$ver . '.' . (string) filemtime( $css )
	);

	wp_enqueue_script(
		'lf-hero-slider',
		get_theme_file_uri( 'assets/js/lf-hero-slider.js' ),
		array(),
		$ver . '.' . (string) filemtime( $js ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'lf_hero_slider_enqueue_assets', 30 );

/**
 * Register settings so they can be saved from Node Settings.
 */
function lf_hero_slider_register_settings(): void {
	$bool = static function ( string $option, string $default ): Closure {
		return static function ( $value ) use ( $option, $default ): string {
			if ( null === $value ) {
				$value = get_option( $option, $default );
			}
			return '1' === (string) $value ? '1' : '0';
		};
	};

	register_setting(
		'node_settings_group',
		LF_HERO_SLIDER_OPTION_ENABLED,
		array( 'sanitize_callback' => $bool( LF_HERO_SLIDER_OPTION_ENABLED, '1' ) )
	);
	register_setting(
		'node_settings_group',
		LF_HERO_SLIDER_OPTION_SOURCE,
		array(
			'sanitize_callback' => static function ( $value ): string {
				$value = $value ?? get_option( LF_HERO_SLIDER_OPTION_SOURCE, 'latest' );
				$allowed = array( 'latest', 'category', 'ids', 'sticky' );
				$value   = is_string( $value ) ? $value : 'latest';
				return in_array( $value, $allowed, true ) ? $value : 'latest';
			},
		)
	);
	register_setting(
		'node_settings_group',
		LF_HERO_SLIDER_OPTION_CATEGORY,
		array( 'sanitize_callback' => static function ( $value ): string {
			return sanitize_text_field( $value ?? get_option( LF_HERO_SLIDER_OPTION_CATEGORY, '' ) );
		} )
	);
	register_setting(
		'node_settings_group',
		LF_HERO_SLIDER_OPTION_IDS,
		array( 'sanitize_callback' => static function ( $value ): string {
			return sanitize_text_field( $value ?? get_option( LF_HERO_SLIDER_OPTION_IDS, '' ) );
		} )
	);
	register_setting(
		'node_settings_group',
		LF_HERO_SLIDER_OPTION_COUNT,
		array(
			'sanitize_callback' => static function ( $value ): int {
				return max( 1, min( 8, absint( $value ?? get_option( LF_HERO_SLIDER_OPTION_COUNT, 6 ) ) ) );
			},
		)
	);
	register_setting(
		'node_settings_group',
		LF_HERO_SLIDER_OPTION_AUTOPLAY,
		array( 'sanitize_callback' => $bool( LF_HERO_SLIDER_OPTION_AUTOPLAY, '1' ) )
	);
	register_setting(
		'node_settings_group',
		LF_HERO_SLIDER_OPTION_INTERVAL,
		array(
			'sanitize_callback' => static function ( $value ): int {
				return max( 4000, min( 12000, absint( $value ?? get_option( LF_HERO_SLIDER_OPTION_INTERVAL, 6000 ) ) ) );
			},
		)
	);
}
add_action( 'admin_init', 'lf_hero_slider_register_settings' );

/**
 * Admin card HTML. Call from node_render_settings_page() if you want UI.
 */
function lf_hero_slider_render_admin_card(): void {
	$enabled  = (string) get_option( LF_HERO_SLIDER_OPTION_ENABLED, '1' );
	$source   = (string) get_option( LF_HERO_SLIDER_OPTION_SOURCE, 'latest' );
	$category = (string) get_option( LF_HERO_SLIDER_OPTION_CATEGORY, '' );
	$ids      = (string) get_option( LF_HERO_SLIDER_OPTION_IDS, '' );
	$count    = (int) get_option( LF_HERO_SLIDER_OPTION_COUNT, 6 );
	$autoplay = (string) get_option( LF_HERO_SLIDER_OPTION_AUTOPLAY, '1' );
	$interval = (int) get_option( LF_HERO_SLIDER_OPTION_INTERVAL, 6000 );
	?>
	<div class="m3-admin-card" style="background:#fff;padding:25px;border-radius:16px;margin-bottom:25px;border:1px solid #e0e0e0;box-shadow:0 4px 12px rgba(0,0,0,.05);">
		<h2 style="margin-top:0;color:#FF9900;display:flex;align-items:center;gap:10px;">
			<span class="dashicons dashicons-images-alt2"></span> 注目記事スライドショー
		</h2>
		<p class="description">トップページの既存ヘッダー直下に表示します。ヘッダー自体は変更しません。</p>
		<table class="form-table">
			<tr>
				<th scope="row">表示</th>
				<td>
					<input type="hidden" name="<?php echo esc_attr( LF_HERO_SLIDER_OPTION_ENABLED ); ?>" value="0" />
					<label>
						<input type="checkbox" name="<?php echo esc_attr( LF_HERO_SLIDER_OPTION_ENABLED ); ?>" value="1" <?php checked( $enabled, '1' ); ?> />
						有効にする
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row">記事の取得元</th>
				<td>
					<select name="<?php echo esc_attr( LF_HERO_SLIDER_OPTION_SOURCE ); ?>">
						<option value="latest" <?php selected( $source, 'latest' ); ?>>最新記事</option>
						<option value="category" <?php selected( $source, 'category' ); ?>>特定カテゴリ</option>
						<option value="sticky" <?php selected( $source, 'sticky' ); ?>>先頭固定（注目）</option>
						<option value="ids" <?php selected( $source, 'ids' ); ?>>任意の記事ID</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">カテゴリ（slug または ID）</th>
				<td>
					<input type="text" class="regular-text" name="<?php echo esc_attr( LF_HERO_SLIDER_OPTION_CATEGORY ); ?>" value="<?php echo esc_attr( $category ); ?>" placeholder="news" />
				</td>
			</tr>
			<tr>
				<th scope="row">記事ID（カンマ区切り）</th>
				<td>
					<input type="text" class="regular-text" name="<?php echo esc_attr( LF_HERO_SLIDER_OPTION_IDS ); ?>" value="<?php echo esc_attr( $ids ); ?>" placeholder="12, 34, 56" />
				</td>
			</tr>
			<tr>
				<th scope="row">表示件数</th>
				<td>
					<input type="number" min="1" max="8" name="<?php echo esc_attr( LF_HERO_SLIDER_OPTION_COUNT ); ?>" value="<?php echo esc_attr( (string) $count ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row">自動再生</th>
				<td>
					<input type="hidden" name="<?php echo esc_attr( LF_HERO_SLIDER_OPTION_AUTOPLAY ); ?>" value="0" />
					<label>
						<input type="checkbox" name="<?php echo esc_attr( LF_HERO_SLIDER_OPTION_AUTOPLAY ); ?>" value="1" <?php checked( $autoplay, '1' ); ?> />
						有効にする（ホバー・操作・非表示時は停止）
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row">自動再生間隔（ms）</th>
				<td>
					<input type="number" min="4000" max="12000" step="500" name="<?php echo esc_attr( LF_HERO_SLIDER_OPTION_INTERVAL ); ?>" value="<?php echo esc_attr( (string) $interval ); ?>" />
				</td>
			</tr>
		</table>
	</div>
	<?php
}

/**
 * Shortcode [lf_hero_slider] for a custom block / page later.
 */
function lf_hero_slider_shortcode(): string {
	ob_start();
	$template = get_theme_file_path( 'template-parts/hero-slider.php' );
	if ( is_readable( $template ) ) {
		include $template;
	}
	return (string) ob_get_clean();
}
add_shortcode( 'lf_hero_slider', 'lf_hero_slider_shortcode' );
