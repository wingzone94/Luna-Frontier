/**
 * `topic-nav.php` の標準アイコン対応表が、登録一覧（`inc/icon-font.php`）に
 * すべて含まれていることを直接確認する回帰テスト。
 *
 * これは「一般的な PHP の対応表を推測で見つける」検証ではない。対象のファイル・
 * 関数・変数を名指しして読み、対応表の値を登録一覧と突き合わせるだけの、
 * 意図的に狭い検査である。推測で対応表を探すのは `scripts/verify-icon-subset.mjs`
 * （別の修正）の仕事で、こちらは「その推測に頼らず、この1つの表だけは必ず守る」
 * ための最終防衛線。両者は独立していて、推測側の条件を変えてもこちらは影響を受けない。
 *
 * なぜ必要か: 対応表のアイコンが登録一覧に無いとグリフが配信されない。
 * `.material-symbols-outlined` は `inline-size: 1em; overflow: hidden` なので、
 * リガチャが引けず**文字がそのまま頭だけ切れて出る**。実際に `music_note` /
 * `movie` / `restaurant` で起きた（2026-10-18 に登録を追加して修正）。
 *
 * 実行:
 *   node --test scripts/topic-nav-icons.test.mjs
 *
 * 別のツリーを検査する（採用Dに戻して失敗することを確かめる等）:
 *   TOPIC_NAV_TEST_ROOT=work/Luna-Frontier node --test scripts/topic-nav-icons.test.mjs
 *
 * テストパスは推測検証器の製品参照集計から除外される。
 * 本テストは推測検証器の実装をimportしない。
 */

import { strict as assert } from 'node:assert';
import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { test } from 'node:test';

/** 検査対象のツリー。既定はこのリポジトリ（テストを実行したディレクトリ）。 */
const ROOT = process.env.TOPIC_NAV_TEST_ROOT ?? process.cwd();

const TOPIC_NAV = 'template-parts/luna/topic-nav.php';
const ICON_FONT = 'inc/icon-font.php';

/**
 * 対応表を返す関数の名前。ここを名指しするのが要点（推測しない）。
 * 括弧を付けずに書いてあるのは、リポジトリ全体を走査する検証器に
 * 「実行時に決まる使用箇所」として拾われないようにするため。
 */
const TOPIC_ICON_FUNCTION = 'luna_frontier_topic_icon';

/** 読み取りが壊れていないことを確かめるための下限。実物は11件。全項目の読み取り完了も別に確認する。 */
const MIN_ENTRIES = 11;

/** この固定表用。PHP全構文の評価器ではなく、未対応構文は失敗させる。 */
function withoutComments(text) {
	return text.replace(/'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|\/\*[\s\S]*?\*\/|\/\/[^\n]*|\#[^\n]*/g,
		(token) => /^(?:\/\*|\/\/|#)/.test(token) ? token.replace(/[^\n]/g, ' ') : token);
}

function literalEntries(body, keyed) {
	const entries = [];
	// 補間・escape・式は評価しない。表記変更時は無視せず検査失敗とする。
	const token = keyed
		? /\s*(['"])([^'"\\$]+)\1\s*=>\s*(['"])([a-z0-9_]+)\3\s*(,|$)/y
		: /\s*(['"])([a-z0-9_]+)\1\s*(,|$)/y;
	let at = 0;
	while (body.slice(at).trim()) {
		token.lastIndex = at;
		const m = token.exec(body);
		assert.ok(m, `配列を最後まで読めない: ${body.slice(at, at + 80)}`);
		entries.push(keyed ? [m[2], m[4]] : m[2]);
		at = token.lastIndex;
	}
	assert.ok(entries.length, '空の配列は許容しない');
	return entries;
}

/** 引用符の中を数えずに、`from` の位置の `open` に対応する閉じ位置を返す。 */
function matchClose(text, from, open, close) {
	let depth = 1;
	let quote = null;
	let i = from;
	while (i < text.length) {
		const c = text[i];
		if (quote !== null) {
			if (c === '\\') {
				i += 2;
				continue;
			}
			if (c === quote) quote = null;
			i++;
			continue;
		}
		if (c === "'" || c === '"') {
			quote = c;
			i++;
			continue;
		}
		if (c === open) depth++;
		else if (c === close) {
			depth--;
			if (depth === 0) return i;
		}
		i++;
	}
	return -1;
}

/** 名指しした関数の本体を返す（関数名で探して、波括弧の対応を数えるだけ）。 */
function functionBody(text, name) {
	const re = new RegExp(`function\\s+${name}\\s*\\([^)]*\\)[^{]*\\{`, 'g');
	const m = re.exec(text);
	assert.ok(m, `${TOPIC_NAV} に関数 ${name} が見つからない（構造が変わった？）`);
	const start = m.index + m[0].length;
	const end = matchClose(text, start, '{', '}');
	assert.ok(end !== -1, `${name} の本体の閉じ括弧が見つからない`);
	return text.slice(start, end);
}

/**
 * `$map = array( 'キー' => 'アイコン名', ... );` を取り出す。
 * 「アイコンらしい配列」を探すのではなく、`$map` という変数名から辿る。
 */
function topicIconMap(text) {
	const body = functionBody(withoutComments(text), TOPIC_ICON_FUNCTION);

	const assignment = /\$map\s*=\s*array\s*\(/g.exec(body);
	assert.ok(assignment, '$map の配列代入が見つからない');
	const from = assignment.index + assignment[0].length;
	const end = matchClose(body, from, '(', ')');
	assert.ok(end !== -1, '$map の閉じ括弧が見つからない');
	const entries = literalEntries(body.slice(from, end), true);
	const map = new Map(entries);
	assert.equal(map.size, entries.length, '対応表に重複キーがある');
	return map;
}

/** 登録一覧（`inc/icon-font.php` の `$names` 配列）を返す。 */
function registeredIcons(text) {
	text = withoutComments(text);
	const marker = '$names = array(';
	const at = text.indexOf(marker);
	assert.ok(at !== -1, `${ICON_FONT} に ${marker} が見つからない`);

	const from = at + marker.length;
	const end = matchClose(text, from, '(', ')');
	assert.ok(end !== -1, `$names の array(...) の閉じ括弧が見つからない`);

	return new Set(literalEntries(text.slice(from, end), false));
}

test('対応表を読み取れている（読み取り失敗で素通りしないことの確認）', async () => {
	const map = topicIconMap(await readFile(join(ROOT, TOPIC_NAV), 'utf8'));

	assert.ok(
		map.size >= MIN_ENTRIES,
		`対応表の項目が ${map.size} 件しか読めていない（${MIN_ENTRIES} 件以上あるはず）。読み取りが壊れている。`
	);
	// 名前を1つだけ固定して、$map 以外の配列を読んでいないことを確かめる。
	assert.equal(map.get('ニュース'), 'newspaper');
	// 対応表の外にある既定値（$icon）を、対応表の値として読んでいないこと。
	assert.ok(![...map.values()].includes('label'), '既定値を対応表の値として読んでいる');
});

test('対応表の全アイコンが登録一覧に含まれる', async () => {
	const map = topicIconMap(await readFile(join(ROOT, TOPIC_NAV), 'utf8'));
	const registered = registeredIcons(await readFile(join(ROOT, ICON_FONT), 'utf8'));

	const missing = [...map.values()].filter((name) => !registered.has(name));
	assert.deepEqual(
		missing,
		[],
		`${TOPIC_NAV} の対応表にあるアイコンが ${ICON_FONT} に未登録: ${missing.join(', ')}\n` +
			'登録が無いとグリフが配信されず、リガチャの文字がそのまま（1em の箱で切れて）表示される。'
	);
});


test('直接読取器: 二重引用符を読み、未対応の式や部分読取を拒否する', () => {
 const php = (value) => `function luna_frontier_topic_icon(string $label): string { $map = array('音楽' => ${value}, 'ニュース' => 'newspaper'); }`;
 assert.equal(topicIconMap(php('"music_note"')).get('音楽'), 'music_note');
 assert.throws(() => topicIconMap(php('$unknown')), /最後まで読めない/);
 assert.throws(() => topicIconMap(php("'music_' . 'note'")), /最後まで読めない/);
 assert.throws(() => registeredIcons("$names = array('music_note', $unknown);"), /最後まで読めない/);
 assert.equal(topicIconMap(php("'music_note' /* quote ' and brace } */")).size, 2);
});
