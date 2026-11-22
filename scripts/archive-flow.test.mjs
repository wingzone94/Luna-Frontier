import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

const script = readFileSync(new URL('../assets/js/luna-archive-flow.js', import.meta.url), 'utf8');
const sortCss = readFileSync(new URL('../assets/css/luna-archive-flow.css', import.meta.url), 'utf8');
const card = (id, date) => `<article id="post-${id}" data-date="${date}"><h2>Post ${id}</h2></article>`;
const layout = (cards, next = '', type = 'archive') => `<!doctype html><body class="${type}"><main><div class="m3-post-grid__container">${cards}</div><nav class="m3-navigation">${next ? `<a class="next" href="${next}">Next</a>` : ''}</nav></main><footer>Footer</footer></body>`;

test('sort control CSS matches its source', () => {
  assert.equal(sortCss, readFileSync(new URL('../src/luna/styles/_archive-flow.css', import.meta.url), 'utf8'));
});

for (const type of ['archive', 'search']) for (const order of ['asc', 'desc']) {
  test(`${type} flow keeps ${order} order, filters, ties, uniqueness and stops`, async () => {
    const browser = await chromium.launch({ headless: true });
    try {
      const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
      const observed = [];
      const rows = order === 'asc'
        ? [[1, '2026-01-01'], [2, '2026-01-01'], [3, '2026-01-02'], [4, '2026-01-03']]
        : [[4, '2026-01-03'], [3, '2026-01-02'], [2, '2026-01-01'], [1, '2026-01-01']];
      await page.addInitScript(() => {
        window.nodeFlowSettings = {};
        window.IntersectionObserver = class {
          constructor(callback) { this.callback = callback; this.stopped = false; }
          observe(target) { this.stopped = false; setTimeout(() => { if (!this.stopped) this.callback([{ isIntersecting: true, target }]); }, 0); }
          unobserve() { this.stopped = true; }
          disconnect() { this.stopped = true; }
        };
      });
      await page.route('https://flow.test/**', async route => {
        const url = new URL(route.request().url());
        const number = Number(url.searchParams.get('paged') || 1);
        observed.push(url);
        if (number === 1) return route.fulfill({ contentType: 'text/html', body: layout(card(...rows[0]) + card(...rows[1]), '?paged=2', type) });
        if (number === 2) return route.fulfill({ contentType: 'text/html', body: layout(card(...rows[1]) + `<div>${card(...rows[2])}</div>`, '?paged=3&sort=wrong', type) });
        return route.fulfill({ contentType: 'text/html', body: layout(card(...rows[2]) + card(...rows[3]), '', type) });
      });
      const sortKey = type === 'search' ? 'm3_sort' : 'sort';
      const sortValue = type === 'search' ? (order === 'asc' ? 'oldest' : 'newest') : `date-${order}`;
      await page.goto(`https://flow.test/category/?${sortKey}=${sortValue}&filter[]=a&filter[]=b`);
      await page.addScriptTag({ content: script });
      await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
      await page.waitForFunction(() => document.querySelectorAll('.m3-post-grid__container [id^="post-"]').length === 4);
      await page.waitForFunction(() => !document.querySelector('.node-flow-loader'));
      assert.deepEqual(await page.locator('.m3-post-grid__container [id^="post-"]').evaluateAll(els => els.map(e => Number(e.id.slice(5)))), rows.map(row => row[0]));
      assert.deepEqual(observed.slice(1).map(url => url.searchParams.get(sortKey)), [sortValue, sortValue]);
      assert.deepEqual(observed[2].searchParams.getAll('filter[]'), ['a', 'b']);
      assert.equal(await page.locator('footer').innerText(), 'Footer');
    } finally { await browser.close(); }
  });
}

test('failed page load restores a usable next link and removes the loader', async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    await page.addInitScript(() => {
      window.nodeFlowSettings = {};
      window.IntersectionObserver = class { constructor(cb) { this.cb = cb; } observe(target) { setTimeout(() => this.cb([{ isIntersecting: true, target }]), 0); } disconnect() {} unobserve() {} };
    });
    await page.route('https://flow.test/**', route => {
      const pageNo = new URL(route.request().url()).searchParams.get('paged');
      return pageNo ? route.fulfill({ status: 503, body: 'unavailable' }) : route.fulfill({ contentType: 'text/html', body: layout(card(1, '2026-01-01'), '?paged=2') });
    });
    await page.goto('https://flow.test/category/?sort=date-asc');
    await page.addScriptTag({ content: script });
    await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
    await page.waitForFunction(() => !document.querySelector('.node-flow-loader') && getComputedStyle(document.querySelector('.m3-navigation')).display !== 'none');
    assert.match(await page.locator('.m3-navigation .next').getAttribute('href'), /paged=2/);
    assert.match(await page.locator('.m3-navigation .next').getAttribute('href'), /sort=date-asc/);
  } finally { await browser.close(); }
});

test('a stalled request times out and restores pagination', async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    await page.addInitScript(() => {
      const nativeTimeout = window.setTimeout;
      window.setTimeout = (callback, delay, ...args) => nativeTimeout(callback, delay === 15000 ? 30 : delay, ...args);
      window.IntersectionObserver = class { constructor(cb) { this.cb = cb; } observe(target) { nativeTimeout(() => this.cb([{ isIntersecting: true, target }]), 0); } disconnect() {} unobserve() {} };
      window.fetch = (_url, options) => new Promise((_resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('aborted'))));
    });
    await page.route('https://flow.test/**', route => route.fulfill({ contentType: 'text/html', body: layout(card(1, '2026-01-01'), '?paged=2') }));
    await page.goto('https://flow.test/category/?sort=date-asc');
    await page.addScriptTag({ content: script });
    await page.waitForFunction(() => !document.querySelector('.node-flow-loader') && getComputedStyle(document.querySelector('.m3-navigation')).display !== 'none');
    assert.match(await page.locator('.m3-navigation .next').getAttribute('href'), /paged=2/);
  } finally { await browser.close(); }
});
