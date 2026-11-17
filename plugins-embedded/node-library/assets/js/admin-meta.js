(function () {
    var addBtn = document.getElementById('node-library-add-link');
    var container = document.querySelector('#node-library-links-editor .links-container');

    if (addBtn && container) {
        addBtn.addEventListener('click', function () {
            var hidden = container.querySelectorAll('.link-row.is-hidden');
            if (hidden.length > 0) {
                hidden[0].classList.remove('is-hidden');
            }
            if (container.querySelectorAll('.link-row.is-hidden').length === 0) {
                addBtn.disabled = true;
                addBtn.textContent = 'リンク行の上限に達しました';
            }
        });

        if (container.querySelectorAll('.link-row.is-hidden').length === 0) {
            addBtn.disabled = true;
        }
    }

    var generateBtn = document.getElementById('node-library-generate-info');
    var generateStatus = document.getElementById('node-library-generate-status');
    var summaryField = document.querySelector('textarea[name="node_library_summary"]');
    var titleField = document.getElementById('title');
	var typeField = document.getElementById('node-library-type');

    if (generateBtn && generateStatus && summaryField && titleField && window.wp && window.wp.apiFetch) {
        generateBtn.addEventListener('click', function () {
            var title = titleField.value.trim();
			var linkRows = container ? Array.prototype.slice.call(container.querySelectorAll('.link-row')) : [];
			var hasLinks = linkRows.some(function (row) {
				var platform = row.querySelector('input[name*="[platform]"]');
				var url = row.querySelector('input[name*="[url]"]');
				return (platform && platform.value.trim()) || (url && url.value.trim());
			});
            if (!title) {
                generateStatus.textContent = '先にタイトルを入力してください。';
                titleField.focus();
                return;
            }

			if ((summaryField.value.trim() || hasLinks) && !window.confirm('現在の紹介文とストアリンクを取得結果で置き換えますか？')) {
                return;
            }

            generateBtn.disabled = true;
            generateStatus.style.color = '';
            generateStatus.textContent = '取得中…';

            window.wp.apiFetch({
                path: (window.nodeLibraryAdmin && window.nodeLibraryAdmin.generatePath) || '/node-library/v1/generate-game-info',
                method: 'POST',
				data: {
					title: title,
					type: typeField ? typeField.value : 'game'
				}
            }).then(function (response) {
                summaryField.value = response.summary || '';
                summaryField.dispatchEvent(new Event('input', { bubbles: true }));

				var validCategories = ['auto', 'pc', 'mobile', 'console'];
				var validHardware = [
					'auto',
					'windows-pc',
					'mac',
					'iphone-ipad',
					'android',
					'amazon-fire',
					'nintendo-switch',
					'nintendo-switch-2',
					'playstation-4',
					'playstation-5',
					'playstation-crossgen',
					'xbox-one',
					'xbox-series',
					'xbox-crossgen'
				];
				linkRows.forEach(function (row, index) {
					var link = response.links && response.links[index] ? response.links[index] : null;
					var platform = row.querySelector('input[name*="[platform]"]');
					var url = row.querySelector('input[name*="[url]"]');
					var category = row.querySelector('select[name*="[category]"]');
					var hardware = row.querySelector('select[name*="[hardware]"]');
					if (platform) platform.value = link ? link.platform : '';
					if (url) url.value = link ? link.url : '';
					if (category) {
						var cat = link && validCategories.indexOf(link.category) !== -1 ? link.category : 'auto';
						category.value = cat;
					}
					if (hardware) {
						var device = link && validHardware.indexOf(link.hardware) !== -1 ? link.hardware : 'auto';
						hardware.value = device;
					}

					var shouldShow = index < Math.max(2, response.links ? response.links.length : 0);
					row.classList.toggle('is-hidden', !shouldShow);
				});

				if (addBtn && container) {
					addBtn.disabled = container.querySelectorAll('.link-row.is-hidden').length === 0;
				}

				var linkCount = response.links ? response.links.length : 0;
				generateStatus.textContent = linkCount > 0
					? '紹介文とストアリンク' + linkCount + '件を反映しました。'
					: '紹介文を反映しました。公式ストアページは見つかりませんでした。';
            }).catch(function (error) {
				generateStatus.style.color = '#d63638';
                generateStatus.textContent = error && error.message ? error.message : 'ゲーム（アプリ）情報の取得に失敗しました。';
            }).finally(function () {
                generateBtn.disabled = false;
            });
        });
    }

    var select = document.getElementById('node-linked-library-select');
    var preview = document.getElementById('node-library-metabox-preview');
    var previewText = document.getElementById('node-library-metabox-preview-text');

    if (select && preview && previewText) {
        select.addEventListener('change', function () {
            var option = select.options[select.selectedIndex];
            if (!select.value) {
                preview.style.display = 'none';
                return;
            }
            var summary = option.getAttribute('data-summary') || '（紹介文なし）';
            previewText.textContent = summary || '（紹介文なし）';
            preview.style.display = '';
        });
    }
})();

// Catalogue fetching is independent of title entry and AI settings.
(function () {
    var button = document.getElementById('node-library-fetch-store');
    var input = document.getElementById('node-library-store-input');
    var store = document.getElementById('node-library-store');
    var status = document.getElementById('node-library-fetch-store-status');
    var preview = document.getElementById('node-library-store-preview');
    var text = document.getElementById('node-library-store-preview-text');
    var apply = document.getElementById('node-library-apply-store');
    var ticket = document.getElementById('node-library-catalog-ticket');
    if (!button || !input || !store || !window.wp || !window.wp.apiFetch) return;
    var pending = null;
    var busy = false;
    function update() { button.disabled = busy || !input.value.trim(); }
    function invalidate() { pending = null; preview.hidden = true; update(); }
    input.addEventListener('input', invalidate);
    store.addEventListener('change', invalidate);
    update();
    button.addEventListener('click', function () {
        if (busy || !input.value.trim()) return;
        busy = true;
        pending = null;
        preview.hidden = true;
        status.textContent = 'カタログと関連商品を取得中…';
        update();
        window.wp.apiFetch({
            path: window.nodeLibraryAdmin.fetchStorePath,
            method: 'POST',
            data: { store: store.value, input: input.value.trim(), post_id: Number(button.dataset.postId) }
        }).then(function (data) {
            if (!data || !data.ticket || !data.meta || !Array.isArray(data.relations)) throw new Error('カタログの応答が不完全です。');
            pending = data;
            text.textContent = JSON.stringify({
                タイトル: data.title, 紹介文: data.summary, ストアID: data.id,
                現在価格: data.price ? data.price.price + ' ' + data.price.currency : '購入可能な価格情報なし',
                定価: data.price ? data.price.list_price : null,
                セール終了UTC: data.price ? data.price.sale_end : null,
                画像URL: data.image_url, 構造化情報: data.meta,
                対応機種: data.platforms, 機能: data.features, 関連商品: data.relations
            }, null, 2);
            preview.hidden = false;
            status.textContent = '取得しました。内容を確認してから編集欄へ反映してください。まだ保存していません。';
        }).catch(function (error) {
            pending = null;
            preview.hidden = true;
            status.textContent = error.message || 'カタログを取得できませんでした。入力済み情報は変更していません。';
        }).finally(function () { busy = false; update(); });
    });
    function setValue(element, value) {
        if (!element) return;
        element.value = value;
        element.dispatchEvent(new Event('input', { bubbles: true }));
        element.dispatchEvent(new Event('change', { bubbles: true }));
    }
    apply.addEventListener('click', function () {
        if (!pending || !window.confirm('タイトル・紹介文・構造化カタログ情報を取得内容で置き換えますか？ 他ストアのIDと既存リンクは保持します。')) return;
        setValue(document.getElementById('title'), pending.title);
        setValue(document.querySelector('textarea[name="node_library_summary"]'), pending.summary);
        Object.keys(pending.meta).forEach(function (field) {
            var value = pending.meta[field];
            if (field === 'store_ids') {
                Object.keys(value).forEach(function (key) { setValue(document.getElementById('node-library-structured-store_ids-' + key), value[key]); });
            } else if (field === 'genres') {
                setValue(document.getElementById('node-library-structured-genres'), value.join('\n'));
            } else if (value && typeof value === 'object') {
                // Empty/missing flags in a complete preview mean unknown for this product.
                document.querySelectorAll('[name^="node_library_structured[' + field + ']["]').forEach(function (element) { setValue(element, ''); });
                Object.keys(value).forEach(function (key) { setValue(document.getElementById('node-library-structured-' + field + '-' + key), typeof value[key] === 'boolean' ? (value[key] ? '1' : '0') : value[key]); });
            } else {
                setValue(document.getElementById('node-library-structured-' + field), value);
            }
        });
        ticket.value = pending.ticket;
        preview.hidden = true;
        status.textContent = '編集欄へ反映しました。項目を保存すると価格・画像・関連商品も記録されます。';
    });
})();
