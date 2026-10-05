<?php

declare(strict_types=1);
/**
 * Theme Settings Page
 *
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add Settings Menu
 */
function node_add_admin_menu() {
    // 「設定」配下に埋もれていたが、AI・外部連携と並ぶ運営の入口なので
    // ダッシュボードのトップレベルへ独立させる（1.3）
    $page = add_menu_page(
        'Luna Settings',
        'Luna Settings',
        'manage_options',
        'luminous-settings',
        'node_render_settings_page',
        'dashicons-admin-generic',
        58 // 「外観」の少し上（プラグインの並びと衝突しにくい位置）
    );

    // 親と同じスラッグの子を先頭に置き、サブメニュー名が「Luna Settings」の重複表示になるのを避ける
    add_submenu_page(
        'luminous-settings',
        'Luna Settings',
        'テーマ設定',
        'manage_options',
        'luminous-settings',
        'node_render_settings_page'
    );

    add_action( 'admin_print_scripts-' . $page, function() {
        wp_enqueue_media();
    } );
}
add_action( 'admin_menu', 'node_add_admin_menu' );

/**
 * Register Settings
 */
function node_register_settings() {
    // --- Gemini設定 ---
    // Gemini設定はユーザーごとに保存するため、ここでは登録しません
    // register_setting( 'node_settings_group', 'node_gemini_api_key' );
    // register_setting( 'node_settings_group', 'node_gemini_model' );

    // --- ライブラリ設定 (Luna Library) ---
    register_setting( 'node_settings_group', 'node_library_auto_insert' );
    register_setting( 'node_settings_group', 'node_library_header_text' );
    register_setting( 'node_settings_group', 'node_library_button_text' );

    // --- 商品リンク設定 (Nexus) ---
    register_setting( 'node_settings_group', 'luminous_nexus_amazon_id' );
    register_setting( 'node_settings_group', 'luminous_nexus_rakuten_id' );
    register_setting( 'node_settings_group', 'luminous_nexus_disclosure_amazon' );
    register_setting( 'node_settings_group', 'luminous_nexus_disclosure_rakuten' );
    
    // --- X (Twitter) 連携設定 ---
    // node-connect プラグイン（設定 → 外部連携）へ移設。オプション名は node_x_* のまま互換維持。

    // --- OGP設定 ---
    register_setting( 'node_settings_group', 'node_ogp_enabled' );
    register_setting( 'node_settings_group', 'node_ogp_bg_id' );
    register_setting( 'node_settings_group', 'node_ogp_logo_id' );

    // --- Google 優先ソース CTA ---
    register_setting( 'node_settings_group', 'node_preferred_source_enabled', array(
        'sanitize_callback' => static function ( $value ) {
            return '1' === (string) $value ? '1' : '0';
        },
    ) );
    register_setting( 'node_settings_group', 'node_preferred_source_placement', array(
        'sanitize_callback' => static function ( $value ) {
            $allowed = array( 'article', 'footer', 'both' );
            return in_array( $value, $allowed, true ) ? $value : 'article';
        },
    ) );
    register_setting( 'node_settings_group', 'node_preferred_source_url', array(
        'sanitize_callback' => static function ( $value ) {
            $url = esc_url_raw( $value );
            return $url ?: NODE_PREFERRED_SOURCE_DEFAULT_URL;
        },
    ) );

    // --- 印刷（Node 1.3 第5段階） ---
    register_setting( 'node_settings_group', NODE_PRINT_OPTION_ENABLED, array(
        'sanitize_callback' => static function ( $value ) {
            return '1' === (string) $value ? '1' : '0';
        },
    ) );
    register_setting( 'node_settings_group', NODE_PRINT_OPTION_POSITION, array(
        'sanitize_callback' => static function ( $value ) {
            return in_array( $value, array( 'start', 'end' ), true ) ? $value : 'end';
        },
    ) );
    register_setting( 'node_settings_group', NODE_PRINT_OPTION_SHOW_META, array(
        'sanitize_callback' => static function ( $value ) {
            return '1' === (string) $value ? '1' : '0';
        },
    ) );
}
add_action( 'admin_init', 'node_register_settings' );

/**
 * Render Settings Page
 */
function node_render_settings_page() {
    ?>
    <div class="wrap">
        <h1 style="margin-bottom: 30px;">Luminous Core テーマ設定</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'node_settings_group' );
            wp_nonce_field( 'node_save_gemini', 'node_gemini_settings_nonce' );
            ?>
            
            <!-- メンテナンスモード -->
            <div class="m3-admin-card" id="node-maintenance" style="background: #fff; padding: 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid <?php echo node_maintenance_is_enabled() ? '#FF9900' : '#e0e0e0'; ?>; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                <h2 style="margin-top: 0; color: #FF9900; display: flex; align-items: center; gap: 10px;">
                    <span class="dashicons dashicons-hammer"></span> メンテナンスモード
                    <?php if ( node_maintenance_is_enabled() ) : ?>
                        <span style="font-size: 12px; font-weight: 700; color: #fff; background: #FF9900; padding: 2px 10px; border-radius: 999px;">有効</span>
                    <?php endif; ?>
                </h2>
                <p class="description" style="margin-top: 0;">
                    有効にすると、サイトのフロント側に専用のメンテナンス画面（HTTP 503）を表示します。管理画面とログイン画面は影響を受けません。
                    <strong>管理者にもメンテナンス画面が表示されます</strong>が、画面内に管理画面へ戻るリンクが出ます。
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row">メンテナンスモード</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( NODE_MAINTENANCE_OPTION_ENABLED ); ?>" value="1" <?php checked( node_maintenance_is_enabled() ); ?> />
                                有効にする
                            </label>
                            <p class="description">切り替えると node-connect 経由で Discord へ開始・終了が通知されます（購読設定が必要）。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">開始予定時刻</th>
                        <td>
                            <input type="datetime-local" name="<?php echo esc_attr( NODE_MAINTENANCE_OPTION_START ); ?>" value="<?php echo esc_attr( (string) get_option( NODE_MAINTENANCE_OPTION_START, '' ) ); ?>" />
                            <p class="description">
                                設定した場合、有効化済みでもこの時刻までは通常表示を維持し、到達後にメンテナンス画面へ切り替えます。
                                サイトのタイムゾーン（<?php echo esc_html( wp_timezone_string() ); ?>）で解釈されます。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">復旧予定時刻</th>
                        <td>
                            <input type="datetime-local" name="<?php echo esc_attr( NODE_MAINTENANCE_OPTION_ETA ); ?>" value="<?php echo esc_attr( (string) get_option( NODE_MAINTENANCE_OPTION_ETA, '' ) ); ?>" />
                            <p class="description">
                                設定すると、メンテナンス画面にカウントダウンと進捗ゲージが表示され、到達後は自動解除して Luminous Core のトップへ移動します（未設定なら非表示）。
                                サイトのタイムゾーン（<?php echo esc_html( wp_timezone_string() ); ?>）で解釈されます。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">表示メッセージ</th>
                        <td>
                            <textarea name="<?php echo esc_attr( NODE_MAINTENANCE_OPTION_MESSAGE ); ?>" rows="3" class="large-text" placeholder="<?php echo esc_attr( NODE_MAINTENANCE_DEFAULT_MESSAGE ); ?>"><?php echo esc_textarea( (string) get_option( NODE_MAINTENANCE_OPTION_MESSAGE, '' ) ); ?></textarea>
                            <p class="description">空欄の場合は既定の文面を表示します。</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Gemini設定（管理者自身の個人キー。ライターは ユーザー → プロフィール で設定） -->
            <div class="m3-admin-card" style="background: #fff; padding: 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #e0e0e0; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                <h2 style="margin-top: 0; color: #FF9900; display: flex; align-items: center; gap: 10px;">
                    <span class="dashicons dashicons-rest-api"></span> Gemini 設定（管理者・個人）
                </h2>
                <?php
                $current_user = wp_get_current_user();
                if ( function_exists( 'node_render_gemini_user_fields' ) ) {
                    node_render_gemini_user_fields( $current_user, true );
                }
                ?>
                <p class="description" style="margin-top:0;">
                    ライター（投稿権限のあるユーザー）は <strong>ユーザー → プロフィール</strong> から、各自の API キーを設定してください。
                </p>
            </div>

            <!-- ライブラリ設定 -->
            <div class="m3-admin-card" style="background: #fff; padding: 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #e0e0e0; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                <h2 style="margin-top: 0; color: #FF9900; display: flex; align-items: center; gap: 10px;">
                    <span class="dashicons dashicons-database"></span> ライブラリ設定 (Luna Library)
                </h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">自動表示の有効化</th>
                        <td>
                            <?php $auto_insert = get_option( 'node_library_auto_insert', '1' ); ?>
                            <label>
                                <input type="checkbox" name="node_library_auto_insert" value="1" <?php checked( $auto_insert, '1' ); ?> />
                                ライターエリアの下に「ゲーム・アプリ情報」を自動で表示する
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">カード見出しの文言</th>
                        <td>
                            <input type="text" name="node_library_header_text" value="<?php echo esc_attr( get_option( 'node_library_header_text', 'GAME / APP INFO' ) ); ?>" class="regular-text" />
                            <p class="description">ライブラリカードの上部に表示される見出しです。</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">ボタンの補助テキスト</th>
                        <td>
                            <input type="text" name="node_library_button_text" value="<?php echo esc_attr( get_option( 'node_library_button_text', 'で見る' ) ); ?>" class="regular-text" />
                            <p class="description">プラットフォーム名の後に続く文言です。（例：「Steam で見る」の「で見る」部分）</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 商品リンク設定 -->
            <div class="m3-admin-card" style="background: #fff; padding: 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #e0e0e0; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                <h2 style="margin-top: 0; color: #FF9900; display: flex; align-items: center; gap: 10px;">
                    <span class="dashicons dashicons-cart"></span> 商品リンク設定 (Amazon/楽天)
                </h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Amazon アソシエイト ID</th>
                        <td>
                            <input type="text" name="luminous_nexus_amazon_id" value="<?php echo esc_attr( get_option( 'luminous_nexus_amazon_id' ) ); ?>" class="regular-text" placeholder="example-22" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Amazon 用注釈文言</th>
                        <td>
                            <input type="text" name="luminous_nexus_disclosure_amazon" value="<?php echo esc_attr( get_option( 'luminous_nexus_disclosure_amazon', 'Amazonのアソシエイトとして、当メディアは適格販売により収入を得ています。' ) ); ?>" class="large-text" />
                        </td>
                    </tr>
                    <tr><td colspan="2"><hr></td></tr>
                    <tr>
                        <th scope="row">楽天 アフィリエイト ID</th>
                        <td>
                            <input type="text" name="luminous_nexus_rakuten_id" value="<?php echo esc_attr( get_option( 'luminous_nexus_rakuten_id' ) ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">楽天 用注釈文言</th>
                        <td>
                            <input type="text" name="luminous_nexus_disclosure_rakuten" value="<?php echo esc_attr( get_option( 'luminous_nexus_disclosure_rakuten', '当メディアは、楽天アフィリエイト・プログラムの参加者です。' ) ); ?>" class="large-text" />
                        </td>
                    </tr>
                </table>
            </div>

            <!-- X (Twitter) 連携設定は node-connect プラグイン（設定 → 外部連携）へ移設 -->

            <!-- OGP自動生成設定 -->
            <div class="m3-admin-card" style="background: #fff; padding: 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #e0e0e0; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                <h2 style="margin-top: 0; color: #6200EE; display: flex; align-items: center; gap: 10px;">
                    <span class="dashicons dashicons-format-image"></span> OGP 自動生成設定
                </h2>
                <p class="description">記事タイトルを合成したOGP画像を自動で生成します（GDライブラリを使用）。</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">自動生成を有効にする</th>
                        <td>
                            <label>
                                <input type="checkbox" name="node_ogp_enabled" value="1" <?php checked( get_option( 'node_ogp_enabled' ), '1' ); ?> />
                                記事保存時にOGP画像を自動生成する
                            </label>
                            <p class="description">
                                ※背景デザインおよびロゴは「Luminous Core 公式仕様」としてシステムに固定されています。
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Google 優先ソース CTA -->
            <div class="m3-admin-card" style="background: #fff; padding: 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #e0e0e0; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                <h2 style="margin-top: 0; color: #FF9900; display: flex; align-items: center; gap: 10px;">
                    <span class="dashicons dashicons-search"></span> Google 優先ソース CTA
                </h2>
                <p class="description">読者が任意で Luminous Core を Google 検索の優先ソースに追加するための補助導線です。</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">優先ソースCTAを表示する</th>
                        <td>
                            <?php $preferred_source_enabled = get_option( 'node_preferred_source_enabled', '1' ); ?>
                            <input type="hidden" name="node_preferred_source_enabled" value="0" />
                            <label>
                                <input type="checkbox" name="node_preferred_source_enabled" value="1" <?php checked( $preferred_source_enabled, '1' ); ?> />
                                表示する
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">表示場所</th>
                        <td>
                            <?php $preferred_source_placement = get_option( 'node_preferred_source_placement', 'article' ); ?>
                            <select name="node_preferred_source_placement">
                                <option value="article" <?php selected( $preferred_source_placement, 'article' ); ?>>記事下部のみ</option>
                                <option value="footer" <?php selected( $preferred_source_placement, 'footer' ); ?>>フッターのみ</option>
                                <option value="both" <?php selected( $preferred_source_placement, 'both' ); ?>>記事下部＋フッター</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">優先ソースURL</th>
                        <td>
                            <input type="url" name="node_preferred_source_url" value="<?php echo esc_attr( get_option( 'node_preferred_source_url', NODE_PREFERRED_SOURCE_DEFAULT_URL ) ); ?>" class="regular-text" />
                            <p class="description">初期値: <code><?php echo esc_html( NODE_PREFERRED_SOURCE_DEFAULT_URL ); ?></code></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 印刷 -->
            <div class="m3-admin-card" style="background: #fff; padding: 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #e0e0e0; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                <h2 style="margin-top: 0; color: #FF9900; display: flex; align-items: center; gap: 10px;">
                    <span class="dashicons dashicons-printer"></span> 印刷
                </h2>
                <p class="description">記事を紙やPDFで残すための機能です。PDF保存はブラウザの印刷画面から行えます。</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">印刷機能を有効にする</th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr( NODE_PRINT_OPTION_ENABLED ); ?>" value="0" />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( NODE_PRINT_OPTION_ENABLED ); ?>" value="1" <?php checked( node_print_is_enabled() ); ?> />
                                記事下部に印刷ボタンを表示する
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ボタンの表示位置</th>
                        <td>
                            <select name="<?php echo esc_attr( NODE_PRINT_OPTION_POSITION ); ?>">
                                <option value="end" <?php selected( node_print_button_position(), 'end' ); ?>>シェアボタン群の末尾</option>
                                <option value="start" <?php selected( node_print_button_position(), 'start' ); ?>>シェアボタン群の先頭</option>
                            </select>
                            <p class="description">「この記事をシェアする」の並びの中での位置です。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">記事URL・著作権表記</th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr( NODE_PRINT_OPTION_SHOW_META ); ?>" value="0" />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( NODE_PRINT_OPTION_SHOW_META ); ?>" value="1" <?php checked( node_print_show_meta() ); ?> />
                                印刷したページの末尾に記事URLと著作権表記を入れる
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- テーマのアップデート -->
            <div class="m3-admin-card" style="background: #fff; padding: 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #e0e0e0; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                <h2 style="margin-top: 0; color: #FF9900; display: flex; align-items: center; gap: 10px;">
                    <span class="dashicons dashicons-update"></span> テーマのアップデート
                </h2>
                <p class="description">Luna Frontier の配布ブランチから最新の <code>luna.zip</code> を取得して自動インストールします。</p>
                
                <div id="luminous-update-info" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                    <p>現在のバージョン: <strong>v<?php echo esc_html( node_get_theme_version() ); ?></strong></p>
                    <?php $node_build_info = function_exists( 'node_get_build_info' ) ? node_get_build_info() : null; ?>
                    <p>現在のビルド: <strong><?php echo esc_html( $node_build_info['build_id'] ?? '不明（build.json なし）' ); ?></strong></p>
                    <p class="description">フッター表示（v<?php echo esc_html( node_get_theme_version() ); ?>）と同じ style.css の Version を参照しています。同一バージョンのまま luna.zip が更新された場合はビルド識別子で検知します。</p>
                    <div id="update-check-result"></div>
                </div>

                <button type="button" id="luminous-check-update" class="button button-secondary">アップデートを確認</button>
                <button type="button" id="luminous-install-update" class="button button-primary" style="display:none;">最新版をインストール</button>
                
                <div id="update-progress-container" style="display:none; margin-top: 20px;">
                    <div style="width: 100%; background: #eee; border-radius: 10px; height: 10px; overflow: hidden;">
                        <div id="update-progress-bar" style="width: 0%; height: 100%; background: #FF9900; transition: width 0.3s ease;"></div>
                    </div>
                    <p id="update-status-text" style="margin-top: 10px; font-weight: bold;"></p>
                </div>
            </div>

            <?php submit_button('すべての設定を保存'); ?>

            <script>
            jQuery(document).ready(function($){
                // wp.media が存在する場合のみ実行（JSエラー防止）
                if ( typeof wp !== 'undefined' && typeof wp.media !== 'undefined' ) {
                    var frame;
                    $('.node-media-upload').on('click', function(e) {
                        e.preventDefault();
                        var button = $(this);
                        var target = $(button.data('target'));
                        var preview = $(button.data('preview'));
                        
                        if (frame) { frame.open(); return; }
                        frame = wp.media({ title: '画像を選択', button: { text: 'これを使う' }, multiple: false });
                        frame.on('select', function() {
                            var attachment = frame.state().get('selection').first().toJSON();
                            target.val(attachment.id);
                            preview.html('<img src="' + attachment.url + '" style="max-width: 300px; height: auto; border-radius: 8px;">');
                        });
                        frame.open();
                    });
                    $('.node-media-clear').on('click', function() {
                        $($(this).data('target')).val('');
                        $($(this).data('preview')).empty();
                    });
                }

                // --- テーマアップデート機能 ---
                $('#luminous-check-update').on('click', function() {
                    var btn = $(this);
                    btn.prop('disabled', true).text('確認中...');
                    $('#update-check-result').html('');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'luminous_check_update',
                            nonce: '<?php echo wp_create_nonce("luminous_update_nonce"); ?>'
                        },
                        success: function(response) {
                            btn.prop('disabled', false).text('アップデートを確認');
                            if (response.success) {
                                var remote = response.data.remote_version;
                                var local = response.data.local_version;
                                if (response.data.update_available) {
                                    $('#update-check-result').html('<p style="color: #FF9900; font-weight: bold;">新しいバージョン (v' + remote + ') が見つかりました！</p>');
                                    $('#luminous-install-update').text('最新版をインストール').show();
                                } else if (response.data.install_available) {
                                    if (response.data.build_update_available) {
                                        $('#update-check-result').html('<p style="color: #FF9900; font-weight: bold;">同一バージョン (v' + local + ') の新しいビルドが配信されています。<br>配信中: ' + response.data.remote_build + '<br>インストール済み: ' + (response.data.local_build || '不明') + '</p>');
                                        $('#luminous-install-update').text('新しいビルドを再インストール').show();
                                    } else if (response.data.remote_build && response.data.local_build && response.data.remote_build === response.data.local_build) {
                                        $('#update-check-result').html('<p style="color: #4CAF50;">最新バージョン・最新ビルドです (v' + local + ' / ' + response.data.local_build + ')。同じバージョンを再インストールできます。</p>');
                                        $('#luminous-install-update').text('同じバージョンを再インストール').show();
                                    } else {
                                        $('#update-check-result').html('<p style="color: #4CAF50;">最新バージョンです (v' + local + ')。ビルド比較は利用できません（配信側またはインストール側に build.json がありません）。同じバージョンを再インストールできます。</p>');
                                        $('#luminous-install-update').text('同じバージョンを再インストール').show();
                                    }
                                } else {
                                    $('#update-check-result').html('<p style="color: #4CAF50;">最新バージョンです (v' + local + ')。</p>');
                                    $('#luminous-install-update').hide();
                                }
                            } else {
                                $('#update-check-result').html('<p style="color: #f44336;">エラー: ' + response.data + '</p>');
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('アップデートを確認');
                            $('#update-check-result').html('<p style="color: #f44336;">ネットワークエラーが発生しました。</p>');
                        }
                    });
                });

                $('#luminous-install-update').on('click', function() {
                    if (!confirm('テーマを最新バージョンに更新しますか？\n(現在のファイルが上書きされます)')) return;

                    var btn = $(this);
                    btn.prop('disabled', true);
                    $('#luminous-check-update').hide();
                    $('#update-progress-container').show();
                    
                    updateStatus('最新版のダウンロードを開始しています...', 20);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'luminous_install_update',
                            nonce: '<?php echo wp_create_nonce("luminous_update_nonce"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                var installedBuild = (response.data && response.data.installed_build) ? '（ビルド: ' + response.data.installed_build + '）' : '';
                                updateStatus('インストール完了！' + installedBuild + ' ページをリロードします...', 100);
                                setTimeout(function() {
                                    location.reload();
                                }, 2500);
                            } else {
                                updateStatus('エラー: ' + response.data, 0);
                                btn.prop('disabled', false);
                            }
                        },
                        error: function() {
                            updateStatus('インストール中に致命的なエラーが発生しました。', 0);
                            btn.prop('disabled', false);
                        }
                    });
                });

                function updateStatus(text, progress) {
                    $('#update-status-text').text(text);
                    $('#update-progress-bar').css('width', progress + '%');
                }
            });
            </script>
        </form>
    </div>
    <?php
}
