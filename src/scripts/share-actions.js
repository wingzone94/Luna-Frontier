/**
 * System Share & Action Logic
 * 
 * Note: FAB visibility and scroll logic has been moved to main.js to avoid conflicts.
 * This script now only handles sharing functionalities.
 */
(function() {
    const copyResetTimers = new WeakMap();
    let copyToastTimer = 0;
    const compactPreferenceKey = 'node-share-compact';

    const readCompactPreference = () => {
        try {
            return window.localStorage.getItem(compactPreferenceKey) === 'true';
        } catch (error) {
            return false;
        }
    };

    const writeCompactPreference = (isCompact) => {
        try {
            window.localStorage.setItem(compactPreferenceKey, String(isCompact));
        } catch (error) {
            // Storage may be unavailable in private browsing; the current page still works.
        }
    };

    const initShareViewToggles = () => {
        const isDesktop = window.matchMedia('(min-width: 600px)');

        document.querySelectorAll('[data-share-view-toggle]').forEach((toggle) => {
            const section = toggle.closest('.m3-share-section');
            const buttons = section?.querySelector('[data-share-buttons]');
            if (!buttons || toggle.dataset.shareViewReady === 'true') return;

            const applyCompactState = (isCompact) => {
                buttons.classList.toggle('is-compact', isCompact);
                toggle.setAttribute('aria-checked', String(isCompact));
            };

            toggle.dataset.shareViewReady = 'true';
            applyCompactState(readCompactPreference());
            toggle.addEventListener('click', () => {
                if (!isDesktop.matches) return;

                const nextState = toggle.getAttribute('aria-checked') !== 'true';
                applyCompactState(nextState);
                writeCompactPreference(nextState);
            });
        });
    };

    // システムシェアボタンはHTMLでは hidden で出力し、
    // Web Share API 対応ブラウザ（iOS/Android/macOS Safari/Windows Chrome・Edge等）でのみ表示する
    const revealSystemShareButtons = () => {
        if (typeof navigator.share !== 'function') return;
        document.querySelectorAll('.m3-share-btn--system[hidden]').forEach((btn) => btn.removeAttribute('hidden'));
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            revealSystemShareButtons();
            initShareViewToggles();
        });
    } else {
        revealSystemShareButtons();
        initShareViewToggles();
    }

    const showCopyToast = () => {
        let toast = document.querySelector('.node-copy-toast');

        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'node-copy-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.innerHTML = '<span class="material-symbols-outlined" data-icon="check_circle" aria-hidden="true"></span><span>タイトルとURLをコピーしました</span>';
            document.body.appendChild(toast);
        }

        window.clearTimeout(copyToastTimer);
        toast.classList.remove('is-visible');
        window.requestAnimationFrame(() => toast.classList.add('is-visible'));
        copyToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 2600);
    };

    const openSharePopup = (link) => {
        if (window.matchMedia('(max-width: 599px)').matches) return false;

        const width = 640;
        const height = 720;
        const dualScreenLeft = window.screenLeft ?? window.screenX ?? 0;
        const dualScreenTop = window.screenTop ?? window.screenY ?? 0;
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || screen.width;
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || screen.height;
        const left = Math.max(0, dualScreenLeft + (viewportWidth - width) / 2);
        const top = Math.max(0, dualScreenTop + (viewportHeight - height) / 2);
        const features = `scrollbars=yes,resizable=yes,width=${width},height=${height},left=${left},top=${top}`;
        const popup = window.open(link.href, 'node-share-window', features);

        if (!popup) return false;
        popup.opener = null;
        popup.focus();
        return true;
    };

    const getSharePayload = (btn) => {
        const url = btn.dataset.url || window.location.href;
        const rawTitle = btn.dataset.shareTitle || document.querySelector('meta[property="og:title"]')?.content || document.title || '';
        const title = rawTitle.replace(/\s+/g, ' ').trim();

        return {
            title,
            url,
            text: title ? `${title} ${url}` : url,
        };
    };

    const resetCopyFeedback = (btn) => {
        const copyIcon = btn.querySelector('.m3-copy-icon') || btn.querySelector('.material-symbols-outlined');
        const copyLabel = btn.querySelector('.m3-copy-label') || btn.querySelector('.m3-share-btn__label');

        btn.classList.remove('is-success', 'is-copy-armed');
        btn.dataset.copyStage = '';
        if (copyIcon && btn.dataset.copyOriginalIcon) {
            copyIcon.dataset.icon = btn.dataset.copyOriginalIcon;
            copyIcon.textContent = '';
        }
        if (copyLabel && btn.dataset.copyOriginalLabel) copyLabel.textContent = btn.dataset.copyOriginalLabel;
        if (btn.dataset.copyOriginalAriaLabel) {
            btn.setAttribute('aria-label', btn.dataset.copyOriginalAriaLabel);
        } else {
            btn.removeAttribute('aria-label');
        }
    };

    const executeCopy = async (btn, text, options = {}) => {
        const targetText = text || getSharePayload(btn).text;
        let success = false;
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(targetText);
                success = true;
            } else { throw new Error(); }
        } catch (err) {
            const textarea = document.createElement('textarea');
            textarea.value = targetText; textarea.style.position = 'fixed'; textarea.style.opacity = '0';
            document.body.appendChild(textarea); textarea.select();
            try { success = document.execCommand('copy'); } catch (e) { success = false; }
            document.body.removeChild(textarea);
        }
        if (success) {
            const previousTimer = copyResetTimers.get(btn);
            if (previousTimer) window.clearTimeout(previousTimer);

            btn.classList.add('is-success');
            btn.classList.toggle('is-copy-armed', Boolean(options.armed));
            const copyIcon = btn.querySelector('.m3-copy-icon') || btn.querySelector('.material-symbols-outlined');
            const copyLabel = btn.querySelector('.m3-copy-label') || btn.querySelector('.m3-share-btn__label');
            if (copyIcon && !btn.dataset.copyOriginalIcon) btn.dataset.copyOriginalIcon = copyIcon.dataset.icon || '';
            if (copyLabel && !btn.dataset.copyOriginalLabel) btn.dataset.copyOriginalLabel = copyLabel.textContent;
            if (!Object.prototype.hasOwnProperty.call(btn.dataset, 'copyOriginalAriaLabel')) {
                btn.dataset.copyOriginalAriaLabel = btn.getAttribute('aria-label') || '';
            }
            if (copyIcon) {
                copyIcon.dataset.icon = 'check';
                copyIcon.textContent = '';
            }
            if (copyLabel) copyLabel.textContent = options.label || 'コピーしました！';
            btn.setAttribute('aria-label', options.ariaLabel || options.label || 'コピーしました');
            if (typeof gsap !== 'undefined') gsap.fromTo(btn, { scale: 0.92 }, { scale: 1, duration: 0.5, ease: "elastic.out(1, 0.3)" });
            const timer = window.setTimeout(() => resetCopyFeedback(btn), options.resetAfter || 2500);
            copyResetTimers.set(btn, timer);
        }

        return success;
    };

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.m3-share-btn');
        if (!btn) return;
        const isSystemShare = btn.id === 'm3-system-share-trigger' || btn.classList.contains('m3-share-btn--system');
        const isCopyBtn = btn.id === 'm3-copy-trigger' || btn.classList.contains('m3-share-btn--copy');
        const isShareLink = btn.matches('a[data-share-popup="true"]');

        if (isShareLink && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey && e.button === 0) {
            if (openSharePopup(btn)) {
                e.preventDefault();
                e.stopPropagation();
            }
            return;
        }

        if (isSystemShare || isCopyBtn) {
            e.stopImmediatePropagation(); e.preventDefault();
            const sharePayload = getSharePayload(btn);
            if (isSystemShare && navigator.share) {
                try { await navigator.share({ title: sharePayload.title || document.title, url: sharePayload.url }); }
                catch (err) { if (err.name !== 'AbortError') executeCopy(btn, sharePayload.text); }
            } else if (isCopyBtn) {
                if (btn.dataset.copyStage === 'url') {
                    btn.dataset.copyStage = '';
                    const titleAndUrl = sharePayload.title
                        ? `${sharePayload.title}\n${sharePayload.url}`
                        : sharePayload.url;
                    const copied = await executeCopy(btn, titleAndUrl, {
                        label: '両方コピー済み',
                        ariaLabel: 'タイトルとURLをコピーしました',
                    });
                    if (copied) showCopyToast();
                } else {
                    const copied = await executeCopy(btn, sharePayload.url, {
                        label: 'もう一度押す',
                        ariaLabel: 'URLをコピーしました。もう一度押すとタイトルとURLをコピーします',
                        armed: true,
                        resetAfter: 4000,
                    });
                    if (copied) btn.dataset.copyStage = 'url';
                }
            } else {
                executeCopy(btn, sharePayload.text);
            }
        }
    }, { capture: true });
})();
