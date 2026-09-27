(function (wp) {
	'use strict';

	if (!wp || !wp.plugins || !wp.editPost || !wp.components || !wp.data || !wp.element) {
		return;
	}

	var createElement = wp.element.createElement;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var metaKey = '_node_ogp_use_featured_image';
	var convertData = window.nodeSeoOgpData || {};

	function FeaturedImageOgpPanel() {
		var busyState = useState(false);
		var busy = busyState[0];
		var setBusy = busyState[1];
		var messageState = useState('');
		var message = messageState[0];
		var setMessage = messageState[1];
		var editor = useSelect(function (select) {
			var store = select('core/editor');
			var featuredId = store.getEditedPostAttribute('featured_media') || 0;
			var media = featuredId ? select('core').getMedia(featuredId) : null;
			return {
				postType: store.getCurrentPostType(),
				meta: store.getEditedPostAttribute('meta') || {},
				featuredId: featuredId,
				featuredMime: media && media.mime_type ? media.mime_type : ''
			};
		}, []);
		var editPost = useDispatch('core/editor').editPost;
		var isWebp = editor.featuredMime === 'image/webp';

		function convertFeaturedImage() {
			if (!editor.featuredId || busy || !convertData.canConvert) {
				return;
			}

			var attachmentId = editor.featuredId;
			var body = new URLSearchParams();
			body.set('action', 'node_ic_convert_one');
			body.set('nonce', convertData.nonce);
			body.set('attachment_id', String(attachmentId));
			setBusy(true);
			setMessage('');

			fetch(convertData.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function (response) {
				return response.json();
			}).then(function (result) {
				setMessage((result.data && result.data.message) || (result.success ? 'WebPに置き換えました。' : '置き換えに失敗しました。'));
				if (result.success) {
					wp.data.dispatch('core').invalidateResolution('getMedia', [attachmentId]);
				}
			}).catch(function () {
				setMessage('通信に失敗しました。');
			}).finally(function () {
				setBusy(false);
			});
		}

		if (editor.postType !== 'post') {
			return null;
		}

		return createElement(
			wp.editPost.PluginDocumentSettingPanel,
			{
				name: 'node-featured-image-ogp',
				title: 'OGP画像',
				className: 'node-featured-image-ogp-panel'
			},
			createElement(wp.components.ToggleControl, {
				label: 'アイキャッチ画像をOGPに使用',
				checked: editor.meta[metaKey] === true,
				help: editor.featuredId
					? 'オフにすると、記事タイトル入りの自動生成OGPを使用します。'
					: 'アイキャッチ画像が未設定の場合は、自動生成OGPを使用します。',
				onChange: function (enabled) {
					editPost({
						meta: Object.assign({}, editor.meta, { [metaKey]: enabled })
					});
				}
			}),
			convertData.canConvert && editor.featuredId ? createElement(
				wp.components.Button,
				{
					variant: 'secondary',
					isBusy: busy,
					disabled: busy || isWebp,
					onClick: convertFeaturedImage
				},
				isWebp ? 'WebPに置き換え済み' : 'アイキャッチをWebPに置き換える'
			) : null,
			message ? createElement(wp.components.Notice, {
				status: 'info',
				isDismissible: true,
				onRemove: function () { setMessage(''); }
			}, message) : null
		);
	}

	wp.plugins.registerPlugin('node-featured-image-ogp', {
		render: FeaturedImageOgpPanel
	});
})(window.wp);
