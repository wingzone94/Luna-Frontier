/* Run from the Node repository with: node luna-interactive/tests/local-regression.cjs */
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const url = process.env.LUNA_TEST_URL || 'http://cybernode.local/luna-five-series-pie-test/';

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 320, height: 900 } });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.locator('.luna-chart[data-luna-ready]').first().waitFor();
    const layout = await page.locator('.luna-chart').first().evaluate((figure) => {
      const panels = [...figure.querySelectorAll('.luna-pie-panel')];
      return {
        scrollWidth: document.documentElement.scrollWidth,
        viewport: innerWidth,
        panels: panels.map((panel) => {
          const circle = panel.querySelector('.luna-pie-canvas').getBoundingClientRect();
          return { x: circle.x, y: circle.y, size: circle.width };
        }),
      };
    });
    assert.equal(layout.panels.length, 5);
    assert.ok(layout.scrollWidth <= layout.viewport, '320pxで横はみ出し');
    assert.equal(new Set(layout.panels.map(({ y }) => Math.round(y))).size, 3, '2＋2＋1の配置');
    assert.ok(Math.max(...layout.panels.map(({ size }) => size)) - Math.min(...layout.panels.map(({ size }) => size)) < 1, '円の直径');
    const names = await page.locator('.luna-chart').first().locator('.luna-series-toggle').evaluateAll((buttons) =>
      buttons.map((button) => button.getBoundingClientRect().height));
    assert.ok(Math.max(...names) - Math.min(...names) < 1, '長い系列名の高さ');

    await page.waitForTimeout(1000);
    const sweeps = await page.locator('.luna-chart').nth(1).locator('canvas').evaluate((canvas) => {
      const chart = window.Chart.getChart(canvas);
      return chart.getDatasetMeta(0).data.map((arc) => arc.endAngle - arc.startAngle);
    });
    assert.equal(sweeps.length, 3);
    sweeps.forEach((angle) => assert.ok(Math.abs(angle - 2 * Math.PI / 3) < 0.001, '均等データは120°'));

    const first = page.locator('.luna-chart').first();
    await first.getByRole('button', { name: '系列2', exact: true }).click();
    await first.getByRole('button', { name: 'A', exact: true }).click();
    await page.evaluate(() => {
      window.lunaVisibleFrames = { running: true, empty: 0 };
      const watch = () => {
        const figure = document.querySelector('.luna-chart');
        const visible = [...figure.querySelectorAll('.luna-static, .luna-dynamic')]
          .filter((layer) => !layer.hidden && getComputedStyle(layer).visibility !== 'hidden');
        if (!visible.some((layer) => layer.querySelector('canvas, img, svg'))) window.lunaVisibleFrames.empty++;
        if (window.lunaVisibleFrames.running) requestAnimationFrame(watch);
      };
      requestAnimationFrame(watch);
    });
    await first.getByRole('radio', { name: '静的表示' }).click();
    assert.equal(await first.locator('.luna-static-img').count(), 4);
    assert.ok(await first.locator('.luna-static-img').evaluateAll((images) =>
      images.every((image) => image.src.startsWith('data:image/webp'))), '静的WebP');
    const colorCount = await first.locator('.luna-static-img').first().evaluate(async (image) => {
      await image.decode();
      const canvas = document.createElement('canvas');
      canvas.width = image.naturalWidth;
      canvas.height = image.naturalHeight;
      const context = canvas.getContext('2d');
      context.drawImage(image, 0, 0);
      const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
      const colors = new Set();
      for (let i = 0; i < pixels.length; i += 64)
        colors.add(`${pixels[i]},${pixels[i + 1]},${pixels[i + 2]}`);
      return colors.size;
    });
    assert.ok(colorCount > 4, 'WebPにグラフが描画されている');
    await first.getByRole('radio', { name: '棒グラフ' }).click();
    assert.equal(await first.locator('[data-luna-role="series"][data-index="1"]').getAttribute('aria-pressed'), 'false');
    await first.getByRole('radio', { name: '動的表示' }).click();
    assert.equal(await page.evaluate(() => {
      window.lunaVisibleFrames.running = false;
      return window.lunaVisibleFrames.empty;
    }), 0, '表示切り替え中に空の描画フレーム');
    await first.getByRole('radio', { name: '円グラフ' }).click();
    assert.equal(await first.locator('[data-luna-role="item"][data-index="0"]').getAttribute('aria-pressed'), 'false');
    assert.equal(await first.locator('[data-luna-role="series"][data-index="1"]').getAttribute('aria-pressed'), 'false');
    assert.equal(await first.locator('.luna-dynamic canvas').count(), 4);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.locator('.luna-chart[data-luna-ready]').first().waitFor();
    const piePoint = await page.locator('.luna-chart').first().locator('canvas').first().evaluate((canvas) => {
      const arc = window.Chart.getChart(canvas).getDatasetMeta(0).data[0];
      const rect = canvas.getBoundingClientRect();
      const angle = (arc.startAngle + arc.endAngle) / 2;
      return { x: rect.x + arc.x + Math.cos(angle) * arc.outerRadius * 0.65,
        y: rect.y + arc.y + Math.sin(angle) * arc.outerRadius * 0.65 };
    });
    await page.mouse.move(piePoint.x, piePoint.y);
    await page.waitForTimeout(80);
    const hovered = await page.locator('.luna-chart').first().locator('canvas').first().evaluate((canvas) => {
      const chart = window.Chart.getChart(canvas);
      return { count: chart.tooltip.dataPoints?.length || 0,
        percent: chart.tooltip.dataPoints?.[0]?.formattedValue || '' };
    });
    assert.equal(hovered.count, 1, 'ホバーは1系列');
    const tooltip = await page.evaluate(() => {
      const canvas = document.querySelector('.luna-chart canvas').getBoundingClientRect();
      const tip = document.querySelector('.luna-pie-tooltip');
      const rect = tip.getBoundingClientRect();
      return { visible: !tip.hidden, outside: rect.left >= canvas.right || rect.right <= canvas.left,
        text: tip.textContent };
    });
    assert.ok(tooltip.visible && tooltip.outside && tooltip.text.includes('50%'), '円の外側に割合を表示');
    for (const name of ['棒グラフ', '折れ線グラフ']) {
      await page.locator('.luna-chart').first().getByRole('radio', { name }).click();
      await page.waitForTimeout(300);
      const point = await page.locator('.luna-chart').first().locator('canvas').evaluate((canvas) => {
        const element = window.Chart.getChart(canvas).getDatasetMeta(0).data[0];
        const rect = canvas.getBoundingClientRect();
        const center = element.getCenterPoint();
        return { x: rect.x + center.x, y: rect.y + center.y };
      });
      await page.mouse.move(point.x, point.y);
      await page.waitForTimeout(80);
      assert.equal(await page.locator('.luna-chart').first().locator('canvas').evaluate((canvas) =>
        window.Chart.getChart(canvas).tooltip.dataPoints?.length || 0), 1, `${name}のホバーは1系列`);
    }
    assert.deepEqual(errors, [], 'ページ内JavaScriptエラー');
    console.log('Luna interactive: 320px・120°・同一直径・選択保持・WebP・ホバー OK');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
