const PREFERRED_DURATION_A = 1150;
const PREFERRED_DURATION_B = 1300;

function prefersReducedMotion() {
    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function resolvePattern(el) {
    try {
        const queryPattern = new URLSearchParams(window.location.search).get('sale_anim');
        if (queryPattern === 'a' || queryPattern === 'b') {
            return queryPattern;
        }
    } catch {
        // Ignore invalid URL contexts.
    }
    return el.dataset.nodeLibrarySaleAnim === 'b' ? 'b' : 'a';
}

function formatStepPrice(value, currency) {
    if (currency === 'JPY') {
        return '¥' + Math.round(value).toLocaleString('ja-JP');
    }
    return `${currency} ${value.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

function buildDrumItems(prevPrice, currPrice, prevLabel, currLabel, currency) {
    const stepCount = 7;
    const labels = [];
    for (let i = 0; i < stepCount; i++) {
        const progress = i / (stepCount - 1);
        const eased = Math.pow(progress, 0.85);
        const raw = prevPrice + (currPrice - prevPrice) * eased;
        const label = i === 0
            ? prevLabel
            : (i === stepCount - 1 ? currLabel : formatStepPrice(raw, currency));
        if (labels[labels.length - 1] !== label) {
            labels.push(label);
        }
    }
    if (labels[labels.length - 1] !== currLabel) {
        labels.push(currLabel);
    }

    // Add one subtle trailing item below the final price so the bottom edge of the picker stays balanced.
    const delta = Math.max(1, Math.round((prevPrice - currPrice) * 0.12));
    const lowerVal = Math.max(0, currPrice - delta);
    const lowerLabel = formatStepPrice(lowerVal, currency);

    return {
        items: ['', ...labels, lowerLabel === currLabel ? '' : lowerLabel],
        startIndex: 1,
        targetIndex: labels.length,
    };
}

function ensureStage(el) {
    if (el.parentElement && el.parentElement.classList.contains('node-library-pricing__price-stage')) {
        return el.parentElement;
    }
    const stage = document.createElement('span');
    stage.className = 'node-library-pricing__price-stage';
    el.parentNode.insertBefore(stage, el);
    stage.appendChild(el);
    return stage;
}

function runDrumPickerAnimation(el, data) {
    const stage = ensureStage(el);
    const { items, startIndex, targetIndex } = buildDrumItems(
        data.prevPrice,
        data.currPrice,
        data.prevLabel,
        data.currLabel,
        data.currency
    );

    const rowHeight = window.matchMedia('(max-width: 560px)').matches ? 28 : 32;
    const drum = document.createElement('span');
    drum.className = 'node-library-sale-drum';
    drum.setAttribute('aria-hidden', 'true');
    drum.style.setProperty('--drum-row-h', `${rowHeight}px`);

    const band = document.createElement('span');
    band.className = 'node-library-sale-drum__band';

    const track = document.createElement('span');
    track.className = 'node-library-sale-drum__track';

    const itemEls = items.map((text) => {
        const item = document.createElement('span');
        item.className = 'node-library-sale-drum__item';
        item.textContent = text;
        track.appendChild(item);
        return item;
    });

    drum.appendChild(band);
    drum.appendChild(track);
    stage.classList.add('node-library-pricing__price-stage--drum');
    el.classList.add('node-library-pricing__price-final--sr-only');
    stage.appendChild(drum);

    const updateWheel = (currentIndex) => {
        const offsetPx = (1 - currentIndex) * rowHeight;
        track.style.transform = `translate3d(0, ${offsetPx}px, 0)`;

        itemEls.forEach((itemEl, idx) => {
            const dist = idx - currentIndex;
            const absDist = Math.abs(dist);
            const clamped = Math.min(absDist, 2.2);
            const rotateX = dist * -26;
            const scale = Math.max(0.84, 1.04 - clamped * 0.1);
            const opacity = itemEl.textContent === ''
                ? 0
                : Math.max(0.14, 1 - clamped * 0.42);
            itemEl.style.transform = `perspective(320px) rotateX(${rotateX.toFixed(2)}deg) scale(${scale.toFixed(3)})`;
            itemEl.style.opacity = opacity.toFixed(3);
            itemEl.classList.toggle('is-center', absDist < 0.35);
        });
    };

    updateWheel(startIndex);
    const startTime = performance.now();

    const tick = (now) => {
        const elapsed = now - startTime;
        const progress = Math.min(1, elapsed / PREFERRED_DURATION_A);
        const eased = 1 - Math.pow(1 - progress, 3.2);
        const index = startIndex + (targetIndex - startIndex) * eased;
        updateWheel(index);

        if (progress < 1) {
            window.requestAnimationFrame(tick);
        } else {
            updateWheel(targetIndex);
            drum.classList.add('is-settled');
            window.setTimeout(() => {
                drum.remove();
                stage.classList.remove('node-library-pricing__price-stage--drum');
                el.classList.remove('node-library-pricing__price-final--sr-only');
            }, 350);
        }
    };

    window.requestAnimationFrame(tick);
}

function parseRgb(colorStr) {
    const match = colorStr.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
    if (!match) {
        return { r: 28, g: 27, b: 31 };
    }
    return {
        r: Number(match[1]),
        g: Number(match[2]),
        b: Number(match[3]),
    };
}

function runParticleDispersionAnimation(el, data) {
    const stage = ensureStage(el);
    const computed = window.getComputedStyle(el);
    const font = `${computed.fontStyle} ${computed.fontWeight} ${computed.fontSize} ${computed.fontFamily}`;
    const rgb = parseRgb(computed.color);

    const probeCanvas = document.createElement('canvas');
    const probeCtx = probeCanvas.getContext('2d');
    if (!probeCtx) {
        return;
    }

    probeCtx.font = font;
    const oldMetrics = probeCtx.measureText(data.prevLabel);
    const newRect = el.getBoundingClientRect();
    const fontSizePx = parseFloat(computed.fontSize) || 18;
    const width = Math.max(Math.ceil(newRect.width), Math.ceil(oldMetrics.width)) + 8;
    const height = Math.max(Math.ceil(newRect.height), Math.ceil(fontSizePx * 1.45));

    const padX = 16;
    const padY = 14;
    const canvasW = width + padX * 2;
    const canvasH = height + padY * 2;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);

    const offscreen = document.createElement('canvas');
    offscreen.width = Math.ceil(width * dpr);
    offscreen.height = Math.ceil(height * dpr);
    const offCtx = offscreen.getContext('2d');
    if (!offCtx) {
        return;
    }

    offCtx.scale(dpr, dpr);
    offCtx.font = font;
    offCtx.fillStyle = `rgb(${rgb.r}, ${rgb.g}, ${rgb.b})`;
    offCtx.textBaseline = 'middle';
    offCtx.fillText(data.prevLabel, 2, height / 2);

    let pixels;
    try {
        pixels = offCtx.getImageData(0, 0, offscreen.width, offscreen.height).data;
    } catch {
        el.style.opacity = '1';
        return;
    }

    const particles = [];
    const stride = 2;
    for (let py = 0; py < offscreen.height; py += stride) {
        for (let px = 0; px < offscreen.width; px += stride) {
            const idx = (py * offscreen.width + px) * 4;
            const alpha = pixels[idx + 3];
            if (alpha < 40) continue;

            const x = px / dpr + padX;
            const y = py / dpr + padY;
            const nx = (px / offscreen.width);
            particles.push({
                x,
                y,
                vx: 0.45 + Math.random() * 1.65 + nx * 0.65,
                vy: -0.35 - Math.random() * 1.45,
                drift: (Math.random() - 0.5) * 0.1,
                size: 1.7 + Math.random() * 1.35,
                alpha: Math.min(1, (alpha / 255) * 1.15),
                delay: nx * 240 + Math.random() * 100,
            });
        }
    }

    if (particles.length === 0) {
        return;
    }

    // Cap particle density for consistent 60fps on mobile devices.
    const maxParticles = 760;
    const sampled = particles.length > maxParticles
        ? particles.filter((_, i) => i % Math.ceil(particles.length / maxParticles) === 0)
        : particles;

    const canvas = document.createElement('canvas');
    canvas.className = 'node-library-sale-particles';
    canvas.setAttribute('aria-hidden', 'true');
    canvas.width = Math.ceil(canvasW * dpr);
    canvas.height = Math.ceil(canvasH * dpr);
    canvas.style.width = `${canvasW}px`;
    canvas.style.height = `${canvasH}px`;

    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return;
    }
    ctx.scale(dpr, dpr);

    stage.style.minWidth = `${Math.min(width, 260)}px`;
    el.style.opacity = '0';
    stage.appendChild(canvas);

    let revealed = false;
    const startTime = performance.now();

    const tick = (now) => {
        const elapsed = now - startTime;
        ctx.clearRect(0, 0, canvasW, canvasH);

        if (!revealed && elapsed >= 620) {
            revealed = true;
            el.style.transition = 'opacity 420ms ease, transform 420ms cubic-bezier(0.2, 0, 0, 1)';
            el.style.opacity = '1';
        }

        let alive = 0;
        for (let i = 0; i < sampled.length; i++) {
            const p = sampled[i];
            const activeElapsed = elapsed - (180 + p.delay);
            if (activeElapsed <= 0) {
                ctx.fillStyle = `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${p.alpha.toFixed(3)})`;
                ctx.fillRect(p.x, p.y, p.size, p.size);
                alive++;
                continue;
            }

            const life = Math.min(1, activeElapsed / 820);
            if (life >= 1) continue;

            p.x += p.vx;
            p.y += p.vy + Math.sin(activeElapsed * 0.014) * p.drift * 10;
            p.vx *= 0.99;
            p.vy -= 0.015;
            const currentAlpha = p.alpha * (1 - Math.pow(life, 1.45));
            const radius = p.size * (1 - life * 0.5);

            ctx.fillStyle = `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${Math.max(0, currentAlpha).toFixed(3)})`;
            ctx.beginPath();
            ctx.arc(p.x, p.y, Math.max(0.5, radius), 0, Math.PI * 2);
            ctx.fill();
            alive++;
        }

        if (elapsed < PREFERRED_DURATION_B && alive > 0) {
            window.requestAnimationFrame(tick);
        } else {
            el.style.opacity = '1';
            el.style.transition = '';
            canvas.remove();
        }
    };

    window.requestAnimationFrame(tick);
}

export function initLibrarySaleAnimations() {
    if (prefersReducedMotion()) {
        return;
    }

    const targets = document.querySelectorAll('[data-node-library-sale-anim]');
    if (!targets.length) {
        return;
    }

    const startTarget = (el) => {
        if (el.dataset.saleAnimPlayed === 'true') {
            return;
        }
        const prevPrice = Number(el.dataset.salePrevPrice);
        const currPrice = Number(el.dataset.saleCurrPrice);
        const prevLabel = (el.dataset.salePrevLabel || '').trim();
        const currLabel = (el.dataset.saleCurrLabel || el.textContent || '').trim();
        const currency = (el.dataset.saleCurrency || 'JPY').toUpperCase();

        if (!Number.isFinite(prevPrice) || !Number.isFinite(currPrice) || currPrice >= prevPrice || !prevLabel || !currLabel) {
            return;
        }

        el.dataset.saleAnimPlayed = 'true';
        const pattern = resolvePattern(el);
        const data = { prevPrice, currPrice, prevLabel, currLabel, currency };

        if (pattern === 'b') {
            const runB = () => runParticleDispersionAnimation(el, data);
            if (document.fonts && document.fonts.ready) {
                Promise.race([
                    document.fonts.ready,
                    new Promise((resolve) => window.setTimeout(resolve, 150)),
                ]).then(runB).catch(runB);
            } else {
                runB();
            }
        } else {
            runDrumPickerAnimation(el, data);
        }
    };

    const waitForSplash = () => new Promise((resolve) => {
        const splash = document.getElementById('ll-splash');
        if (!splash || splash.hidden || splash.classList.contains('is-done') || window.getComputedStyle(splash).display === 'none') {
            resolve();
            return;
        }
        let settled = false;
        const done = () => {
            if (settled) return;
            settled = true;
            observer.disconnect();
            window.clearTimeout(fallbackTimer);
            resolve();
        };
        const observer = new MutationObserver(() => {
            if (!document.getElementById('ll-splash') || splash.hidden || splash.classList.contains('is-done') || window.getComputedStyle(splash).display === 'none') {
                done();
            }
        });
        observer.observe(splash, { attributes: true, attributeFilter: ['class', 'hidden', 'style'] });
        document.addEventListener('ll-splash:done', done, { once: true });
        const fallbackTimer = window.setTimeout(done, 2500);
    });

    waitForSplash().then(() => {
        if (typeof window.IntersectionObserver !== 'function') {
            targets.forEach((el) => startTarget(el));
            return;
        }
        const io = new window.IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    io.unobserve(entry.target);
                    startTarget(entry.target);
                }
            });
        }, { threshold: 0.2 });
        targets.forEach((el) => io.observe(el));
    });
}
