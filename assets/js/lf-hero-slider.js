(() => {
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let reduceMotion = motion.matches;

  const init = (root) => {
    const viewport = root.querySelector('[data-lf-viewport]');
    const slides = Array.from(root.querySelectorAll('[data-lf-slide]'));
    const dots = Array.from(root.querySelectorAll('[data-lf-dot]'));
    const thumbs = Array.from(root.querySelectorAll('[data-lf-thumb]'));
    const prev = root.querySelector('[data-lf-prev]');
    const next = root.querySelector('[data-lf-next]');
    const live = root.querySelector('[data-lf-live]');
    const playback = root.querySelector('[data-lf-playback]');
    if (!viewport || slides.length === 0) return;

    const total = slides.length;
    // Edge copies allow native swipe / scroll snap to cross either end.
    const track = root.querySelector('[data-lf-track]');
    const edgeCopies = [];
    if (total > 1 && track) {
      for (const [source, position] of [[slides[total - 1], 'start'], [slides[0], 'end']]) {
        const copy = source.cloneNode(true);
        copy.removeAttribute('id');
        copy.removeAttribute('data-lf-slide');
        copy.removeAttribute('data-index');
        copy.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));
        copy.setAttribute('aria-hidden', 'true');
        copy.setAttribute('inert', '');
        copy.querySelectorAll('img').forEach(img => { img.loading = 'eager'; });
        if (position === 'start') track.prepend(copy);
        else track.append(copy);
        edgeCopies.push(copy);
      }
    }
    const physicalSlides = total > 1 ? [edgeCopies[0], ...slides, edgeCopies[1]] : slides;
    let scrollSettled = 0;
    let pendingTarget = null;
    const autoplayEnabled = root.dataset.autoplay === 'true';
    root.querySelectorAll('[data-lf-controls], [data-lf-thumbs]').forEach(el => { el.hidden = total < 2; });
    const pagination = root.querySelector('[data-lf-pagination]');
    if (pagination) pagination.hidden = false;
    const interval = Math.max(4000, Number(root.dataset.interval || 6000));
    let index = 0;
    let timer = 0;
    let userPaused = !autoplayEnabled;
    let hovering = false;
    let keyboardFocus = false;
    let pointerHeld = false;
    let manualUntil = 0;

    const clamp = (value) => ((value % total) + total) % total;

    const offset = (slide) => slide.getBoundingClientRect().left - viewport.getBoundingClientRect().left + viewport.scrollLeft;
    const slideOffset = (i) => offset(slides[i]);

    const goTo = (nextIndex, { instant = false } = {}) => {
      index = clamp(nextIndex);
      pendingTarget = index;
      const behavior = instant || reduceMotion ? 'instant' : 'smooth';
      const target = !instant && total > 1 && nextIndex < 0 ? edgeCopies[0]
        : !instant && total > 1 && nextIndex >= total ? edgeCopies[1] : slides[index];
      viewport.scrollTo({ left: offset(target), behavior });
      sync();
    };

    const sync = () => {
      slides.forEach((slide, i) => {
        const current = i === index;
        slide.classList.toggle('lf-hero-is-current', current);
        slide.setAttribute('aria-hidden', current ? 'false' : 'true');
        if (current) slide.removeAttribute('inert');
        else slide.setAttribute('inert', '');
      });

      dots.forEach((dot, i) => {
        const current = i === index;
        dot.classList.toggle('lf-hero-is-active', current);
        dot.setAttribute('aria-pressed', current ? 'true' : 'false');
      });

      thumbs.forEach((thumb, i) => {
        const current = i === index;
        thumb.classList.toggle('lf-hero-is-active', current);
        thumb.setAttribute('aria-pressed', current ? 'true' : 'false');
        if (current) {
          const strip = thumb.parentElement;
          const bounds = strip.getBoundingClientRect();
          const item = thumb.getBoundingClientRect();
          const delta = item.left < bounds.left ? item.left - bounds.left : Math.max(0, item.right - bounds.right);
          if (delta) strip.scrollBy({ left: delta, behavior: reduceMotion ? 'auto' : 'smooth' });
        }
      });

      if (prev) prev.setAttribute('aria-controls', slides[clamp(index - 1)].id);
      if (next) next.setAttribute('aria-controls', slides[clamp(index + 1)].id);
      if (live) live.textContent = `${index + 1} / ${total}`;
    };

    const nearestPhysical = () => {
      const left = viewport.scrollLeft;
      let best = 0;
      let bestDist = Infinity;
      physicalSlides.forEach((slide, i) => {
        const dist = Math.abs(Math.min(offset(slide), viewport.scrollWidth - viewport.clientWidth) - left);
        if (dist < bestDist) { bestDist = dist; best = i; }
      });
      return best;
    };

    const settle = () => {
      const physical = nearestPhysical();
      const settledIndex = total > 1 ? clamp(physical - 1) : 0;
      if (pendingTarget !== null && settledIndex !== pendingTarget) return;
      pendingTarget = null;
      index = settledIndex;
      sync();
      if (total > 1 && (physical === 0 || physical === physicalSlides.length - 1)) {
        index = physical === 0 ? total - 1 : 0;
        viewport.scrollTo({ left: slideOffset(index), behavior: 'instant' });
        sync();
      }
    };

    const updatePlayback = () => {
      if (playback) {
        playback.hidden = total < 2;
        playback.disabled = reduceMotion;
        playback.textContent = reduceMotion ? '自動送り停止' : userPaused ? '再開' : '一時停止';
        playback.setAttribute('aria-label', reduceMotion ? '動きを減らす設定により自動送りは停止中' : userPaused ? 'スライドの自動送りを再開' : 'スライドの自動送りを一時停止');
      }
      if (live) live.setAttribute('aria-live', timer ? 'off' : 'polite');
    };

    const stopTimer = () => {
      window.clearTimeout(timer);
      timer = 0;
      updatePlayback();
    };

    const startTimer = () => {
      stopTimer();
      if (total < 2 || reduceMotion || userPaused || hovering || keyboardFocus || pointerHeld || document.hidden) return;
      timer = window.setTimeout(() => {
        goTo(index + 1);
        startTimer();
      }, Math.max(interval, manualUntil - performance.now()));
      updatePlayback();
    };

    const pauseByUser = () => {
      manualUntil = performance.now() + 10000;
      startTimer();
    };

    if (playback) playback.addEventListener('click', () => {
      userPaused = !userPaused;
      manualUntil = 0;
      startTimer();
    });

    viewport.addEventListener('scroll', () => {
      window.clearTimeout(scrollSettled);
      scrollSettled = window.setTimeout(settle, 180);
      if (pendingTarget !== null) return;
      const nextNearest = total > 1 ? clamp(nearestPhysical() - 1) : 0;
      if (nextNearest !== index) {
        index = nextNearest;
        sync();
      }
    }, { passive: true });

    viewport.addEventListener('scrollend', settle);

    if (prev) {
      prev.addEventListener('click', () => {
        pauseByUser();
        goTo(index - 1);
      });
    }
    if (next) {
      next.addEventListener('click', () => {
        pauseByUser();
        goTo(index + 1);
      });
    }

    dots.forEach((dot) => {
      dot.addEventListener('click', () => {
        pauseByUser();
        goTo(Number(dot.dataset.index || 0));
      });
    });

    thumbs.forEach((thumb) => {
      thumb.addEventListener('click', () => {
        pauseByUser();
        goTo(Number(thumb.dataset.index || 0));
      });
    });

    viewport.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        pauseByUser();
        goTo(index - 1);
      }
      if (event.key === 'ArrowRight') {
        event.preventDefault();
        pauseByUser();
        goTo(index + 1);
      }
      if (event.key === 'Home') {
        event.preventDefault();
        pauseByUser();
        goTo(0);
      }
      if (event.key === 'End') {
        event.preventDefault();
        pauseByUser();
        goTo(total - 1);
      }
    });

    root.addEventListener('focusin', (event) => {
      keyboardFocus = event.target.matches(':focus-visible');
      startTimer();
    });
    root.addEventListener('focusout', () => {
      queueMicrotask(() => {
        keyboardFocus = root.contains(document.activeElement) && document.activeElement.matches(':focus-visible');
        startTimer();
      });
    });
    root.addEventListener('keydown', () => { keyboardFocus = true; stopTimer(); });
    motion.addEventListener('change', () => { reduceMotion = motion.matches; startTimer(); });

    root.addEventListener('pointerenter', (event) => {
      if (event.pointerType !== 'mouse') return;
      hovering = true;
      stopTimer();
    });
    root.addEventListener('pointerleave', (event) => {
      if (event.pointerType !== 'mouse') return;
      hovering = false;
      startTimer();
    });

    root.addEventListener('pointerdown', (event) => {
      keyboardFocus = false;
      if (event.target.closest('[data-lf-playback]')) return;
      pointerHeld = true;
      pendingTarget = null;
      pauseByUser();
    }, { passive: true });
    const releasePointer = () => {
      if (!pointerHeld) return;
      pointerHeld = false;
      pauseByUser();
    };
    window.addEventListener('pointerup', releasePointer, { passive: true });
    window.addEventListener('pointercancel', releasePointer, { passive: true });
    root.addEventListener('wheel', (event) => {
      if (Math.abs(event.deltaX) > Math.abs(event.deltaY)) { pendingTarget = null; pauseByUser(); }
    }, { passive: true });

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) stopTimer();
      else startTimer();
    });

    window.addEventListener('resize', () => goTo(index, { instant: true }));

    goTo(0, { instant: true });
    startTimer();
  };

  const boot = () => {
    document.querySelectorAll('[data-lf-hero-slider]').forEach(init);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
