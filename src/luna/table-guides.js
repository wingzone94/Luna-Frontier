const initializedArticles = new WeakSet();

// The Paper layout scrolls the figure for blocks and the table for classic HTML.
export function setupTableGuides() {
  const article = document.querySelector('.single .m3-article__body');
  if (!article || initializedArticles.has(article)) return;
  const desktop = matchMedia('(min-width: 1024px)');
  const tables = Array.from(article.querySelectorAll('table')).filter(table => !table.parentElement.closest('table'));
  if (!tables.length) return;
  initializedArticles.add(article);

  const states = new Map();
  function update() {
    const overflowing = new Set();
    for (const table of tables) {
      for (let el = table; el && el !== article; el = el.parentElement) {
        if (el.clientWidth && el.scrollWidth > el.clientWidth &&
            /^(auto|scroll)$/.test(getComputedStyle(el).overflowX)) {
          overflowing.add(el);
          break;
        }
      }
    }
    for (const el of overflowing) {
      if (!states.has(el)) states.set(el, { guide: null, addedTab: false });
    }
    for (const [el, state] of states) {
      const scrollable = overflowing.has(el);
      if (scrollable && !el.hasAttribute('tabindex')) {
        el.tabIndex = 0;
        state.addedTab = true;
      } else if (!scrollable && state.addedTab) {
        el.removeAttribute('tabindex');
        state.addedTab = false;
      }
      const isDesktop = desktop.matches || document.documentElement.classList.contains('lf-tablet-pc');
      const show = scrollable && !isDesktop;
      if (show && !state.guide) {
        const guide = document.createElement('div');
        guide.className = 'lf-table-guide';
        guide.setAttribute('aria-hidden', 'true');
        guide.textContent = '左右にスワイプして表を確認';
        (el.closest('figure.wp-block-table') || el).before(guide);
        state.guide = guide;
      }
      if (state.guide) state.guide.hidden = !show;
    }
  }

  let frame;
  function schedule() {
    if (frame) return;
    frame = requestAnimationFrame(() => {
      frame = 0;
      update();
    });
  }

  article.addEventListener('scroll', event => {
    const guide = states.get(event.target)?.guide;
    if (guide && !guide.hidden && event.target.scrollLeft !== 0) {
      guide.classList.add('is-dismissed');
    }
  }, { capture: true, passive: true });

  const observer = new ResizeObserver(schedule);
  for (const table of tables) {
    observer.observe(table);
    observer.observe(table.parentElement);
    for (const section of table.tBodies) observer.observe(section);
  }
  desktop.addEventListener('change', schedule);
  update();
}
