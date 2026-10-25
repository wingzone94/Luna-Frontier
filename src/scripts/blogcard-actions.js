/**
 * Blog Card — copy link & native share actions.
 *
 * The share button invokes the OS share sheet through the Web Share API
 * (navigator.share), which covers Android, iOS, Windows, macOS Safari and
 * ChromeOS. Where the API is missing it falls back to copying title + URL.
 * The copy button always copies the card's link to the clipboard.
 *
 * These buttons live above the card's stretched link (a higher z-index), so
 * they are handled independently here and never trigger card navigation.
 */
(function () {
    const supportsShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

    // Reveal the share button only where the native share sheet exists.
    if (supportsShare) {
        document.documentElement.classList.add('has-web-share');
    }

    // Absolute timestamps also keep cached pages accurate. No timer is needed without scheduled cards.
    const countdowns = [...document.querySelectorAll('.m3-blogcard__countdown[data-publish-at]')];
    if (countdowns.length) {
        const updateCountdowns = () => {
            const now = Date.now();
            countdowns.forEach((element) => {
                const remaining = Number(element.dataset.publishAt) * 1000 - now;
                if (!Number.isFinite(remaining)) return;
                const minutes = Math.max(0, Math.ceil(remaining / 60000));
                let text;
                if (remaining > 86400000) {
                    text = `公開まであと${Math.floor(remaining / 86400000)}日`;
                } else if (minutes > 0) {
                    text = `公開まであと${Math.floor(minutes / 60)}時間${minutes % 60}分`;
                } else {
                    text = '公開予定時刻になりました。ページを更新してご確認ください。';
                }
                if (element.textContent !== text) element.textContent = text;
            });
        };
        updateCountdowns();
        window.setInterval(updateCountdowns, 1000);
        window.addEventListener('pageshow', updateCountdowns);
    }

    const iconResetTimers = new WeakMap();
    let toastTimer = 0;

    const showToast = (message) => {
        let toast = document.querySelector('.node-copy-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'node-copy-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.setAttribute('aria-atomic', 'true');
            document.body.appendChild(toast);
        }
        toast.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">check_circle</span><span></span>';
        toast.querySelector('span:last-child').textContent = message;

        window.clearTimeout(toastTimer);
        toast.classList.remove('is-visible');
        window.requestAnimationFrame(() => toast.classList.add('is-visible'));
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 2600);
    };

    const copyText = async (text) => {
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                return true;
            }
            throw new Error('clipboard unavailable');
        } catch (err) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            let ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(textarea);
            return ok;
        }
    };

    const flashSuccess = (btn) => {
        const icon = btn.querySelector('.m3-blogcard__action-icon');
        if (icon && !btn.dataset.originalIcon) {
            btn.dataset.originalIcon = icon.textContent;
        }

        btn.classList.add('is-success');
        if (icon) icon.textContent = 'check';

        const previous = iconResetTimers.get(btn);
        if (previous) window.clearTimeout(previous);
        const timer = window.setTimeout(() => {
            btn.classList.remove('is-success');
            if (icon && btn.dataset.originalIcon) icon.textContent = btn.dataset.originalIcon;
        }, 2000);
        iconResetTimers.set(btn, timer);

        if (typeof gsap !== 'undefined') {
            gsap.fromTo(btn, { scale: 0.85 }, { scale: 1, duration: 0.5, ease: 'elastic.out(1, 0.35)' });
        }
    };

    // Animate a card action button out, then drop it from the DOM.
    const removeCardAction = (btn) => {
        btn.disabled = true;
        btn.setAttribute('aria-hidden', 'true');
        btn.style.pointerEvents = 'none';
        if (typeof gsap !== 'undefined') {
            gsap.to(btn, {
                scale: 0,
                opacity: 0,
                width: 0,
                marginInline: 0,
                duration: 0.3,
                ease: 'back.in(1.4)',
                onComplete: () => btn.remove(),
            });
        } else {
            btn.remove();
        }
    };

    const getPayload = (btn) => {
        const url = btn.dataset.url || window.location.href;
        const title = (btn.dataset.shareTitle || '').replace(/\s+/g, ' ').trim();
        return { url, title };
    };

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.m3-blogcard__action');
        if (!btn) return;

        // Never let the card's stretched link fire.
        e.preventDefault();
        e.stopPropagation();

        const { url, title } = getPayload(btn);

        if (btn.classList.contains('m3-blogcard__action--share')) {
            // Detect at click time (not module load) so the OS share sheet is used
            // whenever the browser exposes it. Web Share API is only available in a
            // secure context (HTTPS / localhost); over plain http it is undefined and
            // we fall back to copying the link.
            const canNativeShare = typeof navigator.share === 'function';
            if (canNativeShare) {
                try {
                    const payload = title ? { title, url } : { url };
                    if (typeof navigator.canShare !== 'function' || navigator.canShare(payload)) {
                        await navigator.share(payload);
                        return;
                    }
                } catch (err) {
                    if (err && err.name === 'AbortError') return; // user closed the sheet
                    // fall through to clipboard fallback on real errors
                }
            }
            const shared = await copyText(title ? `${title}\n${url}` : url);
            if (shared) {
                flashSuccess(btn);
                showToast(canNativeShare ? 'タイトルとURLをコピーしました' : 'リンクをコピーしました（共有シートはHTTPSで有効）');
            }
            return;
        }

        // Copy link button — remove it once the link is on the clipboard.
        const copied = await copyText(url);
        if (copied) {
            showToast('リンクをコピーしました');
            removeCardAction(btn);
        }
    }, { capture: true });
})();
