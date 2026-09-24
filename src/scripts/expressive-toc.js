import { isSinglePostView } from './page-state';

export function initExpressiveFloatingTOC() {
    if (!isSinglePostView()) return;

    const root = document.documentElement;
    const headingSelector = 'h1, h2, h3, h4, h5, h6';
    const normalizeText = (value) => value.replace(/\s+/g, ' ').trim();
    const slugify = (value) => {
        return normalizeText(value)
            .toLowerCase()
            .replace(/[^\p{Letter}\p{Number}\s-]/gu, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    };
    const getArticleTocItems = () => {
        const source = document.getElementById('m3-article-toc-data');
        const json = source?.textContent?.trim();
        if (!json) return [];

        try {
            const items = JSON.parse(json);
            if (!Array.isArray(items)) return [];

            return items.map((item) => {
                return {
                    id: String(item.id || ''),
                    level: String(item.level || 'h2').toLowerCase(),
                    text: normalizeText(String(item.text || '')),
                    href: String(item.href || ''),
                    page: Number(item.page || 1),
                    current: Boolean(item.current)
                };
            }).filter((item) => item.id && item.text);
        } catch {
            return [];
        }
    };

    let headings = [];
    let tocReadyDispatched = false;
    let lastToggleAt = 0;

    const getArticle = () => {
        return document.querySelector('.m3-article__body')
            || document.querySelector('.entry-content')
            || document.querySelector('.wp-block-post-content')
            || document.querySelector('article');
    };

    const getRefs = () => {
        return {
            tocPanel: document.getElementById('m3-sticky-toc'),
            tocContainer: document.getElementById('m3-toc-container'),
            tocTrigger: document.getElementById('m3-toc-trigger'),
            backToTopTrigger: document.getElementById('m3-back-to-top'),
            actionStack: document.querySelector('.m3-action-stack')
        };
    };

    const ensureShell = () => {
        let {
            tocPanel,
            tocContainer,
            tocTrigger,
            actionStack
        } = getRefs();

        if (!tocTrigger && actionStack) {
            tocTrigger = document.createElement('button');
            tocTrigger.id = 'm3-toc-trigger';
            tocTrigger.type = 'button';
            tocTrigger.className = 'm3-fab m3-fab--extended m3-fab--mobile-hidden toc-ready';
            tocTrigger.setAttribute('aria-label', '目次を表示');
            tocTrigger.innerHTML = [
                '<span class="material-symbols-outlined" aria-hidden="true">list</span>',
                '<span class="m3-fab-text">目次</span>'
            ].join('');
            actionStack.appendChild(tocTrigger);
        }

        if (!tocPanel) {
            tocPanel = document.createElement('div');
            tocPanel.id = 'm3-sticky-toc';
            tocPanel.className = 'm3-sticky-toc';
            tocPanel.setAttribute('aria-hidden', 'true');
            tocPanel.innerHTML = [
                '<div class="m3-sticky-toc__header">',
                '<span class="material-symbols-outlined m3-toc-icon" aria-hidden="true">toc</span>',
                '<span class="m3-sticky-toc__title">目次</span>',
                '<button type="button" id="m3-toc-close" class="m3-toc-close-btn" aria-label="目次を閉じる">',
                '<span class="material-symbols-outlined" aria-hidden="true">close</span>',
                '</button>',
                '</div>',
                '<nav id="m3-toc-container" class="m3-toc-body" aria-label="ページ内目次"></nav>'
            ].join('');
            document.body.appendChild(tocPanel);
            tocContainer = tocPanel.querySelector('#m3-toc-container');
        }

        if (tocPanel.parentElement !== document.body) {
            document.body.appendChild(tocPanel);
        }

        if (!tocContainer && tocPanel) {
            tocContainer = document.createElement('nav');
            tocContainer.id = 'm3-toc-container';
            tocContainer.className = 'm3-toc-body';
            tocContainer.setAttribute('aria-label', 'ページ内目次');
            tocPanel.appendChild(tocContainer);
        }
    };

    const getScrollTarget = (heading) => {
        const header = document.querySelector('.m3-header');
        const headerHeight = header ? header.getBoundingClientRect().height : 0;
        return heading.getBoundingClientRect().top + window.scrollY - Math.max(88, headerHeight + 16);
    };

    const updateActiveLink = () => {
        const { tocContainer } = getRefs();
        if (!tocContainer || !headings.length) return;

        const header = document.querySelector('.m3-header');
        const scrollBase = window.scrollY + Math.max(88, (header ? header.getBoundingClientRect().height : 0) + 24);
        let currentId = headings[0]?.id;

        headings.forEach((heading) => {
            const top = heading.getBoundingClientRect().top + window.scrollY;
            if (scrollBase >= top) currentId = heading.id;
        });

        tocContainer.querySelectorAll('.m3-toc-link').forEach((link) => {
            link.classList.toggle('is-active', link.dataset.tocTarget === currentId);
        });
    };

    const setPanelOpen = (open) => {
        ensureShell();

        const { tocPanel, tocTrigger } = getRefs();
        if (!tocPanel) return;

        let scrim = document.querySelector('.m3-toc-scrim');
        if (!scrim) {
            scrim = document.createElement('div');
            scrim.className = 'm3-toc-scrim';
            document.body.appendChild(scrim);
        }

        tocPanel.classList.toggle('is-active', open);
        tocPanel.setAttribute('aria-hidden', String(!open));
        tocPanel.style.display = 'flex';
        tocPanel.style.opacity = open ? '1' : '0';
        tocPanel.style.visibility = open ? 'visible' : 'hidden';
        tocPanel.style.pointerEvents = open ? 'auto' : 'none';
        tocPanel.style.transform = open ? 'translateY(0) scale(1)' : '';
        tocPanel.style.zIndex = '10001';
        document.body.classList.toggle('is-active-toc', open);
        scrim.classList.toggle('is-active', open);
        tocTrigger?.setAttribute('aria-expanded', String(open));

        if (open) {
            updateActiveLink();
        }
    };

    const togglePanelSafely = () => {
        const now = Date.now();
        // Prevent duplicate toggle events in the same interaction frame.
        if (now - lastToggleAt < 180) return;
        lastToggleAt = now;
        const { tocPanel } = getRefs();
        setPanelOpen(!(tocPanel?.classList.contains('is-active')));
    };

    const bindTriggerFallback = (trigger) => {
        if (!trigger || trigger.dataset.m3TocDirectBound === 'true') return;

        trigger.dataset.m3TocDirectBound = 'true';
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation?.();
            togglePanelSafely();
        }, { capture: true });
    };

    const renderTOC = () => {
        const article = getArticle();
        const {
            backToTopTrigger,
            actionStack
        } = getRefs();

        ensureShell();

        if (!article) return;

        headings = Array.from(article.querySelectorAll(headingSelector)).filter((heading) => {
            if (heading.closest('#m3-sticky-toc, #m3-inline-toc, #comments, #comments-section, .m3-post-comment-toc')) {
                return false;
            }
            return Boolean(normalizeText(heading.textContent || ''));
        });
        const articleTocItems = getArticleTocItems();
        const currentArticleTocItems = articleTocItems.filter((item) => item.current);

        if (!headings.length && !articleTocItems.length) {
            const {
                tocPanel,
                tocTrigger
            } = getRefs();

            // 見出しが無いページでは追従目次を一切表示しない（FAB・パネルとも非表示）
            tocPanel?.classList.remove('is-active');
            document.body.classList.remove('is-active-toc');
            tocTrigger?.classList.remove('toc-ready');
            if (tocTrigger) tocTrigger.style.display = 'none';
            actionStack?.classList.remove('is-has-toc');
            actionStack?.classList.add('is-visible');
            backToTopTrigger?.setAttribute('aria-label', 'ページ上部へ戻る');

            if (!tocReadyDispatched) {
                document.dispatchEvent(new CustomEvent('m3:toc:ready'));
                tocReadyDispatched = true;
            }
            return;
        }

        const {
            tocPanel,
            tocContainer,
            tocTrigger
        } = getRefs();

        // 見出しあり: 非表示化されていた場合に備えて表示を復元
        if (tocTrigger) tocTrigger.style.removeProperty('display');

        tocPanel?.classList.add('m3-sticky-toc--expressive');
        tocTrigger?.classList.add('toc-ready');
        tocTrigger?.setAttribute('aria-label', '目次を表示');
        tocTrigger?.setAttribute('aria-controls', 'm3-sticky-toc');
        tocTrigger?.setAttribute('aria-haspopup', 'menu');
        backToTopTrigger?.setAttribute('aria-label', 'ページ上部へ戻る');
        actionStack?.classList.add('is-has-toc', 'is-visible');
        bindTriggerFallback(tocTrigger);

        const usedIds = new Set(Array.from(document.querySelectorAll('[id]')).map((element) => element.id));
        let currentHeadingIndex = 0;
        headings.forEach((heading, index) => {
            if (!heading.id) {
                const tocItem = currentArticleTocItems[currentHeadingIndex];
                const slug = slugify(heading.textContent || `section-${index + 1}`);
                const baseId = tocItem?.id || (slug ? `toc-${slug}` : `section-${index + 1}`);
                let nextId = baseId;
                let suffix = 2;
                while (usedIds.has(nextId)) {
                    nextId = `${baseId}-${suffix}`;
                    suffix += 1;
                }
                heading.id = nextId;
                usedIds.add(nextId);
            }
            currentHeadingIndex += 1;
        });

        if (!tocContainer) return;

        if (articleTocItems.length) {
            const nextSignature = articleTocItems.map((item) => {
                return `${item.level}|${item.id}|${item.text}|${item.page}|${item.href}`;
            }).join('||');

            if (tocContainer.dataset.m3Signature === nextSignature && tocContainer.dataset.m3State === 'filled') {
                updateActiveLink();
                if (!tocReadyDispatched) {
                    document.dispatchEvent(new CustomEvent('m3:toc:ready'));
                    tocReadyDispatched = true;
                }
                return;
            }

            const list = document.createElement('ul');
            list.className = 'm3-toc-list m3-toc-list--expressive';

            articleTocItems.forEach((tocItem) => {
                const item = document.createElement('li');
                const level = tocItem.level || 'h2';
                item.className = `m3-toc-item m3-toc-item--${level}`;

                const link = document.createElement('a');
                link.className = 'm3-toc-link';
                link.href = tocItem.href || `#${tocItem.id}`;
                link.dataset.tocTarget = tocItem.id;
                link.dataset.tocPage = String(tocItem.page || 1);
                link.textContent = tocItem.text;

                item.appendChild(link);
                list.appendChild(item);
            });

            tocContainer.innerHTML = '';
            tocContainer.appendChild(list);
            tocContainer.dataset.m3Enhanced = 'true';
            tocContainer.dataset.m3Signature = nextSignature;
            tocContainer.dataset.m3State = 'filled';
            updateActiveLink();
            if (!tocReadyDispatched) {
                document.dispatchEvent(new CustomEvent('m3:toc:ready'));
                tocReadyDispatched = true;
            }
            return;
        }

        const nextSignature = headings.map((heading) => {
            return `${heading.tagName.toLowerCase()}|${heading.id}|${normalizeText(heading.textContent || '')}`;
        }).join('||');

        if (tocContainer.dataset.m3Signature === nextSignature && tocContainer.dataset.m3State === 'filled') {
            updateActiveLink();
            if (!tocReadyDispatched) {
                document.dispatchEvent(new CustomEvent('m3:toc:ready'));
                tocReadyDispatched = true;
            }
            return;
        }

        const list = document.createElement('ul');
        list.className = 'm3-toc-list m3-toc-list--expressive';

        headings.forEach((heading) => {
            const item = document.createElement('li');
            const level = heading.tagName.toLowerCase();
            item.className = `m3-toc-item m3-toc-item--${level}`;

            const link = document.createElement('a');
            link.className = 'm3-toc-link';
            link.href = `#${heading.id}`;
            link.dataset.tocTarget = heading.id;
            link.textContent = normalizeText(heading.textContent || '');

            item.appendChild(link);
            list.appendChild(item);
        });

        tocContainer.innerHTML = '';
        tocContainer.appendChild(list);
        tocContainer.dataset.m3Enhanced = 'true';
        tocContainer.dataset.m3Signature = nextSignature;
        tocContainer.dataset.m3State = 'filled';
        updateActiveLink();
        if (!tocReadyDispatched) {
            document.dispatchEvent(new CustomEvent('m3:toc:ready'));
            tocReadyDispatched = true;
        }
    };

    if (!root.dataset.m3TocBound) {
        root.dataset.m3TocBound = 'true';

        const scheduleRender = () => {
            window.clearTimeout(window.__m3TocRenderTimer);
            window.__m3TocRenderTimer = window.setTimeout(() => {
                window.__m3RefreshFloatingTOC?.();
            }, 80);
        };

        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('#m3-toc-trigger');
            if (trigger) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation?.();
                togglePanelSafely();
                return;
            }

            const link = event.target.closest('#m3-toc-container .m3-toc-link');
            if (link) {
                const href = link.getAttribute('href') || '';
                if (!href.startsWith('#')) {
                    setPanelOpen(false);
                    return;
                }

                const targetId = link.dataset.tocTarget || href.slice(1);
                const heading = document.getElementById(targetId);
                if (!heading) return;
                event.preventDefault();
                window.scrollTo({ top: getScrollTarget(heading), behavior: 'smooth' });
                history.replaceState(null, '', `#${targetId}`);
                setPanelOpen(false);
                return;
            }

            const closeButton = event.target.closest('#m3-toc-close');
            if (closeButton) {
                event.preventDefault();
                setPanelOpen(false);
                return;
            }

            const { tocPanel, tocTrigger } = getRefs();
            if (!tocPanel?.classList.contains('is-active')) return;

            const clickedPanel = tocPanel.contains(event.target);
            const clickedTrigger = tocTrigger?.contains(event.target);
            if (!clickedPanel && !clickedTrigger) {
                setPanelOpen(false);
            }
        }, true);

        document.addEventListener('m3:toc:toggle', (event) => {
            event.preventDefault?.();
            event.stopPropagation?.();
            event.stopImmediatePropagation?.();
            togglePanelSafely();
        }, true);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') setPanelOpen(false);
        });

        window.addEventListener('scroll', updateActiveLink, { passive: true });
        window.addEventListener('resize', scheduleRender, { passive: true });
        window.addEventListener('pageshow', scheduleRender);

        const observer = new MutationObserver((mutations) => {
            const isOnlyTocMutation = mutations.every((mutation) => {
                const target = mutation.target;
                return target instanceof Element
                    && Boolean(target.closest('#m3-sticky-toc, .m3-toc-scrim, #m3-toc-trigger'));
            });

            if (isOnlyTocMutation) return;
            scheduleRender();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    window.__m3RefreshFloatingTOC = renderTOC;
    renderTOC();
    setTimeout(renderTOC, 120);
    setTimeout(renderTOC, 600);
}
