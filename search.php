<?php
declare(strict_types=1);

/**
 * 検索結果ページ
 */
get_header(); ?>

<main id="primary" class="site-main m3-archive-layout m3-search-layout">

    <header class="m3-search-results-header">
        <div class="m3-search-results-header__inner">
            <div class="m3-search-results-header__top">
                <h1 class="m3-search-results-title">
                    <span class="material-symbols-outlined">search</span>
                    <?php 
                    $query = get_search_query();
                    if ($query) {
                        printf(esc_html__('「%s」の検索結果', 'node'), $query);
                    } else {
                        esc_html_e('詳細検索結果', 'node');
                    }
                    ?>
                </h1>
                <p class="m3-search-results-count">
                    <?php
                    global $wp_query;
                    printf(esc_html__('%d 件の記事が見つかりました', 'node'), $wp_query->found_posts);
                    ?>
                </p>
            </div>

            <?php
            // 現在適用されている詳細フィルタを表示
            $filters = [];
            // 1. Keyword
            if (get_search_query()) $filters[] = ['label' => 'キーワード', 'value' => get_search_query(), 'icon' => 'key'];
            // 2. Category
            $cat_id = $_GET['m3_cat'] ?? '';
            if (!empty($cat_id)) {
                $cat = get_category($cat_id);
                if ($cat) $filters[] = ['label' => 'カテゴリ', 'value' => $cat->name, 'icon' => 'category'];
            }
            // 3. Tag
            $tag = $_GET['m3_tag'] ?? '';
            if (!empty($tag)) $filters[] = ['label' => 'タグ', 'value' => $tag, 'icon' => 'sell'];
            // 4. Date Range
            $start_date = $_GET['m3_start_date'] ?? '';
            $end_date = $_GET['m3_end_date'] ?? '';
            if (!empty($start_date) || !empty($end_date)) {
                $start = $start_date ?: '開始日未指定';
                $end = $end_date ?: '終了日未指定';
                $filters[] = ['label' => '期間', 'value' => $start . ' 〜 ' . $end, 'icon' => 'calendar_month'];
            }
            // 5. Word Count
            $min = isset($_GET['m3_min']) ? intval($_GET['m3_min']) : 0;
            $max = isset($_GET['m3_max']) ? intval($_GET['m3_max']) : 10000;
            if ($min > 0 || $max < 10000) {
                $filters[] = ['label' => '文字数', 'value' => $min . ' 〜 ' . ($max >= 10000 ? '10000+' : $max) . '字', 'icon' => 'format_size'];
            }
            // 6. Platform
            $platforms_input = $_GET['m3_platform'] ?? [];
            if (!empty($platforms_input)) {
                $platforms = (array)$platforms_input;
                $filters[] = ['label' => 'プラットフォーム', 'value' => implode(', ', $platforms), 'icon' => 'devices'];
            }
            // 7. Media Types
            $media_input = $_GET['m3_media_type'] ?? [];
            if (!empty($media_input)) {
                $media_map = [
                    'image' => '画像', 'video' => '動画', 'map' => '地図',
                    'youtube' => 'YouTube', 'sns' => 'SNS埋め込み', 'download' => 'ファイル'
                ];
                $media_labels = array_map(function($t) use ($media_map) { return $media_map[$t] ?? $t; }, (array)$media_input);
                $filters[] = ['label' => 'メディア', 'value' => implode(', ', $media_labels), 'icon' => 'perm_media'];
            }

            if (!empty($filters)) : ?>
                <div class="m3-active-filters-wrapper">
                    <span class="m3-active-filters-label">適用中のフィルター:</span>
                    <div class="m3-active-filters">
                        <?php foreach ($filters as $f) : ?>
                            <div class="m3-filter-chip">
                                <span class="material-symbols-outlined"><?php echo esc_attr($f['icon']); ?></span>
                                <div class="m3-filter-chip__content">
                                    <span class="m3-filter-chip__label"><?php echo esc_html($f['label']); ?></span>
                                    <span class="m3-filter-chip__value"><?php echo esc_html($f['value']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <a href="<?php echo esc_url( node_get_all_articles_url() ); ?>" class="m3-filter-chip m3-filter-chip--clear">
                            <span class="material-symbols-outlined">clear_all</span>
                            <span class="m3-filter-chip__value">すべて解除</span>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <div class="m3-search-results-toolbar">
        <div class="m3-search-results-toolbar__inner">
            <div class="m3-search-results-status">
                <span class="material-symbols-outlined">analytics</span>
                <?php printf(esc_html__('全 %d 件の検索結果', 'node'), $wp_query->found_posts); ?>
            </div>
            <div class="m3-search-results-sort">
                <div class="m3-segmented-button">
                    <?php
                    $current_sort = isset($_GET['m3_sort']) ? $_GET['m3_sort'] : 'newest';
                    $sort_options = [
                        'newest' => ['icon' => 'arrow_downward', 'label' => '新しい順'],
                        'oldest' => ['icon' => 'arrow_upward', 'label' => '古い順'],
                        'alpha'  => ['icon' => 'sort_by_alpha', 'label' => '五十音順']
                    ];
                    foreach ($sort_options as $val => $data) :
                        $active = ($current_sort === $val) ? 'is-active' : '';
                        $url = add_query_arg('m3_sort', $val);
                    ?>
                        <?php // 検索結果は noindex, follow なので、並び替えリンクを辿られると
                              // 同じ結果の並び違いが3件ずつクロールされる。rel="nofollow" で
                              // パラメータの増殖を止める（NODE-1.3.md §23）。 ?>
                        <a href="<?php echo esc_url($url); ?>" rel="nofollow" class="m3-segmented-item <?php echo esc_attr($active); ?> m3-tooltip-target" aria-label="<?php echo esc_attr($data['label']); ?>" title="<?php echo esc_attr($data['label']); ?>" <?php if ($active) : ?>aria-current="true"<?php endif; ?> data-tooltip="<?php echo esc_attr($data['label']); ?>" data-tooltip-pos="bottom">
                            <span class="material-symbols-outlined" aria-hidden="true"><?php echo esc_html($data['icon']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="l-card-grid m3-post-grid m3-search-results-grid">
        <?php if (have_posts()) : ?>
            <div class="l-card-grid__items m3-post-grid__container">
                <?php
                while (have_posts()) : the_post();
                    get_template_part('template-parts/article-card', null, ['card_class' => 'card-standard']);
                endwhile;
                ?>
            </div>
        <?php else : ?>
            <div class="m3-no-results">
                <div class="m3-no-results__inner">
                    <div class="m3-no-results__icon">
                        <span class="material-symbols-outlined">search_off</span>
                    </div>
                    <h2 class="m3-no-results__title">見つかりませんでした</h2>
                    <p class="m3-no-results__text">検索条件に一致する記事が見つかりませんでした。<br>別のキーワードやフィルタ条件を試してみてください。</p>
                    <button type="button" class="m3-button m3-button--filled" onclick="document.getElementById('m3-advanced-search-trigger').click()">
                        <span class="material-symbols-outlined">tune</span> 条件を再調整
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="m3-navigation">
        <?php 
        the_posts_pagination([
            'mid_size'  => 2,
            'prev_text' => '<span class="material-symbols-outlined">chevron_left</span>',
            'next_text' => '<span class="material-symbols-outlined">chevron_right</span>',
        ]); 
        ?>
    </div>

</main>

<?php get_footer(); ?>
