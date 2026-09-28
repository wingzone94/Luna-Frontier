<?php

declare(strict_types=1);
/**
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 記事タイトルカードに表示する手動更新日。
 */
function node_sanitize_manual_modified_date( $value ) {
	$value = sanitize_text_field( (string) $value );

	if ( '' === $value ) {
		return '';
	}

	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
		return '';
	}

	return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ? $value : '';
}

function node_register_manual_modified_date_meta() {
	register_post_meta(
		'post',
		'_node_manual_modified_date',
		array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'node_sanitize_manual_modified_date',
			'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', (int) $post_id );
			},
		)
	);
}
add_action( 'init', 'node_register_manual_modified_date_meta' );

function node_maybe_auto_record_manual_modified_date( $post_id, $post, $is_update ) {
	if ( ! $is_update || ! $post instanceof WP_Post ) {
		return;
	}

	if ( 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return;
	}

	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	$timezone     = wp_timezone();
	$published_at = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $post->post_date, $timezone );

	if ( ! $published_at ) {
		return;
	}

	$now = new DateTimeImmutable( 'now', $timezone );

	// 公開から3時間経過で追記日を記録できる（訂正などを当日中に反映するため）
	if ( $now->getTimestamp() - $published_at->getTimestamp() < 3 * HOUR_IN_SECONDS ) {
		return;
	}

	$auto_date    = $now->format( 'Y-m-d' );
	$current_date = get_post_meta( $post_id, '_node_manual_modified_date', true );

	if ( $current_date !== $auto_date ) {
		update_post_meta( $post_id, '_node_manual_modified_date', $auto_date );
	}
}

function node_auto_record_manual_modified_date_on_save( $post_id, $post, $update ) {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	node_maybe_auto_record_manual_modified_date( $post_id, $post, $update );
}
add_action( 'save_post_post', 'node_auto_record_manual_modified_date_on_save', 20, 3 );

function node_auto_record_manual_modified_date_after_rest_save( $post, $request, $creating ) {
	node_maybe_auto_record_manual_modified_date( $post->ID, $post, ! $creating );
}
add_action( 'rest_after_insert_post', 'node_auto_record_manual_modified_date_after_rest_save', 20, 3 );

function node_enqueue_manual_modified_date_editor_assets() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'post' !== $screen->post_type ) {
		return;
	}

	wp_register_script(
		'node-manual-modified-date-editor',
		false,
		array( 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-notices', 'wp-plugins' ),
		NODE_THEME_VERSION,
		true
	);
	wp_enqueue_script( 'node-manual-modified-date-editor' );

	$script = <<<'JS'
(function (wp) {
	if (!wp || !wp.plugins || !wp.editPost || !wp.element || !wp.data || !wp.components) {
		return;
	}

	var createElement = wp.element.createElement;
	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var TextControl = wp.components.TextControl;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;

	function NodeManualModifiedDatePanel() {
		var postType = useSelect(function (select) {
			return select('core/editor').getCurrentPostType();
		}, []);
		var postDate = useSelect(function (select) {
			return select('core/editor').getEditedPostAttribute('date');
		}, []);
		var meta = useSelect(function (select) {
			return select('core/editor').getEditedPostAttribute('meta') || {};
		}, []);
		var isSavingPost = useSelect(function (select) {
			return select('core/editor').isSavingPost();
		}, []);
		var isAutosavingPost = useSelect(function (select) {
			return select('core/editor').isAutosavingPost();
		}, []);
		var editPost = useDispatch('core/editor').editPost;
		var createSuccessNotice = useDispatch('core/notices').createSuccessNotice;
		var value = meta._node_manual_modified_date || '';
		var publishedAt = postDate ? new Date(postDate) : null;
		var publishedTime = publishedAt && !isNaN(publishedAt.getTime()) ? publishedAt.getTime() : 0;
		var canSetManualDate = publishedTime > 0 && Date.now() - publishedTime >= 3 * 60 * 60 * 1000;
		var minDate = '';
		var wasSaving = useRef(false);
		var valueBeforeSave = useRef(value);

		function setManualDate(nextValue) {
			editPost({
				meta: Object.assign({}, meta, {
					_node_manual_modified_date: nextValue || ''
				})
			});
		}

		if (publishedTime > 0) {
			var nextDate = new Date(publishedTime);
			minDate = [
				nextDate.getFullYear(),
				String(nextDate.getMonth() + 1).padStart(2, '0'),
				String(nextDate.getDate()).padStart(2, '0')
			].join('-');
		}

		useEffect(function () {
			var isManualSave = isSavingPost && !isAutosavingPost;

			if (isManualSave && !wasSaving.current) {
				valueBeforeSave.current = value;
			}

			if (wasSaving.current && !isManualSave && canSetManualDate && value && value !== valueBeforeSave.current) {
				createSuccessNotice('追記日が自動的に記録されました', {
					type: 'snackbar',
					id: 'node-manual-modified-date-auto-recorded'
				});
			}

			wasSaving.current = isManualSave;
		}, [isSavingPost, isAutosavingPost, canSetManualDate, value]);

		if (postType !== 'post') {
			return null;
		}

		return createElement(
			PluginDocumentSettingPanel,
			{
				name: 'node-manual-modified-date',
				title: '更新日表示',
				className: 'node-manual-modified-date-panel'
			},
			!canSetManualDate ? createElement(
				Notice,
				{
					status: 'info',
					isDismissible: false
				},
				'公開から3時間後に設定できます。'
			) : null,
			createElement(TextControl, {
				label: '記事タイトルカードの更新日',
				type: 'date',
				value: value,
				min: minDate,
				disabled: !canSetManualDate,
				onChange: setManualDate,
				help: '公開から3時間後に保存すると自動で記録されます。手動で設定した追記日は公開日と同じ日でも表示されます。'
			}),
			value ? createElement(
				Button,
				{
					variant: 'link',
					isDestructive: true,
					onClick: function () {
						setManualDate('');
					}
				},
				'日付をクリア'
			) : null
		);
	}

	registerPlugin('node-manual-modified-date', {
		render: NodeManualModifiedDatePanel
	});
})(window.wp);
JS;

	wp_add_inline_script( 'node-manual-modified-date-editor', $script );
}
add_action( 'enqueue_block_editor_assets', 'node_enqueue_manual_modified_date_editor_assets' );
