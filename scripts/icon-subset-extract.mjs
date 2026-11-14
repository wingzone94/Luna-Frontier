/**
 * Material Symbolsの静的な参照候補を集める。実行時の登録や描画は別途照合する。
 *
 * high: Material Symbolsクラスを明示するspan、同一CSSブロックのfont-family指定。
 * medium: 汎用textContent、icon/data-icon属性、PHP iconキー、JS iconプロパティ、
 *         setIcon、別CSSルールの近接一致。別アイコン体系との衝突を断定しない。
 * map: 登録済み値の割合から推測した対応表。未登録エラーには用いない。
 *
 * CSS近接窓800文字は派生セレクタの参照を残すための候補抽出であり、適用証明ではない。
 * フィルタ追加は名前付き関数の単純array_merge型だけを候補として読む。
 * フィルタ候補を有効登録集合に混ぜず、実PHP確認を要する状態として残す。
 * tests/fixture、検証器自身、登録配列を製品の使用箇所として数えない。
 * 静的参照なしは削除可能を意味しない。正規表現ベースの抽出はPHP/JS/CSS全構文の
 * パーサではなく、動的指定やcascadeの完全性は保証しない。
 */

export const ICON_NAME = '[a-z0-9_]{2,40}';
export const ICON_TOKEN = new RegExp(`^${ICON_NAME}$`);

/** 値の後ろに来てよい文字。`'name'` の直後が比較演算子なら名前では無い。 */
const VALUE_END = '(?=\\s*(?:[,;)\\]}>/]|$))';

/** アイコン名がそのまま書かれている箇所。 */
export const LITERAL_PATTERNS = [
	{
		id: 'span_literal',
		confidence: 'high',
		note: '<span class="material-symbols-outlined">name</span>',
		re: new RegExp(`class=["'][^"']*material-symbols-outlined[^"']*["'][^>]*>\\s*(${ICON_NAME})\\s*<`, 'g'),
	},
	{
		id: 'text_content',
		confidence: 'medium',
		note: 'el.textContent = "name"',
		re: new RegExp(`\\.textContent\\s*=\\s*['"](${ICON_NAME})['"]${VALUE_END}`, 'g'),
	},
	{
		id: 'data_icon',
		confidence: 'medium',
		note: 'data-icon="name" / data-node-section-icon="name"',
		re: new RegExp(`\\bdata-(?:node-section-)?icon\\s*=\\s*["'](${ICON_NAME})["']${VALUE_END}`, 'g'),
	},
	{
		id: 'icon_attribute',
		confidence: 'medium',
		note: 'icon="name"',
		re: new RegExp(`\\bicon\\s*=\\s*["'](${ICON_NAME})["']${VALUE_END}`, 'g'),
	},
	{
		id: 'icon_arrow',
		confidence: 'medium',
		note: "PHP の 'icon' => 'name'",
		re: new RegExp(`['"]icon['"]\\s*=>\\s*['"](${ICON_NAME})['"]${VALUE_END}`, 'g'),
	},
	{
		id: 'icon_property',
		confidence: 'medium',
		note: 'JS/JSON の icon: "name"（WordPress のブロックアイコンと衝突しうる）',
		re: new RegExp(`\\bicon\\s*:\\s*['"](${ICON_NAME})['"]${VALUE_END}`, 'g'),
	},
	{
		id: 'set_icon_call',
		confidence: 'medium',
		note: 'setIcon(el, "name")（他の API と衝突しうる）',
		re: new RegExp(`\\.setIcon\\s*\\([^,()]*,\\s*['"](${ICON_NAME})['"]${VALUE_END}`, 'g'),
	},
];

/** CSS の content。アイコンフォントを使う規則の中にあるものだけ採用する。 */
export const CSS_CONTENT_PATTERN = new RegExp(`content\\s*:\\s*['"](${ICON_NAME})['"]${VALUE_END}`, 'g');
export const ICON_FONT_MARKER = /Material Symbols Outlined/;

/**
 * content の前後どこまでを「同じ規則圏内」とみなすか。
 * 実測: `.m3-article__body blockquote::before` は 43 文字後ろ、
 * 並べ替え表の `th.is-desc::after` は 526 文字前に基準の規則がある。
 */
export const CSS_CONTEXT_WINDOW = 800;

/** 対応表らしき配列を探すときの手掛かり。配列の直前（400文字）に "icon" があるもの。 */
export const ICON_MAP_CONTEXT = /icon/i;
export const ICON_MAP_CONTEXT_WINDOW = 400;

/**
 * 対応表とみなす条件: 値が2つ以上あり、6割以上が登録済みのアイコン名であること。
 * 実測: topic-nav の `'音楽' => 'music_note' ...` は 11 値中 8 値が登録済み（0.73）で採用。
 * `array( 'taxonomy' => 'category', 'orderby' => 'count' )` は 2 値中 1 値（0.50）で除外。
 */
export const ICON_MAP_MIN_VALUES = 2;
export const ICON_MAP_MIN_KNOWN_RATIO = 0.6;

/** 実行時にしか決まらない指定。ここに載った種類があると「未使用」と言い切れない。 */
export const DYNAMIC_SOURCES = [
	{
		id: 'php_expression_icon',
		note: 'スパンの中身が PHP の式（名前がソースに無い）',
		re: new RegExp(`class=["'][^"']*material-symbols-outlined[^"']*["'][^>]*>\\s*<\\?php`, 'g'),
	},
	{
		id: 'php_variable_icon',
		note: "PHP の 'icon' に変数・式を渡している（静的に読めない）",
		re: /['"]icon['"]\s*=>\s*(?!['"])/g,
	},
	{
		id: 'topic_icon_function',
		note: 'ラベルからアイコン名を決める関数（引数で変わる）',
		re: /\bluna_frontier_topic_icon\s*\(/g,
	},
	{
		id: 'icon_font_names_filter',
		note: 'node_icon_font_names フィルタで登録を足せる',
		re: /(?:add|apply)_filter\s*\(\s*['"]node_icon_font_names['"]/g,
	},
	{
		id: 'topic_icon_filter',
		note: 'luna_frontier_topic_icon フィルタで対応を差し替えられる',
		re: /apply_filters\s*\(\s*['"]luna_frontier_topic_icon['"]/g,
	},
	{
		id: 'js_variable_icon',
		note: 'JS で変数から icon を渡している（静的に読めない）',
		re: /\bicon\s*:\s*[A-Za-z_$]/g,
	},
];

export function lineOf(text, index) {
	let line = 1;
	for (let i = 0; i < index && i < text.length; i++) if (text.charCodeAt(i) === 10) line++;
	return line;
}

/**
 * 三項演算子の結果枝（`? 'a' : 'b'`）からアイコン名リテラルだけを取り出す。
 * 条件式側の比較文字列（`'skipped' === $status['state']` 等）は拾わない。
 *
 * @param {string} expr
 * @returns {string[]}
 */
export function extractTernaryBranchLiterals(expr) {
	const out = [];
	const scan = (chunk) => {
		const trimmed = chunk.trim();
		if (!trimmed) return;
		let q = -1;
		let quote = null;
		let paren = 0;
		let bracket = 0;
		for (let i = 0; i < trimmed.length; i++) {
			const c = trimmed[i];
			if (quote !== null) {
				if (c === '\\') { i++; continue; }
				if (c === quote) quote = null;
				continue;
			}
			if (c === "'" || c === '"') { quote = c; continue; }
			if (c === '(') paren++;
			else if (c === ')' && paren > 0) paren--;
			else if (c === '[') bracket++;
			else if (c === ']' && bracket > 0) bracket--;
			else if (c === '?' && paren === 0 && bracket === 0) {
				if (trimmed[i + 1] === '?') { i++; continue; }
				q = i;
				break;
			}
		}

		if (q === -1) {
			const unwrapped = trimmed.replace(/^\(([\s\S]*)\)$/, '$1').trim();
			const single = new RegExp(`^['"](${ICON_NAME})['"]$`).exec(unwrapped);
			if (single) {
				out.push(single[1]);
				return;
			}
			const fallback = new RegExp(`(?:\\|\\||\\?\\?)\\s*['"](${ICON_NAME})['"]\\s*$`).exec(unwrapped);
			if (fallback) out.push(fallback[1]);
			return;
		}

		const rest = trimmed.slice(q + 1);
		let colon = -1;
		quote = null;
		paren = 0;
		bracket = 0;
		let nested = 0;
		for (let i = 0; i < rest.length; i++) {
			const c = rest[i];
			if (quote !== null) {
				if (c === '\\') { i++; continue; }
				if (c === quote) quote = null;
				continue;
			}
			if (c === "'" || c === '"') { quote = c; continue; }
			if (c === '(') paren++;
			else if (c === ')' && paren > 0) paren--;
			else if (c === '[') bracket++;
			else if (c === ']' && bracket > 0) bracket--;
			else if (paren === 0 && bracket === 0) {
				if (c === '?' && rest[i + 1] !== '?') nested++;
				else if (c === ':') {
					if (rest[i + 1] === ':') { i++; continue; }
					if (nested === 0) { colon = i; break; }
					nested--;
				}
			}
		}
		if (colon === -1) return;
		scan(rest.slice(0, colon));
		scan(rest.slice(colon + 1));
	};
	scan(expr);
	return out;
}

/**
 * `open` に対応する閉じ位置を返す。文字列リテラルの中は数えない。
 * 入れ子の array( ... ) / [ ... ] や関数本体に対応するため。
 */
export function matchClose(text, from, open, close) {
	let depth = 1;
	let quote = null;
	let i = from;
	while (i < text.length) {
		const c = text[i];
		if (quote !== null) {
			if (c === '\\') { i += 2; continue; }
			if (c === quote) quote = null;
			i++;
			continue;
		}
		if (c === "'" || c === '"') { quote = c; i++; continue; }
		if (c === open) depth++;
		else if (c === close) { depth--; if (depth === 0) return i; }
		i++;
	}
	return -1;
}

/**
 * アイコン名がそのまま書かれている箇所と、高信頼の三項演算子・icon要素 textContent 代入を拾う。
 */
export function extractLiterals(text) {
	const found = [];
	for (const pattern of LITERAL_PATTERNS) {
		pattern.re.lastIndex = 0;
		let m;
		while ((m = pattern.re.exec(text))) {
			found.push({
				name: m[1],
				id: pattern.id,
				confidence: pattern.confidence,
				note: pattern.note,
				line: lineOf(text, m.index),
			});
		}
	}

	const spanPhpRe = /class=["'][^"']*material-symbols-outlined[^"']*["'][^>]*>\s*<\?(?:php\b|=)([\s\S]*?)\?>\s*(?=<\/span>)/g;
	for (const m of text.matchAll(spanPhpRe)) {
		const expr = m[1].replace(/^\s*(?:echo|print)\b\s*/, '').replace(/;\s*$/, '').trim();
		const names = extractTernaryBranchLiterals(expr);
		const line = lineOf(text, m.index);
		for (const name of names) {
			found.push({
				name,
				id: 'span_php_literal',
				confidence: 'high',
				note: '<span class="material-symbols-outlined"><?php ... ?></span>',
				line,
			});
		}
	}

	const iconTextRe = /(?:^|[^\w$])(?:[A-Za-z0-9_$]*[Ii]con(?:El|Element|Node|Span)?|querySelector\s*\(\s*['"][^'"]*material-symbols[^'"]*['"]\s*\))\.textContent\s*=\s*([^;\n]+)/gm;
	for (const m of text.matchAll(iconTextRe)) {
		const lineStart = text.lastIndexOf('\n', m.index) + 1;
		const linePrefix = text.slice(lineStart, m.index).trimStart();
		if (linePrefix.startsWith('//') || linePrefix.startsWith('*')) continue;
		const names = extractTernaryBranchLiterals(m[1]);
		const line = lineOf(text, m.index);
		for (const name of names) {
			found.push({
				name,
				id: 'icon_text_content',
				confidence: 'high',
				note: 'icon要素の textContent 代入',
				line,
			});
		}
	}

	return found;
}

/**
 * PHP ファイルやプラグイン内の高信頼なアイコン配列・三項演算子・match・定数マップを抽出する。
 *
 * @param {string} rel
 * @param {string} text
 * @returns {Array<{name: string, id: string, confidence: string, note: string, line: number}>}
 */
export function extractScopedHighRefs(rel, text) {
	const found = [];
	const normalized = String(rel).replaceAll('\\', '/');
	const isPhp = normalized.endsWith('.php');

	if (isPhp) {
		const phpIconArrowRe = /['"]icon['"]\s*=>\s*([^,\n;)]+)/g;
		for (const m of text.matchAll(phpIconArrowRe)) {
			const expr = m[1].trim();
			const names = extractTernaryBranchLiterals(expr);
			const line = lineOf(text, m.index);
			for (const name of names) {
				found.push({
					name,
					id: 'php_icon_arrow',
					confidence: 'high',
					note: "PHP の 'icon' => リテラル / 三項演算子",
					line,
				});
			}
		}

		const phpIconFnRe = /function\s+([A-Za-z0-9_]*icon[A-Za-z0-9_]*)\s*\([^)]*\)[^{]*\{/gi;
		for (const fnMatch of text.matchAll(phpIconFnRe)) {
			if (/(?:^|_)(?:icon_names|register_icon)/.test(fnMatch[1])) continue;
			const start = fnMatch.index + fnMatch[0].length;
			const end = matchClose(text, start, '{', '}');
			if (end === -1) continue;
			const body = text.slice(start, end);
			const lineBase = fnMatch.index;

			for (const matchExpr of body.matchAll(/\bmatch\s*\([^)]*\)\s*\{/g)) {
				const mStart = matchExpr.index + matchExpr[0].length;
				const mEnd = matchClose(body, mStart, '{', '}');
				if (mEnd === -1) continue;
				const mBody = body.slice(mStart, mEnd);
				for (const arm of mBody.matchAll(new RegExp(`=>\\s*['"](${ICON_NAME})['"]${VALUE_END}`, 'g'))) {
					found.push({
						name: arm[1],
						id: 'php_icon_match',
						confidence: 'high',
						note: `PHP icon関数 ${fnMatch[1]}() の match`,
						line: lineOf(text, lineBase + matchExpr.index),
					});
				}
			}

			for (const literal of findArrayLiterals(body, { requireIconContext: false })) {
				for (const name of namesInArrayBody(literal.body)) {
					found.push({
						name,
						id: 'php_icon_fn_array',
						confidence: 'high',
						note: `PHP icon関数 ${fnMatch[1]}() の配列`,
						line: lineOf(text, lineBase + literal.start),
					});
				}
			}

			for (const fallback of body.matchAll(new RegExp(`\\$icon\\s*=\\s*['"](${ICON_NAME})['"]\\s*;`, 'g'))) {
				found.push({
					name: fallback[1],
					id: 'php_icon_fn_fallback',
					confidence: 'high',
					note: `PHP icon関数 ${fnMatch[1]}() の既定値`,
					line: lineOf(text, lineBase + fallback.index),
				});
			}
		}
	}

	const jsIconMapRe = /\b(?:const|let|var)\s+([A-Za-z0-9_$]*_?ICONS?)\s*=\s*\{/g;
	for (const mapMatch of text.matchAll(jsIconMapRe)) {
		const mapName = mapMatch[1];
		const usedInTextContent = new RegExp(`\\.textContent\\s*=[^;]*\\b${mapName}\\s*\\[`).test(text);
		if (!usedInTextContent) continue;
		const start = mapMatch.index + mapMatch[0].length;
		const end = matchClose(text, start, '{', '}');
		if (end === -1) continue;
		const body = text.slice(start, end);
		const line = lineOf(text, mapMatch.index);
		for (const entry of body.matchAll(new RegExp(`:\\s*['"](${ICON_NAME})['"]${VALUE_END}`, 'g'))) {
			found.push({
				name: entry[1],
				id: 'js_icon_map_const',
				confidence: 'high',
				note: `JS アイコン定数マップ ${mapName}`,
				line,
			});
		}
	}

	return found;
}

/** CSS の content にアイコン名が入っている箇所（アイコンフォントを使う規則の中だけ）。 */
export function extractCssContent(text) {
	const found = [];
	CSS_CONTENT_PATTERN.lastIndex = 0;
	let m;
	while ((m = CSS_CONTENT_PATTERN.exec(text))) {
		const from = Math.max(0, m.index - CSS_CONTEXT_WINDOW);
		const to = Math.min(text.length, m.index + m[0].length + CSS_CONTEXT_WINDOW);
		if (!ICON_FONT_MARKER.test(text.slice(from, to))) continue;
		// 近接する別ルールを確定扱いしない。同一ブロック内の明示的font指定のみhigh。
		const open = text.lastIndexOf('{', m.index);
		const close = text.indexOf('}', m.index);
		const block = open >= 0 && close >= 0 ? text.slice(open + 1, close) : '';
		const families = [...block.matchAll(/font-family\s*:\s*([^;}]+)/g)];
		const ownFamily = families.at(-1)?.[1];
		if (ownFamily && !ICON_FONT_MARKER.test(ownFamily)) continue;
		found.push({
			name: m[1],
			id: 'css_content',
			confidence: ownFamily ? 'high' : 'medium',
			note: ownFamily ? 'CSS同一ルールにMaterial Symbols指定' : '近接するMaterial Symbols指定（適用関係は未確定）',
			line: lineOf(text, m.index),
		});
	}
	return found;
}

/**
 * 配列リテラルを、対応する閉じ括弧まで拾う。
 *
 * @param {string} text
 * @param {{ requireIconContext?: boolean }} [options]
 *   requireIconContext（既定 true）— 配列の直前 400 文字に "icon" が無いものは捨てる。
 *   フィルタ関数の本体のように「もうアイコン用と分かっている」範囲を走査するときは false。
 */
export function findArrayLiterals(text, options = {}) {
	const requireIconContext = options.requireIconContext ?? true;
	const out = [];
	const re = /\barray\s*\(|\[/g;
	let m;
	while ((m = re.exec(text))) {
		if (requireIconContext) {
			const context = text.slice(Math.max(0, m.index - ICON_MAP_CONTEXT_WINDOW), m.index);
			if (!ICON_MAP_CONTEXT.test(context)) continue;
		}
		const open = m[0].endsWith('(') ? '(' : '[';
		const close = open === '(' ? ')' : ']';
		const bodyStart = m.index + m[0].length;
		const end = matchClose(text, bodyStart, open, close);
		if (end === -1) continue;
		out.push({ start: m.index, body: text.slice(bodyStart, end), line: lineOf(text, m.index) });
	}
	return out;
}

/**
 * 配列の本体から名前の候補を拾う。
 * `'key' => 'name'` と `'key': 'name'` の値、および裸の `'name'`。
 * キーは終端記号の条件で落ちる（`'label' => 'ホームに戻る'` の `'label'` は名前では無い）。
 */
export function namesInArrayBody(body) {
	const out = new Set();
	for (const m of body.matchAll(new RegExp(`=>\\s*['"](${ICON_NAME})['"]${VALUE_END}`, 'g'))) out.add(m[1]);
	for (const m of body.matchAll(new RegExp(`:\\s*['"](${ICON_NAME})['"]${VALUE_END}`, 'g'))) out.add(m[1]);
	for (const m of body.matchAll(new RegExp(`['"](${ICON_NAME})['"]${VALUE_END}`, 'g'))) out.add(m[1]);
	return [...out];
}

/**
 * アイコン対応表とみなせる配列から名前を拾う（中信頼）。
 *
 * @param {string} text
 * @param {{ known?: Set<string>, minValues?: number, minKnownRatio?: number }} [options]
 *   known — 登録済みのアイコン名。対応表かどうかの判定に使う。
 */
export function extractIconMaps(text, options = {}) {
	const known = options.known ?? new Set();
	const minValues = options.minValues ?? ICON_MAP_MIN_VALUES;
	const minKnownRatio = options.minKnownRatio ?? ICON_MAP_MIN_KNOWN_RATIO;

	const found = [];
	for (const literal of findArrayLiterals(text)) {
		const names = namesInArrayBody(literal.body);
		if (names.length < minValues) continue;
		const knownCount = names.filter((name) => known.has(name)).length;
		if (knownCount / names.length < minKnownRatio) continue;
		for (const name of names) {
			found.push({ name, id: 'icon_map', confidence: 'map', note: 'アイコン対応表らしき配列の値', line: literal.line });
		}
	}
	return found;
}

/**
 * 静的に解決するのは、名前付きcallbackが入力配列へliteral配列を単純mergeして返す形だけ。
 * 無関係な配列、array_diff、条件分岐、closureは登録済みと断定しない。
 * 実行順・active hookはPHP実行で別途確認が必要（静的候補集合）。
 */
export function extractFontRegistrationAdditions(text) {
	const out = [];
	const hooks = /add_filter\s*\(\s*['"]node_icon_font_names['"]\s*,\s*(['"])([A-Za-z_][A-Za-z0-9_]*)\1\s*\)/g;
	for (const hook of text.matchAll(hooks)) {
		const def = new RegExp(`function\\s+${hook[2]}\\s*\\(\\s*(?:array\\s+)?(\\$[A-Za-z_][A-Za-z0-9_]*)\\s*\\)[^{]*\\{`).exec(text);
		if (!def) continue;
		const start = def.index + def[0].length;
		const end = matchClose(text, start, '{', '}');
		if (end === -1) continue;
		const scope = text.slice(start, end).trim();
		const input = def[1].replace('$', '\\$');
		const expression = new RegExp(`^return\\s+array_merge\\s*\\(\\s*${input}\\s*,\\s*array\\s*\\(([\\s\\S]*)\\)\\s*\\)\\s*;\\s*$`).exec(scope);
		if (!expression) continue;
		const body = expression[1];
		const value = /\s*(['"])([a-z0-9_]{2,40})\1\s*(,|$)/y;
		let at = 0;
		const names = [];
		while (body.slice(at).trim()) {
			value.lastIndex = at;
			const m = value.exec(body);
			if (!m) break;
			names.push(m[2]); at = value.lastIndex;
		}
		if (body.slice(at).trim()) continue;
		for (const name of names) out.push({ name, id: 'font_registration', confidence: 'map',
			note: '単純array_merge型フィルタの追加候補（有効hookは実PHPで要確認）', line: lineOf(text, hook.index) });
	}
	return out;
}

/**
 * 本体配列にない名前は、フィルタ追加候補なら未登録と断定しない。
 * 推測集合を本体登録へunionして「登録確認済み」にもしない。
 */
export function partitionMissing(uses, declared, additions) {
	const missing = [];
	const needsRuntime = [];
	for (const [name, hit] of uses) {
		if (declared.has(name)) continue;
		(additions.has(name) ? needsRuntime : missing).push([name, hit]);
	}
	return { missing, needsRuntime };
}

/**
 * 未登録アイコン名が登録済みの別名に前方一致し、リガチャ化けを起こす危険を検知する。
 *
 * @param {Iterable<string>} missingNames 未登録のアイコン名一覧。
 * @param {Set<string>} declared 登録済みのアイコン名集合。
 * @returns {Array<{name: string, prefix: string, prefixes: string[]}>}
 */
export function findPrefixCollisions(missingNames, declared) {
	const declaredList = [...declared].sort((a, b) => b.length - a.length || a.localeCompare(b));
	const seen = new Set();
	const collisions = [];
	for (const name of missingNames) {
		if (seen.has(name) || declared.has(name)) continue;
		seen.add(name);
		const prefixes = declaredList.filter((r) => name !== r && name.startsWith(r));
		if (prefixes.length > 0) {
			collisions.push({ name, prefix: prefixes[0], prefixes });
		}
	}
	return collisions;
}

/**
 * `inc/icon-font.php` の配列から登録済みの名前を読む。
 * PHP を動かさずに済ませるための最低限の読み取り（配列の中の 'name' を拾うだけ）。
 */
export function declaredIconsFromSource(text) {
	const from = text.indexOf('$names = array(');
	const to = text.indexOf(');', from);
	if (from === -1 || to === -1) return new Set();
	const body = text.slice(from, to);
	return new Set([...body.matchAll(new RegExp(`'(${ICON_NAME})'`, 'g'))].map((m) => m[1]));
}

/** 実行時にしか決まらない指定を探す。 */
export function findDynamicSources(text) {
	const out = [];
	for (const source of DYNAMIC_SOURCES) {
		source.re.lastIndex = 0;
		const m = source.re.exec(text);
		if (m) out.push({ id: source.id, note: source.note, line: lineOf(text, m.index) });
	}
	return out;
}

/* --- 製品かどうかの線引き（テスト・fixture を「使用箇所」に数えない） -------- */

/**
 * 「使用箇所」として数えてはいけないファイル。
 *
 *   - inc/icon-font.php : 登録そのもの。数えると登録済みが全部「使用中」になる。
 *     ただし「静的に読めない指定」の観測対象には残す（フィルタ適用の呼び出しがあるため）。
 *   - 検証器自身のソース : 説明のためにアイコン名や span の例が書いてある。
 *     数えると例示用の `name` が未登録のアイコンとして報告される。
 */
export const DECLARATION_FILES = new Set(['inc/icon-font.php']);
export const TOOL_FILES = new Set([
	'scripts/icon-subset-extract.mjs',
	'scripts/verify-icon-subset.mjs',
	'scripts/verify-icon-subset.test.mjs',
]);

/**
 * 製品ではない（テスト・fixture の）ファイルかどうか。
 *
 * テストコードや fixture は製品のアイコン使用箇所ではない。ここを明示的に切っておかないと、
 * 「テストファイルに使用箇所らしい文字列を書かない」というコメント上の約束に依存してしまう。
 * 約束が破れた瞬間に、(1) 登録済みの名前がテストに出ただけで「使用中」に化けて
 * 未使用の検出能力が落ち、(2) 架空の名前が未登録アイコンとして exit 1 を起こす。
 *
 * これは推測ではなくパスで切る。製品側のファイル（inc/**, template-parts/**, *.php 等）は
 * 一切除外しない。
 */
const NON_PRODUCT_PATTERNS = [
	/(^|\/)tests?\//,
	/(^|\/)__tests__\//,
	/(^|\/)fixtures?\//,
	/\.(?:test|spec|fixture)\.[cm]?[jt]s$/,
];

export function isNonProductPath(rel) {
	const p = String(rel).replaceAll('\\', '/');
	return NON_PRODUCT_PATTERNS.some((re) => re.test(p));
}

/**
 * 走査対象のエントリ（`{ path, text }`）から、製品の使用箇所だけを集計する。
 *
 * ファイル探索と読み込みは呼び出し側（CLI）の仕事。ここは純粋な集計なので、
 * テストから「製品ファイルは拾う／テスト・fixture は拾わない」をそのまま確認できる。
 *
 * @param {Array<{path: string, text: string}>} entries
 * @param {Set<string>} declared 登録済みのアイコン名（対応表の判定に使う）。
 * @returns {{found: Map, medium: Map, maps: Map, filterAdded: Map, dynamic: Array}}
 *   found / medium / maps / filterAdded は「製品の使用箇所」。dynamic も製品のファイルだけ。
 */
export function extractUsageFromEntries(entries, declared) {
	const found = new Map();
	const medium = new Map();
	const maps = new Map();
	const filterAdded = new Map();
	const dynamic = [];

	for (const { path: rel, text } of entries) {
		// 検証器自身のソースは、例示用の文字列が混ざるので何も数えない
		// （実行時に読めない指定としても数えない。製品の話ではないため）。
		if (TOOL_FILES.has(rel)) continue;
		// テスト・fixture は製品の使用箇所でも、製品の動的指定でもない。
		if (isNonProductPath(rel)) continue;

		if (!DECLARATION_FILES.has(rel)) {
			for (const hit of [...extractLiterals(text), ...extractScopedHighRefs(rel, text), ...extractCssContent(text)]) {
				const bucket = hit.confidence === 'high' ? found : medium;
				if (!bucket.has(hit.name)) bucket.set(hit.name, { file: rel, ...hit });
			}
			for (const hit of extractIconMaps(text, { known: declared })) {
				if (!maps.has(hit.name)) maps.set(hit.name, { file: rel, ...hit });
			}
			for (const hit of extractFontRegistrationAdditions(text)) {
				if (!filterAdded.has(hit.name)) filterAdded.set(hit.name, { file: rel, ...hit });
			}
		}

		// 実行時にしか決まらない指定は、登録ファイルも含めて数える
		// （そこが「静的に見えない」原因そのものなので）。
		for (const hit of findDynamicSources(text)) dynamic.push({ file: rel, ...hit });
	}

	return { found, medium, maps, filterAdded, dynamic };
}
