import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../plugins-embedded/node-library/assets/js/block-editor.js', import.meta.url), 'utf8');
function loadEditor(available) {
  const registered = new Map();
  const components = new Proxy({}, { get: (_, key) => key });
  const window = {
    nodeLibraryEditor: { productCardAvailable: available },
    wp: {
      blocks: { registerBlockType: (name, config) => registered.set(name, config) },
      element: {
        createElement: (type, props, ...children) => ({ type, props, children }),
        useState: (value) => [value, () => {}],
      },
      components, data: {}, apiFetch: () => {}, blockEditor: { InspectorControls: 'InspectorControls' },
    },
  };
  vm.runInNewContext(source, { window });
  return registered;
}
for (const available of [true, false]) {
  test(`Product dependency notice and saved attributes: Nexus ${available}`, () => {
    const registry = loadEditor(available);
    const block = registry.get('node-library/product-card');
    const attributes = { title: '既存の商品', asin: 'B012345678', showAmazonDisclosure: false };
    let writes = 0;
    const elements = block.edit({ attributes, setAttributes: () => { writes++; } });
    const notice = elements.find((element) => element?.type === 'Notice');
    assert.equal(Boolean(notice), !available);
    if (notice) assert.match(notice.children.join(''), /Nexus/);
    assert.equal(writes, 0, 'Dependency state never rewrites saved attributes');
    assert.equal(block.save(), null, 'The block remains dynamic');
    assert.equal(block.attributes.showAmazonDisclosure.default, true);
    assert.equal(block.attributes.amazonDownload.default, false);
    assert.equal(block.attributes.nintendoUrl.default, '');
    assert.ok(registry.has('node-library/item-card'), 'Other blocks stay registered');
  });
}
