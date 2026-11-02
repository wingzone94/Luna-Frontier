import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { chromium, webkit } from 'playwright';

const base = process.env.NODE_VISUAL_BASE_URL || 'http://cybernode.local';
const path = process.env.NODE_ACTIONS_ARTICLE || '/post-2425/';
const output = process.env.NODE_ACTIONS_OUTPUT || 'scratch/mobile-actions';
const headers = process.env.NODE_ACTIONS_PREVIEW ? { 'X-Node-UI-Preview': process.env.NODE_ACTIONS_PREVIEW } : {};
const urlFor = path => {
    const url = new URL(path, base);
    url.searchParams.set('_node_ui_validation', String(Date.now()));
    return url.href;
};
const engine = process.env.NODE_ACTIONS_BROWSER === 'webkit' ? webkit : chromium;
const browser = await engine.launch({ headless: true });
const results = [];
await mkdir(output, { recursive: true });
const check = (name, value) => { assert.ok(value, name); results.push(name); console.log('PASS:', name); };
try {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, extraHTTPHeaders: headers, reducedMotion: 'reduce' });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(urlFor(path), { waitUntil: 'networkidle' });
    if (process.env.NODE_ACTIONS_THEME) {
        const manifest = JSON.parse(await readFile('dist/.vite/manifest.json', 'utf8'));
        const expected = '/wp-content/themes/' + process.env.NODE_ACTIONS_THEME + '/dist/' + manifest['src/main.js'].file;
        check('page loads the current build manifest', await page.locator('script[type="module"]').evaluateAll((scripts, suffix) => scripts.some(script => new URL(script.src).pathname === suffix), expected));
    }
    const toggle = page.locator('#m3-actions-toggle');
    const items = page.locator('#m3-actions-items');
    await page.locator('.m3-action-stack.is-actions-ready').waitFor({ state: 'attached' });
    check('mobile starts collapsed', await items.evaluate(el => el.hidden));
    await page.locator('.m3-article__body').evaluate(el => window.scrollTo({ top: window.scrollY + el.getBoundingClientRect().top + 250, behavior: 'instant' }));
    await toggle.waitFor({ state: 'visible' });
    check('collapsed children excluded from layout', !(await page.locator('#m3-back-to-top').isVisible()));
    await page.screenshot({ path: output + '/mobile-collapsed.png' });
    await toggle.click();
    check('toggle expands existing actions', await toggle.getAttribute('aria-expanded') === 'true' && await page.locator('#m3-back-to-top').isVisible());
    const box = await toggle.boundingBox();
    check('mobile touch target at least 48px', box.width >= 48 && box.height >= 48);
    await page.screenshot({ path: output + '/mobile-expanded.png' });
    await page.keyboard.press('Escape');
    check('Escape collapses and restores focus', await items.evaluate(el => el.hidden) && await toggle.evaluate(el => el === document.activeElement));
    await toggle.click();
    await page.locator('.m3-article__body').click({ position: { x: 20, y: 300 } });
    check('outside click collapses', await items.evaluate(el => el.hidden));
    await toggle.click();
    const toc = page.locator('#m3-toc-trigger');
    await toc.click();
    await page.locator('#m3-sticky-toc.is-active').waitFor({ state: 'visible' });
    check('TOC opens and action menu collapses', await items.evaluate(el => el.hidden));
    await page.screenshot({ path: output + '/mobile-toc.png' });
    await page.locator('#m3-toc-close').click();
    check('TOC close returns focus to disclosure', await toggle.evaluate(el => el === document.activeElement));
    await toggle.click();
    await page.locator('#m3-back-to-top').click();
    await page.waitForFunction(() => window.scrollY < 2);
    check('TOP scrolls to page start', true);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.waitForFunction(() => document.getElementById('m3-actions-toggle').hidden && !document.getElementById('m3-actions-items').hidden);
    check('desktop keeps actions expanded without toggle', await page.locator('#m3-back-to-top').isVisible());
    await page.screenshot({ path: output + '/desktop.png' });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForFunction(() => document.getElementById('m3-actions-items').hidden);
    check('resize back to mobile resets disclosure', true);
    await page.goto(urlFor('/'), { waitUntil: 'networkidle' });
    await page.evaluate(() => window.scrollTo({ top: 500, behavior: 'instant' }));
    await toggle.waitFor({ state: 'visible' });
    await toggle.click();
    check('home exposes TOP without phantom TOC', await page.locator('#m3-back-to-top').isVisible() && await page.locator('#m3-toc-trigger').count() === 0);
    check('no page JavaScript errors', errors.length === 0);
    await writeFile(output + '/results.json', JSON.stringify({ results, errors }, null, 2));
    await context.close();
} finally {
    await browser.close();
}
