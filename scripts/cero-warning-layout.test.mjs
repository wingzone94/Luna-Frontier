import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

const css = ['style', 'luna-style'].map(name => readFileSync(new URL(`../dist/css/${name}.css`, import.meta.url), 'utf8')).join('\n');
const fixture = `<!doctype html><html lang="ja"><body class="lf-theme single single-post is-loaded">
<main class="site-main article-view"><article class="m3-article">
<aside class="m3-article__cero-warning" aria-labelledby="warning">
<h2 class="m3-article__cero-warning-title" id="warning"><span class="material-symbols-outlined" data-icon="warning" aria-hidden="true"></span>年齢制限に関する注意事項</h2>
<p>この記事で扱うゲームには、CERO Z（18歳以上のみ対象）のタイトルが含まれます。</p>
<p>対象タイトル：<strong>${'UnbrokenGameTitle'.repeat(16)}、二つ目のタイトル</strong></p>
<p class="m3-article__cero-warning-note">レーティングは日本版のCERO区分に基づきます</p>
</aside></article></main></body></html>`;

test('CERO warning keeps long titles readable in both themes and across viewport sizes', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        await page.setContent(fixture);
        await page.addStyleTag({ content: css });

        for (const width of [320, 768, 1280]) {
            await page.setViewportSize({ width, height: 800 });
            for (const [theme, background] of [['light', 'rgb(255, 241, 204)'], ['dark', 'rgb(64, 53, 30)']]) {
                await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, theme);
                const result = await page.evaluate(() => {
                    const box = document.querySelector('.m3-article__cero-warning');
                    const style = getComputedStyle(box);
                    const title = getComputedStyle(box.querySelector('h2'));
                    const text = getComputedStyle(box.querySelector('p'));
                    const note = getComputedStyle(box.querySelector('.m3-article__cero-warning-note'));
                    const game = getComputedStyle(box.querySelector('strong'));
                    return {
                        background: style.backgroundColor,
                        borders: [style.borderTopWidth, style.borderRightWidth, style.borderBottomWidth, style.borderLeftWidth],
                        shadow: style.boxShadow,
                        radius: style.borderTopLeftRadius,
                        padding: style.paddingTop,
                        overflow: box.scrollWidth - box.clientWidth,
                        weights: [title.fontWeight, text.fontWeight, game.fontWeight],
                        smallerNote: parseFloat(note.fontSize) < parseFloat(text.fontSize),
                    };
                });
                assert.deepEqual(result, {
                    background,
                    borders: ['0px', '0px', '0px', '0px'],
                    shadow: 'none',
                    radius: '16px',
                    padding: '16px',
                    overflow: 0,
                    weights: ['700', '400', '700'],
                    smallerNote: true,
                }, `${theme}, ${width}px`);
            }
        }
    } finally {
        await browser.close();
    }
});

test('CERO warning sits directly below article header and above body in 2-column desktop layout', async () => {
    const layoutHtml = `<!doctype html><html lang="ja"><body class="lf-theme single single-post is-loaded">
<main class="site-main article-view"><article class="m3-article">
<header class="lf-article-header"><h1 class="lf-article-header__title">Sample Article</h1></header>
<aside class="m3-article__cero-warning" aria-labelledby="warning">
<h2 class="m3-article__cero-warning-title" id="warning">年齢制限に関する注意事項</h2>
<p>この記事で扱うゲームには、CERO Z（18歳以上のみ対象）のタイトルが含まれます。</p>
</aside>
<aside class="lf-reading-aside" style="min-height: 420px;">Reading Aside / TOC</aside>
<div class="m3-article__body"><p>Article body content.</p></div>
</article></main></body></html>`;

    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
        await page.setContent(layoutHtml);
        await page.addStyleTag({ content: css });

        const rects = await page.evaluate(() => {
            const get = (sel) => {
                const r = document.querySelector(sel).getBoundingClientRect();
                return { top: r.top, bottom: r.bottom, left: r.left, width: r.width };
            };
            return {
                header: get('.lf-article-header'),
                warning: get('.m3-article__cero-warning'),
                body: get('.m3-article__body'),
                aside: get('.lf-reading-aside'),
            };
        });

        assert.ok(rects.warning.top >= rects.header.bottom, 'warning must be below header');
        assert.ok(rects.body.top >= rects.warning.bottom, 'body must be below warning');
        assert.ok(rects.body.top - rects.warning.bottom < 80, 'reading aside must not push body down');
        assert.equal(Math.round(rects.warning.width), Math.round(rects.body.width), 'warning shares column 1 width with body');
        assert.ok(rects.aside.left >= rects.warning.left + rects.warning.width, 'aside stays in column 2');
    } finally {
        await browser.close();
    }
});
