# Luminous Core / Node系テーマ P0・P1 引き継ぎ

作業日: 2026-10-28（JST）。対象は、公開サイトが実際に配信している独立テーマ Luna Frontier 2.0.1（Node由来の共通コードを含む）。Node 1.x配布チャンネルへの適用ではありません。

## 作業状態と適用範囲

- リポジトリ: `/Users/saitoutatsuya/Documents/GitHub/Node`
- 作業ブランチ: `fix/node-p0-p1-20261028`（最終検証作業は隔離ブランチ `fix/node-p0-p1-final-20261028`。完了時に元の作業ブランチをfast-forward）
- 基準: `origin/luna-frontier-2.0-skyalow` / `00a177b`。開始時の作業元は別ブランチ `perf/luna-2.0.1-mobile` / `c03700b` で、未コミット差分なし。
- リモートをfetchし、公開配布側にある10月18日以降のPSI修正・更新機構修正を含む基準へ切り替えた。開始ブランチのc03700bは変更していない。同ブランチ固有の10月26日の主カテゴリー反応性等は、この配布ブランチに無条件統合していない。
- ソースとビルド成果物を変更し、専用のLocalWPテーマ `node-p0-p1-validation` で確認。既存のLocalWPテーマファイルは上書きしていない。
- 本番更新・push・共有ブランチへのマージは行っていない。Versionは2.0.1を維持し、新しいBuild IDで配布候補ZIPをローカル生成・検証。公開配布チャンネルは未更新。
- AGENTS.md / AGENT.MD / DESIGN.MDを確認。対象ごとに調査・実装・検証し、全面デザイン変更や新ライブラリ追加は行っていない。

## 全10項目の判定

1. **B7: 修正完了（LocalWP）**。画像・記事情報・操作UIを通常フローの別領域へ分離。CTAと矢印・インジケーター・カウンター・一時停止の重なりを解消。CTA/矢印/再生は48px、ドットは44px以上。ドット列の横スクロールを記事スワイプから分離。全スライドが同じ高さへ伸び、長短タイトルでもCTAの縦位置が揃う。サムネイル、自動再生、一時停止、再開、手動切替、スワイプ、タップ遷移を保持。
2. **B2: 修正完了（LocalWP）**。画像とHTML見出しを別のグリッド領域に配置。モバイルは上下、デスクトップは左右。画像はcontainで文字の切り取りも抑える。全文HTMLタイトルとリンクを維持。画像内文字の自動検出は使用しない。
3. **B1: 修正完了（LocalWP）**。1000px以下では特集とおすすめカテゴリーを別行として最初から表示。各行のリスト内を横スクロールでき、↔とスクロールバーで操作を示す。リストにキーボードフォーカスと説明を追加。既存URL・リンク・表示順・メニュー設定・カテゴリー階層は変更していない。
4. **B3: 修正完了（LocalWP、実機未検証）**。最初の下端レール案は本文を覆うため撤回。最終実装では、本文Paperの右側に専用ガターを確保し、48pxの目次/TOPをその外側へ配置する。二重article gutterを統合し、Paper insetを12pxへ調整して狭い画面の可読幅を保持。320pxの本文内容幅218px、Paper右端256px、FAB左端264pxで8px分離。768pxではPaper右端700px、FAB左端712px。本文がFABの画面内位置を十分含む間だけ表示し、ヘッダー・前後記事・コメントなど全幅の他領域へ移る前に退避。コメントフォーム表示中/フォーカス時も退避する。画像・表・リンク・本文の文字RangeがFABと交差しないことを7幅×2テーマで実測。safe-areaの右/下を考慮し、windowのスクロール構造は変更していない。
5. **B4: 修正完了（LocalWP）**。背景画像への文字重ねを廃止し、装飾imgと本文領域を分離。前後記事のHTMLタイトルは全文表示。CSSの2行制限とLunaのタイトル短縮JSの対象から前後記事を除外。
6. **B5: 既に修正済み**。配布基準の検索・カテゴリー/アーカイブに通常ページネーションがあり、JavaScript無効のChromiumでも2ページ目へ遷移し、記事を閲覧できた。該当コードは変更していない。既存のJS機能も削除していない。
7. **B6: 修正完了（LocalWP）**。副カテゴリー用の生成CSSがライト向けの暗い文字色をダークにも固定していた。副チップの文字・枠をcategory色20%＋on-surface80%へ統一し、ライト/ダークへ追従。塗りチップのカテゴリー固有色は維持。検証したブランド塗りは8.01:1、薄黄の副チップはライト9.81:1、ダーク12.25:1。hover/focusも色とフォーカス表示を確認。追加監査で、ヒーロー専用カテゴリはYIQの白文字選択が生成CSSの対象から漏れることを発見。白文字は維持し、背景だけ最小限暗くする経路を追加。#d06060では3.80:1から背景#bd5757/白の4.5019:1へ改善し、既に読める色は変更しない。
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
- 10月18日以降の `node_defer_image_html`、画像候補復元順序sizes→srcset→src、画面外クローンのlazy/優先度除去、初期化前のvisibilityによるレイアウト予約を保持。最終修正では記事スワイプとクリック抑制をviewportだけへ限定した。操作バーの横スクロールを記事スワイプとして扱わない。320px・8スライドの実タッチ入力で、ドット列scrollLeft=90でも1/8を維持し、記事のスワイプでは2/8へ移ることを確認。画像の遅延読み込み処理は変更していない。
- `src/styles/_hero-slider.css` はVite entryへimportされていない。実際にenqueueされるのは `assets/css/lf-hero-slider.css`。この2ファイルを同期させる必要がある。今回、既存のレイアウト予約ルールもソース側へ一致させた。

## 維持するデザイン・トークン

- ブランドオレンジ `#FF9900`、既存ロゴ、丸いヒーローカード/操作ボタン、サムネイル帯、Paper基盤、既存のフォント・記事/カテゴリー識別を保持。
- `--lf-hero-radius` → `--node-hero-card-radius` / `--m3-radius-large`、`--lf-hero-shadow`、`--lf-hero-border`、`--node-content-wide-max`、`--node-gutter-inline`を継承。
- 新しい本文面は既存 `--md-sys-color-surface-container`、`--md-sys-color-on-surface`、`--md-sys-color-on-surface-variant`を使用。
- ナビは `--lf-content-max`、`--lf-gutter`、`--lf-space-*`、`--lc-accent`、`--lf-rule`。チップは `--category-color`、`--category-on-color` と on-surfaceを使用。
- FABは既存 `--node-fab-*` を維持。モバイル寸法は `--node-action-size:48px`、本文の `--lf-action-gutter:calc(52px + env(safe-area-inset-right))` とセット。Paperの `margin-inline:0 auto` を維持し、中央寄せでガターを侵食させない。下位置は24px＋safe-area。
- 旧画像重ね用 `--lf-hero-scrim` / `--lf-hero-scrim-mobile` は使用箇所がなくなったため撤去。画像重ねを復活させるトークンとして再導入しない。

## テスト結果と証拠

- `bun x vite build`: 成功（Vite 5.4.21、121 modules）。手書きのヒーローCSSは別途同期。
- 追加PHPUnit `Node_P0_P1_Test`: 6 tests / 39 assertions 成功。
- 全体PHPUnit: 523 tests / 2760 assertions、失敗0、1 skipped。配布ZIP一致検査も成功。
- ZIP生成前の機能テスト: 519 tests / 1854 assertions、失敗0、1 skipped。
- 変更PHPの構文検査: 成功。
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

- 配布候補ZIPはローカルで新Build IDを付けて再生成。公開する場合はこの検証済み候補とメタデータを使用し、HOW_TO_RELEASE.mdのPR/公開後確認を別途実行する。master/node.zipは対象外。
- 実機iPhone/Android、Safari/WebKit、実機スクリーンリーダー、非ゼロsafe-areaやソフトキーボード表示中の実挙動は未検証。headless Chromiumの画面幅検証を実機検証と呼ばない。
- 本番適用後の見た目・キャッシュ・更新機構は未検証。PSI/Lighthouseの性能スコアやLCPの非悪化は今回測定していない。
- 独立セッションによるレビューを実施し、B3の本文遮蔽、B7のドット列gesture、B6のhero色漏れの3件を修正。追検証では320〜430pxと境界幅の非交差、B7の修正を確認。独立レビューの最終ターンは使用上限で終了したため、最終的な全幅・メディア交差検証は実装担当が実行した。独立レビュー全項目の承認済みとは扱わない。P2では本差分を基準にし、P0/P1と混ぜた全面再設計をしない。
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
- `assets/js/footnotes-popover.DxYIyiOK.js`
- `assets/js/lf-hero-slider.js`
- `assets/js/luna.js`
- `assets/js/main.BfSJEyJZ.js`
- `assets/js/main.Cd6lXo7F.js`
- `build.json`
- `docs/verification/p0-p1-ui-handoff.md`
- `inc/luna/category-label.php`
- `inc/media.php`
- `luna.zip`
- `src/Controllers/TemplateController.php`
- `src/luna/luna.js`
- `src/luna/styles/_article-layout.css`
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

## Grok案件の保全

`8b04949d0f82d37495476b036f8fbd53b2970541` はmasterの「全記事一覧240件上限撤去」。今回とは別チャンネル・別案件で、対象6ファイルは今回のコード変更と重ならない。Grokコミットは本P0/P1ブランチ・ZIPへ取り込まず、masterもnode.zipも更新していない。検証コピーだけへ一時適用した共存テストでは31件中1件失敗したが、8b04949単体でも同じテスト・同じassertionが失敗した（空のページに対するfound_postsを27と期待するが、このLocalWPでは0）。今回の変更による回帰とは扱わず、Grokコード/テストは修正しない。`grok-preservation.json` と両方のログを参照。

## P2で守る追加の境界

- 本文の右ガターと48px操作ボタンを一体として保持する。単なるbody末尾paddingや下端レールへ戻さない。768pxを含めPaperを左側へ固定する。
- モバイル操作は本文のlaneに収まる間だけ表示する。本文以外の全幅セクションで強制表示しない。
- stage全体へtouchstart/touchend/click suppressionを再拡大しない。ドット列/サムネイルの操作と記事スワイプを分離する。
- categoryCSSのキャッシュはv4。ヒーローの既存白文字を暗い文字へ変更せず、必要な背景トーン調整を通す。

## 最終ゲート（2026-10-28）

- 機能テスト519件、全体テスト523件とも失敗0（いずれも1件スキップ）。全体は2760 assertions。最初に残していたZIPとソースの不一致は解消。
- 指定7幅×ライト/ダークの14条件は最終コードで再実行し、失敗0。本文文字Range・画像・表・リンクと操作ボタンの交差も0。
- ZIPから展開した専用LocalWPテーマ `node-p0-p1-package-validation` でも375 / 768 / 1366px × ライト/ダークの6条件でHTTP・JS・画像・レイアウト・記事切替を確認、全て成功。WordPressの外部プラグインとの二重読込を避けるため、LocalWP専用コピーのみembeddedプラグインを除外した。配布ZIPにはembeddedプラグインを保持。
- ZIPは268ファイル、3,413,871 bytes、全エントリーの内容がソースに一致。フォント・vendor・node_modules・開発用文書/テストは同梱しない。
- Build ID: `20261028T103729Z-6cebde2`、Version: `2.0.1`。
- ZIP SHA-256: `44afee5cdc9cfbe1c936908eccac3df360b891b3a890a246ccbff0d7eba46e13`。
- 最終ローカル復元: 元の有効テーマ `luna-frontier-2.0-validation` とカルーセル設定は全項目一致。検証用28投稿は下書き。2つの専用検証テーマとfixtureメディアは再検証用に保持。
- Grokのremote masterは `8b04949d0f82d37495476b036f8fbd53b2970541` のまま。対象6ファイルはP0/P1基準との差分なし。Grokを含む別案件をこのZIPへ混在させていない。
- 本番更新・push・リモートマージは行っていない。候補ZIPを本番適用済みとは扱わない。実機・Safari・性能スコア等の未検証範囲は上記のまま。

最終証拠: `full-phpunit.txt`、`functional-tests.txt`、`targeted-tests.txt`、`browser-matrix.json`、`browser-behavior.json`、`dot-gesture-regression.json`、`package-browser.json`、`package-verification.json`、`grok-preservation.json`、`local-restoration.json`。
