/** Mobile disclosure for existing TOP/TOC controls; desktop stays expanded. */
export function initMobileActionMenu(stack) {
    const toggle = stack.querySelector('#m3-actions-toggle');
    const actions = stack.querySelector('#m3-actions-items');
    if (!toggle || !actions) return () => {};
    const mobile = window.matchMedia('(max-width: 1000px)');
    let open = false;
    const sync = () => {
        const expanded = !mobile.matches || open;
        if (!expanded && actions.contains(document.activeElement)) toggle.focus({ preventScroll: true });
        actions.hidden = !expanded;
        toggle.hidden = !mobile.matches;
        toggle.setAttribute('aria-expanded', String(mobile.matches && open));
        toggle.setAttribute('aria-label', open ? 'ページ操作を閉じる' : 'ページ操作を開く');
        toggle.querySelector('[aria-hidden]').textContent = open ? '×' : '＋';
        stack.classList.toggle('is-actions-open', mobile.matches && open);
    };
    const close = (restoreFocus = false) => {
        if (!open) return;
        open = false;
        if (restoreFocus) toggle.focus({ preventScroll: true });
        sync();
    };
    toggle.addEventListener('click', () => { open = !open; sync(); });
    document.addEventListener('click', event => {
        if (!stack.contains(event.target)) close();
    });
    // Safari does not focus buttons on pointer click; Escape must also work
    // when the disclosure was opened without moving keyboard focus.
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && open) {
            event.preventDefault();
            event.stopPropagation();
            close(true);
        }
    });
    stack.addEventListener('focusout', event => {
        // Native focusout may run before activeElement has become the next button.
        // relatedTarget keeps a pointer click inside the disclosure from closing it.
        if (event.relatedTarget && !stack.contains(event.relatedTarget)) close();
    });
    // Capture runs before the TOC's existing direct listener stops propagation.
    actions.addEventListener('click', event => {
        if (mobile.matches && event.target.closest('button')) close(true);
    }, true);
    document.addEventListener('m3:toc:visibility', event => {
        if (event.detail.open && mobile.matches) close();
    });
    mobile.addEventListener('change', () => { open = false; sync(); });
    stack.classList.add('is-actions-ready');
    sync();
    return close;
}
