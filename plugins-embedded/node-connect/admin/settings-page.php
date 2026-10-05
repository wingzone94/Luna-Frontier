<?php
/**
 * Node Connect 外部連携設定画面。
 *
 * Webhook URL はフロントHTMLへ一切出力せず、この画面（manage_options 限定）でも
 * 末尾数文字以外はマスク表示する。保存済みURLを input value として再出力しない。
 *
 * @package Node_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// テーマ（優先度10）が親メニューを登録した後に走らせる。
// プラグインは functions.php より先に読み込まれるため、同じ優先度だと親が未登録になる
add_action(
	'admin_menu',
	static function (): void {
		// Luna Settings（テーマ側の add_menu_page）配下へ入れる。
		// 親が未登録の場合（プラグイン単体利用）は従来どおり「設定」配下へ退避する
		if ( menu_page_url( 'luminous-settings', false ) ) {
			add_submenu_page(
				'luminous-settings',
				'外部連携設定',
				'外部連携',
				'manage_options',
				'node-connect',
				'node_connect_render_settings_page'
			);
			return;
		}

		add_options_page(
			'外部連携設定',
			'外部連携',
			'manage_options',
			'node-connect',
			'node_connect_render_settings_page'
		);
	},
	20
);

add_action(
	'admin_init',
	static function (): void {
		register_setting(
			'node_connect_group',
			Node_Connect_Event_Bus::OPTION_ENABLED,
			[
				'sanitize_callback' => static fn( $value ) => '1' === (string) $value ? '1' : '',
			]
		);
		register_setting(
			'node_connect_group',
			Node_Connect_Event_Bus::OPTION_PAUSED,
			[
				'sanitize_callback' => static fn( $value ) => '1' === (string) $value ? '1' : '',
			]
		);
		register_setting(
			'node_connect_group',
			Node_Connect_Event_Bus::OPTION_WEBHOOKS,
			[
				'sanitize_callback' => 'node_connect_sanitize_webhooks',
			]
		);

		// --- X（Twitter）自動投稿 ---
		register_setting(
			'node_connect_group',
			Node_Connect_X_Poster::OPTION_ENABLED,
			[
				'sanitize_callback' => static fn( $value ) => '1' === (string) $value ? '1' : '',
			]
		);
		register_setting(
			'node_connect_group',
			Node_Connect_X_Poster::OPTION_TEMPLATES,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Node_Connect_X_Poster::class, 'sanitize_templates' ],
			]
		);
		foreach ( [
			Node_Connect_X_Poster::OPTION_API_KEY,
			Node_Connect_X_Poster::OPTION_API_SECRET,
			Node_Connect_X_Poster::OPTION_ACCESS_TOKEN,
			Node_Connect_X_Poster::OPTION_ACCESS_TOKEN_SECRET,
		] as $node_connect_x_option ) {
			register_setting(
				'node_connect_group',
				$node_connect_x_option,
				[
					'sanitize_callback' => static fn( $value ) => node_connect_sanitize_x_credential( $value, $node_connect_x_option ),
				]
			);
		}
	}
);

/**
 * X認証情報の保存。空欄は「変更しない」＝保存済みの値を維持（マスク表示のため
 * 画面に既存値を再出力しない方式）。「認証情報をすべて削除」チェック時は空にする。
 *
 * @param mixed $value
 */
function node_connect_sanitize_x_credential( $value, string $option_name ): string {
	if ( ! empty( $_POST['node_connect_x_clear'] ) ) {
		if ( Node_Connect_X_Poster::OPTION_ACCESS_TOKEN === $option_name ) {
			delete_option( Node_Connect_X_Poster::OPTION_SCREEN_NAME );
		}
		return '';
	}
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return (string) get_option( $option_name, '' );
	}
	return sanitize_text_field( $value );
}

/**
 * Webhook設定の保存。URL欄が空なら保存済みURLを維持する（マスク表示のため
 * 画面には既存URLを出さない方式の裏側）。「削除」チェックで行ごと破棄。
 *
 * @param mixed $input
 * @return array<int, array{label: string, url: string, events: string[], enabled: bool}>
 */
function node_connect_sanitize_webhooks( $input ): array {
	$existing  = Node_Connect_Event_Bus::get_webhooks();
	$catalog   = array_keys( Node_Connect_Event_Bus::get_event_catalog() );
	$sanitized = [];

	if ( ! is_array( $input ) ) {
		return $existing;
	}

	for ( $i = 0; $i < NODE_CONNECT_MAX_WEBHOOKS; $i++ ) {
		$row = is_array( $input[ $i ] ?? null ) ? $input[ $i ] : [];

		if ( ! empty( $row['remove'] ) ) {
			continue;
		}

		$url = trim( (string) ( $row['url'] ?? '' ) );
		if ( '' !== $url ) {
			$url = sanitize_url( $url );
			if ( ! str_starts_with( $url, 'https://' ) ) {
				$url = '';
			}
		}
		if ( '' === $url ) {
			$url = $existing[ $i ]['url'] ?? '';
		}
		if ( '' === $url ) {
			continue;
		}

		$sanitized[] = [
			'label'   => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
			'url'     => $url,
			'events'  => array_values( array_intersect( array_map( 'strval', (array) ( $row['events'] ?? [] ) ), $catalog ) ),
			'enabled' => ! empty( $row['enabled'] ),
		];
	}

	return $sanitized;
}

/**
 * URLの末尾数文字以外をマスクする。
 */
function node_connect_mask_url( string $url ): string {
	$tail = mb_substr( $url, -6 );
	return 'https://…' . $tail;
}

/**
 * 接続テスト（admin-post）。テストEmbedを同期送信して結果を通知する。
 */
add_action(
	'admin_post_node_connect_test',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'node_connect_test' );

		$index  = isset( $_GET['webhook'] ) ? (int) $_GET['webhook'] : 0;
		$result = Node_Connect_Webhook_Sender::send_test( $index );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'        => 'node-connect',
					'test_result' => $result['ok'] ? 'ok' : 'ng',
					'test_status' => rawurlencode( $result['status'] ),
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
);

/**
 * 「Xと連携」開始（admin-post）。request_token を取得してXの認可画面へ転送する。
 */
add_action(
	'admin_post_node_connect_x_connect',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'node_connect_x_connect' );

		// Xは登録済みCallback URLとの完全一致を要求するため、可変パラメータ（nonce等）は付けない。
		// コールバック側のCSRF対策は、開始時に保存するワンタイムの request_token 照合で担保する。
		$callback = admin_url( 'admin-post.php?action=node_connect_x_callback' );
		$result   = Node_Connect_X_Poster::start_authorization( $callback );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'page'          => 'node-connect',
						'x_test_result' => 'ng',
						'x_test_status' => rawurlencode( $result->get_error_message() ),
					],
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		wp_redirect( $result ); // 外部（X認可画面）への転送のため wp_safe_redirect は使わない。
		exit;
	}
);

/**
 * 「Xと連携」コールバック（admin-post）。verifier をトークンに交換して保存する。
 */
add_action(
	'admin_post_node_connect_x_callback',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		// nonceは使わない（XのCallback URL完全一致要件のため）。connect開始時に transient へ
		// 保存した request_token と一致しない限り complete_authorization が失敗するため、
		// 第三者が用意したトークンでの偽装コールバックは成立しない。

		$token    = sanitize_text_field( (string) ( $_GET['oauth_token'] ?? '' ) );
		$verifier = sanitize_text_field( (string) ( $_GET['oauth_verifier'] ?? '' ) );

		if ( '' === $token || '' === $verifier ) {
			$result = new WP_Error( 'node_connect_x', '連携がキャンセルされたか、パラメータが不足しています。' );
		} else {
			$result = Node_Connect_X_Poster::complete_authorization( $token, $verifier );
		}

		$args = is_wp_error( $result )
			? [
				'x_test_result' => 'ng',
				'x_test_status' => rawurlencode( $result->get_error_message() ),
			]
			: [
				'x_connected' => '1',
			];

		wp_safe_redirect( add_query_arg( [ 'page' => 'node-connect' ] + $args, admin_url( 'options-general.php' ) ) );
		exit;
	}
);

/**
 * X連携解除（admin-post）。
 */
add_action(
	'admin_post_node_connect_x_disconnect',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'node_connect_x_disconnect' );

		Node_Connect_X_Poster::disconnect();

		wp_safe_redirect( add_query_arg( [ 'page' => 'node-connect' ], admin_url( 'options-general.php' ) ) );
		exit;
	}
);

/**
 * X認証テスト（admin-post）。GET /2/users/me で検証（投稿はしない）。
 */
add_action(
	'admin_post_node_connect_x_test',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'node_connect_x_test' );

		$result = Node_Connect_X_Poster::test_credentials();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => 'node-connect',
					'x_test_result' => $result['ok'] ? 'ok' : 'ng',
					'x_test_status' => rawurlencode( $result['status'] ),
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
);

/**
 * 送信履歴のクリア（admin-post）。
 */
add_action(
	'admin_post_node_connect_clear_log',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'node_connect_clear_log' );

		Node_Connect_Delivery_Log::clear();

		wp_safe_redirect( add_query_arg( [ 'page' => 'node-connect' ], admin_url( 'options-general.php' ) ) );
		exit;
	}
);

/**
 * トグルスイッチ型のチェックボックスを描画する。
 *
 * input は通常の checkbox のまま（保存処理・input名は従来と完全互換）で、
 * 見た目だけをトラック＋ノブのトグルに置き換える。ロック（グレーアウト）時も
 * disabled 属性は使わない: disabled にすると値がPOSTされず、保存のたびに
 * ロック中の購読設定が消えてしまうため、CSS の pointer-events と tabindex で
 * 操作だけを止める（値は保持されたまま送信される）。
 *
 * @param array{name:string, checked:bool, text?:string, value?:string, data?:array<string,string>} $args
 */
function node_connect_render_toggle( array $args ): void {
	$name    = (string) $args['name'];
	$checked = ! empty( $args['checked'] );
	$text    = (string) ( $args['text'] ?? '' );
	$value   = (string) ( $args['value'] ?? '1' );
	$data    = (array) ( $args['data'] ?? [] );

	$data_attrs = '';
	foreach ( $data as $data_key => $data_value ) {
		$data_attrs .= sprintf( ' data-%s="%s"', esc_attr( $data_key ), esc_attr( $data_value ) );
	}
	?>
	<label class="nc-toggle">
		<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $checked ); ?><?php echo $data_attrs; // 各値はesc_attr済み ?> />
		<span class="nc-toggle__track" aria-hidden="true"></span>
		<?php if ( '' !== $text ) : ?>
			<span class="nc-toggle__text"><?php echo esc_html( $text ); ?></span>
		<?php endif; ?>
	</label>
	<?php
}

/**
 * 設定画面の描画。
 */
function node_connect_render_settings_page(): void {
	$enabled  = Node_Connect_Event_Bus::is_enabled();
	$paused   = Node_Connect_Event_Bus::is_paused();
	$webhooks = Node_Connect_Event_Bus::get_webhooks();
	$catalog  = Node_Connect_Event_Bus::get_event_catalog();
	$log      = Node_Connect_Delivery_Log::get();

	$card_style = 'background: #fff; padding: 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #e0e0e0; box-shadow: 0 4px 12px rgba(0,0,0,0.05);';
	$h2_style   = 'margin-top: 0; color: #FF9900; display: flex; align-items: center; gap: 10px;';
	?>
	<style>
		.nc-toggle { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; user-select: none; margin: 2px 0; vertical-align: middle; }
		.nc-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
		.nc-toggle__track { position: relative; width: 40px; height: 22px; flex-shrink: 0; border-radius: 999px; background: #c3c4c7; transition: background 0.15s ease; }
		.nc-toggle__track::after { content: ""; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.25); transition: transform 0.15s ease; }
		.nc-toggle input:checked + .nc-toggle__track { background: #FF9900; }
		.nc-toggle input:checked + .nc-toggle__track::after { transform: translateX(18px); }
		.nc-toggle input:focus-visible + .nc-toggle__track { outline: 2px solid #FF9900; outline-offset: 2px; }
		/* ロック＝親機能が無効のため操作不可（値は保持されたまま送信される） */
		.nc-toggle.is-locked { opacity: 0.45; pointer-events: none; }
		.nc-toggle.is-locked .nc-toggle__track { background: #dcdcde; }
		.nc-toggle.is-locked input:checked + .nc-toggle__track { background: #e8c99a; }
		.nc-toggle-list .nc-toggle { display: flex; margin-bottom: 6px; }
	</style>
	<div class="wrap">
		<h1 style="margin-bottom: 30px;">外部連携設定（Luna Connect）</h1>

		<?php if ( isset( $_GET['test_result'] ) ) : ?>
			<?php $test_ok = 'ok' === $_GET['test_result']; ?>
			<div class="notice <?php echo $test_ok ? 'notice-success' : 'notice-error'; ?> is-dismissible">
				<p>
					<?php echo $test_ok ? 'テスト通知を送信しました。Discord側で受信を確認してください。' : 'テスト通知の送信に失敗しました。'; ?>
					（<?php echo esc_html( rawurldecode( (string) ( $_GET['test_status'] ?? '' ) ) ); ?>）
				</p>
			</div>
		<?php endif; ?>

		<?php if ( isset( $_GET['x_connected'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>Xアカウントと連携しました。</p>
			</div>
		<?php endif; ?>

		<?php if ( isset( $_GET['x_test_result'] ) ) : ?>
			<?php $x_test_ok = 'ok' === $_GET['x_test_result']; ?>
			<div class="notice <?php echo $x_test_ok ? 'notice-success' : 'notice-error'; ?> is-dismissible">
				<p>
					<?php echo $x_test_ok ? 'X APIの認証に成功しました。' : 'X APIの認証に失敗しました。キーとトークンを確認してください。'; ?>
					（<?php echo esc_html( rawurldecode( (string) ( $_GET['x_test_status'] ?? '' ) ) ); ?>）
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'node_connect_group' ); ?>

			<div class="m3-admin-card" style="<?php echo esc_attr( $card_style ); ?>">
				<h2 style="<?php echo esc_attr( $h2_style ); ?>">
					<span class="dashicons dashicons-share-alt2"></span> 全体設定
				</h2>
				<table class="form-table">
					<tr>
						<th scope="row">Webhook通知</th>
						<td>
							<?php
							node_connect_render_toggle(
								[
									'name'    => Node_Connect_Event_Bus::OPTION_ENABLED,
									'checked' => $enabled,
									'text'    => '有効にする',
									'data'    => [ 'nc-role' => 'master' ],
								]
							);
							?>
							<p class="description">オフにすると、すべてのイベント通知が送信されません（配下の設定はグレーアウトされます）。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">一時停止</th>
						<td>
							<?php
							node_connect_render_toggle(
								[
									'name'    => Node_Connect_Event_Bus::OPTION_PAUSED,
									'checked' => $paused,
									'text'    => 'すべての通知を一時停止する',
									'data'    => [ 'nc-role' => 'pause' ],
								]
							);
							?>
							<p class="description">設定を残したまま送信だけを止めたいとき（メンテナンス作業中など）に使います。</p>
						</td>
					</tr>
				</table>
			</div>

			<?php for ( $i = 0; $i < NODE_CONNECT_MAX_WEBHOOKS; $i++ ) : ?>
				<?php
				$webhook   = $webhooks[ $i ] ?? null;
				$row_label = $webhook['label'] ?? '';
				?>
				<div class="m3-admin-card" style="<?php echo esc_attr( $card_style ); ?>">
					<h2 style="<?php echo esc_attr( $h2_style ); ?>">
						<span class="dashicons dashicons-admin-links"></span> Webhook <?php echo (int) ( $i + 1 ); ?>
						<?php if ( null !== $webhook && ! $webhook['enabled'] ) : ?>
							<span style="font-size: 12px; color: #999; font-weight: normal;">（停止中）</span>
						<?php endif; ?>
					</h2>
					<table class="form-table">
						<tr>
							<th scope="row">ラベル</th>
							<td>
								<input type="text" name="node_connect_webhooks[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $row_label ); ?>" class="regular-text" placeholder="例: 新着通知用チャンネル" />
								<p class="description">送信履歴に表示される宛先の名前です。</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Discord Webhook URL</th>
							<td>
								<?php if ( null !== $webhook && '' !== $webhook['url'] ) : ?>
									<p style="margin-top: 0;"><code><?php echo esc_html( node_connect_mask_url( $webhook['url'] ) ); ?></code>（設定済み）</p>
								<?php endif; ?>
								<input type="url" name="node_connect_webhooks[<?php echo (int) $i; ?>][url]" value="" class="large-text" placeholder="<?php echo ( null !== $webhook && '' !== $webhook['url'] ) ? '変更する場合のみ入力' : 'https://discord.com/api/webhooks/…'; ?>" autocomplete="off" />
								<p class="description">Discordのチャンネル設定 → 連携サービス → ウェブフックで発行したURLを貼り付けます（https:// のみ）。</p>
								<?php if ( null !== $webhook && '' !== $webhook['url'] ) : ?>
									<label>
										<input type="checkbox" name="node_connect_webhooks[<?php echo (int) $i; ?>][remove]" value="1" />
										このWebhookを削除する
									</label>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">このWebhookの通知</th>
							<td>
								<?php
								node_connect_render_toggle(
									[
										'name'    => 'node_connect_webhooks[' . (int) $i . '][enabled]',
										'checked' => null === $webhook || $webhook['enabled'],
										'text'    => '有効にする',
										'data'    => [
											'nc-role'    => 'webhook-enabled',
											'nc-webhook' => (string) $i,
										],
									]
								);
								?>
								<p class="description">オフにすると、このWebhookだけ送信を止められます（イベントの購読設定はグレーアウトされ、保持されたまま送信だけ止まります）。</p>
							</td>
						</tr>
						<tr>
							<th scope="row">通知するイベント</th>
							<td>
								<fieldset class="nc-toggle-list">
									<?php
									foreach ( $catalog as $event_id => $event_label ) {
										node_connect_render_toggle(
											[
												'name'    => 'node_connect_webhooks[' . (int) $i . '][events][]',
												'checked' => null !== $webhook && in_array( $event_id, $webhook['events'], true ),
												'text'    => $event_label,
												'value'   => $event_id,
												'data'    => [
													'nc-role'    => 'event',
													'nc-webhook' => (string) $i,
												],
											]
										);
									}
									?>
								</fieldset>
								<p class="description">オンにしたイベントだけが、このWebhookへ送信されます。AI関連のイベントは今後のアップデートで発火します。</p>
							</td>
						</tr>
						<?php if ( null !== $webhook && '' !== $webhook['url'] ) : ?>
							<tr>
								<th scope="row">接続テスト</th>
								<td>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=node_connect_test&webhook=' . $i ), 'node_connect_test' ) ); ?>" class="button">テスト通知を送信</a>
									<p class="description">保存済みの設定でテストEmbedを送信します（変更がある場合は先に保存してください）。</p>
								</td>
							</tr>
						<?php endif; ?>
					</table>
				</div>
			<?php endfor; ?>

			<?php
			$x_enabled     = Node_Connect_X_Poster::is_enabled();
			$x_credentials = [
				Node_Connect_X_Poster::OPTION_API_KEY    => 'API Key',
				Node_Connect_X_Poster::OPTION_API_SECRET => 'API Key Secret',
			];
			$x_has_app     = null !== Node_Connect_X_Poster::get_app_credentials();
			$x_ready       = null !== Node_Connect_X_Poster::get_credentials();
			$x_screen_name = (string) get_option( Node_Connect_X_Poster::OPTION_SCREEN_NAME, '' );
			?>
			<div class="m3-admin-card" style="<?php echo esc_attr( $card_style ); ?>">
				<h2 style="<?php echo esc_attr( $h2_style ); ?>">
					<span class="dashicons dashicons-twitter"></span> X（Twitter）自動投稿
				</h2>
				<p class="description">このブログの記事を新規公開した時（予約公開を含む）に、選択した投稿文言テンプレートから作った投稿文を、このブログに連携した公式Xアカウントへ自動投稿します。投稿は記事1件につき1回だけで、記事編集画面から記事ごとにテンプレート選択・文言編集・除外ができます。</p>
				<table class="form-table">
					<tr>
						<th scope="row">自動投稿</th>
						<td>
							<?php
							node_connect_render_toggle(
								[
									'name'    => Node_Connect_X_Poster::OPTION_ENABLED,
									'checked' => $x_enabled,
									'text'    => '有効にする',
									'data'    => [ 'nc-role' => 'x-enabled' ],
								]
							);
							?>
							<p class="description">オフの間は自動投稿されません（記事編集画面からの手動投稿はいつでも使えます）。</p>
						</td>
					</tr>
					<?php foreach ( $x_credentials as $x_option => $x_label ) : ?>
						<?php $x_value = (string) get_option( $x_option, '' ); ?>
						<tr>
							<th scope="row"><?php echo esc_html( $x_label ); ?></th>
							<td>
								<?php if ( '' !== $x_value ) : ?>
									<p style="margin-top: 0;"><code>…<?php echo esc_html( mb_substr( $x_value, -4 ) ); ?></code>（設定済み）</p>
								<?php endif; ?>
								<input type="password" name="<?php echo esc_attr( $x_option ); ?>" value="" class="regular-text" placeholder="<?php echo '' !== $x_value ? '変更する場合のみ入力' : ''; ?>" autocomplete="new-password" />
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row">アカウント連携</th>
						<td>
							<?php if ( $x_ready ) : ?>
								<p style="margin-top: 0;">
									連携済み<?php echo '' !== $x_screen_name ? '： <strong>@' . esc_html( $x_screen_name ) . '</strong>' : ''; ?>
								</p>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=node_connect_x_disconnect' ), 'node_connect_x_disconnect' ) ); ?>" class="button">連携を解除</a>
							<?php elseif ( $x_has_app ) : ?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=node_connect_x_connect' ), 'node_connect_x_connect' ) ); ?>" class="button button-primary">Xと連携（ログイン）</a>
								<p class="description">Xの認可画面が開きます。許可すると投稿用トークンが自動で保存されます。<br>事前に X Developer Portal のアプリ設定（User authentication settings）で Read and write 権限と、Callback URL に次のURLを<strong>そのまま</strong>登録してください:<br><code><?php echo esc_html( admin_url( 'admin-post.php?action=node_connect_x_callback' ) ); ?></code></p>
							<?php else : ?>
								<p class="description" style="margin-top: 0;">先に API Key / API Key Secret を保存すると、ここに「Xと連携」ボタンが表示されます。</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">認証情報の削除</th>
						<td>
							<label>
								<input type="checkbox" name="node_connect_x_clear" value="1" />
								保存時に認証情報（API Key・連携トークン）をすべて削除する
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">X投稿文言テンプレート</th>
						<td>
							<div data-nc-x-templates>
								<?php foreach ( Node_Connect_X_Poster::get_templates() as $x_template_id => $x_template ) : ?>
									<div data-nc-x-template-row style="margin-bottom: 12px; padding: 12px; border: 1px solid #dcdcde; border-radius: 8px;">
										<input type="hidden" name="<?php echo esc_attr( Node_Connect_X_Poster::OPTION_TEMPLATES ); ?>[<?php echo esc_attr( $x_template_id ); ?>][id]" value="<?php echo esc_attr( $x_template_id ); ?>" />
										<p style="margin-top: 0;"><input type="text" name="<?php echo esc_attr( Node_Connect_X_Poster::OPTION_TEMPLATES ); ?>[<?php echo esc_attr( $x_template_id ); ?>][name]" value="<?php echo esc_attr( $x_template['name'] ); ?>" class="regular-text" aria-label="テンプレート名" placeholder="テンプレート名" /></p>
										<textarea name="<?php echo esc_attr( Node_Connect_X_Poster::OPTION_TEMPLATES ); ?>[<?php echo esc_attr( $x_template_id ); ?>][content]" rows="5" class="large-text" aria-label="X投稿文言"><?php echo esc_textarea( $x_template['content'] ); ?></textarea>
										<p style="margin-bottom: 0;"><button type="button" class="button-link-delete" data-nc-remove-x-template>このテンプレートを削除</button></p>
									</div>
								<?php endforeach; ?>
							</div>
							<p><button type="button" class="button" data-nc-add-x-template>テンプレートを追加</button></p>
							<p class="description">
								このブログで使用するテンプレートを複数件登録できます。使用可能な変数: <code>{{title}}</code>, <code>{{url}}</code>, <code>{{summary}}</code>, <code>{{category}}</code>, <code>{{tags}}</code><br>
								投稿全体がXの上限（日本語約140文字・URLは23文字換算）に収まるよう、要約は自動で切り詰め、タグは入り切る分だけ付きます。
							</p>
						</td>
					</tr>
					<?php if ( $x_ready ) : ?>
						<tr>
							<th scope="row">認証テスト</th>
							<td>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=node_connect_x_test' ), 'node_connect_x_test' ) ); ?>" class="button">認証を確認</a>
								<p class="description">保存済みのキーでアカウント情報を取得して確認します（投稿はされません）。</p>
							</td>
						</tr>
					<?php endif; ?>
				</table>
			</div>

			<?php submit_button( '設定を保存' ); ?>
		</form>

		<div class="m3-admin-card" style="<?php echo esc_attr( $card_style ); ?>">
			<h2 style="<?php echo esc_attr( $h2_style ); ?>">
				<span class="dashicons dashicons-list-view"></span> 送信履歴（直近<?php echo (int) Node_Connect_Delivery_Log::MAX_ENTRIES; ?>件）
			</h2>
			<?php if ( [] === $log ) : ?>
				<p>まだ送信履歴はありません。</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>日時</th>
							<th>イベント</th>
							<th>宛先</th>
							<th>結果</th>
							<th>試行</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
								<?php
								$node_connect_log_labels = Node_Connect_Event_Bus::get_event_catalog() + [
									'test'   => '接続テスト',
									'x_post' => 'X自動投稿',
								];
								?>
								<td><?php echo esc_html( $node_connect_log_labels[ $entry['event'] ?? '' ] ?? (string) ( $entry['event'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['label'] ?? '' ) ); ?></td>
								<td><?php echo ! empty( $entry['ok'] ) ? '[OK]' : '[失敗]'; ?> <?php echo esc_html( (string) ( $entry['status'] ?? '' ) ); ?></td>
								<td><?php echo (int) ( $entry['attempt'] ?? 1 ); ?>回目</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p style="margin-top: 12px;">
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=node_connect_clear_log' ), 'node_connect_clear_log' ) ); ?>" class="button">履歴をクリア</a>
				</p>
			<?php endif; ?>
		</div>
	</div>
	<script>
	( function () {
		var masterInput = document.querySelector( 'input[data-nc-role="master"]' );
		var templateList = document.querySelector( '[data-nc-x-templates]' );
		var addTemplateButton = document.querySelector( '[data-nc-add-x-template]' );

		function all( selector ) {
			return Array.prototype.slice.call( document.querySelectorAll( selector ) );
		}

		function setLocked( input, locked ) {
			var label = input.closest( '.nc-toggle' );
			if ( label ) {
				label.classList.toggle( 'is-locked', locked );
			}
			// disabled属性は使わない（値がPOSTされなくなり保存で設定が消えるため）。
			// pointer-events はCSS側、キーボード操作は tabindex で止める。
			input.tabIndex = locked ? -1 : 0;
			input.setAttribute( 'aria-disabled', locked ? 'true' : 'false' );
		}

		function refresh() {
			var masterOn = ! masterInput || masterInput.checked;

			all( 'input[data-nc-role="pause"]' ).forEach( function ( input ) {
				setLocked( input, ! masterOn );
			} );
			all( 'input[data-nc-role="webhook-enabled"]' ).forEach( function ( input ) {
				setLocked( input, ! masterOn );
			} );
			all( 'input[data-nc-role="event"]' ).forEach( function ( input ) {
				var webhookToggle = document.querySelector(
					'input[data-nc-role="webhook-enabled"][data-nc-webhook="' + input.getAttribute( 'data-nc-webhook' ) + '"]'
				);
				setLocked( input, ! masterOn || ( webhookToggle && ! webhookToggle.checked ) );
			} );
			// X自動投稿はWebhook通知から独立した機能のためロック連動しない。
		}

		function refreshTemplateRemoveButtons() {
			var rows = all( '[data-nc-x-template-row]' );
			rows.forEach( function ( row ) {
				var button = row.querySelector( '[data-nc-remove-x-template]' );
				if ( button ) {
					button.disabled = rows.length <= 1;
				}
			} );
		}

		if ( templateList && addTemplateButton ) {
			addTemplateButton.addEventListener( 'click', function () {
				var id = 'template-' + Date.now();
				var row = document.createElement( 'div' );
				row.setAttribute( 'data-nc-x-template-row', '' );
				row.setAttribute( 'style', 'margin-bottom: 12px; padding: 12px; border: 1px solid #dcdcde; border-radius: 8px;' );
				row.innerHTML =
					'<input type="hidden" name="<?php echo esc_js( Node_Connect_X_Poster::OPTION_TEMPLATES ); ?>[' + id + '][id]" value="' + id + '" />' +
					'<p style="margin-top: 0;"><input type="text" name="<?php echo esc_js( Node_Connect_X_Poster::OPTION_TEMPLATES ); ?>[' + id + '][name]" class="regular-text" aria-label="テンプレート名" placeholder="テンプレート名" /></p>' +
					'<textarea name="<?php echo esc_js( Node_Connect_X_Poster::OPTION_TEMPLATES ); ?>[' + id + '][content]" rows="5" class="large-text" aria-label="X投稿文言"></textarea>' +
					'<p style="margin-bottom: 0;"><button type="button" class="button-link-delete" data-nc-remove-x-template>このテンプレートを削除</button></p>';
				templateList.appendChild( row );
				refreshTemplateRemoveButtons();
			} );
			templateList.addEventListener( 'click', function ( event ) {
				if ( event.target && event.target.matches( '[data-nc-remove-x-template]' ) ) {
					event.target.closest( '[data-nc-x-template-row]' ).remove();
					refreshTemplateRemoveButtons();
				}
			} );
		}

		document.addEventListener( 'change', function ( event ) {
			if ( event.target && event.target.matches && event.target.matches( 'input[data-nc-role]' ) ) {
				refresh();
			}
		} );

		refresh();
		refreshTemplateRemoveButtons();
	} )();
	</script>
	<?php
}
