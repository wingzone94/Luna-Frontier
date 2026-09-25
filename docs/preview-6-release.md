# Luna Frontier 2.0 Preview 6

2026年9月25日、Luna Frontier Preview 6の候補を作成します。

Preview 6は、Node 1.4.0を親テーマとして組み合わせ、Qwenレビューで確認・採用した修正を含みます。AI要約とファクトチェックの投稿編集権限確認、OGP画像再生成のバックグラウンド化、更新パッケージの検証とロールバック、Node LibraryのTLS証明書検証を反映しました。Luna Interactiveを同梱し、同梱プラグインの表示名とバージョンをLuna / 1.4.0に揃えています。

Previewの単一記事要約はLuna固有のテンプレートで表示されます。Nodeの要約色設定を上書きする処理は現行経路にないため、色変更は加えていません。計測対象としたNode Libraryのクエリは、今回の結果では最適化を正当化する負荷を示さず、検索データ移行やチャンク分割も含めていません。

## 配布物と導入

- `node.zip`: Node 1.4.0の親テーマビルド（Build ID `20260925T075504Z-8a77b46`）。
- `luna-frontier.zip`: Luna Frontier `2.0.0-preview.6`。ZIP内ルートは `luna-frontier/`。
- `production_plugins/*.zip`: 既存の独立配布プラグイン10本。プラグインslugを保ったままLuna表示名・1.4.0に同期。
- テーマZIPではSEO Tools内の重複フォントを除外し、テーマ側の `assets/ttf/NotoSansJP-VF.ttf` を参照。
- 配布先候補は `luna-frontier-2.0-skyalow`、タグ候補は `luna-frontier-v2.0.0-preview.6`。レビューと採用後に公開します。
- Preview版はNode安定版の `master` 更新チャンネルと分離。

## 検証

- `bun run build` 成功。`bun run verify:icon-subset` は未登録アイコン0件。
- 全PHPUnitは465テスト・2,299アサーション成功。別途AJAX権限グループは2テスト・8アサーション成功。
- LocalWPのNode Library回帰チェックは116項目すべて成功。固定フィクスチャ以外の投稿は操作していない。
- LocalWPでPreview候補を実際に有効化し、Preview 6のCSS URLを確認。ホーム、記事、検索、SPOTLIGHTは390px / 1440pxでHTTP 200。
- 画面レイアウト検査は記事・検索・SPOTLIGHTで問題なし。ホームはスクリーンリーダー専用見出しとARIAラベルのみを幅超過として報告した。画面上の横はみ出しはなく、スクリーンショットでも表示崩れは見つからなかった。
- LocalWPルート検査は11/11成功。
- 検証後にcybernode.localの有効テーマを`node-140`へ戻し、一時候補テーマを削除。
- 配布ZIPは検証通過後に作成し、ZIP内テーマバージョンとビルドIDを照合する。

H-10/H-11のLocalWP計測値と再判断条件は [Qwen高優先度指摘の後続対応](./qwen-high-followups.md) に記録しています。
