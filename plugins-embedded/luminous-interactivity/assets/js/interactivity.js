document.addEventListener('DOMContentLoaded', () => {
    // 1. スポイラー機能
    const spoilers = document.querySelectorAll('.node-spoiler');
    spoilers.forEach(spoiler => {
        const content = spoiler.querySelector('.node-spoiler__content');
        if (!spoiler.classList.contains('is-revealed')) {
            spoiler.setAttribute('aria-label', 'ネタバレを表示（クリックまたはEnterで展開）');
            if (content) {
                content.setAttribute('aria-hidden', 'true');
            }
        }
        spoiler.addEventListener('click', () => {
            spoiler.classList.add('is-revealed');
            spoiler.setAttribute('aria-expanded', 'true');
            spoiler.removeAttribute('aria-label');
            if (content) {
                content.removeAttribute('aria-hidden');
            }
        });
        spoiler.setAttribute('tabindex', '0');
        spoiler.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                spoiler.click();
            }
        });
    });

    // 2. CERO Z 警告ダイアログ
    const ceroDialog = document.getElementById('cero-z-dialog');
    if (ceroDialog) {
        const hasAccepted = sessionStorage.getItem('cero_z_accepted');
        if (!hasAccepted) {
            ceroDialog.showModal();
        }
        
        const acceptBtn = document.getElementById('cero-z-accept');
        const declineBtn = document.getElementById('cero-z-decline');

        if (acceptBtn) {
            acceptBtn.addEventListener('click', () => {
                sessionStorage.setItem('cero_z_accepted', 'true');
                ceroDialog.close();
            });
        }

        if (declineBtn) {
            declineBtn.addEventListener('click', () => {
                if (window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.href = '/';
                }
            });
        }
    }
});
