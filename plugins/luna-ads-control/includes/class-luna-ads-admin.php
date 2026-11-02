<?php
/**
 * 管理画面（設定8項目・記事単位の除外・競合警告）。
 *
 * @package Luna_Ads_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理画面のUIと保存処理。
 */
final class Luna_Ads_Admin {

	const PAGE_SLUG = 'luna-ads-control';

	/** @var string|false Registered page hook, including its current parent menu. */
	private static $page_hook = '';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 80 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_post_meta' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_filter( 'option_page_capability_luna_ads_control', array( __CLASS__, 'settings_capability' ) );
	}

	/**
	 * 設定ページの権限。
	 *
	 * @return string
	 */
	public static function settings_capability() {
		$capability = apply_filters( 'luna_ads_capability', 'manage_options' );
		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Luna Settings → Luna Ads Center。
	 *
	 * @return void
	 */
	public static function add_menu() {
		$parent = isset( $GLOBALS['admin_page_hooks']['luminous-settings'] ) ? 'luminous-settings' : 'options-general.php';
		self::$page_hook = add_submenu_page(
			$parent,
			'Luna Ads Center',
			'Luna Ads Center',
			self::settings_capability(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
		// Keep bookmarks to the former Settings page working.
		if ( 'luminous-settings' === $parent && 'options-general.php' === ( $GLOBALS['pagenow'] ?? '' ) && isset( $_GET['page'] ) && is_string( $_GET['page'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) && Luna_Ads_Settings::user_can_manage() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}
	}

	/**
	 * 設定を登録する。
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'luna_ads_control',
			Luna_Ads_Settings::OPTION,
			array(
				'type'              => 'array',
				'default'           => Luna_Ads_Settings::defaults(),
				'sanitize_callback' => array( 'Luna_Ads_Settings', 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * 設定画面でだけ管理用CSSを読み込む。
	 *
	 * @param string $hook_suffix 現在の管理画面フック。
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( self::$page_hook !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'luna-ads-control-admin',
			LUNA_ADS_CONTROL_URL . 'assets/admin.css',
			array(),
			LUNA_ADS_CONTROL_VERSION
		);
	}

	/**
	 * 設定画面を描画する。
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! Luna_Ads_Settings::user_can_manage() ) {
			wp_die( esc_html__( 'この設定を変更する権限がありません。', 'luna-ads-control' ) );
		}

		$settings = Luna_Ads_Settings::get();
		$slots    = $settings['slots'];
		$exclude  = $settings['exclude'];
		?>
		<div class="wrap luna-ads-admin">
			<header class="luna-ads-hero">
				<div>
					<p class="luna-ads-eyebrow">Luna Frontier · Monetization</p>
					<h1>Luna Ads Center</h1>
					<p class="luna-ads-lead">広告枠の配信を一元管理します。初期状態では配信しません。未設定の枠は空白のプレースホルダーも出力しません。</p>
				</div>
				<div class="luna-ads-hero__status" aria-live="polite">
					<span class="luna-ads-chip <?php echo $settings['enabled'] ? 'is-on' : 'is-off'; ?>">
						<span class="luna-ads-chip__dot" aria-hidden="true"></span>
						<?php echo $settings['enabled'] ? '広告配信: 有効' : '広告配信: 無効'; ?>
					</span>
				</div>
			</header>

			<?php self::render_status( $settings ); ?>
			<?php settings_errors(); ?>

			<form method="post" action="options.php" class="luna-ads-form">
				<?php settings_fields( 'luna_ads_control' ); ?>

				<section class="luna-ads-card" aria-labelledby="luna-ads-sec-1">
					<div class="luna-ads-card__head">
						<span class="luna-ads-step" aria-hidden="true">1</span>
						<div>
							<h2 id="luna-ads-sec-1">広告全体の有効・無効</h2>
							<p>オフの間は、広告コード・ローダー・CSSを一切出力しません。</p>
						</div>
					</div>
					<label class="luna-ads-switch-row" for="luna-ads-enabled">
						<span>
							<strong>広告配信を有効にする</strong>
							<span class="luna-ads-help">オフのままでも安全に設置できます。</span>
						</span>
						<input type="checkbox" id="luna-ads-enabled" class="luna-ads-switch"
							name="luna_ads_settings[enabled]" value="1" <?php checked( $settings['enabled'] ); ?> />
					</label>
				</section>

				<section class="luna-ads-card" aria-labelledby="luna-ads-sec-2">
					<div class="luna-ads-card__head">
						<span class="luna-ads-step" aria-hidden="true">2</span>
						<div>
							<h2 id="luna-ads-sec-2">広告コードの管理元</h2>
							<p>Site Kit など既存の管理元と競合しないよう、出力元を1つに固定します。</p>
						</div>
					</div>

					<div class="luna-ads-field">
						<label for="luna-ads-source">管理元</label>
						<select id="luna-ads-source" name="luna_ads_settings[code_source]">
							<option value="plugin" <?php selected( $settings['code_source'], 'plugin' ); ?>>このプラグインで管理する</option>
							<option value="theme" <?php selected( $settings['code_source'], 'theme' ); ?>>テーマの広告設定を使う</option>
							<option value="external" <?php selected( $settings['code_source'], 'external' ); ?>>Site Kit など外部で管理する</option>
						</select>
						<p class="luna-ads-help">「テーマ」「外部」を選ぶと、このプラグインは広告枠を出力しません（二重挿入防止）。</p>
					</div>

					<div class="luna-ads-field">
						<label for="luna-ads-client">AdSense クライアントID（任意）</label>
						<input type="text" id="luna-ads-client" class="regular-text code"
							name="luna_ads_settings[client_id]"
							value="<?php echo esc_attr( $settings['client_id'] ); ?>"
							placeholder="ca-pub-0000000000000000" inputmode="latin" autocomplete="off" />
						<p class="luna-ads-help">入力すると AdSense ローダーを head に1回だけ出力します。Site Kit 検出時、または広告コード内にローダーがある場合は出力しません。</p>
					</div>
				</section>

				<section class="luna-ads-card" aria-labelledby="luna-ads-sec-3">
					<div class="luna-ads-card__head">
						<span class="luna-ads-step" aria-hidden="true">3</span>
						<div>
							<h2 id="luna-ads-sec-3">広告配信方式</h2>
							<p>自動広告と手動広告では挿入するものが異なります。</p>
						</div>
					</div>

					<div class="luna-ads-field">
						<label for="luna-ads-delivery">配信方式</label>
						<select id="luna-ads-delivery" name="luna_ads_settings[delivery]">
							<option value="manual" <?php selected( $settings['delivery'], 'manual' ); ?>>手動広告のみ（各枠のコードを挿入）</option>
							<option value="auto" <?php selected( $settings['delivery'], 'auto' ); ?>>自動広告のみ（枠コードは挿入しない）</option>
							<option value="manual_auto" <?php selected( $settings['delivery'], 'manual_auto' ); ?>>手動広告＋自動広告</option>
						</select>
						<p class="luna-ads-help">自動広告のみの場合、AdSense の自動配置に任せるため、下の各枠コードは出力しません。</p>
					</div>
				</section>

				<section class="luna-ads-card" aria-labelledby="luna-ads-sec-4">
					<div class="luna-ads-card__head">
						<span class="luna-ads-step" aria-hidden="true">4</span>
						<div>
							<h2 id="luna-ads-sec-4">記事本文中の広告</h2>
							<p>指定した段落の直後に1回だけ挿入します。段落が足りない場合は記事末尾へ回します（末尾広告が有効なときを除く）。</p>
						</div>
					</div>

					<label class="luna-ads-switch-row" for="luna-ads-in-article">
						<span><strong>本文中広告を有効にする</strong></span>
						<input type="checkbox" id="luna-ads-in-article" class="luna-ads-switch"
							name="luna_ads_settings[slots][in_article][enabled]" value="1"
							<?php checked( ! empty( $slots['in_article']['enabled'] ) ); ?> />
					</label>

					<div class="luna-ads-field luna-ads-field--inline">
						<label for="luna-ads-paragraph">挿入位置（何段落目の後）</label>
						<input type="number" id="luna-ads-paragraph" class="small-text" min="1" max="20"
							name="luna_ads_settings[slots][in_article][paragraph]"
							value="<?php echo esc_attr( (string) $slots['in_article']['paragraph'] ); ?>" />
					</div>

					<div class="luna-ads-field">
						<label for="luna-ads-code-in-article">広告コード</label>
						<textarea id="luna-ads-code-in-article" rows="5" class="large-text code"
							name="luna_ads_settings[slots][in_article][code]"
							placeholder="&lt;ins class=&quot;adsbygoogle&quot; ...&gt;&lt;/ins&gt;"><?php echo esc_textarea( $slots['in_article']['code'] ); ?></textarea>
					</div>
				</section>

				<section class="luna-ads-card" aria-labelledby="luna-ads-sec-5">
					<div class="luna-ads-card__head">
						<span class="luna-ads-step" aria-hidden="true">5</span>
						<div>
							<h2 id="luna-ads-sec-5">記事末尾の広告</h2>
							<p>本文の直後に出力します。テーマ側の記事末尾広告が設定済みの場合は出力しません。</p>
						</div>
					</div>

					<label class="luna-ads-switch-row" for="luna-ads-article-bottom">
						<span><strong>記事末尾広告を有効にする</strong></span>
						<input type="checkbox" id="luna-ads-article-bottom" class="luna-ads-switch"
							name="luna_ads_settings[slots][article_bottom][enabled]" value="1"
							<?php checked( ! empty( $slots['article_bottom']['enabled'] ) ); ?> />
					</label>

					<div class="luna-ads-field">
						<label for="luna-ads-code-article-bottom">広告コード</label>
						<textarea id="luna-ads-code-article-bottom" rows="5" class="large-text code"
							name="luna_ads_settings[slots][article_bottom][code]"><?php echo esc_textarea( $slots['article_bottom']['code'] ); ?></textarea>
					</div>
				</section>

				<section class="luna-ads-card" aria-labelledby="luna-ads-sec-6">
					<div class="luna-ads-card__head">
						<span class="luna-ads-step" aria-hidden="true">6</span>
						<div>
							<h2 id="luna-ads-sec-6">インフィード広告</h2>
							<p>ホーム・アーカイブ・検索結果で、指定した件数ごとにカードの間に挿入します。</p>
						</div>
					</div>

					<label class="luna-ads-switch-row" for="luna-ads-infeed">
						<span><strong>インフィード広告を有効にする</strong></span>
						<input type="checkbox" id="luna-ads-infeed" class="luna-ads-switch"
							name="luna_ads_settings[slots][infeed][enabled]" value="1"
							<?php checked( ! empty( $slots['infeed']['enabled'] ) ); ?> />
					</label>

					<div class="luna-ads-field luna-ads-field--inline">
						<label for="luna-ads-every">表示間隔（何件ごと）</label>
						<input type="number" id="luna-ads-every" class="small-text" min="1" max="20"
							name="luna_ads_settings[slots][infeed][every]"
							value="<?php echo esc_attr( (string) $slots['infeed']['every'] ); ?>" />
					</div>

					<div class="luna-ads-field">
						<label for="luna-ads-code-infeed">広告コード</label>
						<textarea id="luna-ads-code-infeed" rows="5" class="large-text code"
							name="luna_ads_settings[slots][infeed][code]"><?php echo esc_textarea( $slots['infeed']['code'] ); ?></textarea>
					</div>
				</section>

				<section class="luna-ads-card" aria-labelledby="luna-ads-sec-7">
					<div class="luna-ads-card__head">
						<span class="luna-ads-step" aria-hidden="true">7</span>
						<div>
							<h2 id="luna-ads-sec-7">サイドバー広告</h2>
							<p>テーマにサイドバーがない場合は、ショートコードで任意の位置に置けます。</p>
						</div>
					</div>

					<label class="luna-ads-switch-row" for="luna-ads-sidebar">
						<span><strong>サイドバー広告を有効にする</strong></span>
						<input type="checkbox" id="luna-ads-sidebar" class="luna-ads-switch"
							name="luna_ads_settings[slots][sidebar][enabled]" value="1"
							<?php checked( ! empty( $slots['sidebar']['enabled'] ) ); ?> />
					</label>

					<div class="luna-ads-field">
						<label for="luna-ads-code-sidebar">広告コード</label>
						<textarea id="luna-ads-code-sidebar" rows="5" class="large-text code"
							name="luna_ads_settings[slots][sidebar][code]"><?php echo esc_textarea( $slots['sidebar']['code'] ); ?></textarea>
						<p class="luna-ads-help">設置例: <code>[luna_ad slot="sidebar"]</code> / PHP: <code>&lt;?php luna_ads_render( 'sidebar' ); ?&gt;</code></p>
					</div>
				</section>

				<section class="luna-ads-card" aria-labelledby="luna-ads-sec-8">
					<div class="luna-ads-card__head">
						<span class="luna-ads-step" aria-hidden="true">8</span>
						<div>
							<h2 id="luna-ads-sec-8">表示除外設定</h2>
							<p>記事編集画面の「この記事では広告を表示しない」と併用できます。</p>
						</div>
					</div>

					<div class="luna-ads-field">
						<label for="luna-ads-exclude-posts">除外する投稿ID</label>
						<input type="text" id="luna-ads-exclude-posts" class="regular-text code"
							name="luna_ads_settings[exclude][post_ids]"
							value="<?php echo esc_attr( $exclude['post_ids'] ); ?>"
							placeholder="例: 12, 345" autocomplete="off" />
					</div>

					<div class="luna-ads-field">
						<label for="luna-ads-exclude-categories">除外するカテゴリID</label>
						<input type="text" id="luna-ads-exclude-categories" class="regular-text code"
							name="luna_ads_settings[exclude][category_ids]"
							value="<?php echo esc_attr( $exclude['category_ids'] ); ?>"
							placeholder="例: 3, 8" autocomplete="off" />
					</div>

					<label class="luna-ads-switch-row" for="luna-ads-exclude-logged-in">
						<span>
							<strong>ログインユーザーには表示しない</strong>
							<span class="luna-ads-help">購読者・投稿者・管理者を含みます。</span>
						</span>
						<input type="checkbox" id="luna-ads-exclude-logged-in" class="luna-ads-switch"
							name="luna_ads_settings[exclude][logged_in]" value="1"
							<?php checked( ! empty( $exclude['logged_in'] ) ); ?> />
					</label>
				</section>

				<div class="luna-ads-actions">
					<?php submit_button( '設定を保存', 'primary', 'submit', false ); ?>
					<p class="luna-ads-help">保存には管理者権限と nonce 検証が必要です。</p>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * 現在の状態と競合検出を表示する。
	 *
	 * @param array<string,mixed> $settings 設定。
	 * @return void
	 */
	private static function render_status( $settings ) {
		$source_labels = array(
			'plugin'   => 'このプラグイン',
			'theme'    => 'テーマ',
			'external' => '外部（Site Kit 等）',
		);
		$delivery_labels = array(
			'manual'      => '手動広告のみ',
			'auto'        => '自動広告のみ',
			'manual_auto' => '手動＋自動',
		);

		$site_kit     = Luna_Ads_Renderer::site_kit_active();
		$theme_article = Luna_Ads_Renderer::theme_owns_slot( 'article_bottom' );
		$theme_infeed  = Luna_Ads_Renderer::theme_owns_slot( 'infeed' );
		?>
		<section class="luna-ads-status" aria-label="現在の広告状態">
			<div class="luna-ads-status__item">
				<span class="luna-ads-status__label">管理元</span>
				<strong><?php echo esc_html( $source_labels[ $settings['code_source'] ] ?? $settings['code_source'] ); ?></strong>
			</div>
			<div class="luna-ads-status__item">
				<span class="luna-ads-status__label">配信方式</span>
				<strong><?php echo esc_html( $delivery_labels[ $settings['delivery'] ] ?? $settings['delivery'] ); ?></strong>
			</div>
			<div class="luna-ads-status__item">
				<span class="luna-ads-status__label">Site Kit</span>
				<strong><?php echo $site_kit ? '検出（ローダーは出力しません）' : '未検出'; ?></strong>
			</div>
			<div class="luna-ads-status__item">
				<span class="luna-ads-status__label">テーマ側の広告</span>
				<strong>
					<?php
					if ( $theme_article || $theme_infeed ) {
						$positions = array();
						if ( $theme_article ) {
							$positions[] = '記事末尾';
						}
						if ( $theme_infeed ) {
							$positions[] = 'インフィード';
						}
						echo esc_html( implode( '・', $positions ) . 'を検出（プラグインは出力しません）' );
					} else {
						echo '未設定';
					}
					?>
				</strong>
			</div>
		</section>
		<?php
	}

	/**
	 * 競合・未設定の警告。
	 *
	 * @return void
	 */
	public static function render_admin_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::$page_hook !== $screen->id ) {
			return;
		}

		$settings = Luna_Ads_Settings::get();

		if ( empty( $settings['enabled'] ) ) {
			echo '<div class="notice notice-info"><p>広告配信は無効です。この状態では広告コードもローダーも出力しません。</p></div>';
			return;
		}

		if ( 'manual' === $settings['delivery'] && ! Luna_Ads_Renderer::has_active_slot( $settings ) ) {
			echo '<div class="notice notice-warning"><p>広告配信は有効ですが、有効かつコード入力済みの枠がありません。空白のプレースホルダーは出力されません。</p></div>';
		}

		$conflicts = array();
		if ( Luna_Ads_Renderer::theme_owns_slot( 'article_bottom' ) && Luna_Ads_Renderer::slot_has_code( 'article_bottom' ) ) {
			$conflicts[] = '記事末尾';
		}
		if ( Luna_Ads_Renderer::theme_owns_slot( 'infeed' ) && Luna_Ads_Renderer::slot_has_code( 'infeed' ) ) {
			$conflicts[] = 'インフィード';
		}
		if ( ! empty( $conflicts ) ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html( implode( '・', $conflicts ) . 'の広告はテーマ側の設定が優先されます。二重挿入を防ぐため、プラグインからは出力しません。' ) .
				'</p></div>';
		}

		if ( 'external' === $settings['code_source'] ) {
			echo '<div class="notice notice-info"><p>管理元が外部です。このプラグインは広告枠を出力しません。除外設定は外部側の広告には適用されません。</p></div>';
		}
	}

	/**
	 * 記事編集画面の「広告を表示しない」メタボックス。
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types['attachment'] );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'luna-ads-control-post',
				'Luna Ads Center',
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * メタボックス本体。
	 *
	 * @param WP_Post $post 投稿。
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'luna_ads_save_post', 'luna_ads_post_nonce' );
		$disabled = '1' === (string) get_post_meta( $post->ID, '_luna_ads_disabled', true );
		?>
		<p>
			<label for="luna-ads-disable-post">
				<input type="checkbox" id="luna-ads-disable-post" name="luna_ads_disable_post" value="1" <?php checked( $disabled ); ?> />
				この記事では広告を表示しない
			</label>
		</p>
		<p class="description">本文中・記事末尾・インフィードのすべてを、この記事に限って停止します。設定の全体除外とは別に保存されます。</p>
		<?php
	}

	/**
	 * 記事単位の除外を保存する。nonce・自動保存・権限を検証する。
	 *
	 * @param int $post_id 投稿ID。
	 * @return void
	 */
	public static function save_post_meta( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['luna_ads_post_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['luna_ads_post_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'luna_ads_save_post' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['luna_ads_disable_post'] ) ) {
			update_post_meta( $post_id, '_luna_ads_disabled', '1' );
		} else {
			delete_post_meta( $post_id, '_luna_ads_disabled' );
		}
	}
}
