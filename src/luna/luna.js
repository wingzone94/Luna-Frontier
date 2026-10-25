/**
 * Luna Frontier 2.0 / SkyAlow — entry point
 *
 * 方針（指示書 §16）:
 * - JavaScript は Progressive Enhancement 専用。
 * - 本文・固定ページ・ナビゲーション・パンくず・基本検索は JS なしで成立させる。
 * - Node 1.x 由来の main.js を置き換えず、2.0 固有の差分だけを足す。
 *
 * CSS は独立した Vite entry（src/styles/luna.css）として出力し、ここからは import
 * しない。JS が失敗してもスタイルは適用される状態を保つため。
 */

/**
 * 脚注を Reading Aside（右カラム）へ移す。
 *
 * 脚注は Node 由来の the_content フィルタ（優先度 999）で本文末に追加するため、
 * Reading Aside を描画する時点ではまだ存在しない。サーバー側で右カラムへ
 * 差し込むことができないので、描画後に DOM を移動する。
 *
 * - 2 カラムが成立する幅（>=1024px）でだけ移動する。
 * - 移動できない／JS が動かない場合は本文末に残るだけで、脚注は問題なく読める。
 * - innerHTML で作り直さず要素そのものを移すので、既存スクリプトが張った
 *   イベントリスナー（番号タブ・説明トグル）はそのまま生き残る。
 */
function setupFootnoteRelocation() {
  const aside = document.querySelector('.lf-reading-aside');
  const footnotes = document.querySelector('.node-footnotes');

  if (!aside || !footnotes) return;

  // 元の位置を覚えておき、狭い画面へ戻したときに復帰できるようにする。
  const anchor = document.createComment('luna-frontier:footnotes');
  footnotes.parentNode.insertBefore(anchor, footnotes);

  const block = document.createElement('section');
  block.className = 'lf-aside-block lf-aside-block--footnotes';
  block.setAttribute('aria-label', '脚注');

  const media = window.matchMedia('(min-width: 1024px)');

  const apply = () => {
    if (media.matches) {
      if (footnotes.parentNode === block) return;
      block.appendChild(footnotes);
      aside.appendChild(block);
    } else {
      if (footnotes.parentNode !== block) return;
      anchor.parentNode.insertBefore(footnotes, anchor.nextSibling);
      block.remove();
    }
  };

  apply();

  if (typeof media.addEventListener === 'function') {
    media.addEventListener('change', apply);
  }
}

/**
 * 記事タイトルが枠に収まらないカードを自動で詰める。
 *
 * 背景: HEADLINE カードのタイトルは Node 由来 CSS が -webkit-line-clamp で 3 行に
 * 制限しているが、Chromium が display: -webkit-box を blockify するため
 * clamp が効かず、overflow: hidden だけが残って文字の途中で切れていた。
 *
 * ここでは「少し詰めれば収まるものは詰める」を段階的に試し、
 * それでも収まらないものだけ末尾を省略する。
 *   1. 字送りと文字サイズをわずかに詰める（3 段階）
 *   2. それでも溢れるなら二分探索で切り詰めて … を付ける
 *
 * JS が動かない場合は CSS 側の max-height + 下端のぼかしが受け止めるので、
 * 文字が中途半端に切れた見た目にはならない。
 */
/*
 * 対象は「一覧に並ぶ記事タイトル」。ページ種別を問わず同じ処理を当てる
 * （index / archive のリスト・グリッド / 検索結果 / 関連記事 / HEADLINE）。
 * 実際に溢れている要素にしか手を入れないので、対象を広めに取っても副作用はない。
 * 見出し・目次・アーカイブヘッダーなど「一覧のカードではないもの」は含めない。
 */
const TITLE_SELECTOR = [
  '.m3-card__title',
  '.c-card__title',
  '.c-headline-card__title',
  '.m3-headline-card__title',
  '.m3-related-card__title',
  '.m3-game-card__title',
  '.m3-elevated-nav-card__title',
].join(', ');

// [font-size 倍率, letter-spacing]
const FIT_STEPS = [
  [1, null],
  [0.96, '-0.015em'],
  [0.92, '-0.025em'],
  [0.88, '-0.035em'],
];

function overflows(el) {
  return el.scrollHeight - el.clientHeight > 1;
}

function applyStep(el, step) {
  const [scale, tracking] = FIT_STEPS[step];
  if (step === 0) {
    el.style.removeProperty('--lf-title-fit');
    el.style.removeProperty('--lf-title-tracking');
    el.classList.remove('lf-title-fit');
    return;
  }
  el.classList.add('lf-title-fit');
  el.style.setProperty('--lf-title-fit', String(scale));
  if (tracking) el.style.setProperty('--lf-title-tracking', tracking);
}

/*
 * タイトルの文字を持つ要素。カードによっては <h3><a>タイトル</a></h3> の形なので、
 * h3 の textContent を書き換えるとリンクごと壊れる。必ず内側の要素を触る。
 * 溢れているかどうかの判定は、高さを持つ外側（h3）で行う。
 */
function textHostOf(el) {
  return el.querySelector('a') || el;
}

function truncate(el) {
  const host = textHostOf(el);
  const full = el.dataset.lfTitle;
  let lo = 0;
  let hi = full.length;

  // 収まる最大の文字数を二分探索する
  while (lo < hi) {
    const mid = Math.ceil((lo + hi) / 2);
    host.textContent = full.slice(0, mid) + '…';
    if (overflows(el)) hi = mid - 1;
    else lo = mid;
  }

  host.textContent = lo > 0 ? full.slice(0, lo) + '…' : full;
  el.classList.add('lf-title-truncated');
  el.title = full;
}

/*
 * タイトルの箱が 2 行ぶんも無いときは、レイアウト側の都合で潰れている
 * （flex の縮小など）。ここで切り詰めても読めるものにはならず、
 * 元の文字列を壊すだけなので何もしない。
 */
function isCollapsed(el) {
  const lh = parseFloat(getComputedStyle(el).lineHeight);
  if (!Number.isFinite(lh) || lh <= 0) return false;
  return el.clientHeight < lh * 1.5;
}

function fitTitle(el) {
  const host = textHostOf(el);
  if (!el.dataset.lfTitle) el.dataset.lfTitle = host.textContent.trim();

  // 毎回まっさらな状態から測り直す（リサイズで枠が広がった場合に戻せるように）
  host.textContent = el.dataset.lfTitle;
  el.classList.remove('lf-title-truncated');
  el.removeAttribute('title');
  applyStep(el, 0);

  if (!overflows(el) || isCollapsed(el)) return;

  for (let step = 1; step < FIT_STEPS.length; step += 1) {
    applyStep(el, step);
    if (!overflows(el)) return;
  }

  /*
   * 詰めても収まらなかった場合。
   * どのみち省略するなら、縮めたままだとカード間で文字サイズが不揃いになるだけなので
   * 通常サイズへ戻してから切る。詰めるのは「詰めれば切らずに済む」ときだけにする。
   */
  applyStep(el, 0);
  truncate(el);
}

function setupTitleAutoFit() {
  const titles = document.querySelectorAll(TITLE_SELECTOR);
  if (!titles.length) return;

  const run = () => titles.forEach(fitTitle);

  // Web フォント適用前に測ると行数がずれるので、読み込み完了後にもう一度測る。
  run();
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(run).catch(() => {});
  }

  let timer = 0;
  window.addEventListener('resize', () => {
    clearTimeout(timer);
    timer = window.setTimeout(run, 150);
  });
}

const ARTICLE_HEADING_SELECTOR = 'h2, h3, h4, h5, h6';
const HEADING_EXCLUDE_SELECTOR = [
  '#m3-sticky-toc',
  '#m3-inline-toc',
  '#comments',
  '#comments-section',
  '.m3-post-comment-toc',
  '.m3-blogcard',
  '.m3-card',
  '.c-card',
  '.lf-reading-aside',
].join(', ');

const DESKTOP_TOC_MEDIA = '(min-width: 1024px)';
const ASIDE_TOC_FLASH_MS = 1400;

function isSinglePostView() {
  return document.body.classList.contains('single')
    || document.body.classList.contains('single-post');
}

function findAsideTocLink(targetId) {
  if (!targetId) return null;

  return Array.from(document.querySelectorAll('.lf-aside-block--toc .lf-toc__link')).find((link) => {
    const href = link.getAttribute('href') || '';
    return href === `#${targetId}` || link.hash.slice(1) === targetId;
  }) || null;
}

function revealAsideTocForHeading(heading) {
  const tocBlock = document.querySelector('.lf-aside-block--toc');
  if (!tocBlock) return;

  tocBlock.classList.add('is-lf-revealed');

  document.querySelectorAll('.lf-aside-block--toc .lf-toc__link').forEach((tocLink) => {
    tocLink.classList.remove('is-current', 'lf-toc__link--flash');
    tocLink.removeAttribute('aria-current');
  });

  const link = findAsideTocLink(heading?.id);
  if (!link) return;

  link.classList.add('is-current', 'lf-toc__link--flash');
  link.setAttribute('aria-current', 'location');

  const linkRect = link.getBoundingClientRect();
  const blockRect = tocBlock.getBoundingClientRect();
  tocBlock.scrollTop += linkRect.top - blockRect.top - (blockRect.height / 2) + (linkRect.height / 2);

  window.clearTimeout(Number(link.dataset.lfTocFlashTimer || 0));
  const timer = window.setTimeout(() => {
    link.classList.remove('lf-toc__link--flash');
    delete link.dataset.lfTocFlashTimer;
  }, ASIDE_TOC_FLASH_MS);
  link.dataset.lfTocFlashTimer = String(timer);
}

/* ---------------------------------------------------------------------
 * 目次の縦ライン
 *
 * 項目ごとの border を出し入れすると、現在地が移るたびに線が消えて別の
 * 場所に現れる。1 本のマーカーを滑り下ろす形に変え、移動中だけ薄くする。
 * 位置と高さは CSS 変数で渡す（描画は _article-layout.css の .lf-toc::after）。
 * --------------------------------------------------------------------- */
function moveTocMarker(list, link) {
  if (!list) return;

  if (!link) {
    list.style.setProperty('--lf-toc-marker-opacity', '0');
    return;
  }

  const top = link.offsetTop;
  const height = link.offsetHeight;
  const previous = list.dataset.lfMarkerTop;

  list.style.setProperty('--lf-toc-marker-top', `${top}px`);
  list.style.setProperty('--lf-toc-marker-height', `${height}px`);

  // 初回は移動が無いので、そのまま出す。
  if (previous === undefined) {
    list.style.setProperty('--lf-toc-marker-opacity', '1');
    list.dataset.lfMarkerTop = String(top);
    return;
  }

  if (previous !== String(top)) {
    // 一度薄くしてから戻す。位置の遷移と同じ長さなので、滑りながら
    // 消えて、着く頃に戻って見える。
    list.style.setProperty('--lf-toc-marker-opacity', '0.35');
    window.clearTimeout(Number(list.dataset.lfMarkerTimer || 0));
    const timer = window.setTimeout(() => {
      list.style.setProperty('--lf-toc-marker-opacity', '1');
      delete list.dataset.lfMarkerTimer;
    }, 60);
    list.dataset.lfMarkerTimer = String(timer);
  } else {
    list.style.setProperty('--lf-toc-marker-opacity', '1');
  }

  list.dataset.lfMarkerTop = String(top);
}

function markCurrentTocLink(list, targetId) {
  if (!list) return null;

  let current = null;
  list.querySelectorAll('.lf-toc__link').forEach((link) => {
    const isCurrent = link.hash.slice(1) === targetId;
    link.classList.toggle('is-current', isCurrent);
    if (isCurrent) {
      link.setAttribute('aria-current', 'location');
      current = link;
    } else {
      link.removeAttribute('aria-current');
    }
  });

  moveTocMarker(list, current);
  return current;
}

/* ---------------------------------------------------------------------
 * 本文見出しのダブルクリックで、右カラムの目次を現在地表示にする。
 * --------------------------------------------------------------------- */
function setupHeadingTocAccess() {
  if (!isSinglePostView()) return;

  const article = document.querySelector('.m3-article__body');
  if (!article) return;

  const media = window.matchMedia(DESKTOP_TOC_MEDIA);
  article.addEventListener('dblclick', (event) => {
    if (!media.matches) return;

    const heading = event.target.closest(ARTICLE_HEADING_SELECTOR);
    if (!heading || !article.contains(heading) || heading.closest(HEADING_EXCLUDE_SELECTOR)) return;

    event.preventDefault();
    revealAsideTocForHeading(heading);
  });
}

/* ---------------------------------------------------------------------
 * 右カラム目次の現在地を、読んでいる見出しに合わせて動かす。
 * 縦ラインはここから moveTocMarker() 経由で滑る。
 * --------------------------------------------------------------------- */
function setupAsideTocProgress() {
  if (!isSinglePostView()) return;

  const list = document.querySelector('.lf-aside-block--toc .lf-toc');
  const article = document.querySelector('.m3-article__body');
  if (!list || !article) return;

  const headings = Array.from(list.querySelectorAll('.lf-toc__link'))
    .map((link) => ({ link, heading: document.getElementById(link.hash.slice(1)) }))
    .filter((entry) => entry.heading);

  if (!headings.length) return;

  let frame = 0;
  const update = () => {
    frame = 0;
    // 画面の上 1/3 を通過した最後の見出しを現在地とする。
    const marker = window.innerHeight / 3;
    let current = headings[0];
    for (const entry of headings) {
      if (entry.heading.getBoundingClientRect().top <= marker) current = entry;
    }
    markCurrentTocLink(list, current.heading.id);
  };

  const schedule = () => {
    if (!frame) frame = window.requestAnimationFrame(update);
  };

  window.addEventListener('scroll', schedule, { passive: true });
  window.addEventListener('resize', schedule, { passive: true });
  update();
}

function setupCalendarMonth() {
  const form = document.querySelector('.lf-home__calendar-form');
  if (!form) return;
  const select = form.querySelector('select');
  const panel = form.closest('.lf-home__calendar');
  let request;
  form.classList.add('is-enhanced');
  select.addEventListener('change', async () => {
    request?.abort();
    const controller = new AbortController();
    request = controller;
    const url = new URL(form.action);
    new FormData(form).forEach((value, key) => url.searchParams.set(key, value));
    panel.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, { signal: controller.signal });
      if (!response.ok) throw new Error('Calendar request failed');
      const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
      for (const selector of ['#wp-calendar', '.wp-calendar-nav']) {
        const next = doc.querySelector(selector);
        const current = panel.querySelector(selector);
        if (!next || !current) throw new Error('Calendar markup missing');
        current.replaceWith(next);
      }
    } catch (error) {
      if (error.name !== 'AbortError') form.submit();
    } finally {
      if (request === controller) panel.removeAttribute('aria-busy');
    }
  });
}

function init() {
  const headerSearch = document.getElementById('m3-search-input');
  if (headerSearch) {
    headerSearch.placeholder = 'キーワードで検索';
    headerSearch.setAttribute('aria-label', 'キーワードで検索');
  }
  // The all-articles list uses the parent card typography without Luna auto-fit.
  if (document.body.classList.contains('lf-legacy-archive')) return;
  setupCalendarMonth();
  setupFootnoteRelocation();
  setupTitleAutoFit();
  setupHeadingTocAccess();
  setupAsideTocProgress();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}

export {};
