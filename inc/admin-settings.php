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
 * register_setting 用の名前付きサニタイズコールバック。
 *
 * settings API の管理方向の関数であり、プレフィックス規約（node_）に従う。
 * 開示文言は入力が input type=text の単一行のため、HTMLを保存しない単一行版を使う。
 */

function node_sanitize_checkbox_flag( $value ): string {
	return '1' === (string) $value ? '1' : '0';
}

function node_sanitize_single_line_text( $value ): string {
	return sanitize_text_field( wp_unslash( (string) $value ) );
}

function node_sanitize_disclosure_text( $value ): string {
	return node_sanitize_single_line_text( $value );
}

function node_sanitize_attachment_id( $value ): int {
	return absint( $value );
}

/**
 * Register Settings
 */
function node_register_settings() {
    // --- Gemini設定 ---
    // Gemini設定はユーザーごとに保存するため、ここでは登録しません
    // register_setting( 'node_settings_group', 'node_gemini_api_key' );
    // register_setting( 'node_settings_group', 'node_gemini_model' );

    // --- ライブラリ設定 (Luna Library) ---
    register_setting( 'node_settings_group', 'node_library_auto_insert', array(
        'sanitize_callback' => 'node_sanitize_checkbox_flag',
    ) );
    register_setting( 'node_settings_group', 'node_library_header_text', array(
        'sanitize_callback' => 'node_sanitize_single_line_text',
    ) );
    register_setting( 'node_settings_group', 'node_library_button_text', array(
        'sanitize_callback' => 'node_sanitize_single_line_text',
    ) );

    // --- 商品リンク設定 (Nexus) ---
    register_setting( 'node_settings_group', 'luminous_nexus_amazon_id', array(
        'sanitize_callback' => 'node_sanitize_single_line_text',
    ) );
    register_setting( 'node_settings_group', 'luminous_nexus_rakuten_id', array(
        'sanitize_callback' => 'node_sanitize_single_line_text',
    ) );
    register_setting( 'node_settings_group', 'luminous_nexus_disclosure_amazon', array(
        'sanitize_callback' => 'node_sanitize_disclosure_text',
    ) );
    register_setting( 'node_settings_group', 'luminous_nexus_disclosure_rakuten', array(
        'sanitize_callback' => 'node_sanitize_disclosure_text',
    ) );
    
    // --- X (Twitter) 連携設定 ---
    // node-connect プラグイン（設定 → 外部連携）へ移設。オプション名は node_x_* のまま互換維持。

    // --- コード表示用フォント ---
    register_setting( 'node_settings_group', 'node_code_font_enabled', array(
        'type' => 'string',
        'default' => '1',
        'sanitize_callback' => static function ( $value ) {
            return '0' === (string) $value ? '0' : '1';
        },
    ) );

    // --- OGP設定 ---
    register_setting( 'node_settings_group', 'node_ogp_enabled', array(
        'sanitize_callback' => 'node_sanitize_checkbox_flag',
    ) );
    register_setting( 'node_settings_group', 'node_ogp_bg_id', array(
        'sanitize_callback' => 'node_sanitize_attachment_id',
    ) );
    register_setting( 'node_settings_group', 'node_ogp_logo_id', array(
        'sanitize_callback' => 'node_sanitize_attachment_id',
    ) );

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
        <h1 style="margin-bottom: 30px;">Luna Frontier テーマ設定</h1>
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

            <!-- コード／コマンド表示設定 -->
            <div class="m3-admin-card" style="background: #fff; padding: 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #e0e0e0; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                <h2 style="margin-top: 0; color: #FF9900; display: flex; align-items: center; gap: 10px;">
                    <span class="dashicons dashicons-editor-code"></span> コード・コマンド表示
                </h2>
                <p class="description">コードブロックやインラインコードの文字だけを変更します。本文フォント、色、背景、余白、コピーボタンは変更しません。</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">コード表示用フォント</th>
                        <td>
                            <input type="hidden" name="node_code_font_enabled" value="0" />
                            <label>
                                <input type="checkbox" name="node_code_font_enabled" value="1" <?php checked( node_code_font_is_enabled() ); ?> />
                                JetBrains Mono（英数字）＋ M PLUS 1 Code（日本語）を使用する
                            </label>
                            <p class="description">既定は有効です。無効にするとシステム等幅フォントへ戻し、専用フォントの読み込みを停止します。</p>
                        </td>
                    </tr>
                </table>
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
                            <input type="hidden" name="node_library_auto_insert" value="0" />
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
                                <input type="hidden" name="node_ogp_enabled" value="0" />
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

            <?php if ( current_user_can( 'update_themes' ) ) : ?>
                <p><a href="<?php echo esc_url( self_admin_url( 'update-core.php' ) ); ?>">WordPress の更新画面でテーマの更新を確認</a></p>
            <?php endif; ?>

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

            });
            </script>
        </form>
    </div>
    <?php
}
