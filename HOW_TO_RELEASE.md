# Luna Frontier 2.0 リリース手順

このドキュメントでは、WordPressテーマ **Luna Frontier**（独立テーマ）の配布用ZIP (`luna.zip`) を生成し、GitHub Release として公開するまでの手順を説明します。ZIP は配布ブランチへコミットしません。

---

## 概要と重要方針

- **テーマ名**: `Luna Frontier`
- **配布ブランチ / 更新チャンネル**: `luna-frontier-2.0-skyalow`
- **配布ZIPファイル名**: `luna.zip`
- **ZIP内ルートディレクトリ**: `luna-frontier/`
- **独立テーマ原則**:
  - Luna Frontier 2.0 は Node の子テーマではなく**完全な独立テーマ**です。
  - `style.css` に `Template:` ヘッダーを**絶対に記述しない**こと。
  - 親テーマ（Node）のインストールは不要で、単体で新規有効化・動作可能であること。
  - Node安定版（`master` ブランチ、`node.zip`）を**絶対に上書き・破壊しない**こと。

---

# 第1部: Luna Frontier 2.0 リリース手順（現行正本）

## 1. 命名・メタデータの確認

`style.css` のテーマヘッダーが以下の設定になっていることを確認します。

```css
/*
Theme Name: Luna Frontier
Theme URI: https://luminous-core.net/
Author: Luminous Core Teams
Author URI: https://luminous-core.net/
Description: Luna Frontier for Luminous Core. Independent theme descended from Node 1.x. Not a child theme.
Version: 2.0.4
Text Domain: node
*/
```

- `Theme Name` は **Luna Frontier** であること。
- `Template:` ヘッダーが存在しないこと。
- バージョン番号が、切ろうとしているリリースの `style.css` と一致していること。上の `2.0.4` は現行ヘッダー例であり、次の版を切るときはその版の値に読み替える。
- ブランチにコミットされている `build.json` の `version` / `build_id` は、公開済み Release の値とは別に残りうる。更新判定は Release の `luna-release.json` と ZIP 内の `build.json` を見る。`build_id` を文書の都合で書き換えない。

## 2. アセットのビルド

テーマ内のCSSやJavaScriptを変更した場合は、必ずBunでビルドを実行します。

```bash
bun run build
```

### 注意事項
- `vite.config.js` の出力先は `dist/`。PHP は `dist/.vite/manifest.json` を優先し、契約を満たさないときだけ `assets/.vite/manifest.json` へ戻る。
- フォント（`NotoSansJP-VF.ttf`, `Inter-Regular.ttf` 等）はCDNから取得するため、テーマ内には同梱しません。

## 3. テストと動作検証

ZIP生成前に、すべてのPHPUnitテストと表示確認を通過させる必要があります。

### 3-a. PHPUnitテスト
```bash
composer test
```
特に以下のテストがすべて成功することを確認します：
- `tests/luna-release-package-test.php`
- `tests/node-image-repair-test.php`
- `tests/node-image-repair-ajax-test.php`
- `tests/node-image-retention-test.php`
- `tests/node-image-legacy-retention-test.php`
- `tests/node-theme-update-test.php`
- `tests/node-sslverify-guard-test.php`

### 3-b. ローカル環境（cybernode.local）での表示検査
```bash
bun run verify:visual
```
主要画面（トップ、通常投稿、カテゴリ、日付アーカイブ、検索結果、SPOTLIGHT、404）において、以下の画面幅で表示崩れ・横スクロール・PHPエラーがないことを確認します：
- 1440px（デスクトップ広幅）
- 1280px（デスクトップ標準）
- 1024px（タブレット横 / デスクトップ境界）
- 768px（タブレット縦）
- 390px（スマートフォン）

## 4. build.json の生成契約（CI 自動刻印 / ローカル検証用）

同日リリースや同一バージョン内での修正配信に対応するため、配布 ZIP 生成時は **`style.css` の `Version` と一致する `build.json`** を生成して ZIP に同梱します。Luminous Settings の更新判定は不変タグ `luna-frontier-v{version}-{build_id}` 配下の `luna-release.json` と ZIP 内 `build.json` の `build_id` を照合します。

- **本番 Release（GitHub Actions）**: `.github/workflows/release-luna-frontier.yml` がワークフロー内で `build.json` を自動生成して `luna.zip` に同梱し、結果を Git へはコミットしません。
- **ローカル ZIP 検証**: ローカルで `luna.zip` と `Luna_Release_Package_Test` を検証する場合のみ、必要に応じて一時的に再生成できます（テスト後に不要な変更を残さないこと）。

```bash
printf '{\n    "build_id": "%s",\n    "built_at": "%s",\n    "version": "%s"\n}\n' \
  "$(date -u +%Y%m%dT%H%M%SZ)-$(git rev-parse --short HEAD)" \
  "$(date -u +%FT%TZ)" \
  "$(grep -m1 '^Version:' style.css | awk '{print $2}')" > build.json
```

## 5. luna.zip の生成

ローカルでのパッケージ生成と CI（`release-luna-frontier.yml`）の正本スクリプト **`scripts/build-luna-zip.sh`** を実行して `luna.zip` を生成します（手順の重複を防ぐため、除外定義は `scripts/build-luna-zip.sh` 側で一元管理します）。

```bash
bash scripts/build-luna-zip.sh
```

### 生成後の必須チェック
1. **ZIP内ルート**: すべてのファイルが `luna-frontier/` 配下にあること。
2. **ZIPサイズ**: 重複フォントを含めず、10MiB未満（通常3〜5MiB）であること。
3. **必須PHPクラス**: `src/Setup/`, `src/Hooks/` などのオートロード対象PHPクラスが含まれていること。
4. **画像修復機構**: `inc/image-repair.php`, `inc/image-repair-admin.php`, `assets/js/image-repair.js` が含まれ、ソースと一致していること。
5. **バージョン照合**: `tests/luna-release-package-test.php` を実行して検証します。
   ```bash
   vendor/bin/phpunit tests/luna-release-package-test.php
   ```

## 6. 配布ブランチへの反映と GitHub Release

1. ソースの変更だけをコミットする。`luna.zip` は `.gitignore` 済みで、Git に戻さない。
2. 作業ブランチから `luna-frontier-2.0-skyalow` へ PR を作り、Tests が成功してからマージする。
3. `master` へ直接 push しない。`node.zip` も触らない。
4. マージ後、配布ブランチ上で Actions の **Release Luna Frontier**（`workflow_dispatch`）を実行する。このワークフローが `dist` のビルド、`build.json` の刻印、`luna.zip`、PHPUnit のパッケージ検査、不変タグ、Release を行う。
5. タグは `luna-frontier-v{version}-{build_id}`。既存タグは動かさない。Asset は `luna.zip` と `luna-release.json` の2つ。sha256 は `luna-release.json` だけが持つ。
6. 承認なしにこのワークフローを実行しない。

## 7. 更新判定の整合性確認

Luna Settings の更新確認は、ブランチ HEAD の `style.css` / `build.json` / `luna.zip` を読まない。GitHub Releases の不変タグから `luna-release.json` と `luna.zip` を取り、sha256 と版を照合する（`inc/ajax.php`）。

2026-11-12 時点で公開済みの最新 Release は次のとおり。これは記録であり、新しい build_id の発行ではない。

- タグ: `luna-frontier-v2.0.3-20261111T103054Z-0941737`
- https://github.com/wingzone94/Luna-Frontier/releases/tag/luna-frontier-v2.0.3-20261111T103054Z-0941737

ブランチ上の `build.json` にはリリース前の開発時点の値（例: `version: 2.0.2` や現行ブランチの `2.0.4`）が残りうる。Release ワークフローはジョブ内で `style.css` の Version に合わせて `build.json` と `luna-release.json` を刻み直すが、その結果は Git へコミットしない。

---

# 第2部: （参考）Node 1.x 安定版リリース手順

> **注意**: 以下は親テーマ「Node」安定版（`master` チャンネル）を更新する場合の過去の手順です。Luna Frontier 2.0 のリリース作業では実行しないでください。

### Node安定版概要
- テーマ名: `Node`
- 配布ブランチ: `master`
- 配布ZIP: `node.zip`（ZIP内ルート: `Node/`）
- 更新URL: `https://raw.githubusercontent.com/wingzone94/Luna-Frontier/master/style.css`
- ZIP URL: `https://github.com/wingzone94/Luna-Frontier/raw/master/node.zip`

Node安定版をリリースする際は、`node.zip` のルートを `Node/` とし、`master` ブランチの `node.zip` および `style.css` を更新します。
Luna Frontier 2.0 の作業中にこれらを上書きしてはなりません。
