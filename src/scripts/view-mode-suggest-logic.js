export const VIEW_SUGGEST_DISMISS_MS = 7 * 24 * 60 * 60 * 1000;

/**
 * 推奨表示。電話と断定できるときだけ mobile、
 * ホバー付き実デスクトップだけ pc。タブレットやタッチ対応ノートは明示選択を尊重する（提案しない）。
 */
export function recommendViewMode({
    phoneLike,
    realDesktop,
}) {
    if (phoneLike) return 'mobile';
    if (realDesktop) return 'pc';
    return null;
}

/** 初期表示モード。保存値 > 電話UA/小型タッチ端末（mobile）> それ以外（pc）。 */
export function effectiveViewMode({ saved, phoneLike }) {
    if (saved === 'mobile' || saved === 'pc') return saved;
    return phoneLike ? 'mobile' : 'pc';
}

export function viewModeDeviceKind({
    phoneLike,
    tabletIdentity,
    tabletLike,
    hasHoverMouse,
    realDesktop,
}) {
    if (phoneLike) return 'phone';
    if (tabletIdentity || (tabletLike && !hasHoverMouse)) return 'tablet';
    if (realDesktop) return 'desktop';
    return 'ambiguous';
}

export function suggestionFingerprint(deviceKind, current, recommended) {
    return `${deviceKind}:${current}:${recommended}`;
}

export function shouldSuggestViewMode(input) {
    const recommended = recommendViewMode(input);
    if (!recommended) return null;
    const current = effectiveViewMode(input);
    if (current === recommended) return null;
    const kind = viewModeDeviceKind(input);
    return {
        current,
        recommended,
        deviceKind: kind,
        fingerprint: suggestionFingerprint(kind, current, recommended),
    };
}

export function isViewSuggestDismissed(record, fingerprint, now) {
    if (!record || typeof record !== 'object') return false;
    if (record.fingerprint !== fingerprint) return false;
    if (typeof record.until !== 'number') return false;
    return now < record.until;
}
