/** Local acceptance checks for Luna's editorial home. Run with Bun. */
import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import assert from 'node:assert/strict';

const base = process.env.NODE_VISUAL_BASE_URL || 'http://cybernode.local';
const output = process.env.LUNA_HOME_ARTIFACTS || '/tmp/luna-home-verification';
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true });
const report = [];

async function checkPage(page, url, label) {
  const errors = [];
  const failed = [];
  const cancelled = [];
  const onError = error => errors.push(error.message);
  const onFailed = request => {
    const entry = { url: request.url(), reason: request.failure()?.errorText };
    // Responsive srcset changes and navigation can cancel an otherwise valid request.
    (entry.reason === 'net::ERR_ABORTED' ? cancelled : failed).push(entry);
  };
  page.on('pageerror', onError);
  page.on('requestfailed', onFailed);
  const response = await page.goto(url, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  assert.equal(response.status(), 200, label);
  const geometry = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
  assert.ok(geometry.scroll <= geometry.width, `${label}: page overflow ${JSON.stringify(geometry)}`);
  assert.deepEqual(errors, [], `${label}: script errors`);
  assert.deepEqual(failed, [], `${label}: failed requests`);
  assert.doesNotMatch(await page.locator('body').innerText(), /Fatal error|Warning:|Notice:/);
  report.push({ label, http: response.status(), ...geometry, errors, failed, cancelled });
  page.off('pageerror', onError);
  page.off('requestfailed', onFailed);
}

try {
  for (const theme of ['light', 'dark']) {
    const context = await browser.newContext();
    await context.addInitScript(theme => {
      localStorage.setItem('node-color-mode', 'manual');
      localStorage.setItem('node_theme', theme);
    }, theme);
    const page = await context.newPage();
    for (const width of [1440, 1024, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      await checkPage(page, base, `home-${theme}-${width}`);
      assert.equal(await page.locator('.lf-home').count(), 1);
      assert.equal(await page.locator('main h1').count(), 1);
      assert.ok((await page.locator('#lf-news-title').innerText()).includes('速報'));
      assert.equal(await page.locator('.lf-home-story__views').count(), 0);
      assert.doesNotMatch(await page.locator('.lf-home__ranking').innerText(), /PV/);
      assert.equal(await page.locator('#lf-home-category').count(), 1);
      assert.ok(await page.locator('.lf-topic-nav__features > li').count() <= 4);
      if (await page.locator('.lf-topic-nav__features > li').count()) {
        assert.ok(await page.locator('.lf-topic-nav__features').isVisible());
        assert.ok(await page.locator('.lf-topic-nav__past').isVisible());
      }
      assert.ok(await page.locator('.lf-home-story--news').count() <= 4);
      assert.ok(await page.locator('.lf-home-story--latest').count() <= 5);
      assert.ok(await page.locator('.lf-home-story--popular').count() <= 5);
      assert.equal(await page.locator('a a').count(), 0, 'Nested links');
      const invalidImages = await page.locator('.lf-home img').evaluateAll(images => images.filter(img => img.complete && !img.naturalWidth).map(img => img.src));
      assert.deepEqual(invalidImages, []);
      if (width === 1440 || width === 390) {
        await page.screenshot({ path: `${output}/home-${theme}-${width}.png`, fullPage: true });
      }
      if (width === 1440) {
        assert.equal(await page.locator('#m3-search-input').isVisible(), false);
        await page.locator('#search-toggle').click();
        assert.ok(await page.locator('#m3-search-input').isVisible());
        assert.equal(await page.locator('#m3-search-mobile-close').isVisible(), true);
        await page.locator('#m3-search-input').focus();
        const outline = await page.locator('.m3-search-input-wrapper').evaluate(el => getComputedStyle(el).outlineStyle);
        assert.equal(outline, 'none', 'No outline around the search box');
        const submit = await page.locator('#m3-search-submit').evaluate(el => { const c = getComputedStyle(el); return { width: c.width, height: c.height, radius: c.borderRadius, background: c.backgroundColor }; });
        assert.equal(submit.width, submit.height);
        assert.equal(submit.radius, '50%');
        assert.notEqual(submit.background, 'rgba(0, 0, 0, 0)');
        const header = await page.locator('#masthead').boundingBox();
        assert.equal(header.height, 64, 'Preserve header height');
      }
      if (width === 390) {
        await page.locator('#search-toggle').click();
        assert.ok(await page.locator('#m3-search-input').isVisible());
        await page.locator('#m3-search-input').fill('Nintendo');
        await page.locator('#m3-search-input').press('Escape');
        if (!(await page.locator('#masthead').getAttribute('class')).includes('search-is-active')) {
          await page.locator('#search-toggle').click();
        }
        await page.locator('#m3-search-mobile-close').click();
        assert.ok(await page.locator('#search-toggle').isVisible());
      }
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(base, { waitUntil: 'networkidle' });
    const latestLink = await page.locator('.lf-home-story--latest a').first().getAttribute('href');
    const categoryId = await page.locator('#lf-home-category option:not([value="0"])').first().getAttribute('value');
    const categoryLink = `${base}/?cat=${categoryId}`;
    const moreLink = await page.locator('.lf-home__latest .lf-home__more').getAttribute('href');
    for (const [label, url] of [['article', latestLink], ['category', categoryLink], ['archive', moreLink], ['search', `${base}/?s=Nintendo`], ['search-empty', `${base}/?s=lf-no-match-96281`]]) {
      for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await checkPage(page, url, `${label}-${theme}-${width}`);
      }
    }
    await context.close();
  }

  const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  await checkPage(page, base, 'home-no-js');
  const filter = page.locator('.lf-home__filters a').nth(1);
  const filterUrl = await filter.getAttribute('href');
  await checkPage(page, filterUrl, 'category-filter-no-js');
  assert.equal(await page.locator('.lf-home__filters a[aria-current]').getAttribute('href'), filterUrl);
  const moreUrl = await page.locator('.lf-home__latest .lf-home__more').getAttribute('href');
  assert.ok(moreUrl.includes('/category/'), 'Filtered more link leads to category archive');
  await checkPage(page, `${base}/?s=Nintendo`, 'search-results-no-js');
  await checkPage(page, `${base}/?lf_category%5B%5D=1`, 'invalid-filter');
  assert.equal(await page.locator('.lf-home__filters a[aria-current]').innerText(), 'すべて');
  await page.goto(base, { waitUntil: 'networkidle' });
  const selectedCategory = await page.locator('#lf-home-category option:not([value="0"])').first().getAttribute('value');
  await page.locator('#lf-home-category').selectOption(selectedCategory);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.lf-home__category-form button').click()]);
  assert.ok(page.url().includes('/category/') || page.url().includes('cat='));
  await context.close();
  await writeFile(`${output}/report.json`, JSON.stringify(report, null, 2));
  console.log(`PASS: ${report.length} page checks. Artifacts: ${output}`);
} finally {
  await browser.close();
}
