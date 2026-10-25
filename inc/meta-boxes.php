<?php

declare(strict_types=1);
/**
 * メタボックスの管理（テーマ側）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'node_add_custom_meta_boxes' ) ) {
    /**
     * 各種メタボックスの追加
     */
    function node_add_custom_meta_boxes() {
        add_meta_box('node_post_labels', '記事設定 (ラベル)', 'node_post_labels_callback', 'post', 'side');
        add_meta_box('node_primary_category', 'プライマリカテゴリ', 'node_primary_category_meta_box_callback', 'post', 'side');
        add_meta_box('node_m3_color', 'Material You カラー設定', 'node_m3_color_meta_box_callback', 'post', 'side');
    }
}
add_action('add_meta_boxes', 'node_add_custom_meta_boxes');

if ( ! function_exists( 'node_meta_boxes_admin_assets' ) ) {
    /**
     * 投稿編集画面に「投稿個別カラー」用のカラーピッカーを読み込む。
     * （カテゴリ編集画面用は inc/category-meta.php が担当）
     */
    function node_meta_boxes_admin_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'post' !== $screen->post_type ) {
            return;
        }

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        $js = <<<'JS'
(function ($) {
    $(function () {
        var $picker = $('#node_m3_color .node-color-picker');
        $picker.each(function () {
            $(this).wpColorPicker();
        });

        // プライマリカテゴリのセレクト変更に連動して、継承説明文とピッカー既定色を即時更新
        var $select = $('#node_primary_category_select');
        var dataEl = document.getElementById('node-color-inherit-data');
        var descEl = document.getElementById('node-color-inherit-desc');
        if (!$select.length || !dataEl || !descEl) return;

        var data;
        try {
            data = JSON.parse(dataEl.textContent || '{}');
        } catch (e) {
            return;
        }

        $select.on('change', function () {
            var selectedId = parseInt($select.val(), 10) || 0;
            var label = selectedId ? 'プライマリカテゴリ' : '先頭カテゴリ';
            var id = selectedId || data.fallbackId || 0;
            var entry = id && data.map ? data.map[id] : null;

            var text;
            if (entry && entry.color) {
                text = 'この記事のプライマリカラーは' + label + '「' + entry.name + '」から継承中です。別の色にしたい場合のみ、上で任意の色を指定してください。';
            } else if (entry) {
                text = '継承元の' + label + '「' + entry.name + '」に色が設定されていないため、アイキャッチ画像の抽出色 → 既定色の順で自動決定されます。';
            } else {
                text = 'カテゴリ未設定のため、アイキャッチ画像の抽出色 → 既定色の順で自動決定されます。固定したい場合のみ、上で任意の色を指定してください。';
            }
            descEl.textContent = text;

            var color = (entry && entry.color) ? entry.color : '#FF9900';
            try {
                $picker.wpColorPicker('option', 'defaultColor', color);
            } catch (e) { /* 初期化前などは無視 */ }
        });
    });
}(jQuery));
JS;
        wp_add_inline_script( 'wp-color-picker', $js );
    }
}
add_action( 'admin_enqueue_scripts', 'node_meta_boxes_admin_assets' );

/**
 * 記事メタデータをREST APIに公開
 */
function node_register_post_meta() {
    register_post_meta('post', '_node_primary_category', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'integer',
        'auth_callback' => function() {
            return current_user_can('edit_posts');
        }
    ]);
    register_meta('post', '_node_is_ai_generated', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'boolean',
        'auth_callback' => function() {
            return current_user_can('edit_posts');
        }
    ]);
    register_meta('post', '_node_is_ai_text_generated', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'boolean',
        'auth_callback' => function() {
            return current_user_can('edit_posts');
        }
    ]);
}
add_action('init', 'node_register_post_meta');


// --- コールバック関数 ---

if ( ! function_exists( 'node_primary_category_meta_box_callback' ) ) {
    function node_primary_category_meta_box_callback($post) {
        wp_nonce_field('node_save_primary_category', 'node_primary_category_nonce');

        $categories   = get_the_category($post->ID);
        $selected     = absint(get_post_meta($post->ID, '_node_primary_category', true));
        $category_ids = array_map(
            static function ( $category ) {
                return (int) $category->term_id;
            },
            is_array($categories) ? $categories : array()
        );

        if ( ! in_array($selected, $category_ids, true) ) {
            $selected = 0;
        }

        echo '<p><label for="node_primary_category_select">主カテゴリ</label></p>';
        echo '<select id="node_primary_category_select" name="node_primary_category" style="width:100%;">';
        echo '<option value=""' . selected($selected, 0, false) . '>指定なし（先頭カテゴリを使用）</option>';

        foreach ( $categories as $category ) {
            echo '<option value="' . esc_attr($category->term_id) . '"' . selected($selected, (int) $category->term_id, false) . '>';
            echo esc_html($category->name);
            echo '</option>';
        }

        echo '</select>';
        echo '<p class="description">パンくずとカテゴリラベルで優先表示するカテゴリを選択します。選択肢はこの記事に割り当て済みのカテゴリのみです。</p>';
        echo '<p class="description" style="color:#666;">※ 編集中のカテゴリ選択に合わせて候補が連動します。</p>';
    }
}

if ( ! function_exists( 'node_post_labels_callback' ) ) {
    function node_post_labels_callback($post) {
        $is_ai = get_post_meta($post->ID, '_node_is_ai_generated', true);
        $is_ai_text = get_post_meta($post->ID, '_node_is_ai_text_generated', true);
        $is_sponsor = get_post_meta($post->ID, '_node_is_sponsor', true);
        $sponsor_text = get_post_meta($post->ID, '_node_sponsor_text', true) ?: 'SPONSORED';
        $sponsor_tooltip = get_post_meta($post->ID, '_node_sponsor_tooltip', true) ?: '本記事はスポンサー提供です。';
        
        wp_nonce_field('node_save_meta_box', 'node_meta_box_nonce');
        
        echo '<h4>ラベル設定</h4>';
        echo '<p><label><input type="checkbox" name="node_is_ai_generated" value="1" '.checked($is_ai, '1', false).'> 生成されたメディアを含む</label></p>';
        echo '<p><label><input type="checkbox" name="node_is_ai_text_generated" value="1" '.checked($is_ai_text, '1', false).'> 生成された文章を含む</label></p>';
        echo '<p><label><input type="checkbox" name="node_is_sponsor" value="1" '.checked($is_sponsor, '1', false).'> スポンサー記事（案件 ）</label></p>';
        echo '<p><label>スポンサーラベル文言:<br><input type="text" name="node_sponsor_text" value="'.esc_attr($sponsor_text).'" style="width:100%"></label></p>';
        echo '<p><label>スポンサー説明文 (ホバー時):<br><input type="text" name="node_sponsor_tooltip" value="'.esc_attr($sponsor_tooltip).'" style="width:100%"></label></p>';
    }
}

if ( ! function_exists( 'node_m3_color_meta_box_callback' ) ) {
    function node_m3_color_meta_box_callback($post) {
        $color = get_post_meta($post->ID, '_m3_primary_color', true);

        // プライマリカテゴリ（未指定時は表示用先頭カテゴリ）の色を既定値として提示する
        $primary_category = function_exists('node_get_primary_category') ? node_get_primary_category($post->ID) : null;
        $inherited_color  = $primary_category ? node_get_category_color($primary_category) : '';
        $default_color    = $inherited_color ?: '#FF9900';

        // 明示的にプライマリカテゴリ指定されているか（未指定なら先頭カテゴリのフォールバック）
        $primary_meta_id  = absint(get_post_meta($post->ID, '_node_primary_category', true));
        $is_explicit      = $primary_category && $primary_meta_id && (int) $primary_category->term_id === $primary_meta_id;
        $source_label     = $is_explicit ? 'プライマリカテゴリ' : '先頭カテゴリ';

        echo '<p><label>この記事だけのプライマリカラー:<br>';
        echo '<input type="text" name="m3_primary_color" value="' . esc_attr($color) . '" class="node-color-picker" data-default-color="' . esc_attr($default_color) . '"></label></p>';

        if ($primary_category && $inherited_color) {
            $desc = 'この記事のプライマリカラーは' . $source_label . '「' . $primary_category->name . '」から継承中です。別の色にしたい場合のみ、上で任意の色を指定してください。';
        } elseif ($primary_category) {
            $desc = '継承元の' . $source_label . '「' . $primary_category->name . '」に色が設定されていないため、アイキャッチ画像の抽出色 → 既定色の順で自動決定されます。';
        } else {
            $desc = 'カテゴリ未設定のため、アイキャッチ画像の抽出色 → 既定色の順で自動決定されます。固定したい場合のみ、上で任意の色を指定してください。';
        }
        echo '<p class="description" id="node-color-inherit-desc">' . esc_html($desc) . '</p>';

        // プライマリカテゴリのセレクト変更に連動して説明文を即時更新するためのデータ
        $assigned_categories = get_the_category($post->ID);
        $inherit_map = array();
        foreach ((array) $assigned_categories as $cat) {
            if (!$cat || is_wp_error($cat)) continue;
            $inherit_map[(int) $cat->term_id] = array(
                'name'  => $cat->name,
                'color' => node_get_category_color($cat),
            );
        }
        $natural_categories = function_exists('node_deduplicate_post_categories')
            ? node_deduplicate_post_categories($assigned_categories)
            : array();
        echo '<script type="application/json" id="node-color-inherit-data">' . wp_json_encode(array(
            'map'        => $inherit_map,
            'fallbackId' => !empty($natural_categories) ? (int) $natural_categories[0]->term_id : 0,
        )) . '</script>';
    }
}

if ( ! function_exists( 'node_save_custom_meta' ) ) {
    /**
     * 保存処理（テーマ側）
     * 責務: 記事ラベル、スポンサー情報、個別カラー設定、読了ゲージ設定
     */
    function node_save_custom_meta($post_id) {
        if (!isset($_POST['node_meta_box_nonce']) || !wp_verify_nonce($_POST['node_meta_box_nonce'], 'node_save_meta_box')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // --- AI生成メディアの自動判別 ---
        $has_ai_media = false;
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id && get_post_meta($thumbnail_id, '_node_is_ai_media', true) === '1') {
            $has_ai_media = true;
        }
        if (!$has_ai_media) {
            $post_content = get_post_field('post_content', $post_id);
            
            // 1. 画像アタッチメントのメタデータから判別
            preg_match_all('/wp-image-([0-9]+)/', $post_content, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $att_id) {
                    if (get_post_meta($att_id, '_node_is_ai_media', true) === '1') {
                        $has_ai_media = true;
                        break;
                    }
                }
            }

            // 2. キャプションに「生成」が含まれるかチェック
            if (!$has_ai_media && strpos($post_content, '生成') !== false) {
                // Gutenbergのブロックデリミタやfigcaption内を検索
                if (preg_match('/<!--\s+wp:image\s+{[^}]*"caption":"[^"]*生成[^"]*"[^}]*}\s+-->/i', $post_content) || 
                    preg_match('/<figcaption[^>]*>[^<]*生成[^<]*<\/figcaption>/i', $post_content)) {
                    $has_ai_media = true;
                }
            }
        }
        if ($has_ai_media) {
            $_POST['node_is_ai_generated'] = '1';
        }

        // 保存対象フィールド
        $text_fields = [
            '_node_is_ai_generated'      => 'node_is_ai_generated',
            '_node_is_ai_text_generated' => 'node_is_ai_text_generated',
            '_node_is_sponsor'           => 'node_is_sponsor',
            '_node_sponsor_text'         => 'node_sponsor_text',
            '_node_sponsor_tooltip'      => 'node_sponsor_tooltip',
            '_m3_primary_color'          => 'm3_primary_color',
        ];

        foreach ($text_fields as $key => $post_key) {
            if (isset($_POST[$post_key])) {
                update_post_meta($post_id, $key, sanitize_text_field($_POST[$post_key]));
            } else {
                delete_post_meta($post_id, $key);
            }
        }
    }
}
add_action('save_post', 'node_save_custom_meta');

if ( ! function_exists( 'node_save_primary_category_meta' ) ) {
    function node_save_primary_category_meta($post_id) {
        if (!isset($_POST['node_primary_category_nonce']) || !wp_verify_nonce($_POST['node_primary_category_nonce'], 'node_save_primary_category')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if ('post' !== get_post_type($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $selected = isset($_POST['node_primary_category']) ? absint($_POST['node_primary_category']) : 0;

        if (!$selected) {
            delete_post_meta($post_id, '_node_primary_category');
            return;
        }

        $category_ids = wp_get_post_categories($post_id, array('fields' => 'ids'));
        $category_ids = array_map('intval', is_array($category_ids) ? $category_ids : array());

        if (in_array($selected, $category_ids, true)) {
            update_post_meta($post_id, '_node_primary_category', (string) $selected);
        } else {
            delete_post_meta($post_id, '_node_primary_category');
        }
    }
}
add_action('save_post', 'node_save_primary_category_meta');

if ( ! function_exists( 'node_cleanup_primary_category_after_terms_change' ) ) {
    function node_cleanup_primary_category_after_terms_change($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        if ('category' !== $taxonomy || 'post' !== get_post_type($object_id)) {
            return;
        }

        $selected = absint(get_post_meta($object_id, '_node_primary_category', true));
        if (!$selected) {
            return;
        }

        $category_ids = wp_get_post_categories($object_id, array('fields' => 'ids'));
        $category_ids = array_map('intval', is_array($category_ids) ? $category_ids : array());

        if (!in_array($selected, $category_ids, true)) {
            delete_post_meta($object_id, '_node_primary_category');
        }
    }
}
add_action('set_object_terms', 'node_cleanup_primary_category_after_terms_change', 10, 6);

if ( ! function_exists( 'node_validate_primary_category_on_rest_save' ) ) {
    /**
     * REST API投稿保存時にもプライマリカテゴリが割り当てカテゴリに含まれていることを検証する
     */
    function node_validate_primary_category_on_rest_save( $post, $request, $creating ) {
        if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
            return;
        }

        $post_id  = $post->ID;
        $selected = absint( get_post_meta( $post_id, '_node_primary_category', true ) );
        if ( ! $selected ) {
            return;
        }

        $category_ids = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
        $category_ids = array_map( 'intval', is_array( $category_ids ) ? $category_ids : array() );

        if ( ! in_array( $selected, $category_ids, true ) ) {
            delete_post_meta( $post_id, '_node_primary_category' );
        }
    }
}
add_action( 'rest_after_insert_post', 'node_validate_primary_category_on_rest_save', 25, 3 );

if ( ! function_exists( 'node_enqueue_primary_category_block_editor_assets' ) ) {
    /**
     * ブロックエディターで編集中のカテゴリ選択とプライマリカテゴリ候補を即時連動させる
     */
    function node_enqueue_primary_category_block_editor_assets() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'post' !== $screen->post_type ) {
            return;
        }

        wp_register_script(
            'node-primary-category-editor',
            false,
            array( 'wp-data', 'wp-core-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-dom-ready' ),
            defined( 'NODE_THEME_VERSION' ) ? NODE_THEME_VERSION : '1.0',
            true
        );
        wp_enqueue_script( 'node-primary-category-editor' );

        $js = <<<'JS'
(function (wp) {
    if (!wp || !wp.data || !wp.domReady) {
        return;
    }

    wp.domReady(function () {
        var selectEl = document.getElementById('node_primary_category_select');
        if (!selectEl) {
            return;
        }

        var storeSelect = wp.data.select;
        var storeDispatch = wp.data.dispatch;
        var storeSubscribe = wp.data.subscribe;

        if (!storeSelect('core/editor') || !storeSelect('core')) {
            return;
        }

        var termNames = {};
        for (var i = 0; i < selectEl.options.length; i++) {
            var opt = selectEl.options[i];
            if (opt.value) {
                termNames[opt.value] = opt.textContent;
            }
        }

        var lastCategoryIdsKey = null;
        var isInitialized = false;

        function updateOptions(categoryIds) {
            var currentSelected = selectEl.value;
            var stringIds = [];

            for (var j = 0; j < categoryIds.length; j++) {
                var idStr = String(categoryIds[j]);
                if (stringIds.indexOf(idStr) === -1) {
                    stringIds.push(idStr);
                }
            }

            var pendingFetch = false;
            var core = storeSelect('core');

            for (var k = 0; k < stringIds.length; k++) {
                var catIdStr = stringIds[k];
                var catIdNum = parseInt(catIdStr, 10);

                if (!termNames[catIdStr]) {
                    var record = (core && core.getEntityRecord) ? core.getEntityRecord('taxonomy', 'category', catIdNum) : null;
                    if (record && record.name) {
                        termNames[catIdStr] = record.name;
                    } else if (core && core.getEntityRecords) {
                        var allRecords = core.getEntityRecords('taxonomy', 'category', { per_page: -1, context: 'view' });
                        if (!allRecords) {
                            allRecords = core.getEntityRecords('taxonomy', 'category', { per_page: -1 });
                        }
                        if (Array.isArray(allRecords)) {
                            for (var r = 0; r < allRecords.length; r++) {
                                if (allRecords[r] && allRecords[r].id === catIdNum && allRecords[r].name) {
                                    termNames[catIdStr] = allRecords[r].name;
                                    break;
                                }
                            }
                        }
                    }

                    if (!termNames[catIdStr]) {
                        pendingFetch = true;
                    }
                }
            }

            // カテゴリ情報の取得中は、既存の指定を消さないよう保留
            if (pendingFetch) {
                return;
            }

            var existingValues = [];
            for (var m = 0; m < selectEl.options.length; m++) {
                var val = selectEl.options[m].value;
                if (val !== '') {
                    existingValues.push(val);
                }
            }

            var isSame = existingValues.length === stringIds.length;
            if (isSame) {
                for (var n = 0; n < stringIds.length; n++) {
                    if (existingValues.indexOf(stringIds[n]) === -1) {
                        isSame = false;
                        break;
                    }
                }
            }

            var nextSelectedValue = '';
            if (currentSelected && stringIds.indexOf(currentSelected) !== -1) {
                nextSelectedValue = currentSelected;
            } else {
                nextSelectedValue = '';
            }

            if (isSame && selectEl.value === nextSelectedValue && isInitialized) {
                return;
            }

            var fragment = document.createDocumentFragment();

            var defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = '指定なし（先頭カテゴリを使用）';
            fragment.appendChild(defaultOption);

            for (var p = 0; p < stringIds.length; p++) {
                var cId = stringIds[p];
                var optEl = document.createElement('option');
                optEl.value = cId;
                optEl.textContent = termNames[cId] || ('カテゴリ ' + cId);
                fragment.appendChild(optEl);
            }

            selectEl.innerHTML = '';
            selectEl.appendChild(fragment);
            selectEl.value = nextSelectedValue;

            if (isInitialized && currentSelected !== nextSelectedValue) {
                selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                if (window.jQuery) {
                    window.jQuery(selectEl).trigger('change');
                }
                if (storeDispatch('core/editor') && storeDispatch('core/editor').editPost) {
                    storeDispatch('core/editor').editPost({
                        meta: {
                            _node_primary_category: nextSelectedValue ? parseInt(nextSelectedValue, 10) : 0
                        }
                    });
                }
            }

            isInitialized = true;
        }

        storeSubscribe(function () {
            var editor = storeSelect('core/editor');
            if (!editor) {
                return;
            }

            var categoryIds = editor.getEditedPostAttribute('categories');
            if (!Array.isArray(categoryIds)) {
                return;
            }

            var sortedKey = categoryIds.slice().sort(function (a, b) {
                return a - b;
            }).join(',');

            if (sortedKey !== lastCategoryIdsKey || !isInitialized) {
                lastCategoryIdsKey = sortedKey;
                updateOptions(categoryIds);
            }
        });

        selectEl.addEventListener('change', function () {
            var val = selectEl.value;
            var numVal = val ? parseInt(val, 10) : 0;
            if (storeDispatch('core/editor') && storeDispatch('core/editor').editPost) {
                var currentMeta = storeSelect('core/editor').getEditedPostAttribute('meta') || {};
                if (currentMeta._node_primary_category !== numVal) {
                    storeDispatch('core/editor').editPost({
                        meta: {
                            _node_primary_category: numVal
                        }
                    });
                }
            }
        });
    });
})(window.wp);
JS;
        wp_add_inline_script( 'node-primary-category-editor', $js );
    }
}
add_action( 'enqueue_block_editor_assets', 'node_enqueue_primary_category_block_editor_assets' );

