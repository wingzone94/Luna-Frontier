# Luna Frontier Preview 6向け Qwen修正候補

起点は `origin/work/luna-frontier-preview-5-integration` の確定済みコミット。Preview 5統合作業ツリーと `claude/spotlight-nav-format` の未コミット変更は含めない。現起点の `style.css` はまだ `2.0.0-preview.4` であり、このブランチをPreview 6の配布済み成果物とは扱わない。

## 取り込み範囲

- 親テーマのブログカードTLS検証、Geminiモデル一覧取得の上限、Node LibraryのTLS検証、AI AJAXの投稿編集権限確認。
- 記事表示時のOGP画像再生成の予約化、更新ZIPの検証と退避・復元。
- 同梱プラグイン13件の表示名と版定数をLuna / 1.4.0へ統一。単独プラグインの識別子を維持し、Luna Interactiveを同梱。

## Preview固有の確認結果

記事のAI要約は `template-parts/single/hero.php` のHeader内に表示し、`template-parts/ai-summary.php` はsingleモードを止める委譲ファイルになっている。親テーマのH-4修正をここへ直接適用しない。Previewの要約色は既存のHeaderデザインで決まっており、別途UI方針が確定するまで変更しない。

## 配布前に必要な条件

Preview 5統合の未コミット作業を確定し、正式なPreview 6起点を選ぶ。親テーマの今回のビルドとの組み合わせを固定し、Luna固有のホーム・記事・検索・モバイル・操作を検証してから版情報とZIPを生成する。
