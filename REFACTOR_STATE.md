# REFACTOR_STATE.md — リファクタリング進捗記録

- 開始日時: 2026-11-02 (更新: 2026-11-03)
- ベースライン環境: `luna-frontier-2.0-skyalow` (Luna Frontier 2.0.2)
- PHPUnit ベースライン: 535 tests, 3297 assertions, 0 failures, 1 error (`Node_SEO_OGP_Featured_Test::test_editor_can_save_toggle_through_rest` — `mu-plugins/luna-debug2.php` 起因の既存エラー), 1 skipped

---

## タスク進捗一覧

| タスク | 内容 | 状態 | 変更ファイル | 検証結果 |
|---|---|---|---|---|
| タスクA | `twitter:card` の二重出力を直す | 完了 | `plugins-embedded/node-seo-tools/includes/Share/Meta_Output.php` | `property="twitter:card"` 削除、トップ・記事とも `twitter:card` 1個、`og:*` / `twitter:*` 欠損なし (`a9ee9cd`) |
| タスクB | Typekit（DIN 2014）の扱いを決めて実行する | 完了（現状維持） | なし（コード変更なし） | `.m3-logo-text` (`LUMINOUS CORE`) 1ルールのみ使用。Manrope代替で幅+15.91px (+8.9%) の差分が出るため現状維持 |
| タスクC | CSS の棚卸しと削減 | 完了 | `src/styles/style.css`, `src/styles/_header.css`, `src/styles/_article.css`, `src/styles/_article-layout.css`, `src/styles/_article-polish.css`, `src/styles/_hero-toc.css`, `src/styles/_spotlight.css`, `src/styles/_date-archive.css`, `src/styles/_search-results.css`, `src/styles/_footer-misc.css`, `src/styles/_theme-controls.css`, `assets/css/style.css` | パーシャル対応表作成、PHP/JS 0ヒットの未使用セレクタ 2,448行削除。`style.css` 333.3KB → 288.8KB (-44.5KB)、テーマCSS合計 425.7KB → 381.2KB (`edbbd49`) |
| タスクD | 画像の `alt` を自動設定する | 完了 | `template-parts/luna/home-story.php`, `inc/hero-slider.php`, `template-parts/hero-slider.php`, `template-parts/single/hero.php`, `template-parts/single/related.php`, `template-parts/article-card.php`, `template-parts/legacy/article-card.php`, `template-parts/headline-carousel.php` | 既存 `_wp_attachment_image_alt` を保持しつつ未設定時に記事タイトル (`esc_attr`) を出力。トップ記事サムネイル alt 設定率 100% (`4eb4f47`) |

---

## タスク詳細記録

### タスクA: twitter:card の二重出力を直す
- `plugins-embedded/node-seo-tools/includes/Share/Meta_Output.php` の `inject_ogp_tags()` から `<meta property="twitter:card"...>` 行のみを削除し、`<meta name="twitter:card" content="summary_large_image" />` の1行に統一。
- リポジトリ全体で `twitter:card` の出力箇所が当該1箇所のみであることを確認。

### タスクB: Typekit（DIN 2014）の扱いを決めて実行する
- 使用箇所: `src/styles/_header.css:69` `.m3-logo-text`（ヘッダー・フッターのブランドロゴ文字 `LUMINOUS CORE`）の1ルールのみ。
- 差分評価: DIN 2014（幅 178.56px）に対し、Manrope（幅 194.47px、+15.91px / +8.9%）、Inter（幅 203.38px、+24.82px）とコンデンス字形および文字幅の差分が目立つため、タスクB手順3に従い変更せず現状維持。

### タスクC: CSS の棚卸しと削減
- **1. パーシャル×テンプレート対応表**:
  - 全ページ共通 (14パーシャル): `_tokens.css`, `_reset.css`, `_base.css`, `_layout.css`, `_header.css`, `_navigation.css`, `_drawer.css`, `_search.css`, `_footer.css`, `_footer-misc.css`, `_components.css`, `_theme-controls.css`, `_utilities.css`, `_motion.css`
  - トップ専用 (2パーシャル): `_hero-slider.css`, `_spotlight.css`
  - 記事ページ専用 (23パーシャル): `_article.css`, `_article-layout.css`, `_article-polish.css`, `_hero-toc.css`, `_prose-tables.css`, `_prose-Callout.css`, `_prose-code.css`, `_prose-diff.css`, `_prose-media.css`, `_prose-Embeds.css`, `_prose-footnotes.css`, `_prose-links.css`, `_prose-math.css`, `_prose-review.css`, `_prose-Sheet.css`, `_prose-step.css`, `_prose- switch.css`, `_prose-timeline.css`, `_blogcard.css`, `_comments.css`, `_share.css`, `_series.css`, `_library.css`
  - アーカイブ／カテゴリ／検索専用 (5パーシャル): `_cards.css`, `_archive.css`, `_date-archive.css`, `_search-results.css`, `_notice.css`
  - 未使用 (1パーシャル): `_skeleton.css`（全7クラス・`@keyframes mc-shimmer` とも PHP/JS で 0ヒット）
- **2. 条件分岐出し分けの評価**:
  - `src/styles/style.css` は Vite 単一バンドルかつ `@layer reset, base, components, utilities;` で構成されており、レイヤー外の `luna-style.css` とカスケード順序が結合しているため、ファイル構成を変えずに分割配信するとカスケード優先順位が変わる。そのため単一バンドル構造を維持したまま未使用セレクタ削減を実施。
- **3. 削除根拠付き未使用セレクタ削除（PHP/JS 全体検索 0ヒット確認済み）**:
  - `_skeleton.css` import、`_theme-controls.css` (`.m3-switch*`, `.m3-theme-popover__arrow*`, `#theme-toggle-light/dark-icon`)、`_hero-toc.css` (`.m3-hero-grid/row/col/toc*`, `.m3-article__library-quick-link*`)、`_article-polish.css` (`.single .m3-article__header-card*`)、`_spotlight.css` (`.m3-spotlight-archive-*`)、`_date-archive.css` (`.m3-date-bar/sort/toggle/feed/groups`)、`_search-results.css` (`.m3-search-results-header/title/count*`, `.m3-active-filters*`)、`_article-layout.css` (`.m3-floating-toc-card*`, `.m3-toc-content*`)、`_footer-misc.css` (`.m3-footer__grid/brand/title/tagline/social/bottom/version`, `.m3-404-visual/number/icon`, `.m3-empty-*`)、`_header.css` (`.m3-popover*`, `.m3-header__clock/greeting/datetime`)、`_article.css` (`.m3-ai-summary__*`, `.m3-ai-shimmer*`, `.m3-article__top-badges`, `.m3-article__featured-*`, `.m3-article__cat-bottom-right*`, `.m3-article__header-card*`, `.m3-article__header--overlap*`, `.m3-pagination-toc*`, `.m3-pagination-top-btn*`) の計 2,448行を削除。
  - `dist/css/style.css`: `333.3KB` (333,314B) → `288.8KB` (288,812B)（**-44.5KB**）。
  - 残存大物内訳（ソース換算）: `_article.css` (61.2KB)、`luna-style.css` (92.4KB)、`_prose-*.css` 群 (約96KB)、`_cards.css` (25.6KB)、`_header.css` (16.2KB)。
- **4. `!important` 削減候補リスト**:
  - `_article.css` 内の `[data-theme="dark"] .m3-article__category-group:not(.is-card) .m3-label--category.is-secondary` 等（同一 `@layer components` 内で詳細度 (0,5,0) により `!important` なしで上書き可能）
  - `_cards.css` 内の `.all-articles-layout :is(.c-card, .m3-card)` 等のコンテナ詳細度で勝てる宣言
  - `_prose-tables.css` / `_prose-media.css` の `.m3-article__body` 配下の Gutenberg ブロック上書き宣言
  - ※Google Fonts のレイヤー外 `.material-symbols-outlined { font-size: 24px }` を `@layer components` 内から上書きする `font-size: ... !important` は仕様上必須のため除外。

### タスクD: 画像の alt を自動設定する
- 記事サムネイル出力箇所（`template-parts/luna/home-story.php`, `inc/hero-slider.php`, `template-parts/hero-slider.php`, `template-parts/single/hero.php`, `template-parts/single/related.php`, `template-parts/article-card.php`, `template-parts/legacy/article-card.php`, `template-parts/headline-carousel.php`）にて、添付ファイルの `_wp_attachment_image_alt` が設定済みの場合はそれを維持し、空の場合のみ記事タイトル（`esc_attr` エスケープ済み）を `alt` に出力するよう統一。
- 装飾・非記事画像で `alt=""` を維持した箇所（判断リスト）:
  1. `template-parts/legacy/header.php:132` (`.m3-header__logo-img` — テキストロゴ横の装飾SVGアイコン、`aria-hidden="true"`)
  2. `template-parts/single/footer.php:49,65` (`.m3-elevated-nav-card__bg` — 前後記事ナビの背景装飾画像)
  3. `template-parts/spotlight-archive.php:47` (`.lf-spotlight-index__media` — `aria-hidden="true"` の特集インデックス装飾サムネイル)
  4. `inc/blogcard.php:542,547,577` (`.node-blogcard__thumbnail` / `.node-blogcard__favicon` — `aria-hidden="true"` 内の装飾画像)
  5. `inc/custom-fields.php:446` (`.node-preferred-source__badge--dark` — ライト版バッジが `alt` を持つため重複読み上げ防止の `aria-hidden="true"` ダーク用バッジ)
