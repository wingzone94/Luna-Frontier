(function () {
  "use strict";

  const AUTO_CLOSE_MS = 1600;
  const timers = new WeakMap();

  function clearRowTimer(couponEl) {
    const existing = timers.get(couponEl);
    if (existing) {
      window.clearTimeout(existing);
      timers.delete(couponEl);
    }
  }

  function resetPanelState(couponEl) {
    const copyBtn = couponEl.querySelector("[data-coupon-copy]");
    const statusEl = couponEl.querySelector("[data-coupon-status]");
    if (copyBtn) {
      copyBtn.textContent = "コピー";
      copyBtn.classList.remove("is-copied");
    }
    if (statusEl) {
      statusEl.textContent = "";
    }
  }

  function openPanel(couponEl) {
    clearRowTimer(couponEl);
    const toggle = couponEl.querySelector("[data-coupon-toggle]");
    const panel = couponEl.querySelector("[data-coupon-panel]");
    if (!toggle || !panel) return;

    resetPanelState(couponEl);
    panel.hidden = false;
    panel.removeAttribute("hidden");
    toggle.setAttribute("aria-expanded", "true");
  }

  function closePanel(couponEl, restoreFocus) {
    clearRowTimer(couponEl);
    const toggle = couponEl.querySelector("[data-coupon-toggle]");
    const panel = couponEl.querySelector("[data-coupon-panel]");
    if (!toggle || !panel) return;

    panel.hidden = true;
    panel.setAttribute("hidden", "");
    toggle.setAttribute("aria-expanded", "false");
    resetPanelState(couponEl);

    if (restoreFocus) {
      toggle.focus();
    }
  }

  function selectCodeInput(codeInput) {
    if (!codeInput) return;
    const val = codeInput.value || "";
    codeInput.focus();
    codeInput.select();
    if (typeof codeInput.setSelectionRange === "function") {
      codeInput.setSelectionRange(0, val.length);
    }
  }

  async function copyCouponText(codeInput) {
    const code = codeInput ? String(codeInput.value || "") : "";
    if (!code) {
      return { ok: false, method: "empty" };
    }

    if (
      typeof navigator !== "undefined" &&
      navigator.clipboard &&
      typeof navigator.clipboard.writeText === "function"
    ) {
      try {
        await navigator.clipboard.writeText(code);
        return { ok: true, method: "clipboard" };
      } catch (_err) {
        // Fall back to execCommand('copy') below
      }
    }

    try {
      selectCodeInput(codeInput);
      const copied =
        typeof document.execCommand === "function" &&
        document.execCommand("copy");
      if (copied) {
        return { ok: true, method: "execCommand" };
      }
    } catch (_err) {
      // Copy failed
    }

    return { ok: false, method: "failed" };
  }

  async function handleCopy(couponEl) {
    clearRowTimer(couponEl);
    const codeInput = couponEl.querySelector("[data-coupon-code]");
    const copyBtn = couponEl.querySelector("[data-coupon-copy]");
    const statusEl = couponEl.querySelector("[data-coupon-status]");
    if (!codeInput || !copyBtn) return;

    const result = await copyCouponText(codeInput);
    couponEl.setAttribute("data-last-copy-method", result.method);

    if (result.ok) {
      copyBtn.textContent = "コピーしました";
      copyBtn.classList.add("is-copied");
      if (statusEl) {
        statusEl.textContent = "クーポンコードをコピーしました";
      }

      const timerId = window.setTimeout(function () {
        timers.delete(couponEl);
        closePanel(couponEl, true);
      }, AUTO_CLOSE_MS);
      timers.set(couponEl, timerId);
    } else {
      copyBtn.textContent = "コピー";
      copyBtn.classList.remove("is-copied");
      if (statusEl) {
        statusEl.textContent =
          "コピーできませんでした。コードを選択してコピーしてください";
      }
      selectCodeInput(codeInput);
    }
  }

  document.addEventListener("click", function (event) {
    const target = event.target;
    if (!target || typeof target.closest !== "function") return;

    const toggleBtn = target.closest("[data-coupon-toggle]");
    if (toggleBtn) {
      const couponEl = toggleBtn.closest("[data-coupon]");
      if (!couponEl) return;
      const isExpanded = toggleBtn.getAttribute("aria-expanded") === "true";
      if (isExpanded) {
        closePanel(couponEl, false);
      } else {
        openPanel(couponEl);
      }
      return;
    }

    const copyBtn = target.closest("[data-coupon-copy]");
    if (copyBtn) {
      const couponEl = copyBtn.closest("[data-coupon]");
      if (!couponEl) return;
      handleCopy(couponEl);
      return;
    }

    const codeInput = target.closest("[data-coupon-code]");
    if (codeInput) {
      selectCodeInput(codeInput);
    }
  });
})();
