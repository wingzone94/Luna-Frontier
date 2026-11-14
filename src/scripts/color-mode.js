import SunCalc from 'suncalc';

export function initColorMode() {
    const MODE_KEY = 'node-color-mode'; // 'auto' | 'manual'
    const THEME_KEY = 'node_theme';     // 'light' | 'dark'
    const DARK_CLASS = 'is-dark';
    const FALLBACK_DARK_HOUR = 18;
    const FALLBACK_LIGHT_HOUR = 6;

    const TZ_COORDS = {
        'Asia/Tokyo': [35.6762, 139.6503],
        'Asia/Osaka': [34.6937, 135.5023],
        'Asia/Sapporo': [43.0618, 141.3545],
        'Asia/Seoul': [37.5665, 126.9780],
        'Asia/Shanghai': [31.2304, 121.4737],
        'Asia/Hong_Kong': [22.3193, 114.1694],
        'Asia/Singapore': [1.3521, 103.8198],
        'Asia/Taipei': [25.0330, 121.5654],
        'Asia/Bangkok': [13.7563, 100.5018],
        'Asia/Jakarta': [-6.2088, 106.8456],
        'Asia/Kolkata': [28.6139, 77.2090],
        'Asia/Dubai': [25.2048, 55.2708],
        'Europe/London': [51.5074, -0.1278],
        'Europe/Paris': [48.8566, 2.3522],
        'Europe/Berlin': [52.5200, 13.4050],
        'Europe/Rome': [41.9028, 12.4964],
        'Europe/Madrid': [40.4168, -3.7038],
        'Europe/Amsterdam': [52.3676, 4.9041],
        'Europe/Stockholm': [59.3293, 18.0686],
        'Europe/Moscow': [55.7558, 37.6173],
        'America/New_York': [40.7128, -74.0060],
        'America/Chicago': [41.8781, -87.6298],
        'America/Denver': [39.7392, -104.9903],
        'America/Los_Angeles': [34.0522, -118.2437],
        'America/Toronto': [43.6532, -79.3832],
        'America/Vancouver': [49.2827, -123.1207],
        'America/Sao_Paulo': [-23.5505, -46.6333],
        'America/Mexico_City': [19.4326, -99.1332],
        'Australia/Sydney': [-33.8688, 151.2093],
        'Australia/Melbourne': [-37.8136, 144.9631],
        'Pacific/Auckland': [-36.8485, 174.7633],
        'Africa/Cairo': [30.0444, 31.2357],
        'Africa/Johannesburg': [-26.2041, 28.0473],
    };
    const FALLBACK_COORDS = [35.6762, 139.6503];

    const TOGGLE_SELECTOR = '#theme-toggle, #m3-theme-toggle-handy';
    if (!document.querySelector(TOGGLE_SELECTOR)) return;

    function getCoordsFromTimezone() {
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        return TZ_COORDS[tz] ?? FALLBACK_COORDS;
    }

    const VALID_MODES = ['auto', 'manual', 'os'];
    let mode = VALID_MODES.includes(localStorage.getItem(MODE_KEY))
        ? localStorage.getItem(MODE_KEY)
        : 'auto';
    let autoTimer = null;
    let clickTimer = null;
    let longPressTimer = null;
    let longPressFired = false;
    let lastTouchTime = 0;
    const DOUBLE_CLICK_MS = 300;
    const LONG_PRESS_MS = 500;

    const mql = window.matchMedia('(prefers-color-scheme: dark)');

    function setIcon(isDark) {
        document
            .querySelectorAll('#theme-toggle .material-symbols-outlined, #m3-theme-toggle-handy .material-symbols-outlined')
            .forEach(icon => {
                const iconName = isDark ? 'dark_mode' : 'light_mode';
                if (window.NodeMaterialSymbols) {
                    window.NodeMaterialSymbols.setIcon(icon, iconName);
                    return;
                }
                icon.dataset.icon = iconName;
                icon.textContent = '';
            });
    }

    function applyDark(isDark) {
        const theme = isDark ? 'dark' : 'light';
        document.documentElement.classList.toggle(DARK_CLASS, isDark);
        document.body.classList.toggle(DARK_CLASS, isDark);
        document.documentElement.setAttribute('data-theme', theme);
        document.body.setAttribute('data-theme', theme);
        try {
            localStorage.setItem(THEME_KEY, theme);
        } catch (e) {}
        setIcon(isDark);
    }

    function getValidSunTime(value, fallbackHour) {
        if (value instanceof Date && Number.isFinite(Number(value))) {
            return value;
        }

        const now = new Date();
        return new Date(now.getFullYear(), now.getMonth(), now.getDate(), fallbackHour);
    }

    function applyAuto() {
        clearTimeout(autoTimer);
        const [lat, lon] = getCoordsFromTimezone();
        const now = new Date();
        const times = SunCalc.getTimes(now, lat, lon);
        const sunrise = getValidSunTime(times.sunrise, FALLBACK_LIGHT_HOUR);
        const sunset = getValidSunTime(times.sunset, FALLBACK_DARK_HOUR);
        const isDark = now < sunrise || now > sunset;

        applyDark(isDark);

        const tomorrow = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1);
        const tomorrowTimes = SunCalc.getTimes(tomorrow, lat, lon);
        const nextSunrise = getValidSunTime(tomorrowTimes.sunrise, FALLBACK_LIGHT_HOUR);
        const next = isDark ? (now < sunrise ? sunrise : nextSunrise) : sunset;
        const delay = Math.max(Number(next) - Number(now), 60000);
        autoTimer = setTimeout(applyAuto, delay);
    }

    function applyOS() {
        applyDark(mql.matches);
    }

    function showSyncNotice() {
        const message = '外観設定をシステムと同期しました';
        const isMobile = window.matchMedia('(max-width: 600px)').matches;

        document.querySelectorAll('.m3-color-sync-notice').forEach(el => el.remove());

        const notice = document.createElement('div');
        notice.className = 'm3-color-sync-notice ' + (isMobile ? 'm3-color-sync-notice--mobile' : 'm3-color-sync-notice--header');
        notice.setAttribute('role', 'status');
        notice.setAttribute('aria-live', 'polite');
        notice.innerHTML = `
            <span class="material-symbols-outlined" data-icon="sync" aria-hidden="true"></span>
            <span class="m3-color-sync-notice__text">${message}</span>
        `;

        document.body.appendChild(notice);

        requestAnimationFrame(() => notice.classList.add('is-visible'));
        setTimeout(() => {
            notice.classList.remove('is-visible');
            setTimeout(() => notice.remove(), 400);
        }, 3200);
    }

    // 手動オーバーライド: ライト/ダークを反転し、自動・システム同期を停止する。
    function manualToggle() {
        mode = 'manual';
        try {
            localStorage.setItem(MODE_KEY, 'manual');
        } catch (e) {}
        clearTimeout(autoTimer);
        mql.removeEventListener('change', applyOS);

        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        applyDark(!isDark);
    }

    // システム同期: OS のダークモード設定に追従し、変更を監視する。
    function setSystemSync() {
        mode = 'os';
        try {
            localStorage.setItem(MODE_KEY, 'os');
        } catch (e) {}
        clearTimeout(autoTimer);
        applyOS();
        mql.removeEventListener('change', applyOS);
        mql.addEventListener('change', applyOS);
        showSyncNotice();
    }

    // 操作方法（document 委譲のため、後から生成されるハンディーモードのトグルにも対応）:
    //   マウス  : シングルクリック=手動反転 / ダブルクリック=システム同期
    //   タッチ  : タップ=手動反転 / 長押し=システム同期（ダブルタップはズームと競合するため不採用）
    document.addEventListener('touchstart', e => {
        const btn = e.target.closest(TOGGLE_SELECTOR);
        if (!btn) return;

        longPressFired = false;
        clearTimeout(longPressTimer);
        longPressTimer = setTimeout(() => {
            longPressFired = true;
            setSystemSync();
        }, LONG_PRESS_MS);
    }, { passive: true });

    const cancelLongPress = () => {
        clearTimeout(longPressTimer);
        longPressTimer = null;
    };
    document.addEventListener('touchmove', cancelLongPress, { passive: true });
    document.addEventListener('touchcancel', cancelLongPress, { passive: true });

    document.addEventListener('touchend', e => {
        const btn = e.target.closest(TOGGLE_SELECTOR);
        if (!btn) return;

        clearTimeout(longPressTimer);
        longPressTimer = null;
        lastTouchTime = Date.now();

        if (longPressFired) {
            // 長押しで処理済み。直後の合成クリックによる反転を防ぐ。
            e.preventDefault();
            return;
        }

        // タップ=手動反転（ダブルタップ判定が不要な分、即時反映）。
        e.preventDefault();
        manualToggle();
    });

    document.addEventListener('click', e => {
        const btn = e.target.closest(TOGGLE_SELECTOR);
        if (!btn) return;

        e.preventDefault();

        // タッチ由来の合成クリックはタッチ側で処理済みのため無視する。
        if (Date.now() - lastTouchTime < 700) return;

        if (clickTimer) {
            clearTimeout(clickTimer);
            clickTimer = null;
            setSystemSync();
            return;
        }

        clickTimer = setTimeout(() => {
            clickTimer = null;
            manualToggle();
        }, DOUBLE_CLICK_MS);
    });

    if (mode === 'manual') {
        const stored = localStorage.getItem(THEME_KEY) || document.documentElement.getAttribute('data-theme');
        setIcon(stored === 'dark');
    } else if (mode === 'os') {
        applyOS();
        mql.addEventListener('change', applyOS);
    } else {
        applyAuto();
    }
}
