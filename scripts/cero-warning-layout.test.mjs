import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

const css = ['style', 'luna-style'].map(name => readFileSync(new URL(`../dist/css/${name}.css`, import.meta.url), 'utf8')).join('\n');
const fixture = `<!doctype html><html lang="ja"><body class="lf-theme single single-post is-loaded">
<main class="site-main article-view"><article class="m3-article">
<aside class="m3-article__cero-warning" aria-labelledby="warning">
<h2 class="m3-article__cero-warning-title" id="warning"><span class="material-symbols-outlined" data-icon="warning" aria-hidden="true"></span>年齢制限に関する注意事項</h2>
<p>この記事で扱うゲームには、CERO Z（18歳以上対象）のタイトルが含まれます。</p>
<p>対象タイトル：<strong>${'UnbrokenGameTitle'.repeat(16)}、二つ目のタイトル</strong></p>
<p class="m3-article__cero-warning-note">（日本のCEROレーティングに基づく警告です）</p>
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
