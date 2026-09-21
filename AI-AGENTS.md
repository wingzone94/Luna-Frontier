# AI-AGENTS.md — 本プロジェクトで利用するAIエージェント一覧

Luminous Core（ブログブランド） / Node（WordPressテーマ）の開発・運用・機能で利用しているAIエージェントおよびAIモデルをまとめる。
運用ルールの正本は [`AGENTS.md`](./AGENTS.md)。Claude Code / Codex / Cursor を含む共通方針は AGENTS.md に統合し、このファイルは利用ツール・モデルの参考一覧として扱う。

---

## 1. 開発・運用に利用するAIエージェント / ツール

| エージェント / ツール | 役割 | 主な用途 |
| --- | --- | --- |
| **Claude Code（VS Code / Claude アプリ）** | AIコーディングエージェント | 実装・差分・テストの主力。設計判断・横断レビュー・リリース前ゲート |
| **AntiGravity** | AIエージェント（Worktree / Local モード） | Gemini API 連携部の検証・調査・差分提案・限定的な実装 |
| **ChatGPT** | 対話型AI | プロンプト整理・仕様整理・記事/文章の下書き |
| **Codex（CLI / IDE）** | コーディングエージェント | 実装・調査・レビュー。共通ルールは `AGENTS.md` を参照 |

---

## 2. 利用するAIモデル

`AGENTS.md` の「モデル使用方針・週次制限対策」に基づき、以下を**高コスト / 中コスト / 通常・軽量**の3段階に区別して運用する。

### 高コストモデル（設計判断・重要修正・リリース前レビュー等に限定）

| モデル | 提供 | 備考 |
| --- | --- | --- |
| **Claude Opus 4.8**（thinking-high / thinking-high-fast を含む） | Anthropic | 難しい設計判断・破壊的変更前レビュー |
| **GPT-5.5**（Thinking を含む） | OpenAI | 重要修正・複雑なバグ調査。**「高」以上は非推奨** |
| **GPT-5.6**（Thinking を含む） | OpenAI | スポット起用のみ（例外運用）。**「高」以上は非推奨** |

### 中コストモデル（例外使用可能）

| モデル | 提供 | 備考 |
| --- | --- | --- |
| **Claude Fable 5（高）** | Anthropic | 実装・設計判断・ゲートの主力。**例外使用可能LLM**として位置付け、高コストモデルの使用条件は適用しない。ただし検証・雑務には使わない |

### 通常 / 軽量モデル（小規模修正・diff確認・文章作業）

| モデル | 提供 | 備考 |
| --- | --- | --- |
| **Claude Opus 4.6** | Anthropic | 定型実装・修正 |
| **Auto / 軽量モデル** | Claude Code の軽量モード等 | typo修正・小規模CSS修正・コミットメッセージ・diff要約 |
| **Gemini 系**（AntiGravity 等で利用） | Google | 調査・補助・Gemini API 領域 |

---

## 3. テーマ機能として組み込まれているAI

`node-ai-tools` プラグインおよび `inc/gemini-models.php` を通じて、Google Gemini API を利用する。
モデル一覧は API から動的取得し、取得失敗時は以下の静的フォールバックを使用する。

| モデルID | 表示名 |
| --- | --- |
| `gemini-2.5-flash` | Gemini 2.5 Flash |
| `gemini-2.5-pro` | Gemini 2.5 Pro |
| `gemini-2.0-flash` | Gemini 2.0 Flash |
| `gemini-2.0-flash-lite` | Gemini 2.0 Flash-Lite |

- API エンドポイント: `https://generativelanguage.googleapis.com/v1beta/models`
- デフォルトは Flash 系を優先

---

## 4. 運用上の原則（要約）

- 高コストモデル（Opus 4.8 / GPT-5.5 / GPT-5.6）は常用せず、設計判断・重要修正・リリース前レビューに限定する。
- **GPT-5.5 / GPT-5.6 の「高」以上は引き続き非推奨**（週次枠を先に消費し、実装レーンが止まるため）。
- **Fable 5（高）は中コストモデル**として、例外的に通常の実装・設計判断・ゲートに使用してよい。ただし検証・雑務には使わない。
- 1タスク1目的・読み込みファイルは原則5ファイルまで。
- 検証は `cybernode.local` で行い、未検証を検証済みとしない。
- 詳細は [`AGENTS.md`](./AGENTS.md) を正本とする。

---

*この一覧は開発時点の利用状況に基づく。モデルやツールを追加・変更した場合は本ファイルを更新すること。*
