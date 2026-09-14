# Node
### WordPress Theme for Luminous Core

![Version](https://img.shields.io/badge/version-1.3.0-orange?style=for-the-badge)
![License](https://img.shields.io/badge/license-MIT-blue?style=for-the-badge)
![WordPress](https://img.shields.io/badge/WordPress-6.0+-21759b?style=for-the-badge&logo=wordpress)

Material Design 3 (Expressive) の哲学を WordPress テーマに昇華させた、次世代のクリエイティブ・プラットフォーム。  
README は導入と運用ガイドに集中し、リリース履歴は `CHANGELOG.md` に集約します。

## Luna Frontier 2.0 Preview

Luna Frontierは、Luminous Coreの次期デザインを検証するNodeの子テーマです。現在の最新版は **2.0.0-preview.4** です。Preview版は安定版の `master` 更新チャンネルとは分離して配布します。

- **Preview 1（2026.08.16）**: 編集メディア向けのトップページ、四角いPaper表現、Manrope見出し、モバイルセクションナビを導入した初回公開版。
- **Preview 2（2026.08.22）**: Material 3トークン、記事・パンくず・目次・FABの階層、モバイル表示、ZIP検査とCIを整備した基盤更新版。
- **Preview 3（2026.09.12）**: 記事ヘッダー、著者・カテゴリ・検索、カード配色、HOT枠を更新し、Node AI ToolsとNode Image Compressorを同梱した最新版。
- **Preview 4（2026.09.14）**: 1段のトピックナビゲーション、SPOTLIGHTアーカイブ、特集一覧の統一表示、レスポンシブ対応を追加。

Preview 4の配布物、導入方法、検証結果は [Luna Frontier 2.0 Preview 4 リリースノート](./docs/preview-4-release.md) を参照してください。各版の詳細は [CHANGELOG.md](./CHANGELOG.md) に記録しています。現時点のLunaは `Template: node` に依存し、スタンドアロン化はまだ含みません。

## 主な機能
- **Material You 動的カラー:** アイキャッチ画像やカテゴリ設定からテーマカラーを自動生成。
- **シリーズ（連載）:** 複数記事を連載としてまとめ、目次・前後ナビ・カード上のバナー（現在回/全話数）を自動表示。
- **Node Library:** 作品・アプリのストアフロント一覧と個別ページ。記事からの導線と「この作品に触れた記事」の逆引きに対応。
- **ブログカード / 埋め込み:** 自サイト・他サイトの記事URLを統一デザインのカードに変換（X・YouTube は標準の埋め込みを維持）。
- **インテリジェント詳細検索:** 読了時間、文字数、プラットフォーム、AI生成の有無などで高度な絞り込みが可能。
- **フローティング・ナビゲーション:** 記事ページでの目次アクセス、コメント移動、トップ戻りをスムーズに。
- **プラットフォーム・ブランド連携:** デバイスごとの公式ブランドカラーをUIに反映（Windows, iOS, Android, Nintendo, PlayStation, Xbox）。
- **AI 連携:** Gemini・Qwen・Ollamaを共通基盤から利用し、記事要約・ファクトチェック補助・校正を支援。ファクトチェックは無料枠で使える最新の Gemini Flash を自動選択し、Web 検索と公式ページを根拠に検証します（根拠のない断定は保存前に補正されます）。
- **アイキャッチの WebP 置き換え:** アップロードしたアイキャッチを自動で WebP へ置き換え、転送量と LCP を改善。品質は画像ごとに自動決定し、「Node Settings → 画像圧縮」または投稿編集画面からいつでも手動で実行できます。置き換えで消えた旧 URL は新しい画像へ 301 転送されます。
- **PWA 対応:** オフライン閲覧やホーム画面へのインストールをサポート。

## v1.2.1 (2026.07.19)
Node 1.3「Connect」で正式提供予定の外部連携プラグイン **node-connect（ベータ版）** を同梱するマイナーアップデートです。本番環境での動作検証を目的としています。

- **node-connect ベータ版を同梱** — 記事の公開・更新・非公開化・削除を Discord へ自動通知する Webhook 基盤。設定 → 外部連携から Webhook URL（最大3件）とイベントを選んで利用します。テーマZIP内の `plugins-embedded/node-connect/` および `production_plugins/node-connect.zip`（インストール用）として同梱。
- テーマ更新（Luminous Settings の更新インストール）成功時に `node_connect_event` を発火するフックを追加。

## v1.2.0 (2026.07.18)
Node の記事体験を一新するメジャーアップデートです。詳細は [CHANGELOG.md](./CHANGELOG.md) を参照してください。

- **シリーズ（連載）機能を新設** — 記事を連載としてまとめ、目次・前後ナビ・話数バナーを表示。シリーズ共通色と記事ごとの色上書きの2段階設定に対応。
- **記事ヒーローを再設計** — PC は縦2カラム（左に核心、右に補足）に整理し、目次をヒーローへ統合。1000px 以下では従来どおり1カラムへ自動で折りたたみ。
- **読了時間の精度を改善** — 550字/分の固定換算と絶対文字数による長さ判定に改定し、従来の相対換算による異常値を解消。
- **Node Library を新設計** — ストアフロント一覧と個別ページを追加。タイプ別の絞り込みはプリティURL（`/node-library/game/` 等）に対応。
- **ブログカードを刷新** — 自サイト記事がカード化されない問題を修正し、oEmbed パイプライン方式へ移行。エディタ用の `node/embed` ブロックも追加。
- **記事カードの改善** — カード全面クリック、追記日の表示、グリッドの高さ統一。
- **日付アーカイブ と 404 ページを新設** — 日別タイムライン型の一覧と、検索フォーム付きの404ページ。
- **導線の強化** — ライター別アーカイブとSNSピル、フッターへの公式SNS常設。

## インストール
1. `wp-content/themes/node` に配置。
2. `bun install && bun run build` を実行してアセットを生成。
3. WordPress 管理画面より「Node」を有効化。
4. 必要に応じて `functions.php` または環境変数に Gemini API キーを設定。

---
**Node Teams**
*Evolution through Light and Logic.*

## 更新履歴
更新履歴は [CHANGELOG.md](./CHANGELOG.md) を参照してください。
