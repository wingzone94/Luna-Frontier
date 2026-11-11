(function (wp, config) {
  "use strict";

  const { createElement: h, Fragment } = wp.element;
  const {
    InspectorControls,
    MediaUpload,
    MediaUploadCheck,
    MediaPlaceholder,
    useBlockProps,
  } = wp.blockEditor;
  const {
    PanelBody,
    SelectControl,
    TextControl,
    ToggleControl,
    Button,
  } = wp.components;
  const ServerSideRender = wp.serverSideRender;

  const fallbackPresets = [
    { id: "amazon", label: "Amazon", color: "#FF9900", tier: 1 },
    { id: "rakuten", label: "楽天市場", color: "#BF0000", tier: 1 },
    { id: "anker", label: "Anker Store", color: "#00A9E0", tier: 2 },
    { id: "nintendo", label: "My Nintendo Store", color: "#E60012", tier: 2 },
    { id: "apple", label: "Apple Store", color: "#333333", tier: 2 },
    { id: "microsoft", label: "Microsoft Store", color: "#107C10", tier: 2 },
    { id: "playstation", label: "PlayStation Store", color: "#003087", tier: 2 },
    { id: "steam", label: "Steam", color: "#00BCF2", tier: 2 },
    { id: "yodobashi", label: "ヨドバシカメラ", color: "#CC0000", tier: 3 },
    { id: "biccamera", label: "ビックカメラ", color: "#E60012", tier: 3 },
    { id: "joshin", label: "Joshin", color: "#004098", tier: 3 },
    { id: "sofmap", label: "ソフマップ", color: "#005BAC", tier: 3 },
    { id: "yahoo", label: "Yahoo!ショッピング", color: "#FF0033", tier: 3 },
    { id: "custom", label: "カスタム（自由入力）", color: "#333333", tier: 3 },
  ];

  const presets =
    config && Array.isArray(config.presets) && config.presets.length
      ? config.presets
      : fallbackPresets;

  const defaultStores =
    config && Array.isArray(config.defaultStores) && config.defaultStores.length
      ? config.defaultStores
      : [
          {
            preset: "amazon",
            label: "",
            color: "",
            url: "",
            price: 0,
            listPrice: 0,
            saleNote: "",
            official: false,
          },
          {
            preset: "rakuten",
            label: "",
            color: "",
            url: "",
            price: 0,
            listPrice: 0,
            saleNote: "",
            official: false,
          },
        ];

  const presetOptions = presets.map((p) => ({
    label: p.label,
    value: p.id,
  }));

  function normalizeStore(row) {
    const r = row && typeof row === "object" ? row : {};
    return {
      preset: typeof r.preset === "string" && r.preset ? r.preset : "amazon",
      label: typeof r.label === "string" ? r.label : "",
      color: typeof r.color === "string" ? r.color : "",
      url: typeof r.url === "string" ? r.url : "",
      price: Number.isFinite(Number(r.price)) ? Math.max(0, Math.trunc(Number(r.price))) : 0,
      listPrice: Number.isFinite(Number(r.listPrice)) ? Math.max(0, Math.trunc(Number(r.listPrice))) : 0,
      saleNote: typeof r.saleNote === "string" ? r.saleNote : "",
      official: Boolean(r.official),
    };
  }

  const attrs = {
    title: { type: "string", default: "" },
    spec: { type: "string", default: "" },
    imageId: { type: "number", default: 0 },
    imageUrl: { type: "string", default: "" },
    asin: { type: "string", default: "" },
    checkedAt: { type: "string", default: "" },
    stores: { type: "array", default: defaultStores },
  };

  function Edit({ attributes: a, setAttributes }) {
    const stores = (Array.isArray(a.stores) ? a.stores : defaultStores)
      .slice(0, 4)
      .map(normalizeStore);

    function updateStore(index, patch) {
      const next = stores.map((item, idx) =>
        idx === index ? normalizeStore({ ...item, ...patch }) : item
      );
      setAttributes({ stores: next });
    }

    function moveStore(index, delta) {
      const target = index + delta;
      if (target < 0 || target >= stores.length) return;
      const next = [...stores];
      const temp = next[index];
      next[index] = next[target];
      next[target] = temp;
      setAttributes({ stores: next });
    }

    function removeStore(index) {
      const next = stores.filter((_, idx) => idx !== index);
      setAttributes({ stores: next });
    }

    function addStore() {
      if (stores.length >= 4) return;
      const next = [
        ...stores,
        normalizeStore({
          preset: "anker",
          label: "",
          color: "",
          url: "",
          price: 0,
          listPrice: 0,
          saleNote: "",
          official: false,
        }),
      ];
      setAttributes({ stores: next });
    }

    function onSelectMedia(media) {
      if (!media || typeof media !== "object") return;
      const url =
        (media.sizes && media.sizes.large && media.sizes.large.url) ||
        media.url ||
        "";
      setAttributes({
        imageId: Number(media.id) || 0,
        imageUrl: url,
      });
    }

    function getPresetLabel(presetId) {
      const found = presets.find((p) => p.id === presetId);
      return found ? found.label : presetId;
    }

    return h(
      Fragment,
      null,
      h(
        InspectorControls,
        null,
        h(
          PanelBody,
          { title: "商品基本情報", initialOpen: true },
          h(TextControl, {
            label: "商品名",
            value: a.title || "",
            onChange: (v) => setAttributes({ title: v }),
          }),
          h(TextControl, {
            label: "スペック行",
            help: "例：45W・Display・スイングプラグ／ホワイト",
            value: a.spec || "",
            onChange: (v) => setAttributes({ spec: v }),
          }),
          h(TextControl, {
            label: "Amazon ASIN（任意）",
            help: "入力すると Amazon 行の URL（amazon.co.jp/dp/{ASIN}）を自動構築します。",
            value: a.asin || "",
            onChange: (v) => setAttributes({ asin: v }),
          }),
          h(TextControl, {
            label: "価格確認日（任意）",
            help: "例：2026年11月11日（カード内注記に「{確認日} 時点の価格」と表示）",
            value: a.checkedAt || "",
            onChange: (v) => setAttributes({ checkedAt: v }),
          }),
          h(TextControl, {
            label: "商品画像 URL（直接入力またはメディア選択）",
            value: a.imageUrl || "",
            onChange: (v) => setAttributes({ imageUrl: v }),
          })
        ),
        h(
          PanelBody,
          { title: "ストア行（最大4行）", initialOpen: true },
          stores.map((store, index) =>
            h(
              "div",
              { className: "luna-products-inspector-store", key: index },
              h(
                "div",
                { className: "luna-products-inspector-store__head" },
                h(
                  "span",
                  null,
                  `#${index + 1} ${store.label || getPresetLabel(store.preset)}`
                ),
                h(
                  "div",
                  { className: "luna-products-inspector-store__actions" },
                  h(
                    Button,
                    {
                      size: "small",
                      variant: "secondary",
                      disabled: index === 0,
                      onClick: () => moveStore(index, -1),
                    },
                    "上へ"
                  ),
                  h(
                    Button,
                    {
                      size: "small",
                      variant: "secondary",
                      disabled: index === stores.length - 1,
                      onClick: () => moveStore(index, 1),
                    },
                    "下へ"
                  ),
                  h(
                    Button,
                    {
                      size: "small",
                      isDestructive: true,
                      onClick: () => removeStore(index),
                    },
                    "削除"
                  )
                )
              ),
              h(SelectControl, {
                label: "ストアプリセット",
                value: store.preset,
                options: presetOptions,
                onChange: (v) => updateStore(index, { preset: v }),
              }),
              h(TextControl, {
                label:
                  store.preset === "custom"
                    ? "表示名（必須）"
                    : "表示名の上書き（空欄でプリセット名）",
                value: store.label,
                onChange: (v) => updateStore(index, { label: v }),
              }),
              store.preset === "custom" &&
                h(TextControl, {
                  label: "ブランド色（16進数・任意）",
                  help: "例：#0066CC",
                  value: store.color,
                  onChange: (v) => updateStore(index, { color: v }),
                }),
              h(TextControl, {
                label: "商品ページ URL",
                help:
                  store.preset === "amazon"
                    ? "ASIN が設定されている場合は ASIN 由来の URL が優先されます。"
                    : undefined,
                value: store.url,
                onChange: (v) => updateStore(index, { url: v }),
              }),
              h(TextControl, {
                label: "価格（円・税込、0 で非表示）",
                type: "number",
                min: 0,
                value: store.price > 0 ? String(store.price) : "",
                onChange: (v) =>
                  updateStore(index, {
                    price: v === "" ? 0 : Math.max(0, parseInt(v, 10) || 0),
                  }),
              }),
              h(TextControl, {
                label: "定価（円・税込、価格より大きい場合のみ OFF 表示）",
                type: "number",
                min: 0,
                value: store.listPrice > 0 ? String(store.listPrice) : "",
                onChange: (v) =>
                  updateStore(index, {
                    listPrice: v === "" ? 0 : Math.max(0, parseInt(v, 10) || 0),
                  }),
              }),
              h(TextControl, {
                label: "セール期限・条件の文（任意）",
                help: "例：11月7日まで",
                value: store.saleNote,
                onChange: (v) => updateStore(index, { saleNote: v }),
              }),
              h(ToggleControl, {
                label: "「公式」チップを表示",
                checked: Boolean(store.official),
                onChange: (v) => updateStore(index, { official: Boolean(v) }),
              })
            )
          ),
          h(
            Button,
            {
              variant: "secondary",
              disabled: stores.length >= 4,
              onClick: addStore,
            },
            stores.length >= 4
              ? "ストア行は最大4行です"
              : "ストア行を追加"
          )
        )
      ),
      h(
        "div",
        useBlockProps({ className: "luna-products-editor" }),
        h(
          "div",
          { className: "luna-products-editor__canvas-controls" },
          h(
            "div",
            { className: "luna-products-editor__media-box" },
            a.imageUrl
              ? h(
                  Fragment,
                  null,
                  h("img", {
                    className: "luna-products-editor__thumb",
                    src: a.imageUrl,
                    alt: a.title || "",
                  }),
                  MediaUploadCheck && MediaUpload
                    ? h(
                        MediaUploadCheck,
                        null,
                        h(MediaUpload, {
                          onSelect: onSelectMedia,
                          allowedTypes: ["image"],
                          value: a.imageId || 0,
                          render: ({ open }) =>
                            h(
                              Button,
                              { size: "small", variant: "secondary", onClick: open },
                              "画像を変更"
                            ),
                        })
                      )
                    : null,
                  h(
                    Button,
                    {
                      size: "small",
                      isDestructive: true,
                      onClick: () => setAttributes({ imageId: 0, imageUrl: "" }),
                    },
                    "削除"
                  )
                )
              : MediaPlaceholder
                ? h(MediaPlaceholder, {
                    icon: "products",
                    labels: { title: "商品画像" },
                    allowedTypes: ["image"],
                    onSelect: onSelectMedia,
                    onSelectURL: (url) => setAttributes({ imageId: 0, imageUrl: url }),
                  })
                : null
          ),
          h(
            "div",
            { className: "luna-products-editor__fields" },
            h(TextControl, {
              label: "商品名",
              placeholder: "例：Anker Nano Charger (45W)",
              value: a.title || "",
              onChange: (v) => setAttributes({ title: v }),
            }),
            h(TextControl, {
              label: "スペック行",
              placeholder: "例：45W・Display・スイングプラグ／ホワイト",
              value: a.spec || "",
              onChange: (v) => setAttributes({ spec: v }),
            })
          )
        ),
        h(
          "div",
          { className: "luna-products-editor__preview" },
          ServerSideRender
            ? h(ServerSideRender, {
                block: "luna-products/card",
                attributes: a,
              })
            : null
        )
      )
    );
  }

  wp.blocks.registerBlockType("luna-products/card", {
    apiVersion: 3,
    title: "商品カード（Luna Products）",
    description: "商品画像・スペック・複数ストアの価格と購入導線を表示します。",
    icon: "cart",
    category: "widgets",
    supports: { html: false },
    attributes: attrs,
    edit: Edit,
    save: () => null,
  });
})(window.wp, window.LunaProducts || {});
