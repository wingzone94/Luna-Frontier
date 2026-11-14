import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import test from 'node:test';

const repoRoot = process.cwd();

test('template-parts/components/floating-actions.php: pull tab markup exists and scopes to single post', async () => {
    const phpContent = await readFile(
        join(repoRoot, 'template-parts/components/floating-actions.php'),
        'utf-8'
    );

    assert.ok(
        phpContent.includes("is_singular( 'post' )"),
        "Template must scope pull tab and TOC trigger to is_singular( 'post' )"
    );
    assert.ok(
        !/is_singular\(\s*\)/.test(phpContent),
        'Template must not use bare is_singular() which outputs article controls on pages'
    );
    assert.ok(
        phpContent.includes('id="m3-action-pull-tab"'),
        'Pull tab ID must exist in template'
    );
    assert.ok(
        phpContent.includes('class="m3-action-pull-tab"'),
        'Pull tab class must exist in template'
    );
    assert.ok(
        phpContent.includes('aria-label="<?php esc_attr_e( \'追従ボタンを折りたたむ\', \'node\' ); ?>"')
            || phpContent.includes('追従ボタンを折りたたむ'),
        'Initial aria-label must be set to 追従ボタンを折りたたむ'
    );
    assert.ok(
        phpContent.includes('aria-expanded="true"'),
        'Initial aria-expanded must be true'
    );
    assert.ok(
        phpContent.includes('aria-controls="m3-back-to-top m3-toc-trigger"'),
        'aria-controls must target both back-to-top and toc-trigger'
    );
    assert.ok(
        phpContent.includes('>expand_less<'),
        'Icon must be included subset glyph expand_less'
    );
    assert.ok(
        !phpContent.includes('chevron_right'),
        'Legacy chevron_right icon must not be used'
    );
});

test('CSS: pull tab & bottom dock share unified 767px breakpoint and clean collapse rules', async () => {
    const tocFabCss = await readFile(
        join(repoRoot, 'src/styles/_toc-fab.css'),
        'utf-8'
    );
    const articleLayoutCss = await readFile(
        join(repoRoot, 'src/luna/styles/_article-layout.css'),
        'utf-8'
    );

    assert.ok(
        tocFabCss.includes('.m3-action-pull-tab {\n    display: none;\n}'),
        'Pull tab must be hidden by default on PC'
    );
    assert.ok(
        tocFabCss.includes('@media (max-width: 767px)')
            && articleLayoutCss.includes('@media (max-width: 767px)'),
        'Both _toc-fab.css and _article-layout.css must use unified @media (max-width: 767px) breakpoint'
    );
    assert.ok(
        !tocFabCss.includes('@media (max-width: 600px)'),
        'Legacy @media (max-width: 600px) pull-tab breakpoint must be removed'
    );
    assert.ok(
        tocFabCss.includes('.m3-action-stack.has-pull-tab .m3-action-pull-tab'),
        'Mobile display rule must require .has-pull-tab'
    );
    assert.ok(
        tocFabCss.includes('right: calc(12px + env(safe-area-inset-right))'),
        'Pull tab must account for safe-area-inset-right'
    );
    assert.ok(
        tocFabCss.includes('min-width: 48px;') && tocFabCss.includes('min-height: 48px;'),
        'Pull tab must ensure >= 48x48px touch target'
    );
    assert.ok(
        tocFabCss.includes('.m3-action-stack.has-pull-tab .m3-action-pull-tab:focus-visible'),
        'Pull tab must style :focus-visible outline'
    );
    assert.ok(
        tocFabCss.includes('.m3-action-pull-tab__icon')
            && tocFabCss.includes('transform: rotate(180deg);')
            && tocFabCss.includes('.m3-action-stack.is-collapsed .m3-action-pull-tab__icon {\n        transform: rotate(0deg);'),
        'Expanded pull tab must point down (180deg) and collapsed must point up (0deg)'
    );
    assert.ok(
        tocFabCss.includes('.m3-action-stack.has-pull-tab.is-collapsed .m3-fab')
            && tocFabCss.includes('.m3-action-stack.has-pull-tab.is-collapsed #m3-toc-trigger.toc-ready')
            && tocFabCss.includes('body.is-hero-passed .m3-action-stack.is-singular:not(.is-collapsed) #m3-toc-trigger.toc-ready'),
        'Collapsed rule must hide all FABs including toc-ready after hero pass'
    );
    assert.ok(
        tocFabCss.includes('[data-theme="dark"] .m3-action-stack.has-pull-tab .m3-action-pull-tab'),
        'Dark mode rule must exist'
    );
    assert.ok(
        tocFabCss.includes('prefers-reduced-motion: reduce')
            && articleLayoutCss.includes('prefers-reduced-motion: reduce'),
        'prefers-reduced-motion rule must exist'
    );
});

test('src/scripts/floating-actions.js: pull tab state, focus rescue, rAF optimization, and post-body persistence', async () => {
    const jsContent = await readFile(
        join(repoRoot, 'src/scripts/floating-actions.js'),
        'utf-8'
    );

    assert.ok(
        jsContent.includes("document.body.classList.contains('single-post')"),
        'Script must restrict article-only controls to single-post matching PHP'
    );
    assert.ok(
        jsContent.includes("document.getElementById('m3-action-pull-tab')"),
        'Script must reference #m3-action-pull-tab'
    );
    assert.ok(
        jsContent.includes("actionStack.classList.add('has-pull-tab')"),
        'Script must add has-pull-tab class'
    );
    assert.ok(
        jsContent.includes("actionStack.classList.toggle('is-collapsed'"),
        'Script must toggle is-collapsed on click'
    );
    assert.ok(
        jsContent.includes('追従ボタンを展開する') && jsContent.includes('追従ボタンを折りたたむ'),
        'Script must switch aria-label between expanded and collapsed states'
    );
    assert.ok(
        jsContent.includes('pullTab.focus({ preventScroll: true })'),
        'Collapsing must move focus out of hidden buttons onto pullTab'
    );
    assert.ok(
        jsContent.includes("setAttribute('tabindex', '-1')")
            && jsContent.includes("removeAttribute('tabindex')")
            && jsContent.includes("setAttribute('aria-hidden', 'true')")
            && jsContent.includes("removeAttribute('aria-hidden')"),
        'Script must manage tabindex and aria-hidden for accessibility when collapsed'
    );
    assert.ok(
        jsContent.includes('requestAnimationFrame'),
        'Visibility updates must be throttled via requestAnimationFrame'
    );
    assert.ok(
        !jsContent.includes('getComputedStyle'),
        'Scroll handler must avoid synchronous getComputedStyle calls'
    );
    assert.ok(
        jsContent.includes('IntersectionObserver') && !jsContent.includes('bounds.bottom >='),
        'Must use IntersectionObserver and not hide back-to-top after scrolling past article body bottom'
    );
});

test('src/scripts/floating-actions.js: viewport resize synchronization matches 767px and clears collapsed state', async () => {
    const jsContent = await readFile(
        join(repoRoot, 'src/scripts/floating-actions.js'),
        'utf-8'
    );

    assert.ok(
        jsContent.includes("'(max-width: 767px)'") && jsContent.includes('window.matchMedia(MOBILE_DOCK_QUERY)'),
        'Script must match CSS max-width: 767px breakpoint via matchMedia'
    );
    assert.ok(
        jsContent.includes('syncPullTabA11y'),
        'Script must define syncPullTabA11y synchronization function'
    );
    assert.ok(
        jsContent.includes('isMobileDock && isCollapsed'),
        'Only hide accessibility attributes when inside mobile dock breakpoint and collapsed'
    );
    assert.ok(
        jsContent.includes('if (!mobileDockMedia.matches)') && jsContent.includes('setCollapsed(false)'),
        'Leaving max-width: 767px must immediately clear is-collapsed, aria-hidden, and tabindex'
    );
});
