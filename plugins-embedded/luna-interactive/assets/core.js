/* Luna Interactive 1.4.0 — shared data and chart logic, GPL-2.0-or-later.
   Series palette is intentionally separate from the Luna Frontier brand orange. */
(function (root) {
  "use strict";
  const palette = [
    "#0F8F86",
    "#3B6FE0",
    "#8A4FD0",
    "#E0567A",
    "#3C8C48",
    "#D06A1C",
    "#1E88B5",
    "#C43B8A",
  ];
  const limits = { rows: 1000, series: 20, pieSeries: 5, bytes: 5 * 1024 * 1024 };
  function number(value) {
    if (
      value == null ||
      String(value).trim() === "" ||
      /^(N\/A|NA|null|—|-)$/i.test(String(value).trim())
    )
      return null;
    const s = String(value).trim();
    if (
      !/^[+-]?(?:(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(
        s,
      )
    )
      throw new Error("数値として読めません: " + s.slice(0, 40));
    const n = Number(s.replace(/,/g, ""));
    if (!Number.isFinite(n) || Math.abs(n) > 1e15)
      throw new Error("数値は±1,000兆の範囲にしてください。");
    return n;
  }
  function csv(text) {
    text = text.replace(/^\uFEFF/, "");
    const rows = [];
    let row = [],
      cell = "",
      quoted = false,
      closed = false;
    for (let i = 0; i < text.length; i++) {
      const c = text[i];
      if (quoted) {
        if (c === '"') {
          if (text[i + 1] === '"') {
            cell += '"';
            i++;
          } else {
            quoted = false;
            closed = true;
          }
        } else cell += c;
        continue;
      }
      if (c === '"' && cell === "") {
        quoted = true;
        continue;
      }
      if (c === "," || c === "\n" || c === "\r") {
        row.push(cell);
        cell = "";
        closed = false;
        if (c !== ",") {
          rows.push(row);
          row = [];
          if (c === "\r" && text[i + 1] === "\n") i++;
        }
        continue;
      }
      if (closed && !/\s/.test(c))
        throw new Error("CSVの引用符の後に不正な文字があります。");
      if (!closed) cell += c;
    }
    if (quoted) throw new Error("CSVの引用符が閉じられていません。");
    row.push(cell);
    rows.push(row);
    return rows.filter((r) => r.some((c) => String(c).trim() !== ""));
  }
  function table(rows) {
    if (rows.length < 2 || rows[0].length < 2)
      throw new Error(
        "1行目に見出し、1列目に項目名、2列目以降に数値を入力してください。",
      );
    if (rows.length - 1 > limits.rows || rows[0].length - 1 > limits.series)
      throw new Error("最大1,000行・20系列です。");
    const header = rows[0];
    const body = rows
      .slice(1)
      .filter((r) => r.some((v) => v != null && String(v).trim() !== ""));
    if (body.some((r) => r.length > header.length))
      throw new Error("見出しより列数が多い行があります。");
    const labels = body.map((r) => String(r[0] ?? ""));
    const datasets = header.slice(1).map((name, col) => ({
      label: String(name || "系列 " + (col + 1)),
      data: body.map((r, i) => {
        try {
          return number(r[col + 1]);
        } catch (e) {
          throw new Error(i + 2 + "行 " + (col + 2) + "列: " + e.message);
        }
      }),
    }));
    return { labels, datasets };
  }
  function isoDate(s) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return false;
    const d = new Date(s + "T00:00:00Z");
    return Number.isFinite(d.getTime()) && d.toISOString().slice(0, 10) === s;
  }
  function filter(spec, f) {
    const selected = spec.datasets
      .map((d, i) => i)
      .filter((i) => !f.series || f.series.includes(i));
    const indices = spec.labels
      .map((_, i) => i)
      .filter((i) => {
        const label = String(spec.labels[i]);
        if (f.items && !f.items.includes(i)) return false;
        if (
          f.query &&
          !label.toLocaleLowerCase().includes(f.query.toLocaleLowerCase())
        )
          return false;
        if (
          (f.from || f.to) &&
          (!isoDate(label) ||
            (f.from && label < f.from) ||
            (f.to && label > f.to))
        )
          return false;
        if (f.min != null || f.max != null)
          return selected.some((j) => {
            const n = spec.datasets[j].data[i];
            return (
              n != null &&
              (f.min == null || n >= f.min) &&
              (f.max == null || n <= f.max)
            );
          });
        return true;
      });
    return {
      type: spec.type,
      labels: indices.map((i) => spec.labels[i]),
      datasets: selected.map((j) => ({
        ...spec.datasets[j],
        colorIndex: j,
        data: indices.map((i) => spec.datasets[j].data[i]),
      })),
      indices,
    };
  }
  function finiteOrNull(value) {
    if (value == null || value === "") return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
  }
  function formatFull(n) {
    if (n == null || !Number.isFinite(Number(n))) return "—";
    return new Intl.NumberFormat("ja-JP", { maximumFractionDigits: 10 }).format(
      Number(n),
    );
  }
  function formatAxis(n) {
    if (!Number.isFinite(n)) return "";
    const abs = Math.abs(n);
    if (abs !== 0 && abs < 0.001)
      return new Intl.NumberFormat("ja-JP", {
        maximumSignificantDigits: 3,
      }).format(n);
    if (abs >= 10000)
      return new Intl.NumberFormat("ja-JP", {
        notation: "compact",
        maximumFractionDigits: 1,
      }).format(n);
    return new Intl.NumberFormat("ja-JP", { maximumFractionDigits: 4 }).format(
      n,
    );
  }
  function formatTickLabel(label) {
    const s = String(label ?? "");
    if (isoDate(s)) return Number(s.slice(5, 7)) + "/" + Number(s.slice(8, 10));
    return s.length > 10 ? s.slice(0, 9) + "…" : s;
  }
  function themeColors(dark) {
    return dark
      ? {
          ink: "#ebe0d9",
          muted: "#D6C6B8",
          grid: "rgba(214, 198, 184, 0.2)",
          tipBg: "#3D372E",
          tipTitle: "#ebe0d9",
          tipBody: "#D6C6B8",
          tipBorder: "#9F8E7D",
          pointStroke: "#25221b",
        }
      : {
          ink: "#2b1700",
          muted: "#6B5A4A",
          grid: "rgba(133, 115, 98, 0.22)",
          tipBg: "#ffffff",
          tipTitle: "#2b1700",
          tipBody: "#6B5A4A",
          tipBorder: "#d6c2b1",
          pointStroke: "#ffffff",
        };
  }
  function config(spec, darkOrOptions) {
    const opt =
      darkOrOptions && typeof darkOrOptions === "object"
        ? darkOrOptions
        : { dark: !!darkOrOptions };
    const dark = !!opt.dark;
    const colors = opt.colors || themeColors(dark);
    const pie = spec.type === "pie";
    const yMin = finiteOrNull(opt.yMin);
    const yMax = finiteOrNull(opt.yMax);
    const limited = !pie && (yMin != null || yMax != null);
    const dense = (spec.labels || []).length > 48;
    const yScale = {
      beginAtZero: !limited,
      grace: limited ? 0 : "6%",
      ticks: {
        color: colors.muted,
        maxTicksLimit: 6,
        padding: 8,
        callback(value) {
          return formatAxis(Number(value));
        },
      },
      grid: { color: colors.grid, drawTicks: false },
      border: { display: false },
    };
    if (yMin != null) yScale.min = yMin;
    if (yMax != null) yScale.max = yMax;
    return {
      type: spec.type || "bar",
      data: {
        labels: spec.labels,
        datasets: spec.datasets.map((d, i) => {
          const color = palette[(d.colorIndex ?? i) % palette.length];
          const sliceColors = pie
            ? spec.labels.map(
                (_, j) =>
                  palette[(spec.indices ? spec.indices[j] : j) % palette.length],
              )
            : color;
          return {
            label: d.label,
            data: pie
              ? d.data.map((v) => (v == null || v < 0 ? null : v))
              : d.data,
            sourceData: d.data,
            backgroundColor: sliceColors,
            borderColor: pie ? (opt.plotColor || colors.pointStroke) : color,
            borderAlign: pie ? "inner" : "center",
            pointBackgroundColor: color,
            pointBorderColor: colors.pointStroke,
            pointBorderWidth: spec.type === "line" ? 1.5 : 0,
            pointRadius: spec.type === "line" ? (dense ? 0 : 3) : 0,
            pointHoverRadius: spec.type === "line" ? (dense ? 5 : 6) : 0,
            pointHitRadius: 12,
            hoverBackgroundColor: sliceColors,
            hoverBorderColor: pie ? (opt.plotColor || colors.pointStroke) : colors.ink,
            hoverBorderWidth: pie ? 3 : spec.type === "bar" ? 2 : 2.5,
            hoverOffset: pie ? 5 : 0,
            borderWidth: spec.type === "line" ? 2.5 : pie ? 2 : 0,
            borderRadius: spec.type === "bar" ? 6 : 0,
            tension: 0,
            fill: false,
            spanGaps: false,
          };
        }),
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: 0,
        animation: opt.animate
          ? pie
            ? { duration: 900, easing: "easeOutQuart", animateRotate: true, animateScale: false }
            : { duration: 240, easing: "easeOutCubic" }
          : false,
        locale: "ja-JP",
        color: colors.ink,
        font: {
          family: '"Inter", "Noto Sans JP", "Hiragino Sans", "Yu Gothic", sans-serif',
          size: 12,
        },
        interaction: {
          mode: "nearest",
          intersect: true,
        },
        layout: { padding: pie ? 2 : { top: 12, right: 12, bottom: 4, left: 4 } },
        plugins: {
          legend: { display: false },
          tooltip: {
            enabled: !pie || (!!opt.pieHoverPercent && !opt.pieExternalTooltip),
            external: pie ? opt.pieExternalTooltip : undefined,
            filter: (_item, index) => index === 0,
            position: "nearest",
            displayColors: false,
            backgroundColor: colors.tipBg,
            titleColor: colors.tipTitle,
            bodyColor: colors.tipBody,
            borderColor: colors.tipBorder,
            borderWidth: 1,
            padding: 8,
            cornerRadius: 8,
            caretSize: 5,
            caretPadding: 6,
            bodyFont: { size: 11 },
            titleFont: { size: 11, weight: "600" },
            titleMarginBottom: 2,
            callbacks: {
              title(items) {
                const label = items[0] ? items[0].label : "";
                return String(label ?? "");
              },
              label(ctx) {
                const name = ctx.dataset?.label || "";
                const src = ctx.dataset?.sourceData
                  ? ctx.dataset.sourceData[ctx.dataIndex]
                  : ctx.raw;
                if (src == null) return name + ": 欠損";
                if (pie && src < 0)
                  return name + ": " + formatFull(src) + "（円では非表示）";
                if (pie) {
                  const total = ctx.dataset.data.reduce(
                    (sum, value) => sum + (Number(value) > 0 ? Number(value) : 0),
                    0,
                  );
                  const percent = total > 0 ? (Number(src) / total) * 100 : 0;
                  return name + ": " + formatFull(src) + "（" +
                    new Intl.NumberFormat("ja-JP", { maximumFractionDigits: 1 }).format(percent) + "%）";
                }
                return name + ": " + formatFull(src);
              },
            },
          },
        },
        scales: pie
          ? {}
          : {
              x: {
                ticks: {
                  color: colors.muted,
                  maxRotation: 0,
                  autoSkip: true,
                  autoSkipPadding: 10,
                  maxTicksLimit: (spec.labels || []).length > 14 ? 8 : 12,
                  callback(value) {
                    const label = this.getLabelForValue
                      ? this.getLabelForValue(value)
                      : value;
                    return formatTickLabel(label);
                  },
                },
                grid: { display: false },
                border: { display: false },
              },
              y: yScale,
            },
      },
    };
  }
  function describePie(spec, title) {
    const dataset = spec.datasets[0];
    const name = dataset?.label || "系列";
    const prefix = `${title || "グラフ"}、${name}。`;
    if (!dataset || !spec.labels.length) return prefix + "表示する項目がありません。";
    const total = dataset.data.reduce(
      (sum, value) => sum + (Number(value) > 0 ? Number(value) : 0), 0,
    );
    if (total <= 0) return prefix + "円に表示できる値がありません。";
    const percent = new Intl.NumberFormat("ja-JP", { maximumFractionDigits: 1 });
    return prefix + spec.labels.map((label, index) => {
      const value = dataset.data[index];
      if (value == null) return `${label || "（名称なし）"}は欠損`;
      if (Number(value) < 0) return `${label || "（名称なし）"}は対象外`;
      return `${label || "（名称なし）"} ${percent.format(Number(value) / total * 100)}%`;
    }).join("、") + "。";
  }
  root.LunaGraph = {
    palette,
    limits,
    number,
    csv,
    table,
    filter,
    config,
    describePie,
    isoDate,
    formatFull,
    formatAxis,
    formatTickLabel,
    themeColors,
  };
  if (typeof module !== "undefined") module.exports = root.LunaGraph;
})(typeof window === "undefined" ? globalThis : window);
