<?php
$game_info = get_post_meta(get_the_ID(), '_node_game_info', true);
if (is_array($game_info) && !empty($game_info['title'])) : ?>
    <section class="m3-game-card">
        <div class="m3-game-card__header">
            <span class="material-symbols-outlined" data-icon="videogame_asset"></span>
            <h3>GAME INFO</h3>
        </div>
        <div class="m3-game-card__body">
            <h4><?php echo esc_html($game_info['title']); ?></h4>
            <p><?php echo esc_html($game_info['summary']); ?></p>
            <?php if (!empty($game_info['links'])) : ?>
                <div class="m3-game-card__actions">
                    <?php foreach ($game_info['links'] as $link) : ?>
                        <a href="<?php echo esc_url($link['url']); ?>" class="m3-button m3-button--filled" target="_blank">
                            <?php echo esc_html($link['platform']); ?>でチェック
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>