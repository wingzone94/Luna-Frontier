import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

const source = readFileSync(new URL('../src/scripts/view-switcher.js', import.meta.url), 'utf8')
    .replace(/^import .*;$/gm, '').replaceAll('export ', '');
const css = readFileSync(new URL('../dist/css/style.css', import.meta.url), 'utf8');
const header = readFileSync(new URL('../template-parts/legacy/header.php', import.meta.url), 'utf8');
const button = header.match(/<button[^>]*id="m3-view-toggle"[\s\S]*?<\/button>/)[0];

for (const fontState of ['loaded', 'missing', 'failed', 'delayed']) {
    test(`view control keeps its name and a readable fallback when font is ${fontState}`, async () => {
        const browser = await chromium.launch({ headless: true });
        try {
            const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
            await page.setContent(`<!doctype html><html lang="ja"><body class="is-loaded"><header style="position:relative;height:64px">${button}</header></body></html>`);
            await page.addStyleTag({ content: css });
            await page.evaluate(({ source, fontState }) => {
                const fonts = new EventTarget();
                fonts.load = () => fontState === 'delayed' ? new Promise(resolve => { window.resolveFont = resolve; })
                    : fontState === 'failed' ? Promise.reject(new Error('offline'))
                    : Promise.resolve(fontState === 'loaded' ? [{ status: 'loaded' }] : []);
                Object.defineProperty(document, 'fonts', { value: fonts });
                const deps = `const storage={get:()=>null,set(){}}; const isPhoneLikeDevice=()=>true; const isTabletLikeDevice=()=>false; const syncTabletPcClass=()=>{}; const initViewModeSuggest=()=>{};`;
                window.eval(`${deps}${source};initViewSwitcher();`);
            }, { source, fontState });
            const control = page.getByRole('button', { name: 'PC表示に切り替える' });
            const fallback = page.locator('#m3-view-toggle-fallback');
            if (fontState !== 'delayed') await page.waitForFunction(() => document.querySelector('#m3-view-toggle').dataset.viewIconReady !== undefined);
            assert.equal(await fallback.isVisible(), fontState !== 'loaded');
            assert.equal(await fallback.textContent(), 'スマホ');
            assert.equal(await page.locator('#m3-view-toggle-icon').evaluate(x => getComputedStyle(x).visibility), fontState === 'loaded' ? 'visible' : 'hidden');
            const box = await control.boundingBox();
            assert.ok(box.width >= 48 && box.height >= 48);
            assert.equal(await control.getAttribute('aria-pressed'), null);
            await page.keyboard.press('Tab');
            const tooltip = page.getByRole('tooltip');
            assert.equal(await tooltip.textContent(), '現在はモバイル表示です');
            assert.equal(await control.getAttribute('aria-describedby'), await tooltip.getAttribute('id'));
            await page.keyboard.press('Escape');
            assert.equal(await tooltip.isVisible(), false);
            await page.mouse.move(380, 400);
            await control.hover();
            assert.equal(await tooltip.isVisible(), true);
            await tooltip.hover();
            await page.waitForTimeout(200);
            assert.equal(await tooltip.isVisible(), true);
            if (fontState === 'delayed') {
                await page.evaluate(() => window.resolveFont([{ status: 'loaded' }]));
                await page.waitForFunction(() => document.querySelector('#m3-view-toggle').dataset.viewIconReady === 'true');
                assert.equal(await fallback.isVisible(), false);
                assert.deepEqual(await control.boundingBox(), box);
            }
        } finally {
            await browser.close();
        }
    });
}
