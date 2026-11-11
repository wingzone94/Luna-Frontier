# Luna Products — 検証記録

## 1.0.0（2026-11-11）

### 確認手順

1. **構文検査**
   ```bash
   php -l luna-products/luna-products.php
   php -l luna-products/includes/stores.php
   php -l luna-products/includes/settings.php
   node --check luna-products/assets/editor.js
   ```
2. **最小回帰テスト・CSS変数監査・実レイアウト検証**
   ```bash
   node luna-products/tests/local-regression.cjs
   ```

### 確認結果

- **PHP 構文検査（`php -l`）**:
  - `luna-products/luna-products.php` — No syntax errors detected
  - `luna-products/includes/stores.php` — No syntax errors detected
  - `luna-products/includes/settings.php` — No syntax errors detected
- **JavaScript 構文検査（`node --check`）**:
  - `luna-products/assets/editor.js` — 構文エラーなし
- **描画 HTML 回帰検証（`luna-products/tests/local-regression.cjs`）**:
  - プリセットレジストリ（`luna_products_store_presets()`）に Tier 1（2種）・Tier 2（6種）・Tier 3（6種）の全14種が登録されていることを確認。
  - 価格の3桁カンマ区切り＋「円」整形（`2,790円`、`定価3,990円`、`3,800円`）を確認。
  - OFF 率の切り捨て計算（`(1 - 2790/3990)` → `30% OFF`、および IEEE 754 浮動小数点誤差が発生しやすい `(1 - 710/1000)` → `29% OFF`）を確認。
  - 価格入力行のうち最小価格の行にのみ「最安」チップ（`.luna-product-badge--cheapest`）と行強調クラス（`.luna-product-store--cheapest`）が付与され、同額最小価格が複数ある場合も先頭の1行のみに付与されることを確認。
  - 直販ストア行の「公式」チップ（`.luna-product-badge--official`）および価格なし（URL のみ）の行描画を確認。
  - URL と価格の両方が空の行は描画されないこと、また5行以上渡された場合も最大4行までに制限されることを確認。
  - Amazon ASIN（`B0EXAMPLE1`）とトラッキング ID（`luminous-22`）から `https://www.amazon.co.jp/dp/B0EXAMPLE1?tag=luminous-22` が自動構築されることを確認。
  - 全外部リンクに `target="_blank"` および `rel="sponsored noopener noreferrer"` が付与されることを確認。
  - Google favicon 取得サービス（`google.com/s2/favicons`）等の外部アイコン取得が一切含まれないことを確認。
  - カード内注記に「税込」「`{checkedAt}` 時点の価格」「価格・セール期間は表示時点のものです」が出力されることを確認。
  - カード下部ディスクロージャーが、実際に描画されたネットワーク（Amazon／楽天市場）の分だけ自動出力されることを確認。
- **CSS トークン実在監査（本番テーマ `luna-frontier-2.0-skyalow` 基準）**:
  - `luna-products/assets/style.css` および `luna-products/assets/editor.css` 内の副次テキスト色を `color-mix` 代替から `--md-sys-color-on-surface-variant` の実トークン直接参照へ統一（`color-mix` 記述は全撤去）。
  - `luna-products/tests/local-regression.cjs` の監査参照先を本番テーマブランチ `luna-frontier-2.0-skyalow:src/styles/_variables.css`（ライト `#6B5A4A`／ダーク `#D6C6B8` 定義済み）に合わせ、`--md-sys-color-on-surface-variant` を含むすべての `var(--...)` 参照で未定義変数が **0件** であることを確認。
  - **既知の差分（`master` ブランチのテーマとの差異）**: `master` ブランチ側の `src/styles/_variables.css` には `--md-sys-color-on-surface-variant` の定義が存在しない（本番系 `luna-frontier-2.0-skyalow` ブランチには定義済み）。`master` 系テーマ環境では未定義変数のため親要素（`--md-sys-color-on-surface`）の色を継承する動作となる。テーマ本体へのトークン追加は別タスク範囲のため本プラグイン側では行わない。
- **アフィリエイト ID のフォールバック解決验证**:
  - Amazon トラッキング ID と楽天アフィリエイト ID の解決順を「Luna Products 設定値（`luna_products_amazon_id` / `luna_products_rakuten_id`）→ 空なら Luminous Nexus 設定値（`luminous_nexus_amazon_id` / `luminous_nexus_rakuten_id`）」とし、両方空の場合は素の URL を出力することを確認。
  - ディスクロージャー文言はフォールバック対象外とし、Luna Products 側の設定値（初期値は Nexus デフォルト文言と同一）を使用することを確認。
- **Chromium レスポンシブレイアウト検証（1280px / 360px）**:
  - デスクトップ幅（1280px）：左に 1:1 白背景の商品画像パネル、右にタイトル・スペック・ストア行リストが横並びで配置され、横はみ出しがないことを確認。また `luna-frontier-2.0-skyalow` の `_variables.css` 読込時に `.luna-product-card__spec` の計算色がライト `rgb(107, 90, 74)`（`#6B5A4A`）・ダーク `rgb(214, 198, 184)`（`#D6C6B8`）になることを確認。
  - モバイル幅（360px）：画像パネル→本文が縦積みになり、各ストア行内では価格と CTA ボタンが横並びのまま潰れずに収まり、横はみ出しがないことを確認。
- **既存資産の不変確認**:
  - テーマ本体、`luna-interactive/`、`plugins-embedded/luminous-nexus/` への変更がゼロであることを `git status` で確認。

---

## ローカル実機テスト記録（LocalWP `http://cybernode.local`、2026-11-11）

1. **事前確認（有効テーマと `--md-sys-color-on-surface-variant` 実測）**:
   - ローカルサイト（`http://cybernode.local`）の有効テーマ： `Node`（v2.0.3、`/wp-content/themes/Node`）。
   - テーマ CSS（`assets/css/style.css`）における `--md-sys-color-on-surface-variant` 定義の有無を実測： **定義なし（0件）**。ブラウザ上の `getComputedStyle(document.documentElement).getPropertyValue('--md-sys-color-on-surface-variant')` も空文字 `""` となり、ローカル環境では既知の差分どおり副次テキスト（`.luna-product-card__spec` 等）がカード本文の継承色（ライト `rgb(43, 23, 0)`／ダーク `rgb(235, 224, 217)`）で描画されることを確認。本番系 `luna-frontier-2.0-skyalow` の `_variables.css` 定義値（`#6B5A4A` / `#D6C6B8`）による発色は `tests/local-regression.cjs` の Chromium テストで裏付け済み。
2. **有効化の確認**:
   - `/wp-content/plugins/luna-products/` へ配置・有効化し、管理画面（`/wp-admin/options-general.php?page=luna-products`）および公開側ページが Fatal Error / Warning なく HTTP 200 で開くことを確認。
3. **ブロックの動作確認（テスト投稿 ID: 1628 `/luna-products-local-test/`）**:
   - **a. 初期状態とプレビュー一致**: ブロック登録の `stores` デフォルト値が `amazon`・`rakuten` の2行であること、ブロックエディター（`ServerSideRender`）のプレビュー HTML と公開側の描画 HTML が一致することを確認。
   - **b. 価格・バッジ・空行制御**: 3桁カンマ＋「円」（`2,790円`、`3,990円`、`3,800円`）、定価取り消し線（`定価3,990円`）、OFF 率切り捨て（`30% OFF`）、セール文（`11月7日まで`）、最安チップ（同額最安 `5,980円` が2行ある場合も先頭1行のみ付与）、公式チップ（`Anker Store`・`マイニンテンドーストア`）、価格なし直販行の表示、URL・価格とも空の楽天行が描画されないことを確認。
   - **c. カスタムストア行**: `preset: "custom"`・表示名 `e☆イヤホン`・色 `#0066CC` が `e☆イヤホンで見る` ボタン（背景色 `rgb(0, 102, 204)`）として正しく描画されることを確認。
   - **d. アフィリエイトのフォールバック実証**: `luna_products_amazon_id` を空にし、`luminous_nexus_amazon_id` にのみテスト用 ID（`testtag-22`）を設定した状態で、ASIN `B0EXAMPLE1` から `https://www.amazon.co.jp/dp/B0EXAMPLE1?tag=testtag-22` が構築・出力されることを確認。楽天は両オプションとも空の状態で素の URL（`https://item.rakuten.co.jp/anker/a2692/`）のまま出力されることを確認。
   - **e. ディスクロージャー表示制御**: Amazon・楽天の両方を含むカードでは2行、Amazon のみを含むカードでは Amazon の1行のみディスクロージャーが表示されることを確認。
4. **表示確認（4条件スクリーンショット）**:
   - 1280px ライト／1280px ダーク／360px ライト／360px ダークの4条件でスクリーンショットを撮影し、はみ出し・重なり・ボタンの潰れがないことを目視確認。
   - 保存パス（リポジトリ外アーティファクトディレクトリ）：
     - `/Users/saitoutatsuya/.gemini/antigravity/brain/128263d8-ea75-4168-8fa8-53c70204a6f2/screenshots/desktop-1280-light.png`
     - `/Users/saitoutatsuya/.gemini/antigravity/brain/128263d8-ea75-4168-8fa8-53c70204a6f2/screenshots/desktop-1280-dark.png`
     - `/Users/saitoutatsuya/.gemini/antigravity/brain/128263d8-ea75-4168-8fa8-53c70204a6f2/screenshots/mobile-360-light.png`
     - `/Users/saitoutatsuya/.gemini/antigravity/brain/128263d8-ea75-4168-8fa8-53c70204a6f2/screenshots/mobile-360-dark.png`
5. **既存機能への非干渉**:
   - 同一テスト投稿内で Luna Interactive のグラフブロック（`luna-interactive/chart` → `.luna-chart`）および Luminous Nexus の `[product_card]` ショートコード（`.m3-product-card`）が従来どおり正常表示されることを確認。
