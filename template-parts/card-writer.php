<?php

declare(strict_types=1);
/**
 * Template part for displaying the writer information card.
 */
$author_id = get_the_author_meta('ID');
$description = get_the_author_meta('description');
$has_description = '' !== trim(wp_strip_all_tags((string) $description));

// サポート対象サービスのブランドアイコン（インラインSVG・simple-icons 24x24パス）
$brand_icons = [
    'x' => [
        'label' => 'X (Twitter)',
        'bg'    => '#0F1419',
        'fg'    => '#FFFFFF',
        'path'  => 'M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z',
    ],
    'github' => [
        'label' => 'GitHub',
        'bg'    => '#181717',
        'fg'    => '#FFFFFF',
        'path'  => 'M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12',
    ],
    'youtube' => [
        'label' => 'YouTube',
        'bg'    => '#FF0000',
        'fg'    => '#FFFFFF',
        'path'  => 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z',
    ],
    'facebook' => [
        'label' => 'Facebook',
        'bg'    => '#0866FF',
        'fg'    => '#FFFFFF',
        'path'  => 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z',
    ],
    'note' => [
        'label' => 'note',
        'bg'    => '#41C9B4',
        'fg'    => '#FFFFFF',
        'path'  => 'M0 .279c4.623 0 10.953-.235 15.498-.117 6.099.156 8.39 2.813 8.468 9.374.077 3.71 0 14.335 0 14.335h-6.598c0-9.296.04-10.83 0-13.759-.078-2.578-.814-3.807-2.795-4.041-2.097-.235-7.975-.04-7.975-.04v17.84H0Z',
    ],
    'instagram' => [
        'label' => 'Instagram',
        'bg'    => '#E4405F',
        'fg'    => '#FFFFFF',
        'path'  => 'M12 0C8.74 0 8.333.015 7.053.072 5.775.132 4.905.333 4.14.63c-.789.306-1.459.717-2.126 1.384S.935 3.35.63 4.14C.333 4.905.131 5.775.072 7.053.012 8.333 0 8.74 0 12s.015 3.667.072 4.947c.06 1.277.261 2.148.558 2.913.306.788.717 1.459 1.384 2.126.667.666 1.336 1.079 2.126 1.384.766.296 1.636.499 2.913.558C8.333 23.988 8.74 24 12 24s3.667-.015 4.947-.072c1.277-.06 2.148-.262 2.913-.558.788-.306 1.459-.718 2.126-1.384.666-.667 1.079-1.335 1.384-2.126.296-.765.499-1.636.558-2.913.06-1.28.072-1.687.072-4.947s-.015-3.667-.072-4.947c-.06-1.277-.262-2.149-.558-2.913-.306-.789-.718-1.459-1.384-2.126C21.319 1.347 20.651.935 19.86.63c-.765-.297-1.636-.499-2.913-.558C15.667.012 15.26 0 12 0zm0 2.16c3.203 0 3.585.016 4.85.071 1.17.055 1.805.249 2.227.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.057 1.266.07 1.646.07 4.85s-.015 3.585-.074 4.85c-.061 1.17-.256 1.805-.421 2.227-.224.562-.479.96-.899 1.382-.419.419-.824.679-1.38.896-.42.164-1.065.36-2.235.413-1.274.057-1.649.07-4.859.07-3.211 0-3.586-.015-4.859-.074-1.171-.061-1.816-.256-2.236-.421-.569-.224-.96-.479-1.379-.899-.421-.419-.69-.824-.9-1.38-.165-.42-.359-1.065-.42-2.235-.045-1.26-.061-1.649-.061-4.844 0-3.196.016-3.586.061-4.861.061-1.17.255-1.814.42-2.234.21-.57.479-.96.9-1.381.419-.419.81-.689 1.379-.898.42-.166 1.051-.361 2.221-.421 1.275-.045 1.65-.06 4.859-.06zm0 5.678c-3.405 0-6.162 2.76-6.162 6.162 0 3.405 2.757 6.162 6.162 6.162 3.405 0 6.162-2.757 6.162-6.162 0-3.402-2.757-6.162-6.162-6.162zM12 16c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4zm7.846-10.405c0 .795-.646 1.44-1.44 1.44-.795 0-1.44-.645-1.44-1.44 0-.794.646-1.439 1.44-1.439.793 0 1.44.645 1.44 1.439z',
    ],
    'discord' => [
        'label' => 'Discord',
        'bg'    => '#5865F2',
        'fg'    => '#FFFFFF',
        'path'  => 'M20.317 4.3698a19.7913 19.7913 0 0 0-4.8851-1.5152.0741.0741 0 0 0-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 0 0-.0785-.037 19.7363 19.7363 0 0 0-4.8852 1.515.0699.0699 0 0 0-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 0 0 .0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 0 0 .0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 0 0-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 0 1-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 0 1 .0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 0 1 .0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 0 1-.0066.1276 12.2986 12.2986 0 0 1-1.873.8914.0766.0766 0 0 0-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 0 0 .0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 0 0 .0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 0 0-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z',
    ],
];

// 登録欄に依存せず、URLのホストからサービスを判定する。
$detect_brand = static function ( string $url ) : ?string {
    $host = wp_parse_url( $url, PHP_URL_HOST );
    if ( ! is_string( $host ) || '' === $host ) {
        return null;
    }
    $host = strtolower( preg_replace( '/^www\./', '', $host ) );
    $map  = [
        'x.com'         => 'x',
        'twitter.com'   => 'x',
        'github.com'    => 'github',
        'youtube.com'   => 'youtube',
        'youtu.be'      => 'youtube',
        'facebook.com'  => 'facebook',
        'instagram.com' => 'instagram',
        'note.com'      => 'note',
        'note.mu'       => 'note',
        'discord.com'   => 'discord',
        'discord.gg'    => 'discord',
        'discordapp.com' => 'discord',
    ];
    foreach ( $map as $domain => $brand ) {
        // ドメイン境界を確認し、youtube.com.example.org 等を誤認しない。
        if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
            return $brand;
        }
    }
    return null;
};

// SNS・Webサービスリンクをピル表示用に収集
$sns_keys = [
    'twitter'       => 'x',
    'github'        => 'github',
    'youtube'       => 'youtube',
    'facebook'      => 'facebook',
    'instagram'     => 'instagram',
    'discord'       => 'discord',
    'custom_link_1' => null,
    'custom_link_2' => null,
    'custom_link_3' => null,
    'custom_link_4' => null,
    'custom_link_5' => null,
];

$sns_links = [];
foreach ( $sns_keys as $key => $brand ) {
    $val = get_user_meta( $author_id, $key, true );
    if ( ! $val ) {
        continue;
    }
    $brand = $detect_brand( $val );
    if ( $brand && isset( $brand_icons[ $brand ] ) ) {
        $label = $brand_icons[ $brand ]['label'];
    } else {
        $host  = wp_parse_url( $val, PHP_URL_HOST );
        $label = is_string( $host ) && '' !== $host ? preg_replace( '/^www\./', '', $host ) : 'リンク';
    }
    $sns_links[ $key ] = [
        'url'   => $val,
        'brand' => $brand,
        'label' => $label,
    ];
}

// 著者アーカイブ（ライターごとの記事一覧）
$author_post_count = (int) count_user_posts( $author_id, 'post', true );
$author_archive_url = get_author_posts_url( $author_id );
?>

<section id="m3-writer-card" class="m3-writer-card m3-reveal" aria-label="著者情報">
    <div class="m3-writer-card__header">
        <span class="m3-writer-card__label">WRITER INFO</span>
    </div>
    <div class="m3-writer-card__body">
        <div class="m3-writer-card__avatar">
            <?php echo get_avatar($author_id, 160); ?>
        </div>
        <div class="m3-writer-card__info">
            <div class="m3-writer-card__name-row">
                <h3 class="m3-writer-card__name"><?php echo esc_html(get_the_author()); ?></h3>
                <?php if ($author_post_count > 0) : ?>
                    <a href="<?php echo esc_url($author_archive_url); ?>"
                       class="m3-writer-card__archive-link m3-ripple-host"
                       aria-label="<?php echo esc_attr(sprintf('%sの記事一覧（%d件）', get_the_author(), $author_post_count)); ?>">
                        <span class="material-symbols-outlined" aria-hidden="true">article</span>
                        <span class="m3-writer-card__archive-text">記事一覧</span>
                        <span class="m3-writer-card__archive-count"><?php echo esc_html(sprintf('%d件', $author_post_count)); ?></span>
                    </a>
                <?php endif; ?>
            </div>
            <?php if ($has_description) : ?>
            <div class="m3-writer-card__bio">
                <?php echo wp_kses_post(wpautop($description)); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($sns_links)) : ?>
        <div class="m3-writer-card__actions">
                <div class="m3-writer-card__sns">
                    <?php foreach ($sns_links as $sns) :
                        $pill_brand = $sns['brand'] && isset($brand_icons[$sns['brand']]) ? $brand_icons[$sns['brand']] : null;
                        $pill_style = $pill_brand
                            ? sprintf('--writer-pill-bg: %s; --writer-pill-fg: %s;', $pill_brand['bg'], $pill_brand['fg'])
                            : '';
                    ?>
                        <a href="<?php echo esc_url($sns['url']); ?>"
                           class="m3-writer-pill m3-ripple-host"
                           <?php if ($pill_style) : ?>style="<?php echo esc_attr($pill_style); ?>"<?php endif; ?>
                           target="_blank"
                           rel="noopener"
                           title="<?php echo esc_attr($sns['label']); ?>"
                           aria-label="<?php echo esc_attr($sns['label']); ?>">
                            <span class="lf-writer-pill__mark" aria-hidden="true">
                            <?php if ($sns['brand'] && isset($brand_icons[$sns['brand']])) : ?>
                                <svg class="m3-writer-pill__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="<?php echo esc_attr($brand_icons[$sns['brand']]['path']); ?>" />
                                </svg>
                            <?php else : ?>
                                <span class="material-symbols-outlined m3-writer-pill__symbol" aria-hidden="true">link</span>
                            <?php endif; ?>
                            </span>
                            <span class="m3-writer-pill__label"><?php echo esc_html($sns['brand'] === 'x' ? 'X' : $sns['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
        </div>
        <?php endif; ?>
    </div>
</section>