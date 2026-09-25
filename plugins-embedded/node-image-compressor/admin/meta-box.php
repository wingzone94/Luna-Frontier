<?php
/**
 * 投稿編集画面のメタボックス。
 *
 * 独立ページへ行かなくても、いま編集している記事のアイキャッチをその場で
 * WebP へ置き換え・復元できるようにする。マークアップは対象一覧のカード 1 枚と同じ構造に
 * 揃えてあるので、admin.js のイベント委譲と admin.css の M3 スタイルをそのまま使える。
 *
 * @package Node_Image_Compressor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'add_meta_boxes',
	static function (): void {
		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( ! post_type_supports( $post_type, 'thumbnail' ) ) {
				continue;
			}

			add_meta_box(
				'node_ic_featured',
				'アイキャッチの WebP 置き換え',
				'node_ic_render_meta_box',
				$post_type,
				'side',
				'default'
			);
		}
	}
);

/**
 * メタボックス本体。
 *
 * @param WP_Post $post 編集中の投稿。
 */
function node_ic_render_meta_box( WP_Post $post ): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		echo '<p>この機能を使うには管理者権限が必要です。</p>';
		return;
	}

	$page_url = menu_page_url( 'luminous-settings', false )
		? admin_url( 'admin.php?page=node-image-compressor' )
		: admin_url( 'upload.php?page=node-image-compressor' );

	$attachment_id = (int) get_post_thumbnail_id( $post );
	?>
	<div class="node-ic-app node-ic-app--metabox">
		<?php if ( $attachment_id <= 0 ) : ?>
			<p class="node-ic-metabox__hint">アイキャッチ画像が未設定です。設定して保存すると、ここから WebP に置き換えできます。</p>
		<?php elseif ( ! Node_IC_Converter::is_supported() ) : ?>
			<p class="node-ic-metabox__hint">このサーバーは WebP の書き出しに対応していないため、置き換えできません。</p>
		<?php else : ?>
			<?php
			$status    = node_ic_get_status( $attachment_id );
			$thumbnail = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
			$filename  = wp_basename( (string) get_attached_file( $attachment_id ) );
			?>
			<div class="node-ic-item" data-node-ic-item="<?php echo esc_attr( (string) $attachment_id ); ?>" data-state="<?php echo esc_attr( $status['state'] ); ?>">
				<div class="node-ic-item__thumb">
					<?php if ( $thumbnail ) : ?>
						<img src="<?php echo esc_url( $thumbnail ); ?>" alt="" loading="lazy" width="56" height="56" data-node-ic-thumb>
					<?php else : ?>
						<span class="material-symbols-outlined" aria-hidden="true">broken_image</span>
					<?php endif; ?>
				</div>

				<div class="node-ic-item__body">
					<p class="node-ic-item__name" title="<?php echo esc_attr( $filename ); ?>"><?php echo esc_html( $filename ); ?></p>
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
					<button type="button" class="node-ic-btn node-ic-btn--tonal" data-node-ic-convert <?php disabled( 'pending' !== $status['state'] ); ?>>
						<span class="material-symbols-outlined" aria-hidden="true">compress</span>
						<span class="node-ic-btn__text">WebP に置き換え</span>
					</button>
					<button type="button" class="node-ic-btn node-ic-btn--text" data-node-ic-restore <?php disabled( ! $status['restorable'] ); ?>>
						<span class="material-symbols-outlined" aria-hidden="true">undo</span>
						<span class="node-ic-btn__text">復元</span>
					</button>
				</div>
			</div>

			<p class="node-ic-metabox__hint">
				アイキャッチを差し替えた直後は、記事を保存すると最新の状態になります。
				<?php if ( ! Node_IC_Converter::keeps_original() ) : ?>
					元ファイルは削除されるため、置き換え後は復元できません。
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<p class="node-ic-metabox__link">
			<a href="<?php echo esc_url( $page_url ); ?>">すべてのアイキャッチをまとめて置き換える</a>
		</p>

		<div class="node-ic-snackbar" data-node-ic-snackbar role="status" aria-live="polite" hidden></div>
	</div>
	<?php
}
