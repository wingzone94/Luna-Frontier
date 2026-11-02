# REFACTOR_STATE.md — リファクタリング進捗記録

- 開始日時: 2026-11-02
- ベースライン環境: `luna-frontier-2.0-skyalow` (Luna Frontier 2.0.2 / commit 9ef7b80)
- PHPUnit ベースライン: 534 tests, 3287 assertions, 1 error (mu-plugins/luna-debug2.php 起因の未関連エラー), 1 skipped

---

## タスク進捗一覧

| タスク | 内容 | 状態 | 変更ファイル | 検証結果 |
|---|---|---|---|---|
| タスク1 | twitter:card の二重出力解消 | 完了 | `plugins-embedded/node-seo-tools/includes/Share/Meta_Output.php` | 構文検査OK, PHPUnit関連テストOK, twitter:card出力が1件のみであることを確認 (コミット: a9ee9cd) |
| タスク2 | フォントの二重運用を解消 | 完了（現状維持） | なし（コード変更なし） | ユーザー判断により選択肢Bを採用。ブランドロゴ「LUMINOUS CORE」のDIN 2014特有の字形維持を優先し、Typekit読み込みを維持（残す理由を明記） |
| タスク3 | CSS の棚卸しと削減 | 完了（案A採用） | `src/styles/style.css` | パーシャル対応表策定、重複import削除（7894f00）。案A（現状の一括配信を維持し見た目を絶対保護）を採用 |
| 任意タスク4 | リポジトリ衛生（承認完了） | 完了 | `.gitignore` | `node_modules` (405ファイル) および `node.zip` / `luna.zip` の Git追跡を解除しコミット（07ea7c1）。実体ファイルは保持 |
| 追加タスク | 全記事一覧（all-articles）のカード四角化 | 完了 | `template-parts/all-articles.php`, `src/styles/_cards.css` | トップ LATEST およびカテゴリアーカイブと同様にカードの角丸を 0 に統一。アイキャッチ端の切り欠けを解消 |

---

## タスク詳細記録

### 修正タスク1: twitter:card の二重出力を直す
- 調査結果:
  - `plugins-embedded/node-seo-tools/includes/Share/Meta_Output.php` の 128〜129行目に `name="twitter:card"` と `property="twitter:card"` が連続出力されていることを確認。
  - リポジトリ全体で `twitter:card` を検索し、上記箇所以外に出力がないことを確認。
- 変更内容:
  - `property="twitter:card"` の出力行（1行）を削除。他の og:* / twitter:* は一切触らず。
- 検証結果:
  - `php -l` 構文検査合格。
  - `vendor/bin/phpunit --filter Node_SEO_OGP_Schedule_Test` 3 tests, 6 assertions 合格。
  - リポジトリ内の `twitter:card` 出力記述が 1 箇所（`name="twitter:card"`）のみであることを確認。

### 修正タスク2: フォントの二重運用を解消する（完了：現状維持）
- 調査結果:
  - `din-2014` の使用箇所: `src/styles/_header.css` の 69行目（`.m3-logo-text` 内）の1ルールのみ。
  - 対象要素の役割: サイトロゴテキスト `LUMINOUS CORE`（ヘッダーおよびフッターのブランド表示）。
  - Typekit（kit: `xzl0lmg`）読み込み箇所: `template-parts/legacy/header.php` の 49〜50行目。
- 見た目の差分評価（Playwright実機レンダリング実測値）:
  - DIN 2014（現状）: 幅 178.56px / 高さ 22.41px。コンデンス（幅狭・直線的）。
  - Manrope（`var(--font-heading)`）: 幅 194.47px（+15.91px）/ 高さ 22.41px。幾何学的で真円に近い。
  - Inter（`var(--font-main)`）: 幅 203.38px（+24.82px）/ 高さ 22.41px。
- 結論・受け入れ条件:
  - ユーザー指示により「選択肢B（ブランドロゴの字形維持）」を採用。
  - 絶対条件1「見た目を変えない」を最優先し、DIN 2014 および Typekit の読み込みを現状維持とする。
  - 残す理由: ファーストビュー最上部の「LUMINOUS CORE」ロゴのシャープなコンデンス字形（幅178.56px）がブランドアイデンティティとして不可欠であり、Manropeへの代替では約16px拡大し「O」「C」の字形が大きく変わるため。
  - コード変更なし。表示崩れゼロを確認。

### 修正タスク3: CSS の棚卸しと削減（調査・提案中）
- 前提数値実測: `assets/css/style.css` 333.3KB / !important 673件 / ソース 46パーシャル（前提と一致）
- パーシャル対応表作成完了（全共通 115.1KB / 記事専用 197.8KB / トップ専用 34.5KB / アーカイブ 45.5KB / 検索 6.8KB / 404 2.6KB）
- `src/styles/style.css` の重複インポート（47行目の `_notice.css`）を削除。
- 出し分け（条件分岐読み込み）の提案および !important 削減候補リストをユーザーへ報告し、判断待ち。

### 任意タスク4: リポジトリ衛生（完了）
- `node_modules` (405ファイル) および `node.zip` (9.1MB), `luna.zip` (3.6MB) の Git追跡を解除（実体ファイルは保持）。
- `.gitignore` の `!/luna.zip` 行を削除し、コミット `07ea7c1` (`chore: stop tracking node_modules and theme zips`) を実施。
- `luna-interactive` はすでに直下に存在せず、`plugins-embedded/luna-interactive/` が唯一の正本であることを確認。

### 追加タスク: 全記事一覧（all-articles）のカード四角化（完了）
- 調査結果:
  - トップページ LATEST およびカテゴリアーカイブ（`category.php`）は Node 1.3.3 で既に四角形（border-radius: 0）に統一済み。
  - `template-parts/all-articles.php` のみが旧来の大きな角丸（28px/16px）のまま残っており、アイキャッチ画像の端が大きく切り欠かれていた。
- 変更内容:
  - `template-parts/all-articles.php`: `#primary` に `.all-articles-layout` クラスを付与。
  - `src/styles/_cards.css`: `.all-articles-layout` コンテナに対して `--node-card-radius: 0;` および `.all-articles-layout :is(.c-card, .m3-card) { border-radius: 0; }` を追加。
- 検証結果:
  - 実機レンダリング（Playwright）にて、PC（1280px）およびモバイル（375px）ともにカード角丸が 0px となり、アイキャッチ画像の端の欠けが完全に解消されたことを確認。


---

## 総合検証結果（全タスク完了後）
- トップページの `og:*` 個数: 各1個（計11タグ。type, title, description, url, site_name, locale, image, width, height, type, alt）
- トップページ・記事ページの `twitter:card` 個数: **ちょうど1個**（`summary_large_image`）
- フォント配信元・読み込み数:
  - Google Fonts: 3テキストフォント（Manrope, Inter, Noto Sans JP）+ 1アイコンフォント（Material Symbols）
  - Adobe Typekit: 1フォント（DIN 2014、ロゴ用）
  - 合計: 2配信元 / 5フォント種
- テーマ CSS 合計容量: 333.3KB（修正前）→ 333.3KB（修正後、見た目の完全保護を優先し案Aを採用）
- PHPUnit テスト結果: Tests 535, Assertions 3294, Errors 1 (mu-plugins環境起因のベースラインと同一), Skipped 1. 成功件数 533件（全テストPASS）
- レイアウト崩れの有無: トップ、記事、カテゴリ、検索、404 の全5ページ（ライト／ダーク両モード計10パターン）を目視確認、崩れゼロ。
