/** Preserve the current WordPress query while loading article result pages. */
(function () {
  function initArchiveFlow() {
    const containers = document.querySelectorAll('.m3-post-grid__container');
    const container = containers[containers.length - 1];
    const navigation = document.querySelector('.m3-navigation');
    const initialNext = navigation?.querySelector('a.next');
    if (!container || !initialNext || !('IntersectionObserver' in window)) return;

    const initialParams = new URL(location.href).searchParams;
    const pageUrl = (href, base) => {
        if (!href) return null;
        const url = new URL(href, base);
        if (url.origin !== location.origin) throw new Error('Invalid pagination origin');
        url.hash = '';
        for (const key of new Set(initialParams.keys())) {
            if (key !== 'paged' && key !== 'page') {
                url.searchParams.delete(key);
                initialParams.getAll(key).forEach(value => url.searchParams.append(key, value));
            }
        }
        return url.href;
    };

    let nextUrl = pageUrl(initialNext.getAttribute('href'), location.href);
    let loading = false;
    const visited = new Set([location.href]);
    const seen = new Set(Array.from(container.querySelectorAll('[id^="post-"]'), el => el.id));
    if (!seen.size) return;

    const sentinel = document.createElement('div');
    sentinel.className = 'node-flow-loader';
    sentinel.setAttribute('aria-live', 'polite');
    sentinel.style.minHeight = '1px';
    container.parentNode.insertBefore(sentinel, container.nextSibling);
    const previousDisplay = navigation.style.display;
    navigation.style.display = 'none';

    const observer = new IntersectionObserver(async entries => {
        if (!entries.some(entry => entry.isIntersecting) || loading || !nextUrl) return;
        loading = true;
        sentinel.textContent = '読み込み中...';
        const requested = nextUrl;
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 15000);
        try {
            if (visited.has(requested)) throw new Error('Repeated pagination URL');
                const response = await fetch(requested, { credentials: 'same-origin', signal: controller.signal });
            if (!response.ok || new URL(response.url || requested).origin !== location.origin) throw new Error('Page request failed');
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const grids = doc.querySelectorAll('.m3-post-grid__container');
            const grid = grids[grids.length - 1];
            if (!grid) throw new Error('Article list missing');
            const children = Array.from(grid.children);
            const additions = [];
            const nextSeen = new Set(seen);
            for (const child of children) {
                const cards = child.matches('[id^="post-"]') ? [child] : Array.from(child.querySelectorAll('[id^="post-"]'));
                if (!cards.length) throw new Error('Article identifier missing');
                let uniqueCount = 0;
                for (const card of cards) {
                    if (nextSeen.has(card.id)) card.remove();
                    else { nextSeen.add(card.id); uniqueCount++; }
                }
                if (uniqueCount) additions.push(child);
            }
            const link = doc.querySelector('.m3-navigation a.next');
            const following = link ? pageUrl(link.getAttribute('href'), response.url || requested) : null;
            if (following === requested || (following && visited.has(following))) throw new Error('Repeated pagination URL');
            additions.forEach(item => container.appendChild(item));
            nextSeen.forEach(id => seen.add(id));
            visited.add(requested);
            nextUrl = following;
            if (!nextUrl) {
                observer.disconnect();
                sentinel.remove();
            }
        } catch (error) {
            console.error('Luna Flow: Error loading posts.', error);
            observer.disconnect();
            initialNext.href = requested;
            navigation.style.display = previousDisplay;
            sentinel.textContent = '';
            sentinel.remove();
        } finally {
                window.clearTimeout(timeout);
            loading = false;
            if (sentinel.isConnected) {
                sentinel.textContent = '';
                observer.unobserve(sentinel);
                observer.observe(sentinel);
            }
        }
    }, { rootMargin: '200px' });
    observer.observe(sentinel);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initArchiveFlow, { once: true });
  } else {
    initArchiveFlow();
  }
})();
