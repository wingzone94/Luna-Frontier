import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';
const source = readFileSync(new URL('../src/scripts/search-bar.js', import.meta.url), 'utf8')
    .replace("import { storage } from '../storage';", 'const storage={get:()=>null,set(){},remove(){}};').replace('export function', 'function');
const css = ['style', 'luna-style'].map(name => readFileSync(new URL(`../dist/css/${name}.css`, import.meta.url), 'utf8')).join('\n');
const fixture = `<!doctype html><html lang="ja"><body class="lf-theme is-loaded"><header class="m3-header"><div class="m3-header__inner">
<a class="m3-header__logo-link" href="#">Luminous Core</a><div class="m3-header__search-slot"><div class="m3-search-container">
<form class="m3-search-bar" id="m3-main-search-form"><div class="m3-search-input-wrapper"><label class="screen-reader-text" for="m3-search-input">記事を検索</label><input class="m3-search-bar__input" id="m3-search-input"></div></form></div></div>
<div class="m3-header__actions"><button id="search-toggle" class="m3-icon-button">検索</button></div></div></header></body></html>`;

test('search input receives focus synchronously during the opening tap, including tablet PC layout', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        for (const tablet of [false, true]) {
            const page = await browser.newPage({ viewport: { width: tablet ? 768 : 390, height: 844 } });
            await page.setContent(fixture);
            await page.addStyleTag({ content: css });
            await page.evaluate(({ source, tablet }) => {
                document.documentElement.classList.toggle('lf-tablet-pc', tablet);
                window.eval(`${source};initSearchBar();`);
                window.focusOnTap = null;
                document.querySelector('#search-toggle').addEventListener('click', () => {
                    window.focusOnTap = document.activeElement.id;
                });
            }, { source, tablet });
            // Establish the fully closed visibility state, rather than test before CSS settles.
            await page.waitForFunction(() => getComputedStyle(document.querySelector('.m3-search-input-wrapper')).visibility === 'hidden');
            await page.locator('#search-toggle').click();
            assert.equal(await page.evaluate(() => window.focusOnTap), 'm3-search-input');
            await page.close();
        }
    } finally {
        await browser.close();
    }
});
