<?php
/**
 * Node Connect X投稿メタボックス。
 *
 * ブロックエディタの右ペインとクラシックエディタに、記事別の投稿文・
 * 自動投稿の除外設定・投稿済み状態を表示する。
 *
 * @package Node_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'add_meta_boxes',
	static function (): void {
		add_meta_box(
			'node_connect_x_post',
			'X投稿（Luna Connect）',
			'node_connect_render_x_meta_box',
			'post',
			'side',
			'default',
			[
				'__back_compat_meta_box' => true,
			]
		);
	}
);

add_action(
	'enqueue_block_editor_assets',
	static function (): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof WP_Screen || 'post' !== $screen->post_type ) {
			return;
		}

		$handle = 'node-connect-x-editor';
		$editor_templates = [];
		foreach ( Node_Connect_X_Poster::get_templates() as $template_id => $template ) {
			$editor_templates[] = [
				'label' => $template['name'],
				'value' => $template_id,
			];
		}
		wp_enqueue_script(
			$handle,
			plugin_dir_url( __FILE__ ) . 'editor-x-post.js',
			[ 'wp-components', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-plugins' ],
			NODE_CONNECT_VERSION,
			true
		);
		wp_localize_script(
			$handle,
			'nodeConnectXEditor',
			[
				'textMetaKey'     => Node_Connect_X_Poster::TEXT_META,
				'skipMetaKey'     => Node_Connect_X_Poster::SKIP_META,
				'templateMetaKey' => Node_Connect_X_Poster::TEMPLATE_META,
				'templates'       => $editor_templates,
				'limit'           => Node_Connect_X_Poster::CUSTOM_TEXT_LIMIT,
			]
		);
	}
);

/**
 * メタボックス描画。
 */
function node_connect_render_x_meta_box( WP_Post $post ): void {
	$skip      = '1' === (string) get_post_meta( $post->ID, Node_Connect_X_Poster::SKIP_META, true );
	$posted_at = (int) get_post_meta( $post->ID, Node_Connect_X_Poster::POSTED_META, true );
	$custom    = (string) get_post_meta( $post->ID, Node_Connect_X_Poster::TEXT_META, true );
	$template_key = Node_Connect_X_Poster::sanitize_template_key( get_post_meta( $post->ID, Node_Connect_X_Poster::TEMPLATE_META, true ) );
	$preview   = Node_Connect_X_Poster::get_post_text( $post );

	$intent_url = 'https://twitter.com/intent/tweet?text=' . rawurlencode( $preview );

	wp_nonce_field( 'node_connect_x_meta', 'node_connect_x_meta_nonce' );
	?>
	<?php if ( $posted_at > 0 ) : ?>
		<p style="margin-top: 0;">自動投稿済み（<?php echo esc_html( wp_date( 'Y-m-d H:i', $posted_at ) ); ?>）</p>
	<?php elseif ( Node_Connect_X_Poster::is_enabled() ) : ?>
		<p style="margin-top: 0;">この記事の新規公開時に、下のプレビュー内容で自動投稿されます。</p>
	<?php else : ?>
		<p style="margin-top: 0;">自動投稿は無効です（設定 → 外部連携で有効化できます）。下のボタンから手動投稿できます。</p>
	<?php endif; ?>

	<p style="margin-bottom: 4px;"><strong>投稿文言テンプレート</strong></p>
	<select name="node_connect_x_template" style="width: 100%;">
		<?php foreach ( Node_Connect_X_Poster::get_templates() as $key => $template ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $template_key, $key ); ?>><?php echo esc_html( $template['name'] ); ?></option>
		<?php endforeach; ?>
	</select>

	<p style="margin-bottom: 4px;"><strong>投稿文（この記事のみ）</strong></p>
	<textarea name="node_connect_x_text" rows="7" maxlength="<?php echo (int) Node_Connect_X_Poster::CUSTOM_TEXT_LIMIT; ?>" style="width: 100%; font-size: 12px;" placeholder="<?php echo esc_attr( $preview ); ?>"><?php echo esc_textarea( $custom ); ?></textarea>
	<p class="description">140文字以内で編集できます。空欄なら選択した投稿文言テンプレートを使用します。</p>

	<p>
		<a href="<?php echo esc_url( $intent_url ); ?>" target="_blank" rel="noopener noreferrer" class="button">Xで投稿画面を開く</a>
	</p>

	<label>
		<input type="checkbox" name="node_connect_x_skip" value="1" <?php checked( $skip ); ?> />
		この記事は自動投稿しない
	</label>
	<p class="description" style="margin-bottom: 0;">公開の約<?php echo (int) Node_Connect_X_Poster::DELIVERY_DELAY; ?>秒後に送信されます。それまでにチェックを保存すれば自動投稿を止められます。自動投稿は記事1件につき1回だけ行われます。</p>
	<?php
}

add_action(
	'save_post_post',
	static function ( int $post_id ): void {
		if ( ! isset( $_POST['node_connect_x_meta_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( (string) $_POST['node_connect_x_meta_nonce'], 'node_connect_x_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// ブロックエディタはRESTメタを正本とする。互換メタボックスの入力欄が
		// 送信されていない2段階目の保存で、直前のREST値を削除しない。
		if ( ! isset( $_POST['node_connect_x_text'] ) ) {
			return;
		}

		if ( ! empty( $_POST['node_connect_x_skip'] ) ) {
			update_post_meta( $post_id, Node_Connect_X_Poster::SKIP_META, '1' );
		} else {
			delete_post_meta( $post_id, Node_Connect_X_Poster::SKIP_META );
		}

		$template_key = Node_Connect_X_Poster::sanitize_template_key( wp_unslash( $_POST['node_connect_x_template'] ?? '' ) );
		update_post_meta( $post_id, Node_Connect_X_Poster::TEMPLATE_META, $template_key );

		$custom_text = Node_Connect_X_Poster::sanitize_post_text( wp_unslash( $_POST['node_connect_x_text'] ) );
		if ( '' !== $custom_text ) {
			update_post_meta( $post_id, Node_Connect_X_Poster::TEXT_META, $custom_text );
		} else {
			delete_post_meta( $post_id, Node_Connect_X_Poster::TEXT_META );
		}
	}
);
