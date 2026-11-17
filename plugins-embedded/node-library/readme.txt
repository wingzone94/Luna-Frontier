=== Luna Library ===
Contributors: luminous-core-teams
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 1.6.0
License: MIT

ゲーム・アプリ情報の管理と商品リンクカード。Amazon・楽天・My Nintendo Storeに対応します。

== インストール・更新 ==

1. WordPressの「プラグイン → 新規プラグインを追加 → プラグインのアップロード」から node-library-1.6.0.zip を選択します。
2. 同名の単体プラグインがある場合は「アップロードしたもので現在のものを置き換える」を選択します。
3. 単体版がない場合はインストール後に有効化してください。Luna Frontierでは有効な単体版がテーマ内蔵版より優先されます。
4. 投稿編集画面を再読み込みしてください。商品リンクカードに「商品ページから取り込む」が追加されます。

商品カードの公開側表示には、既存のLuminous Nexus（Luna Nexus）の商品カード描画機能が必要です。このZIPにはテーマやNexusは含みません。

== 商品情報の取り込み ==

まず商品URLを入力して「情報を取得」を押してください。
Amazon側のアクセス制限やNintendo Storeの待合室などで自動取得できない場合は、次の方法で補完できます。

初回設定（macOS Chrome）:
1. 商品リンクカードの「商品ページから取り込む」を開きます。
2. 「初回設定：取り込み用ブックマークを登録」を開きます。
3. Chromeでブックマークを作成し、名前を「商品情報取込」に変更します。
4. ブックマークのURLを、画面に表示される「ブックマークのURL用コード」全体に置き換えて保存します。

毎回の操作:
1. ブラウザーでAmazonまたはMy Nintendo Storeの商品詳細ページを開きます。待合室の場合は通常の商品ページが表示されるまで待ってください。
2. 登録した「商品情報取込」ブックマークを実行します。
3. 商品名・画像URL・商品URL・商品識別子がコピーされます。コピーできない環境ではコピー用のテキストが表示されます。
4. 投稿編集画面に戻り、「商品ページでコピーしたデータ」へ貼り付けます。
5. 「取り込み内容を確認」で商品名・画像・URLを確認し、「この情報をカードに反映」を押します。
6. 別商品の情報になる場合は上書きの確認が必要です。価格と他ストアのリンクは変更されないため確認してください。
7. 最後に記事を保存してください。取り込むだけでは記事は保存・公開されません。

取り込み用ブックマークは開いている商品ページの情報だけを読み取り、外部サービスへの送信は行いません。APIキー、ブラウザーの拡張機能、Amazonへのログイン情報の設定は不要です。

== 自動取得の範囲 ==

* Amazon: OGPがない場合も商品主画像から補完します。サーバー側の取得制限を常に回避できるわけではありません。
* Nintendoソフト: 既存の公開ソフト検索APIを使用します。
* Nintendo周辺機器: 取得できた商品ページのOGP・Product構造化データを使用します。待合室は商品情報として保存しません。
* 取得失敗時も購入先URLと入力済み情報を保持します。
* Amazon Creators API連携はこの版には含みません。導入する場合は公式の利用資格・専用認証情報の確認が別途必要です。

== 構造化カタログ情報 ==

「カタログ情報（任意）」でストアID、開発元、発売元、発売日、ジャンル、CERO/ESRB、日本語の音声・字幕・UI、能力・映像仕様、サブスク在籍を保存できます。対応フラグは未設定・対応・非対応を区別します。ストアIDはURLではなくIDを入力してください。既存の紹介文・リンク・対応機種分類と公開表示は変更しません。この版ではストア情報の自動取得・旧リンクからの移行は行いません。

開発用メタ契約（すべて single / 任意 / 公開RESTには未公開）:
* _node_library_store_ids: microsoft / steam / nintendo / ps / amazon_asin / epic / gog / apple / googleplay の文字列マップ。先頭ゼロを保持。
* _node_library_developer / _node_library_publisher: 文字列。
* _node_library_release_date: YYYY-MM-DD。
* _node_library_genres: ジャンル文字列の配列。
* _node_library_rating: cero / esrb の文字列マップ。
* _node_library_japanese: audio / subtitles / ui のboolマップ。
* _node_library_capabilities: crossplay / crosssave / online / hdr のbool、max_fpsの正整数、resolutionの文字列。
* _node_library_subscriptions: gamepass / psplus / nso / ea_play / ubisoft_plus / apple_arcade の現在在籍フラグ（boolマップ、在籍中のみ保持。在籍の履歴管理は Task L7 の範囲）。
価格フィールドは L1 メタには持たせません（価格は時系列データであり Task L3 の価格履歴テーブルで扱います）。
未設定のキーは省略。falseは非対応。フォームにないフィールドは保持し、明示的な空欄は削除します。新規アダプタは Node_Library_Structured_Meta::sanitize() と同じ形式を使用してください。

== Microsoft Store / Xboxのカタログ取得 ==

ライブラリの編集画面にある「ストアカタログから取得」で12桁のプロダクトIDまたは公式ストアURLを入力します。タイトル入力・AIキーは不要です。「カタログを取得」でプレビューし、「確認した情報を編集欄へ反映」を押してから下書き保存・更新してください。取得ボタンだけでは投稿を保存しません。既存リンクと他ストアのIDは保持します。

タイトル・ストア説明文・発売元・開発元・ジャンル・レーティング・日本語の音声/字幕/UI・能力フラグ・対応機種をマッピングします。発売日は日本市場の日付（Asia/Tokyo）。購入可能な公開オファーから現在価格・定価・通貨・セール終了UTCを取得し、試用版、引き換え専用、会員限定オファーは価格に混ぜません。購入可能な価格がなければnullを返し、無料とは推測しません。APIが日本語対応の内訳を提供しない場合は未設定のままです。

関連商品は専用テーブル {prefix}node_library_relations に保存します。dlcはこの項目の追加コンテンツ、included_inはこの項目を含むバンドル/本編、bundle_containsはこのバンドルに含まれる商品です。DLCは公式APIの継続トークンを追跡し、全件取得できなければエラーにします。価格・画像などの現在の取得スナップショットは _node_library_catalog のストア別マップに保存します。関係リストはpostmetaに重複保存しません。価格履歴はまだ蓄積しません。

REST: POST /node-library/v1/fetch-store
入力: {"store":"microsoft","input":"9NBLGGH537BL","post_id":123}
対象node_libraryの編集権限とWordPress REST nonceが必要です。出力は store / id / title / summary / url / image_url / price / meta / features / platforms / relations / editions / subscriptions / subscription_metadata / fetched_at / source と、確認保存用ticketです。ticketは取得した編集者と投稿IDにひも付き、2時間で期限切れになります。取得失敗時はWP_Errorを返し、部分情報のticketは発行しません。確認保存には編集画面のcatalog nonceも必要です。

取得元はMicrosoft Display Catalogと、Xbox公式ストアが使用するEmeraldの商品・browse API。任意URLへアクセスせず、HTTPS検証を維持します。APIのスキーマ・ページ送りが変わった場合は理由付きエラーとなり、既存表示は保存済み情報を使用できます。

== 旧リンクからのストアID移行（WP-CLI） ==

最初にデータベースをバックアップし、変更なしの全件レポートを確認してください。対象は公開・下書き・非公開・ゴミ箱を含む全ライブラリ項目です。

    wp node-library migrate-store-ids --format=json > library-preview.json
    wp node-library migrate-store-ids --apply --format=json > library-migration.json

--apply を付けるまでデータを書き換えません。--format=csv または table も選べます。JSONには全件のitem_id、タイトル、success / needs_manual、抽出ID、URL別の理由と集計を含みます。既存IDは上書きせず、旧リンクは一切変更しません。複数エディションのIDが競合するストアは自動選択しません。

Microsoft/Xbox、Steam App、Amazon ASIN、NintendoのソフトID、PSの個別商品ID、Apple App ID、Google PlayのパッケージIDを抽出します。短縮URLの展開や外部通信は行いません。Epic/GOGのスラッグ、PS concept、URLにIDがないページは推測せず手作業対象です。invalid_existing_store_ids は既存IDメタの不正形式、database_write_failed はDB書き込み失敗を示します。

適用済みでもプレビューは何度でもできます。手作業修正後の再適用は --apply --force を明示します。処理は100件ずつ読み込み、実行開始時点の全件を対象にします。DB書き込み失敗時は終了コード1で、完了フラグを付けません。中断後にロックが残った場合は実行中プロセスがないことを確認してから wp option delete node_library_store_id_migration_lock で解除し、再実行してください。node_library_store_id_migration optionには最終適用日時と集計だけを保存します。全件レポートはCLI出力をファイルに保存してください。

== ソースと配布 ==

単体版1.5.1を統合した plugins-embedded/node-library が単一ソースです。このディレクトリを node-library/ としてパッケージすることで単体版を配布します。別実装や outputs/ 配下のコピーを継続編集しません。有効な単体版を優先し、未導入時だけテーマ内蔵版が動作します。単体導入時はテーマを必要としませんが、商品カード等のテーマ連携表示は対応する描画機能が必要です。

== 変更履歴 ==

= 1.6.0 =
* 単体版1.5.1を同梱ソースへ統合。
* 任意の構造化カタログ情報と、権限・nonce・自動保存保護を伴う編集欄を追加。
* 既存の紹介文・リンクの形式と公開カード表示を維持。


= 1.5.1 =
* テーマ同梱版の読み込み後でも単体プラグインを有効化できるよう、二重定義を防止。
* Amazon・My Nintendo Storeの商品ページからのブラウザー取り込みを追加。
* 商品情報のプレビュー、入力検証、別商品を上書きする際の確認を追加。
* アクセス制限・画像なし・一部取得の状態表示を追加。
* Nintendo周辺機器のProduct構造化データ取得に対応。
* Amazon主画像による補完と、不完全な取得結果のキャッシュを改善。
* 単体プラグインが自身のエディターJS・画像を読み込むよう修正。
