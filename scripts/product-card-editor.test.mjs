import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../plugins-embedded/node-library/assets/js/block-editor.js', import.meta.url), 'utf8');
function loadEditor(available, { apiFetchImpl = () => Promise.resolve({}), initialStates = [] } = {}) {
  const registered = new Map();
  const components = new Proxy({}, { get: (_, key) => key });
  let stateIndex = 0;
  let refIndex = 0;
  const refs = [];
  const alerts = [];
  const window = {
    alert: (msg) => alerts.push(msg),
    nodeLibraryEditor: { productCardAvailable: available },
    wp: {
      blocks: { registerBlockType: (name, config) => registered.set(name, config) },
      element: {
        createElement: (type, props, ...children) => ({ type, props, children }),
        useState: (value) => {
          const idx = stateIndex++;
          const current = idx in initialStates ? initialStates[idx] : value;
          const setter = (next) => { initialStates[idx] = next; };
          return [current, setter];
        },
        useRef: (value) => {
          const idx = refIndex++;
          if (!(idx in refs)) {
            refs[idx] = { current: value };
          }
          return refs[idx];
        },
      },
      components, data: {}, apiFetch: apiFetchImpl, blockEditor: { InspectorControls: 'InspectorControls' },
    },
  };
  vm.runInNewContext(source, { window, console, URL });
  return { registered, alerts, states: initialStates };
}
function findNodes(node, predicate, out = []) {
  if (!node) return out;
  if (Array.isArray(node)) {
    node.forEach((child) => findNodes(child, predicate, out));
    return out;
  }
  if (typeof node === 'object') {
    if (predicate(node)) out.push(node);
    if (Array.isArray(node.children)) findNodes(node.children, predicate, out);
  }
  return out;
}
for (const available of [true, false]) {
  test(`Product dependency notice and saved attributes: Nexus ${available}`, () => {
    const { registered } = loadEditor(available);
    const block = registered.get('node-library/product-card');
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
    assert.ok(registered.has('node-library/item-card'), 'Other blocks stay registered');
  });
}

test('OGP 503 fallback populates store URL, preserves manual values, and avoids window.alert', async () => {
  const states = ['https://store-jp.nintendo.com/item/software/D70010000130791', false, null];
  const { registered, alerts } = loadEditor(true, {
    apiFetchImpl: () => Promise.reject(new Error('HTTP 503')),
    initialStates: states,
  });
  const block = registered.get('node-library/product-card');
  let saved = { title: '手入力タイトル', price: '', imageUrl: '', asin: '', rakutenUrl: '', nintendoUrl: '' };
  const setAttributes = (patch) => { saved = { ...saved, ...patch }; };

  const tree = block.edit({ attributes: saved, setAttributes });
  const fetchButton = findNodes(tree, (n) => n.type === 'Button' && n.children?.includes('情報を取得'))[0];
  assert.ok(fetchButton, 'Fetch button exists');
  fetchButton.props.onClick();
  await new Promise((r) => setTimeout(r, 0));

  assert.equal(alerts.length, 0, 'window.alert must not be called on OGP failure');
  assert.equal(saved.nintendoUrl, 'https://store-jp.nintendo.com/item/software/D70010000130791');
  assert.equal(saved.title, '手入力タイトル', 'Manual title is preserved on OGP failure');
  assert.equal(states[2]?.status, 'warning');
  assert.match(states[2]?.message || '', /OGPを取得できませんでした。手入力で登録できます/);
});

test('Ocarina of Time Nintendo Store fetch populates title, price, imageUrl, and nintendoUrl', async () => {
  const ocarinaUrl = 'https://store-jp.nintendo.com/item/software/D70010000130791';
  const states = [ocarinaUrl, false, null];
  const { registered, alerts } = loadEditor(true, {
    apiFetchImpl: () => Promise.resolve({
      title: 'ゼルダの伝説 時のオカリナ',
      price: '￥7,980',
      image: 'https://img-eshop.cdn.nintendo.net/i/ac28612fa15a103adba3546e77dc9ffd0286b22f55c48d1220813cdaaa1f8d40.jpg',
      nintendo_url: ocarinaUrl,
      ogp_error: false,
      partial: false,
    }),
    initialStates: states,
  });
  const block = registered.get('node-library/product-card');
  let saved = { title: '', price: '', imageUrl: '', nintendoUrl: '' };
  const setAttributes = (patch) => { saved = { ...saved, ...patch }; };

  const tree = block.edit({ attributes: saved, setAttributes });
  const fetchButton = findNodes(tree, (n) => n.type === 'Button' && n.children?.includes('情報を取得'))[0];
  fetchButton.props.onClick();
  await new Promise((r) => setTimeout(r, 0));

  assert.equal(alerts.length, 0);
  assert.equal(saved.title, 'ゼルダの伝説 時のオカリナ');
  assert.equal(saved.price, '￥7,980');
  assert.match(saved.imageUrl, /img-eshop\.cdn\.nintendo\.net/);
  assert.equal(saved.nintendoUrl, ocarinaUrl);
  assert.equal(states[2], null);
});
