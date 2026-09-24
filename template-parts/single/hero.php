<?php

declare(strict_types=1);
/**
 * Template part for displaying the article hero section in single.php
 *
 * v1.2: PC は縦2カラム（左=核心 / 右=補足・訴求）＋横ラインの階層構成。
 *       1000px 以下では従来同様の1カラム（中央寄せ）へ自動で折りたたむ。
 *       右カラム（モバイルではメタ下）にヒーロー統合目次プルダウンを設置。
 */
?>
<div class="m3-article__header-card <?php echo has_post_thumbnail() ? 'has-thumbnail' : 'has-no-thumbnail'; ?>">
    <?php
    global $post;
    $current_post_id = $post->ID ?? get_the_ID();
    $has_thumb = has_post_thumbnail($current_post_id);
    $linked_library_id = absint( get_post_meta( $current_post_id, '_node_linked_library_id', true ) );
    if ( ! $linked_library_id ) {
        $library_card_references = get_post_meta( $current_post_id, '_node_library_card_reference', false );
        $linked_library_id       = absint( $library_card_references[0] ?? 0 );
    }
    $linked_library = $linked_library_id ? get_post( $linked_library_id ) : null;
    if ( ! $linked_library instanceof WP_Post || 'node_library' !== $linked_library->post_type || 'publish' !== $linked_library->post_status ) {
        $linked_library = null;
    }
    ?>

    <?php if ($has_thumb) : ?>
        <div class="m3-article__featured-image">
            <?php
            $thumbnail_id = get_post_thumbnail_id($current_post_id);
            if ($thumbnail_id) :
                $image_alt    = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true) ?: get_the_title();
            ?>
                <?php echo wp_get_attachment_image( $thumbnail_id, 'full', false, array(
                    'alt'           => $image_alt,
                    'class'         => 'm3-article__featured-img',
                    'loading'       => 'eager',
                    'fetchpriority' => 'high',
                    'decoding'      => 'sync',
                ) ); ?>
            <?php endif; ?>
            <div class="m3-article__featured-gradient"></div>
        </div>
    <?php endif; ?>

    <header class="m3-article__header m3-article__header--overlap m3-article__header--split">
        <div class="m3-article__accent-line"></div>

        <div class="m3-article__header-inner">
            <div class="m3-hero-grid">

                <!-- ===== 左カラム: 記事の核心（カテゴリ → タイトル → メタ） ===== -->
                <div class="m3-hero-col m3-hero-col--main">

                    <!-- 上: カテゴリラベル -->
                    <div class="m3-hero-row m3-hero-row--cat">
                        <div class="m3-article__cat-top">
                            <?php node_the_category_labels(); ?>
                        </div>
                    </div>

                    <!-- 中: タイトル（文字数に応じて字幅・サイズを自動調整） -->
                    <?php
                    $hero_title_len   = mb_strlen( wp_strip_all_tags( get_the_title() ) );
                    $hero_title_class = '';
                    if ( $hero_title_len > 42 ) {
                        $hero_title_class = ' is-long';
                    } elseif ( $hero_title_len > 24 ) {
                        $hero_title_class = ' is-medium';
                    }
                    ?>
                    <div class="m3-hero-row m3-hero-row--title">
                        <h1 class="m3-article__title<?php echo esc_attr( $hero_title_class ); ?>" id="m3-hero-title">
                            <?php the_title(); ?>
                        </h1>
                        <!-- PCで3行を超えるタイトルのみJSが表示する全文展開ボタン -->
                        <button type="button"
                                class="m3-hero-title-expand"
                                data-hero-title-expand
                                hidden
                                aria-expanded="false"
                                aria-controls="m3-hero-title"
                                aria-label="タイトルを全文表示">
                            <span class="material-symbols-outlined" aria-hidden="true">more_horiz</span>
                        </button>
                    </div>

                    <!-- 下: メタ情報（日付・追記・コメント） -->
                    <div class="m3-hero-row m3-hero-row--meta">
                        <div class="m3-article__meta-container">
                            <?php
                            $hero_modified         = node_get_post_modified_display( get_the_ID() );
                            $modified_datetime     = $hero_modified['datetime'] ?? '';
                            $modified_display_date = $hero_modified['display'] ?? '';
                            $show_modified_date    = null !== $hero_modified;
                            ?>
                            <div class="m3-article__meta">
                                <a href="<?php echo esc_url( get_day_link( get_the_date( 'Y' ), get_the_date( 'n' ), get_the_date( 'j' ) ) ); ?>"
                                   class="m3-article__meta-item m3-article__date"
                                   aria-label="<?php echo esc_attr( get_the_date( 'Y年n月j日' ) . 'の記事一覧へ' ); ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true">calendar_today</span>
                                    <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
                                        <?php echo esc_html(get_the_date('Y/m/d')); ?>
                                    </time>
                                </a>
                                <?php if ( $show_modified_date ) : ?>
                                <div class="m3-article__meta-item m3-article__modified">
                                    <span class="material-symbols-outlined" aria-hidden="true">update</span>
                                    <time datetime="<?php echo esc_attr( $modified_datetime ); ?>">
                                        <?php echo esc_html( sprintf( '追記 %s', $modified_display_date ) ); ?>
                                    </time>
                                </div>
                                <?php endif; ?>
                                <?php // コメント0件のカウンター表示はノイズになるため、1件以上のときだけ出す ?>
                                <?php if (get_comments_number() > 0) : ?>
                                <a href="#comments" class="m3-article__meta-item m3-article__comments" id="m3-hero-comment-trigger"
                                   aria-label="<?php echo esc_attr( sprintf( 'コメント %d件へ移動', get_comments_number() ) ); ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span>
                                    <span><?php echo esc_html( get_comments_number() ); ?></span>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ( $linked_library ) : ?>
                            <?php $linked_library_type = get_post_meta( $linked_library->ID, '_node_library_type', true ); ?>
                            <a class="m3-article__library-quick-link" href="<?php echo esc_url( get_permalink( $linked_library ) ); ?>">
                                <span class="material-symbols-outlined" aria-hidden="true"><?php echo 'app' === $linked_library_type ? 'smartphone' : 'sports_esports'; ?></span>
                                <span class="m3-article__library-quick-link-label">Node Library</span>
                                <span class="m3-article__library-quick-link-title"><?php echo esc_html( get_the_title( $linked_library ) ); ?></span>
                                <span class="material-symbols-outlined m3-article__library-quick-link-arrow" aria-hidden="true">arrow_forward</span>
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- ===== 右カラム: 補足（読了 → 著者 → 開示バッジ → 目次） =====
                     読者の意思決定に効く順（所要時間が先、開示情報は控えめに後ろ）に積む -->
                <aside class="m3-hero-col m3-hero-col--aside" aria-label="記事の補足情報">

                    <!-- Expressive Reading Badge (0.9.1 Style) -->
                    <?php
                    $post_id = get_the_ID();
                    $reading_info = node_get_article_ranking_info($post_id);
                    $total_seconds = isset($reading_info['reading_seconds'])
                        ? max(30, (int) $reading_info['reading_seconds'])
                        : max(30, (int) round(($reading_info['chars'] / 550) * 60));
                    // 「◯分◯秒」の精密表示は読む前の判断には過剰なため、分単位の目安に丸める
                    $reading_minutes = (int) ceil($total_seconds / 60);
                    $reading_time_display = $total_seconds < 60
                        ? '1分未満で読めます'
                        : sprintf('約%d分で読めます', $reading_minutes);
                    $reading_progress = isset($reading_info['progress'])
                        ? min(100, max(0, (float) $reading_info['progress']))
                        : 0;
                    $reading_angle = round($reading_progress * 3.6, 2);
                    ?>

                    <div class="m3-article__meta-reading">
                        <?php
                        if ($reading_info['chars'] > 200) : // 極端に短い記事は非表示
                        ?>
                        <div class="m3-article__reading-badge-expressive m3-ripple-host"
                             id="m3-hero-reading-badge"
                             style="--reading-color: <?php echo esc_attr($reading_info['color']); ?>; --reading-bg: <?php echo esc_attr($reading_info['container_color']); ?>; --reading-rank-color: <?php echo esc_attr($reading_info['badge_color']); ?>; --reading-rank-bg: <?php echo esc_attr($reading_info['badge_bg']); ?>;">
                            <div class="m3-reading-badge__gauge">
                                <svg viewBox="0 0 36 36"
                                     class="m3-reading-circle__svg"
                                     style="--target-progress: <?php echo esc_attr($reading_progress); ?>; --target-angle: <?php echo esc_attr($reading_angle); ?>deg;"
                                     aria-hidden="true"
                                     focusable="false">
                                    <path class="m3-reading-circle__bg" pathLength="100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                    <path class="m3-reading-circle__progress"
                                          pathLength="100"
                                          d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                    <circle class="m3-reading-circle__head" cx="18" cy="2.0845" r="2.15" />
                                </svg>
                                <span class="material-symbols-outlined" aria-hidden="true">timer</span>
                            </div>
                            <div class="m3-reading-badge-content">
                                <span class="m3-reading-badge-text m3-reading-badge-text--main">
                                    <?php echo esc_html($reading_time_display); ?>
                                </span>
                                <span class="m3-reading-badge-label">
                                    <span class="m3-badge-label-main"><?php echo esc_html(sprintf('約%s文字', number_format_i18n($reading_info['chars']))); ?></span>
                                </span>
                                <!-- 判定結果チップ（M3 Expressive: 判定カラー連動のトーナルチップ＋先頭ドット。
                                     円ゲージ・カラーと同じ「長さの判定」を語で明示する） -->
                                <span class="m3-reading-rank-chip" data-rank="<?php echo esc_attr($reading_info['rank']); ?>">
                                    <span class="m3-reading-rank-chip__dot" aria-hidden="true"></span>
                                    <span class="m3-reading-rank-chip__label"><?php echo esc_html($reading_info['label']); ?></span>
                                </span>
                                <span id="m3-reading-badge-desc" class="m3-reading-badge-text m3-reading-badge-text--desc">
                                    本文の文字数から550字/分で換算した読了目安です。
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- PC補足: 著者情報 + ページ数（複数ページ時のみ）。CSSで PC(>=1001px) のみ表示 -->
                    <?php
                    $hero_author_id   = (int) get_the_author_meta('ID');
                    $hero_raw_content = get_post_field('post_content', get_the_ID());
                    $hero_numpages    = 1 + (int) preg_match_all('/<!--\s*nextpage\s*-->/i', is_string($hero_raw_content) ? $hero_raw_content : '');
                    if ($hero_author_id || $hero_numpages > 1) :
                    ?>
                    <div class="m3-hero-aside-meta">
                        <?php if ($hero_author_id) : ?>
                        <!-- クリックで記事下部のライター情報カード(#m3-writer-card)へスクロール -->
                        <a class="m3-hero-author" href="#m3-writer-card" data-hero-author-jump
                           aria-label="記事下部のライター情報へ移動">
                            <span class="m3-hero-author__avatar">
                                <?php echo get_avatar($hero_author_id, 40, '', get_the_author_meta('display_name')); ?>
                            </span>
                            <span class="m3-hero-author__text">
                                <span class="m3-hero-author__label">この記事を書いた人</span>
                                <span class="m3-hero-author__name">
                                    <?php the_author(); ?>
                                </span>
                            </span>
                        </a>
                        <?php endif; ?>

                        <?php if ($hero_numpages > 1) : ?>
                        <div class="m3-hero-pagecount">
                            <span class="material-symbols-outlined" aria-hidden="true">auto_stories</span>
                            <span><?php echo esc_html(sprintf('全 %d ページ', $hero_numpages)); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- 開示バッジ（AI・スポンサー）: 情報チップとして控えめに（CSSでヒーロー内は静音化） -->
                    <?php
                    $has_ai_media = get_post_meta(get_the_ID(), '_node_is_ai_generated', true) === '1';
                    $has_ai_text  = get_post_meta(get_the_ID(), '_node_is_ai_text_generated', true) === '1';
                    if ($has_ai_media || $has_ai_text) :
                    ?>
                        <div class="m3-article__ai-disclosure-wrapper">
                            <?php node_the_post_badges(get_the_ID(), 'expressive', ['ai']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (get_post_meta(get_the_ID(), '_node_is_sponsor', true) === '1') : ?>
                        <div class="m3-article__sponsor-bubble-wrapper">
                            <?php node_the_post_badges(get_the_ID(), 'full', ['sponsor']); ?>
                        </div>
                    <?php endif; ?>

                    <!-- ヒーロー統合目次プルダウン（ネイティブ <select> ベース） -->
                    <?php
                    $hero_toc_items = function_exists('node_get_article_toc_export_items')
                        ? node_get_article_toc_export_items(get_the_ID())
                        : array();

                    if (!empty($hero_toc_items)) :
                        // ページ単位にグループ化
                        $hero_toc_pages = array();
                        foreach ($hero_toc_items as $hero_toc_item) {
                            $hero_toc_pages[(int) $hero_toc_item['page']][] = $hero_toc_item;
                        }
                        $hero_toc_is_multipage = count($hero_toc_pages) > 1;

                        // 見出しレベルに応じた視覚的インデント（全角スペース）
                        $hero_toc_indent = static function ($level) {
                            $n = (int) preg_replace('/[^0-9]/', '', (string) $level);
                            if ($n < 2) {
                                $n = 2;
                            }
                            $depth = max(0, min(3, $n - 2));
                            return str_repeat('　', $depth);
                        };
                    ?>
                        <div class="m3-hero-toc" data-hero-toc>
                            <label class="m3-hero-toc__label" for="m3-hero-toc-select">
                                <span class="material-symbols-outlined" aria-hidden="true">toc</span>
                                <span>目次から移動</span>
                            </label>
                            <div class="m3-hero-toc__field">
                                <select id="m3-hero-toc-select" class="m3-hero-toc__select" data-hero-toc-select aria-label="目次から見出しへ移動">
                                    <option value="" data-hero-toc-placeholder selected>セクションを選択…</option>
                                    <?php if ($hero_toc_is_multipage) : ?>
                                        <?php foreach ($hero_toc_pages as $hero_toc_page_num => $hero_toc_page_items) : ?>
                                            <optgroup label="<?php echo esc_attr(sprintf('ページ %d', $hero_toc_page_num)); ?>">
                                                <?php foreach ($hero_toc_page_items as $hero_toc_item) : ?>
                                                    <option value="<?php echo esc_attr($hero_toc_item['href']); ?>"
                                                            data-toc-target="<?php echo esc_attr($hero_toc_item['id']); ?>"
                                                            data-toc-page="<?php echo esc_attr($hero_toc_item['page']); ?>"
                                                            <?php echo empty($hero_toc_item['current']) ? '' : 'data-toc-current="1"'; ?>>
                                                        <?php echo esc_html($hero_toc_indent($hero_toc_item['level']) . $hero_toc_item['text']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <?php foreach ($hero_toc_items as $hero_toc_item) : ?>
                                            <option value="<?php echo esc_attr($hero_toc_item['href']); ?>"
                                                    data-toc-target="<?php echo esc_attr($hero_toc_item['id']); ?>"
                                                    data-toc-page="<?php echo esc_attr($hero_toc_item['page']); ?>"
                                                    <?php echo empty($hero_toc_item['current']) ? '' : 'data-toc-current="1"'; ?>>
                                                <?php echo esc_html($hero_toc_indent($hero_toc_item['level']) . $hero_toc_item['text']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <span class="material-symbols-outlined m3-hero-toc__chevron" aria-hidden="true">expand_more</span>
                            </div>
                        </div>
                    <?php endif; ?>

                </aside>

            </div>

        </div>
    </header>
</div>
