/** Fetch news images when their reserved card approaches the viewport. */
export function initHomeNewsImages() {
    const visuals = Array.from(document.querySelectorAll('.lf-home-story--news .lf-home-story__visual'))
        .filter(visual => visual.querySelector('[data-lf-deferred-image]'));
    if (!visuals.length) return;
    const load = (visual) => {
        visual.querySelectorAll('[data-lf-src], [data-lf-srcset]').forEach(element => {
            for (const attribute of ['sizes', 'srcset', 'src']) {
                const value = element.getAttribute(`data-lf-${attribute}`);
                if (value !== null) {
                    element.setAttribute(attribute, value);
                    element.removeAttribute(`data-lf-${attribute}`);
                }
            }
            element.removeAttribute('data-lf-deferred-image');
        });
    };
    if (!('IntersectionObserver' in window)) {
        visuals.forEach(load);
        return;
    }
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            load(entry.target);
            observer.unobserve(entry.target);
        });
    }, { rootMargin: '200px 0px' });
    visuals.forEach(visual => observer.observe(visual));
}
