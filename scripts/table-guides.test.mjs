// Run after bun run build with the Playwright browsers installed.
import assert from 'node:assert/strict';
import test from 'node:test';
import { chromium, webkit } from 'playwright';

const fixture = `
  <main class="m3-article__body">
    <figure class="wp-block-table">
      <table><caption>モデル比較</caption><tbody>
        <tr><th rowspan="2">モデル</th><th colspan="2">仕様</th></tr>
        <tr><td>長いモデル名</td><td style="white-space: nowrap">${'LongModelIdentifier'.repeat(8)}</td></tr>
      </tbody></table>
    </figure>
    <table><tbody><tr><th>料金</th><td style="white-space: nowrap">${'PricingPlan'.repeat(20)}</td></tr></tbody></table>
    <table><tbody><tr><th>月額</th><td>無料</td></tr></tbody></table>
  </main>`;

for (const engine of [chromium, webkit]) {
  test(`table guides follow native overflow in ${engine.name()}`, async () => {
    const browser = await engine.launch();
    try {
      const page = await browser.newPage({ reducedMotion: 'reduce' });
      await page.setContent(`<!doctype html><html lang="ja"><body class="single lf-theme is-loaded">${fixture}</body>`);
      await page.addStyleTag({ path: 'dist/css/style.css' });
      await page.addStyleTag({ path: 'dist/css/luna-style.css' });
      await page.addScriptTag({ path: 'dist/js/luna.js' });
      for (const width of [320, 375, 390, 430, 768, 1024, 1440, 390]) {
        await page.setViewportSize({ width, height: 800 });
        await page.waitForFunction(() => {
          return [...document.querySelectorAll('figure, main > table')].every(host => {
            const guide = host.previousElementSibling;
            const visible = guide?.matches('.lf-table-guide') && guide.getClientRects().length > 0;
            return Boolean(visible) === (innerWidth < 1024 && host.scrollWidth > host.clientWidth);
          });
        });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      }
      const host = page.locator('figure');
      const top = (await host.boundingBox()).y;
      await host.focus();
      assert(await host.evaluate(el => el === document.activeElement));
      await host.evaluate(el => { el.scrollLeft = 40; });
      await page.waitForSelector('.lf-table-guide.is-dismissed', { state: 'attached' });
      assert.equal((await host.boundingBox()).y, top);
      assert.equal(await page.locator('.lf-table-guide:not(.is-dismissed):visible').count(), 1);
      await host.evaluate(el => { el.scrollLeft = el.scrollWidth; });
      assert(await host.evaluate(el => Math.abs(el.scrollWidth - el.clientWidth - el.scrollLeft) <= 1));
    } finally {
      await browser.close();
    }
  });
}
