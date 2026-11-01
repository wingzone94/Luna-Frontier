=== Luna Ads Center ===
Contributors: luminouscore
Tags: ads, adsense, monetization, advertising
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

広告枠の配信を一元管理する独立プラグイン。初期状態では広告を配信しません。

== Description ==

Luna Ads Center は、Luminous Core / Luna Frontier のための独立した広告管理プラグインです。
テーマを切り替えても動作し、テーマ側の `node_the_ad_area()` や Site Kit と二重に広告を挿入しません。

主な特徴:

* 初期状態では広告配信を無効。AdSense 審査前でも安全に設置できます。
* 管理画面の設定は8項目（全体の有効・無効 / 管理元 / 配信方式 / 本文中 / 記事末尾 / インフィード / サイドバー / 表示除外）。
* 未設定の広告枠は、空白のプレースホルダーも含めて何も出力しません。
* 記事編集画面から記事単位で広告を除外できます。
* テーマ側・Site Kit 側の広告を検出した場合、同じ位置の出力を控えます。
* 自動広告と手動広告を区別し、自動広告のみの場合は枠コードを挿入しません。
* 広告コードを変更できるのは管理者だけです（nonce・権限・許可タグによる検証）。

== Installation ==

1. `luna-ads-control.zip` を「プラグイン → 新規追加 → プラグインのアップロード」からインストールします。
2. プラグインを有効化します。この時点では広告は配信されません。
3. 「Luna Settings → Luna Ads Center」で管理元・配信方式・各枠を設定します。
4. 問題がなければ「広告配信を有効にする」をオンにします。

== Frequently Asked Questions ==

= Site Kit と併用できますか？ =

はい。管理元を「Site Kit など外部で管理する」にすると、このプラグインは広告枠を出力しません。
「このプラグインで管理する」場合でも、Site Kit を検出すると AdSense ローダーは出力しません。

= テーマの広告設定と競合しませんか？ =

テーマ側の記事末尾・インフィード広告にコードが入っている場合、同じ位置はプラグインから出力しません。
設定画面に検出結果を表示します。

= サイドバーに置くには？ =

テーマにサイドバーがない場合は、ショートコード `[luna_ad slot="sidebar"]` を任意のブロックに置くか、
PHP テンプレートで `luna_ads_render( 'sidebar' );` を呼び出してください。

= 既存記事の本文は書き換えますか？ =

いいえ。表示時にだけ広告を挿入し、保存済みの本文は変更しません。

= 本文中・記事末尾の広告が表示されません =

このプラグインは WordPress 標準の `the_content` フィルターを使います。
テーマが独自のレンダリングで `the_content` を呼び出さない場合、本文中・末尾の広告は出力されません。
その場合はショートコード `[luna_ad slot="sidebar"]` または PHP の `luna_ads_render()` を任意の位置に置いてください。

== Changelog ==

= 1.0.0 =
* 初版。8項目の設定、4種類の広告枠、記事単位の除外、二重挿入防止、単体テストを追加。
