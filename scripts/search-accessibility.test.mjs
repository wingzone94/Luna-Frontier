import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

const source = readFileSync(new URL('../src/scripts/search-bar.js', import.meta.url), 'utf8')
    .replace("import { storage } from '../storage';", 'const storage = { get: () => null, set() {}, remove() {} };')
    .replace('export function', 'function');
const header = readFileSync(new URL('../template-parts/legacy/header.php', import.meta.url), 'utf8');
const fixture = `<!doctype html><html lang="ja"><body>
<button id="search-toggle">検索</button><header class="m3-header"></header>
<form id="m3-main-search-form"><label for="m3-search-input">記事を検索</label>
<input id="m3-search-input"><div id="m3-search-suggestions"></div></form>
<button id="m3-advanced-search-trigger">詳細検索</button>
<dialog id="m3-advanced-search-modal"><button id="m3-advanced-search-close">閉じる</button>
<label for="m3-tag-input">タグ</label><input id="m3-tag-input">
<div id="m3-tag-suggestions"></div><button id="m3-advanced-search-apply">適用</button><section id="m3-search-rest-results" hidden><p id="m3-search-rest-results-status" role="status"></p><ul id="m3-search-rest-results-list"></ul></section></dialog></body></html>`;

async function withSearch(run) {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        await page.route('http://search.test/**', route => {
            const url = new URL(route.request().url());
            if (url.pathname === '/suggest') return route.fulfill({ json: [{ keyword: '日本語', source: 'category' }] });
            if (url.pathname === '/tags') return route.fulfill({ json: [{ name: '日本語タグ' }] });
            return route.fulfill({ contentType: 'text/html', body: fixture });
        });
        await page.goto('http://search.test/');
        await page.evaluate(`window.m3_ajax = { search_suggest_url: '/suggest', tag_search_url: '/tags' }; ${source}; initSearchBar();`);
        await run(page);
    } finally {
        await browser.close();
    }
}

test('search and tag controls have explicit labels in the PHP template', () => {
    assert.match(header, /<label[^>]*for="m3-search-input"[^>]*>記事を検索<\/label>/);
    assert.match(header, /<label[^>]*for="m3-tag-input"/);
});

test('Escape closes suggestions first, then restores focus to the search button', async () => {
    await withSearch(async page => {
        await page.locator('#search-toggle').click();
        await page.locator('#m3-search-input').fill('日本');
        await page.locator('#m3-search-suggestions [role=option]').waitFor();
        await page.locator('#m3-search-input').press('ArrowDown');
        assert.equal(await page.locator('#m3-search-input').getAttribute('aria-activedescendant'), 'm3-search-suggestion-0');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#m3-search-input').getAttribute('aria-expanded'), 'false');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'm3-search-input');
        await page.keyboard.press('Escape');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'search-toggle');
    });
});

test('tag Escape keeps the native dialog open until the next Escape', async () => {
    await withSearch(async page => {
        await page.locator('#m3-advanced-search-trigger').click();
        await page.locator('#m3-tag-input').fill('日本');
        await page.locator('#m3-tag-suggestions [role=option]').waitFor();
        await page.locator('#m3-tag-input').press('ArrowDown');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('dialog').evaluate(x => x.open), true);
        assert.equal(await page.locator('#m3-tag-input').getAttribute('aria-activedescendant'), null);
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('dialog').evaluate(x => x.open), false);
        assert.equal(await page.evaluate(() => document.activeElement.id), 'm3-advanced-search-trigger');
    });
});

test('composing Enter and Safari 229 never submit; normal Enter submits once', async () => {
    await withSearch(async page => {
        const result = await page.evaluate(() => {
            let submits = 0;
            document.querySelector('form').requestSubmit = () => submits++;
            const input = document.querySelector('#m3-search-input');
            input.value = '日本語';
            const counts = [];
            for (const init of [{ isComposing: true }, { keyCode: 229 }, { keyCode: 13 }]) {
                input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true, ...init }));
                counts.push(submits);
            }
            return counts;
        });
        assert.deepEqual(result, [0, 0, 1]);
    });
});

for (const reducedMotion of ['reduce', 'no-preference']) {
    test(`search results honor reduced motion: ${reducedMotion}`, async () => {
        await withSearch(async page => {
            await page.emulateMedia({ reducedMotion });
            await page.route('http://search.test/results*', route => route.fulfill({ json: { total: 0, results: [] } }));
            await page.evaluate(() => {
                window.m3_ajax.home_url = 'http://search.test/';
                window.m3_ajax.result_search_url = '/results';
                window.resultScroll = [];
                document.querySelector('#m3-search-rest-results').scrollIntoView = options => resultScroll.push(options);
                document.querySelector('dialog').showModal();
            });
            await page.locator('#m3-advanced-search-apply').click();
            await page.waitForFunction(() => window.resultScroll.length > 0);
            assert.deepEqual(await page.evaluate(() => resultScroll), [{ behavior: reducedMotion === 'reduce' ? 'instant' : 'smooth', block: 'start' }]);
            assert.equal(await page.locator('[role=status]').textContent(), '条件に一致する記事はありませんでした。');
        });
    });
}
