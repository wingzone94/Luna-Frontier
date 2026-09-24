(function (wp, G) {
  "use strict";
  const {
    createElement: h,
    useState,
    useRef,
    useEffect,
    Fragment,
  } = wp.element;
  const { InspectorControls, useBlockProps } = wp.blockEditor;
  const {
    PanelBody,
    SelectControl,
    TextControl,
    TextareaControl,
    RangeControl,
    Notice,
    Button,
    CheckboxControl,
  } = wp.components;
  const attrs = {
    title: { type: "string", default: "" },
    caption: { type: "string", default: "" },
    source: { type: "string", default: "" },
    sourceUrl: { type: "string", default: "" },
    chartType: { type: "string", default: "bar" },
    allowedTypes: { type: "array", default: ["bar", "line", "pie"] },
    mode: { type: "string", default: "dynamic" },
    labels: { type: "array", default: [] },
    datasets: { type: "array", default: [] },
    fileName: { type: "string", default: "" },
    staticUrl: { type: "string", default: "" },
    staticId: { type: "number", default: 0 },
    height: { type: "number", default: 320 },
  };
  function Edit({ attributes: a, setAttributes, clientId }) {
    const types = [
      { label: "棒グラフ", value: "bar" },
      { label: "折れ線グラフ", value: "line" },
      { label: "円グラフ", value: "pie" },
    ];
    const allowed = types.filter(({ value }) =>
      Array.isArray(a.allowedTypes) ? a.allowedTypes.includes(value) : true,
    );
    const enabled = allowed.length ? allowed : types.slice(0, 1);
    const initialType = enabled.some(({ value }) => value === a.chartType)
      ? a.chartType
      : enabled[0].value;
    const pieAllowed = enabled.some(({ value }) => value === "pie");
    const pieOverflow = pieAllowed && a.datasets.length > G.limits.pieSeries;
    const [error, setError] = useState(""),
      [busy, setBusy] = useState(false),
      [page, setPage] = useState(0),
      [book, setBook] = useState(null),
      [sheet, setSheet] = useState(""),
      [encoding, setEncoding] = useState("utf-8"),
      [drafts, setDrafts] = useState({}),
      [hiddenSeries, setHiddenSeries] = useState([]),
      [hiddenItems, setHiddenItems] = useState([]),
      [previewImages, setPreviewImages] = useState({});
    const ref = useRef(null),
      pieRefs = useRef([]),
      fileRef = useRef(null),
      request = useRef(0),
      mounted = useRef(true);
    useEffect(() => {
      mounted.current = true;
      return () => {
        mounted.current = false;
        request.current++;
      };
    }, []);
    const hasInvalid = Object.values(drafts).some((v) => {
      try {
        G.number(v);
        return false;
      } catch (e) {
        return true;
      }
    });
    useEffect(() => {
      const actions = wp.data.dispatch("core/editor");
      if (!actions || !actions.lockPostSaving) return;
      const key = "luna-invalid-" + clientId;
      if (hasInvalid || pieOverflow) actions.lockPostSaving(key);
      else actions.unlockPostSaving(key);
      return () => actions.unlockPostSaving(key);
    }, [hasInvalid, pieOverflow, clientId]);
    const set = (v) => setAttributes({ ...v, staticUrl: "", staticId: 0 });
    useEffect(() => {
      const charts = [];
      try {
        if (window.Chart) {
          const explicit = document.documentElement.getAttribute("data-theme");
          const dark =
            explicit === "dark" ||
            (explicit !== "light" &&
              window.matchMedia("(prefers-color-scheme: dark)").matches);
          const spec = { type: initialType, labels: a.labels, datasets: a.datasets };
          const items = a.labels.map((_, i) => i).filter((i) => !hiddenItems.includes(i));
          const series = a.datasets.map((_, i) => i).filter((i) => !hiddenSeries.includes(i));
          const entries = initialType === "pie"
            ? a.datasets.map((_, i) => ({ canvas: pieRefs.current[i], data: G.filter(spec, { series: [i], items }), index: i }))
            : [{ canvas: ref.current, data: G.filter(spec, { series, items }), index: 0 }];
          const images = {};
          entries.forEach(({ canvas, data, index }) => {
            if (!canvas) return;
            const plotColor = getComputedStyle(canvas.closest(".luna-m3-surface")).backgroundColor;
            const chart = new window.Chart(canvas, G.config({ ...data, type: initialType }, { dark, plotColor }));
            charts.push(chart);
            chart.update("none");
            if (a.mode === "static") images[index] = canvas.toDataURL("image/webp", 0.95);
          });
          setPreviewImages(images);
        }
      } catch (e) {
        setError("プレビューを表示できません: " + e.message);
      }
      return () => {
        charts.forEach((chart) => chart.destroy());
      };
    }, [a.labels, a.datasets, initialType, a.height, a.mode, hiddenSeries, hiddenItems]);
    function toggleType(value, checked) {
      if (value === "pie" && checked && a.datasets.length > G.limits.pieSeries) {
        setError("円グラフを許可する場合、系列は5つ以下にしてください。");
        return;
      }
      const next = checked
        ? types.filter((type) => enabled.some((item) => item.value === type.value) || type.value === value)
        : enabled.filter((type) => type.value !== value);
      if (!next.length) return;
      set({
        allowedTypes: next.map((type) => type.value),
        chartType: next.some((type) => type.value === initialType)
          ? initialType
          : next[0].value,
      });
    }
    function sheetRows(ws) {
      const range = ws["!fullref"] || ws["!ref"];
      if (range) {
        const size = window.XLSX.utils.decode_range(range);
        if (size.e.r > G.limits.rows || size.e.c > G.limits.series)
          throw new Error(
            "最大1,000データ行・20系列です。シートの範囲を小さくしてください。",
          );
      }
      return window.XLSX.utils.sheet_to_json(ws, {
        header: 1,
        defval: null,
        raw: false,
        dateNF: "yyyy-mm-dd",
      });
    }
    function applyRows(rows, name) {
      const parsed = G.table(rows);
      if (pieAllowed && parsed.datasets.length > G.limits.pieSeries)
        throw new Error("円グラフを許可する場合、系列は5つ以下にしてください。");
      set({ ...parsed, fileName: name });
      setPage(0);
      setDrafts({});
      setError("");
    }
    async function onFile(e) {
      const file = e.target.files[0];
      e.target.value = "";
      if (!file) return;
      const id = ++request.current;
      setError("");
      setBook(null);
      if (file.size > G.limits.bytes) {
        setError("ファイルは5MB以下にしてください。");
        return;
      }
      setBusy(true);
      try {
        const buffer = await file.arrayBuffer();
        if (!mounted.current || id !== request.current) return;
        if (/\.csv$/i.test(file.name))
          applyRows(
            G.csv(new TextDecoder(encoding, { fatal: true }).decode(buffer)),
            file.name,
          );
        else if (/\.xlsx?$/i.test(file.name)) {
          if (!window.XLSX)
            throw new Error(
              "Excel読み込み機能を開始できません。ページを再読み込みしてください。",
            );
          const wb = window.XLSX.read(buffer, {
            type: "array",
            cellDates: true,
            sheetRows: G.limits.rows + 2,
          });
          setBook({ wb, name: file.name });
          setSheet(wb.SheetNames[0]);
          applyRows(sheetRows(wb.Sheets[wb.SheetNames[0]]), file.name);
        } else throw new Error("CSV・XLSX・XLSを選んでください。");
      } catch (err) {
        if (mounted.current) setError(err.message);
      } finally {
        if (mounted.current && id === request.current) setBusy(false);
      }
    }
    function valueChange(i, j, text) {
      const key = i + ":" + j;
      setDrafts((prev) => ({ ...prev, [key]: text }));
      try {
        const n = G.number(text);
        set({
          datasets: a.datasets.map((d, k) =>
            k === j
              ? { ...d, data: d.data.map((v, r) => (r === i ? n : v)) }
              : d,
          ),
        });
        setError("");
      } catch (e) {
        setError("数値の変更は未反映です。" + e.message);
      }
    }
    const start = page * 25,
      end = Math.min(a.labels.length, start + 25);
    const control = (label, key) =>
      h(TextControl, {
        label,
        value: a[key] || "",
        onChange: (v) => set({ [key]: v }),
      });
    const el = wp.element.createElement;
    const icon = (name) => {
      const common = { viewBox: "0 0 24 24", "aria-hidden": "true", focusable: "false" };
      if (name === "bar")
        return el("svg", common, el("path", { fill: "currentColor", d: "M4 13h4v7H4zm6-8h4v15h-4zm6 5h4v10h-4z" }));
      if (name === "line")
        return el(
          "svg",
          common,
          el("path", { fill: "none", stroke: "currentColor", strokeWidth: 2, strokeLinecap: "round", strokeLinejoin: "round", d: "M4 16.5 8.5 11l3.2 3.2L20 6" }),
          el("circle", { cx: 4, cy: 16.5, r: 1.35, fill: "currentColor" }),
          el("circle", { cx: 8.5, cy: 11, r: 1.35, fill: "currentColor" }),
          el("circle", { cx: 11.7, cy: 14.2, r: 1.35, fill: "currentColor" }),
          el("circle", { cx: 20, cy: 6, r: 1.35, fill: "currentColor" }),
        );
      if (name === "pie")
        return el(
          "svg",
          common,
          el("path", { fill: "currentColor", d: "M11 3.1A8.9 8.9 0 1 0 20.9 13H11V3.1z" }),
          el("path", { fill: "currentColor", opacity: 0.45, d: "M13 2.2A9 9 0 0 1 21.8 11H13V2.2z" }),
        );
      if (name === "dynamic")
        return el("svg", common, el("path", { fill: "none", stroke: "currentColor", strokeWidth: 2, strokeLinecap: "round", strokeLinejoin: "round", d: "M4 15V9m5 6V4m5 4V6M13 11l7 4-3 .8-1.5 3.2z" }));
      return el(
        "svg",
        common,
        el("rect", { x: 3.5, y: 5, width: 17, height: 14, rx: 2, fill: "none", stroke: "currentColor", strokeWidth: 2 }),
        el("circle", { cx: 8.5, cy: 9.5, r: 1.3, fill: "currentColor" }),
        el("path", { fill: "none", stroke: "currentColor", strokeWidth: 2, strokeLinecap: "round", strokeLinejoin: "round", d: "m4 16.2 4.2-3.2 2.8 2 3.6-3.4L20 16.2" }),
      );
    };
    const segment = (label, value, current, onChange, glyph) =>
      h(
        "button",
        {
          type: "button",
          className: "luna-segment-btn" + (["dynamic", "static"].includes(value) ? " luna-view-button" : ""),
          role: "radio",
          "aria-label": label,
          title: label,
          "aria-checked": current === value ? "true" : "false",
          tabIndex: current === value ? 0 : -1,
          onClick: () => onChange(value),
          onKeyDown: (event) => {
            if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
            const buttons = Array.from(event.currentTarget.parentElement.querySelectorAll('[role="radio"]'));
            const index = buttons.indexOf(event.currentTarget);
            const next = buttons[(index + (event.key === "ArrowRight" ? 1 : buttons.length - 1)) % buttons.length];
            event.preventDefault();
            next.focus();
            next.click();
          },
        },
        glyph,
        ["dynamic", "static"].includes(value) && h("span", null, value === "dynamic" ? "動的" : "静的"),
      );
    return h(
      Fragment,
      null,
      h(
        InspectorControls,
        null,
        h(
          PanelBody,
          { title: "グラフ設定" },
          h(SelectControl, {
            label: "公開時の初期形式",
            value: initialType,
            options: enabled,
            onChange: (v) => set({ chartType: v }),
          }),
          h("div", { className: "luna-editor-allowed", role: "group", "aria-label": "公開できるグラフ形式" },
            h("p", null, "公開できるグラフ形式"),
            types.map(({ label, value }) => h(CheckboxControl, {
              key: value,
              label,
              checked: enabled.some((type) => type.value === value),
              disabled: enabled.length === 1 && enabled[0].value === value,
              onChange: (checked) => toggleType(value, checked),
            })),
          ),
          h(SelectControl, {
            label: "公開時の初期ビュー",
            help: "閲覧者は動的・静的を切り替えられます。形式の切り替えは表示中だけで、再読み込みすると初期値に戻ります。",
            value: a.mode,
            options: [
              { label: "動的", value: "dynamic" },
              { label: "静的", value: "static" },
            ],
            onChange: (v) => set({ mode: v }),
          }),
          h(RangeControl, {
            label: "高さ",
            min: 200,
            max: 560,
            value: a.height,
            onChange: (v) => set({ height: v || 320 }),
          }),
          control("出典名", "source"),
          control("出典URL（https://…）", "sourceUrl"),
        ),
      ),
      h(
        "div",
        useBlockProps({ className: "luna-editor-card" }),
        (error || hasInvalid || pieOverflow) &&
          h(
            Notice,
            { status: "error", isDismissible: false },
            error || (pieOverflow ? "円グラフを許可する場合、系列は5つ以下にしてください。" : "未反映の数値があります。入力を修正してください。"),
          ),
        busy && h("p", { role: "status", className: "luna-editor-status" }, "読み込み中…"),
        control("タイトル", "title"),
        h(TextareaControl, {
          label: "説明",
          value: a.caption,
          onChange: (v) => set({ caption: v }),
        }),
        h(
          "div",
          {
            className: "luna-editor-canvas-wrap luna-m3-surface" + (initialType === "pie" ? " luna-editor-pie" : ""),
            style: initialType === "pie" ? undefined : { height: a.height },
          },
          h("div", { className: "luna-header" },
            h("div", { className: "luna-head" },
              h("span", { className: "luna-m3-title" }, a.title || "グラフ"),
              a.caption && h("p", { className: "luna-caption" }, a.caption)),
          h(
            "div",
            { className: "luna-toolbar", role: "group", "aria-label": "グラフの形式と初期ビュー" },
            h(
              "div",
              { className: "luna-segment", role: "radiogroup", "aria-label": "グラフ形式" },
              enabled.map(({ label, value }) => segment(label, value, initialType, (v) => set({ chartType: v }), icon(value))),
            ),
            h(
              "div",
              { className: "luna-segment", role: "radiogroup", "aria-label": "公開時の初期ビュー" },
              segment("動的表示", "dynamic", a.mode, (v) => set({ mode: v }), icon("dynamic")),
              segment("静的表示", "static", a.mode, (v) => set({ mode: v }), icon("static")),
            ),
          )),
          h(
            "div",
            { className: "luna-plot" },
            initialType === "pie"
              ? h("div", { className: "luna-pie-grid", "data-series-count": a.datasets.length }, a.datasets.map((dataset, i) =>
                  h("div", { className: "luna-pie-panel", key: i },
                    h("div", { className: "luna-pie-canvas" },
                      hiddenSeries.includes(i)
                        ? h("span", { className: "luna-pie-empty" }, "非表示")
                        : h(Fragment, null,
                          h("canvas", {
                            ref: (node) => { pieRefs.current[i] = node; }, role: "img",
                            "aria-hidden": a.mode === "static" ? "true" : undefined,
                            "aria-label": G.describePie(G.filter({ type: "pie", labels: a.labels, datasets: a.datasets }, {
                              series: [i], items: a.labels.map((_, index) => index).filter((index) => !hiddenItems.includes(index)),
                            }), a.title || "グラフのプレビュー"),
                            style: a.mode === "static" ? { visibility: "hidden" } : undefined,
                          }),
                          a.mode === "static" && previewImages[i] && h("img", {
                            className: "luna-editor-snapshot", src: previewImages[i],
                            alt: G.describePie(G.filter({ type: "pie", labels: a.labels, datasets: a.datasets }, {
                              series: [i], items: a.labels.map((_, index) => index).filter((index) => !hiddenItems.includes(index)),
                            }), a.title || "グラフのプレビュー") + "（静的画像）",
                          }))),
                    h("button", { type: "button", className: "luna-chip luna-series-toggle",
                      "aria-pressed": !hiddenSeries.includes(i),
                      onClick: () => setHiddenSeries((values) => values.includes(i) ? values.filter((v) => v !== i) : [...values, i]),
                    }, dataset.label || "（名称なし）"),
                  )))
              : h(Fragment, null, h("canvas", {
                  ref, role: "img", "aria-label": a.title || "グラフのプレビュー",
                  "aria-hidden": a.mode === "static" ? "true" : undefined,
                  style: a.mode === "static" ? { visibility: "hidden" } : undefined,
                }), a.mode === "static" && previewImages[0] && h("img", {
                  className: "luna-editor-snapshot", src: previewImages[0], alt: (a.title || "グラフ") + "（静的画像）",
                })),
          ),
          initialType === "pie" && h("div", { className: "luna-editor-legend", role: "group", "aria-label": "項目の表示" },
            a.labels.map((label, i) => h("button", { key: i, type: "button", className: "luna-chip",
              "aria-pressed": !hiddenItems.includes(i),
              onClick: () => setHiddenItems((values) => values.includes(i) ? values.filter((v) => v !== i) : [...values, i]),
            }, h("i", { "aria-hidden": true, style: { background: G.palette[i % G.palette.length] } }), label))),
        ),
        initialType === "pie" &&
          h(
            "p",
            { className: "luna-editor-note" },
            "円グラフは系列ごとに個別の円を並べます。負数・欠損値は描画しません。",
          ),
        h(
          "div",
          { className: "luna-editor-toolbar" },
          h(
            Button,
            {
              variant: "secondary",
              disabled: busy,
              onClick: () => fileRef.current.click(),
            },
            "CSV / Excelを読み込む",
          ),
          h("input", {
            ref: fileRef,
            type: "file",
            hidden: true,
            accept: ".csv,.xlsx,.xls",
            onChange: onFile,
          }),
          h(SelectControl, {
            label: "CSV文字コード",
            value: encoding,
            options: [
              { label: "UTF-8", value: "utf-8" },
              { label: "Shift_JIS", value: "shift_jis" },
            ],
            onChange: setEncoding,
          }),
        ),
        book &&
          h(SelectControl, {
            label: "Excelシート",
            value: sheet,
            options: book.wb.SheetNames.map((n) => ({ label: n, value: n })),
            onChange: (n) => {
              setSheet(n);
              try {
                applyRows(sheetRows(book.wb.Sheets[n]), book.name);
              } catch (e) {
                setError(e.message);
              }
            },
          }),
        a.fileName && h("p", { className: "luna-editor-note" }, "読み込み元: " + a.fileName),
        h(
          "details",
          { className: "luna-data-editor" },
          h("summary", null, "データを編集"),
          h(
            "p",
            { className: "luna-editor-note" },
            "空欄は欠損値として保持します。日付で絞り込む場合は項目名を YYYY-MM-DD にしてください。",
          ),
          h(
            "div",
            { className: "luna-table-scroll" },
            h(
              "table",
              null,
              h(
                "thead",
                null,
                h(
                  "tr",
                  null,
                  h("th", null, "項目"),
                  ...a.datasets.map((d, j) =>
                    h(
                      "th",
                      { key: j },
                      h(TextControl, {
                        "aria-label": "系列" + (j + 1) + "の名前",
                        value: d.label,
                        onChange: (v) =>
                          set({
                            datasets: a.datasets.map((x, k) =>
                              k === j ? { ...x, label: v } : x,
                            ),
                          }),
                      }),
                      h(
                        Button,
                        {
                          isDestructive: true,
                          onClick: () => {
                            set({
                              datasets: a.datasets.filter((_, k) => k !== j),
                            });
                            setDrafts({});
                          },
                        },
                        "系列を削除",
                      ),
                    ),
                  ),
                  h("th", null, "操作"),
                ),
              ),
              h(
                "tbody",
                null,
                ...a.labels.slice(start, end).map((label, n) => {
                  const i = start + n;
                  return h(
                    "tr",
                    { key: i },
                    h(
                      "td",
                      null,
                      h(TextControl, {
                        "aria-label": i + 1 + "行の項目",
                        value: label,
                        onChange: (v) =>
                          set({
                            labels: a.labels.map((x, k) => (k === i ? v : x)),
                          }),
                      }),
                    ),
                    ...a.datasets.map((d, j) =>
                      h(
                        "td",
                        { key: j },
                        h(TextControl, {
                          "aria-label": label + " / " + d.label,
                          value: drafts[i + ":" + j] ?? d.data[i] ?? "",
                          onChange: (v) => valueChange(i, j, v),
                          onBlur: () => {
                            const key = i + ":" + j;
                            try {
                              G.number(drafts[key]);
                              setDrafts((prev) => {
                                const next = { ...prev };
                                delete next[key];
                                return next;
                              });
                            } catch (e) {}
                          },
                        }),
                      ),
                    ),
                    h(
                      "td",
                      null,
                      h(
                        Button,
                        {
                          isDestructive: true,
                          onClick: () => {
                            set({
                              labels: a.labels.filter((_, k) => k !== i),
                              datasets: a.datasets.map((d) => ({
                                ...d,
                                data: d.data.filter((_, k) => k !== i),
                              })),
                            });
                            setPage(0);
                            setDrafts({});
                          },
                        },
                        "行を削除",
                      ),
                    ),
                  );
                }),
              ),
            ),
          ),
          h(
            "div",
            { className: "luna-editor-toolbar" },
            h(
              Button,
              {
                variant: "secondary",
                disabled: a.labels.length >= G.limits.rows,
                onClick: () => {
                  set({
                    labels: [...a.labels, "項目 " + (a.labels.length + 1)],
                    datasets: a.datasets.map((d) => ({
                      ...d,
                      data: [...d.data, null],
                    })),
                  });
                  setPage(Math.floor(a.labels.length / 25));
                },
              },
              "行を追加",
            ),
            h(
              Button,
              {
                variant: "secondary",
                disabled: a.datasets.length >= (pieAllowed ? G.limits.pieSeries : G.limits.series),
                onClick: () =>
                  set({
                    datasets: [
                      ...a.datasets,
                      {
                        label: "系列 " + (a.datasets.length + 1),
                        data: a.labels.map(() => null),
                      },
                    ],
                  }),
              },
              "系列を追加",
            ),
            h(
              Button,
              { disabled: page === 0, onClick: () => setPage(page - 1) },
              "前へ",
            ),
            h(
              "span",
              null,
              a.labels.length
                ? `${start + 1}–${end} / ${a.labels.length}行`
                : "0行",
            ),
            h(
              Button,
              {
                disabled: end >= a.labels.length,
                onClick: () => setPage(page + 1),
              },
              "次へ",
            ),
          ),
        ),
      ),
    );
  }
  wp.blocks.registerBlockType("luna-interactive/chart", {
    apiVersion: 3,
    title: "グラフ",
    description: "表データを編集し、動的・静的グラフを表示します。",
    icon: "chart-bar",
    category: "widgets",
    supports: { html: false },
    attributes: attrs,
    edit: Edit,
    save: () => null,
  });
})(window.wp, window.LunaGraph);
