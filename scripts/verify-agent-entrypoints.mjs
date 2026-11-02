import fs from 'node:fs';
import assert from 'node:assert/strict';
assert.ok(fs.existsSync('AGENT.MD'), 'Canonical instructions must exist');
for (const name of ['AGENTS.md', 'CLAUDE.md', 'GEMINI.md']) {
    const source = fs.readFileSync(name, 'utf8');
    assert.ok(source.includes('[AGENT.MD](./AGENT.MD)'), name + ' must point to the canonical instructions');
    assert.ok(source.trim().split('\n').length <= 3, name + ' must remain a stub, not an independent instruction document');
    console.log('PASS: canonical entry', name);
}
for (const name of ['AGEND.MD', 'AI.md', 'AI-AGENTS.md', 'CHATGPT.md']) {
    assert.ok(!fs.existsSync(name), 'Do not recreate redundant agent entry: ' + name);
}
console.log('PASS: one instruction authority, no obsolete entry copies');
