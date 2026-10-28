# Luminous Core / Node系テーマ P0・P1 引き継ぎ

作業日: 2026-10-28（JST）。対象は、公開サイトが実際に配信している独立テーマ Luna Frontier 2.0.1（Node由来の共通コードを含む）。Node 1.x配布チャンネルへの適用ではありません。

## 作業状態と適用範囲

- リポジトリ: `/Users/saitoutatsuya/Documents/GitHub/Node`
- 作業ブランチ: `fix/node-p0-p1-20261028`
- 基準: `origin/luna-frontier-2.0-skyalow` / `00a177b`。開始時の作業元は別ブランチ `perf/luna-2.0.1-mobile` / `c03700b` で、未コミット差分なし。
- リモートをfetchし、公開配布側にある10月18日以降のPSI修正・更新機構修正を含む基準へ切り替えた。開始ブランチのc03700bは変更していない。同ブランチ固有の10月26日の主カテゴリー反応性等は、この配布ブランチに無条件統合していない。
- ソースとビルド成果物を変更し、専用のLocalWPテーマ `node-p0-p1-validation` で確認。既存のLocalWPテーマファイルは上書きしていない。
- 本番更新、push、マージ、配布ZIP更新は行っていない。Versionとbuild.jsonは基準版のまま。今回の差分パッチはソース引き継ぎ用で、更新配布用ZIPではない。
- AGENTS.md / AGENT.MD / DESIGN.MDを確認。対象ごとに調査・実装・検証し、全面デザイン変更や新ライブラリ追加は行っていない。

## 全10項目の判定

1. **B7: 修正完了（LocalWP）**。画像・記事情報・操作UIを通常フローの別領域へ分離。CTAと矢印・インジケーター・カウンター・一時停止の重なりを解消。CTA/矢印/再生は48px、ドットは44px以上。全スライドが同じ高さへ伸び、長短タイトルでもCTAの縦位置が揃う。サムネイル、自動再生、一時停止、再開、手動切替、スワイプ、タップ遷移を保持。
2. **B2: 修正完了（LocalWP）**。画像とHTML見出しを別のグリッド領域に配置。モバイルは上下、デスクトップは左右。画像はcontainで文字の切り取りも抑える。全文HTMLタイトルとリンクを維持。画像内文字の自動検出は使用しない。
3. **B1: 修正完了（LocalWP）**。1000px以下では特集とおすすめカテゴリーを別行として最初から表示。各行のリスト内を横スクロールでき、↔とスクロールバーで操作を示す。リストにキーボードフォーカスと説明を追加。既存URL・リンク・表示順・メニュー設定・カテゴリー階層は変更していない。
4. **B3: 修正完了（LocalWP、実機未検証）**。モバイルの縦並びFABを下端の横並びレールへ変更。safe-area、文書末尾余白、アンカー/フォームのスクロール余白を確保し、内容をレールより上まで移動できる。コメントフォームが画面内にある間、またはフォームにフォーカスがある間はレール全体を退避。目次とTOPの機能を保持。レールは固定表示のため画面下端の領域を使う。本文全体を別スクローラーへ変更する設計は採用していない。
5. **B4: 修正完了（LocalWP）**。背景画像への文字重ねを廃止し、装飾imgと本文領域を分離。前後記事のHTMLタイトルは全文表示。CSSの2行制限とLunaのタイトル短縮JSの対象から前後記事を除外。
6. **B5: 既に修正済み**。配布基準の検索・カテゴリー/アーカイブに通常ページネーションがあり、JavaScript無効のChromiumでも2ページ目へ遷移し、記事を閲覧できた。該当コードは変更していない。既存のJS機能も削除していない。
7. **B6: 修正完了（LocalWP）**。副カテゴリー用の生成CSSがライト向けの暗い文字色をダークにも固定していた。副チップの文字・枠をcategory色20%＋on-surface80%へ統一し、ライト/ダークへ追従。塗りチップのカテゴリー固有色は維持。検証したブランド塗りは8.01:1、薄黄の副チップはライト9.81:1、ダーク12.25:1。hover/focusも色とフォーカス表示を確認。
8. **C3: 修正完了（標準ロゴ）**。現在の標準ヘッダーロゴはaltと表示テキストの二重名があった。三重名になるaria-labelは現行コードにはなかった。装飾画像をalt空＋aria-hiddenにし、可視テキストだけでリンク名を形成。WordPress標準のカスタムロゴ分岐、フッターテキスト、画像・フォント・寸法は維持。実機スクリーンリーダーによる音声検証は未実施。
9. **C4: 修正完了（LocalWP / PHPUnit）**。記事ヘッダーは生の公開/更新日が異なるだけで更新日を表示し、カードは別の手動日付判定を使用していた。公開日時を変更した投稿等でpost_modifiedがpost_dateより古い場合も「更新日」と出る原因があった。共有ヘルパーへ統一し、公開前/非公開、公開以前の更新、未来の更新を抑制。手動日付は実在する日・公開日以後・今日以前のみ採用。不正な手動日付は有効なWP更新日へフォールバック。正当な同日追記の手動開示は維持。DBの投稿日時そのものは書き換えない。
10. **D1: 修正完了（LocalWP）**。本番の1366px記事で未使用preload警告を実測。元コードはfullをpreloadし、記事ヘッダーはlargeを描画していた。as=image自体は正しかった。largeの通常画像生成・URLフィルター適用後からsrc/srcset/sizesを取得し、fetchpriority=highを表示と揃えた。記事以外は画像の表示自体が保証されないため推測でpreloadしない。修正後の全7幅で警告なし、preloadと表示候補が一致。同じ表示URLの重複取得なし。デスクトップの同一画像に別サイズがあるのは前後記事用サムネイルの取得で、未使用fullの取得とは区別した。

## B2・B7を壊さないためのP2作業の注意

- `.lf-hero-slide__card` の画像領域と記事領域のグリッド分離を維持する。HTMLタイトルを画像上へ絶対配置し直さない。
- `.lf-hero-slider__toolbar` はviewportの後ろに置く通常フローの兄弟領域。CTAや画像上へ重ねない。余白だけで干渉を隠さない。
- trackのstretch、cardのheight:100%、copyの縦flex、titleのflex:1がCTAを揃える。短いタイトルでカード全体を縮めたり、JSで高さを後補正しない。
- HTMLタイトルの全文を保持する。ヒーローと前後記事を、一覧カード用TITLE_SELECTORやmax-block-size/line-clampへ再追加しない。
- ドットの見た目の点は疑似要素。ボタン本体44pxと4pxの間隔を縮めない。8スライド等で横幅が不足したときはドット列内がスクロールできる構造を保つ。
- CTAの白文字は、ホームの汎用 `body.lf-theme .lf-home a {color:inherit}` に負けないコンポーネントスコープにしてある。特異性を落とすとライトで暗い本文色を継承するため注意。
- サムネイルと再生ボタンを削除しない。`data-lf-*`、aria-controls、aria-pressed、inert、クローンの扱いを変える場合は操作テストをやり直す。
- 10月18日以降の `node_defer_image_html`、画像候補復元順序sizes→srcset→src、画面外クローンのlazy/優先度除去、初期化前のvisibilityによるレイアウト予約を保持。今回カルーセルJSは変更していない。
- `src/styles/_hero-slider.css` はVite entryへimportされていない。実際にenqueueされるのは `assets/css/lf-hero-slider.css`。この2ファイルを同期させる必要がある。今回、既存のレイアウト予約ルールもソース側へ一致させた。

## 維持するデザイン・トークン

- ブランドオレンジ `#FF9900`、既存ロゴ、丸いヒーローカード/操作ボタン、サムネイル帯、Paper基盤、既存のフォント・記事/カテゴリー識別を保持。
- `--lf-hero-radius` → `--node-hero-card-radius` / `--m3-radius-large`、`--lf-hero-shadow`、`--lf-hero-border`、`--node-content-wide-max`、`--node-gutter-inline`を継承。
- 新しい本文面は既存 `--md-sys-color-surface-container`、`--md-sys-color-on-surface`、`--md-sys-color-on-surface-variant`を使用。
- ナビは `--lf-content-max`、`--lf-gutter`、`--lf-space-*`、`--lc-accent`、`--lf-rule`。チップは `--category-color`、`--category-on-color` と on-surfaceを使用。
- FABは既存 `--node-fab-*`、surface-container-low、outline-variantを使用。safe-area-inset-bottom/left/rightを考慮。
- 旧画像重ね用 `--lf-hero-scrim` / `--lf-hero-scrim-mobile` は使用箇所がなくなったため撤去。画像重ねを復活させるトークンとして再導入しない。

## テスト結果と証拠

- `bun x vite build`: 成功（Vite 5.4.21、121 modules）。手書きのヒーローCSSは別途同期。
- 追加PHPUnit `Node_P0_P1_Test`: 5 tests / 15 assertions 成功。
- 全体PHPUnit: 522 tests / 2760 assertions、1 failure、1 skipped。失敗は既存 `luna.zip` と変更後TemplateControllerの一致検査のみ。
- 配布ZIPを扱う `Luna_Release_Package_Test` 4件を除外した機能テスト: 518 tests / 1830 assertions、失敗なし、1 skipped。
- 変更PHP10ファイルの構文検査: 成功。
- アイコンクラス検査: Font Awesomeなし。アイコンサブセット検査: 高信頼参照と実ページに未登録なし。中信頼のcart等は既存の参考情報として出力される。
- `node --test scripts/topic-nav-icons.test.mjs`: 3件成功。
- Chromium / DPR 1: 320 / 375 / 390 / 430 / 768 / 1366 / 2560px × ライト/ダーク。横はみ出し、画像/タイトル/CTA/操作UIの交差、ターゲット寸法、CTA位置、前後記事の文字切れ、コメント時退避、ロゴのアクセシブル名を検査。14条件で失敗なし。各条件で次ボタン5回・サムネイル復帰・CTA遷移を実操作。
- 手動切替後はカウンターだけで判定せず、現在スライドとviewportの左端が一致するまで待って検証。スクリーンショットも移動/既存登場演出の完了後に撮影。
- 長短タイトル、文字入り/文字なし/画像なしのローカル投稿を使用。自動送り1→2、一時停止2の維持、タップ再開2→3、スワイプ1→2、reduced-motionで自動送り停止、目次開閉、TOP→scrollY=0を確認。
- JavaScript無効: 検索・カテゴリーとも通常リンクで2ページ目へ移動、4記事の表示を確認。
- 既存記事1459（お知らせ/内部ブログカード等）、検索、カテゴリー: HTTP 200、Fatalなし、390px横はみ出しなし。固定ページはsample-pageで別途確認。LocalWPの `/privacy-policy/` はページデータがないため404であり、固定URLの修正はしていない。
- ブラウザの未処理JS例外・ローカル4xx/5xx: 14条件の対象ページでなし。
- 証拠JSON: `browser-matrix.json`、`browser-behavior.json`、`preload-before.json`、`preload-after.json`、`extra-verification.json`、`page-smoke.json`。
- 画像: `hero-{375,1366}-{light,dark}.png`、`article-nav-{375,1366}-{light,dark}.png`、`comments-375-{light,dark}.png`、`toc-375-light.png`。before-hero-375.pngは本番の読取結果、afterはLocalWPの制御用fixtureであり、同一記事のpixel比較ではない。

## 未解決・未検証と次の工程

- **配布ゲートは未完了**: 配布ZIPを変更していないため、そのソース一致検査はまだ失敗する。公開する段階ではHOW_TO_RELEASE.mdに従い、同一バージョンでもBuild IDを更新し、検証済みソースからZIPを生成して全テストを通すこと。既存luna.zipを今回の実装済みZIPと誤認しない。
- 実機iPhone/Android、Safari/WebKit、実機スクリーンリーダー、非ゼロsafe-areaやソフトキーボード表示中の実挙動は未検証。headless Chromiumの画面幅検証を実機検証と呼ばない。
- 本番適用後の見た目・キャッシュ・更新機構は未検証。PSI/Lighthouseの性能スコアやLCPの非悪化は今回測定していない。
- 独立した別モデル/別セッションによるレビューは未実施。P2担当は本差分を基準にレビューし、P0/P1と混ぜた全面再設計をしない。
- 新規LocalWP検証投稿は作業終了時に下書きへ戻す。元の有効テーマとカルーセル設定も復元する。候補テーマと検証用メディアは再検証用に残す。既存投稿の日時・本文やテーマファイルは変更していない。
- 再検証にはwork/fixtures.jsonのIDとURL、work/local-options-before.jsonを参照。テスト投稿を再公開して専用テーマを有効にし、カルーセルsource=ids、ID=先頭6件、autoplay=1、interval=4000で検証できる。終了後は再び復元する。

## 変更ファイル

以下はソース/テスト/文書とビルド成果物。ハッシュ付きJSの削除/追加はVite再生成による。
- `CHANGELOG.md`
- `assets/.vite/manifest.json`
- `assets/css/lf-hero-slider.css`
- `assets/css/luna-archive.css`
- `assets/css/luna-style.css`
- `assets/css/style.css`
- `assets/js/footnotes-popover.BA0518wr.js`
- `assets/js/footnotes-popover.s9ugIGFU.js`
- `assets/js/luna.js`
- `assets/js/main.9gapDLHb.js`
- `assets/js/main.Cd6lXo7F.js`
- `docs/verification/p0-p1-ui-handoff.md`
- `inc/luna/category-label.php`
- `inc/media.php`
- `src/Controllers/TemplateController.php`
- `src/luna/luna.js`
- `src/luna/styles/_chrome.css`
- `src/luna/styles/_latest.css`
- `src/scripts/floating-actions.js`
- `src/styles/_fab.css`
- `src/styles/_hero-slider.css`
- `src/styles/_navigation.css`
- `src/styles/_toc-fab.css`
- `template-parts/components/floating-actions.php`
- `template-parts/hero-slider.php`
- `template-parts/legacy/header.php`
- `template-parts/luna/topic-nav.php`
- `template-parts/single/footer.php`
- `template-parts/single/hero.php`
- `tests/node-p0-p1-test.php`

## 再検証コマンド

PHPUnitはリポジトリをcwdとして実行（LocalWP起動が必要）。Bunでビルドし、アイコン対応表テストはNodeのnode:testランナーを使う。

```sh
bun x vite build
php vendor/bin/phpunit -c phpunit.xml.dist --filter Node_P0_P1_Test
php vendor/bin/phpunit -c phpunit.xml.dist --filter '/^(?!Luna_Release_Package_Test::)/'
node --test scripts/topic-nav-icons.test.mjs
git diff --check
```

このMacではLocalWP付属PHP 8.2.29を使って検証した。CLIの既定PHPは8.5.7のため、実行環境は区別する。ブラウザ再検証スクリプトは成果物側へ添付し、元のチャット作業ディレクトリをcwdにして実行する（work/fixtures.jsonを使用）。各スクリプトはこのMacの既存Playwrightへの絶対パスをimportする。別環境ではそのパスとfixtureの用意を調整する。
