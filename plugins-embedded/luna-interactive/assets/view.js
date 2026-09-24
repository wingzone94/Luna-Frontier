(function (G) {
  "use strict";
  function luminance(color) {
    const m = String(color).match(/[\d.]+/g);
    if (!m || m.length < 3) return 1;
    const [r, g, b] = m.map(Number);
    return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
  }
  function boot(root) {
    if (root.dataset.lunaReady) return;
    const editable = (target) =>
      target instanceof Element &&
      !!target.closest("input,textarea,select,[contenteditable=true]");
    if (root.dataset.lunaCopyProtected === "true") {
      ["copy", "cut", "contextmenu", "dragstart", "selectstart"].forEach((name) =>
        root.addEventListener(name, (event) => {
          if (!editable(event.target)) event.preventDefault();
        }),
      );
    }
    const $ = (s) => root.querySelector(s),
      status = $(".luna-status");
    let spec;
    try {
      spec = JSON.parse(root.dataset.lunaSpec);
    } catch (e) {
      status.textContent = "グラフの設定を読み込めません。";
      return;
    }
    if (!window.Chart || !G) {
      status.textContent =
        "動的グラフを開始できません。静的グラフをご利用ください。";
      return;
    }
    root.dataset.lunaReady = "1";
    const dyn = $(".luna-dynamic"),
      still = $(".luna-static"),
      initial = still.innerHTML;
    let charts = [],
      viewType =
        spec.type === "line" || spec.type === "pie" ? spec.type : "bar",
      viewMode = root.dataset.lunaMode === "static" ? "static" : "dynamic";
    let chartsByIndex = new Map(), rendered = null;
    const pieHoverMedia = window.matchMedia("(min-width: 721px) and (hover: hover) and (pointer: fine)");
    let pieTip = null;
    function hidePieTip() {
      if (pieTip) pieTip.hidden = true;
    }
    function externalPieTip({ chart, tooltip }) {
      if (!pieHoverMedia.matches || !tooltip || tooltip.opacity === 0 || !tooltip.dataPoints?.length) {
        hidePieTip();
        return;
      }
      if (!pieTip) {
        pieTip = document.createElement("div");
        pieTip.className = "luna-pie-tooltip";
        pieTip.setAttribute("aria-hidden", "true");
        document.body.append(pieTip);
      }
      const surface = getComputedStyle($(".luna-m3-surface"));
      pieTip.style.background = surface.backgroundColor;
      pieTip.style.color = surface.color;
      pieTip.style.borderColor = getComputedStyle(root).getPropertyValue("--luna-outline-variant");
      const heading = document.createElement("strong");
      heading.textContent = tooltip.title?.[0] || "";
      const value = document.createElement("span");
      value.textContent = tooltip.body?.[0]?.lines?.[0] || "";
      pieTip.replaceChildren(heading, value);
      pieTip.hidden = false;
      const rect = chart.canvas.getBoundingClientRect();
      const width = pieTip.offsetWidth;
      const height = pieTip.offsetHeight;
      let x = rect.right + 8;
      if (x + width > innerWidth - 8) x = rect.left - width - 8;
      pieTip.style.left = `${Math.max(8, Math.min(x, innerWidth - width - 8))}px`;
      pieTip.style.top = `${Math.max(8, Math.min(rect.top + (rect.height - height) / 2, innerHeight - height - 8))}px`;
    }
    window.addEventListener("scroll", hidePieTip, { passive: true });
    window.addEventListener("resize", hidePieTip);
    root.addEventListener("pointerleave", hidePieTip);
    pieHoverMedia.addEventListener("change", () => {
      if (viewType !== "pie") return;
      hidePieTip();
      charts.forEach((chart) => {
        chart.options.plugins.tooltip.enabled = false;
        chart.update("none");
      });
    });
    const pressed = { series: new Map(), item: new Map() };
    function chip(name, index, role, color) {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "luna-chip";
      btn.dataset.index = String(index);
      btn.dataset.lunaRole = role;
      const on =
        !pressed[role] || pressed[role].get(String(index)) !== false;
      btn.setAttribute("aria-pressed", on ? "true" : "false");
      if (color) {
        const sw = document.createElement("i");
        sw.style.background = color;
        sw.setAttribute("aria-hidden", "true");
        btn.append(sw);
        btn.style.setProperty("--chip", color);
      }
      const label = document.createElement("span");
      label.className = "luna-chip-label";
      label.textContent = name || "（名称なし）";
      btn.append(label);
      btn.addEventListener("click", () => {
        const next = btn.getAttribute("aria-pressed") !== "true";
        pressed[role].set(String(index), next);
        btn.setAttribute("aria-pressed", next ? "true" : "false");
        if (!updateExisting(role)) render();
      });
      return btn;
    }
    function fill(host, names, role, colorAt) {
      host.replaceChildren();
      names.forEach((name, i) =>
        host.append(chip(name, i, role, colorAt ? colorAt(i) : null)),
      );
    }
    function mountChips() {
      const pie = viewType === "pie";
      const primary = $(".luna-chips");
      primary.setAttribute("aria-label", pie ? "項目の表示" : "系列の表示");
      fill(primary, pie ? spec.labels : spec.datasets.map((d) => d.label),
        pie ? "item" : "series", (i) => G.palette[i % G.palette.length]);
    }
    function indices(role, total) {
      return Array.from({ length: total }, (_, i) => i)
        .filter((i) => pressed[role].get(String(i)) !== false);
    }
    function syncSegments() {
      root.querySelectorAll("[data-luna-type]").forEach((btn) => {
        const selected = btn.dataset.lunaType === viewType;
        btn.setAttribute("aria-checked", selected ? "true" : "false");
        btn.tabIndex = selected ? 0 : -1;
      });
      root.querySelectorAll("[data-luna-view]").forEach((btn) => {
        const selected = btn.dataset.lunaView === viewMode;
        btn.setAttribute("aria-checked", selected ? "true" : "false");
        btn.tabIndex = selected ? 0 : -1;
      });
    }
    function isDark() {
      const plot = $(".luna-m3-surface");
      const bg = plot ? getComputedStyle(plot).backgroundColor : "";
      if (bg && bg !== "rgba(0, 0, 0, 0)" && bg !== "transparent")
        return luminance(bg) < 0.45;
      const theme = document.documentElement.getAttribute("data-theme");
      if (theme === "dark") return true;
      if (theme === "light") return false;
      return window.matchMedia("(prefers-color-scheme: dark)").matches;
    }
    function staticImage(canvas, dark, label, description) {
      const snapshot = document.createElement("canvas");
      snapshot.width = canvas.width;
      snapshot.height = canvas.height;
      const ctx = snapshot.getContext("2d");
      const surface = getComputedStyle($(".luna-plot")).backgroundColor;
      const card = getComputedStyle($(".luna-m3-surface")).backgroundColor;
      ctx.fillStyle = surface && surface !== "rgba(0, 0, 0, 0)" && surface !== "transparent"
        ? surface : card && card !== "rgba(0, 0, 0, 0)" && card !== "transparent"
          ? card : dark ? "#25221b" : "#ffffff";
      ctx.fillRect(0, 0, snapshot.width, snapshot.height);
      ctx.drawImage(canvas, 0, 0);
      const img = document.createElement("img");
      img.className = "luna-static-img";
      img.draggable = false;
      img.alt = (description || ((root.getAttribute("aria-label") || "グラフ") + (label ? `、${label}` : ""))) + "（静的画像）";
      const webp = snapshot.toDataURL("image/webp", 0.95);
      img.src = webp.startsWith("data:image/webp") ? webp : snapshot.toDataURL("image/png");
      return img;
    }
    function updateExisting(role) {
      if (viewType !== "pie" || viewMode !== "dynamic" ||
          rendered?.type !== "pie" || rendered.mode !== "dynamic" ||
          rendered.dark !== isDark() || chartsByIndex.size !== spec.datasets.length) return false;
      const items = indices("item", spec.labels.length);
      const series = indices("series", spec.datasets.length);
      const itemKey = items.join(",");
      const updateData = role === "item" && itemKey !== rendered.itemKey;
      spec.datasets.forEach((dataset, index) => {
        const chart = chartsByIndex.get(index);
        const panel = dyn.querySelectorAll(".luna-pie-panel")[index];
        if (!chart || !panel) return;
        const box = panel.querySelector(".luna-pie-canvas");
        const canvas = chart.canvas;
        const wasVisible = canvas.style.visibility !== "hidden";
        const single = { ...G.filter(spec, { series: [index], items }), type: "pie" };
        if (updateData) {
          const config = G.config(single, {
            dark: rendered.dark, animate: rendered.animate, plotColor: rendered.plotColor,
            pieHoverPercent: pieHoverMedia.matches, pieExternalTooltip: externalPieTip,
          });
          chart.data = config.data;
          chart.update(rendered.animate ? undefined : "none");
        }
        const shown = series.includes(index);
        const hasValues = single.labels.length && single.datasets[0].data.some((value) => value != null && value > 0);
        canvas.style.visibility = shown && hasValues ? "visible" : "hidden";
        canvas.setAttribute("aria-hidden", shown && hasValues ? "false" : "true");
        if (shown && hasValues && !wasVisible && rendered.animate) {
          chart.reset();
          chart.update();
        }
        canvas.setAttribute("aria-label", G.describePie(single, root.getAttribute("aria-label")));
        let note = box.querySelector(".luna-pie-empty");
        if (!shown || !hasValues) {
          if (!note) {
            note = document.createElement("span");
            note.className = "luna-pie-empty";
            box.append(note);
          }
          note.textContent = shown ? "データなし" : "非表示";
        } else note?.remove();
      });
      rendered.itemKey = itemKey;
      status.textContent = !items.length || !series.length ? "表示するデータがありません。" : "";
      return true;
    }
    function render() {
      hidePieTip();
      const focus = document.activeElement;
      const restore = root.contains(focus) && focus.dataset.lunaRole === "series"
        ? focus.dataset.index : null;
      syncLegend();
      const items = indices("item", spec.labels.length);
      const series = indices("series", spec.datasets.length);
      const data = G.filter(spec, { series, items });
      data.type = viewType;
      status.textContent = !data.labels.length || !data.datasets.length
        ? "表示するデータがありません。" : "";
      let stage = null;
      const nextCharts = [];
      const nextChartsByIndex = new Map();
      try {
        const dark = isDark();
        const animate = viewMode === "dynamic" &&
          !window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        const plotColor = getComputedStyle($(".luna-m3-surface")).backgroundColor;
        const pie = viewType === "pie";
        stage = document.createElement("div");
        stage.className = "luna-dynamic";
        Object.assign(stage.style, {
          position: "absolute", inset: "0", width: "100%",
          height: pie ? "auto" : "100%", visibility: "hidden", pointerEvents: "none",
        });
        $(".luna-plot").append(stage);
        const grid = pie ? document.createElement("div") : stage;
        if (pie) {
          grid.className = "luna-pie-grid";
          grid.dataset.seriesCount = String(spec.datasets.length);
          stage.append(grid);
        }
        const entries = pie ? spec.datasets : [null];
        entries.forEach((dataset, index) => {
          const label = dataset ? dataset.label : "";
          const host = pie ? document.createElement("div") : grid;
          const box = pie ? document.createElement("div") : host;
          if (pie) {
            host.className = "luna-pie-panel";
            box.className = "luna-pie-canvas";
            host.append(box);
            grid.append(host);
          }
          const shown = !pie || series.includes(index);
          const single = pie ? { ...G.filter(spec, { series: [index], items }), type: "pie" } : data;
          const hasValues = !pie || single.datasets[0].data.some((v) => v != null && v > 0);
          if (!shown || !hasValues || !single.labels.length) {
            const note = document.createElement("span");
            note.className = "luna-pie-empty";
            note.textContent = shown ? "データなし" : "非表示";
            box.append(note);
          } else {
            const canvas = document.createElement("canvas");
            canvas.setAttribute("role", "img");
            canvas.setAttribute("aria-label", pie ? G.describePie(single, root.getAttribute("aria-label")) : (root.getAttribute("aria-label") || "グラフ"));
            if (root.hasAttribute("aria-describedby")) canvas.setAttribute("aria-describedby", root.getAttribute("aria-describedby"));
            box.append(canvas);
            const chart = new window.Chart(canvas, G.config(single, {
              dark, animate, plotColor, pieHoverPercent: pieHoverMedia.matches,
              pieExternalTooltip: pie ? externalPieTip : undefined,
            }));
            nextCharts.push(chart);
            if (pie && viewMode === "dynamic") nextChartsByIndex.set(index, chart);
            chart.update("none");
            if (viewMode === "static") {
              const image = staticImage(canvas, dark, label, pie ? G.describePie(single, root.getAttribute("aria-label")) : "");
              chart.destroy();
              nextCharts.pop();
              box.replaceChildren(image);
            }
          }
          if (pie) {
            const toggle = chip(label, index, "series");
            toggle.classList.add("luna-series-toggle");
            toggle.title = `${label || "（名称なし）"}の表示切り替え`;
            host.append(toggle);
          }
        });
        const target = viewMode === "static" ? still : dyn;
        const previousCharts = charts;
        target.replaceChildren(...stage.childNodes);
        target.hidden = false;
        (target === dyn ? still : dyn).hidden = true;
        root.dataset.lunaType = viewType;
        stage.remove();
        stage = null;
        charts = nextCharts;
        chartsByIndex = nextChartsByIndex;
        rendered = { type: viewType, mode: viewMode, dark, animate, plotColor, itemKey: items.join(",") };
        previousCharts.forEach((chart) => chart.destroy());
        (target === dyn ? still : dyn).replaceChildren();
        if (viewMode === "dynamic") {
          nextCharts.forEach((chart) => {
            chart.resize();
            if (animate) {
              chart.reset();
              chart.update();
            } else chart.update("none");
          });
        }
        if (restore !== null) {
          root.querySelector(`[data-luna-role="series"][data-index="${restore}"]`)?.focus({ preventScroll: true });
        }
      } catch (e) {
        stage?.remove();
        nextCharts.forEach((chart) => chart.destroy());
        if (rendered) {
          viewType = rendered.type;
          viewMode = rendered.mode;
          syncSegments();
          mountChips();
          syncLegend();
        } else {
          root.dataset.lunaType = spec.type;
          still.innerHTML = initial;
          still.hidden = false;
          dyn.hidden = true;
        }
        status.textContent = "動的描画に失敗しました。初期グラフを表示しています。";
      }
    }
    mountChips();
    syncSegments();
    root.querySelectorAll(".luna-segment").forEach((group) => {
      group.addEventListener("keydown", (event) => {
        if (!["ArrowRight", "ArrowLeft", "ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
        const buttons = Array.from(group.querySelectorAll("button"));
        const index = buttons.indexOf(document.activeElement);
        if (index < 0) return;
        event.preventDefault();
        const delta = event.key === "ArrowRight" || event.key === "ArrowDown" ? 1 : buttons.length - 1;
        const next = event.key === "Home" ? buttons[0] : event.key === "End" ? buttons[buttons.length - 1] : buttons[(index + delta) % buttons.length];
        next.focus();
        next.click();
      });
    });
    $(".luna-chips").hidden = false;
    $(".luna-toolbar").hidden = false;
    $(".luna-fallback-legend").hidden = true;
    const legend = $(".luna-aside");
    const narrowLegend = window.matchMedia("(max-width: 720px)");
    const syncLegend = () => {
      if (legend) legend.open = viewType === "pie" || !narrowLegend.matches;
    };
    narrowLegend.addEventListener("change", syncLegend);
    syncLegend();
    root.querySelectorAll("[data-luna-type]").forEach((btn) =>
      btn.addEventListener("click", () => {
        if (viewType === btn.dataset.lunaType) return;
        viewType = btn.dataset.lunaType;
        syncSegments();
        mountChips();
        render();
      }),
    );
    root.querySelectorAll("[data-luna-view]").forEach((btn) =>
      btn.addEventListener("click", () => {
        if (viewMode === btn.dataset.lunaView) return;
        viewMode = btn.dataset.lunaView;
        syncSegments();
        render();
      }),
    );
    let lastDark = isDark();
    const refreshTheme = () => {
      const dark = isDark();
      if (dark === lastDark) return;
      lastDark = dark;
      render();
    };
    const media = window.matchMedia("(prefers-color-scheme: dark)");
    media.addEventListener("change", refreshTheme);
    const watch = new MutationObserver(refreshTheme);
    watch.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ["data-theme", "class"],
    });
    if (document.body)
      watch.observe(document.body, {
        attributes: true,
        attributeFilter: ["data-theme", "class"],
      });
    render();
  }
  function all() {
    document.querySelectorAll(".luna-chart[data-luna-spec]").forEach(boot);
  }
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", all);
  else all();
})(window.LunaGraph);
