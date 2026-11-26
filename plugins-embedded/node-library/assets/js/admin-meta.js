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
    var inputVersion = 0;
    function update() { button.disabled = busy || !input.value.trim(); }
    function invalidate() { inputVersion++; pending = null; preview.hidden = true; status.textContent = ''; update(); }
    input.addEventListener('input', invalidate);
    store.addEventListener('change', invalidate);
    update();
    button.addEventListener('click', function () {
        if (busy || !input.value.trim()) return;
        busy = true;
        var requestVersion = inputVersion;
        pending = null;
        preview.hidden = true;
        status.textContent = 'カタログと関連商品を取得中…';
        update();
        window.wp.apiFetch({
            path: window.nodeLibraryAdmin.fetchStorePath,
            method: 'POST',
            data: { store: store.value, input: input.value.trim(), post_id: Number(button.dataset.postId) }
        }).then(function (data) {
            if (requestVersion !== inputVersion) return;
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
            if (requestVersion !== inputVersion) return;
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
        if (pending.summary) {
            setValue(document.querySelector('textarea[name="node_library_summary"]'), pending.summary);
        }
        var imageField = document.getElementById('node-library-structured-image_url');
        if (pending.image_url && imageField && !imageField.value.trim()) {
            setValue(imageField, pending.image_url);
        }
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
