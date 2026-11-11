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
- **CSS トークン実在監査**:
  - `luna-products/assets/style.css` および `luna-products/assets/editor.css` 内のすべての `var(--...)` 参照を抽出し、テーマの `src/styles/_variables.css` に実在するトークンのみであること（未定義変数参照 0件）を確認。
- **Chromium レスポンシブレイアウト検証（1280px / 360px）**:
  - デスクトップ幅（1280px）：左に 1:1 白背景の商品画像パネル、右にタイトル・スペック・ストア行リストが横並びで配置され、横はみ出しがないことを確認。
  - モバイル幅（360px）：画像パネル→本文が縦積みになり、各ストア行内では価格と CTA ボタンが横並びのまま潰れずに収まり、横はみ出しがないことを確認。
- **既存資産の不変確認**:
  - テーマ本体、`luna-interactive/`、`plugins-embedded/luminous-nexus/` への変更がゼロであることを `git status` で確認。
