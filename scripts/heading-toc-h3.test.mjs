import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

const js = readFileSync(new URL('../dist/js/luna.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../dist/css/luna-style.css', import.meta.url), 'utf8');

test('floating Contents preserves H3, indentation, wrapping, and final entry', async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1920, height: 900 } });
    const items = Array.from({ length: 15 }, (_, i) => ({
      id: `section-${i}`, level: i === 5 || i === 6 ? 'h3' : 'h2',
      text: i === 5 ? 'Aquatic Update' : i === 6 ? 'Village&Pillage' : `長い見出し ${i} の表示と折り返しを確認するためのテキスト`,
    }));
    const toc = items.map(({ id, level, text }) => `<li class="lf-toc__item" data-level="${level}"><a class="lf-toc__link" href="#${id}">${text}</a></li>`).join('');
    const headings = items.map(({ id, level, text }) => `<${level} id="${id}">${text}</${level}>`).join('');
    await page.setContent(`<style>${css}</style><style>body { margin: 0 } .m3-article { position: relative; width: 820px; margin: auto } .m3-article__body { width: 820px } .m3-article__body h2, .m3-article__body h3 { margin: 30px 0 }</style><body class="lf-theme single-post"><div class="m3-article"><aside class="lf-aside-block--toc"><ol class="lf-toc">${toc}</ol></aside><div class="m3-article__body">${headings}</div></div></body>`);
    await page.addScriptTag({ content: js });
    await page.locator('.m3-article__body h2').first().hover();
    const panel = page.locator('.lf-heading-toc');
    await panel.waitFor({ state: 'visible' });
    const entries = panel.locator('.lf-toc__item');
    assert.equal(await entries.count(), 15);
    assert.deepEqual(await panel.locator('[data-level="h3"] a').allTextContents(), ['Aquatic Update', 'Village&Pillage']);
    const padding = await panel.locator('[data-level="h3"] a').first().evaluate(e => parseFloat(getComputedStyle(e).paddingInlineStart));
    const basePadding = await panel.locator('[data-level="h2"] a').first().evaluate(e => parseFloat(getComputedStyle(e).paddingInlineStart));
    assert.ok(padding > basePadding, `H3 indent ${padding} should exceed H2 ${basePadding}`);
    const last = entries.last();
    await last.scrollIntoViewIfNeeded();
    assert.equal(await last.locator('a').getAttribute('href'), '#section-14');
    const visible = await last.evaluate(e => {
      const item = e.getBoundingClientRect();
      const panel = e.closest('.lf-heading-toc').getBoundingClientRect();
      return item.bottom <= panel.bottom && item.top >= panel.top;
    });
    assert.ok(visible, 'final entry can be scrolled fully into view');
    const overflow = await panel.evaluate(e => e.scrollWidth <= e.clientWidth);
    assert.ok(overflow, 'wrapped titles must not create horizontal overflow');
  } finally {
    await browser.close();
  }
});
