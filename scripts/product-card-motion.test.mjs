import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../src/scripts/card-animation.js', import.meta.url), 'utf8')
  .replaceAll('export const ', 'const ')
  + '\nthis.motion = { hasBelowFoldCardMotion, initCardAnimations };';
function setup({ nexusOnly = false, reduced = false } = {}) {
  const nexus = { style: {}, closest: () => ({}), getBoundingClientRect: () => ({ top: 2000, bottom: 2300 }) };
  const legacy = { style: {}, closest: () => null, getBoundingClientRect: () => ({ top: 2400, bottom: 2600 }) };
  const animated = [];
  const observed = [];
  const context = {
    document: {
      querySelectorAll: (selector) => selector.includes('.m3-product-card') ? (nexusOnly ? [nexus] : [nexus, legacy]) : [],
      addEventListener() {},
    },
    window: { innerHeight: 900, matchMedia: () => ({ matches: reduced }) },
    gsap: { set: (elements, values) => { animated.push(...elements); elements.forEach(e => Object.assign(e.style, values)); } },
    IntersectionObserver: class { observe(element) { observed.push(element); } },
  };
  vm.runInNewContext(source, context);
  return { ...context.motion, nexus, legacy, animated, observed };
}
test('Nexus cards remain visible even when IntersectionObserver never delivers', () => {
  const state = setup();
  assert.equal(state.hasBelowFoldCardMotion(), true, 'Legacy card still requests motion');
  state.initCardAnimations();
  assert.deepEqual(state.nexus.style, {}, 'No hidden inline style is applied to Nexus cards');
  assert.deepEqual(state.animated, [state.legacy]);
  assert.deepEqual(state.observed, [state.legacy]);
});
test('A page with only Nexus cards does not request GSAP card motion', () => {
  const state = setup({ nexusOnly: true });
  assert.equal(state.hasBelowFoldCardMotion(), false);
  state.initCardAnimations();
  assert.deepEqual(state.animated, []);
  assert.deepEqual(state.nexus.style, {});
});
test('Reduced motion continues to leave every card visible', () => {
  const state = setup({ reduced: true });
  assert.equal(state.hasBelowFoldCardMotion(), false);
  state.initCardAnimations();
  assert.deepEqual(state.animated, []);
});
