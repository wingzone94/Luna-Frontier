<?php
/**
 * Single library page extensions: spec rows, price history/badges,
 * bidirectional relations, related-edition articles, and JSON-LD Offer.
 *
 * @package Node_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Library_Single_Extensions {
	/** @var bool */
	private static $initialized = false;

	/** Register single-page extension hooks once. */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_filter( 'node_library_spec_rows', array( self::class, 'filter_spec_rows' ), 10, 2 );
		add_action( 'node_library_single_after_specs', array( self::class, 'render_features_section' ), 5, 1 );
		add_action( 'node_library_single_after_specs', array( self::class, 'render_price_section' ), 10, 2 );
		add_action( 'node_library_single_before_stores', array( self::class, 'render_price_section' ) );
		add_action( 'node_library_single_after_stores', array( self::class, 'render_relations_section' ), 10, 1 );
		add_action( 'node_library_single_after_articles', array( self::class, 'render_related_articles_section' ), 10, 1 );
		add_filter( 'node_library_product_jsonld_offers', array( self::class, 'filter_jsonld_offers' ), 10, 2 );
		add_action( 'admin_menu', array( self::class, 'register_settings_page' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
	}

	/**
	 * Append L1 structured metadata rows to the spec table. Omit unset/empty fields.
	 *
	 * @param array<int, array<string, mixed>> $rows    Existing spec rows.
	 * @param int                              $post_id Library item ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter_spec_rows( array $rows, int $post_id ): array {
		$developer = trim( (string) get_post_meta( $post_id, '_node_library_developer', true ) );
		if ( '' !== $developer ) {
			$rows[] = array(
				'label' => '開発元',
				'value' => $developer,
			);
		}

		$publisher = trim( (string) get_post_meta( $post_id, '_node_library_publisher', true ) );
		if ( '' !== $publisher ) {
			$rows[] = array(
				'label' => '発売元',
				'value' => $publisher,
			);
		}

		$release_date = trim( (string) get_post_meta( $post_id, '_node_library_release_date', true ) );
		if ( '' !== $release_date ) {
			$rows[] = array(
				'label' => '発売日',
				'value' => $release_date,
			);
		}

		$genres = get_post_meta( $post_id, '_node_library_genres', true );
		if ( is_array( $genres ) ) {
			$clean_genres = array_values( array_filter( array_map( static fn( $g ) => is_string( $g ) ? trim( $g ) : '', $genres ), static fn( $g ) => '' !== $g ) );
			if ( $clean_genres ) {
				$rows[] = array(
					'label' => 'ジャンル',
					'value' => implode( '、', $clean_genres ),
				);
			}
		}

		if ( 'app' !== get_post_meta( $post_id, '_node_library_type', true ) ) {
			$rating       = Node_Library_Structured_Meta::sanitize( 'rating', get_post_meta( $post_id, '_node_library_rating', true ) );
			$cero         = Node_Library_Structured_Meta::cero_options()[ $rating['cero'] ?? '' ] ?? '';
			$rating_parts = '' !== $cero ? array( $cero ) : array();
			if ( isset( $rating['esrb'] ) ) {
				$rating_parts[] = 'ESRB: ' . $rating['esrb'];
			}
			if ( $rating_parts ) {
				$rows[] = array(
					'label' => __( 'レーティング', 'node-library' ),
					'value' => implode( ' / ', $rating_parts ),
				);
			}
		}

		$japanese = get_post_meta( $post_id, '_node_library_japanese', true );
		if ( is_array( $japanese ) ) {
			$jp_labels = array(
				'audio'     => '音声',
				'subtitles' => '字幕',
				'ui'        => 'UI',
			);
			$jp_parts  = array();
			foreach ( $jp_labels as $key => $label ) {
				if ( array_key_exists( $key, $japanese ) && is_bool( $japanese[ $key ] ) ) {
					$jp_parts[] = $label . ': ' . ( $japanese[ $key ] ? '対応' : '非対応' );
				}
			}
			if ( $jp_parts ) {
				$rows[] = array(
					'label' => '日本語対応',
					'value' => implode( ' / ', $jp_parts ),
				);
			}
		}

		$capabilities = get_post_meta( $post_id, '_node_library_capabilities', true );
		if ( is_array( $capabilities ) ) {
			$cap_parts  = array();
			$cap_labels = array(
				'crossplay' => 'クロスプレイ',
				'crosssave' => 'クロスセーブ',
				'online'    => 'オンライン対応',
				'hdr'       => 'HDR',
			);
			foreach ( $cap_labels as $key => $label ) {
				if ( array_key_exists( $key, $capabilities ) && is_bool( $capabilities[ $key ] ) ) {
					$cap_parts[] = $label . ': ' . ( $capabilities[ $key ] ? '対応' : '非対応' );
				}
			}
			if ( isset( $capabilities['max_fps'] ) && is_numeric( $capabilities['max_fps'] ) && (int) $capabilities['max_fps'] > 0 ) {
				$cap_parts[] = '最大FPS: ' . (int) $capabilities['max_fps'] . 'fps';
			}
			if ( isset( $capabilities['resolution'] ) && is_string( $capabilities['resolution'] ) && '' !== trim( $capabilities['resolution'] ) ) {
				$cap_parts[] = '解像度: ' . trim( $capabilities['resolution'] );
			}
			if ( $cap_parts ) {
				$rows[] = array(
					'label' => '能力・映像仕様',
					'value' => implode( ' / ', $cap_parts ),
				);
			}
		}

		return $rows;
	}

	/** Build detail-page offers from stored links and the currently registered products. */
	public static function store_offers( int $post_id, array $links ): array {
		$ids      = (array) get_post_meta( $post_id, '_node_library_store_ids', true );
		$offers   = array();
		$matched  = array();
		$labels   = Node_Library_Structured_Meta::stores();
		$labels['amazon_asin'] = 'Amazon';

		foreach ( $links as $link ) {
			if ( ! is_array( $link ) || ! is_string( $link['url'] ?? null ) ) {
				continue;
			}
			$url = esc_url_raw( $link['url'], array( 'http', 'https' ) );
			if ( ! wp_parse_url( $url, PHP_URL_HOST ) ) {
				continue;
			}
			$product = Node_Library_Store_ID_Migration::extract( $url );
			$store   = $product['store'] ?? '';
			$prod_id = $product['id'] ?? '';
			if ( '' === $store ) {
				$epic_slug = self::extract_epic_slug( $url );
				if ( '' !== $epic_slug ) {
					$store   = 'epic';
					$prod_id = $epic_slug;
				}
			}
			$link['url'] = $url;
			$link['catalogue_store'] = $store;
			$link['platform'] = node_library_public_store_label( $link, $ids );
			unset( $link['price_store'] );
			if ( '' !== $store ) {
				$matched[ $store ] = true;
			}
			if ( '' !== $prod_id && isset( $ids[ $store ] ) && 0 === strcasecmp( $ids[ $store ], $prod_id ) ) {
				$link['price_store'] = $store;
			}
			$offers[] = $link;
		}
		foreach ( $labels as $store => $label ) {
			if ( isset( $matched[ $store ] ) || ! is_string( $ids[ $store ] ?? null ) ) {
				continue;
			}
			$url = self::store_url( $store, $ids[ $store ] );
			if ( 'ps' === $store && Node_Library_PS_Store::valid_id( $ids[ $store ] ) ) {
				$url = 'https://store.playstation.com/ja-jp/product/' . $ids[ $store ];
			}
			if ( '' !== $url ) {
				$offers[] = array( 'platform' => $label, 'url' => $url, 'catalogue_store' => $store, 'price_store' => $store );
			}
		}

		$japanese = array();
		$other    = array();
		$prices   = array();
		foreach ( $offers as $link ) {
			$store = $link['price_store'] ?? '';
			$link['price_label']      = '';
			$link['price_numeric']    = null;
			$link['price_date']       = '';
			$link['list_price_label'] = '';
			$link['discount_percent'] = 0;
			if ( in_array( $store, Node_Library_Store::price_stores(), true ) ) {
				if ( ! array_key_exists( $store, $prices ) ) {
					$prices[ $store ] = Node_Library_Prices::effective_offer( Node_Library_Prices::current( $post_id, $store ) );
				}
				$current = $prices[ $store ];
				if ( is_numeric( $current['price'] ?? null ) && ! empty( $current['currency'] ) ) {
					$link['price_label']   = self::format_currency( $current['price'], $current['currency'] );
					$link['price_numeric'] = (float) $current['price'];
					$link['price_date']    = substr( self::format_jst_datetime( $current['recorded_at'] ), 0, 10 );
					if ( is_numeric( $current['list_price'] ?? null ) && (float) $current['list_price'] > (float) $current['price'] && (float) $current['list_price'] > 0 ) {
						$discount = (int) round( ( ( (float) $current['list_price'] - (float) $current['price'] ) / (float) $current['list_price'] ) * 100 );
						if ( $discount > 0 ) {
							$link['list_price_label'] = self::format_currency( $current['list_price'], $current['currency'] );
							$link['discount_percent'] = $discount;
						}
					}
				}
			}
			if ( self::is_japanese_store_url( $link['url'] ) ) {
				$japanese[] = $link;
			} else {
				$other[] = $link;
			}
		}
		return array_merge( $japanese, $other );
	}

	/** Region-specific destinations precede unmarked and overseas links. */
	private static function is_japanese_store_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		parse_str( $parts['query'] ?? '', $query );
		return (bool) preg_match( '~(?:\.jp$|^store-jp\.)~i', $host )
			|| (bool) preg_match( '~/(?:ja-jp|ja|jp)(?:/|$)~i', $parts['path'] ?? '' )
			|| ( is_string( $query['gl'] ?? null ) && 0 === strcasecmp( $query['gl'], 'JP' ) );
	}

	/**
	 * Render price history summary, badges, and recent price changes after the spec table.
	 */
	public static function render_price_section( int $post_id, bool $price_already_placed = false ): void {
		if ( $price_already_placed ) {
			return;
		}
		$store_ids = get_post_meta( $post_id, '_node_library_store_ids', true );
		$store_ids = is_array( $store_ids ) ? $store_ids : array();

		$price_stores = class_exists( 'Node_Library_Store' ) ? Node_Library_Store::price_stores() : array();
		$store_labels = class_exists( 'Node_Library_Structured_Meta' ) ? Node_Library_Structured_Meta::stores() : array();

		$entries = array();
		foreach ( $price_stores as $store ) {
			$external_id = isset( $store_ids[ $store ] ) && is_string( $store_ids[ $store ] ) ? trim( $store_ids[ $store ] ) : '';
			if ( '' === $external_id || ! class_exists( 'Node_Library_Prices' ) ) {
				continue;
			}
			$stats = Node_Library_Prices::stats( $post_id, $store );
			if ( null === ( $stats['current'] ?? null ) ) {
				continue;
			}
			$history       = self::recent_price_history( $post_id, $store, $external_id, 10 );
			$has_numeric   = is_numeric( $stats['current']['price'] ?? null );
			if ( ! $has_numeric ) {
				foreach ( $history as $hrow ) {
					if ( is_numeric( $hrow['price'] ?? null ) ) {
						$has_numeric = true;
						break;
					}
				}
			}
			if ( ! $has_numeric ) {
				continue;
			}
			$entries[] = array(
				'store'       => $store,
				'label'       => $store_labels[ $store ] ?? $store,
				'external_id' => $external_id,
				'stats'       => $stats,
				'history'     => $history,
			);
		}

		if ( ! $entries ) {
			if ( doing_action( 'node_library_single_before_stores' ) ) {
				$free = Node_Library_Structured_Meta::sanitize( 'is_free', get_post_meta( $post_id, '_node_library_is_free', true ) );
				?>
				<p class="library-price-status"><?php echo esc_html( $free ? __( '無料', 'node-library' ) : __( '価格データなし', 'node-library' ) ); ?></p>
				<?php
			}
			return;
		}

		$badges = array();
		foreach ( $entries as $entry ) {
			$stats   = $entry['stats'];
			$current = $stats['current'];
			if ( null !== $stats['discount_percent'] ) {
				$badges[] = array(
					'kind'  => 'sale',
					'icon'  => 'sell',
					'label' => sprintf( 'セール中（-%d%%）', (int) $stats['discount_percent'] ),
				);
			}
			if (
				null !== $stats['discount_percent']
				&& null !== ( $current['price'] ?? null )
				&& '' !== (string) $current['price']
				&& null !== $stats['lowest']
				&& '' !== (string) $stats['lowest']
				&& (float) $current['price'] <= (float) $stats['lowest']
			) {
				$badges[] = array(
					'kind'  => 'lowest',
					'icon'  => 'verified',
					'label' => '過去最安値',
				);
			}
		}
		?>
		<section class="node-library-single__pricing" aria-labelledby="node-library-pricing-title">
			<div class="node-library-single__section-heading">
				<span class="material-symbols-outlined" data-icon="sell" aria-hidden="true"></span>
				<h2 id="node-library-pricing-title"><?php esc_html_e( '価格・セール情報', 'node-library' ); ?></h2>
			</div>

			<?php if ( $badges ) : ?>
				<ul class="node-library-pricing__badges" aria-label="<?php esc_attr_e( 'ステータスバッジ', 'node-library' ); ?>">
					<?php foreach ( $badges as $badge ) : ?>
						<li class="node-library-pricing__badge node-library-pricing__badge--<?php echo esc_attr( $badge['kind'] ); ?>">
							<span class="material-symbols-outlined" data-icon="<?php echo esc_attr( $badge['icon'] ); ?>" aria-hidden="true"></span>
							<span><?php echo esc_html( $badge['label'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php foreach ( $entries as $entry ) : ?>
				<?php
				$stats        = $entry['stats'];
				$current      = $entry['current'] ?? $stats['current'];
				$currency     = (string) ( $current['currency'] ?? '' );
				$has_now      = null !== ( $current['price'] ?? null ) && '' !== (string) $current['price'];
				$on_sale      = null !== $stats['discount_percent'];
				$sale_end     = is_string( $stats['sale_end'] ?? null ) ? $stats['sale_end'] : '';
				$prev_row     = $has_now && $on_sale
					? self::previous_price_for_sale_animation( $current, null !== $stats['discount_percent'] ? (int) $stats['discount_percent'] : null, $entry['history'] )
					: null;
				$anim_pattern = null !== $prev_row ? self::sale_animation_pattern( $post_id ) : '';
				?>
				<div class="node-library-pricing__card">
					<h3 class="node-library-pricing__store"><?php echo esc_html( $entry['label'] ); ?></h3>
					<dl class="node-library-pricing__summary">
						<div class="node-library-pricing__metric">
							<dt><?php esc_html_e( '現在価格', 'node-library' ); ?></dt>
							<dd class="node-library-pricing__current">
								<?php if ( $has_now ) : ?>
									<?php $curr_label = self::format_currency( (string) $current['price'], $currency ); ?>
									<strong class="node-library-pricing__price-final"<?php
									if ( null !== $prev_row ) {
										$prev_label = self::format_currency( (string) $prev_row['price'], $currency );
										echo ' data-node-library-sale-anim="' . esc_attr( $anim_pattern ) . '"'
											. ' data-sale-prev-price="' . esc_attr( (string) (float) $prev_row['price'] ) . '"'
											. ' data-sale-curr-price="' . esc_attr( (string) (float) $current['price'] ) . '"'
											. ' data-sale-prev-label="' . esc_attr( $prev_label ) . '"'
											. ' data-sale-curr-label="' . esc_attr( $curr_label ) . '"'
											. ' data-sale-currency="' . esc_attr( $currency ) . '"';
									}
									?>><?php echo esc_html( $curr_label ); ?></strong>
									<?php if ( $on_sale && null !== ( $current['list_price'] ?? null ) ) : ?>
										<del class="node-library-pricing__list-price"><?php echo esc_html( self::format_currency( (string) $current['list_price'], $currency ) ); ?></del>
										<span class="node-library-pricing__discount"><?php echo esc_html( sprintf( '-%d%%', (int) $stats['discount_percent'] ) ); ?></span>
									<?php endif; ?>
								<?php else : ?>
									<span><?php esc_html_e( '価格情報なし', 'node-library' ); ?></span>
								<?php endif; ?>
							</dd>
						</div>

						<?php if ( null !== $stats['lowest'] && '' !== (string) $stats['lowest'] ) : ?>
							<div class="node-library-pricing__metric">
								<dt><?php esc_html_e( '過去最安値', 'node-library' ); ?></dt>
								<dd><?php echo esc_html( self::format_currency( (string) $stats['lowest'], $currency ) ); ?></dd>
							</div>
						<?php endif; ?>

						<div class="node-library-pricing__metric">
							<dt><?php esc_html_e( '過去1年のセール回数', 'node-library' ); ?></dt>
							<dd><?php echo esc_html( sprintf( '%d回', (int) $stats['sales_last_year'] ) ); ?></dd>
						</div>

						<?php if ( '' !== $sale_end ) : ?>
							<div class="node-library-pricing__metric">
								<dt>
									<span class="material-symbols-outlined" data-icon="schedule" aria-hidden="true"></span>
									<?php esc_html_e( 'セール終了予定', 'node-library' ); ?>
								</dt>
								<dd><?php echo esc_html( self::format_jst_datetime( $sale_end ) ); ?></dd>
							</div>
						<?php endif; ?>
					</dl>

					<?php if ( $entry['history'] ) : ?>
						<div class="node-library-pricing__history">
							<h4><?php esc_html_e( '直近の価格変動履歴', 'node-library' ); ?></h4>
							<table class="node-library-pricing__table">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( '記録日時（JST）', 'node-library' ); ?></th>
										<th scope="col"><?php esc_html_e( '価格', 'node-library' ); ?></th>
										<th scope="col"><?php esc_html_e( '定価', 'node-library' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $entry['history'] as $row ) : ?>
										<?php
										$row_currency = (string) ( $row['currency'] ?? $currency );
										$row_price    = null !== ( $row['price'] ?? null ) && '' !== (string) $row['price']
											? self::format_currency( (string) $row['price'], $row_currency )
											: '—';
										$row_list     = null !== ( $row['list_price'] ?? null ) && '' !== (string) $row['list_price']
											? self::format_currency( (string) $row['list_price'], $row_currency )
											: '—';
										?>
										<tr>
											<td><?php echo esc_html( self::format_jst_datetime( (string) ( $row['recorded_at'] ?? '' ) ) ); ?></td>
											<td><?php echo esc_html( $row_price ); ?></td>
											<td><?php echo esc_html( $row_list ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * Render bidirectional related editions/DLC section after store links.
	 * Only published in-site node_library items are displayed.
	 */
	public static function render_relations_section( int $post_id ): void {
		$items = self::get_related_items( $post_id );
		if ( ! $items ) {
			return;
		}
		?>
		<section class="node-library-single__relations" aria-labelledby="node-library-relations-title">
			<div class="node-library-single__section-heading">
				<span class="material-symbols-outlined" data-icon="inventory_2" aria-hidden="true"></span>
				<h2 id="node-library-relations-title"><?php esc_html_e( '関連エディション・DLC', 'node-library' ); ?></h2>
			</div>
			<ul class="node-library-relations__list">
				<?php foreach ( $items as $item ) : ?>
					<li class="node-library-relations__item">
						<a class="node-library-relations__link" href="<?php echo esc_url( $item['url'] ); ?>">
							<span class="node-library-relations__badge"><?php echo esc_html( $item['label'] ); ?></span>
							<span class="node-library-relations__title"><?php echo esc_html( $item['title'] ); ?></span>
							<span class="material-symbols-outlined node-library-relations__chevron" data-icon="chevron_right" aria-hidden="true"></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * Render published articles linked to resolved related editions/DLC,
	 * excluding articles already shown in the primary article list.
	 */
	public static function render_related_articles_section( int $post_id ): void {
		$related_items = self::get_related_items( $post_id );
		if ( ! $related_items ) {
			return;
		}

		$related_ids = array_values( array_unique( array_map( static fn( $item ) => (int) $item['post_id'], $related_items ) ) );
		if ( ! $related_ids ) {
			return;
		}

		$primary_article_ids = self::primary_linked_article_ids( $post_id );
		$matched_ids         = self::find_linked_article_ids( $related_ids, 12, $primary_article_ids );
		if ( ! $matched_ids ) {
			return;
		}

		$query = new WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'post__in'            => $matched_ids,
				'posts_per_page'      => count( $matched_ids ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'orderby'             => 'post__in',
			)
		);
		update_post_thumbnail_cache( $query );
		$articles = array_values(
			array_filter(
				$query->posts,
				static fn( $p ) => $p instanceof WP_Post && '' !== trim( $p->post_title )
			)
		);
		if ( ! $articles ) {
			return;
		}
		?>
		<section class="node-library-single__articles node-library-single__related-articles" aria-labelledby="node-library-related-articles-title">
			<header class="m3-date-group__header">
				<span class="m3-date-group__icon" aria-hidden="true"><span class="material-symbols-outlined" data-icon="article"></span></span>
				<h2 class="m3-date-group__title" id="node-library-related-articles-title"><?php esc_html_e( '関連エディション・DLCに関する記事', 'node-library' ); ?></h2>
				<span class="m3-date-group__count"><?php echo esc_html( number_format_i18n( count( $articles ) ) ); ?><?php esc_html_e( '件', 'node-library' ); ?></span>
			</header>
			<div class="node-library-single__article-list">
				<div class="m3-date-group__items">
					<?php foreach ( $articles as $article ) : ?>
						<a class="m3-date-item" href="<?php echo esc_url( get_permalink( $article ) ); ?>">
							<span class="m3-date-item__body"><span class="m3-date-item__title"><?php echo esc_html( get_the_title( $article ) ); ?></span></span>
							<span class="material-symbols-outlined m3-date-item__chevron" data-icon="chevron_right" aria-hidden="true"></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Build schema.org/Offer data from L3 price stats.
	 *
	 * @param mixed $offers  Existing offers value.
	 * @param int   $post_id Library item ID.
	 * @return mixed
	 */
	public static function filter_jsonld_offers( $offers, int $post_id ) {
		if ( is_array( $offers ) && $offers ) {
			return $offers;
		}
		if ( ! class_exists( 'Node_Library_Store' ) || ! class_exists( 'Node_Library_Prices' ) ) {
			return $offers;
		}

		$store_ids = get_post_meta( $post_id, '_node_library_store_ids', true );
		$store_ids = is_array( $store_ids ) ? $store_ids : array();
		$links     = get_post_meta( $post_id, '_node_library_links', true );
		$links     = is_array( $links ) ? $links : array();

		$built = array();
		foreach ( Node_Library_Store::price_stores() as $store ) {
			$external_id = isset( $store_ids[ $store ] ) && is_string( $store_ids[ $store ] ) ? trim( $store_ids[ $store ] ) : '';
			if ( '' === $external_id ) {
				continue;
			}
			$current = Node_Library_Prices::effective_offer( Node_Library_Prices::current( $post_id, $store ) );
			if ( ! is_array( $current ) ) {
				continue;
			}
			$raw_price = $current['price'] ?? null;
			$currency  = is_string( $current['currency'] ?? null ) ? trim( $current['currency'] ) : '';
			if ( null === $raw_price || '' === (string) $raw_price || '' === $currency ) {
				continue;
			}

			$offer = array(
				'@type'         => 'Offer',
				'price'         => self::format_jsonld_price( (string) $raw_price, $currency ),
				'priceCurrency' => $currency,
			);

			$sale_end = $current['sale_end'] ?? null;
			if ( is_string( $sale_end ) && '' !== $sale_end ) {
				$end_ts = strtotime( $sale_end . ' UTC' );
				if ( false !== $end_ts && $end_ts > time() ) {
					$offer['priceValidUntil'] = wp_date( 'Y-m-d', $end_ts, new DateTimeZone( 'Asia/Tokyo' ) );
				}
			}

			$url = self::resolve_offer_url( $store, $external_id, $links );
			if ( '' !== $url ) {
				$offer['url'] = $url;
			}

			$built[] = $offer;
		}

		if ( ! $built ) {
			$is_free = class_exists( 'Node_Library_Structured_Meta' )
				&& true === Node_Library_Structured_Meta::sanitize( 'is_free', get_post_meta( $post_id, '_node_library_is_free', true ) );
			if ( ! $is_free ) {
				return $offers;
			}
			$free_offer = array(
				'@type'         => 'Offer',
				'price'         => '0',
				'priceCurrency' => 'JPY',
			);
			$resolved_offers = self::store_offers( $post_id, $links );
			if ( ! empty( $resolved_offers[0]['url'] ) ) {
				$free_offer['url'] = $resolved_offers[0]['url'];
			}
			return $free_offer;
		}

		return 1 === count( $built ) ? $built[0] : $built;
	}

	/**
	 * Resolve bidirectional relations to published in-site node_library posts.
	 *
	 * @return array<int, array{post_id:int,title:string,url:string,relation:string,label:string}>
	 */
	public static function get_related_items( int $post_id ): array {
		if ( '1' !== get_option( 'node_library_relations_schema' ) ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'node_library_relations';

		$forward_labels = array(
			'dlc'             => 'DLC',
			'bundle_contains' => '収録コンテンツ',
			'included_in'     => '収録エディション／バンドル',
		);
		$reverse_labels = array(
			'dlc'             => '本編',
			'bundle_contains' => '収録エディション',
			'included_in'     => '収録コンテンツ',
		);

		$resolved = array();
		$seen     = array();

		// 1. Forward relations owned by $post_id.
		$forward_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT store, relation, external_id FROM {$table} WHERE item_id = %d",
				$post_id
			),
			ARRAY_A
		);
		if ( is_array( $forward_rows ) && $forward_rows ) {
			$pairs = array();
			foreach ( $forward_rows as $row ) {
				$store = (string) ( $row['store'] ?? '' );
				$ext   = (string) ( $row['external_id'] ?? '' );
				if ( '' !== $store && '' !== $ext ) {
					$pairs[ $store . ':' . $ext ] = array(
						'store'       => $store,
						'external_id' => $ext,
					);
				}
			}
			$map = self::resolve_external_ids_to_posts( array_values( $pairs ), $post_id );
			foreach ( $forward_rows as $row ) {
				$store    = (string) ( $row['store'] ?? '' );
				$ext      = (string) ( $row['external_id'] ?? '' );
				$relation = (string) ( $row['relation'] ?? '' );
				$key      = $store . ':' . $ext;
				if ( ! isset( $map[ $key ], $forward_labels[ $relation ] ) ) {
					continue;
				}
				$target_post = $map[ $key ];
				$dedupe_key  = $target_post->ID . ':' . $forward_labels[ $relation ];
				if ( isset( $seen[ $dedupe_key ] ) ) {
					continue;
				}
				$seen[ $dedupe_key ] = true;
				$resolved[]          = array(
					'post_id'  => (int) $target_post->ID,
					'title'    => get_the_title( $target_post ),
					'url'      => (string) get_permalink( $target_post ),
					'relation' => $relation,
					'label'    => $forward_labels[ $relation ],
				);
			}
		}

		// 2. Reverse relations referencing $post_id's store IDs.
		$store_ids = get_post_meta( $post_id, '_node_library_store_ids', true );
		if ( is_array( $store_ids ) && $store_ids ) {
			$conditions = array();
			$params     = array( $post_id );
			foreach ( $store_ids as $store => $ext ) {
				if ( is_string( $store ) && '' !== $store && is_string( $ext ) && '' !== trim( $ext ) ) {
					$conditions[] = '(store = %s AND external_id = %s)';
					$params[]     = $store;
					$params[]     = trim( $ext );
				}
			}
			if ( $conditions ) {
				$where_pairs  = implode( ' OR ', $conditions );
				$reverse_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT item_id, store, relation FROM {$table} WHERE item_id <> %d AND ({$where_pairs})",
						$params
					),
					ARRAY_A
				);
				if ( is_array( $reverse_rows ) ) {
					foreach ( $reverse_rows as $row ) {
						$source_id = (int) ( $row['item_id'] ?? 0 );
						$relation  = (string) ( $row['relation'] ?? '' );
						if ( $source_id <= 0 || ! isset( $reverse_labels[ $relation ] ) ) {
							continue;
						}
						$source_post = get_post( $source_id );
						if ( ! $source_post instanceof WP_Post || 'node_library' !== $source_post->post_type || 'publish' !== $source_post->post_status ) {
							continue;
						}
						$dedupe_key = $source_post->ID . ':' . $reverse_labels[ $relation ];
						if ( isset( $seen[ $dedupe_key ] ) ) {
							continue;
						}
						$seen[ $dedupe_key ] = true;
						$resolved[]          = array(
							'post_id'  => (int) $source_post->ID,
							'title'    => get_the_title( $source_post ),
							'url'      => (string) get_permalink( $source_post ),
							'relation' => $relation,
							'label'    => $reverse_labels[ $relation ],
						);
					}
				}
			}
		}

		return $resolved;
	}

	/**
	 * Resolve (store, external_id) pairs to published node_library WP_Post objects
	 * via LIKE candidate extraction + strict PHP comparison on _node_library_store_ids.
	 *
	 * @param array<int, array{store:string,external_id:string}> $pairs           Store ID pairs.
	 * @param int                                                $exclude_post_id Current post ID to exclude.
	 * @return array<string, WP_Post> Keyed by "{store}:{external_id}".
	 */
	private static function resolve_external_ids_to_posts( array $pairs, int $exclude_post_id ): array {
		if ( ! $pairs ) {
			return array();
		}

		global $wpdb;
		$like_clauses = array();
		$params       = array( $exclude_post_id );
		foreach ( $pairs as $pair ) {
			$like_clauses[] = 'm.meta_value LIKE %s';
			$params[]       = '%' . $wpdb->esc_like( $pair['external_id'] ) . '%';
		}

		$where_like    = implode( ' OR ', $like_clauses );
		$candidate_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE p.post_type = 'node_library'
				AND p.post_status = 'publish'
				AND p.ID <> %d
				AND m.meta_key = '_node_library_store_ids'
				AND ({$where_like})",
				$params
			)
		);

		if ( ! is_array( $candidate_ids ) || ! $candidate_ids ) {
			return array();
		}

		$candidate_ids = array_values( array_filter( array_map( 'absint', $candidate_ids ) ) );
		update_meta_cache( 'post', $candidate_ids );

		$needed = array();
		foreach ( $pairs as $pair ) {
			$needed[ $pair['store'] . ':' . $pair['external_id'] ] = $pair;
		}

		$resolved = array();
		foreach ( $candidate_ids as $cid ) {
			$meta = get_post_meta( $cid, '_node_library_store_ids', true );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			foreach ( $needed as $key => $pair ) {
				if ( isset( $resolved[ $key ] ) ) {
					continue;
				}
				if ( isset( $meta[ $pair['store'] ] ) && is_string( $meta[ $pair['store'] ] ) && $meta[ $pair['store'] ] === $pair['external_id'] ) {
					$post = get_post( $cid );
					if ( $post instanceof WP_Post && 'node_library' === $post->post_type && 'publish' === $post->post_status ) {
						$resolved[ $key ] = $post;
					}
				}
			}
		}

		return $resolved;
	}

	/**
	 * Fetch recent price history rows directly from {$wpdb->prefix}node_library_price_history.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function recent_price_history( int $post_id, string $store, string $external_id, int $limit = 10 ): array {
		if ( '1' !== get_option( 'node_library_price_schema' ) ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'node_library_price_history';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT price, list_price, currency, sale_end, recorded_at
				FROM {$table}
				WHERE item_id = %d AND store = %s AND external_id = %s
				ORDER BY recorded_at DESC, id DESC
				LIMIT %d",
				$post_id,
				$store,
				$external_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Collect article IDs already displayed in single-node_library.php's primary list (limit 12).
	 *
	 * @return array<int, int>
	 */
	/**
	 * 3つの紐付けメタキーに対する OR 三重 JOIN を単一 SQL IN 句へ統合する（H-8）。
	 *
	 * @param array<int, int> $library_ids 対象の node_library ID 配列。
	 * @param int             $limit       最大件数。
	 * @param array<int, int> $exclude_ids 除外する投稿 ID 配列。
	 * @return array<int, int>
	 */
	public static function find_linked_article_ids( array $library_ids, int $limit = 12, array $exclude_ids = array() ): array {
		$lib_values = array_values( array_unique( array_filter( array_map( 'intval', $library_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( ! $lib_values || $limit <= 0 ) {
			return array();
		}

		global $wpdb;
		$val_placeholders = implode( ',', array_fill( 0, count( $lib_values ), '%s' ) );
		$params           = array_map( 'strval', $lib_values );
		$exclude_sql      = '';
		$clean_exclude    = array_values( array_unique( array_filter( array_map( 'intval', $exclude_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( $clean_exclude ) {
			$ex_placeholders = implode( ',', array_fill( 0, count( $clean_exclude ), '%d' ) );
			$exclude_sql     = " AND p.ID NOT IN ({$ex_placeholders})";
			$params          = array_merge( $params, $clean_exclude );
		}
		$params[] = $limit;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE p.post_type = 'post'
				AND p.post_status = 'publish'
				AND m.meta_key IN ('_node_linked_library_id', '_node_linked_library_second_id', '_node_library_card_reference')
				AND m.meta_value IN ({$val_placeholders}){$exclude_sql}
				ORDER BY p.post_date DESC, p.ID DESC
				LIMIT %d",
				$params
			)
		);

		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}

	private static function primary_linked_article_ids( int $post_id ): array {
		return self::find_linked_article_ids( array( $post_id ), 12 );
	}

	/**
	 * Generate a canonical store URL when the store has a deterministic ID URL pattern.
	 */
	public static function store_url( string $store, string $id ): string {
		$id = trim( $id );
		if ( '' === $id ) {
			return '';
		}
		if ( 'nintendo' === $store && Node_Library_Nintendo_Store::valid_id( $id ) ) {
			return 'https://store-jp.nintendo.com/item/software/D' . $id;
		}
		if ( 'microsoft' === $store ) {
			return 'https://www.xbox.com/ja-JP/games/store/-/' . rawurlencode( $id );
		}
		if ( 'steam' === $store && preg_match( '/^[0-9]+$/D', $id ) ) {
			return 'https://store.steampowered.com/app/' . $id . '/';
		}
		if ( 'amazon_asin' === $store ) {
			return 'https://www.amazon.co.jp/dp/' . rawurlencode( $id );
		}
		if ( 'googleplay' === $store ) {
			return 'https://play.google.com/store/apps/details?id=' . rawurlencode( $id );
		}
		if ( 'apple' === $store && preg_match( '/^(?:id)?([0-9]+)$/D', $id, $m ) ) {
			return 'https://apps.apple.com/jp/app/id' . $m[1];
		}
		if ( 'epic' === $store && preg_match( '/^[A-Za-z0-9._\-]+$/D', $id ) ) {
			return 'https://store.epicgames.com/p/' . rawurlencode( $id );
		}

		return '';
	}

	/**
	 * Extract Epic Games Store product slug from a store.epicgames.com product URL.
	 */
	private static function extract_epic_slug( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		if ( 'store.epicgames.com' !== $host && 'www.store.epicgames.com' !== $host ) {
			return '';
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( preg_match( '~^/(?:[a-z]{2}(?:-[a-z]{2})?/)?p/([A-Za-z0-9._\-]+)(?:/|$)~iD', $path, $match ) ) {
			return $match[1];
		}
		return '';
	}

	/**
	 * Render the supported features chip section on single Library pages.
	 */
	public static function render_features_section( int $post_id ): void {
		$chips = self::get_feature_chips( $post_id );
		if ( ! $chips ) {
			return;
		}
		$groups = array();
		foreach ( $chips as $chip ) {
			$groups[ $chip['brand'] ][] = $chip;
		}
		$labels = array(
			'neutral' => __( '共通機能', 'node-library' ),
			'microsoft' => 'Xbox',
			'playstation' => 'PlayStation',
			'nintendo' => 'Nintendo',
			'steam' => 'Steam',
			'epic' => 'Epic Games Store',
		);
		?>
		<section class="node-library-single__features node-library-single__section" aria-labelledby="node-library-features-title">
			<div class="node-library-single__section-heading">
				<span class="material-symbols-outlined" data-icon="tune" aria-hidden="true"></span>
				<h2 id="node-library-features-title"><?php esc_html_e( '対応している機能', 'node-library' ); ?></h2>
				<span class="node-library-single__section-count"><?php echo esc_html( sprintf( __( '%s件', 'node-library' ), number_format_i18n( count( $chips ) ) ) ); ?></span>
			</div>
			<?php foreach ( $groups as $brand => $features ) : ?>
				<div class="library-feature-group">
					<h3><?php echo esc_html( $labels[ $brand ] ?? $brand ); ?></h3>
					<ul class="node-library-features__list">
						<?php foreach ( $features as $chip ) : ?>
							<?php
							$chip_classes = array(
								'node-library-feature-chip',
								'node-library-feature-chip--' . $chip['brand'],
							);
							if ( ! empty( $chip['sub_kind'] ) ) {
								$chip_classes[] = 'node-library-feature-chip--subscription';
								$chip_classes[] = 'node-library-feature-chip--' . $chip['sub_kind'];
							}
							if ( 'ended' === ( $chip['sub_status'] ?? '' ) ) {
								$chip_classes[] = 'node-library-feature-chip--ended';
							}
							?>
							<li class="<?php echo esc_attr( implode( ' ', $chip_classes ) ); ?>">
								<span class="material-symbols-outlined" data-icon="<?php echo esc_attr( $chip['icon'] ); ?>" aria-hidden="true"></span>
								<span><?php echo esc_html( $chip['label'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * Resolve supported feature chips from capabilities, subscriptions, and linked platforms.
	 *
	 * @return array<int, array{key:string,label:string,brand:string,icon:string}>
	 */
	public static function get_feature_chips( int $post_id ): array {
		$capabilities = class_exists( 'Node_Library_Structured_Meta' )
			? Node_Library_Structured_Meta::sanitize( 'capabilities', get_post_meta( $post_id, '_node_library_capabilities', true ) )
			: array();
		$subscriptions = class_exists( 'Node_Library_Structured_Meta' )
			? Node_Library_Structured_Meta::sanitize( 'subscriptions', get_post_meta( $post_id, '_node_library_subscriptions', true ) )
			: array();
		$platforms = self::detect_platforms( $post_id );

		$chips = array();

		if ( true === ( $capabilities['crossplay'] ?? null ) ) {
			$chips[] = array( 'key' => 'crossplay', 'label' => __( 'クロスプレイ', 'node-library' ), 'brand' => 'neutral', 'icon' => 'sync' );
		}
		if ( true === ( $capabilities['crosssave'] ?? null ) ) {
			$chips[] = array( 'key' => 'crosssave', 'label' => __( 'クロスセーブ', 'node-library' ), 'brand' => 'neutral', 'icon' => 'devices' );
		}
		if ( true === ( $capabilities['online'] ?? null ) ) {
			$chips[] = array( 'key' => 'online', 'label' => __( 'オンライン対応', 'node-library' ), 'brand' => 'neutral', 'icon' => 'travel_explore' );
		}

		$cloud_flag = $capabilities['cloud_streaming'] ?? null;
		if ( true === $cloud_flag ) {
			$chips[] = array( 'key' => 'cloud_streaming', 'label' => __( 'クラウドストリーミング', 'node-library' ), 'brand' => 'neutral', 'icon' => 'media_output' );
		} elseif ( null === $cloud_flag && ! empty( $platforms['geforcenow'] ) ) {
			$chips[] = array( 'key' => 'cloud_streaming', 'label' => __( 'GeForce NOW クラウド', 'node-library' ), 'brand' => 'neutral', 'icon' => 'media_output' );
		}

		if ( true === ( $capabilities['achievements'] ?? null ) ) {
			$achievement_platforms = array(
				'microsoft'   => __( 'Xbox 実績', 'node-library' ),
				'playstation' => __( 'PlayStation トロフィー', 'node-library' ),
				'steam'       => __( 'Steam 実績', 'node-library' ),
				'epic'        => __( 'Epic 実績', 'node-library' ),
			);
			$matched_ach = false;
			foreach ( $achievement_platforms as $brand => $label ) {
				if ( ! empty( $platforms[ $brand ] ) ) {
					$chips[]     = array( 'key' => 'achievements_' . $brand, 'label' => $label, 'brand' => $brand, 'icon' => 'verified' );
					$matched_ach = true;
				}
			}
			if ( ! $matched_ach ) {
				$chips[] = array( 'key' => 'achievements', 'label' => __( '実績／トロフィー', 'node-library' ), 'brand' => 'neutral', 'icon' => 'verified' );
			}
		}

		$sub_map = array(
			'gamepass'        => array( 'label' => 'Xbox / PC Game Pass', 'brand' => 'microsoft' ),
			'psplus'          => array( 'label' => 'PlayStation Plus', 'brand' => 'playstation' ),
			'psplus_freeplay' => array( 'label' => 'PlayStation Plus フリープレイ', 'brand' => 'playstation' ),
			'epic_free'       => array( 'label' => 'Epic Games Store 無料配布', 'brand' => 'epic' ),
			'nso'             => array( 'label' => 'Nintendo Switch Online', 'brand' => 'nintendo' ),
			'ea_play'         => array( 'label' => 'EA Play', 'brand' => 'neutral' ),
			'ubisoft_plus'    => array( 'label' => 'Ubisoft+', 'brand' => 'neutral' ),
			'apple_arcade'    => array( 'label' => 'Apple Arcade', 'brand' => 'neutral' ),
		);
		$display_badges = class_exists( 'Node_Library_Subscriptions' )
			? Node_Library_Subscriptions::get_display_badges( $post_id )
			: array();
		foreach ( $sub_map as $sub_key => $meta ) {
			if ( ! empty( $subscriptions[ $sub_key ] ) ) {
				$badge_info = $display_badges[ $sub_key ] ?? null;
				$is_giveaway = is_array( $badge_info ) && 'giveaway' === ( $badge_info['kind'] ?? '' );
				$chips[] = array(
					'key'        => 'sub_' . $sub_key,
					'label'      => is_array( $badge_info ) ? $badge_info['badge_text'] : sprintf( '%s（%s）', $meta['label'], __( '加入中', 'node-library' ) ),
					'brand'      => $meta['brand'],
					'icon'       => $is_giveaway ? 'sell' : 'apps',
					'sub_kind'   => is_array( $badge_info ) ? $badge_info['kind'] : 'catalog',
					'sub_status' => is_array( $badge_info ) ? $badge_info['status'] : 'active',
				);
			}
		}

		$resolution   = strtolower( trim( (string) ( $capabilities['resolution'] ?? '' ) ) );
		$auto_4k      = '' !== $resolution && ( str_contains( $resolution, '4k' ) || str_contains( $resolution, '3840' ) || str_contains( $resolution, '2160' ) );
		$explicit_4k  = $capabilities['uhd_4k'] ?? null;
		if ( true === $explicit_4k || ( null === $explicit_4k && $auto_4k ) ) {
			$chips[] = array( 'key' => 'uhd_4k', 'label' => __( '4K対応', 'node-library' ), 'brand' => 'neutral', 'icon' => 'image' );
		}

		$max_fps      = (int) ( $capabilities['max_fps'] ?? 0 );
		$auto_120     = $max_fps >= 120;
		$explicit_120 = $capabilities['fps_120'] ?? null;
		if ( true === $explicit_120 || ( null === $explicit_120 && $auto_120 ) ) {
			$chips[] = array( 'key' => 'fps_120', 'label' => __( '120fps対応', 'node-library' ), 'brand' => 'neutral', 'icon' => 'bolt' );
		}

		if ( true === ( $capabilities['hdr'] ?? null ) ) {
			$chips[] = array( 'key' => 'hdr', 'label' => __( 'HDR対応', 'node-library' ), 'brand' => 'neutral', 'icon' => 'check_circle' );
		}
		if ( true === ( $capabilities['ray_tracing'] ?? null ) ) {
			$chips[] = array( 'key' => 'ray_tracing', 'label' => __( 'レイトレーシング', 'node-library' ), 'brand' => 'neutral', 'icon' => 'auto_awesome' );
		}

		return $chips;
	}

	/**
	 * Detect linked platform/store slugs from store IDs and links.
	 *
	 * @return array<string, bool>
	 */
	private static function detect_platforms( int $post_id ): array {
		$found     = array();
		$store_ids = get_post_meta( $post_id, '_node_library_store_ids', true );
		if ( is_array( $store_ids ) ) {
			if ( ! empty( $store_ids['microsoft'] ) ) {
				$found['microsoft'] = true;
			}
			if ( ! empty( $store_ids['ps'] ) ) {
				$found['playstation'] = true;
			}
			if ( ! empty( $store_ids['nintendo'] ) ) {
				$found['nintendo'] = true;
			}
			if ( ! empty( $store_ids['steam'] ) ) {
				$found['steam'] = true;
			}
			if ( ! empty( $store_ids['epic'] ) ) {
				$found['epic'] = true;
			}
		}

		$links = get_post_meta( $post_id, '_node_library_links', true );
		if ( is_array( $links ) ) {
			foreach ( $links as $link ) {
				if ( ! is_array( $link ) ) {
					continue;
				}
				$url      = (string) ( $link['url'] ?? '' );
				$host     = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				$platform = strtolower( (string) ( $link['platform'] ?? '' ) );
				$hardware = (string) ( $link['hardware'] ?? '' );

				if ( in_array( $hardware, array( 'xbox-one', 'xbox-series', 'xbox-crossgen' ), true ) || false !== strpos( $host, 'xbox.com' ) || false !== strpos( $platform, 'xbox' ) ) {
					$found['microsoft'] = true;
				}
				if ( in_array( $hardware, array( 'playstation-4', 'playstation-5', 'playstation-crossgen' ), true ) || false !== strpos( $host, 'playstation.com' ) || false !== strpos( $platform, 'playstation' ) || preg_match( '/(^|\s)ps([345]|\s+store)/', $platform ) ) {
					$found['playstation'] = true;
				}
				if ( in_array( $hardware, array( 'nintendo-switch', 'nintendo-switch-2' ), true ) || false !== strpos( $host, 'nintendo.com' ) || false !== strpos( $platform, 'nintendo' ) || false !== strpos( $platform, 'switch' ) ) {
					$found['nintendo'] = true;
				}
				if ( false !== strpos( $host, 'steampowered.com' ) || false !== strpos( $platform, 'steam' ) ) {
					$found['steam'] = true;
				}
				if ( false !== strpos( $host, 'epicgames.com' ) || false !== strpos( $platform, 'epic' ) ) {
					$found['epic'] = true;
				}
				if ( false !== strpos( $host, 'nvidia.com' ) || false !== strpos( $platform, 'geforce' ) ) {
					$found['geforcenow'] = true;
				}
			}
		}

		return $found;
	}

	/**
	 * Resolve the immediately preceding price history row when the current sale price is lower.
	 *
	 * @param array<string, mixed>             $current          Current observation row.
	 * @param int|null                         $discount_percent Active discount percentage.
	 * @param array<int, array<string, mixed>> $history          Recent history ordered newest-first.
	 * @return array<string, mixed>|null
	 */
	private static function previous_price_for_sale_animation( array $current, ?int $discount_percent, array $history ): ?array {
		if ( null === $discount_percent || $discount_percent <= 0 ) {
			return null;
		}
		$curr_price    = $current['price'] ?? null;
		$curr_currency = strtoupper( trim( (string) ( $current['currency'] ?? '' ) ) );
		if ( ! is_numeric( $curr_price ) || '' === $curr_currency ) {
			return null;
		}

		if ( count( $history ) >= 2 ) {
			$prev          = $history[1] ?? null;
			$prev_price    = is_array( $prev ) ? ( $prev['price'] ?? null ) : null;
			$prev_currency = is_array( $prev ) ? strtoupper( trim( (string) ( $prev['currency'] ?? '' ) ) ) : '';
			if ( is_numeric( $prev_price ) && $prev_currency === $curr_currency && (float) $curr_price < (float) $prev_price ) {
				return $prev;
			}
		}

		// 履歴1件目の初回セールでも定価（list_price）が現在価格より高ければ旧価格としてアニメーション可能にする（A-9）。
		$list_price = $current['list_price'] ?? null;
		if ( is_numeric( $list_price ) && (float) $curr_price < (float) $list_price ) {
			return array(
				'price'    => $list_price,
				'currency' => $curr_currency,
			);
		}

		return null;
	}

	/**
	 * Determine the active sale price animation pattern ('a' = 3D drum picker, 'b' = canvas particle dispersion).
	 */
	public static function sale_animation_pattern( int $post_id ): string {
		$option  = (string) get_option( 'node_library_sale_animation_pattern', 'a' );
		$pattern = apply_filters( 'node_library_sale_animation_pattern', $option, $post_id );
		return self::sanitize_sale_animation_pattern( $pattern );
	}

	/**
	 * Normalize animation pattern identifier to 'a' or 'b'.
	 *
	 * @param mixed $value Raw option or filter value.
	 */
	public static function sanitize_sale_animation_pattern( $value ): string {
		$normalized = is_string( $value ) ? strtolower( trim( $value ) ) : 'a';
		return 'b' === $normalized ? 'b' : 'a';
	}

	/** Register display settings submenu under Luna Library. */
	public static function register_settings_page(): void {
		add_submenu_page(
			'edit.php?post_type=node_library',
			__( 'Luna Library 表示設定', 'node-library' ),
			__( '表示設定', 'node-library' ),
			'manage_options',
			'node-library-display-settings',
			array( self::class, 'render_settings_page' )
		);
	}

	/** Register plugin display options. */
	public static function register_settings(): void {
		register_setting(
			'node_library_display_settings',
			'node_library_sale_animation_pattern',
			array(
				'type'              => 'string',
				'default'           => 'a',
				'sanitize_callback' => array( self::class, 'sanitize_sale_animation_pattern' ),
			)
		);
	}

	/** Render display settings form in WP Admin. */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$current = self::sanitize_sale_animation_pattern( get_option( 'node_library_sale_animation_pattern', 'a' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Luna Library 表示設定', 'node-library' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'node_library_display_settings' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'セール時の価格アニメーション', 'node-library' ); ?></th>
							<td>
								<fieldset>
									<p>
										<label>
											<input type="radio" name="node_library_sale_animation_pattern" value="a"<?php checked( $current, 'a' ); ?>>
											<?php esc_html_e( 'パターンA：ドラム式ピッカー（縦スクロール／3D回転・デフォルト）', 'node-library' ); ?>
										</label>
									</p>
									<p>
										<label>
											<input type="radio" name="node_library_sale_animation_pattern" value="b"<?php checked( $current, 'b' ); ?>>
											<?php esc_html_e( 'パターンB：粒子霧散（Canvas で旧価格を霧散させて新価格を表示）', 'node-library' ); ?>
										</label>
									</p>
								</fieldset>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Resolve an Offer URL from deterministic store URL generation or stored _node_library_links.
	 *
	 * @param array<int, mixed> $links Stored links meta.
	 */
	private static function resolve_offer_url( string $store, string $external_id, array $links ): string {
		$generated = self::store_url( $store, $external_id );
		if ( '' !== $generated ) {
			return $generated;
		}
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$url = esc_url_raw( (string) ( $link['url'] ?? '' ) );
			$product = Node_Library_Store_ID_Migration::extract( $url );
			if ( $store === ( $product['store'] ?? '' ) && $external_id === ( $product['id'] ?? '' ) ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Format a decimal price string for human display.
	 */
	private static function format_currency( string $raw_price, string $currency ): string {
		$amount = (float) $raw_price;
		if ( 'JPY' === strtoupper( $currency ) ) {
			return '¥' . number_format_i18n( (int) round( $amount ) );
		}
		$formatted = number_format( $amount, 2, '.', ',' );
		return '' !== $currency ? strtoupper( $currency ) . ' ' . $formatted : $formatted;
	}

	/**
	 * Format a decimal price string for schema.org/Offer.price.
	 */
	private static function format_jsonld_price( string $raw_price, string $currency ): string {
		$amount = (float) $raw_price;
		if ( 'JPY' === strtoupper( $currency ) ) {
			return (string) (int) round( $amount );
		}
		$formatted = rtrim( rtrim( number_format( $amount, 4, '.', '' ), '0' ), '.' );
		return '' !== $formatted ? $formatted : '0';
	}

	/**
	 * Format a UTC MySQL datetime string in Asia/Tokyo (JST) using wp_date().
	 */
	private static function format_jst_datetime( string $utc_datetime ): string {
		$utc_datetime = trim( $utc_datetime );
		if ( '' === $utc_datetime ) {
			return '';
		}
		$timestamp = strtotime( $utc_datetime . ' UTC' );
		if ( false === $timestamp ) {
			return $utc_datetime;
		}
		return wp_date( 'Y/m/d H:i', $timestamp, new DateTimeZone( 'Asia/Tokyo' ) );
	}
}
