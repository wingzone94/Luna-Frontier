# Luna Frontier 2.0 リリース手順

このドキュメントでは、WordPressテーマ **Luna Frontier 2.0**（独立テーマ）の配布用ZIP (`luna.zip`) を生成し、GitHubの配布ブランチ（`luna-frontier-2.0-skyalow`）へ安全にリリースするまでの手順を説明します。

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
- `vite.config.js` の `clean-hashed-bundles` プラグインにより、古いハッシュ付きバンドルは自動削除されます。
- `assets/.vite/manifest.json` と生成された JS/CSS のハッシュが一致していることを確認してください。
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

以下のスクリプトを実行して、`luna.zip` を生成します。

```bash
rm -f luna.zip
repo_dir=$(pwd)
tmpdir=$(mktemp -d)

rsync -a \
  --exclude='.git' \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='scripts/' \
  --exclude='node_modules/' \
  --exclude='*.zip' \
  --exclude='.DS_Store' \
  --exclude='.!*!.DS_Store' \
  --exclude='.tmp*/' \
  --exclude='.cursor/' \
  --exclude='.gemini/' \
  --exclude='.codex/' \
  --exclude='.claude/' \
  --exclude='.agents/' \
  --exclude='scratch/' \
  --exclude='production_plugins/' \
  --exclude='/luna-interactive/' \
  --exclude='luna-frontier/' \
  --exclude='src/styles/' \
  --exclude='src/scripts/' \
  --exclude='src/fonts/' \
  --exclude='src/*.js' \
  --exclude='/vendor/' \
  --exclude='tests/' \
  --exclude='test-results/' \
  --exclude='design/' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='phpunit.xml.dist' \
  --exclude='.phpunit.result.cache' \
  --exclude='STATUS.md' \
  --exclude='NODE-2.0.md' \
  --exclude='NODE-1.3.md' \
  --exclude='STRUCTURAL-REVIEW-1.2.md' \
  --exclude='REFACTORING_PLAN.md' \
  --exclude='NODE_LIBRARY_REGRESSION_PLAN.md' \
  --exclude='1.2*.md' \
  --exclude='AGENTS.md' \
  --exclude='GEMINI.md' \
  --exclude='AI.md' \
  --exclude='TECHNOLOGIES.md' \
  --exclude='skills-lock.json' \
  --exclude='gemini_targets.txt' \
  --exclude='package.json' \
  --exclude='bun.lock' \
  --exclude='vite.config.js' \
  --exclude='HOW_TO_RELEASE.md' \
  --exclude='CHANGELOG.md' \
  --exclude='.gitignore' \
  --exclude='.gitattributes' \
  --exclude='assets/css/main.css' \
  --exclude='assets/css/material3.css' \
  --exclude='*.ttf' \
  --exclude='*.otf' \
  --exclude='*.woff' \
  --exclude='*.woff2' \
  ./ "$tmpdir/luna-frontier/"

(cd "$tmpdir" && zip -qr "$repo_dir/luna.zip" luna-frontier)
rm -rf "$tmpdir"
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

## 6. Gitへのコミットと配布チャンネルへの反映

1. 変更内容をステージングしてコミットします。
   ```bash
   git add -A
   git commit -m "chore: package Luna Frontier <version>"
   ```
2. 専用作業ブランチから `luna-frontier-2.0-skyalow` 宛てにPRを作成・レビューしてマージします（または指示された手順で配布ブランチへ反映）。
3. **注意**: `master` への直接 push は絶対に行わないでください。

## 7. 更新判定の整合性確認

Luna Frontier 2.0 の管理画面は、以下のRaw URLを参照して更新を判定します：
- バージョン確認: `https://raw.githubusercontent.com/wingzone94/Luna-Frontier/luna-frontier-2.0-skyalow/style.css`
- ビルド確認: `https://raw.githubusercontent.com/wingzone94/Luna-Frontier/luna-frontier-2.0-skyalow/build.json`
- ZIP取得: `https://github.com/wingzone94/Luna-Frontier/raw/refs/heads/luna-frontier-2.0-skyalow/luna.zip`

確認コマンド:
```bash
curl -L -s https://raw.githubusercontent.com/wingzone94/Luna-Frontier/luna-frontier-2.0-skyalow/style.css | head -n 12
curl -L -s https://raw.githubusercontent.com/wingzone94/Luna-Frontier/luna-frontier-2.0-skyalow/build.json
curl -L -s -o /tmp/luna-remote.zip https://github.com/wingzone94/Luna-Frontier/raw/refs/heads/luna-frontier-2.0-skyalow/luna.zip
unzip -p /tmp/luna-remote.zip luna-frontier/style.css | head -n 12
unzip -p /tmp/luna-remote.zip luna-frontier/build.json
```

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
