/**
 * Floating action stack (back-to-top, comments, AI, TOC trigger visibility).
 */

import { isTabletPcMode } from './device-mode';
import { isIndexOrArchiveView, isSinglePostView } from './page-state';

const MOBILE_DOCK_QUERY = '(max-width: 767px)';
const DESKTOP_STACK_QUERY = '(min-width: 1001px)';

export function initFloatingActions() {
    const actionStack = document.querySelector('.m3-action-stack');
    if (!actionStack) return;

    // Template (floating-actions.php) outputs .is-singular / pullTab / tocTrigger only on single-post.
    const isSingle = document.body.classList.contains('single-post');
    const showBackToTopContext = isSingle || isSinglePostView() || isIndexOrArchiveView();
    const backToTop = document.getElementById('m3-back-to-top');
    const tocTrigger = document.getElementById('m3-toc-trigger');
    const tocPanel = document.getElementById('m3-sticky-toc');
    const readingBody = document.querySelector('.m3-article__body');
    const pullTab = document.getElementById('m3-action-pull-tab');
    const form = document.querySelector('#respond');

    backToTop?.addEventListener('click', (event) => {
        event.preventDefault();
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
    });

    if (!isSingle) {
        actionStack.classList.remove('is-singular', 'has-pull-tab', 'is-collapsed', 'is-has-toc');
        pullTab?.remove();
        tocTrigger?.remove();
        tocPanel?.classList.remove('is-active');
        tocPanel?.remove();
        document.querySelector('.m3-toc-scrim')?.remove();
        document.body.classList.remove('is-active-toc');
    }

    const mobileDockMedia = window.matchMedia(MOBILE_DOCK_QUERY);
    const desktopMedia = window.matchMedia(DESKTOP_STACK_QUERY);

    let isCollapsed = false;
    let hasReachedReadingZone = !readingBody;
    let isRespondIntersecting = false;
    let rafId = 0;
    let lastVisible = null;

    const syncPullTabA11y = () => {
        if (!isSingle || !pullTab) return;

        const tabletPc = isTabletPcMode();
        pullTab.hidden = tabletPc;
        const isMobileDock = !tabletPc && mobileDockMedia.matches;
        if (!isMobileDock && isCollapsed) {
            isCollapsed = false;
            actionStack.classList.remove('is-collapsed');
            pullTab.setAttribute('aria-expanded', 'true');
            pullTab.setAttribute('aria-label', '追従ボタンを折りたたむ');
        }

        const shouldHide = isMobileDock && isCollapsed;
        if (shouldHide) {
            const active = document.activeElement;
            if (active && (backToTop?.contains(active) || tocTrigger?.contains(active))) {
                pullTab.focus({ preventScroll: true });
            }
            backToTop?.setAttribute('tabindex', '-1');
            tocTrigger?.setAttribute('tabindex', '-1');
            backToTop?.setAttribute('aria-hidden', 'true');
            tocTrigger?.setAttribute('aria-hidden', 'true');
        } else {
            backToTop?.removeAttribute('tabindex');
            tocTrigger?.removeAttribute('tabindex');
            backToTop?.removeAttribute('aria-hidden');
            tocTrigger?.removeAttribute('aria-hidden');
        }
    };

    const setCollapsed = (collapsed) => {
        if (!isSingle || !pullTab) return;
        isCollapsed = Boolean(collapsed) && !isTabletPcMode() && mobileDockMedia.matches;
        actionStack.classList.toggle('is-collapsed', isCollapsed);
        pullTab.setAttribute('aria-expanded', String(!isCollapsed));
        pullTab.setAttribute(
            'aria-label',
            isCollapsed ? '追従ボタンを展開する' : '追従ボタンを折りたたむ'
        );
        syncPullTabA11y();
    };

    const applyVisibility = (visible) => {
        if (
            visible === lastVisible
            && actionStack.hidden === !visible
            && actionStack.classList.contains('is-visible') === visible
        ) {
            return;
        }
        lastVisible = visible;
        actionStack.hidden = !visible;
        actionStack.classList.toggle('is-visible', visible);
    };

    const updateVisibility = () => {
        syncPullTabA11y();

        if (!showBackToTopContext) {
            applyVisibility(false);
            return;
        }

        const isDesktop = isTabletPcMode() || desktopMedia.matches;
        let visible = true;

        if (!isDesktop) {
            if (!isSingle) {
                visible = false;
            } else {
                const scrollY = window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0;
                const heroPassed = document.body.classList.contains('is-hero-passed');

                if (!('IntersectionObserver' in window) && readingBody) {
                    const bounds = readingBody.getBoundingClientRect();
                    hasReachedReadingZone = bounds.top <= window.innerHeight - 72;
                }
                if (!('IntersectionObserver' in window) && form) {
                    const formBounds = form.getBoundingClientRect();
                    isRespondIntersecting = formBounds.top < window.innerHeight && formBounds.bottom > 0;
                }

                // Give the comment form the viewport while visible or focused; keep TOP/TOC available before and after.
                const editing = Boolean(
                    form && (form.contains(document.activeElement) || isRespondIntersecting)
                );
                const pastTopHero = hasReachedReadingZone && (scrollY > 120 || heroPassed);
                visible = pastTopHero && !editing;
            }
        }

        applyVisibility(visible);
    };

    const scheduleVisibilityUpdate = () => {
        if (rafId) return;
        rafId = window.requestAnimationFrame(() => {
            rafId = 0;
            updateVisibility();
        });
    };

    if (isSingle && pullTab) {
        actionStack.classList.add('has-pull-tab');
        syncPullTabA11y();

        pullTab.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            setCollapsed(!isCollapsed);
        });

        const handleMobileDockChange = () => {
            if (!mobileDockMedia.matches) {
                setCollapsed(false);
            } else {
                syncPullTabA11y();
            }
            scheduleVisibilityUpdate();
        };

        if (typeof mobileDockMedia.addEventListener === 'function') {
            mobileDockMedia.addEventListener('change', handleMobileDockChange);
        } else if (typeof mobileDockMedia.addListener === 'function') {
            mobileDockMedia.addListener(handleMobileDockChange);
        }
    }

    if (typeof desktopMedia.addEventListener === 'function') {
        desktopMedia.addEventListener('change', scheduleVisibilityUpdate);
    } else if (typeof desktopMedia.addListener === 'function') {
        desktopMedia.addListener(scheduleVisibilityUpdate);
    }

    if (isSingle && 'IntersectionObserver' in window) {
        if (readingBody) {
            const bodyObserver = new IntersectionObserver(
                (entries) => {
                    const entry = entries[entries.length - 1];
                    if (!entry) return;
                    // Once the article body enters above the bottom zone (or scrolls past top),
                    // keep back-to-top visible through related posts, comments, and footer.
                    hasReachedReadingZone = entry.isIntersecting || entry.boundingClientRect.top <= 0;
                    scheduleVisibilityUpdate();
                },
                { threshold: 0, rootMargin: '0px 0px -72px 0px' }
            );
            bodyObserver.observe(readingBody);
        }

        if (form) {
            const respondObserver = new IntersectionObserver(
                (entries) => {
                    const entry = entries[entries.length - 1];
                    if (!entry) return;
                    isRespondIntersecting = entry.isIntersecting;
                    scheduleVisibilityUpdate();
                },
                { threshold: 0 }
            );
            respondObserver.observe(form);
        }
    }

    updateVisibility();

    if (typeof MutationObserver === 'function') {
        // expressive-toc.js re-adds .is-visible on delayed re-renders; keep hidden/visible synchronized with lastVisible.
        const classSyncObserver = new MutationObserver(() => {
            if (typeof lastVisible !== 'boolean') return;
            if (actionStack.hidden !== !lastVisible || actionStack.classList.contains('is-visible') !== lastVisible) {
                applyVisibility(lastVisible);
            }
        });
        classSyncObserver.observe(actionStack, { attributes: true, attributeFilter: ['class', 'hidden'] });
    }

    window.addEventListener('scroll', scheduleVisibilityUpdate, { passive: true });
    window.addEventListener('resize', scheduleVisibilityUpdate, { passive: true });
    document.addEventListener('m3:toc:ready', updateVisibility);
    document.addEventListener('focusin', scheduleVisibilityUpdate);
    document.addEventListener('focusout', () => queueMicrotask(scheduleVisibilityUpdate));
}
