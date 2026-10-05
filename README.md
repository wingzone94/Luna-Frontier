# Luna Frontier
### WordPress Theme for Luminous Core

![Version](https://img.shields.io/badge/version-2.0.0-orange?style=for-the-badge)
![License](https://img.shields.io/badge/license-MIT-blue?style=for-the-badge)
![WordPress](https://img.shields.io/badge/WordPress-6.0+-21759b?style=for-the-badge&logo=wordpress)

Material Design 3 (Expressive) の哲学を WordPress テーマに昇華させた、次世代のクリエイティブ・プラットフォーム。  
README は導入と運用ガイドに集中し、リリース履歴は `CHANGELOG.md` に集約します。

## Luna Frontier 2.0

Luna Frontier は Node の子テーマではありません。Node 1.x を起点に発展した、単体で成立する WordPress テーマです。`Template:` ヘッダーは置かず、Node テーマのインストールも不要です。`node_*` / `NODE_*` / `_node_*` などの名前は、既存サイトデータと公開 API の後方互換として残しています。

**2.0.0の配布物はGitHubの専用ブランチへ反映済み**で、サイトへのインストールは2026年10月10日を予定しています。Node安定版の`master`とは更新チャンネルを分離しています。本番サイトへの導入完了を示すものではありません。

## Luna Frontier 2.0.0の主な変更

- Nodeを親テーマとしない、単体で動作するテーマへ移行。
- ヘッダー検索とSPOTLIGHTナビゲーションを整理し、「過去の特集」へのリンクを追加。
- アイキャッチ未設定時の画像表示を刷新し、安全性を重視した画像検査・修復機能を追加。
- Steam埋め込み、WebP置き換え、OGP設定、AI処理と同梱プラグインを整備。
- Luna専用の更新チャンネルとZIPで配布。Node安定版とは分離。

各バージョンの変更点は[CHANGELOG.md](./CHANGELOG.md)にまとめています。

## 主な機能
- **Material You 動的カラー:** アイキャッチ画像やカテゴリ設定からテーマカラーを自動生成。
- **シリーズ（連載）:** 複数記事を連載としてまとめ、目次・前後ナビ・カード上のバナー（現在回/全話数）を自動表示。
- **Luna Library:** 作品・アプリのストアフロント一覧と個別ページ。記事からの導線と「この作品に触れた記事」の逆引きに対応。
- **ブログカード / 埋め込み:** 自サイト・他サイトの記事URLを統一デザインのカードに変換（X・YouTube は標準の埋め込みを維持）。
- **インテリジェント詳細検索:** 読了時間、文字数、プラットフォーム、AI生成の有無などで高度な絞り込みが可能。
- **フローティング・ナビゲーション:** 記事ページでの目次アクセス、コメント移動、トップ戻りをスムーズに。
- **プラットフォーム・ブランド連携:** デバイスごとの公式ブランドカラーをUIに反映（Windows, iOS, Android, Nintendo, PlayStation, Xbox）。
- **AI 連携:** Gemini・Qwen・Ollamaを共通基盤から利用し、記事要約・ファクトチェック補助・校正を支援。ファクトチェックは無料枠で使える最新の Gemini Flash を自動選択し、Web 検索と公式ページを根拠に検証します（根拠のない断定は保存前に補正されます）。
- **アイキャッチの WebP 置き換え:** アップロードしたアイキャッチを自動で WebP へ置き換え、転送量と LCP を改善。品質は画像ごとに自動決定し、「Luna Settings → 画像圧縮」または投稿編集画面からいつでも手動で実行できます。置き換えで消えた旧 URL は新しい画像へ 301 転送されます。
- **PWA 対応:** オフライン閲覧やホーム画面へのインストールをサポート。

## インストール
1. [Luna Frontier 2.0.0の配布ZIP](https://github.com/wingzone94/Luna-Frontier/raw/refs/heads/luna-frontier-2.0-skyalow/luna.zip)をダウンロードする。ZIP内のルートは`luna-frontier/`です。
2. WordPress管理画面の「外観 → テーマ → 新規追加 → テーマのアップロード」から`luna.zip`をアップロードし、「Luna Frontier」を有効化する。既存の同名テーマがある場合は、管理画面の置換確認に従って更新する。
3. 管理画面でバージョン`2.0.0`を確認し、トップページと記事ページを表示確認する。Nodeテーマの追加インストールやBunによるビルドは不要です。

---
**Luminous Core Teams**
*Evolution through Light and Logic.*

## 更新履歴
更新履歴は [CHANGELOG.md](./CHANGELOG.md) を参照してください。
