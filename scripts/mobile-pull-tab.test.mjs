import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import test from 'node:test';

const repoRoot = process.cwd();

test('template-parts/components/floating-actions.php: pull tab markup exists with correct attributes', async () => {
    const phpContent = await readFile(
        join(repoRoot, 'template-parts/components/floating-actions.php'),
        'utf-8'
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
        phpContent.includes('aria-controls="m3-action-stack"'),
        'aria-controls must target the entire action container'
    );
    assert.ok(
        phpContent.includes('chevron_right'),
        'Icon must be chevron_right'
    );
});

test('src/styles/_toc-fab.css: pull tab responsive & collapsed rules exist', async () => {
    const cssContent = await readFile(
        join(repoRoot, 'src/styles/_toc-fab.css'),
        'utf-8'
    );

    assert.ok(
        cssContent.includes('.m3-action-pull-tab {\n    display: none;\n}'),
        'Pull tab must be hidden by default on PC'
    );
    assert.ok(
        cssContent.includes('.m3-action-stack.has-pull-tab + .m3-action-pull-tab'),
        'Mobile display rule must require .has-pull-tab'
    );
    assert.ok(
        cssContent.includes('.m3-action-stack[hidden]'),
        'Hidden rule must remove the entire container'
    );
    assert.ok(
        cssContent.includes('[data-theme="dark"] .m3-action-stack.has-pull-tab + .m3-action-pull-tab'),
        'Dark mode rule must exist'
    );
    assert.ok(
        cssContent.includes('prefers-reduced-motion: reduce'),
        'prefers-reduced-motion rule must exist'
    );
});

test('src/scripts/floating-actions.js: pull tab state management logic', async () => {
    const jsContent = await readFile(
        join(repoRoot, 'src/scripts/floating-actions.js'),
        'utf-8'
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
        jsContent.includes("setAttribute('tabindex', '-1')")
            && jsContent.includes("removeAttribute('tabindex')"),
        'Script must manage tabindex for accessibility when collapsed'
    );
});

test('src/scripts/floating-actions.js: viewport resize accessibility synchronization logic', async () => {
    const jsContent = await readFile(
        join(repoRoot, 'src/scripts/floating-actions.js'),
        'utf-8'
    );

    assert.ok(
        jsContent.includes("window.matchMedia('(min-width: 1001px)')"),
        'Script must check min-width: 1001px matching CSS breakpoint'
    );
    assert.ok(
        jsContent.includes('syncPullTabA11y'),
        'Script must define a synchronization function'
    );
    assert.ok(
        jsContent.includes('!isDesktop && isCollapsed'),
        'Only hide accessibility attributes when NOT desktop and collapsed'
    );
});
