/**
 * Material Symbolsの静的参照・対応表・実ページを登録配列と照合する。
 * highのみ未登録エラー。汎用の書き方と推測対応表は参考情報。
 * フィルタ追加候補にある名前は未登録と断定せず、実PHPでの有効登録確認を求める。
 * exit 0: 高信頼で見つかった未登録なし（動的指定の完全性保証ではない）
 * exit 1: 本体配列にも解決したフィルタ候補にもない高信頼参照
 * exit 2: フィルタ追加候補の実行時確認が必要
 * --static-only: ブラウザーを起動せず静的照合のみ実行する。
 * 静的参照なしを削除可能とは断定しない。tests/fixtureは製品参照から除外する。
 */

import { readdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';

import {
	declaredIconsFromSource,
	partitionMissing,
	extractUsageFromEntries,
} from './icon-subset-extract.mjs';

const ROOT = process.cwd();
const BASE = (process.env.NODE_VISUAL_BASE_URL ?? 'http://cybernode.local').replace(/\/$/, '');
const AS_JSON = process.argv.includes('--json');

const SOURCE_EXTENSIONS = new Set(['.php', '.js', '.css', '.html', '.mjs']);
// .tmp* は配布ZIPを組み立てるときの作業ディレクトリ（.gitignore 済み）。
// 過去の版のテンプレートが残るので、今のアイコン一覧の答え合わせには使えない。
const SKIP_DIRS = new Set(['.git', 'node_modules', 'vendor', 'scratch', 'test-results', '.cursor', '.claude']);
const SKIP_DIR_PREFIXES = ['.tmp'];
/**
 * assets はビルド済みの出力なので、テーマ直下のものだけ飛ばす。
 * plugins-embedded/<plugin>/assets/js/editor.js はビルド前のソースで、
 * `icon: 'warning'` のようにアイコン名が literal で入っている（飛ばすと取りこぼす）。
 */
const SKIP_TOP_LEVEL_DIRS = new Set(['assets']);

/**
 * 「使用箇所」として数えてはいけないファイル、および
 * テスト・fixture の線引き（`isNonProductPath`）は icon-subset-extract.mjs にある。
 * ここはファイルを読んで渡すだけにして、判定は純粋な関数側へ寄せる。
 * これでテストから「製品は拾う／テスト・fixture は拾わない」を直接確認できる。
 */

/** 静的抽出で拾えないと分かっているもの。理由を添えて明示的に許す。 */
const KNOWN_DYNAMIC = new Map([
	['dark_mode', 'src/scripts/color-mode.js の三項演算子で切り替わる'],
	['light_mode', 'src/scripts/color-mode.js の三項演算子で切り替わる'],
]);

/** この文字列が無いファイルは走査しない（3方向のどれにも当たらないファイルを飛ばす）。 */
const SCAN_HINT = /material-symbols|data-(?:node-section-)?icon|\bicon\b|content\s*:|setIcon\s*\(|icon_names/i;

const CRAWL_PATHS = [
	'/',
	'/all-articles/',
	'/?s=Claude',
	'/?s=zzzz-no-such-post',
	'/this-path-should-404/',
];

/*
 * 固定パスだけでは、記事ページ・カテゴリ・著者アーカイブに出るアイコンを
 * 一度も描画しない。実際 person（著者アーカイブの見出し）を取りこぼし、
 * 本番と同じ「リガチャの文字がそのまま出る」状態を検出できなかった。
 * トップから 1 件ずつ辿って、テンプレートの種類を一通り踏む。
 */
const DISCOVER_SELECTORS = [
	['単一記事', '.c-card__title a, .m3-card__title a'],
	['カテゴリ', '.m3-label--category'],
	['著者アーカイブ', '.c-card__writer-link, a[rel="author"]'],
];

async function discoverPaths(browser) {
	const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
	const paths = [];
	try {
		await page.goto(BASE + '/', { waitUntil: 'networkidle', timeout: 20000 });
		for (const [, selector] of DISCOVER_SELECTORS) {
			const href = await page.evaluate((sel) => {
				const el = document.querySelector(sel);
				return el ? el.href : null;
			}, selector);
			if (!href || !href.startsWith(BASE)) continue;
			const path = href.slice(BASE.length) || '/';
			if (!paths.includes(path)) paths.push(path);
		}
	} catch {
		// 到達できないだけなら固定パスで続ける。
	}
	await page.close();
	return paths;
}

async function collectSourceFiles(dir) {
	const out = [];
	for (const entry of await readdir(dir, { withFileTypes: true })) {
		if (SKIP_DIRS.has(entry.name)) continue;
		if (SKIP_DIR_PREFIXES.some((prefix) => entry.name.startsWith(prefix))) continue;
		if (dir === ROOT && SKIP_TOP_LEVEL_DIRS.has(entry.name)) continue;
		const full = join(dir, entry.name);
		if (entry.isDirectory()) {
			out.push(...(await collectSourceFiles(full)));
			continue;
		}
		const dot = entry.name.lastIndexOf('.');
		if (dot !== -1 && SOURCE_EXTENSIONS.has(entry.name.slice(dot))) out.push(full);
	}
	return out;
}

/** inc/icon-font.php の配列を読む。PHP を動かさずに済ませる。 */
async function readDeclaredIcons() {
	return declaredIconsFromSource(await readFile(join(ROOT, 'inc/icon-font.php'), 'utf8'));
}

async function extractFromSource(declared) {
	// ファイル探索と読み込みだけを行い、集計（製品かどうかの線引きを含む）は
	// icon-subset-extract.mjs の純粋な関数へ渡す。
	const entries = [];
	for (const file of await collectSourceFiles(ROOT)) {
		const rel = file.replace(`${ROOT}/`, '');
		const text = await readFile(file, 'utf8');
		if (!SCAN_HINT.test(text)) continue;
		entries.push({ path: rel, text });
	}

	return extractUsageFromEntries(entries, declared);
}

async function extractFromPages() {
	let chromium;
	try {
		({ chromium } = await import('playwright'));
	} catch {
		return null;
	}

	const browser = await chromium.launch();
	const found = new Map();
	try {
		const paths = [...CRAWL_PATHS, ...(await discoverPaths(browser))];
		for (const width of [390, 1440]) {
			for (const path of paths) {
				const page = await browser.newPage({ viewport: { width, height: 900 }, isMobile: width < 700, hasTouch: width < 700 });
				try {
					await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 20000 });
					// 折りたたまれた UI の中のアイコンも拾う。
					await page.evaluate(async () => {
						document.querySelectorAll('details').forEach((d) => { d.open = true; });
						document.querySelectorAll('[hidden]').forEach((e) => { e.hidden = false; });
						document.getElementById('search-toggle')?.click();
						await new Promise((resolve) => setTimeout(resolve, 600));
					});
					const names = await page.evaluate(() =>
						[...document.querySelectorAll('.material-symbols-outlined')]
							.map((el) => el.textContent.trim())
							.filter(Boolean)
					);
					names.forEach((n) => { if (!found.has(n)) found.set(n, `${width}px ${path}`); });
				} catch {
					// 個別ページの失敗は握りつぶす。到達できないだけなら静的側で担保する。
				}
				await page.close();
			}
		}
	} finally {
		await browser.close();
	}
	return found;
}

const declared = await readDeclaredIcons();
const { found: fromSource, medium: fromMedium, maps: fromMaps, filterAdded, dynamic } =
	await extractFromSource(declared);

const problems = [];
const { missing: missingSource, needsRuntime: filterRuntimeChecks } = partitionMissing(fromSource, declared, filterAdded);
missingSource.forEach(([name, hit]) => problems.push(`${name}  (${hit.file}:${hit.line}  ${hit.id})`));

const fromPages = process.argv.includes('--static-only') ? null : await extractFromPages();
let missingPages = [];
if (fromPages !== null && fromPages.size > 0) {
	const pageResult = partitionMissing(fromPages, declared, filterAdded);
	missingPages = pageResult.missing;
	filterRuntimeChecks.push(...pageResult.needsRuntime);
	missingPages.forEach(([name, where]) => problems.push(`${name}  (描画: ${where})`));
}

// 使っていない登録は害が無い（数KBの差）が、増え続けると棚卸しできなくなる。
// 静的に読めない指定が残っている以上、「削除してよい」とは言えない。
const seen = new Set([
	...fromSource.keys(),
	...fromMedium.keys(),
	...fromMaps.keys(),
	...filterAdded.keys(),
	...(fromPages ? fromPages.keys() : []),
]);
const unused = [...declared].filter((n) => !seen.has(n) && !KNOWN_DYNAMIC.has(n));
const mediumUnknown = [...fromMedium].filter(([name]) => !declared.has(name));
const mapUnknown = [...fromMaps.keys()].filter((name) => !declared.has(name));

if (AS_JSON) {
	console.log(JSON.stringify({
		declared: declared.size,
		staticFound: fromSource.size,
		staticMissing: missingSource.map(([name, hit]) => ({ name, file: hit.file, line: hit.line, kind: hit.id })),
		mediumFound: [...fromMedium.keys()],
		mediumMissingFromDeclared: mediumUnknown.map(([name, hit]) => ({ name, file: hit.file, line: hit.line, kind: hit.id })),
		mapFound: [...fromMaps.keys()],
		mapMissingFromDeclared: mapUnknown,
		filterAdded: [...filterAdded.keys()],
		filterRegistrationNeedsRuntime: [...new Set(filterRuntimeChecks.map(([name]) => name))],
		pagesFound: fromPages === null ? null : fromPages.size,
		pagesMissing: missingPages.map(([name, where]) => ({ name, where })),
		dynamicSources: dynamic,
		unused: unused,
	}, null, 2));
	process.exit(problems.length ? 1 : filterRuntimeChecks.length ? 2 : 0);
}

console.log(`inc/icon-font.php の登録数: ${declared.size}`);
console.log(`静的抽出（高信頼）: ${fromSource.size}種 / 未登録 ${missingSource.length}種`);
missingSource.forEach(([name, hit]) => console.log(`  - ${name}  (${hit.file}:${hit.line}  ${hit.id})`));
console.log(`静的抽出（中信頼）: ${fromMedium.size}種 / 登録に無い ${mediumUnknown.length}種`);
mediumUnknown.forEach(([name, hit]) => console.log(`  - ${name}  (${hit.file}:${hit.line}  ${hit.id})`));
if (fromMaps.size) {
	console.log(`対応表から: ${fromMaps.size}種（参考。未登録の判定には使わない）`);
	console.log(`  ${[...fromMaps.keys()].join(', ')}`);
}
if (filterAdded.size) {
	console.log(`フィルタの追加候補（実行時確認が必要）: ${[...filterAdded.keys()].join(', ')}`);
}

if (fromPages === null) {
	console.log('実ページ: playwright が無いので省略');
} else if (fromPages.size === 0) {
	console.log(`実ページ: ${BASE} に到達できず省略`);
} else {
	console.log(`実ページ: ${fromPages.size}種 / 未登録 ${missingPages.length}種`);
	missingPages.forEach(([name, where]) => console.log(`  - ${name}  (描画: ${where})`));
}

if (unused.length) {
	console.log(`\nINFO  静的には参照が見つからない登録 ${unused.length}種: ${unused.join(', ')}`);
	console.log('      これは「削除してよい」の意味ではない。下の「静的に読めない指定」がある限り、');
	console.log('      見えていないだけの可能性が残る。削除前に実ページの描画（上の実ページ欄）を確認すること。');
	if (KNOWN_DYNAMIC.size) {
		console.log(`      既知の動的指定として除外: ${[...KNOWN_DYNAMIC.keys()].join(', ')}`);
	}
}

if (mediumUnknown.length) {
	console.log(`\nINFO  中信頼の書き方にあるが登録に無い名前: ${mediumUnknown.map(([n]) => n).join(', ')}`);
	console.log('      JS の icon: は WordPress のブロックアイコン（dashicons）でも同じ書き方になるため、');
	console.log('      未登録の不具合とは断定しない。実ページの描画（上の実ページ欄）で確認すること。');
}

if (mapUnknown.length) {
	console.log(`\nINFO  対応表にあるが登録（配列）に無い名前 ${mapUnknown.length}種: ${mapUnknown.join(', ')}`);
	console.log('      未登録の不具合とは断定しない（node_icon_font_names フィルタで配信に足されている場合がある）。');
	console.log('      ただし対応表の値として実際に描画されるなら、グリフが無いので文字が出る。表示を目視で確認すること。');
}

if (dynamic.length) {
	console.log(`\nINFO  静的に読めない指定 ${dynamic.length}箇所:`);
	for (const d of dynamic) console.log(`  - ${d.file}:${d.line}  ${d.note}`);
}

if (problems.length) {
	console.error(`\n未登録のアイコン ${problems.length} 種。inc/icon-font.php に追加すること。`);
	console.error('登録しないとグリフが無く、リガチャの文字がそのまま表示される。');
	problems.forEach((p) => console.error(`  - ${p}`));
	process.exit(1);
}

if (filterRuntimeChecks.length) {
	console.log('\n要確認: フィルタ追加候補は実PHPの有効登録集合との照合が必要: ' + [...new Set(filterRuntimeChecks.map(([name]) => name))].join(', '));
	process.exit(2);
}
console.log('\n高信頼の参照に未登録なし（動的指定・中信頼は別途確認）');
