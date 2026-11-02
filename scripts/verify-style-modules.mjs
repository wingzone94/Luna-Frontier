import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import postcss from 'postcss';

const root = process.cwd();
const entries = ['src/styles/style.css', 'src/styles/_header.css', 'src/styles/_article.css', 'src/styles/_article-spotlight.css'];
for (const entry of entries) {
    const ast = postcss.parse(fs.readFileSync(entry, 'utf8'));
    for (const node of ast.nodes) {
        assert.ok(node.type === 'comment' || (node.type === 'atrule' && ['import', 'layer'].includes(node.name) && !node.nodes), entry + ' must remain import-only');
    }
}
const seen = new Set();
function visit(file, chain = []) {
    assert.ok(!chain.includes(file), 'CSS import cycle: ' + [...chain, file].join(' -> '));
    assert.ok(!seen.has(file), 'Duplicate CSS import: ' + path.relative(root, file));
    seen.add(file);
    const source = fs.readFileSync(file, 'utf8');
    const ast = postcss.parse(source);
    const imports = ast.nodes.filter(node => node.type === 'atrule' && node.name === 'import');
    const rules = ast.nodes.filter(node => node.type !== 'comment' && !(node.type === 'atrule' && ['import', 'layer'].includes(node.name)));
    if (rules.length) assert.ok(source.split('\n').length <= 1000, path.relative(root, file) + ' exceeds 1000-line implementation budget');
    for (const node of imports) {
        const match = node.params.match(/^['"]([^'"]+)['"]/);
        assert.ok(match, 'Only reviewed relative CSS imports are allowed');
        visit(path.resolve(path.dirname(file), match[1]), [...chain, file]);
    }
}
visit(path.resolve('src/styles/style.css'));
console.log('PASS: import-only entries, existing imports, no duplicate/cyclic imports, implementation partials <=1000 lines (' + seen.size + ' files)');

function orderedRules(source) {
    const result = [];
    const walk = (ast, layer = '') => {
        for (const node of ast.nodes) {
            if (node.type === 'comment') continue;
            if (node.type === 'atrule' && node.name === 'layer') {
                if (node.nodes) walk(node, layer ? layer + '.' + node.params : node.params);
                else if (node.params.includes(',')) result.push(['layer-order', node.params]);
            } else result.push([layer, node.toString()]);
        }
    };
    walk(postcss.parse(source));
    return result;
}
if (process.argv[2]) {
    const before = fs.readFileSync(process.argv[2], 'utf8');
    const after = fs.readFileSync(process.argv[3] || 'dist/css/style.css', 'utf8');
    assert.deepEqual(orderedRules(before), orderedRules(after), 'Ordered CSS rules, declarations, conditions and layers must remain identical');
    console.log('PASS: ordered bundle rules/layers identical; raw byte delta ' + (Buffer.byteLength(after) - Buffer.byteLength(before)));
}
