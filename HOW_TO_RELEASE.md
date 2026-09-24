# Node Theme リリース手順

このドキュメントでは、テーマ編集後に本番用のZIPファイル (`node.zip`) を生成し、GitHubにプッシュするまでの手順を説明します。

## 0. 命名・ブランド
- ブログ / サイトのブランド名は **Luminous Core** です。
- WordPress テーマ名は **Node** です。
- 配布ZIPのファイル名は **node.zip**、ZIP内のテーマルートディレクトリは **Node/** に統一します。
- ConoHa WING 本番環境は Linux のため、既存テーマURL `/wp-content/themes/Node/` と同じ大文字小文字を維持します。
- `style.css` の `Theme Name` は **Node** のまま維持します。

## 1. アセットのビルド
テーマ内のCSSやJavaScriptを変更した場合は、必ずビルドを実行して最新のアセットを生成します。

```bash
bun x vite build
```

### 古いハッシュ付きバンドルの掃除
`vite.config.js` の `clean-hashed-bundles` プラグインが、ビルド開始時に `assets/js/` 内のハッシュ付きバンドル（`main.<hash>.js` など2ドット形式のファイル）を自動削除します。手動配置の `blocks.js` や `assets/images/` 等は対象外です。

- `assets/js/` に実体として使われるのは `assets/.vite/manifest.json` が参照する最新ハッシュのファイルのみです。古いハッシュのファイルが残っていると `node.zip` が肥大化します。
- 何らかの理由でプラグインを経由せず古いバンドルが残った場合は、ZIP生成前に `git status assets/js` と `manifest.json` を突き合わせ、参照されていないハッシュ付き `.js` を削除してください（git追跡済みの古いバンドルは削除をコミットに含めます）。
- `assets/` には `images/`・`fonts/`・`pwa/`・`js/blocks.js` など手動配置物があるため、`build.emptyOutDir: true` は**使用禁止**です（`outDir` が `assets` 直下のため全消しされます）。

## 2. ローカル表示検査
`cybernode.local` にテーマを同期したあと、Playwrightで主要画面のスクリーンショット取得と文字潰れ・はみ出し候補の自動検査を行います。

```bash
bun run verify:visual
```

必要に応じて対象パスをカンマ区切りで指定します。

```bash
NODE_VISUAL_PATHS="/,/sample-post/,/category/news/,/?s=node" bun run verify:visual
```

スクリーンショットは `scratch/visual-check/` 以下に保存されます。検査が失敗した場合、HTTPエラー、ブラウザエラー、文字の横はみ出し、縦方向のクリップ、極端に詰まった行間の候補を確認し、問題が残る場合はZIP生成に進みません。

### 2-b. Node Library 回帰スイート（Node Libraryコード変更時は必須）

Node Library（`plugins-embedded/node-library/`）のコードを変更したリリースでは、ZIP生成前に回帰スイートを必ず実行します（NODE_LIBRARY_REGRESSION_PLAN.md 準拠）。

```bash
# 1. フィクスチャ記事の決定的再作成（LocalWPのphpバイナリで実行）
"/Users/saitoutatsuya/Library/Application Support/Local/lightning-services/php-8.2.30+1/bin/darwin/bin/php" \
  -d mysqli.default_socket="/Users/saitoutatsuya/Library/Application Support/Local/run/Q39UjXsTt/mysql/mysqld.sock" \
  scripts/library-fixtures.php

# 2. Playwright回帰チェック（タブ・ボタン・注記・Steamトグル・機種警告・モバイル幅）
bun scripts/library-regression.mjs
```

全項目passし、代表スクリーンショット（`scratch/library-regression/`）を確認できるまでZIP生成に進みません。フィクスチャは `node-library-regression-*` スラッグのみ操作するため、実運用データには影響しません。

## 3. 不要なファイルのクリーンアップ（任意）
開発時のキャッシュファイルや不要な`.DS_Store`ファイルなどが混入しないよう整理します。

## 4. 本番用ZIPファイルの生成

### 4-a. build.json（ビルド識別子）の更新（必須）
同日リリースはバージョンを据え置いたまま `node.zip` を更新する運用のため、リリース（=ZIP生成）のたびに **`build.json` を必ず再生成**します。Luminous Settings の更新チェックはバージョンに加えてこの `build_id` を比較し、同一バージョンでも新しいビルドの配信を検知・検証します（`inc/ajax.php` / `inc/utilities.php` の `node_get_build_info()`）。

```bash
printf '{\n    "build_id": "%s",\n    "built_at": "%s",\n    "version": "%s"\n}\n' \
  "$(date -u +%Y%m%dT%H%M%SZ)-$(git rev-parse --short HEAD)" \
  "$(date -u +%FT%TZ)" \
  "$(grep -m1 '^Version:' style.css | awk '{print $2}')" > build.json
```

- `build_id` は「UTC時刻 + ZIP生成時点の HEAD 短縮SHA」。SHAはリリースコミット自体ではなく**生成時点のHEAD**を指す（識別子としての一意性はタイムスタンプが担保）。
- `build.json` は配布ZIPに含め、**コミットにも含めます**（更新チェックが raw URL `https://raw.githubusercontent.com/wingzone94/Node/master/build.json` を参照するため）。

### 4-b. ZIP生成
プロジェクトルートディレクトリから、必要なファイルのみを含めたZIPファイルを作成します。以下のコマンドで `node.zip` を出力します。

**`src/` を丸ごと除外しないこと。** 1.3.0 から `src/Setup` `src/Hooks` `src/Controllers` に テーマ本体の PHP クラス（`NodeTheme\` 名前空間）が置かれ、`functions.php` のオートローダーがこれを読む。除外すると有効化時に `Class "NodeTheme\Setup\ThemeSupport" not found` で全面ダウンする。除外してよいのは Vite のソース（`src/styles` `src/scripts` `src/fonts` `src/*.js`）のみ。

配布ZIPに開発用の成果物（`vendor/`・`tests/`・`.claude/` 等）を混入させないこと。`--exclude='.git/'`（末尾スラッシュ付き）はディレクトリにしかマッチしないため、git worktree 運用で `.git` が**ファイル**（`gitdir:` ポインタ）になっている環境ではZIPに混入する。スラッシュ無しの `--exclude='.git'` を併記すること（1.2.5 のZIPには 78 バイトの `Node/.git` が入っていた）。本番動作に不要な `scripts/`（検証スクリプト）と `.github/`（Actions定義）も除外する。1.2 のリリース準備時、除外リストがこれらの追加に追いついておらず、ZIPが 8.3MB → 46MB に膨張していました。生成後は `vendor/bin/phpunit --filter Node_Release_Package_Test` を実行すること。src/ のオートロード対象 PHP の欠落、開発用成果物（vendor/・tests/・node_modules/）の混入、style.css と build.json のバージョン不一致を機械的に検出する。

生成後は必ず**サイズ**（目安 10MB 未満）と `zipinfo -1 node.zip | awk -F/ 'NF>2{print $2}' | sort -u` の**トップレベル構成**を確認してください。

```bash
rm -f node.zip
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
  --exclude='plugins-embedded/node-seo-tools/assets/share/fonts/NotoSansJP-VF.ttf' \
  ./ "$tmpdir/Node/"
(cd "$tmpdir" && zip -qr "$repo_dir/node.zip" Node)
rm -rf "$tmpdir"
```

`NotoSansJP-VF.ttf` はテーマ側 `assets/ttf/` を配布ZIP内の正本とします。埋め込み版 Node SEO Tools は、そのプラグイン内にフォントがない場合にテーマ側の同一ファイルを参照してからキャッシュ/CDNへフォールバックするため、OGP画像生成を維持したまま約9.6MBの重複収録を避けられます。独立プラグイン配布物の同梱フォントは削除しません。

## 5. Git へのコミットとプッシュ
変更したソースコードと、生成した本番用ZIPファイルをGitHubにプッシュします。

### 5-a. README / CHANGELOG のリリースノート確認（必須）

GitHubへリリース版をpushする前に、`CHANGELOG.md`には毎回、今回のバージョン・正式リリース日・パッチノートを記載します。

`README.md`へのパッチノート掲載は、**バージョンが `1.x.0` のリリース時だけ**必須です。たとえば `1.3.0` は掲載し、`1.3.1` や `1.3.2` は掲載しません。

- `README.md`: `1.x.0`リリースだけ、利用者向けの簡潔なパッチノートを掲載
- `CHANGELOG.md`: すべてのリリースについて、修正理由や影響範囲を含む完全な更新履歴を掲載
- 掲載対象では、バージョンと日付が `style.css` のリリース内容と一致すること

```bash
release_version="$(grep -m1 '^Version:' style.css | awk '{print $2}')"
release_date="YYYY.MM.DD" # 今回の正式リリース日に置き換える
rg -n -x -F "## [${release_version}] - ${release_date}" CHANGELOG.md
if [[ "${release_version}" =~ ^1\.[0-9]+\.0$ ]]; then
  rg -n -x -F "## v${release_version} (${release_date})" README.md
fi
```

上記の確認が通らない場合は、コミット・push・ZIP公開へ進みません。

```bash
# 変更されたファイルをステージング
git add .

# コミットを作成
git commit -m "chore: release Node theme"

# GitHubへプッシュ
git push origin master
```

## 6. Luminous Settings 更新確認との整合
テーマ管理画面の Luminous Settings は、GitHub Release やPRではなく、テーマ内の `inc/ajax.php` で指定している以下の `master` 固定URLを参照します。

- バージョン確認: `https://raw.githubusercontent.com/wingzone94/Node/master/style.css`
- ZIP取得: `https://github.com/wingzone94/Node/raw/master/node.zip`

そのため、PRブランチや `v1.1.x` タグを作成しただけでは、Luminous Settings には最新バージョンとして表示されません。リリース時は必ず `master` 上の `style.css` と `node.zip` を更新してください。

確認コマンド:

```bash
curl -L -s https://raw.githubusercontent.com/wingzone94/Node/master/style.css | sed -n '1,12p'
curl -L -s -o /tmp/node-remote.zip https://github.com/wingzone94/Node/raw/master/node.zip
unzip -p /tmp/node-remote.zip Node/style.css | sed -n '1,12p'
zipinfo -1 /tmp/node-remote.zip | head
```

確認ポイント:

- raw の `style.css` がリリース版の `Version` を返すこと。
- `node.zip` がHTTP 200で取得できること。
- ZIP内のルートディレクトリが `Node/` であること。
- ZIP内の `Node/style.css` も同じ `Version` を返すこと。
- **ZIP内の `Node/build.json` と raw の `build.json` が今回生成した `build_id` に一致すること**（`unzip -p /tmp/node-remote.zip Node/build.json`）。同一バージョンのリリースではこれが唯一の反映確認手段。
- `Author` は `Luminous Core Teams` のまま維持すること。

注意:

- GitHub の raw / raw ZIP URL はCDNキャッシュにより、push直後に古い `style.css` や `node.zip` を返す場合があります。
- `git clone` や GitHub Contents API で新しいコミットが確認できても、Luminous Settings が使う raw URL は数分遅れることがあります。
- raw URLが古い場合は、`cache-control` / `x-cache` / `source-age` ヘッダーを確認し、TTL切れ後に再確認してください。
- raw URLで新しい `Version` と新しいZIPを確認できるまで、「Luminous Settingsから更新可能」と報告しないでください。

## 7. バージョンと自動タグ運用（GitHub）
- `style.css` の `Version` をリリース版に更新してからコミットします（例: `1.0.1`）。
- `master` への push 時に GitHub Actions が `style.css` を読み取り、`v<Version>` タグを自動作成します。
- 既に同名タグが存在する場合は自動スキップされます。
- 自動タグの対象は `1.0.0` 以上です（`v1.0.0` から運用）。

---
※ この手順は、作業完了時に自動エージェントによって実行されるように設計されています。
