export const TABLET_PC_CLASS = 'lf-tablet-pc';

const getNavigator = () => (typeof navigator === 'undefined' ? {} : navigator);

const matchMediaSafe = (query) => (
    typeof window !== 'undefined' && typeof window.matchMedia === 'function'
        ? window.matchMedia(query).matches
        : false
);

export const isPhoneUa = (ua = '') => /iPhone|iPod|Windows Phone/i.test(ua)
    || (/Android/i.test(ua) && /Mobile/i.test(ua));

/**
 * Check whether UA/platform/touch signals definitively identify a tablet
 * (or Windows touch device, which shares desktop UA and maps to tablet-PC mode).
 */
export const isTabletIdentitySignals = ({ ua = '', platform = '', maxTouchPoints = 0 } = {}) => {
    if (isPhoneUa(ua)) {
        return false;
    }

    const iPadClassic = /iPad/i.test(ua);
    const iPadDesktopUa = (platform === 'MacIntel' && maxTouchPoints > 1)
        || (/Macintosh/i.test(ua) && maxTouchPoints >= 2);
    const androidTablet = /Android/i.test(ua) && !/Mobile/i.test(ua);
    const windowsTablet = /Windows/i.test(ua) && maxTouchPoints > 0;
    const tabletUa = /Tablet|Silk|Kindle|PlayBook/i.test(ua);

    return iPadClassic || iPadDesktopUa || androidTablet || windowsTablet || tabletUa;
};

export const getDeviceSignals = () => {
    const nav = getNavigator();
    const ua = nav.userAgent || '';
    const platform = nav.platform || '';
    const maxTouchPoints = Number(nav.maxTouchPoints || 0);

    return {
        ua,
        platform,
        maxTouchPoints,
        hasTouch: maxTouchPoints > 0 || (typeof window !== 'undefined' && 'ontouchstart' in window),
        hasHoverMouse: matchMediaSafe('(hover: hover) and (pointer: fine)'),
        hasCoarsePointer: matchMediaSafe('(pointer: coarse)'),
        isLargeViewport: matchMediaSafe('(min-width: 768px)'),
    };
};

export const isTabletLikeDevice = (signals = getDeviceSignals()) => {
    const {
        ua = '',
        maxTouchPoints = 0,
        hasCoarsePointer = false,
        isLargeViewport = false,
    } = signals;

    if (isPhoneUa(ua)) {
        return false;
    }

    if (isTabletIdentitySignals(signals)) {
        return true;
    }

    return maxTouchPoints > 0 && isLargeViewport && hasCoarsePointer;
};

export const isPhoneLikeDevice = (signals = getDeviceSignals()) => {
    const {
        ua = '',
        maxTouchPoints = 0,
        hasCoarsePointer = false,
        isLargeViewport = false,
    } = signals;

    if (isPhoneUa(ua)) {
        return true;
    }

    // Do not misclassify 744px iPad mini or other tablet identities as phones.
    if (isTabletIdentitySignals(signals)) {
        return false;
    }

    return maxTouchPoints > 0 && hasCoarsePointer && !isLargeViewport;
};

export const isRealDesktopHoverDevice = (signals = getDeviceSignals()) => {
    const { ua = '', maxTouchPoints = 0, hasTouch = false, hasHoverMouse = false } = signals;
    const mobileOrTabletUa = /Android|iPhone|iPad|iPod|Mobile|Tablet|Silk|Kindle|PlayBook|Windows Phone/i.test(ua);

    return hasHoverMouse && maxTouchPoints === 0 && !hasTouch && !mobileOrTabletUa && !isTabletLikeDevice(signals);
};

/**
 * Return true when a tablet should stay on PC-class layout regardless of viewport width.
 */
export const isTabletPcMode = () => {
    if (typeof document === 'undefined') {
        return false;
    }
    const root = document.documentElement;
    if (root.classList.contains(TABLET_PC_CLASS)) {
        return true;
    }
    if (root.dataset.viewMode === 'mobile') {
        return false;
    }
    return !isPhoneLikeDevice() && isTabletLikeDevice();
};

/**
 * Keep the root `.lf-tablet-pc` guard class aligned with the active view mode.
 */
export const syncTabletPcClass = () => {
    if (typeof document === 'undefined') {
        return false;
    }
    const root = document.documentElement;
    const active = root.dataset.viewMode !== 'mobile' && !isPhoneLikeDevice() && isTabletLikeDevice();
    root.classList.toggle(TABLET_PC_CLASS, active);
    return active;
};
