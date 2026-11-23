<?php

declare(strict_types=1);
/**
 * Gemini API 設定 — ライター個別（user_meta）
 *
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 指定ユーザーの Gemini 設定を表示・編集できるか
 *
 * @param int $target_user_id 対象ユーザーID。
 */
function node_can_edit_gemini_settings( int $target_user_id ): bool {
	$current_user_id = get_current_user_id();

	if ( $target_user_id === $current_user_id ) {
		return current_user_can( 'edit_posts' );
	}

	return current_user_can( 'edit_user', $target_user_id ) && user_can( $target_user_id, 'edit_posts' );
}

/**
 * プロフィールに Gemini 設定欄を出すか（投稿権限のあるユーザーのみ）
 *
 * @param int $target_user_id 対象ユーザーID。
 */
function node_should_show_gemini_profile_fields( int $target_user_id ): bool {
	if ( ! user_can( $target_user_id, 'edit_posts' ) ) {
		return false;
	}

	if ( get_current_user_id() === $target_user_id ) {
		return current_user_can( 'edit_posts' );
	}

	return current_user_can( 'edit_user', $target_user_id );
}

/**
 * user_meta から API キーを取得
 *
 * @param int $user_id ユーザーID。0 の場合は現在のユーザー。
 */
function node_get_user_gemini_api_key( int $user_id = 0 ): string {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return '';
	}

	$key = get_user_meta( $user_id, 'node_gemini_api_key', true );
	return is_string( $key ) ? trim( $key ) : '';
}

/**
 * user_meta からモデル名を取得
 *
 * @param int $user_id ユーザーID。0 の場合は現在のユーザー。
 */
function node_get_user_gemini_model( int $user_id = 0 ): string {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	$model   = $user_id > 0 ? get_user_meta( $user_id, 'node_gemini_model', true ) : '';
	$model   = is_string( $model ) ? trim( $model ) : '';

	if ( '' !== $model && node_is_valid_gemini_model_id( $model ) ) {
		return $model;
	}

	return node_get_default_gemini_model();
}

/**
 * Gemini 設定フィールドを出力
 *
 * @param WP_User $user           対象ユーザー。
 * @param bool    $is_settings_page Luminous Settings 用の文言切り替え。
 */
function node_render_gemini_user_fields( WP_User $user, bool $is_settings_page = false ): void {
	if ( ! node_can_edit_gemini_settings( $user->ID ) ) {
		return;
	}

	$has_key        = '' !== node_get_user_gemini_api_key( $user->ID );
	$current_model  = node_get_user_gemini_model( $user->ID );
	$model_options  = node_get_gemini_model_options_for_user( $user->ID );
	$models_meta    = node_get_gemini_models_meta( $user->ID );
	$is_own         = get_current_user_id() === (int) $user->ID;
	?>
	<h2><?php esc_html_e( 'Gemini API（要約・TTS・ファクトチェック専用）', 'node' ); ?></h2>
	<p class="description">
		<?php esc_html_e( '校正・タイトル提案・SNS文案・画像alt生成には、このキーを使用しません。ファクトチェックのモデルは専用の無料枠ポリシーで選びます。', 'node' ); ?>
		<br>
		<?php if ( $is_settings_page && $is_own ) : ?>
			<?php esc_html_e( 'この API キーはあなた専用です。他のライターとは共有されません。', 'node' ); ?>
		<?php elseif ( $is_own ) : ?>
			<?php esc_html_e( 'AI要約・TTS・ファクトチェック専用の、あなたのGemini APIキーです。', 'node' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'このライター個人の Gemini API 設定です。', 'node' ); ?>
		<?php endif; ?>
		<?php if ( ! $is_settings_page && $is_own ) : ?>
			<br><?php esc_html_e( '設定場所: ユーザー → プロフィール', 'node' ); ?>
		<?php endif; ?>
	</p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="node_gemini_api_key"><?php esc_html_e( 'Gemini API Key', 'node' ); ?></label></th>
			<td>
				<input
					type="password"
					name="node_gemini_api_key"
					id="node_gemini_api_key"
					class="regular-text"
					value=""
					autocomplete="new-password"
					placeholder="<?php echo $has_key ? esc_attr__( '登録済み（変更する場合のみ入力）', 'node' ) : esc_attr__( 'API キーを入力', 'node' ); ?>"
				/>
				<?php if ( $has_key ) : ?>
					<p class="description">
						<?php esc_html_e( 'キーは登録済みです。変更しない場合は空欄のまま保存してください。', 'node' ); ?>
					</p>
					<label>
						<input type="checkbox" name="node_gemini_api_key_clear" value="1" />
						<?php esc_html_e( 'API キーを削除する', 'node' ); ?>
					</label>
				<?php else : ?>
					<p class="description">
						<?php esc_html_e( 'Google AI Studio 等で発行したキーを入力してください。', 'node' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="node_gemini_model"><?php esc_html_e( 'Gemini Model', 'node' ); ?></label></th>
			<td>
				<?php $current_selection = node_split_gemini_model( $current_model ); ?>
				<select name="node_gemini_model" id="node_gemini_model">
					<?php foreach ( $model_options as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_selection['model'], $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<label for="node_gemini_thinking" class="screen-reader-text"><?php esc_html_e( '思考量', 'node' ); ?></label>
				<select name="node_gemini_thinking" id="node_gemini_thinking">
					<?php foreach ( node_gemini_thinking_levels() as $level => $level_label ) : ?>
						<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $current_selection['thinking'], $level ); ?>>
							<?php echo esc_html( $level_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'AI 要約・ファクトチェックで使用するモデルです。', 'node' ); ?>
					<?php if ( $models_meta['from_api'] && $models_meta['fetched_at'] > 0 ) : ?>
						<br>
						<?php
						printf(
							/* translators: %s: human-readable time difference */
							esc_html__( '一覧は Gemini API から取得済み（%s 前）', 'node' ),
							esc_html( human_time_diff( $models_meta['fetched_at'], time() ) )
						);
						?>
					<?php elseif ( ! $has_key ) : ?>
						<br><?php esc_html_e( 'API キーを登録すると、利用可能な最新モデルを自動取得します。', 'node' ); ?>
					<?php else : ?>
						<br><?php esc_html_e( 'API からの取得に失敗したため、フォールバック一覧を表示しています。', 'node' ); ?>
					<?php endif; ?>
				</p>
			</td>
		</tr>
	</table>

	<?php if ( function_exists( 'node_gemini_get_user_quota_usage' ) ) : ?>
	<h3 style="margin-top: 30px;"><?php esc_html_e( 'Gemini API クォータ使用状況', 'node' ); ?></h3>
	<p class="description">
		<?php esc_html_e( 'この画面は、このWordPressサーバー経由で実行したリクエストのローカル概算です。Google側の正確な残量・請求額・無料枠の最新条件は取得していません。', 'node' ); ?>
		<br>
		<?php esc_html_e( 'RPM=1分あたりのリクエスト数、TPM=1分あたりのトークン数、RPD=1日あたりのリクエスト数です。料金ではなく、APIの利用上限の目安として確認してください。', 'node' ); ?>
	</p>
	<div class="node-gemini-quota-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; margin-top: 15px;">
		<?php foreach ( $model_options as $model_id => $model_label ) :
			$usage = node_gemini_get_user_quota_usage( $user->ID, $model_id );
			$limits = node_gemini_get_quota_limits( $model_id );
			$is_limit_zero = ( 0 === $limits['rpm'] && 0 === $limits['tpm'] && 0 === $limits['rpd'] );
			
			$is_current = ( $model_id === $current_model );
			$border_color = $is_current ? '#007cba' : '#e0e0e0';
			$bg_color = $is_current ? '#f0f8ff' : '#fafafa';
		?>
		<div class="node-quota-card" style="border: 1px solid <?php echo esc_attr( $border_color ); ?>; background: <?php echo esc_attr( $bg_color ); ?>; padding: 10px; border-radius: 6px;">
			<h4 style="margin: 0 0 8px 0; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">
				<?php echo esc_html( $model_label ); ?>
				<?php if ( $is_current ) : ?>
					<span style="font-size: 10px; background: #007cba; color: #fff; padding: 2px 5px; border-radius: 3px;">選択中</span>
				<?php endif; ?>
			</h4>

			<?php if ( $is_limit_zero ) : ?>
				<div style="padding: 6px 8px; background: #ffebee; border-left: 3px solid #f44336; margin-bottom: 0;">
					<p style="margin: 0; font-size: 11px; color: #d32f2f;">
						<strong>利用不可:</strong> このモデルは現在のクォータ設定では利用できません。<br>
						<?php if ( strpos( $model_id, 'pro' ) !== false ) : ?>
						Pro系で429や利用不可が出る場合は、Flash系への変更を推奨します。
						<?php endif; ?>
					</p>
				</div>
			<?php else : ?>

				<?php
				if ( ! empty( $usage['last_error'] ) && $usage['last_error']['expires'] > time() ) {
					$err = $usage['last_error'];
					if ( $err['retry'] > 0 ) {
						$err_msg = '短時間制限: 残り ' . max( 0, $err['expires'] - time() ) . ' 秒';
						$err_color = '#d32f2f';
						$err_bg = '#ffcdd2';
					} else {
						$err_msg = '日次上限（Quota）到達';
						$err_color = '#d32f2f';
						$err_bg = '#ffebee';
					}
					echo '<div style="margin-bottom: 8px; padding: 6px; background: ' . $err_bg . '; color: ' . $err_color . '; font-size: 11px; font-weight: bold; border-radius: 3px;">' . esc_html( $err_msg ) . '</div>';
				}
				?>

				<?php
				$metrics = [
					'RPM' => [ 'label' => 'RPM（リクエスト/分）', 'val' => $usage['rpm']['count'], 'max' => $limits['rpm'] ],
					'TPM' => [ 'label' => 'TPM（トークン/分）', 'val' => $usage['tpm']['count'], 'max' => $limits['tpm'] ],
					'RPD' => [ 'label' => 'RPD（リクエスト/日）', 'val' => $usage['rpd']['count'], 'max' => $limits['rpd'] ],
				];
				foreach ( $metrics as $key => $m ) :
					$val = $m['val'];
					$max = $m['max'];
					
					$low = $max * 0.7;
					$high = $max * 0.9;
					$optimum = $max * 0.3;
				?>
				<div style="margin-bottom: 4px;">
					<div style="display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 2px; color: #555;">
						<span><?php echo esc_html( $m['label'] ); ?></span>
						<span><?php echo esc_html( number_format( $val ) . '/' . number_format( $max ) ); ?></span>
					</div>
					<meter 
						value="<?php echo esc_attr( $val ); ?>" 
						min="0" 
						max="<?php echo esc_attr( $max ); ?>"
						low="<?php echo esc_attr( $low ); ?>"
						high="<?php echo esc_attr( $high ); ?>"
						optimum="<?php echo esc_attr( $optimum ); ?>"
						style="width: 100%; height: 8px; border-radius: 3px; overflow: hidden; background: #eee; display: block;"
					></meter>
				</div>
				<?php endforeach; ?>

			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	</div>
	<style>
	meter::-webkit-meter-bar { background: #eee; border: none; }
	meter::-webkit-meter-optimum-value { background: #4caf50; }
	meter::-webkit-meter-suboptimum-value { background: #ffeb3b; }
	meter::-webkit-meter-even-less-good-value { background: #f44336; }
	meter:-moz-meter-optimum::-moz-meter-bar { background: #4caf50; }
	meter:-moz-meter-sub-optimum::-moz-meter-bar { background: #ffeb3b; }
	meter:-moz-meter-sub-sub-optimum::-moz-meter-bar { background: #f44336; }
	@media (max-width: 782px) {
		.node-quota-card { width: 100% !important; }
	}
	</style>
	<?php endif; ?>

	<?php
}

/**
 * Gemini 設定を user_meta に保存
 *
 * @param int $user_id 保存対象ユーザーID。
 */
function node_save_gemini_user_meta( int $user_id ): void {
	if ( $user_id <= 0 || ! node_can_edit_gemini_settings( $user_id ) ) {
		return;
	}

	$key_changed = false;

	if ( ! empty( $_POST['node_gemini_api_key_clear'] ) ) {
		delete_user_meta( $user_id, 'node_gemini_api_key' );
		$key_changed = true;
	} elseif ( isset( $_POST['node_gemini_api_key'] ) ) {
		$new_key = sanitize_text_field( wp_unslash( (string) $_POST['node_gemini_api_key'] ) );
		if ( '' !== $new_key ) {
			update_user_meta( $user_id, 'node_gemini_api_key', $new_key );
			$key_changed = true;
		}
	}

	if ( $key_changed ) {
		node_clear_gemini_models_cache();
	}

	if ( isset( $_POST['node_gemini_model'] ) ) {
		$model   = sanitize_text_field( wp_unslash( (string) $_POST['node_gemini_model'] ) );
		$options = node_get_gemini_model_options_for_user( $user_id );

		// 思考量は別プルダウンだが、保存は `<モデルID>@<思考量>` の1値にまとめる（1.2 系と互換）。
		// 思考量に非対応のモデルには付けない
		$thinking = isset( $_POST['node_gemini_thinking'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['node_gemini_thinking'] ) )
			: '';

		if ( ! node_gemini_model_supports_thinking( $model, $user_id ) ) {
			$thinking = '';
		}

		$stored = node_join_gemini_model( $model, $thinking );

		if ( isset( $options[ $model ] ) || node_is_valid_gemini_model_id( $stored ) ) {
			update_user_meta( $user_id, 'node_gemini_model', $stored );
		}
	}
}

/**
 * 自分のプロフィール画面
 *
 * @param WP_User $user ユーザー。
 */
function node_render_gemini_profile_fields( WP_User $user ): void {
	if ( ! node_should_show_gemini_profile_fields( $user->ID ) ) {
		return;
	}

	node_render_gemini_user_fields( $user, false );
}

/**
 * プロフィール保存（自分）
 *
 * @param int $user_id ユーザーID。
 */
function node_save_gemini_profile_fields( int $user_id ): void {
	node_save_gemini_user_meta( $user_id );
}

add_action( 'show_user_profile', 'node_render_gemini_profile_fields' );
add_action( 'edit_user_profile', 'node_render_gemini_profile_fields' );
add_action( 'personal_options_update', 'node_save_gemini_profile_fields' );
add_action( 'edit_user_profile_update', 'node_save_gemini_profile_fields' );

/**
 * Luminous Settings からの保存（管理者自身のキー）
 */
function node_save_user_gemini_settings_from_options_page(): void {
	if ( ! isset( $_POST['node_gemini_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['node_gemini_settings_nonce'] ) ), 'node_save_gemini' ) ) {
		return;
	}

	node_save_gemini_user_meta( get_current_user_id() );
}
add_action( 'admin_init', 'node_save_user_gemini_settings_from_options_page' );
