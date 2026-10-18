/**
 * verify-icon-subset の再発防止テスト。
 *
 * このツールは以前、次の6種を「未使用」と誤判定した（静的抽出の穴）。
 *
 *   arrow_downward, format_quote  … CSS の `content: 'name'`
 *   priority_high, warning, edit_note … JS の `icon: 'name'`
 *   label … node_icon_font_names フィルタが登録に足している名前
 *
 * ここではその6種が実際に参照として見つかること、そのときに使った抽出の条件
 * （終端記号・アイコンフォントの範囲・対応表の割合）が壊れていないことを固定する。
 * あわせて、MS-Trim の棚卸しで「静的に参照が見つからない」と記録した12種が今も検出できること
 * （＝誤判定を直したせいで検出能力を失っていないこと）も確認する。
 *
 * 実行: bun run verify:icon-subset:test
 *       node --test scripts/verify-icon-subset.test.mjs
 */

import { strict as assert } from 'node:assert';
import { readFile, readdir } from 'node:fs/promises';
import { join, relative } from 'node:path';
import { test } from 'node:test';

import {
	declaredIconsFromSource,
	extractCssContent,
	extractFontRegistrationAdditions,
	extractIconMaps,
	extractLiterals,
	extractUsageFromEntries,
	findDynamicSources,
	isNonProductPath,
	partitionMissing,
} from './icon-subset-extract.mjs';

const ROOT = process.cwd();

/** MS-Trim の棚卸し（outputs/phase4/MATERIAL-SYMBOLS-AUDIT.md）で「参照ゼロ」と記録した12種。 */
const KNOWN_UNUSED = [
	'analytics',
	'chat_bubble',
	'clear_all',
	'edit',
	'format_size',
	'key',
	'more_horiz',
	'perm_media',
	'search_off',
	'sort_by_alpha',
	'unfold_less',
	'view_list',
];

/** 以前「未使用」と誤判定していた6種。 */
const PREVIOUS_FALSE_POSITIVES = [
	'arrow_downward',
	'format_quote',
	'priority_high',
	'warning',
	'edit_note',
	'label',
];

const SKIP_DIRS = new Set(['.git', 'node_modules', 'vendor', 'scratch', '.cursor', '.claude']);

async function walk(dir) {
	const out = [];
	for (const entry of await readdir(dir, { withFileTypes: true })) {
		if (SKIP_DIRS.has(entry.name) || entry.name.startsWith('.tmp')) continue;
		// ビルド済みの assets はテーマ直下のものだけ飛ばす（CLI と同じ扱い）。
		if (dir === ROOT && entry.name === 'assets') continue;
		const full = join(dir, entry.name);
		if (entry.isDirectory()) out.push(...(await walk(full)));
		else if (/\.(php|js|css|html|mjs)$/.test(entry.name)) out.push(full);
	}
	return out;
}

async function declaredIcons() {
	return declaredIconsFromSource(await readFile(join(ROOT, 'inc/icon-font.php'), 'utf8'));
}

/**
 * CLI と同じ材料を揃えて、参照として見つかる名前の集合を作る。
 * 集計そのもの（ツール／登録ファイル／テスト・fixture の線引き）は
 * `extractUsageFromEntries` に任せる。CLI と同じ関数を通すので、条件のズレが起きない。
 */
async function scanNames() {
	const known = await declaredIcons();
	const entries = [];
	for (const file of await walk(ROOT)) {
		const rel = relative(ROOT, file);
		const text = await readFile(file, 'utf8');
		if (!/material-symbols|data-(?:node-section-)?icon|\bicon\b|content\s*:|setIcon\s*\(|icon_names/i.test(text)) continue;
		entries.push({ path: rel, text });
	}
	const { found, medium, maps, filterAdded, dynamic } = extractUsageFromEntries(entries, known);
	const seen = new Set([...found.keys(), ...medium.keys(), ...maps.keys(), ...filterAdded.keys()]);
	return { seen, dynamic };
}

/* --- 個別の条件（fixture で壊れ方を固定する） ------------------------------ */

test('CSS の content: は、アイコンフォントを使う規則の中だけ拾う', () => {
	const iconRule = `.m3-article__body blockquote::before {\n  content: 'format_quote';\n  font-family: 'Material Symbols Outlined';\n}`;
	assert.deepEqual(extractCssContent(iconRule).map((h) => h.name), ['format_quote']);

	// リガチャを使わない普通の content は拾わない（'none' をアイコン名と誤認しない）。
	assert.deepEqual(extractCssContent(`.x::before { content: 'none'; }`), []);

	// 空文字は拾わない。
	assert.deepEqual(extractCssContent(`.x::before { content: ''; font-family: 'Material Symbols Outlined'; }`), []);
});

test('CSS の content: は、基準の規則が離れていても拾う（並べ替え表）', () => {
	// 実ファイルでは font-family が 526 文字前にある。窓を狭めると取りこぼす。
	const padding = '/* '.padEnd(600, 'x') + ' */\n';
	const css = `.m3-table--sortable th::after { font-family: 'Material Symbols Outlined'; }\n${padding}.m3-table--sortable th.is-desc::after { content: 'arrow_downward'; }`;
	assert.deepEqual(extractCssContent(css).map((h) => h.name), ['arrow_downward']);
});

test("JS の icon: は拾うが、値の直後が比較演算子なら拾わない", () => {
	const js = `var NOTICE_TYPES = { warning: { icon: 'warning' }, alert: { icon: 'priority_high' } };`;
	assert.deepEqual(extractLiterals(js).map((h) => h.name).sort(), ['priority_high', 'warning']);

	// 実ファイルにあった誤検出: 'app' は三項演算子の比較対象で、アイコン名ではない。
	const php = `'icon' => 'app' === $type ? 'smartphone' : 'sports_esports',`;
	const names = extractLiterals(php).map((h) => h.name);
	assert.ok(!names.includes('app'), '比較用のリテラルをアイコン名として拾っている');
});

test('JS の icon: は中信頼（WordPress のブロックアイコンと衝突するため）', () => {
	const hits = extractLiterals(`registerBlockType('x', { icon: 'cart' });`);
	assert.equal(hits.length, 1);
	assert.equal(hits[0].confidence, 'medium', 'ブロックアイコンの名前を高信頼にしてはいけない');
});

test('アイコン対応表は、値の6割以上が登録済みのときだけ採用する', () => {
	const known = new Set(['category', 'newspaper', 'smartphone']);
	// 実ファイルと同じく、関数名に icon がある範囲だけを対応表の候補にする。
	const map = `function luna_frontier_topic_icon(): string {\n\t$map = array( 'ニュース' => 'newspaper', 'スマホ' => 'smartphone' );\n}`;
	assert.deepEqual(extractIconMaps(map, { known }).map((h) => h.name).sort(), ['newspaper', 'smartphone']);

	// 実ファイルにあった誤検出: WordPress の引数配列（'category' がたまたまアイコン名と同じ）。
	const args = `$terms = get_terms( array( 'taxonomy' => 'category', 'orderby' => 'count' ) );`;
	assert.deepEqual(extractIconMaps(args, { known }), []);
});

test('node_icon_font_names フィルタが足す名前は、割合に関係なく全部拾う', () => {
	const php = `function luna_frontier_home_icon_names( array $names ): array {\n\treturn array_merge( $names, array( 'star', 'label' ) );\n}\nadd_filter( 'node_icon_font_names', 'luna_frontier_home_icon_names' );`;
	assert.deepEqual(extractFontRegistrationAdditions(php).map((h) => h.name).sort(), ['label', 'star']);
});

test('実行時にしか決まらない指定を拾う', () => {
	const php = `<span class="material-symbols-outlined"><?php echo esc_html( $icon ); ?></span>`;
	assert.ok(findDynamicSources(php).some((d) => d.id === 'php_expression_icon'));

	const filter = `add_filter( 'node_icon_font_names', 'luna_frontier_home_icon_names' );`;
	assert.ok(findDynamicSources(filter).some((d) => d.id === 'icon_font_names_filter'));
});

/* --- 実ファイルでの再発防止 ------------------------------------------------- */

test('再発防止: 以前「未使用」と誤判定した6種が、実際に参照として見つかる', async () => {
	const { seen } = await scanNames();
	for (const name of PREVIOUS_FALSE_POSITIVES) {
		assert.ok(seen.has(name), `${name} を参照として拾えていない（静的抽出の穴が戻っている）`);
	}
});

test('検出能力: 静的に参照が見つからない12種は、今も参照が見つからないまま', async () => {
	const { seen } = await scanNames();
	for (const name of KNOWN_UNUSED) {
		assert.ok(!seen.has(name), `${name} を参照として拾ってしまった（未使用の検出能力が落ちている）`);
	}
});

test('実ファイル: CSS の content を使う4ファイル分を拾える', async () => {
	const expectations = [
		['src/styles/_article.css', ['format_quote', 'unfold_more', 'arrow_upward', 'arrow_downward']],
		['src/luna/styles/_search.css', ['expand_more']],
		['src/luna/styles/_article-header.css', ['expand_more']],
	];
	for (const [file, names] of expectations) {
		const found = extractCssContent(await readFile(join(ROOT, file), 'utf8')).map((h) => h.name);
		for (const name of names) assert.ok(found.includes(name), `${file} の ${name} を拾えていない`);
	}
});

test('実ファイル: luminous-blocks の editor.js が save で出力するアイコンを拾える', async () => {
	const file = 'plugins-embedded/luminous-blocks/assets/js/editor.js';
	const found = extractLiterals(await readFile(join(ROOT, file), 'utf8')).map((h) => h.name);
	for (const name of ['campaign', 'warning', 'priority_high', 'edit_note']) {
		assert.ok(found.includes(name), `${file} の ${name} を拾えていない`);
	}
});

test('実ファイル: topic-nav.php の対応表から音楽・動画・フードのアイコンを拾える', async () => {
	const known = await declaredIcons();
	const file = 'template-parts/luna/topic-nav.php';
	const found = extractIconMaps(await readFile(join(ROOT, file), 'utf8'), { known }).map((h) => h.name);
	for (const name of ['music_note', 'movie', 'restaurant', 'edit_note', 'auto_awesome']) {
		assert.ok(found.includes(name), `${file} の対応表から ${name} を拾えていない`);
	}
});

test('実ファイル: ツール自身と登録ファイルを「使用箇所」として数えていない', async () => {
	// 説明用に書いた `name` を拾うと、未登録のアイコンとして誤報告する。
	const { seen } = await scanNames();
	assert.ok(!seen.has('name'), '道具のファイルに書いた例示用の名前を拾っている');
});

/* --- テスト・fixture を製品の使用箇所に数えない ----------------------------- */

/*
 * CLI はリポジトリ全体を走査する。テストや fixture にはアイコン名やフェイクの span が
 * 書かれうるので、「テストには使用箇所らしい文字列を書かない」という約束に依存すると、
 * 約束が破れた瞬間に (1) 登録済みの名前がテストに出ただけで「使用中」に化けて未使用の
 * 検出能力が落ち、(2) 架空の名前が未登録アイコンとして exit 1 を起こす。
 * ここでは線引きがパスで行われ、製品側の参照は消えないことを固定する。
 */

test('isNonProductPath はテスト/fixture だけを指し、製品は除外しない', () => {
	assert.ok(isNonProductPath('tests/node-icon-font-test.php'));
	assert.ok(isNonProductPath('test/probe.php'));
	assert.ok(isNonProductPath('scripts/topic-nav-icons.test.mjs'));
	assert.ok(isNonProductPath('scripts/verify-icon-subset.test.mjs'));
	assert.ok(isNonProductPath('plugins-embedded/x/fixtures/sample.php'));

	assert.ok(!isNonProductPath('inc/icon-font.php'));
	assert.ok(!isNonProductPath('template-parts/luna/topic-nav.php'));
	assert.ok(!isNonProductPath('scripts/verify-icon-subset.mjs'));
	assert.ok(!isNonProductPath('plugins-embedded/luminous-blocks/assets/js/editor.js'));
});

test('テスト/フィクスチャのアイコン名は製品の使用判定を変えない', () => {
	const declared = new Set(['alpha', 'beta']);
	const entries = [
		// 製品ファイル: 未登録の名前 → 本来検出すべき参照
		{ path: 'template-parts/prod.php', text: '<span class="material-symbols-outlined">gamma_new</span>' },
		// テスト: 登録済みだが静的に未使用の名前 → 数えてはいけない
		{ path: 'tests/probe.test.php', text: '<span class="material-symbols-outlined">alpha</span>' },
		// テスト: 架空の名前 → 未登録として報告してはいけない
		{ path: 'scripts/probe.test.mjs', text: "const x = '<span class=\"material-symbols-outlined\">ghost_zzz</span>';" },
	];
	const { found } = extractUsageFromEntries(entries, declared);

	assert.ok(found.has('gamma_new'), '製品ファイルの本来検出すべき参照を落としている');
	assert.ok(!found.has('alpha'), 'テストの名前で「使用中」に変わり、未使用の検出能力が落ちている');
	assert.ok(!found.has('ghost_zzz'), 'テストの架空の名前を未登録アイコンとして拾っている');
});

test('製品ファイルは、テスト除外の影響を受けずに参照を拾う', () => {
	const declared = new Set(['alpha', 'newspaper']);
	const entries = [
		{ path: 'template-parts/luna/topic-nav.php', text: '<span class="material-symbols-outlined">newspaper</span>' },
		{ path: 'src/styles/_article.css', text: ".x::before { content: 'format_quote'; font-family: 'Material Symbols Outlined'; }" },
	];
	const { found } = extractUsageFromEntries(entries, declared);
	assert.ok(found.has('newspaper'), '製品PHPの span を拾えていない');
	assert.ok(found.has('format_quote'), '製品CSSの content を拾えていない');
});

test('実行時ノート（dynamic）もテスト/フィクスチャからは数えない', () => {
	const entries = [
		{ path: 'tests/probe.test.php', text: 'luna_frontier_topic_icon( $label )' },
		{ path: 'inc/luna/bootstrap.php', text: "add_filter( 'node_icon_font_names', 'luna_frontier_home_icon_names' );" },
	];
	const { dynamic } = extractUsageFromEntries(entries, new Set());
	assert.equal(dynamic.length, 1, 'テスト側の実行時ノートまで数えている');
	assert.equal(dynamic[0].file, 'inc/luna/bootstrap.php');
});


test('汎用textContent/属性/PHPキーをMaterial Symbolsと断定しない', () => {
 const hits = extractLiterals(`status.textContent = 'loading'; <widget icon="custom_badge" /> 'icon' => 'cart',`);
 assert.ok(hits.length >= 3);
 assert.ok(hits.every(h => h.confidence === 'medium'));
});

test('別CSSルールへの近接を高信頼にしない', () => {
 const css = `.symbol { font-family: 'Material Symbols Outlined'; } .status::before { content: 'loading'; font-family: sans-serif; }`;
 assert.deepEqual(extractCssContent(css), []);
 const related = `.symbol { font-family: 'Material Symbols Outlined'; } .symbol.is-active::before { content: 'close'; }`;
 assert.equal(extractCssContent(related)[0].confidence, 'medium');
});

test('フィルタの無関係な配列・削除・条件分岐を登録候補にしない', () => {
 for (const body of ["$unused = array('ghost'); return $names;", "return array_diff($names, array('close'));", "if ($flag) { return array_merge($names, array('ghost')); } return $names;"]) {
  const php = `function extra_icons(array $names): array { ${body} } add_filter('node_icon_font_names', 'extra_icons');`;
  assert.deepEqual(extractFontRegistrationAdditions(php), []);
 }
});

test('フィルタ追加候補は未登録エラーと登録確認済みの両方から分離する', () => {
 const found = new Map([['audit_glyph', {}], ['real_missing', {}], ['close', {}]]);
 const result = partitionMissing(found, new Set(['close']), new Map([['audit_glyph', {}]]));
 assert.deepEqual(result.missing.map(([name]) => name), ['real_missing']);
 assert.deepEqual(result.needsRuntime.map(([name]) => name), ['audit_glyph']);
});
