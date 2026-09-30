# Luna Frontier 2.0 Preview 3

> **記録:** この文書は Preview 3 当時の配布記録です。現行の Luna Frontier は独立テーマであり、`Template: node` は使いません。

Preview 3は、Luna Frontierのデザイン更新と、それを動かすテーマ本体・同梱プラグインの変更をまとめたテスト向け配布です。

## 主な変更

- 記事ヘッダー、目次、カテゴリ、著者カード、検索画面のデザイン更新。
- HOT記事枠、カードの補助配色、画像・タイトル・日付の表示調整。
- モバイルのLATESTとデスクトップのリストで、本文領域がカード右端から切れる問題を修正。
- Node AI Toolsのファクトチェック更新と、Node Image Compressorを同梱。

## 配布物と導入

- `node.zip`: Node 1.3.0のPreview 3用ビルド。ZIP内ルートは既存の配布契約に合わせて `Node/`。
- `luna-frontier.zip`: Luna Frontier `2.0.0-preview.3`。ZIP内ルートは `luna-frontier/`。
- `production_plugins/node-ai-tools.zip`: Node AI Tools単体配布。
- `production_plugins/node-image-compressor.zip`: Node Image Compressor単体配布。

当時の Preview 3 は `Template: node` に依存する子テーマとして配布していました。その構成は廃止済みです。現行版は Luna Frontier 単体で有効化します。

同梱プラグインと単体プラグインの二重配置には既存のロード制御が適用されます。画像圧縮は既定で元画像を削除するため、復元を必要とする運用では、実行前に「元ファイルを残す」を有効にしてください。

## 検証（2026-09-12）

- Node / LunaのViteビルド成功。
- PHPテスト454件・1613 assertions成功。ZIP再生成後の配布物検査も10件・181 assertions成功。
- 変更対象PHP 59ファイルの構文検査成功。
- 動的配色のコントラスト180件成功。アイコンの静的・実ページ検査で未登録なし。
- LocalWPでトップ、記事、検索結果、検索の空状態、カテゴリ、固定ページ、著者一覧を390px / 1440px、ライト / ダークの28条件で確認。HTTP 200、ページ横はみ出し・JavaScript例外・失敗リクエストなし。
- LATEST / 著者一覧のカード内部幅を320 / 390 / 700 / 768 / 1440pxで確認。10条件で本文領域の右端はみ出しなし。
- モバイル検索送信、目次リンクの移動先、コメント入力欄の表示、通常モーションでスクロール後にカードが隠れたままにならないことを確認。
- ブラウザ検証は既存のローカル記事・設定を使用。外部AIの実API応答、画像圧縮の本番画像への実行、本番サイト反映は今回の検証対象外。

## 次のデザイン改善候補

- モバイルではHOTの3記事がHEADLINE・LATESTより先に続く。最新記事を優先する編集方針なら、枠の順序やHOTの表示方法を別途検討する。
- HOT・HEADLINE・LATESTでカテゴリ数、タイトルの省略、メタ情報の密度が異なる。各枠の役割を維持しつつ、表示する情報の優先順位を揃える余地がある。

## 公開先

配布ブランチ: `luna-frontier-2.0-skyalow`。タグ: `luna-frontier-v2.0.0-preview.3`。

安定版の `master` 更新チャンネルと本番サイトには反映しない。
