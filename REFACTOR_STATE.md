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
| タスク3 | CSS の棚卸しと削減 | 調査・提案中 | `src/styles/style.css` (重複import解消) | パーシャル対応表・!important内訳・出し分け提案を策定。ユーザー判断待ち |
| 任意タスク4 | リポジトリ衛生（承認待ち） | 調査完了・提案中 | - | node_modules/zipの管理外手順と影響範囲、luna-interactiveの正本根拠を策定。承認待ち |

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

### 任意タスク4: リポジトリ衛生（調査完了・承認待ち）
- `node_modules` (405ファイル) および `node.zip` (9.1MB), `luna.zip` (3.6MB) の追跡確認と安全な管理外化手順を策定。
- `luna-interactive` はすでに直下に存在せず、`plugins-embedded/luna-interactive/` が唯一の正本であることを確認。
