<?php

declare(strict_types=1);
/**
 * Template part for displaying game information.
 * プラットフォームに応じたブランドカラーを適用した Material 3 形式のカード。
 */
$game_info = get_post_meta(get_the_ID(), '_node_game_info', true);

// データが文字列（JSON）で保存されている場合のパース
if (is_string($game_info)) {
    $game_info = json_decode($game_info, true);
}

if (is_array($game_info) && !empty($game_info['title'])) : 
    $title   = $game_info['title'];
    $summary = $game_info['summary'] ?? '';
    $links   = $game_info['links'] ?? [];
?>
    <section class="m3-game-card m3-reveal">
        <div class="m3-game-card__header">
            <span class="material-symbols-outlined" data-icon="videogame_asset"></span>
            <h3>GAME INFO</h3>
        </div>
        
        <div class="m3-game-card__body">
            <h4 class="m3-game-card__title"><?php echo esc_html($title); ?></h4>
            <?php if ($summary) : ?>
                <p class="m3-game-card__summary"><?php echo esc_html($summary); ?></p>
            <?php endif; ?>

            <?php if (!empty($links)) : ?>
                <div class="m3-game-card__actions">
                    <?php foreach ($links as $link) : 
                        $platform = $link['platform'] ?? 'other';
                        $platform_slug = strtolower(str_replace(' ', '', $platform));
                        
                        // アイコンの選定
                        $icon = 'open_in_new';
                        if (stripos($platform, 'switch') !== false || stripos($platform, 'nintendo') !== false) $platform_slug = 'nintendo';
                        if (stripos($platform, 'ps') !== false || stripos($platform, 'playstation') !== false) $platform_slug = 'playstation';
                        if (stripos($platform, 'xbox') !== false) $platform_slug = 'xbox';
                        if (stripos($platform, 'steam') !== false) $platform_slug = 'steam';
                        if (stripos($platform, 'ios') !== false || stripos($platform, 'apple') !== false) $platform_slug = 'ios';
                        if (stripos($platform, 'android') !== false || stripos($platform, 'google') !== false) $platform_slug = 'android';
                        if (stripos($platform, 'windows') !== false || stripos($platform, 'pc') !== false) $platform_slug = 'windows';
                    ?>
                        <a href="<?php echo esc_url($link['url']); ?>" 
                           class="m3-platform-button m3-platform-button--<?php echo esc_attr($platform_slug); ?> m3-ripple-host" 
                           target="_blank" 
                           rel="noopener">
                            <span class="material-symbols-outlined" data-icon="shopping_cart"></span>
                            <?php echo esc_html($platform); ?> で見る
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>