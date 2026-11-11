<?php
/**
 * Luna Products — 管理画面設定（アフィリエイト ID・ディスクロージャー文言）
 *
 * @package Luna_Products
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Amazon ディスクロージャーのデフォルト文言（Luminous Nexus 準拠）を返す。
 *
 * @return string
 */
function luna_products_default_disclosure_amazon(): string {
	return 'Amazonのアソシエイトとして、当メディアは適格販売により収入を得ています。';
}

/**
 * 楽天ディスクロージャーのデフォルト文言（Luminous Nexus 準拠）を返す。
 *
 * @return string
 */
function luna_products_default_disclosure_rakuten(): string {
	return '当メディアは、楽天アフィリエイト・プログラムの参加者です。';
}

/**
 * 設定項目を WordPress に登録する。
 *
 * @return void
 */
function luna_products_register_settings(): void {
	register_setting(
		'luna_products_options',
		'luna_products_amazon_id',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	register_setting(
		'luna_products_options',
		'luna_products_rakuten_id',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	register_setting(
		'luna_products_options',
		'luna_products_disclosure_amazon',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => luna_products_default_disclosure_amazon(),
		)
	);

	register_setting(
		'luna_products_options',
		'luna_products_disclosure_rakuten',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => luna_products_default_disclosure_rakuten(),
		)
	);
}
add_action( 'admin_init', 'luna_products_register_settings' );

/**
 * 管理画面メニューに「Luna Products」設定ページを追加する。
 *
 * @return void
 */
function luna_products_add_settings_menu(): void {
	add_options_page(
		__( 'Luna Products 設定', 'luna-products' ),
		__( 'Luna Products', 'luna-products' ),
		'manage_options',
		'luna-products',
		'luna_products_render_settings_page'
	);
}
add_action( 'admin_menu', 'luna_products_add_settings_menu' );

/**
 * 設定画面の HTML を出力する。
 *
 * @return void
 */
function luna_products_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$amazon_id   = (string) get_option( 'luna_products_amazon_id', '' );
	$rakuten_id  = (string) get_option( 'luna_products_rakuten_id', '' );
	$disc_amazon = (string) get_option( 'luna_products_disclosure_amazon', luna_products_default_disclosure_amazon() );
	$disc_rakuten = (string) get_option( 'luna_products_disclosure_rakuten', luna_products_default_disclosure_rakuten() );
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Luna Products 設定', 'luna-products' ); ?></h1>
		<p><?php echo esc_html__( '商品カードブロック（luna-products/card）で使用するアフィリエイト ID とディスクロージャー文言を設定します。', 'luna-products' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'luna_products_options' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="luna_products_amazon_id"><?php echo esc_html__( 'Amazon トラッキング ID', 'luna-products' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="luna_products_amazon_id"
							name="luna_products_amazon_id"
							value="<?php echo esc_attr( $amazon_id ); ?>"
							class="regular-text"
							placeholder="example-22"
						/>
						<p class="description"><?php echo esc_html__( 'Amazon アソシエイトのトラッキング ID（例: example-22）。ASIN または Amazon URL に ?tag= として自動付与されます。', 'luna-products' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="luna_products_rakuten_id"><?php echo esc_html__( '楽天アフィリエイト ID', 'luna-products' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="luna_products_rakuten_id"
							name="luna_products_rakuten_id"
							value="<?php echo esc_attr( $rakuten_id ); ?>"
							class="regular-text"
						/>
						<p class="description"><?php echo esc_html__( '楽天アフィリエイトのアフィリエイト ID（任意）。設定時は楽天市場のリンクに自動適用されます。', 'luna-products' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="luna_products_disclosure_amazon"><?php echo esc_html__( 'Amazon ディスクロージャー文言', 'luna-products' ); ?></label>
					</th>
					<td>
						<textarea
							id="luna_products_disclosure_amazon"
							name="luna_products_disclosure_amazon"
							rows="2"
							class="large-text"
						><?php echo esc_textarea( $disc_amazon ); ?></textarea>
						<p class="description"><?php echo esc_html__( 'Amazon のストア行が表示されるカードの下部に自動出力されます。', 'luna-products' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="luna_products_disclosure_rakuten"><?php echo esc_html__( '楽天 ディスクロージャー文言', 'luna-products' ); ?></label>
					</th>
					<td>
						<textarea
							id="luna_products_disclosure_rakuten"
							name="luna_products_disclosure_rakuten"
							rows="2"
							class="large-text"
						><?php echo esc_textarea( $disc_rakuten ); ?></textarea>
						<p class="description"><?php echo esc_html__( '楽天市場のストア行が表示されるカードの下部に自動出力されます。', 'luna-products' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
