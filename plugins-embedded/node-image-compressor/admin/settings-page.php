<?php
/**
 * Node Image Compressor の管理ページ（Material 3 / モバイル対応）。
 *
 * Node Settings 配下のサブメニューとして 1 ページに
 * サマリー・一括置き換え・対象一覧・設定を収める。
 *
 * @package Node_Image_Compressor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NODE_IC_PER_PAGE = 20;

// テーマ（優先度10）が親メニューを登録した後に走らせる。
// プラグインは functions.php より先に読み込まれるため、同じ優先度だと親が未登録になる
add_action(
	'admin_menu',
	static function (): void {
		if ( menu_page_url( 'luminous-settings', false ) ) {
			$hook = add_submenu_page(
				'luminous-settings',
				'画像圧縮 (WebP)',
				'画像圧縮',
				'manage_options',
				'node-image-compressor',
				'node_ic_render_page'
			);
		} else {
			// 単体プラグイン利用時は「メディア」配下へ退避する
			$hook = add_submenu_page(
				'upload.php',
				'画像圧縮 (WebP)',
				'画像圧縮',
				'manage_options',
				'node-image-compressor',
				'node_ic_render_page'
			);
		}

		if ( is_string( $hook ) ) {
			Node_Image_Compressor::$page_hook = $hook;
		}
	},
	20
);

add_action(
	'admin_init',
	static function (): void {
		$bool = static fn( $value ): string => ( '1' === (string) $value ) ? '1' : '0';

		register_setting( 'node_ic_group', 'node_ic_enabled', array( 'sanitize_callback' => $bool ) );
		register_setting( 'node_ic_group', 'node_ic_auto', array( 'sanitize_callback' => $bool ) );
		register_setting( 'node_ic_group', 'node_ic_keep_original', array( 'sanitize_callback' => $bool ) );
		register_setting( 'node_ic_group', 'node_ic_redirect', array( 'sanitize_callback' => $bool ) );

		register_setting(
			'node_ic_group',
			'node_ic_quality',
			array(
				'sanitize_callback' => static function ( $value ): int {
					return max( 1, min( 100, (int) $value ) );
				},
			)
		);

		register_setting(
			'node_ic_group',
			'node_ic_quality_mode',
			array(
				'sanitize_callback' => static function ( $value ): string {
					return 'manual' === (string) $value ? 'manual' : 'auto';
				},
			)
		);
	}
);

/**
 * M3 のスタイルとスクリプトは、この画面と投稿編集画面（メタボックス）にだけ読み込む。
 * 他の管理画面へ漏らさないことが必須条件。
 */
add_action(
	'admin_enqueue_scripts',
	static function ( string $hook ): void {
		$is_settings_page = ( '' !== Node_Image_Compressor::$page_hook && $hook === Node_Image_Compressor::$page_hook );
		$is_editor        = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

		if ( ! $is_settings_page && ! $is_editor ) {
			return;
		}

		/*
		 * フロント（header.php）と同じ Material Symbols。管理画面には読み込まれていないのでここで足す。
		 *
		 * アイコンを指定せずに要求すると Google Fonts は全アイコン（約3,700種）を
		 * 1本の可変フォントで返す（実測 3,975KB）。display=block なので届くまで
		 * 画面のアイコンは空白のままで、管理画面の初期表示にも直撃する。
		 * テーマ側の一覧（inc/icon-font.php）へこのプラグインのアイコンを足したものを使う。
		 */
		$symbols_url = function_exists( 'node_get_icon_font_url' )
			? node_get_icon_font_url()
			: 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block';

		wp_enqueue_style(
			'node-ic-symbols',
			$symbols_url,
			array(),
			null
		);

		wp_enqueue_style( 'node-ic-admin', NODE_IC_URL . 'assets/css/admin.css', array( 'node-ic-symbols' ), NODE_IC_VERSION );
		wp_enqueue_script( 'node-ic-admin', NODE_IC_URL . 'assets/js/admin.js', array(), NODE_IC_VERSION, true );

		// 全アイキャッチの走査は一括置き換えのためだけに必要なので、設定画面でのみ行う
		$pending_ids = $is_settings_page ? node_ic_get_summary()['pending_ids'] : array();

		wp_localize_script(
			'node-ic-admin',
			'nodeIcData',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'node_ic_action' ),
				'pendingIds' => array_map( 'strval', $pending_ids ),
			)
		);
	}
);

/**
 * ステータスに対応する Material Symbols のアイコン名。人物アイコンは使わない。
 */
function node_ic_state_icon( string $state ): string {
	return match ( $state ) {
		'converted'  => 'check_circle',
		'pending'    => 'pending',
		'skipped'    => 'block',
		default      => 'do_not_disturb_on',
	};
}

/**
 * M3 スイッチ 1 行を描画する。
 */
function node_ic_render_switch( string $option, string $label, string $description, string $default = '1' ): void {
	$checked = ( '1' === (string) get_option( $option, $default ) );
	?>
	<div class="node-ic-field node-ic-field--switch">
		<div class="node-ic-field__text">
			<label class="node-ic-field__label" for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label>
			<p class="node-ic-field__desc"><?php echo esc_html( $description ); ?></p>
		</div>
		<input type="hidden" name="<?php echo esc_attr( $option ); ?>" value="0">
		<label class="node-ic-switch">
			<input type="checkbox" id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" value="1" <?php checked( $checked ); ?>>
			<span class="node-ic-switch__track" aria-hidden="true"><span class="node-ic-switch__thumb"></span></span>
		</label>
	</div>
	<?php
}

/**
 * 管理ページ本体。
 */
function node_ic_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}

	$supported = Node_IC_Converter::is_supported();
	$summary   = node_ic_get_summary();
	$target_ids = node_ic_get_target_ids();

	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$total  = count( $target_ids );
	$pages  = max( 1, (int) ceil( $total / NODE_IC_PER_PAGE ) );
	$paged  = min( $paged, $pages );
	$slice  = array_slice( $target_ids, ( $paged - 1 ) * NODE_IC_PER_PAGE, NODE_IC_PER_PAGE );

	$saved_rate = $summary['before'] > 0 ? ( $summary['saved'] / $summary['before'] ) * 100 : 0.0;
	?>
	<div class="wrap node-ic-wrap">
		<div class="node-ic-app">

			<header class="node-ic-header">
				<h1 class="node-ic-title">
					<span class="material-symbols-outlined" aria-hidden="true">compress</span>
					アイキャッチの WebP 置き換え
				</h1>
				<p class="node-ic-subtitle">アイキャッチ画像を WebP に置き換えて転送量と LCP を改善します。既定では元の JPEG / PNG は削除されます。</p>
			</header>

			<?php if ( ! $supported ) : ?>
				<div class="node-ic-banner node-ic-banner--error" role="alert">
					<span class="material-symbols-outlined" aria-hidden="true">error</span>
					<div>
						<strong>このサーバーは WebP の書き出しに対応していません。</strong>
						<p>GD の WebP サポート、または Imagick の有効化をホスティング事業者にご確認ください。置き換え機能は無効化されています。</p>
					</div>
				</div>
			<?php elseif ( ! Node_IC_Converter::is_enabled() ) : ?>
				<div class="node-ic-banner node-ic-banner--warn" role="status">
					<span class="material-symbols-outlined" aria-hidden="true">pause_circle</span>
					<div>
						<strong>WebP への置き換えは現在オフです。</strong>
						<p>下の「設定」から有効にすると置き換えできるようになります。</p>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $supported && Node_IC_Converter::is_enabled() && ! Node_IC_Converter::keeps_original() ) : ?>
				<div class="node-ic-banner node-ic-banner--warn" role="status">
					<span class="material-symbols-outlined" aria-hidden="true">delete_forever</span>
					<div>
						<strong>元ファイルを削除して置き換えます。</strong>
						<p>置き換えた画像は復元できません。残しておきたい場合は下の「元ファイルを残す」をオンにしてください。<?php echo Node_IC_Converter::redirects_old_urls() ? '旧 URL へのアクセスは新しい WebP へ転送されます。' : '旧 URL へのアクセスは 404 になります（下の「旧 URL を新しい画像へ転送する」で転送できます）。'; ?></p>
					</div>
				</div>
			<?php endif; ?>

			<section class="node-ic-stats" aria-label="置き換えの状況">
				<div class="node-ic-stat">
					<span class="node-ic-stat__value"><?php echo esc_html( (string) $summary['total'] ); ?></span>
					<span class="node-ic-stat__label">対象アイキャッチ</span>
				</div>
				<div class="node-ic-stat node-ic-stat--accent">
					<span class="node-ic-stat__value" data-node-ic-count="converted"><?php echo esc_html( (string) $summary['converted'] ); ?></span>
					<span class="node-ic-stat__label">置き換え済み</span>
				</div>
				<div class="node-ic-stat">
					<span class="node-ic-stat__value" data-node-ic-count="pending"><?php echo esc_html( (string) $summary['pending'] ); ?></span>
					<span class="node-ic-stat__label">未置き換え</span>
				</div>
				<div class="node-ic-stat">
					<span class="node-ic-stat__value"><?php echo esc_html( size_format( $summary['saved'] ) ?: '0 B' ); ?></span>
					<span class="node-ic-stat__label">削減できた容量<?php echo $saved_rate > 0 ? esc_html( sprintf( '（%.1f%%）', $saved_rate ) ) : ''; ?></span>
				</div>
			</section>

			<section class="node-ic-card node-ic-bulk" aria-label="一括置き換え">
				<div class="node-ic-bulk__head">
					<h2 class="node-ic-card__title">一括置き換え</h2>
					<p class="node-ic-card__desc">未置き換えのアイキャッチを 1 件ずつ順番に置き換えます。途中で失敗しても次に進みます。</p>
				</div>

				<div class="node-ic-progress" data-node-ic-progress hidden>
					<div class="node-ic-progress__bar"><span class="node-ic-progress__fill" style="width:0%"></span></div>
					<p class="node-ic-progress__text" role="status" aria-live="polite" data-node-ic-progress-text></p>
				</div>

				<div class="node-ic-bulk__actions">
					<button type="button" class="node-ic-btn node-ic-btn--filled" data-node-ic-bulk <?php disabled( ! $supported || 0 === $summary['pending'] ); ?>>
						<span class="material-symbols-outlined" aria-hidden="true">bolt</span>
						未置き換えをすべて置き換え<?php echo $summary['pending'] > 0 ? esc_html( sprintf( '（%d件）', $summary['pending'] ) ) : ''; ?>
					</button>
					<button type="button" class="node-ic-btn node-ic-btn--text" data-node-ic-bulk-stop hidden>
						<span class="material-symbols-outlined" aria-hidden="true">stop_circle</span>
						中止
					</button>
				</div>
			</section>

			<section class="node-ic-card" aria-label="対象一覧">
				<h2 class="node-ic-card__title">対象一覧</h2>

				<?php if ( empty( $slice ) ) : ?>
					<p class="node-ic-empty">アイキャッチが設定された画像がまだありません。</p>
				<?php else : ?>
					<ul class="node-ic-list">
						<?php foreach ( $slice as $attachment_id ) : ?>
							<?php
							$status    = node_ic_get_status( $attachment_id );
							$thumbnail = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
							$posts     = node_ic_get_using_posts( $attachment_id );
							$filename  = wp_basename( (string) get_attached_file( $attachment_id ) );
							?>
							<li class="node-ic-item" data-node-ic-item="<?php echo esc_attr( (string) $attachment_id ); ?>" data-state="<?php echo esc_attr( $status['state'] ); ?>">
								<div class="node-ic-item__thumb">
									<?php if ( $thumbnail ) : ?>
										<img src="<?php echo esc_url( $thumbnail ); ?>" alt="" loading="lazy" width="72" height="72" data-node-ic-thumb>
									<?php else : ?>
										<span class="material-symbols-outlined" aria-hidden="true">broken_image</span>
									<?php endif; ?>
								</div>

								<div class="node-ic-item__body">
									<p class="node-ic-item__name" title="<?php echo esc_attr( $filename ); ?>"><?php echo esc_html( $filename ); ?></p>

									<?php if ( ! empty( $posts ) ) : ?>
										<p class="node-ic-item__posts">
											<?php foreach ( $posts as $post ) : ?>
												<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ?: '(無題)' ); ?></a>
											<?php endforeach; ?>
										</p>
									<?php endif; ?>

									<p class="node-ic-item__meta" data-node-ic-meta>
										<span class="node-ic-chip node-ic-chip--<?php echo esc_attr( $status['state'] ); ?>" data-node-ic-chip>
											<span class="material-symbols-outlined" aria-hidden="true" data-node-ic-chip-icon><?php echo esc_html( node_ic_state_icon( $status['state'] ) ); ?></span>
											<span data-node-ic-chip-label><?php echo esc_html( $status['label'] ); ?></span>
										</span>
										<span class="node-ic-item__size" data-node-ic-size>
											<?php if ( 'converted' === $status['state'] && $status['before'] > 0 ) : ?>
												<?php echo esc_html( sprintf( '%s → %s（-%.1f%%）', size_format( $status['before'] ), size_format( $status['after'] ), $status['rate'] ) ); ?>
											<?php elseif ( '' !== $status['reason'] ) : ?>
												<?php echo esc_html( $status['reason'] ); ?>
											<?php endif; ?>
										</span>
									</p>
								</div>

								<div class="node-ic-item__actions" data-node-ic-actions>
									<button type="button" class="node-ic-btn node-ic-btn--tonal" data-node-ic-convert <?php disabled( ! $supported || 'pending' !== $status['state'] ); ?>>
										<span class="material-symbols-outlined" aria-hidden="true">compress</span>
										<span class="node-ic-btn__text">置き換え</span>
									</button>
									<button type="button" class="node-ic-btn node-ic-btn--text" data-node-ic-restore <?php disabled( ! $status['restorable'] ); ?>>
										<span class="material-symbols-outlined" aria-hidden="true">undo</span>
										<span class="node-ic-btn__text">復元</span>
									</button>
									<button type="button" class="node-ic-btn node-ic-btn--text" data-node-ic-skip <?php disabled( 'converted' === $status['state'] ); ?>>
										<span class="material-symbols-outlined" aria-hidden="true"><?php echo 'skipped' === $status['state'] ? 'restart_alt' : 'block'; ?></span>
										<span class="node-ic-btn__text"><?php echo 'skipped' === $status['state'] ? 'スキップ解除' : 'スキップ'; ?></span>
									</button>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php if ( $pages > 1 ) : ?>
						<nav class="node-ic-pagination" aria-label="ページ送り">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%' ),
										'format'    => '',
										'current'   => $paged,
										'total'     => $pages,
										'prev_text' => '‹',
										'next_text' => '›',
									)
								)
							);
							?>
						</nav>
					<?php endif; ?>
				<?php endif; ?>
			</section>

			<section class="node-ic-card" aria-label="設定">
				<h2 class="node-ic-card__title">設定</h2>

				<form method="post" action="options.php" class="node-ic-form">
					<?php settings_fields( 'node_ic_group' ); ?>

					<?php
					node_ic_render_switch( 'node_ic_enabled', 'WebP への置き換えを有効にする', 'オフにすると自動置き換えも手動置き換えも止まります。すでに置き換え済みの画像はそのままです。' );
					node_ic_render_switch( 'node_ic_auto', 'アイキャッチ設定時に自動で置き換える', '記事にアイキャッチを設定すると、30 秒後にバックグラウンドで置き換えます。' );
					node_ic_render_switch( 'node_ic_keep_original', '元ファイルを残す', 'オンにすると元の JPEG / PNG を残し、いつでも復元できます。オフ（既定）だと削除されるため復元できません。', '0' );
					node_ic_render_switch( 'node_ic_redirect', '旧 URL を新しい画像へ転送する', '削除した元画像（.jpg / .png）へのアクセスを、置き換え後の WebP へ 301 転送します。外部サイトや RSS に残った直リンクが 404 になりません。', '1' );
					?>

					<?php $is_auto = Node_IC_Converter::is_auto_quality(); ?>
					<div class="node-ic-field">
						<div class="node-ic-field__text">
							<label class="node-ic-field__label" for="node_ic_quality_mode">WebP の品質</label>
							<p class="node-ic-field__desc">
								自動では画像 1 枚ごとに品質
								<?php echo esc_html( implode( ' → ', Node_IC_Converter::AUTO_QUALITY_STEPS ) ); ?>
								を上から順に試し、元の <?php echo esc_html( (string) (int) round( Node_IC_Converter::AUTO_TARGET_RATIO * 100 ) ); ?>% 以下に収まった時点で採用します。きれいなまま十分小さくなる画像は高い品質のまま残ります。
							</p>
						</div>
						<select id="node_ic_quality_mode" name="node_ic_quality_mode" class="node-ic-input node-ic-select" data-node-ic-quality-mode>
							<option value="auto" <?php selected( $is_auto ); ?>>自動（推奨）</option>
							<option value="manual" <?php selected( ! $is_auto ); ?>>手動で指定</option>
						</select>
					</div>

					<div class="node-ic-field" data-node-ic-quality-manual <?php echo $is_auto ? 'hidden' : ''; ?>>
						<div class="node-ic-field__text">
							<label class="node-ic-field__label" for="node_ic_quality">手動で指定する品質</label>
							<p class="node-ic-field__desc">高いほどきれいですが容量が増えます。写真は 80 前後が目安です。</p>
						</div>
						<div class="node-ic-slider">
							<input type="range" id="node_ic_quality_range" min="1" max="100" step="1"
								value="<?php echo esc_attr( (string) Node_IC_Converter::get_quality() ); ?>"
								aria-label="WebP の品質（スライダー）" data-node-ic-range="node_ic_quality">
							<input type="number" id="node_ic_quality" name="node_ic_quality" min="1" max="100" step="1"
								value="<?php echo esc_attr( (string) Node_IC_Converter::get_quality() ); ?>"
								class="node-ic-input node-ic-input--number" data-node-ic-number="node_ic_quality">
						</div>
					</div>

					<div class="node-ic-form__actions">
						<button type="submit" class="node-ic-btn node-ic-btn--filled">
							<span class="material-symbols-outlined" aria-hidden="true">save</span>
							設定を保存
						</button>
					</div>
				</form>
			</section>

			<div class="node-ic-snackbar" data-node-ic-snackbar role="status" aria-live="polite" hidden></div>
		</div>
	</div>
	<?php
}
