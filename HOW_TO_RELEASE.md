# Luna Frontier 2.0 リリース手順

このドキュメントでは、WordPressテーマ **Luna Frontier 2.0**（独立テーマ）の配布用ZIP (`luna.zip`) を生成し、GitHubの配布ブランチ（`luna-frontier-2.0-skyalow`）へ安全にリリースするまでの手順を説明します。

---

## 概要と重要方針

- **テーマ名**: `Luna Frontier`
- **配布先（更新チャンネル）**: GitHub Releases（不変タグ `luna-frontier-v{version}-{build_id}`）。ブランチ HEAD は配信元にしない
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
Version: 2.0.0
Text Domain: node
*/
```

- `Theme Name` は **Luna Frontier** であること。
- `Template:` ヘッダーが存在しないこと。
- バージョン番号がリリース対象と一致していること。

## 2. アセットのビルド

テーマ内のCSSやJavaScriptを変更した場合は、必ずBunでビルドを実行します。

```bash
bun run build
```

### 注意事項
- 生成先は `dist/`（`vite.config.js` の `outDir: 'dist'`）。ソース階層 `assets/` には混ぜない。
- PHP は `inc/theme-setup.php` の `node_resolve_vite_build()` で `dist/.vite/manifest.json` → `assets/.vite/manifest.json` の順に解決する。成果物の採用単位は **manifest 全体**（新・旧チャンクの混在は禁止）。
- `dist/.vite/manifest.json` と生成された `dist/js/main.*` / `dist/js/vendor.*` のハッシュが一致していることを確認してください。
- 既存サイトとの後方互換のため、`assets/js/main.*` / `assets/js/vendor.*` と `assets/.vite/manifest.json`（旧世代1回分）は **次リリースまで削除しない**。dist が無い環境（配布ZIP単体）はここへフォールバックして配信される。
- フォント（`NotoSansJP-VF.ttf`, `Inter-Regular.ttf` 等）はCDNから取得するため、テーマ内には同梱しません。

## 3. テストと動作検証

ZIP生成前に、すべてのPHPUnitテストと表示確認を通過させる必要があります。

### 3-a. PHPUnitテスト
```bash
composer test
```
特に以下のテストがすべて成功することを確認します：
- `tests/luna-release-package-test.php`
- `tests/node-release-package-test.php`
- `tests/node-vite-manifest-test.php`
- `tests/node-settings-sanitize-test.php`
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

## 4. build.json の更新（必須）

同日リリースや同一バージョン内での修正配信に対応するため、ZIP生成のたびに **`build.json` を必ず再生成** します。
Luminous Settings の更新判定はこの `build_id` を照合します。

```bash
printf '{\n    "build_id": "%s",\n    "built_at": "%s",\n    "version": "%s"\n}\n' \
  "$(date -u +%Y%m%dT%H%M%SZ)-$(git rev-parse --short HEAD)" \
  "$(date -u +%FT%TZ)" \
  "$(grep -m1 '^Version:' style.css | awk '{print $2}')" > build.json
```

- `build.json` は配布ZIPに含め、Gitコミットにも含めます。

## 5. luna.zip の生成

生成は正本スクリプトへ一元化した。以下を実行します（rsync 除外リストと同一内容は `scripts/build-luna-zip.sh` 保持）。

```bash
rm -f luna.zip
bash scripts/build-luna-zip.sh
```

- **`dist/` は除外しない**（ビルド後の成果物としてZIPへ同梱する。`node_resolve_vite_build()` がZIP単体でもこれを使う）。
- `assets/.vite/manifest.json` と旧ハッシュJS（この1世代分）も除外しない（フォールバックの成立条件）。
- 作業用ドキュメント・未コミットの別タスク資産（`memory/`、`AUDIT-2026-11.md`、`SPEC-2026-11-FIX.md`、`lum-tracking-guard.php` 等）も除外済み。新しく配布物に入れるべきでないルートファイルを置いた場合はスクリプトへ除外を追加します。
- ZIP 生成後にコミットへ入れない（`.gitignore` の `*.zip` 対象）。**配信先は GitHub Release**（節6 の release-luna-frontier workflow / 手動時は手順7）。

### 生成後の必須チェック
1. **ZIP内ルート**: すべてのファイルが `luna-frontier/` 配下にあること。
2. **ZIPサイズ**: 重複フォントを含めず、10MiB未満（通常3〜5MiB）であること。
3. **必須PHPクラス**: `src/Setup/`, `src/Hooks/` などのオートロード対象PHPクラスが含まれていること。`dist/` が入っていること。
4. **画像修復機構**: `inc/image-repair.php`, `inc/image-repair-admin.php`, `assets/js/image-repair.js` が含まれ、ソースと一致していること。
5. **バージョン照合**: `tests/luna-release-package-test.php` を実行して検証します。
   ```bash
   vendor/bin/phpunit tests/luna-release-package-test.php
   ```
6. **sha256 の記録**: `luna-release.json`（sha256・tag・version・build_id・asset）を生成し、リリース時に `luna.zip` と並べて Release asset にアップロードします。ZIP 内 `build.json` に sha256 を書いてはならない（自己言及）。手元での確認:
   ```bash
   tag="luna-frontier-v$(grep -m1 '^Version:' style.css | awk '{print $2}')-$(php -r 'echo json_decode(file_get_contents("build.json"), true)["build_id"];')"
   shasum -a 256 luna.zip
   jq -n --arg tag "$tag" --arg version "$(grep -m1 '^Version:' style.css | awk '{print $2}')" --arg build_id "$(php -r 'echo json_decode(file_get_contents("build.json"), true)["build_id"];')" --arg sha256 "$(shasum -a 256 luna.zip | awk '{print $1}')" '{tag:$tag,version:$version,build_id:$build_id,asset:"luna.zip",sha256:$sha256}' > luna-release.json
   ```

## 6. Gitへのコミットと配布チャンネルへの反映

1. 変更内容をステージングしてコミットします。`git add -A` は使わない（`luna.zip` / `node.zip` / `node_modules/` は配布物であり、Git の対象外）。ステージするパスを列挙します。
   ```bash
   git add -u                 # 追跡済みファイルの変更のみ（zips / node_modules は除く）
   git add build.json         # 節4 で再生成したもの
   git add <新規ファイルがあれば個別に列挙>
   git commit -m "chore: package Luna Frontier <version>"
   ```
2. 専用作業ブランチから `luna-frontier-2.0-skyalow` 宛てにPRを作成・レビューしてマージします（または指示された手順で配布ブランチへ反映）。**配布ブランチの先頭に「Release取得側の更新コード」（節7 の Release 化コミット）が入っていること** を、untrack が先に反映されるよう確認します。順序が逆になると、配布ブランチHEADの `luna.zip` が消えた状態で現行インストールの管理画面更新が404になります。
3. **注意**: `master` への直接 push は絶対に行わないでください。

## 7. 更新判定の整合性確認

Luna Frontier 2.0 の管理画面は、**GitHub Releases（不変タグ）** を参照して更新を判定します。生のブランチ raw URL は参照しません。
- Release 一覧（API）: `https://api.github.com/repos/wingzone94/Luna-Frontier/releases?per_page=20`
- Asset: `https://github.com/wingzone94/Luna-Frontier/releases/download/{tag}/luna.zip`
- ダイジェスト: `https://github.com/wingzone94/Luna-Frontier/releases/download/{tag}/luna-release.json`
- タグ形式: `luna-frontier-v{version}-{build_id}`（不変。既存タグの移動・再作成は workflow が拒否する）

```bash
tag="luna-frontier-v2.0.1-<build_id>"
curl -fsSL -H "Accept: application/vnd.github+json" -H "User-Agent: luna-frontier-release-check" \
  "https://api.github.com/repos/wingzone94/Luna-Frontier/releases/tags/${tag}"
curl -fsSL -L -o /tmp/luna-remote.zip \
  "https://github.com/wingzone94/Luna-Frontier/releases/download/${tag}/luna.zip"
curl -fsSL -L -o /tmp/luna-release.json \
  "https://github.com/wingzone94/Luna-Frontier/releases/download/${tag}/luna-release.json"
php -r 'exit(hash_file("sha256","/tmp/luna-remote.zip") === json_decode(file_get_contents("/tmp/luna-release.json"), true)["sha256"] ? 0 : 1);'
unzip -p /tmp/luna-remote.zip luna-frontier/style.css | head -n 12
unzip -p /tmp/luna-remote.zip luna-frontier/build.json
```

---

# 第2部: （参考）Node 安定版リリース手順

> **注意**: 以下は親テーマ「Node」安定版（`master` チャンネル）を更新する場合の過去の手順です。Luna Frontier 2.0 のリリース作業では実行しないでください。

### Node安定版概要
- テーマ名: `Node`
- 配布ブランチ: `master`
- 配布ZIP: `node.zip`（ZIP内ルート: `Node/`）
- 更新URL（ブランチraw・バージョン確認のみ）: `https://raw.githubusercontent.com/wingzone94/Luna-Frontier/master/style.css`
- **配布方法: `node.zip` を master へコミットしない** こと。不変タグ `v{style.css の Version}`（`auto-tag-theme-version.yml` が作る注釈タグ）へ Release asset として載せる:
  ```bash
  # 1. master の style.css Version に一致する node.zip（ルート Node/）を用意
  # 2. auto-tag-theme-version.yml が作成済みのタグ v1.4.0 に asset を足す（Release が既にあれば upload）
  gh release create v1.4.0 node.zip --repo wingzone94/Luna-Frontier --target master || \
  gh release upload v1.4.0 node.zip --repo wingzone94/Luna-Frontier
  ```
  ZIP がない場合の静的検証は `tests/node-release-package-test.php` がスキップするため、CIだけでは配布漏れを検知しない。node.zip を更新したら必ずローカルで当該テストを実行して一致を確認すること。

Node安定版をリリースする際は、`node.zip` のルートを `Node/` とし、`master` ブランチの `node.zip` および `style.css` を更新します。
Luna Frontier 2.0 の作業中にこれらを上書きしてはなりません。
