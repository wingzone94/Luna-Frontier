import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

const css = ['style', 'luna-style'].map(name =>
    readFileSync(new URL(`../dist/css/${name}.css`, import.meta.url), 'utf8')
).join('\n');

test('desktop article keeps its reading width when the aside is absent', async () => {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
        await page.setContent('<body class="lf-theme single-post"><main class="article-view"><article class="m3-article"><header class="lf-article-header">Title</header><aside class="lf-reading-aside">Contents</aside><div class="m3-article__body">Body</div></article><section>Related</section><div class="lf-comments-layout"><section>Comments</section></div></main>');
        await page.addStyleTag({ content: css });
        const body = page.locator('.m3-article__body');
        const original = await body.boundingBox();
        await page.locator('.lf-reading-aside').evaluate(el => el.remove());
        const centered = await body.boundingBox();
        const comments = await page.locator('.lf-comments-layout > section').boundingBox();
        assert.ok(Math.abs(original.width - centered.width) < 2, 'reading width preserved');
        assert.ok(Math.abs(centered.x + centered.width / 2 - 960) < 2, 'article centered');
        assert.ok(Math.abs(centered.x - comments.x) < 2, 'comments aligned');
        await page.setViewportSize({ width: 390, height: 844 });
        assert.ok(await body.evaluate(el => el.getBoundingClientRect().right <= innerWidth));
    } finally {
        await browser.close();
    }
});

test('sparse home layout stacks discovery panels at desktop width', async () => {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
        await page.setContent('<body class="lf-theme"><main class="lf-home"><div class="lf-home__columns"><section class="lf-home__latest">Latest</section><aside class="lf-home__aside">Discover</aside></div></main>');
        await page.addStyleTag({ content: css });
        const latest = page.locator('.lf-home__latest');
        const aside = page.locator('.lf-home__aside');
        const columns = page.locator('.lf-home__columns');
        assert.ok((await aside.boundingBox()).x > (await latest.boundingBox()).x);
        await columns.evaluate(el => el.classList.add('lf-home__columns--few-stories'));
        assert.ok((await aside.boundingBox()).y > (await latest.boundingBox()).y);
        await page.setViewportSize({ width: 390, height: 844 });
        assert.ok((await aside.boundingBox()).y > (await latest.boundingBox()).y);
    } finally {
        await browser.close();
    }
});
