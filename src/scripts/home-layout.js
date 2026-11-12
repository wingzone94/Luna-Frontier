function isLatestHomePage() {
    const body = document.body;
    return (body.classList.contains('home')
        || body.classList.contains('blog')
        || body.classList.contains('front-page'))
        && !body.classList.contains('paged');
}

export function initSectionArchiveLinks() {
    if (!isLatestHomePage()) return;

    const spotlightMoreLink = document.querySelector('.special-features .m3-headlines__more');
    if (!spotlightMoreLink) return;

    const href = spotlightMoreLink.getAttribute('href');
    if (!href || href === '#') {
        spotlightMoreLink.href = `${window.location.origin}/spotlight/`;
    }
}

export function initHeroInfoBubble() {
    const trigger = document.getElementById('m3-hero-reading-badge');
    if (!trigger) return;

    trigger.classList.remove('is-info-active');
    trigger.removeAttribute('role');
    trigger.removeAttribute('tabindex');
    trigger.removeAttribute('aria-expanded');
    trigger.removeAttribute('aria-controls');
    document.getElementById('m3-hero-info-panel')?.classList.remove('is-active');
}

export function initHomeDateTabs() {
    if (!isLatestHomePage()) return;

    const nav = document.querySelector('.lf-home__date-tabs');
    if (!nav) return;

    const tabs = Array.from(nav.querySelectorAll('.lf-home__date-tab'));
    if (!tabs.length) return;

    // 選択中のタブを視野内にスクロール（モバイル等で横スクロール位置を調整）
    const activeTab = nav.querySelector('.lf-home__date-tab[aria-current="page"]');
    if (activeTab) {
        const navRect = nav.getBoundingClientRect();
        const tabRect = activeTab.getBoundingClientRect();
        if (tabRect.left < navRect.left || tabRect.right > navRect.right) {
            nav.scrollLeft += (tabRect.left - navRect.left) - (navRect.width / 2) + (tabRect.width / 2);
        }
    }

    // キーボード操作アクセシビリティ（左右矢印キーでタブ間フォーカス移動）
    nav.addEventListener('keydown', (event) => {
        const target = event.target;
        if (!target || !target.classList.contains('lf-home__date-tab')) return;

        const currentIndex = tabs.indexOf(target);
        if (currentIndex === -1) return;

        let nextIndex = null;
        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
            nextIndex = (currentIndex + 1) % tabs.length;
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
            nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
        } else if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = tabs.length - 1;
        }

        if (nextIndex !== null) {
            event.preventDefault();
            tabs[nextIndex].focus();
        }
    });
}
