import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../src/scripts/product-card-popover.js', import.meta.url), 'utf8')
  .replace('export const initProductCardPopovers', 'const initProductCardPopovers')
  + '\nthis.api = { initProductCardPopovers };';

function createDom() {
  const docListeners = new Map();
  const winListeners = new Map();

  function createElement(id) {
    const attrs = new Map();
    const listeners = new Map();
    let focused = false;
    return {
      id,
      hidden: false,
      dataset: {},
      style: {},
      get focused() { return focused; },
      focus() { focused = true; },
      blur() { focused = false; },
      setAttribute(name, value) { attrs.set(name, String(value)); },
      getAttribute(name) { return attrs.get(name) ?? null; },
      matches(sel) { return sel === '[data-product-download-info-popover]' && attrs.has('data-product-download-info-popover'); },
      contains(target) { return target === this; },
      getBoundingClientRect() { return { top: 200, bottom: 248, left: 100, right: 320, width: 220, height: 100 }; },
      addEventListener(type, fn) {
        if (!listeners.has(type)) listeners.set(type, []);
        listeners.get(type).push(fn);
      },
      dispatch(type, event = {}) {
        const ev = { target: this, preventDefault() {}, stopPropagation() {}, ...event };
        (listeners.get(type) || []).forEach((fn) => fn(ev));
      },
    };
  }

  const toggle1 = createElement('btn-1');
  toggle1.setAttribute('aria-controls', 'pop-1');
  const pop1 = createElement('pop-1');
  pop1.setAttribute('data-product-download-info-popover', '');
  pop1.hidden = true;

  const toggle2 = createElement('btn-2');
  toggle2.setAttribute('aria-controls', 'pop-2');
  const pop2 = createElement('pop-2');
  pop2.setAttribute('data-product-download-info-popover', '');
  pop2.hidden = true;

  const outside = createElement('outside');
  const byId = new Map([['pop-1', pop1], ['pop-2', pop2]]);

  const context = {
    document: {
      querySelectorAll: (sel) => sel === '[data-product-download-info-toggle]' ? [toggle1, toggle2] : [],
      getElementById: (id) => byId.get(id) || null,
      addEventListener(type, fn) {
        if (!docListeners.has(type)) docListeners.set(type, []);
        docListeners.get(type).push(fn);
      },
    },
    window: {
      innerWidth: 320,
      innerHeight: 640,
      addEventListener(type, fn) {
        if (!winListeners.has(type)) winListeners.set(type, []);
        winListeners.get(type).push(fn);
      },
    },
  };

  vm.runInNewContext(source, context);
  context.api.initProductCardPopovers();

  return {
    toggle1, pop1, toggle2, pop2, outside,
    dispatchDoc(type, event = {}) {
      const ev = { preventDefault() {}, ...event };
      (docListeners.get(type) || []).forEach((fn) => fn(ev));
    },
  };
}

test('Download info popover opens and closes on click, outside click, and Escape', () => {
  const dom = createDom();

  assert.equal(dom.pop1.hidden, true);
  assert.equal(dom.toggle1.getAttribute('aria-expanded'), 'false');

  // Click opens popover
  dom.toggle1.dispatch('click');
  assert.equal(dom.pop1.hidden, false);
  assert.equal(dom.toggle1.getAttribute('aria-expanded'), 'true');

  // Opening second popover closes first
  dom.toggle2.dispatch('click');
  assert.equal(dom.pop1.hidden, true);
  assert.equal(dom.toggle1.getAttribute('aria-expanded'), 'false');
  assert.equal(dom.pop2.hidden, false);
  assert.equal(dom.toggle2.getAttribute('aria-expanded'), 'true');

  // Escape closes popover and restores focus
  dom.dispatchDoc('keydown', { key: 'Escape' });
  assert.equal(dom.pop2.hidden, true);
  assert.equal(dom.toggle2.getAttribute('aria-expanded'), 'false');
  assert.equal(dom.toggle2.focused, true);

  // Outside click closes open popover
  dom.toggle1.dispatch('click');
  assert.equal(dom.pop1.hidden, false);
  dom.dispatchDoc('click', { target: dom.outside });
  assert.equal(dom.pop1.hidden, true);
  assert.equal(dom.toggle1.getAttribute('aria-expanded'), 'false');
});
