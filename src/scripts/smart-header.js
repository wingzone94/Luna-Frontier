export function initSmartHeader() {
    const header = document.querySelector('.m3-header');
    if (!header) return;

    let lastScrollY = window.scrollY || window.pageYOffset;
    let ticking = false;
    const isSearchActive = () => document.querySelector('.m3-search-bar.is-active') !== null;
    const isModalActive = () => document.querySelector('.m3-modal.is-active') !== null;

    const adminBar = document.body.classList.contains('admin-bar')
        ? document.getElementById('wpadminbar')
        : null;

    const syncAdminBarOffset = () => {
        if (!adminBar) return;

        // モバイル幅(600px以下)では管理バーが position:absolute になり
        // スクロールと共に画面外へ出るため、ヘッダーの top を
        // 管理バーの「見えている下端」に追従させる
        if (getComputedStyle(adminBar).position === 'absolute') {
            header.style.top = `${Math.max(0, Math.round(adminBar.getBoundingClientRect().bottom))}px`;
        } else if (header.style.top) {
            header.style.top = '';
        }
    };

    // 読了ゲージ（.m3-header__progress-container）は fixed でヘッダーの外に
    // 置かれているため、位置を計算式で合わせるとヘッダーとズレる余地が残る
    // （管理バーの追従・iOS の env(safe-area-inset-top) の反映タイミング）。
    // ヘッダーの実寸を測って渡し、CSS 側はそれを使う。
    //
    // offsetTop / offsetHeight は transform の影響を受けないので、
    // ヘッダーが is-hidden で退避している最中でも「本来の下端」が取れる
    // （退避中の位置は CSS の .is-hidden ルールが引き続き受け持つ）。
    // 退避時（.is-hidden）はヘッダーの上端＝セーフエリアの下へ置く。
    // セーフエリアの実寸はヘッダー全体と中身（--inner は 64px 固定）の差で取る。
    const headerInner = header.querySelector('.m3-header__inner');
    let lastGaugeTop = null;
    let lastGaugeTopHidden = null;
    const syncGaugeTop = () => {
        const base = header.offsetTop;
        const innerHeight = headerInner ? headerInner.offsetHeight : 64;
        const safeArea = Math.max(0, header.offsetHeight - innerHeight);

        const shown = `${Math.max(0, Math.round(base + header.offsetHeight - 4))}px`;
        if (shown !== lastGaugeTop) {
            lastGaugeTop = shown;
            document.body.style.setProperty('--node-gauge-top', shown);
        }

        const hidden = `${Math.max(0, Math.round(base + safeArea))}px`;
        if (hidden !== lastGaugeTopHidden) {
            lastGaugeTopHidden = hidden;
            document.body.style.setProperty('--node-gauge-top-hidden', hidden);
        }
    };

    const updateHeader = () => {
        const currentScrollY = window.scrollY || window.pageYOffset;

        syncAdminBarOffset();
        syncGaugeTop();

        if (currentScrollY <= 80) {
            header.classList.remove('is-hidden');
        } else if (currentScrollY > lastScrollY && !isSearchActive() && !isModalActive()) {
            if (currentScrollY - lastScrollY > 10) {
                header.classList.add('is-hidden');
            }
        } else if (lastScrollY - currentScrollY > 10) {
            header.classList.remove('is-hidden');
        }

        lastScrollY = currentScrollY;
        ticking = false;
    };

    window.addEventListener('scroll', () => {
        if (!ticking) {
            window.requestAnimationFrame(updateHeader);
            ticking = true;
        }
    }, { passive: true });

    if (adminBar) {
        syncAdminBarOffset();
        window.addEventListener('resize', syncAdminBarOffset, { passive: true });
    }

    syncGaugeTop();
    window.addEventListener('resize', syncGaugeTop, { passive: true });
    window.addEventListener('orientationchange', syncGaugeTop, { passive: true });
}
