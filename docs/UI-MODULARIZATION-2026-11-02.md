# UIモジュール化・モバイル格納・LLM入口整理の完了記録

対象: ヘッダー/記事の巨大CSSと読み込み入口、モバイルTOP/目次格納、ルートLLM指示。
範囲: 実装・ビルド・専用ローカル環境での統合検証。本番公開・有効テーマ切替・Gitコミット・ZIP生成は含めていない。

## 成果

- ヘッダー1,556行は8責務のpartialと9行の互換入口へ分割。
- 記事2,822行の旧実装は11行のimport専用入口へ整理。既存10partialの全ルールを元ソースと照合し、欠落・追加・重複数の変化がないことを確認。既存partial順序は維持。
- 混在していた記事spotlight 1,061行はlabels / metadata / content / breadcrumbsへ分割し、5行の互換入口を保持。分割ソースは元ファイルを完全再構成する。
- style.cssはimportとレイヤー順だけを管理。アイコン互換は専用partialへ移し、noticeの二重importを除去。
- 読み込みグラフ68ファイルに重複import・循環・1,000行超の実装partialなし。
- モバイルは＋からTOP・目次を展開。Escape、外側クリック、操作後の収納、隠れた子要素のフォーカス除外、PCへの復帰を実装。WebKitのクリック時フォーカス差異も修正。
- AGENT.MDを正本とし、AGENTS.md / CLAUDE.md / GEMINI.mdは3行の参照入口のみ。旧入口の削除状態を維持。

## 検証

- Bun/Viteビルド、PHP/JS構文、git diff --check: 合格。
- 最終記事分割前後の生成CSS: ルール・宣言・順序・条件・所属レイヤーが一致。同名レイヤー区切りのみ93バイト増加。バイト同一とはしていない。
- Chromium: 14/14合格（現在のmanifestに一致するJS読み込み確認を含む）。
- WebKit: 同じ14/14合格。実機Safariでの確認とは区別する。
- 幅320/768/1000/1001、見出しなし記事、コメント欄付近の退避: 合格。
- /about/、検索、/category/android/: HTTP200、Fatal errorなし。
- 専用テーマluna-ui-modular-validationをリクエスト限定で使用。有効テーマ選択と他作業ツリーは上書きしていない。一時フィルターは検証後削除。
- 未検証: 物理iPhone/Android、認証済み管理バー、全記事・全配色の網羅。

## 再検証

```sh
bun x vite build
bun scripts/verify-style-modules.mjs
bun scripts/verify-agent-entrypoints.mjs
NODE_VISUAL_BASE_URL=http://cybernode.local NODE_ACTIONS_ARTICLE=/post-2425/ bun scripts/verify-mobile-actions.mjs
NODE_ACTIONS_BROWSER=webkit NODE_VISUAL_BASE_URL=http://cybernode.local NODE_ACTIONS_ARTICLE=/post-2425/ bun scripts/verify-mobile-actions.mjs
```

ブラウザ検証は変更を反映したテーマで行う。上のURLだけで現在の有効テーマが検証対象と同じとは限らない。環境変数NODE_ACTIONS_THEMEにテーマディレクトリ名を渡すと、現在のmanifestとの一致も検査できる。
