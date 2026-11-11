=== Luna Products ===
Contributors: luminouscore
Tags: blocks, product-card, affiliate, ecommerce, material-design
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

商品画像・スペック・複数ストアの価格比較と購入導線を表示する Gutenberg 動的ブロック（`luna-products/card`）。

== Description ==

**Luna Products** は、Luminous Core 向けの独立した商品カードプラグインです。コミュニティ投票で採用された「C案（商品画像重視＋右カラムにストア行リスト）」をベースに、価格の視認性・OFF バッジ・最安チップ・ネットワーク別ディスクロージャー出力を備えています。

= 主な機能 =

* **C案レイアウト＋価格視認性**: デスクトップ（641px 以上）では左に白背景の 1:1 商品画像パネル、右に商品名・スペック行・ストア行リストを配置。モバイル（640px 以下）では左寄せを維持したまま縦積みに切り替え、360px 幅でも価格と CTA ボタンが横並びで収まります。
* **プリセットレジストリによるストア管理**: PHP 側の単一レジストリ（`luna_products_store_presets()`）からエディターの選択肢とサーバーサイド描画の双方を生成。1カードあたり最大4行まで配置できます。
* **最安・OFF率・公式バッジ**:
  * 価格が入力された行のうち最小価格の行（同額時は先頭行のみ）に「最安」チップを自動付与し、行背景をわずかに強調します。
  * 定価（`listPrice`）が販売価格（`price`）を上回る場合、取り消し線つき定価と切り捨て計算による `% OFF` バッジを表示します。
  * メーカー直販行など任意の行に「公式」チップを表示できます。
* **アフィリエイト自動付与・ディスクロージャー制御**:
  * 管理画面「設定 > Luna Products」にて Amazon トラッキング ID・楽天アフィリエイト ID・各ディスクロージャー文言を設定可能。
  * カード内に実際に描画されたネットワーク（Amazon／楽天市場）の分だけ、カード下部にディスクロージャーを自動表示します。

= ストア階層（Tier 1〜3）と並び順の運用方針 =

1. **Tier 1（常時表示・初期2行）**:
   * `amazon`（Amazon）、`rakuten`（楽天市場）
   * ブロック挿入時に初期状態でこの2行が用意されます。アフィリエイト ID 自動付与の対象です。
2. **Tier 2（メーカー直販プリセット）**:
   * `anker`（Anker Store）、`nintendo`（My Nintendo Store）、`apple`（Apple Store）、`microsoft`（Microsoft Store）、`playstation`（PlayStation Store）、`steam`（Steam）
   * 直販行は原則としてアフィリエイト自動付与の対象外（入力された素の URL をそのまま出力）です。
   * 価格を入力せず、製品ページへのリンクのみ（`url` のみ）の行としても配置できます。
   * **【並び順の運用ルール】**: 通常は Tier 1（Amazon／楽天市場）を先頭に置きますが、**公式限定版や、メーカー公式価格・公式ストアの在庫が基準になる商品（Apple 製品、任天堂・PlayStation のダウンロード版／公式限定セット、Anker 公式セール等）では、「上へ／下へ」ボタンで Tier 2（メーカー直販行）を Tier 1 より上に配置し、「公式」チップを ON にする運用**を推奨します。
3. **Tier 3（その他 EC プリセット＋カスタム）**:
   * プリセット：`yodobashi`（ヨドバシカメラ）、`biccamera`（ビックカメラ）、`joshin`（Joshin）、`sofmap`（ソフマップ）、`yahoo`（Yahoo!ショッピング）
   * カスタム（`custom`）：プリセットにない販売先について、編集者が「表示名＋URL＋ブランド色（16進数・任意）」を自由入力できます。

== Installation ==

1. `luna-products` ディレクトリを `/wp-content/plugins/` に配置します。
2. WordPress 管理画面の「プラグイン」メニューから **Luna Products** を有効化します。
3. 必要に応じて「設定 > Luna Products」から Amazon トラッキング ID・楽天アフィリエイト ID・ディスクロージャー文言を設定します。
4. ブロックエディターで「商品カード（Luna Products）」（`luna-products/card`）ブロックを挿入して編集します。

== Changelog ==

= 1.0.0 =
* 初回リリース。C案ベースの商品カードブロック `luna-products/card`、Tier 1〜3 ストアプリセットレジストリ、アフィリエイト設定画面、最小回帰テストを実装。
