<?php
/**
 * Plugin Name: Luna Engine
 * Plugin URI:  https://github.com/wingzone94/Node
 * Description: Luminous Core のテーマ非依存データと記事メトリクスを管理します。
 * Version:     1.4.0
 * Author:      Luminous Core Teams
 * License:     MIT
 * Text Domain: luminous-core-engine
 *
 * @package LuminousCoreEngine
 */

declare(strict_types=1);

namespace LuminousCore\Engine {

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	final class ArticleMetrics {

		public const CHARACTER_COUNT_META_KEY = '_luminous_core_character_count';
		public const READING_SECONDS_META_KEY = '_luminous_core_reading_seconds';
		public const READING_MINUTES_META_KEY = '_luminous_core_reading_minutes';
		public const LEGACY_SEARCH_META_KEY = '_node_char_count';

		private const CHARACTERS_PER_MINUTE = 550;
		private const DISTRIBUTION_CACHE_KEY = 'node_article_length_distribution_v1';

		public function register_hooks(): void {
			add_action( 'init', array( $this, 'register_meta' ) );
			add_action( 'save_post_post', array( $this, 'update_on_save' ), 10, 3 );
			add_action( 'deleted_post', array( $this, 'clear_distribution_cache' ) );
			add_action( 'transition_post_status', array( $this, 'clear_distribution_cache' ) );
		}

		public function register_meta(): void {
			$arguments = array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
					unset( $allowed, $meta_key );

					return current_user_can( 'edit_post', $post_id );
				},
			);

			foreach ( self::meta_keys() as $meta_key ) {
				register_post_meta( 'post', $meta_key, $arguments );
			}
		}

		public function update_on_save( int $post_id, \WP_Post $post, bool $update ): void {
			unset( $update );

			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}

			$this->persist( $post_id, self::calculate( $post->post_content ) );
			$this->clear_distribution_cache();
		}

		/**
		 * @return array{chars: int, reading: int, reading_seconds: int}
		 */
		public function get( int $post_id ): array {
			if ( $this->has_saved_metrics( $post_id ) ) {
				return array(
					'chars'           => (int) get_post_meta( $post_id, self::CHARACTER_COUNT_META_KEY, true ),
					'reading'         => (int) get_post_meta( $post_id, self::READING_MINUTES_META_KEY, true ),
					'reading_seconds' => (int) get_post_meta( $post_id, self::READING_SECONDS_META_KEY, true ),
				);
			}

			$content = (string) get_post_field( 'post_content', $post_id );

			return self::calculate( $content );
		}

		/**
		 * 公開記事の文字数分布を返す。
		 *
		 * @return array{count: int, median: int, p90: int}
		 */
		public function get_distribution(): array {
			$cached = get_transient( self::DISTRIBUTION_CACHE_KEY );

			if ( is_array( $cached ) && isset( $cached['count'], $cached['median'], $cached['p90'] ) ) {
				return array(
					'count'  => (int) $cached['count'],
					'median' => (int) $cached['median'],
					'p90'    => (int) $cached['p90'],
				);
			}

			$post_ids = get_posts(
				array(
					'post_type'              => 'post',
					'post_status'            => 'publish',
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$lengths = array();

			foreach ( $post_ids as $post_id ) {
				$lengths[] = $this->get( (int) $post_id )['chars'];
			}

			sort( $lengths, SORT_NUMERIC );
			$distribution = array(
				'count'  => count( $lengths ),
				'median' => self::nearest_rank_percentile( $lengths, 0.5 ),
				'p90'    => self::nearest_rank_percentile( $lengths, 0.9 ),
			);

			set_transient( self::DISTRIBUTION_CACHE_KEY, $distribution, DAY_IN_SECONDS );

			return $distribution;
		}

		public function clear_distribution_cache(): void {
			delete_transient( self::DISTRIBUTION_CACHE_KEY );
		}

		/**
		 * @return array{chars: int, reading: int, reading_seconds: int}
		 */
		private static function calculate( string $content ): array {
			$plain_text      = wp_strip_all_tags( strip_shortcodes( $content ) );
			$character_count = mb_strlen( $plain_text, 'UTF-8' );
			$reading_seconds = max(
				30,
				(int) round( ( $character_count / self::CHARACTERS_PER_MINUTE ) * 60 )
			);

			return array(
				'chars'           => $character_count,
				'reading'         => max( 1, (int) ceil( $reading_seconds / 60 ) ),
				'reading_seconds' => $reading_seconds,
			);
		}

		/**
		 * @param int[] $values 昇順の整数配列。
		 */
		private static function nearest_rank_percentile( array $values, float $percentile ): int {
			$count = count( $values );

			if ( 0 === $count ) {
				return 0;
			}

			$rank  = (int) ceil( max( 0.0, min( 1.0, $percentile ) ) * $count );
			$index = max( 0, min( $count - 1, $rank - 1 ) );

			return (int) $values[ $index ];
		}

		/**
		 * @param array{chars: int, reading: int, reading_seconds: int} $metrics Article metrics.
		 */
		private function persist( int $post_id, array $metrics ): void {
			update_post_meta( $post_id, self::CHARACTER_COUNT_META_KEY, $metrics['chars'] );
			update_post_meta( $post_id, self::READING_MINUTES_META_KEY, $metrics['reading'] );
			update_post_meta( $post_id, self::READING_SECONDS_META_KEY, $metrics['reading_seconds'] );
			update_post_meta( $post_id, self::LEGACY_SEARCH_META_KEY, $metrics['chars'] );
		}

		private function has_saved_metrics( int $post_id ): bool {
			foreach ( self::meta_keys() as $meta_key ) {
				if ( ! metadata_exists( 'post', $post_id, $meta_key ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * @return string[]
		 */
		private static function meta_keys(): array {
			return array(
				self::CHARACTER_COUNT_META_KEY,
				self::READING_SECONDS_META_KEY,
				self::READING_MINUTES_META_KEY,
				self::LEGACY_SEARCH_META_KEY,
			);
		}
	}

	final class SpotlightRepository {

		private const CACHE_KEY = 'node_spotlight_categories_cache';
		private const PARENT_SLUG = 'spotlight';

		public function register_hooks(): void {
			add_action( 'create_category', array( $this, 'clear_cache' ) );
			add_action( 'edit_category', array( $this, 'clear_cache' ) );
			add_action( 'delete_category', array( $this, 'clear_cache' ) );
			add_action( 'edited_category', array( $this, 'clear_cache' ) );
			add_action( 'save_post_post', array( $this, 'clear_cache' ) );
			add_filter( 'manage_post_posts_columns', array( $this, 'add_admin_column' ) );
			add_action( 'manage_post_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
			add_action( 'admin_head', array( $this, 'render_admin_styles' ) );
		}

		/**
		 * @return array<int, array{name: string, slug: string, url: string, color: string, count: int, description: string}>
		 */
		public function get_categories(): array {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}

			$parent = get_category_by_slug( self::PARENT_SLUG );

			if ( ! $parent instanceof \WP_Term ) {
				return array();
			}

			$categories = get_terms(
				array(
					'taxonomy'               => 'category',
					'parent'                 => $parent->term_id,
					'hide_empty'             => true,
					'update_term_meta_cache' => true,
				)
			);

			if ( is_wp_error( $categories ) ) {
				return array();
			}

			$result = array();

			foreach ( $categories as $category ) {
				if ( ! $category instanceof \WP_Term ) {
					continue;
				}

				$color = (string) get_term_meta( $category->term_id, '_m3_color', true );
				$url   = get_category_link( $category->term_id );
				$label = $category->name;

				if ( ! preg_match( '/特集$/u', $label ) ) {
					$label .= '特集';
				}

				$result[] = array(
					'name'        => $label,
					'slug'        => $category->slug,
					'url'         => is_wp_error( $url ) ? '' : $url,
					'color'       => '' !== $color ? $color : 'var(--md-sys-color-primary)',
					'count'       => (int) $category->count,
					'description' => term_description( $category->term_id, 'category' ),
				);
			}

			set_transient( self::CACHE_KEY, $result, 12 * HOUR_IN_SECONDS );

			return $result;
		}

		public function clear_cache(): void {
			delete_transient( self::CACHE_KEY );
		}

		/**
		 * @param array<string, string> $columns Post list columns.
		 * @return array<string, string>
		 */
		public function add_admin_column( array $columns ): array {
			$new_columns = array();

			foreach ( $columns as $key => $value ) {
				$new_columns[ $key ] = $value;

				if ( 'title' === $key ) {
					$new_columns['node_spotlight'] = 'SPOTLIGHT';
				}
			}

			return $new_columns;
		}

		public function render_admin_column( string $column, int $post_id ): void {
			if ( 'node_spotlight' !== $column ) {
				return;
			}

			if ( has_category( self::PARENT_SLUG, $post_id ) ) {
				echo '<span class="dashicons dashicons-star-filled" style="color:#ff9900" title="SPOTLIGHTカテゴリに属しています"></span>';
				return;
			}

			echo '<span class="dashicons dashicons-star-empty" style="color:#ccc;opacity:.3" aria-hidden="true"></span>';
		}

		public function render_admin_styles(): void {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			if ( ! $screen || 'edit-post' !== $screen->id ) {
				return;
			}
			?>
			<style>
				.column-node_spotlight { width: 60px; text-align: center; }
				@media screen and (min-width: 783px) {
					.post-type-post .wp-list-table .column-title { width: 26%; }
					.post-type-post .wp-list-table .column-author { width: 8%; }
					.post-type-post .wp-list-table .column-categories,
					.post-type-post .wp-list-table .column-tags { width: 10%; }
					.post-type-post .wp-list-table .column-taxonomy-node_series { width: 9%; }
					.post-type-post .wp-list-table .column-ad_reward_rate { width: 80px; }
					.post-type-post .wp-list-table .column-is_special_rate { width: 64px; }
				}
			</style>
			<?php
		}
	}

	final class PlatformMetadata {

		public const META_KEY = '_node_platforms';

		private const NONCE_ACTION = 'luminous_core_engine_save_platforms';
		private const NONCE_NAME = 'luminous_core_engine_platforms_nonce';

		public function register_hooks(): void {
			add_action( 'init', array( $this, 'register_meta' ) );
			add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
			add_action( 'save_post_post', array( $this, 'save' ), 10, 2 );
			add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
			add_filter( 'luminous_core_engine_search_query_args', array( $this, 'apply_search_query' ), 10, 2 );
		}

		public function register_rest_routes(): void {
			foreach ( array( 'luminous-core-engine/v1', 'node/v1' ) as $namespace ) {
				register_rest_route(
					$namespace,
					'/search/platforms',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_rest_options' ),
						'permission_callback' => '__return_true',
					)
				);
			}
		}

		public function get_rest_options(): \WP_REST_Response {
			return rest_ensure_response( array( 'groups' => self::groups() ) );
		}

		public function register_meta(): void {
			register_post_meta(
				'post',
				self::META_KEY,
				array(
					'type'              => 'array',
					'single'            => true,
					'default'           => array(),
					'show_in_rest'      => array(
						'schema' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'uniqueItems' => true,
						),
					),
					'sanitize_callback' => array( self::class, 'sanitize_platforms' ),
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						unset( $allowed, $meta_key );

						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}

		public function add_meta_box(): void {
			add_meta_box(
				'luminous_core_engine_platforms',
				'プラットフォーム',
				array( $this, 'render_meta_box' ),
				'post',
				'side',
				'default'
			);
		}

		public function render_meta_box( \WP_Post $post ): void {
			$current = get_post_meta( $post->ID, self::META_KEY, true );
			$current = is_array( $current ) ? array_map( 'strval', $current ) : array();

			wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
			?>
			<input type="hidden" name="node_platforms_present" value="1">
			<fieldset>
				<legend class="screen-reader-text">この記事に対応するプラットフォーム</legend>
				<div style="display:grid;gap:6px;grid-template-columns:repeat(2,minmax(0,1fr));">
					<?php foreach ( self::options() as $value => $label ) : ?>
						<label>
							<input
								type="checkbox"
								name="node_platforms[]"
								value="<?php echo esc_attr( $value ); ?>"
								<?php checked( in_array( $value, $current, true ) ); ?>
							>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
			<?php
		}

		public function save( int $post_id, \WP_Post $post ): void {
			if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
				return;
			}

			$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

			if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				return;
			}

			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}

			if ( 'post' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			$submitted = isset( $_POST['node_platforms'] )
				? self::sanitize_platforms( wp_unslash( $_POST['node_platforms'] ) )
				: array();
			$existing  = get_post_meta( $post_id, self::META_KEY, true );
			$existing  = is_array( $existing ) ? array_map( 'strval', $existing ) : array();
			$unknown   = array_diff( $existing, array_keys( self::options() ) );
			$platforms = array_values( array_unique( array_merge( $unknown, $submitted ) ) );

			if ( array() === $platforms ) {
				delete_post_meta( $post_id, self::META_KEY );
				return;
			}

			update_post_meta( $post_id, self::META_KEY, $platforms );
		}

		/**
		 * @param array<string, mixed> $args Search query arguments.
		 * @param array<string, mixed> $params Request parameters.
		 * @return array<string, mixed>
		 */
		public function apply_search_query( array $args, array $params ): array {
			$platforms = self::sanitize_platforms( $params['m3_platform'] ?? array() );

			if ( array() === $platforms ) {
				return $args;
			}

			if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
				$args['meta_query'] = array();
			}

			$platform_query = array( 'relation' => 'OR' );

			foreach ( $platforms as $platform ) {
				$platform_query[] = array(
					'key'     => self::META_KEY,
					'value'   => $platform,
					'compare' => 'LIKE',
				);
			}

			$args['meta_query'][] = $platform_query;

			return $args;
		}

		/**
		 * @return array<string, string>
		 */
		public static function options(): array {
			$options = array();

			foreach ( self::groups() as $group ) {
				$collections = isset( $group['items'] )
					? array( $group['items'] )
					: array_column( $group['subgroups'], 'items' );

				foreach ( $collections as $items ) {
					foreach ( $items as $item ) {
						$options[ $item['value'] ] = $item['admin_label'] ?? $item['label'];
					}
				}
			}

			return $options;
		}

		/**
		 * @return array<int, array<string, mixed>>
		 */
		public static function groups(): array {
			return array(
				array(
					'icon'  => 'smartphone',
					'label' => 'Mobile & Web',
					'items' => array(
						array( 'modifier' => 'ios', 'value' => 'iOS', 'label' => 'iOS' ),
						array( 'modifier' => 'android', 'value' => 'Android', 'label' => 'Android' ),
						array( 'modifier' => 'webapp', 'value' => 'Web', 'label' => 'Web App' ),
					),
				),
				array(
					'icon'  => 'desktop_windows',
					'label' => 'PC Platforms',
					'items' => array(
						array( 'modifier' => 'windows', 'value' => 'Windows', 'label' => 'Windows' ),
						array( 'modifier' => 'mac', 'value' => 'Mac', 'label' => 'Mac' ),
						array( 'modifier' => 'linux', 'value' => 'Linux', 'label' => 'Linux' ),
						array( 'modifier' => 'steam', 'value' => 'Steam', 'label' => 'Steam' ),
						array( 'modifier' => 'epic', 'value' => 'Epic', 'label' => 'Epic', 'admin_label' => 'Epic Games' ),
						array( 'modifier' => 'geforce', 'value' => 'GeForce', 'label' => 'GFN', 'admin_label' => 'GeForce NOW' ),
					),
				),
				array(
					'icon'      => 'sports_esports',
					'label'     => 'Consoles',
					'subgroups' => array(
						array(
							'label' => 'Nintendo',
							'items' => array(
								array( 'modifier' => 'nintendo', 'value' => 'Switch', 'label' => 'Switch', 'admin_label' => 'Nintendo Switch' ),
								array( 'modifier' => 'nintendo', 'value' => '3DS', 'label' => '3DS', 'admin_label' => 'Nintendo 3DS' ),
								array( 'modifier' => 'nintendo', 'value' => 'WiiU', 'label' => 'Wii U' ),
							),
						),
						array(
							'label' => 'PlayStation',
							'items' => array(
								array( 'modifier' => 'sony', 'value' => 'PS5', 'label' => 'PS5', 'admin_label' => 'PlayStation 5' ),
								array( 'modifier' => 'sony', 'value' => 'PS4', 'label' => 'PS4', 'admin_label' => 'PlayStation 4' ),
								array( 'modifier' => 'sony', 'value' => 'PS3', 'label' => 'PS3', 'admin_label' => 'PlayStation 3' ),
								array( 'modifier' => 'sony', 'value' => 'PSVita', 'label' => 'PS Vita', 'admin_label' => 'PlayStation Vita' ),
							),
						),
						array(
							'label' => 'Xbox',
							'items' => array(
								array( 'modifier' => 'xbox', 'value' => 'XboxX', 'label' => 'Xbox X|S', 'admin_label' => 'Xbox Series X|S' ),
								array( 'modifier' => 'xbox', 'value' => 'XboxOne', 'label' => 'Xbox One' ),
							),
						),
					),
				),
			);
		}

		/**
		 * @return string[]
		 */
		public static function sanitize_platforms( mixed $value ): array {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$allowed = array_keys( self::options() );
			$values  = array_map( 'sanitize_text_field', $value );

			return array_values( array_unique( array_intersect( $values, $allowed ) ) );
		}
	}

	final class AdvancedSearch {

		private const MEDIA_TYPES = array( 'image', 'video', 'map', 'youtube', 'sns', 'download' );

		public function register_hooks(): void {
			add_action( 'pre_get_posts', array( $this, 'extend_main_query' ) );
			add_filter( 'posts_where', array( $this, 'filter_content_media' ), 10, 2 );
			add_action( 'wp_ajax_node_get_search_count', array( $this, 'ajax_get_count' ) );
			add_action( 'wp_ajax_nopriv_node_get_search_count', array( $this, 'ajax_get_count' ) );
			add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		}

		public function register_rest_routes(): void {
			foreach ( array( 'luminous-core-engine/v1', 'node/v1' ) as $namespace ) {
				register_rest_route(
					$namespace,
					'/search/tags',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_rest_tags' ),
						'permission_callback' => '__return_true',
						'args'                => array(
							'q' => array(
								'required'          => true,
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					)
				);

				register_rest_route(
					$namespace,
					'/search/results',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_rest_results' ),
						'permission_callback' => '__return_true',
					)
				);
			}
		}

		/**
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function get_rest_tags( \WP_REST_Request $request ) {
			$query = sanitize_text_field( (string) $request->get_param( 'q' ) );

			if ( '' === $query ) {
				return rest_ensure_response( array() );
			}

			$terms = get_terms(
				array(
					'taxonomy'   => 'post_tag',
					'hide_empty' => true,
					'number'     => 8,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'search'     => $query,
				)
			);

			if ( is_wp_error( $terms ) ) {
				return $terms;
			}

			return rest_ensure_response(
				array_map(
					static fn( \WP_Term $term ): array => array(
						'id'   => $term->term_id,
						'name' => $term->name,
					),
					$terms
				)
			);
		}

		public function get_rest_results( \WP_REST_Request $request ): \WP_REST_Response {
			$params                        = self::get_request_params( $request );
			$args                          = $this->build_args( $params );
			$args['post_type']             = 'post';
			$args['post_status']           = 'publish';
			$args['posts_per_page']        = 6;
			$args['no_found_rows']         = false;
			$args['node_is_ajax_search']   = true;
			$query                         = new \WP_Query( $args );
			$results                       = array_map(
				static function ( \WP_Post $post ): array {
					$excerpt = has_excerpt( $post )
						? get_the_excerpt( $post )
						: wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 24 );
					$excerpt = html_entity_decode(
						wp_strip_all_tags( $excerpt ),
						ENT_QUOTES | ENT_HTML5,
						get_bloginfo( 'charset' )
					);

					return array(
						'id'           => $post->ID,
						'title'        => get_the_title( $post ),
						'url'          => get_permalink( $post ),
						'excerpt'      => $excerpt,
						'date'         => get_the_date( 'c', $post ),
						'date_display' => get_the_date( get_option( 'date_format' ), $post ),
					);
				},
				$query->posts
			);

			return rest_ensure_response(
				array(
					'total'   => (int) $query->found_posts,
					'results' => $results,
				)
			);
		}

		/**
		 * @return array<string, mixed>
		 */
		private static function get_request_params( \WP_REST_Request $request ): array {
			$params = array();

			foreach ( array( 's', 'm3_cat', 'm3_tag', 'm3_start_date', 'm3_end_date', 'm3_min', 'm3_max', 'm3_sort', 'm3_ai' ) as $key ) {
				$value = $request->get_param( $key );
				if ( null !== $value && ! is_array( $value ) ) {
					$params[ $key ] = sanitize_text_field( (string) $value );
				}
			}

			foreach ( array( 'm3_platform', 'm3_media_type' ) as $key ) {
				$value = $request->get_param( $key );
				if ( null !== $value ) {
					$params[ $key ] = array_values(
						array_filter( array_map( 'sanitize_text_field', (array) $value ) )
					);
				}
			}

			return $params;
		}

		/**
		 * @param array<string, mixed> $params Search parameters.
		 * @return array<string, mixed>
		 */
		public function build_args( array $params ): array {
			$args = array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'meta_query'  => array(),
				'tax_query'   => array(),
			);

			if ( ! empty( $params['s'] ) ) {
				$args['s'] = sanitize_text_field( $params['s'] );
			}

			if ( ! empty( $params['m3_cat'] ) ) {
				$args['tax_query'][] = array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => (int) $params['m3_cat'],
				);
			}

			if ( ! empty( $params['m3_tag'] ) ) {
				$args['tax_query'][] = array(
					'taxonomy' => 'post_tag',
					'field'    => 'name',
					'terms'    => sanitize_text_field( $params['m3_tag'] ),
				);
			}

			$min_chars = isset( $params['m3_min'] ) ? (int) $params['m3_min'] : 0;
			$max_chars = isset( $params['m3_max'] ) ? (int) $params['m3_max'] : 10000;

			if ( $min_chars > 0 || $max_chars < 10000 ) {
				$meta_range = array(
					'key'  => ArticleMetrics::LEGACY_SEARCH_META_KEY,
					'type' => 'NUMERIC',
				);

				if ( $min_chars > 0 && $max_chars < 10000 ) {
					$meta_range['value']   = array( $min_chars, $max_chars );
					$meta_range['compare'] = 'BETWEEN';
				} elseif ( $min_chars > 0 ) {
					$meta_range['value']   = $min_chars;
					$meta_range['compare'] = '>=';
				} else {
					$args['meta_query'][] = array(
						'relation' => 'OR',
						array(
							'key'     => ArticleMetrics::LEGACY_SEARCH_META_KEY,
							'value'   => $max_chars,
							'type'    => 'NUMERIC',
							'compare' => '<=',
						),
						array(
							'key'     => ArticleMetrics::LEGACY_SEARCH_META_KEY,
							'compare' => 'NOT EXISTS',
						),
					);
					$meta_range = null;
				}

				if ( is_array( $meta_range ) ) {
					$args['meta_query'][] = $meta_range;
				}
			}

			$date_query = array();

			if ( ! empty( $params['m3_start_date'] ) ) {
				$date_query['after'] = sanitize_text_field( $params['m3_start_date'] );
			}

			if ( ! empty( $params['m3_end_date'] ) ) {
				$date_query['before'] = sanitize_text_field( $params['m3_end_date'] );
			}

			if ( array() !== $date_query ) {
				$date_query['inclusive'] = true;
				$args['date_query']      = array( $date_query );
			}

			$sort = isset( $params['m3_sort'] ) ? sanitize_key( $params['m3_sort'] ) : '';

			if ( 'word_count' === $sort ) {
				$args['meta_key'] = ArticleMetrics::LEGACY_SEARCH_META_KEY;
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
			} elseif ( 'oldest' === $sort ) {
				$args['orderby'] = 'date';
				$args['order']   = 'ASC';
			} elseif ( 'newest' === $sort ) {
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
			} elseif ( 'alpha' === $sort ) {
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
			}

			if ( ! empty( $params['m3_ai'] ) && 'all' !== $params['m3_ai'] ) {
				if ( 'only' === $params['m3_ai'] ) {
					$args['meta_query'][] = array(
						'key'     => '_node_ai_generated',
						'value'   => '1',
						'compare' => '=',
					);
				} else {
					$args['meta_query'][] = array(
						'key'     => '_node_ai_generated',
						'compare' => 'NOT EXISTS',
					);
				}
			}

			$args = apply_filters( 'luminous_core_engine_search_query_args', $args, $params );

			if ( ! empty( $params['m3_media_type'] ) ) {
				$types = array_map( 'sanitize_key', (array) $params['m3_media_type'] );
				$types = array_values( array_intersect( $types, self::MEDIA_TYPES ) );

				if ( array() !== $types ) {
					$args['node_media_types'] = $types;
				}
			}

			return $args;
		}

		public function filter_content_media( string $where, \WP_Query $query ): string {
			global $wpdb;

			if ( ! ( $query->is_main_query() && $query->is_search() ) && ! $query->get( 'node_is_ajax_search' ) ) {
				return $where;
			}

			$patterns = array(
				'image'    => '<img|wp-block-image',
				'video'    => '<video|wp-block-video',
				'map'      => 'google.com/maps|wp-block-embed-google-maps',
				'youtube'  => 'youtube.com|youtu.be|wp-block-embed-youtube',
				'sns'      => 'twitter.com|x.com|instagram.com|tiktok.com|wp-block-embed-',
				'download' => '\\.(pdf|zip|exe|dmg|rar|7z)',
			);
			$conditions = array();

			foreach ( (array) $query->get( 'node_media_types' ) as $type ) {
				if ( isset( $patterns[ $type ] ) ) {
					$conditions[] = "{$wpdb->posts}.post_content REGEXP '" . esc_sql( $patterns[ $type ] ) . "'";
				}
			}

			if ( array() !== $conditions ) {
				$where .= ' AND (' . implode( ' OR ', $conditions ) . ')';
			}

			return $where;
		}

		public function extend_main_query( \WP_Query $query ): void {
			if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
				return;
			}

			$query->set( 'post_type', 'post' );
			$query->set( 'post_status', 'publish' );

			$advanced_args = $this->build_args( wp_unslash( $_GET ) );
			$forwarded_keys = array(
				'tax_query',
				'meta_query',
				'date_query',
				'meta_key',
				'orderby',
				'order',
				'node_media_types',
			);

			foreach ( $forwarded_keys as $key ) {
				if ( ! empty( $advanced_args[ $key ] ) ) {
					$query->set( $key, $advanced_args[ $key ] );
				}
			}
		}

		/**
		 * @param array<string, mixed> $params Search parameters.
		 */
		public function get_count( array $params ): int {
			$args                        = $this->build_args( $params );
			$args['posts_per_page']      = 1;
			$args['fields']              = 'ids';
			$args['no_found_rows']       = false;
			$args['node_is_ajax_search'] = true;

			$query = new \WP_Query( $args );

			return (int) $query->found_posts;
		}

		public function ajax_get_count(): void {
			wp_send_json_success(
				array(
					'count' => $this->get_count( wp_unslash( $_POST ) ),
				)
			);
		}
	}

	final class Plugin {

		private static bool $booted = false;

		private static ?ArticleMetrics $article_metrics = null;
		private static ?SpotlightRepository $spotlight = null;
		private static ?PlatformMetadata $platform_metadata = null;
		private static ?AdvancedSearch $advanced_search = null;

		public static function boot(): void {
			if ( self::$booted ) {
				return;
			}

			self::$booted         = true;
			self::$article_metrics = new ArticleMetrics();
			self::$spotlight       = new SpotlightRepository();
			self::$platform_metadata = new PlatformMetadata();
			self::$advanced_search = new AdvancedSearch();
			self::$article_metrics->register_hooks();
			self::$spotlight->register_hooks();
			self::$platform_metadata->register_hooks();
			self::$advanced_search->register_hooks();
		}

		public static function article_metrics(): ArticleMetrics {
			self::boot();

			return self::$article_metrics;
		}

		public static function spotlight(): SpotlightRepository {
			self::boot();

			return self::$spotlight;
		}

		public static function advanced_search(): AdvancedSearch {
			self::boot();

			return self::$advanced_search;
		}
	}

	/**
	 * Public API consumed by the Node theme view layer.
	 *
	 * @return array{chars: int, reading: int, reading_seconds: int}
	 */
	function get_article_metrics( int $post_id ): array {
		return Plugin::article_metrics()->get( $post_id );
	}

	/**
	 * @return array{count: int, median: int, p90: int}
	 */
	function get_article_length_distribution(): array {
		return Plugin::article_metrics()->get_distribution();
	}

	/**
	 * @return array<int, array{name: string, slug: string, url: string, color: string, count: int, description: string}>
	 */
	function get_spotlight_categories(): array {
		return Plugin::spotlight()->get_categories();
	}

	/**
	 * @return array<string, string>
	 */
	function get_platform_options(): array {
		return PlatformMetadata::options();
	}

	/**
	 * @param array<string, mixed> $params Search parameters.
	 * @return array<string, mixed>
	 */
	function get_advanced_search_args( array $params ): array {
		return Plugin::advanced_search()->build_args( $params );
	}

	/**
	 * @param array<string, mixed> $params Search parameters.
	 */
	function get_search_count_for_params( array $params ): int {
		return Plugin::advanced_search()->get_count( $params );
	}
}

namespace {
	if ( ! function_exists( 'luminous_core_engine_init' ) ) {
		function luminous_core_engine_init(): void {
			\LuminousCore\Engine\Plugin::boot();
		}
	}

	luminous_core_engine_init();
}
