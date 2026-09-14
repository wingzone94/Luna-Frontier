/** Compare the archive's content styles with the user's live Node 1.3.2 reference. */
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';

const local = process.env.NODE_VISUAL_BASE_URL || 'http://cybernode.local';
const production = 'https://luminous-core.net';
const output = process.env.LUNA_HOME_ARTIFACTS || '/tmp/luna-archive-parity';
await mkdir(output, { recursive: true });
const version = await fetch(`${production}/wp-content/themes/Node/style.css`).then(r => r.text());
assert.match(version, /Version:\s*1\.3\.2\b/, 'Reference must still be Node 1.3.2');
const browser = await chromium.launch();
const report = [];
const selectors = ['.m3-card', '.m3-card__title', '.m3-card__visual', '.m3-card__content', '.m3-label--category', '.m3-post-grid__container'];
const properties = ['borderRadius', 'boxShadow', 'fontSize', 'fontFamily', 'fontWeight', 'lineHeight', 'padding', 'gap', 'display', 'flexDirection'];

try {
  for (const theme of ['light', 'dark']) {
    for (const width of [1440, 390]) {
      const snapshots = {};
      for (const [name, base] of [['production', production], ['local', local]]) {
        const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
        await context.addInitScript(theme => {
          localStorage.setItem('node-color-mode', 'manual');
          localStorage.setItem('node_theme', theme);
        }, theme);
        const page = await context.newPage();
        const response = await page.goto(`${base}/all-articles/`, { waitUntil: 'networkidle' });
        assert.equal(response.status(), 200);
        snapshots[name] = await page.evaluate(({ selectors, properties }) => Object.fromEntries(selectors.map(selector => {
          const style = getComputedStyle(document.querySelector(selector));
          return [selector, Object.fromEntries(properties.map(property => [property, style[property]]))];
        })), { selectors, properties });
        if (name === 'local') {
          assert.equal(await page.locator('.lf-card-spectrum').count(), 0);
          assert.equal(await page.locator('.lf-title-truncated').count(), 0);
          assert.equal(await page.locator('#luna-frontier-inline-css').count(), 0);
          const color = await page.locator('.m3-label--category').first().evaluate(el => getComputedStyle(el).color);
          assert.equal(color, 'rgb(255, 255, 255)');
        }
        await page.screenshot({ path: `${output}/archive-${name}-${theme}-${width}.png` });
        await context.close();
      }
      report.push({ theme, width, ...snapshots });
    }
  }
  await writeFile(`${output}/archive-parity.json`, JSON.stringify(report, null, 2));
  for (const result of report) {
    assert.deepEqual(result.local, result.production, `${result.theme} ${result.width}px: archive content differs`);
  }
  console.log('PASS: Node 1.3.2 archive parity, 4 theme/width combinations × 6 components × 10 properties');
} finally {
  await browser.close();
}
