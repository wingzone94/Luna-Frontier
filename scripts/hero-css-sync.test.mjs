import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');

test('hero slider CSS in src and assets stays the same file', () => {
	const source = readFileSync(join(root, 'src/styles/_hero-slider.css'));
	const shipped = readFileSync(join(root, 'assets/css/lf-hero-slider.css'));
	assert.deepEqual(
		shipped,
		source,
		'PHP は assets/css/lf-hero-slider.css を読む。src/styles/_hero-slider.css を変えたら同じ内容を assets 側へ写す。'
	);
});
