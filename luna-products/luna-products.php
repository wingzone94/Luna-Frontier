<?php
/**
 * Plugin Name: Luna Products
 * Description: 商品画像・価格比較・複数ストア導線に対応する「商品カード」ブロック。
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Luminous Core
 * License: GPL-2.0-or-later
 * Text Domain: luna-products
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'luna_products_register_block' ) ) {

	define( 'LUNA_PRODUCTS_VERSION', '1.0.0' );
	define(
		'LUNA_PRODUCTS_URL',
		defined( 'LUNA_PRODUCTS_EMBEDDED' ) && LUNA_PRODUCTS_EMBEDDED
			? get_template_directory_uri() . '/plugins-embedded/luna-products/'
			: plugin_dir_url( __FILE__ )
	);

	require_once __DIR__ . '/includes/stores.php';
	require_once __DIR__ . '/includes/settings.php';

	/**
	 * ブロック属性スキーマを返す。
	 *
	 * @return array<string, array<string, mixed>>
	 */
	function luna_products_attributes(): array {
		return array(
			'title'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'spec'      => array(
				'type'    => 'string',
				'default' => '',
			),
			'imageId'   => array(
				'type'    => 'number',
				'default' => 0,
			),
			'imageUrl'  => array(
				'type'    => 'string',
				'default' => '',
			),
			'asin'      => array(
				'type'    => 'string',
				'default' => '',
			),
			'checkedAt' => array(
				'type'    => 'string',
				'default' => '',
			),
			'stores'    => array(
				'type'    => 'array',
				'default' => luna_products_default_stores(),
			),
		);
	}

	/**
	 * ブロックとアセットを登録する。
	 *
	 * @return void
	 */
	function luna_products_register_block(): void {
		$url = LUNA_PRODUCTS_URL;
		$v   = LUNA_PRODUCTS_VERSION;

		wp_register_script(
			'luna-products-editor',
			$url . 'assets/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
			$v,
			true
		);

		wp_add_inline_script(
			'luna-products-editor',
			'window.LunaProducts = ' . wp_json_encode(
				array(
					'presets'       => array_values( luna_products_store_presets() ),
					'defaultStores' => luna_products_default_stores(),
				)
			) . ';',
			'before'
		);

		wp_register_style(
			'luna-products-style',
			$url . 'assets/style.css',
			array(),
			$v
		);

		wp_register_style(
			'luna-products-editor',
			$url . 'assets/editor.css',
			array( 'wp-edit-blocks', 'luna-products-style' ),
			$v
		);

		register_block_type(
			'luna-products/card',
			array(
				'api_version'     => 3,
				'editor_script'   => 'luna-products-editor',
				'editor_style'    => 'luna-products-editor',
				'style'           => 'luna-products-style',
				'attributes'      => luna_products_attributes(),
				'render_callback' => 'luna_products_render_card',
				'supports'        => array(
					'html' => false,
				),
			)
		);
	}
	add_action( 'init', 'luna_products_register_block' );

	/**
	 * 商品カードブロック（luna-products/card）をサーバーサイド描画する。
	 *
	 * @param array<string, mixed> $attributes ブロック属性。
	 * @return string 描画された HTML。
	 */
	function luna_products_render_card( array $attributes ): string {
		$title      = trim( wp_strip_all_tags( (string) ( $attributes['title'] ?? '' ) ) );
		$spec       = trim( wp_strip_all_tags( (string) ( $attributes['spec'] ?? '' ) ) );
		$asin       = trim( wp_strip_all_tags( (string) ( $attributes['asin'] ?? '' ) ) );
		$checked_at = trim( wp_strip_all_tags( (string) ( $attributes['checkedAt'] ?? '' ) ) );

		$image_url = '';
		$image_id  = isset( $attributes['imageId'] ) ? (int) $attributes['imageId'] : 0;
		if ( $image_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
			$resolved = wp_get_attachment_image_url( $image_id, 'large' );
			if ( is_string( $resolved ) && '' !== $resolved ) {
				$image_url = $resolved;
			}
		}
		if ( '' === $image_url && ! empty( $attributes['imageUrl'] ) && is_string( $attributes['imageUrl'] ) ) {
			$image_url = trim( $attributes['imageUrl'] );
		}

		$amazon_id    = (string) get_option( 'luna_products_amazon_id', '' );
		$rakuten_id   = (string) get_option( 'luna_products_rakuten_id', '' );
		$disc_amazon  = trim( (string) get_option( 'luna_products_disclosure_amazon', luna_products_default_disclosure_amazon() ) );
		$disc_rakuten = trim( (string) get_option( 'luna_products_disclosure_rakuten', luna_products_default_disclosure_rakuten() ) );

		$raw_stores = isset( $attributes['stores'] ) && is_array( $attributes['stores'] )
			? $attributes['stores']
			: array();

		$prepared    = luna_products_prepare_rows( $raw_stores, $asin, $amazon_id, $rakuten_id );
		$rows        = $prepared['rows'];
		$has_amazon  = $prepared['has_amazon'];
		$has_rakuten = $prepared['has_rakuten'];

		if ( empty( $rows ) && '' === $title && '' === $image_url ) {
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return '<div class="luna-product-card-container"><div class="luna-product-card luna-product-card--placeholder"><p class="luna-product-card__empty">' . esc_html__( '商品名・画像・ストア行（URL または価格）を入力するとプレビューが表示されます。', 'luna-products' ) . '</p></div></div>';
			}
			return '';
		}

		$wrapper_attributes = function_exists( 'get_block_wrapper_attributes' ) && ( function_exists( 'has_blocks' ) && isset( $GLOBALS['wp_current_filter'] ) )
			? get_block_wrapper_attributes( array( 'class' => 'luna-product-card-container' ) )
			: 'class="wp-block-luna-products-card luna-product-card-container"';

		if ( '' !== $checked_at ) {
			$checked_label = str_contains( $checked_at, '時点' ) ? $checked_at : $checked_at . ' 時点の価格';
			$note_text     = sprintf( '※表示価格は税込です（%s）。価格・セール期間は表示時点のものです。', $checked_label );
		} else {
			$note_text = '※表示価格は税込です。価格・セール期間は表示時点のものです。';
		}

		$show_amazon_disc  = $has_amazon && '' !== $disc_amazon;
		$show_rakuten_disc = $has_rakuten && '' !== $disc_rakuten;

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; ?>>
			<div class="luna-product-card">
				<?php if ( '' !== $image_url ) : ?>
					<div class="luna-product-card__media">
						<img
							src="<?php echo esc_url( $image_url ); ?>"
							alt="<?php echo esc_attr( $title ); ?>"
							loading="lazy"
							decoding="async"
						/>
					</div>
				<?php endif; ?>

				<div class="luna-product-card__body">
					<?php if ( '' !== $title || '' !== $spec ) : ?>
						<div class="luna-product-card__header">
							<?php if ( '' !== $title ) : ?>
								<h4 class="luna-product-card__title"><?php echo esc_html( $title ); ?></h4>
							<?php endif; ?>
							<?php if ( '' !== $spec ) : ?>
								<p class="luna-product-card__spec"><?php echo esc_html( $spec ); ?></p>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $rows ) ) : ?>
						<div class="luna-product-card__stores" role="list">
							<?php foreach ( $rows as $row ) :
								$row_classes = 'luna-product-store luna-product-store--' . $row['preset'];
								if ( ! empty( $row['is_cheapest'] ) ) {
									$row_classes .= ' luna-product-store--cheapest';
								}
								$btn_style = ( 'custom' === $row['preset'] && '' !== $row['custom_color'] )
									? 'background-color:' . $row['custom_color'] . ';color:#ffffff;'
									: '';
								?>
								<div class="<?php echo esc_attr( $row_classes ); ?>" role="listitem">
									<div class="luna-product-store__info">
										<div class="luna-product-store__main">
											<div class="luna-product-store__identity">
												<span class="luna-product-store__name"><?php echo esc_html( $row['label'] ); ?></span>
												<?php if ( ! empty( $row['official'] ) ) : ?>
													<span class="luna-product-badge luna-product-badge--official"><?php echo esc_html__( '公式', 'luna-products' ); ?></span>
												<?php endif; ?>
												<?php if ( ! empty( $row['is_cheapest'] ) ) : ?>
													<span class="luna-product-badge luna-product-badge--cheapest"><?php echo esc_html__( '最安', 'luna-products' ); ?></span>
												<?php endif; ?>
											</div>

											<?php if ( $row['price'] > 0 ) : ?>
												<div class="luna-product-store__pricing">
													<span class="luna-product-store__price"><?php echo esc_html( number_format( (int) $row['price'] ) . '円' ); ?></span>
													<?php if ( $row['off_percent'] > 0 ) : ?>
														<s class="luna-product-store__list-price"><?php echo esc_html( '定価' . number_format( (int) $row['list_price'] ) . '円' ); ?></s>
														<span class="luna-product-badge luna-product-badge--off"><?php echo esc_html( (string) $row['off_percent'] . '% OFF' ); ?></span>
													<?php endif; ?>
												</div>
											<?php endif; ?>
										</div>

										<?php if ( '' !== $row['sale_note'] ) : ?>
											<p class="luna-product-store__sale-note"><?php echo esc_html( $row['sale_note'] ); ?></p>
										<?php endif; ?>
									</div>

									<?php if ( '' !== $row['url'] ) : ?>
										<div class="luna-product-store__action">
											<a
												href="<?php echo esc_url( $row['url'] ); ?>"
												class="luna-product-btn luna-product-btn--<?php echo esc_attr( $row['preset'] ); ?>"
												<?php if ( '' !== $btn_style ) : ?>style="<?php echo esc_attr( $btn_style ); ?>"<?php endif; ?>
												target="_blank"
												rel="sponsored noopener noreferrer"
											>
												<span class="luna-product-btn__label"><?php echo esc_html( sprintf( __( '%sで見る', 'luna-products' ), $row['label'] ) ); ?></span>
												<svg class="luna-product-btn__chevron" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M9.29 6.71a1 1 0 0 0 0 1.41L13.17 12l-3.88 3.88a1 1 0 1 0 1.41 1.41l4.59-4.59a1 1 0 0 0 0-1.41L10.7 6.7a1 1 0 0 0-1.41.01z"/></svg>
											</a>
										</div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<p class="luna-product-card__note"><?php echo esc_html( $note_text ); ?></p>
				</div>
			</div>

			<?php if ( $show_amazon_disc || $show_rakuten_disc ) : ?>
				<div class="luna-product-card__disclosures">
					<?php if ( $show_amazon_disc ) : ?>
						<p class="luna-product-card__disclosure" data-network="amazon"><?php echo esc_html( $disc_amazon ); ?></p>
					<?php endif; ?>
					<?php if ( $show_rakuten_disc ) : ?>
						<p class="luna-product-card__disclosure" data-network="rakuten"><?php echo esc_html( $disc_rakuten ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
