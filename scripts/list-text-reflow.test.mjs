import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

const css = ['style', 'luna-archive'].map(name => readFileSync(new URL(`../dist/css/${name}.css`, import.meta.url), 'utf8')).join('\n');
const title = 'OpenAI「28日間チャレンジ」後半戦まとめ！CodexやChatGPT Workの改善を振り返る';
const fixture = `<!doctype html><html lang="ja"><body class="lf-theme lf-legacy-archive is-loaded"><main id="primary">
<section class="m3-archive-header"><div class="m3-headlines__header"><h1 id="all-articles-title" class="m3-headlines__title m3-section-title">ALL ARTICLES <span class="m3-section-title__sub">記事一覧</span></h1></div></section>
<div class="m3-post-grid"><div class="m3-post-grid__container">
<article class="m3-card c-card"><div class="m3-card__visual"><a class="m3-card__image-link" href="#article"><img width="320" height="180" alt="" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='320' height='180'/%3E"></a></div>
<div class="m3-card__content"><div class="m3-card__category-container"><div class="m3-article__category-group"><a class="m3-label--category" href="#category">非常に長い日本語のカテゴリ名も省略せず読めるようにします</a><a class="m3-label--category" href="#second">二つ目</a></div></div>
<h2 class="m3-card__title"><a href="#article">${title}</a></h2><div class="m3-card__footer"><div class="m3-card__dates"><div class="m3-card__date"><span>2026年10月23日</span></div><div class="m3-card__date m3-card__date--modified"><time>追記 10/24</time></div></div></div></div></article></div></div></main></body></html>`;

test('mobile cards preserve compact rows, then reveal full text at 200/400% text scale', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        await page.setContent(fixture);
        await page.addStyleTag({ content: css });
        for (const width of [390, 430, 600, 700]) {
            await page.setViewportSize({ width, height: 844 });
            for (const theme of ['light', 'dark']) {
                for (const size of [16, 32, 64]) {
                    await page.evaluate(({ size, theme }) => {
                        document.documentElement.style.fontSize = `${size}px`;
                        document.documentElement.dataset.theme = theme;
                    }, { size, theme });
                    const result = await page.evaluate(() => {
                        const title = document.querySelector('h2');
                        const chip = document.querySelector('.m3-label--category');
                        const image = document.querySelector('.m3-card__visual').getBoundingClientRect();
                        const content = document.querySelector('.m3-card__content').getBoundingClientRect();
                        const heading = document.querySelector('h1').getBoundingClientRect();
                        return { dateOverflow: Math.max(...[...document.querySelectorAll('.m3-card__date')].map(x => x.scrollWidth-x.clientWidth)), cardWidth: document.querySelector('.m3-card').clientWidth, titleWidth: title.clientWidth, titleFont: parseFloat(getComputedStyle(title).fontSize), clippedTitle: title.scrollHeight - title.clientHeight, clippedChip: chip.scrollHeight - chip.clientHeight, imageBottom: image.bottom, contentTop: content.top, imageTop: image.top, headingRight: heading.right, overflow: document.documentElement.scrollWidth - innerWidth };
                    });
                    const context = JSON.stringify({ width, theme, size, result });
                    assert.ok(result.headingRight <= width + 1, context);
                    assert.ok(result.overflow <= 1, context);
                    if (size === 16) assert.ok(Math.abs(result.imageTop - result.contentTop) <= 1, context);
                    else if (result.cardWidth <= 12 * size) {
                        assert.ok(result.contentTop >= result.imageBottom - 1, context);
                        assert.ok(result.clippedTitle <= 1, context);
                        assert.ok(result.clippedChip <= 1, context);
                        assert.ok(result.dateOverflow <= 1, context);
                    } else {
                        // Wider cards retain the existing two-line preview, with usable text width.
                        assert.ok(result.titleWidth >= 8 * result.titleFont, context);
                    }
                }
            }
        }
    } finally {
        await browser.close();
    }
});

test('Luna search cards restore full titles and metadata when text size changes', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent(fixture.replace('lf-legacy-archive', 'search search-results'));
        const lunaCss = ['style', 'luna-style'].map(name => readFileSync(new URL(`../dist/css/${name}.css`, import.meta.url), 'utf8')).join('\n');
        await page.addStyleTag({ content: lunaCss });
        const luna = readFileSync(new URL('../src/luna/luna.js', import.meta.url), 'utf8');
        const titleLogic = luna.slice(luna.indexOf('const TITLE_SELECTOR'), luna.indexOf('const ARTICLE_HEADING_SELECTOR'));
        await page.evaluate(`${titleLogic};setupTitleAutoFit();`);
        for (const size of [16, 32, 64, 16]) {
            await page.evaluate(size => { document.documentElement.style.fontSize = `${size}px`; }, size);
            if (size > 16) await page.waitForFunction(() => {
                const title = document.querySelector('h2');
                return title.querySelector('a').textContent === title.dataset.lfTitle && getComputedStyle(title).overflow === 'visible';
            });
            const result = await page.evaluate(() => {
                const title = document.querySelector('h2');
                const image = document.querySelector('.m3-card__visual').getBoundingClientRect();
                const content = document.querySelector('.m3-card__content').getBoundingClientRect();
                return { titleHidden: title.scrollHeight-title.clientHeight, imageTop: image.top, contentTop: content.top, imageBottom: image.bottom, dateHidden: Math.max(...[...document.querySelectorAll('.m3-card__date')].map(x=>x.scrollWidth-x.clientWidth)) };
            });
            if (size > 16) {
                assert.ok(result.titleHidden <= 2, JSON.stringify(result));
                assert.ok(result.dateHidden <= 1, JSON.stringify(result));
                assert.ok(result.contentTop >= result.imageBottom-1, JSON.stringify(result));
            } else assert.ok(Math.abs(result.imageTop-result.contentTop)<=1, JSON.stringify(result));
        }
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});
