(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.nodeLibraryProductImport = api;
})(typeof window !== 'undefined' ? window : this, function () {
    'use strict';

    // Kept self-contained so the bookmarklet reads only the current product DOM.
    function collect(doc, href) {
        function text(value) { return typeof value === 'string' ? value.replace(/\s+/g, ' ').trim() : ''; }
        function safeUrl(value, base) {
            if (typeof value !== 'string' || !value.trim()) return '';
            try {
                var u = new URL(value, base);
                return u.protocol === 'https:' && !u.username && !u.password ? u.href : '';
            } catch (e) { return ''; }
        }
        var url = new URL(href);
        var store, id;
        if (/^(www\.)?amazon\.(co\.jp|com)$/.test(url.hostname)) {
            var match = url.pathname.match(/\/(?:dp|gp\/product|gp\/aw\/d)\/([a-z0-9]{10})(?:\/|$)/i);
            if (!match) throw new Error('Amazonの商品ページで実行してください。');
            store = 'amazon';
            id = match[1].toUpperCase();
            var tag = url.searchParams.get('tag');
            url = new URL('/dp/' + id, url.origin);
            if (tag) url.searchParams.set('tag', tag);
        } else if (url.hostname === 'store-jp.nintendo.com' && /^\/item\/[^/]+\/[^/]+\/?$/.test(url.pathname)) {
            store = 'nintendo';
            id = url.pathname.replace(/\/$/, '');
            url.searchParams.delete('srsltid');
            url.hash = '';
        } else {
            throw new Error('AmazonまたはMy Nintendo Storeの商品ページを開いてください。待合室からは取り込めません。');
        }
        var product = null;
        function visit(value, depth) {
            if (!value || typeof value !== 'object' || depth > 8 || product) return;
            if (Array.isArray(value)) { value.forEach(function (entry) { visit(entry, depth + 1); }); return; }
            var types = Array.isArray(value['@type']) ? value['@type'] : [value['@type']];
            if (types.indexOf('Product') !== -1) {
                var productUrl = value.url || (value.offers && value.offers.url);
                if (productUrl && new URL(productUrl, href).pathname.replace(/\/$/, '') !== url.pathname.replace(/\/$/, '')) return;
                product = value;
                return;
            }
            if (value['@graph']) visit(value['@graph'], depth + 1);
            if (value.mainEntity) visit(value.mainEntity, depth + 1);
        }
        Array.prototype.forEach.call(doc.querySelectorAll('script[type="application/ld+json"]'), function (script) {
            if (script.textContent.length > 500000) return;
            try { visit(JSON.parse(script.textContent), 0); } catch (e) { /* Other structured data is optional. */ }
        });
        function meta(property) {
            var node = doc.querySelector('meta[property="' + property + '"]');
            return node ? text(node.getAttribute('content')) : '';
        }
        var titleNode = doc.querySelector(store === 'amazon' ? '#productTitle' : 'main h1, h1[itemprop="name"], h1');
        var title = text(product && product.name) || text(titleNode && titleNode.textContent) || meta('og:title');
        var image = '';
        if (store === 'amazon') {
            var main = doc.querySelector('#landingImage, #imgBlkFront, #main-image');
            if (main) {
                image = safeUrl(main.getAttribute('data-old-hires'), href);
                if (!image) {
                    try {
                        var dynamic = JSON.parse(main.getAttribute('data-a-dynamic-image') || '{}');
                        var variants = Object.keys(dynamic).sort(function (a, b) {
                            return (Number(dynamic[b][0]) * Number(dynamic[b][1])) - (Number(dynamic[a][0]) * Number(dynamic[a][1]));
                        });
                        image = variants.map(function (candidate) { return safeUrl(candidate, href); }).filter(Boolean)[0] || '';
                    } catch (e) { /* src is the last product-image fallback. */ }
                }
                if (!image) image = safeUrl(main.getAttribute('src'), href);
            }
        }
        var structuredImage = product && product.image;
        if (Array.isArray(structuredImage)) structuredImage = structuredImage[0];
        if (structuredImage && typeof structuredImage === 'object') structuredImage = structuredImage.url || structuredImage.contentUrl;
        if (!image && typeof structuredImage === 'string') image = safeUrl(structuredImage, href);
        if (!image) image = safeUrl(meta('og:image'), href);
        if (!image && store === 'nintendo') {
            var mainImage = doc.querySelector('main img[itemprop="image"], main .product-detail img, main .product-image img, main img[data-product-image]');
            if (mainImage) image = safeUrl(mainImage.getAttribute('data-src') || mainImage.getAttribute('src'), href);
        }
        if (!title || !image || /transparent-pixel|\.gif(?:[?#]|$)/i.test(image)) {
            throw new Error('商品名または商品画像が見つかりません。商品詳細の読み込み後に再実行してください。');
        }
        return { version: 1, store: store, url: url.href, productId: id, title: title.slice(0, 500), image: image };
    }

    function identity(raw) {
        try {
            var u = new URL(raw);
            if (u.protocol !== 'https:' || u.username || u.password) return '';
            if (/^(www\.)?amazon\.(co\.jp|com)$/.test(u.hostname)) {
                var asin = u.pathname.match(/\/(?:dp|gp\/product|gp\/aw\/d)\/([a-z0-9]{10})(?:\/|$)/i);
                return asin ? 'amazon:' + asin[1].toUpperCase() : '';
            }
            return u.hostname === 'store-jp.nintendo.com' && /^\/item\/[^/]+\/[^/]+\/?$/.test(u.pathname)
                ? 'nintendo:' + u.pathname.replace(/\/$/, '') : '';
        } catch (e) { return ''; }
    }

    function parse(raw) {
        if (typeof raw !== 'string' || raw.length > 16384) throw new Error('取り込みデータが長すぎます。');
        var value;
        try { value = JSON.parse(raw.replace(/^LUNA_PRODUCT_V1:/, '').trim()); }
        catch (e) { throw new Error('商品ページでコピーした取り込みデータを貼り付けてください。'); }
        if (!value || value.version !== 1 || ['amazon', 'nintendo'].indexOf(value.store) === -1 ||
            typeof value.title !== 'string' || !value.title.trim() || value.title.length > 500 ||
            typeof value.image !== 'string' || value.image.length > 4096 || typeof value.url !== 'string' || value.url.length > 4096 ||
            typeof value.productId !== 'string' || identity(value.url) !== value.store + ':' + value.productId) {
            throw new Error('商品情報の形式または商品URLが正しくありません。');
        }
        var image;
        try { image = new URL(value.image); } catch (e) { throw new Error('画像URLが正しくありません。'); }
        if (image.protocol !== 'https:' || image.username || image.password) throw new Error('画像にはHTTPSのURLを指定してください。');
        return { version: 1, store: value.store, url: value.url, productId: value.productId, title: value.title.trim(), image: image.href };
    }

    function bookmarklet() {
        var script = '(function(){try{var p=(' + collect.toString() + ')(document,location.href);var s="LUNA_PRODUCT_V1:"+JSON.stringify(p);' +
            'function show(){window.prompt("この商品情報をコピーし、商品リンクカードの取り込み欄へ貼り付けてください。",s);}' +
            'if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(s).then(function(){window.alert("商品情報をコピーしました。商品リンクカードへ貼り付けてください。");},show);}else{show();}' +
            '}catch(e){window.alert(e.message);}})()';
        return 'javascript:void(' + encodeURIComponent(script) + ')';
    }
    return { collect: collect, parse: parse, identity: identity, bookmarklet: bookmarklet };
});
