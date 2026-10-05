<?php
/**
 * Luna AI 設定画面（NODE-1.3.md §4.3 / §4.4）
 *
 * プロバイダー選択（Gemini推奨 / Qwen / Ollama / 使用しない）→ 選択したプロバイダーの
 * 項目だけ表示。各プロバイダーに接続テスト。詳細なモデルIDは「詳細設定」に分離。
 * APIキーはマスク表示・input への再出力なし・空欄保存で既存値維持。
 *
 * @package Node_AI_Tools
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
				'Luna AI 設定',
				'AI',
				'manage_options',
				'node-ai',
				'node_ai_render_settings_page'
			);
			return;
		}

		add_options_page(
			'Luna AI 設定',
			'Luna AI',
			'manage_options',
			'node-ai',
			'node_ai_render_settings_page'
		);
	},
	20
);

add_action(
	'admin_init',
	static function (): void {
		register_setting(
			'node_ai_group',
			'node_ai_provider',
			array(
				'sanitize_callback' => static function ( $value ): string {
					$value = (string) $value;
					return in_array( $value, Node_AI_Core::PROVIDERS, true ) ? $value : 'gemini';
				},
			)
		);

		// アイキャッチの alt 自動生成
		register_setting(
			'node_ai_group',
			'node_ai_auto_alt',
			array(
				'sanitize_callback' => static function ( $value ): string {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// --- Gemini ---
		register_setting(
			'node_ai_group',
			'node_ai_gemini_api_key',
			array( 'sanitize_callback' => static fn( $value ) => node_ai_sanitize_secret( $value, 'node_ai_gemini_api_key' ) )
		);
		register_setting(
			'node_ai_group',
			'node_ai_gemini_model',
			array(
				'sanitize_callback' => static function ( $value ): string {
					$value = trim( (string) $value );
					if ( '' === $value ) {
						return '';
					}
					return ( function_exists( 'node_is_valid_gemini_model_id' ) && node_is_valid_gemini_model_id( $value ) ) ? $value : '';
				},
			)
		);

		// --- ファクトチェックのモデル選択（Free Tier Max） ---
		register_setting(
			'node_ai_group',
			Node_AI_Fact_Check_Models::MODE_OPTION,
			array(
				'sanitize_callback' => static function ( $value ): string {
					return 'manual' === (string) $value ? 'manual' : 'auto';
				},
			)
		);
		register_setting(
			'node_ai_group',
			Node_AI_Fact_Check_Models::MANUAL_OPTION,
			array(
				'sanitize_callback' => static function ( $value ): string {
					$value = trim( (string) $value );

					return preg_match( '/^gemini-[a-z0-9][a-z0-9.-]*$/i', $value ) ? $value : '';
				},
			)
		);
		register_setting(
			'node_ai_group',
			Node_AI_Fact_Check_Models::ALLOW_PAID_OPTION,
			array(
				'sanitize_callback' => static function ( $value ): string {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);
		register_setting(
			'node_ai_group',
			Node_AI_Fact_Check_Runner::GROUNDING_OPTION,
			array(
				'sanitize_callback' => static function ( $value ): string {
					$value = (string) $value;

					return in_array( $value, array( 'always', 'required', 'off' ), true ) ? $value : 'always';
				},
			)
		);
		register_setting(
			'node_ai_group',
			Node_AI_Fact_Check_Discovery::ENABLED_OPTION,
			array(
				'sanitize_callback' => static function ( $value ): string {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);
		register_setting(
			'node_ai_group',
			Node_AI_Fact_Check_Sources::OFFICIAL_HOSTS_OPTION,
			array(
				'sanitize_callback' => static function ( $value ): string {
					$hosts = array();

					foreach ( preg_split( '/[\r\n,]+/', (string) $value ) ?: array() as $line ) {
						$host = strtolower( trim( (string) $line ) );
						$host = (string) preg_replace( '#^https?://#', '', $host );
						$host = trim( explode( '/', $host )[0] );

						if ( '' !== $host && preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $host ) ) {
							$hosts[] = $host;
						}
					}

					return implode( "\n", array_unique( $hosts ) );
				},
			)
		);
		register_setting(
			'node_ai_group',
			Node_AI_Fact_Check_Sources::ENABLED_OPTION,
			array(
				'sanitize_callback' => static function ( $value ): string {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// --- Qwen (OpenAI Compatible) ---
		register_setting(
			'node_ai_group',
			'node_ai_qwen_endpoint',
			array(
				'sanitize_callback' => static function ( $value ): string {
					$value = trim( (string) $value );
					if ( '' === $value ) {
						return '';
					}
					$value = sanitize_url( $value );
					return preg_match( '#^https?://#', $value ) ? untrailingslashit( $value ) : '';
				},
			)
		);
		register_setting(
			'node_ai_group',
			'node_ai_qwen_api_key',
			array( 'sanitize_callback' => static fn( $value ) => node_ai_sanitize_secret( $value, 'node_ai_qwen_api_key' ) )
		);
		register_setting(
			'node_ai_group',
			'node_ai_qwen_model',
			array( 'sanitize_callback' => static fn( $value ) => sanitize_text_field( (string) $value ) )
		);

		// --- Ollama ---
		register_setting(
			'node_ai_group',
			'node_ai_ollama_url',
			array(
				'sanitize_callback' => static function ( $value ): string {
					$value = trim( (string) $value );
					if ( '' === $value ) {
						return '';
					}
					$value = sanitize_url( $value );
					return preg_match( '#^https?://#', $value ) ? untrailingslashit( $value ) : '';
				},
			)
		);
		register_setting(
			'node_ai_group',
			'node_ai_ollama_model',
			array(
				'sanitize_callback' => static function ( $value ): string {
					// 詳細設定の任意モデルIDが入力されていればそちらを優先
					$custom = isset( $_POST['node_ai_ollama_model_custom'] ) ? trim( sanitize_text_field( (string) $_POST['node_ai_ollama_model_custom'] ) ) : '';
					if ( '' !== $custom ) {
						return $custom;
					}
					$value   = trim( (string) $value );
					$presets = array_keys( Node_AI_Provider_Ollama::preset_models() );
					return in_array( $value, $presets, true ) ? $value : Node_AI_Provider_Ollama::DEFAULT_MODEL;
				},
			)
		);
	}
);

/**
 * シークレット（APIキー）の保存。空欄は「変更しない」、削除チェックで消去。
 *
 * @param mixed $value
 */
function node_ai_sanitize_secret( $value, string $option_name ): string {
	if ( ! empty( $_POST[ $option_name . '_clear' ] ) ) {
		return '';
	}
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return (string) get_option( $option_name, '' );
	}
	return sanitize_text_field( $value );
}

/**
 * シークレットのマスク表示（末尾4文字のみ）
 */
function node_ai_mask_secret( string $secret ): string {
	if ( '' === $secret ) {
		return '';
	}
	$tail = mb_substr( $secret, -4 );
	return '••••••••' . $tail;
}

// ------------------------------------------------------------------
// 接続テスト（admin-post）
// ------------------------------------------------------------------

add_action(
	'admin_post_node_ai_test_connection',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'node_ai_test_connection' );

		$provider_id = isset( $_POST['provider'] ) ? sanitize_key( (string) $_POST['provider'] ) : '';
		$provider    = node_ai_core()->get_provider( get_current_user_id(), $provider_id );

		if ( is_wp_error( $provider ) ) {
			$result = array(
				'ok'      => false,
				'message' => $provider->get_error_message(),
			);
		} else {
			$test   = $provider->test_connection();
			$result = is_wp_error( $test )
				? array(
					'ok'      => false,
					'message' => '[' . $provider->get_label() . '] ' . $test->get_error_message(),
				)
				: array(
					'ok'      => true,
					'message' => '[' . $provider->get_label() . '] 接続テストに成功しました。',
				);
		}

		set_transient( 'node_ai_test_result_' . get_current_user_id(), $result, 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=node-ai' ) );
		exit;
	}
);

add_action(
	'admin_post_node_ai_refresh_fc_models',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'node_ai_refresh_fc_models' );

		Node_AI_Fact_Check_Models::clear_cache();
		Node_AI_Fact_Check_Models::fetch_catalog( true, get_current_user_id() );

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'options-general.php?page=node-ai' ) );
		exit;
	}
);

add_action(
	'admin_post_node_ai_clear_usage',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'node_ai_clear_usage' );
		delete_option( Node_AI_Core::USAGE_LOG_OPTION );
		wp_safe_redirect( admin_url( 'options-general.php?page=node-ai' ) );
		exit;
	}
);

// ------------------------------------------------------------------
// 画面描画
// ------------------------------------------------------------------

function node_ai_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$core        = node_ai_core();
	$provider_id = $core->get_provider_id();

	$test_result = get_transient( 'node_ai_test_result_' . get_current_user_id() );
	if ( false !== $test_result ) {
		delete_transient( 'node_ai_test_result_' . get_current_user_id() );
	}

	$gemini_key  = (string) get_option( 'node_ai_gemini_api_key', '' );
	$qwen_key    = (string) get_option( 'node_ai_qwen_api_key', '' );
	$ollama_model = trim( (string) get_option( 'node_ai_ollama_model', '' ) );
	$ollama_presets = Node_AI_Provider_Ollama::preset_models();
	$ollama_is_custom = '' !== $ollama_model && ! isset( $ollama_presets[ $ollama_model ] );

	$usage_log     = $core->get_usage_log();
	$monthly_count = $core->get_monthly_usage_count();
	?>
	<div class="wrap">
		<h1>Luna AI 設定</h1>
		<p class="description">記事制作を支援するAI機能（要約・ファクトチェックなど）の接続先を設定します。APIキーはフロントページへ出力されません。</p>

		<?php if ( is_array( $test_result ) ) : ?>
			<div class="notice <?php echo $test_result['ok'] ? 'notice-success' : 'notice-error'; ?> is-dismissible">
				<p><?php echo esc_html( (string) $test_result['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'node_ai_group' ); ?>

			<h2>AIプロバイダー</h2>
			<p class="description">使用するAIサービスを1つ選びます。選んだプロバイダーの設定だけが表示されます。</p>
			<fieldset id="node-ai-provider-select">
				<?php
				$provider_labels = array(
					'gemini' => 'Gemini（標準・推奨） — Google のクラウドAI。検索を参照したファクトチェックに対応',
					'qwen'   => 'Qwen — OpenAI互換APIで接続（DashScope など）',
					'ollama' => 'Ollama — 手元のPCで動くローカルAI。APIキー不要',
					'off'    => '使用しない — AI機能をすべて無効化',
				);
				foreach ( $provider_labels as $id => $label ) :
					?>
					<p>
						<label>
							<input type="radio" name="node_ai_provider" value="<?php echo esc_attr( $id ); ?>" <?php checked( $provider_id, $id ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					</p>
				<?php endforeach; ?>
			</fieldset>

			<!-- Gemini -->
			<div class="node-ai-provider-section" data-provider="gemini" style="<?php echo 'gemini' === $provider_id ? '' : 'display:none;'; ?>">
				<hr />
				<h2>Gemini 設定</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">サイト共通 API キー</th>
						<td>
							<?php if ( '' !== $gemini_key ) : ?>
								<p style="margin-top:0;"><code><?php echo esc_html( node_ai_mask_secret( $gemini_key ) ); ?></code>（設定済み）</p>
							<?php endif; ?>
							<input type="password" name="node_ai_gemini_api_key" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo '' !== $gemini_key ? '空欄のままで現在のキーを維持' : 'AIza… または AQ.…'; ?>" />
							<?php if ( '' !== $gemini_key ) : ?>
								<label style="margin-left:8px;"><input type="checkbox" name="node_ai_gemini_api_key_clear" value="1" /> キーを削除</label>
							<?php endif; ?>
							<p class="description">ライター個人のキー（プロフィール設定）が優先され、未登録のライターにはこのサイト共通キーが使われます。</p>
						</td>
					</tr>
				</table>
				<details>
					<summary>詳細設定（通常は変更不要）</summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">サイト既定モデルID</th>
							<td>
								<input type="text" name="node_ai_gemini_model" value="<?php echo esc_attr( (string) get_option( 'node_ai_gemini_model', '' ) ); ?>" class="regular-text" placeholder="空欄で自動選択（Flash系優先）" />
								<p class="description" style="margin-top:12px;">
									<input type="hidden" name="node_ai_auto_alt" value="0" />
									<label>
										<input type="checkbox" name="node_ai_auto_alt" value="1" <?php checked( function_exists( 'node_ai_auto_alt_enabled' ) && node_ai_auto_alt_enabled() ); ?> />
										アイキャッチの代替テキスト（alt）が未設定のとき、保存後に自動生成する
									</label><br>
									<span>既に alt がある画像は書き換えません。生成は保存の約30秒後にバックグラウンドで実行され、失敗しても公開は妨げません。</span>
								</p>
								<p class="description">個人設定でモデル未選択のライターに適用される既定モデルです。</p>
							</td>
						</tr>
					</table>
				</details>

				<h3>ファクトチェックのモデル（無料枠優先）</h3>
				<p class="description">
					ファクトチェックは Gemini API の無料枠だけで完結するように動きます。
					有料モデルや有料機能へ自動で切り替えることはありません。
				</p>
				<?php node_ai_render_fact_check_model_fields(); ?>
			</div>

			<!-- Qwen -->
			<div class="node-ai-provider-section" data-provider="qwen" style="<?php echo 'qwen' === $provider_id ? '' : 'display:none;'; ?>">
				<hr />
				<h2>Qwen 設定（OpenAI互換API）</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">エンドポイント URL</th>
						<td>
							<input type="url" name="node_ai_qwen_endpoint" value="<?php echo esc_attr( (string) get_option( 'node_ai_qwen_endpoint', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( Node_AI_Provider_Qwen::DEFAULT_ENDPOINT ); ?>" />
							<p class="description">空欄で既定（DashScope 国際版の互換モード）。他の OpenAI 互換サービスの URL も指定できます。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">API キー</th>
						<td>
							<?php if ( '' !== $qwen_key ) : ?>
								<p style="margin-top:0;"><code><?php echo esc_html( node_ai_mask_secret( $qwen_key ) ); ?></code>（設定済み）</p>
							<?php endif; ?>
							<input type="password" name="node_ai_qwen_api_key" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo '' !== $qwen_key ? '空欄のままで現在のキーを維持' : 'sk-…'; ?>" />
							<?php if ( '' !== $qwen_key ) : ?>
								<label style="margin-left:8px;"><input type="checkbox" name="node_ai_qwen_api_key_clear" value="1" /> キーを削除</label>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<details>
					<summary>詳細設定（通常は変更不要）</summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">モデルID</th>
							<td>
								<input type="text" name="node_ai_qwen_model" value="<?php echo esc_attr( (string) get_option( 'node_ai_qwen_model', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( Node_AI_Provider_Qwen::DEFAULT_MODEL ); ?>" />
								<p class="description">空欄で推奨モデル（<?php echo esc_html( Node_AI_Provider_Qwen::DEFAULT_MODEL ); ?>）を使用します。</p>
							</td>
						</tr>
					</table>
				</details>
			</div>

			<!-- Ollama -->
			<div class="node-ai-provider-section" data-provider="ollama" style="<?php echo 'ollama' === $provider_id ? '' : 'display:none;'; ?>">
				<hr />
				<h2>Ollama 設定（ローカルAI）</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">接続先 URL</th>
						<td>
							<input type="url" name="node_ai_ollama_url" value="<?php echo esc_attr( (string) get_option( 'node_ai_ollama_url', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( Node_AI_Provider_Ollama::DEFAULT_URL ); ?>" />
							<p class="description">空欄で既定（同じPC上の Ollama）。APIキーは不要です。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">モデル</th>
						<td>
							<select name="node_ai_ollama_model">
								<?php foreach ( $ollama_presets as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( ! $ollama_is_custom && ( $ollama_model === $id || ( '' === $ollama_model && Node_AI_Provider_Ollama::DEFAULT_MODEL === $id ) ) ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( $ollama_is_custom ) : ?>
								<p class="description">現在は詳細設定の任意モデル「<code><?php echo esc_html( $ollama_model ); ?></code>」を使用中です。</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<details <?php echo $ollama_is_custom ? 'open' : ''; ?>>
					<summary>詳細設定（通常は変更不要）</summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">任意モデルID</th>
							<td>
								<input type="text" name="node_ai_ollama_model_custom" value="<?php echo esc_attr( $ollama_is_custom ? $ollama_model : '' ); ?>" class="regular-text" placeholder="例: llama3.3, qwen3:14b" />
								<p class="description">入力すると上のモデル選択より優先されます。空欄に戻すと選択式に戻ります。</p>
							</td>
						</tr>
					</table>
				</details>
			</div>

			<?php submit_button( '設定を保存' ); ?>
		</form>

		<?php if ( 'off' !== $provider_id ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:-8px;">
				<?php wp_nonce_field( 'node_ai_test_connection' ); ?>
				<input type="hidden" name="action" value="node_ai_test_connection" />
				<input type="hidden" name="provider" value="<?php echo esc_attr( $provider_id ); ?>" />
				<?php submit_button( '接続テスト（保存済みの設定で確認）', 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<hr style="margin-top:24px;" />
		<h2>利用履歴</h2>
		<p>今月の実行回数: <strong><?php echo esc_html( (string) $monthly_count ); ?></strong> 回（直近<?php echo esc_html( (string) Node_AI_Core::USAGE_LOG_LIMIT ); ?>件の履歴から集計。料金の正確な計算は行いません）</p>

		<?php if ( empty( $usage_log ) ) : ?>
			<p class="description">まだAI機能は実行されていません。</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px;">
				<thead>
					<tr>
						<th>日時</th>
						<th>機能</th>
						<th>プロバイダー</th>
						<th>モデル</th>
						<th>記事</th>
						<th>結果</th>
						<th>トークン</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $usage_log, 0, 20 ) as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $entry['feature'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $entry['provider'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $entry['model'] ?? '' ) ); ?></td>
							<td>
								<?php
								$entry_post_id = (int) ( $entry['post_id'] ?? 0 );
								if ( $entry_post_id > 0 ) {
									echo '<a href="' . esc_url( get_edit_post_link( $entry_post_id ) ?? '' ) . '">#' . esc_html( (string) $entry_post_id ) . '</a>';
								} else {
									echo '—';
								}
								?>
							</td>
							<td><?php echo ! empty( $entry['ok'] ) ? '成功' : '<span style="color:#d63638;">失敗' . ( ! empty( $entry['error_code'] ) ? ' (' . esc_html( (string) $entry['error_code'] ) . ')' : '' ) . '</span>'; ?></td>
							<td><?php echo esc_html( (string) (int) ( $entry['tokens'] ?? 0 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
				<?php wp_nonce_field( 'node_ai_clear_usage' ); ?>
				<input type="hidden" name="action" value="node_ai_clear_usage" />
				<?php submit_button( '履歴をクリア', 'delete small', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	</div>

	<script>
	( function () {
		var radios = document.querySelectorAll( '#node-ai-provider-select input[name="node_ai_provider"]' );
		var sections = document.querySelectorAll( '.node-ai-provider-section' );
		function sync() {
			var selected = document.querySelector( '#node-ai-provider-select input[name="node_ai_provider"]:checked' );
			var value = selected ? selected.value : 'gemini';
			sections.forEach( function ( section ) {
				section.style.display = section.getAttribute( 'data-provider' ) === value ? '' : 'none';
			} );
		}
		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', sync );
		} );
	} )();
	</script>
	<?php
}

/**
 * ファクトチェック用モデル設定の描画（現在のモデル・候補・更新日時）。
 */
function node_ai_render_fact_check_model_fields(): void {
	$status     = Node_AI_Fact_Check_Models::status();
	$candidates = (array) $status['candidates'];
	$selected   = (string) $status['selected'];
	$catalog    = (array) $status['catalog'];
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">モデルの選び方</th>
			<td>
				<p>
					<label>
						<input type="radio" name="<?php echo esc_attr( Node_AI_Fact_Check_Models::MODE_OPTION ); ?>" value="auto" <?php checked( 'auto', (string) $status['mode'] ); ?> />
						自動（無料枠推奨）— そのとき利用できる最新の無料 Flash を選びます
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="<?php echo esc_attr( Node_AI_Fact_Check_Models::MODE_OPTION ); ?>" value="manual" <?php checked( 'manual', (string) $status['mode'] ); ?> />
						手動 — 下のモデルを固定で使います
					</label>
				</p>
				<p>
					<select name="<?php echo esc_attr( Node_AI_Fact_Check_Models::MANUAL_OPTION ); ?>">
						<option value="">（未指定）</option>
						<?php
						$manual = (string) get_option( Node_AI_Fact_Check_Models::MANUAL_OPTION, '' );
						foreach ( $candidates as $id ) {
							$label = (string) ( $catalog[ $id ]['label'] ?? $id );
							printf(
								'<option value="%s" %s>%s（%s）</option>',
								esc_attr( (string) $id ),
								selected( $manual, (string) $id, false ),
								esc_html( $label ),
								esc_html( (string) $id )
							);
						}
						?>
					</select>
				</p>
				<p>
					<label>
						<input type="hidden" name="<?php echo esc_attr( Node_AI_Fact_Check_Models::ALLOW_PAID_OPTION ); ?>" value="0" />
						<input type="checkbox" name="<?php echo esc_attr( Node_AI_Fact_Check_Models::ALLOW_PAID_OPTION ); ?>" value="1" <?php checked( ! empty( $status['allow_paid'] ) ); ?> />
						無料枠以外のモデル（有料・Preview 等）の使用を許可する
					</label>
					<br />
					<span class="description">既定はオフです。オフの間は、無料枠と確認できたモデル以外は使いません。</span>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">現在の状態</th>
			<td>
				<?php if ( '' !== $selected ) : ?>
					<p style="margin-top:0;">
						使用中: <code><?php echo esc_html( $selected ); ?></code>
						（<?php echo esc_html( (string) ( $catalog[ $selected ]['label'] ?? $selected ) ); ?> /
						<?php echo esc_html( Node_AI_Fact_Check_Models::classify( $selected ) ); ?> /
						version <?php echo esc_html( (string) ( $catalog[ $selected ]['version'] ?? '不明' ) ); ?> /
						思考量 <?php echo Node_AI_Fact_Check_Models::supports_thinking( $selected ) ? '対応' : '非対応'; ?>）
					</p>
				<?php else : ?>
					<p style="margin-top:0;color:#d63638;"><?php echo esc_html( (string) $status['error'] ); ?></p>
				<?php endif; ?>
				<p>
					モード: <strong><?php echo 'auto' === (string) $status['mode'] ? '自動（無料枠推奨）' : '手動'; ?></strong> ／
					フォールバック候補: <code><?php echo esc_html( implode( ' → ', array_map( 'strval', $candidates ) ) ); ?></code>
				</p>
				<p>
					モデル一覧の取得: <?php echo ! empty( $status['from_api'] ) ? 'Gemini Models API' : '静的候補（API 未取得）'; ?>
					<?php if ( ! empty( $status['fetched_at'] ) ) : ?>
						／ 最終更新: <?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $status['fetched_at'] ) ); ?>
					<?php endif; ?>
				</p>
				<?php if ( ! empty( $status['retired'] ) ) : ?>
					<p>提供終了として除外中: <code><?php echo esc_html( implode( ', ', array_map( 'strval', (array) $status['retired'] ) ) ); ?></code></p>
				<?php endif; ?>
				<p>
					今月の Google 検索（グラウンディング）実行: <strong><?php echo esc_html( (string) Node_AI_Fact_Check_Runner::grounding_usage() ); ?></strong>
					/ <?php echo esc_html( (string) Node_AI_Fact_Check_Runner::grounding_cap() ); ?> 回（自主上限）
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Google 検索の参照</th>
			<td>
				<?php $search_policy = Node_AI_Fact_Check_Runner::search_policy(); ?>
				<p>
					<label>
						<input type="radio" name="<?php echo esc_attr( Node_AI_Fact_Check_Runner::GROUNDING_OPTION ); ?>" value="always" <?php checked( 'always', $search_policy ); ?> />
						常に検索つきで実行する（推奨）— 最新の無料 Flash から順に検索つきで試します
					</label>
					<br />
					<span class="description">
						どのモデルでも検索が使えなかったときだけ、検索なしの暫定結果を保存し（確信度を下げ、断定は避けます）、枠が回復した翌日に自動で取り直します。
					</span>
				</p>
				<p>
					<label>
						<input type="radio" name="<?php echo esc_attr( Node_AI_Fact_Check_Runner::GROUNDING_OPTION ); ?>" value="required" <?php checked( 'required', $search_policy ); ?> />
						検索つきで実行できないときは中止する（暫定結果を残さない）
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="<?php echo esc_attr( Node_AI_Fact_Check_Runner::GROUNDING_OPTION ); ?>" value="off" <?php checked( 'off', $search_policy ); ?> />
						使用しない
					</label>
				</p>
				<p>
					<input type="hidden" name="<?php echo esc_attr( Node_AI_Fact_Check_Sources::ENABLED_OPTION ); ?>" value="0" />
					<label>
						<input type="checkbox" name="<?php echo esc_attr( Node_AI_Fact_Check_Sources::ENABLED_OPTION ); ?>" value="1" <?php checked( Node_AI_Fact_Check_Sources::is_enabled() ); ?> />
						記事本文中の公式ページを取得して検証の根拠に使う（robots.txt を尊重し、最大3件まで）
					</label>
				</p>
				<p>
					<input type="hidden" name="<?php echo esc_attr( Node_AI_Fact_Check_Discovery::ENABLED_OPTION ); ?>" value="0" />
					<label>
						<input type="checkbox" name="<?php echo esc_attr( Node_AI_Fact_Check_Discovery::ENABLED_OPTION ); ?>" value="1" <?php checked( Node_AI_Fact_Check_Discovery::is_enabled() ); ?> />
						記事に公式リンクが無い場合、主題（製品名・企業名）から公式サイトを自動で探す
					</label>
					<br />
					<span class="description">
						Wikidata の「公式ウェブサイト」情報から公式サイトの所在を引き、そのページ本文だけを根拠にします（無料・APIキー不要）。
						検索エンジンのスクレイピングは行いません。公式と確認できなかったページは根拠に加えません。
					</span>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">公式（一次情報）ドメイン</th>
			<td>
				<textarea name="<?php echo esc_attr( Node_AI_Fact_Check_Sources::OFFICIAL_HOSTS_OPTION ); ?>" rows="4" class="large-text code" placeholder="nintendo.co.jp&#10;example.com"><?php echo esc_textarea( (string) get_option( Node_AI_Fact_Check_Sources::OFFICIAL_HOSTS_OPTION, '' ) ); ?></textarea>
				<p class="description">
					1行に1ドメイン。サブドメインは自動で同一扱いになります（<code>support.example.com</code> も <code>example.com</code> として公式）。<br />
					下のプリセットに含まれるドメインは、ここに書かなくても最初から公式として扱われます。報道機関（NewsMediaOrganization）は一次情報として扱いません。
				</p>
				<details>
					<summary>プリセット（最初から公式として扱うドメイン <?php echo esc_html( (string) count( Node_AI_Fact_Check_Sources::preset_official_hosts() ) ); ?> 件）</summary>
					<p class="description" style="margin-top:8px;">
						<?php echo esc_html( implode( '  /  ', Node_AI_Fact_Check_Sources::preset_official_hosts() ) ); ?>
					</p>
					<p class="description">
						この一覧はプラグインに内蔵されています。増減させたい場合は上の欄へ追記するか、<code>node_ai_fc_preset_official_hosts</code> フィルタで差し替えてください。
					</p>
				</details>
			</td>
		</tr>
	</table>
	<p>
		<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=node_ai_refresh_fc_models' ), 'node_ai_refresh_fc_models' ) ); ?>">
			モデル情報を今すぐ更新
		</a>
	</p>
	<?php
}
