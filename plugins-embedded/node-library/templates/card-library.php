<?php
/**
 * Template part for displaying game/app information (Luna Library).
 * プラットフォームに応じたブランドカラーを適用した Material 3 形式のカード。
 */
if ( ! isset( $game_info ) ) {
    return;
}

$title        = $game_info['title'];
$type         = ( $game_info['type'] ?? 'game' ) === 'app' ? 'app' : 'game';
$header_icon  = 'app' === $type ? 'smartphone' : 'sports_esports';
$summary      = $game_info['summary'] ?? '';
$links        = array_values(
    array_filter(
        is_array( $game_info['links'] ?? null ) ? $game_info['links'] : [],
        static function ( $link ) {
            return is_array( $link ) && ! empty( $link['platform'] ) && ! empty( $link['url'] );
        }
    )
);
$header_text  = get_option( 'node_library_header_text', 'GAME / APP INFO' );
$button_text  = get_option( 'node_library_button_text', 'で見る' );
$library_permalink = isset( $lib_post ) && $lib_post instanceof WP_Post && 'node_library' === $lib_post->post_type
    ? get_permalink( $lib_post )
    : '';
$store_tabs_id = wp_unique_id( 'node-library-store-tabs-' );
$badge_base_url = defined( 'NODE_LIBRARY_BADGE_BASE_URL' )
    ? NODE_LIBRARY_BADGE_BASE_URL
    : get_template_directory_uri() . '/plugins-embedded/node-library/assets/images/';
$badge_base_url = trailingslashit( apply_filters( 'node_library_badge_base_url', $badge_base_url ) );
$platform_slug_from_name = static function ( string $platform ): string {
    if ( stripos( $platform, 'switch' ) !== false || stripos( $platform, 'nintendo' ) !== false ) return 'nintendo';
    if ( stripos( $platform, 'amazon' ) !== false ) return 'amazon';
    if ( stripos( $platform, 'playstation' ) !== false || preg_match( '/(^|\s)ps(\s+store|[345](\s|$))/i', $platform ) ) return 'playstation';
    if ( stripos( $platform, 'xbox' ) !== false ) return 'xbox';
    if ( stripos( $platform, 'steam' ) !== false ) return 'steam';
    if ( stripos( $platform, 'mac' ) !== false ) return 'mac';
    if ( stripos( $platform, 'ios' ) !== false || stripos( $platform, 'apple' ) !== false || stripos( $platform, 'app store' ) !== false ) return 'ios';
    if ( stripos( $platform, 'android' ) !== false || stripos( $platform, 'google' ) !== false ) return 'android';
    if ( stripos( $platform, 'microsoft' ) !== false && stripos( $platform, 'xbox' ) === false ) return 'windows';
    if ( stripos( $platform, 'windows' ) !== false || stripos( $platform, 'pc' ) !== false ) return 'windows';
    if ( stripos( $platform, 'geforce' ) !== false ) return 'geforcenow';

    return strtolower( str_replace( ' ', '', $platform ) );
};
$hardware_slug_from_link = static function ( array $link ) use ( $platform_slug_from_name ): string {
    $hardware = (string) ( $link['hardware'] ?? 'auto' );
    $platform = (string) ( $link['platform'] ?? '' );

    return match ( $hardware ) {
        'nintendo-switch', 'nintendo-switch-2' => 'nintendo',
        'playstation-4', 'playstation-5', 'playstation-crossgen' => 'playstation',
        'xbox-one', 'xbox-series', 'xbox-crossgen' => 'xbox',
        'amazon-fire'                          => 'amazon',
        'iphone-ipad'                          => 'ios',
        'android'                              => 'android',
        'windows-pc'                           => 'windows',
        'mac'                                  => 'mac',
        default                                => $platform_slug_from_name( $platform ),
    };
};
$category_from_link = static function ( array $link ) use ( $hardware_slug_from_link ): string {
    $platform = (string) ( $link['platform'] ?? '' );
    $url      = (string) ( $link['url'] ?? '' );
    $slug     = $hardware_slug_from_link( $link );
    $host     = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    $path     = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );

    if ( 0 === strpos( $host, 'www.' ) ) {
        $host = substr( $host, 4 );
    }

    if (
        in_array( $slug, [ 'mac', 'windows', 'steam', 'geforcenow' ], true ) ||
        false !== strpos( $host, 'steampowered.com' ) ||
        false !== strpos( $host, 'apps.microsoft.com' ) ||
        false !== strpos( $host, 'epicgames.com' ) ||
        false !== strpos( $host, 'nvidia.com' ) ||
        false !== strpos( $path, 'for-pc' )
    ) {
        return 'pc';
    }

    if (
        in_array( $slug, [ 'ios', 'android', 'amazon' ], true ) ||
        false !== strpos( $host, 'apps.apple.com' ) ||
        false !== strpos( $host, 'play.google.com' ) ||
        false !== strpos( $host, 'amazon.' )
    ) {
        return 'mobile';
    }

    if (
        in_array( $slug, [ 'nintendo', 'playstation', 'xbox' ], true ) ||
        false !== strpos( $host, 'nintendo.com' ) ||
        false !== strpos( $host, 'playstation.com' ) ||
        false !== strpos( $host, 'xbox.com' )
    ) {
        return 'console';
    }

    return 'auto';
};
$steam_app_id_from_url = static function ( string $url ): string {
    $path = (string) wp_parse_url( $url, PHP_URL_PATH );
    if ( preg_match( '#/app/([0-9]+)(?:/|$)#', $path, $matches ) ) {
        return $matches[1];
    }

    return '';
};
$nintendo_store_device_from_link = static function ( array $link, string $platform_slug ): string {
    $url      = (string) ( $link['url'] ?? '' );
    $hardware = (string) ( $link['hardware'] ?? 'auto' );
    $platform = strtolower( (string) ( $link['platform'] ?? '' ) );
    $host     = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    $path     = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );

    if ( 'nintendo' !== $platform_slug && false === strpos( $host, 'nintendo.com' ) ) {
        return '';
    }

    if ( 'nintendo-switch-2' === $hardware ) {
        return 'switch2';
    }

    if ( 'nintendo-switch' === $hardware ) {
        return 'switch';
    }

    if (
        false !== strpos( $platform, 'switch 2' ) ||
        false !== strpos( $platform, 'switch2' ) ||
        false !== strpos( $path, 'switch2' ) ||
        false !== strpos( $path, 'switch-2' ) ||
        false !== strpos( $path, 'd70010000096732' )
    ) {
        return 'switch2';
    }

    if (
        false !== strpos( $platform, 'switch' ) ||
        false !== strpos( $path, 'd70010000010193' )
    ) {
        return 'switch';
    }

    return 'unknown';
};
$nintendo_store_warning_message = static function ( string $device ): string {
    return match ( $device ) {
        'switch2' => 'このソフトはSwitch 2専用ソフトです。',
        'switch'  => 'このソフトはSwitch専用ソフトです。',
        'unknown' => '対応機種を確認してから入手してください。',
        default   => '',
    };
};
$platform_warning_device_from_link = static function ( array $link, string $platform_slug ) use ( $nintendo_store_device_from_link ): string {
    if ( ! empty( $link['node_library_skip_platform_warning'] ) ) {
        return '';
    }

    $hardware = (string) ( $link['hardware'] ?? 'auto' );
    $platform = strtolower( (string) ( $link['platform'] ?? '' ) );
    $has_ps4 = false !== strpos( $platform, 'playstation 4' ) || false !== strpos( $platform, 'ps4' );
    $has_ps5 = false !== strpos( $platform, 'playstation 5' ) || false !== strpos( $platform, 'ps5' );
    $has_xbox_one = false !== strpos( $platform, 'xbox one' );
    $has_xbox_series = false !== strpos( $platform, 'series' ) || false !== strpos( $platform, 'x|s' ) || false !== strpos( $platform, 's・x' ) || false !== strpos( $platform, 'xs' );

    if ( 'nintendo' === $platform_slug ) {
        return $nintendo_store_device_from_link( $link, $platform_slug );
    }

    if ( 'playstation' === $platform_slug ) {
        if ( 'playstation-crossgen' === $hardware || ( $has_ps4 && $has_ps5 ) ) return '';
        if ( 'playstation-5' === $hardware || $has_ps5 ) return 'ps5';
        if ( 'playstation-4' === $hardware || $has_ps4 ) return 'ps4';
    }

    if ( 'xbox' === $platform_slug ) {
        if ( 'xbox-crossgen' === $hardware || ( $has_xbox_one && $has_xbox_series ) ) return '';
        if ( 'xbox-series' === $hardware || $has_xbox_series ) return 'xbox-series';
        if ( 'xbox-one' === $hardware || $has_xbox_one ) return 'xbox-one';
    }

    return '';
};
$platform_warning_message = static function ( string $device ): string {
    return match ( $device ) {
        'ps4'         => 'このタイトルはPS4専用です。',
        'ps5'         => 'このタイトルはPS5専用です。',
        'xbox-one'    => 'このタイトルはXbox One専用です。',
        'xbox-series' => 'このタイトルはXbox Series X|S専用です。',
        default       => '',
    };
};
$platform_hardware_note_from_link = static function ( array $link, string $platform_slug ): string {
    $hardware = (string) ( $link['hardware'] ?? 'auto' );
    $platform = strtolower( (string) ( $link['platform'] ?? '' ) );
    $has_ps4 = false !== strpos( $platform, 'playstation 4' ) || false !== strpos( $platform, 'ps4' );
    $has_ps5 = false !== strpos( $platform, 'playstation 5' ) || false !== strpos( $platform, 'ps5' );
    $has_xbox_one = false !== strpos( $platform, 'xbox one' );
    $has_xbox_series = false !== strpos( $platform, 'series' ) || false !== strpos( $platform, 'x|s' ) || false !== strpos( $platform, 'xs' );

    if ( 'playstation' === $platform_slug ) {
        if ( 'playstation-crossgen' === $hardware || ( $has_ps4 && $has_ps5 ) ) return 'PS4 / PS5';
        if ( 'playstation-5' === $hardware || $has_ps5 ) return 'PS5';
        if ( 'playstation-4' === $hardware || $has_ps4 ) return 'PS4';
    }

    if ( 'xbox' === $platform_slug ) {
        if ( 'xbox-crossgen' === $hardware || ( $has_xbox_one && $has_xbox_series ) ) return 'Xbox One / Xbox Series X|S';
        if ( 'xbox-series' === $hardware || $has_xbox_series ) return 'Xbox Series X|S';
        if ( 'xbox-one' === $hardware || $has_xbox_one ) return 'Xbox One';
    }

    return '';
};
$store_platform_variant_from_link = static function ( array $link, string $platform_slug ) use ( $nintendo_store_device_from_link ): string {
    $hardware = (string) ( $link['hardware'] ?? 'auto' );
    $platform = strtolower( (string) ( $link['platform'] ?? '' ) );
    $has_ps4 = false !== strpos( $platform, 'playstation 4' ) || false !== strpos( $platform, 'ps4' );
    $has_ps5 = false !== strpos( $platform, 'playstation 5' ) || false !== strpos( $platform, 'ps5' );
    $has_xbox_one = false !== strpos( $platform, 'xbox one' );
    $has_xbox_series = false !== strpos( $platform, 'series' ) || false !== strpos( $platform, 'x|s' ) || false !== strpos( $platform, 's・x' ) || false !== strpos( $platform, 'xs' );

    if ( 'nintendo' === $platform_slug ) {
        return $nintendo_store_device_from_link( $link, $platform_slug );
    }

    if ( 'playstation' === $platform_slug ) {
        if ( 'playstation-crossgen' === $hardware || ( $has_ps4 && $has_ps5 ) ) return 'crossgen';
        if ( 'playstation-5' === $hardware ) return 'ps5';
        if ( 'playstation-4' === $hardware ) return 'ps4';
        if ( false !== strpos( $platform, 'playstation 5' ) || false !== strpos( $platform, 'ps5' ) ) return 'ps5';
        if ( false !== strpos( $platform, 'playstation 4' ) || false !== strpos( $platform, 'ps4' ) ) return 'ps4';
        return 'playstation';
    }

    if ( 'xbox' === $platform_slug ) {
        if ( 'xbox-crossgen' === $hardware || ( $has_xbox_one && $has_xbox_series ) ) return 'crossgen';
        if ( 'xbox-series' === $hardware ) return 'series';
        if ( 'xbox-one' === $hardware ) return 'one';
        if ( false !== strpos( $platform, 'series' ) || false !== strpos( $platform, 'x|s' ) || false !== strpos( $platform, 'xs' ) ) return 'series';
        if ( false !== strpos( $platform, 'xbox one' ) ) return 'one';
        return 'xbox';
    }

    return '';
};
$steam_links = [];
$store_links = [];
foreach ( $links as $link ) {
    $platform = (string) ( $link['platform'] ?? '' );
    $app_id   = 'steam' === $platform_slug_from_name( $platform )
        ? $steam_app_id_from_url( (string) $link['url'] )
        : '';

    if ( '' !== $app_id ) {
        $steam_links[] = [
            'platform' => $platform,
            'url'      => (string) $link['url'],
            'app_id'   => $app_id,
        ];
    }

    $store_links[] = $link;
}
$category_for_store_link = static function ( array $link ) use ( $category_from_link ): string {
    $platform = (string) ( $link['platform'] ?? '' );
    $category = $category_from_link( $link );
    if ( 'auto' === $category ) {
        $category = function_exists( 'node_library_normalize_category' )
            ? node_library_normalize_category( $link['category'] ?? '' )
            : 'auto';
    }

    if ( 'auto' === $category && function_exists( 'node_library_auto_category' ) ) {
        $category = node_library_auto_category( $platform );
    }

    return 'auto' === $category ? 'pc' : $category;
};
$dual_support_groups = [];
foreach ( $store_links as $link ) {
    $platform_slug = $hardware_slug_from_link( $link );
    if ( ! in_array( $platform_slug, [ 'playstation', 'xbox' ], true ) ) {
        continue;
    }

    $category = $category_for_store_link( $link );
    $variant  = $store_platform_variant_from_link( $link, $platform_slug );
    $group_key = $category . ':' . $platform_slug;

    if ( '' !== $variant ) {
        $dual_support_groups[ $group_key ][ $variant ] = true;
    }
}

$deduped_store_links = [];
foreach ( $store_links as $link ) {
    $category = $category_for_store_link( $link );
    $platform_slug = $hardware_slug_from_link( $link );
    $variant = $store_platform_variant_from_link( $link, $platform_slug );
    $group_key = $category . ':' . $platform_slug;

    if (
        'playstation' === $platform_slug &&
        ! empty( $dual_support_groups[ $group_key ]['ps4'] ) &&
        ! empty( $dual_support_groups[ $group_key ]['ps5'] )
    ) {
        $link['node_library_skip_platform_warning'] = true;
    }

    if (
        'xbox' === $platform_slug &&
        ! empty( $dual_support_groups[ $group_key ]['one'] ) &&
        ! empty( $dual_support_groups[ $group_key ]['series'] )
    ) {
        $link['node_library_skip_platform_warning'] = true;
    }

    $device_key = '' !== $variant ? ':' . $variant : '';
    $dedupe_key = ( 'auto' === $category ? 'auto' : $category ) . ':' . $platform_slug . $device_key;
    if ( ! isset( $deduped_store_links[ $dedupe_key ] ) ) {
        $deduped_store_links[ $dedupe_key ] = $link;
    }
}
$store_links = array_values( $deduped_store_links );
$render_steam_embed = static function ( array $steam_link ) {
    ?>
    <div class="m3-platform-steam-embed">
        <iframe
            src="<?php echo esc_url( 'https://store.steampowered.com/widget/' . rawurlencode( (string) $steam_link['app_id'] ) . '/' ); ?>"
            title="<?php echo esc_attr( ( $steam_link['platform'] ?: 'Steam' ) . '公式ストア埋め込み' ); ?>"
            loading="lazy"
            frameborder="0"
        ></iframe>
    </div>
    <?php
};

$store_groups = [
    'pc' => [
        'label'       => 'PC',
        'short_label' => 'PC',
        'icon'        => 'computer',
        'links'       => [],
    ],
    'mobile' => [
        'label'       => 'スマートフォン・タブレット',
        'short_label' => 'スマホ・タブレット',
        'icon'        => 'devices',
        'links'       => [],
    ],
    'console' => [
        'label'       => 'コンソール',
        'short_label' => 'コンソール',
        'icon'        => 'sports_esports',
        'links'       => [],
    ],
];

foreach ( $store_links as $link ) {
    $category = $category_for_store_link( $link );

    if ( ! isset( $store_groups[ $category ] ) ) {
        $category = 'pc';
    }
    $store_groups[ $category ]['links'][] = $link;
}

$store_groups = array_filter(
    $store_groups,
    static function ( $group ) {
        return ! empty( $group['links'] );
    }
);

if ( count( $store_groups ) > 1 ) {
    $store_groups['all'] = [
        'label'       => '全てを表示',
        'short_label' => 'すべて',
        'icon'        => 'apps',
        'links'       => $store_links,
    ];
}

$steam_panel_id = ! empty( $steam_links ) ? wp_unique_id( 'node-library-steam-panel-' ) : '';
$render_store_link  = static function ( $link ) use ( $button_text, $badge_base_url, $hardware_slug_from_link, $platform_warning_device_from_link, $platform_hardware_note_from_link, $nintendo_store_warning_message, $platform_warning_message ) {
    $platform = $link['platform'] ?? 'other';
    $platform_slug = $hardware_slug_from_link( $link );
    if ( 'nintendo' === $platform_slug ) $platform = 'Nintendo Store';
    if ( 'xbox' === $platform_slug ) $platform = 'Microsoft Store（Xbox）';
    if ( 'playstation' === $platform_slug ) $platform = 'PS Store';
    if ( 'amazon' === $platform_slug ) $platform = 'Amazon App Store';
    $platform_warning_device = $platform_warning_device_from_link( $link, $platform_slug );
    $platform_warning = 'nintendo' === $platform_slug
        ? $nintendo_store_warning_message( $platform_warning_device )
        : $platform_warning_message( $platform_warning_device );
    $platform_hardware_note = $platform_hardware_note_from_link( $link, $platform_slug );
    $supports_qr = in_array( $platform_slug, [ 'ios', 'android' ], true );
    $qr_panel_id = $supports_qr ? wp_unique_id( 'node-library-qr-' ) : '';
    $qr_title_id = $supports_qr ? $qr_panel_id . '-title' : '';
    $button_label = match ( $platform_slug ) {
        'nintendo'    => 'Nintendo Storeで見る',
        'playstation' => 'PS Storeで見る',
        'mac'         => 'Mac App Storeで見る',
        'amazon'      => 'Amazon App Storeで見る',
        'geforcenow'  => 'GeForce NOWで見る',
        default       => $platform . ' ' . $button_text,
    };
    $badge_file = match ( $platform_slug ) {
        'ios'     => 'app-store-badge-ja.svg',
        'android' => 'google-play-badge-ja.png',
        'mac'     => 'mac-app-store-badge-ja.svg',
        'windows', 'xbox' => 'microsoft-store-badge-ja.svg',
        default   => '',
    };
    $badge_url = $badge_file ? $badge_base_url . $badge_file : '';
    $badge_always_visible = in_array( $platform_slug, [ 'mac', 'windows' ], true );
    if ( $supports_qr ) :
    ?>
        <div class="m3-platform-action m3-platform-action--qr">
            <div class="m3-platform-action__row">
                <a href="<?php echo esc_url( $link['url'] ); ?>"
                   class="m3-platform-button m3-platform-button--<?php echo esc_attr( $platform_slug ); ?> m3-platform-button--desktop m3-ripple-host"
                   target="_blank"
                   rel="noopener">
                    <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
                    <?php echo esc_html( $button_label ); ?>
                </a>
                <a href="<?php echo esc_url( $link['url'] ); ?>"
                   class="m3-platform-store-badge-link m3-platform-store-badge-link--<?php echo esc_attr( $platform_slug ); ?>"
                   target="_blank"
                   rel="noopener"
                   aria-label="<?php echo esc_attr( $button_label ); ?>">
                    <img class="m3-platform-store-badge" src="<?php echo esc_url( $badge_url ); ?>" alt="<?php echo esc_attr( $button_label ); ?>">
                </a>
                <button class="m3-platform-qr-toggle" type="button" aria-label="<?php echo esc_attr( $platform . 'のQRコードを表示' ); ?>" aria-controls="<?php echo esc_attr( $qr_panel_id ); ?>" aria-expanded="false" title="QRコードを表示" data-node-library-qr-toggle>
                    <span class="material-symbols-outlined" aria-hidden="true">qr_code_2</span>
                </button>
            </div>
            <dialog class="m3-platform-qr-dialog" id="<?php echo esc_attr( $qr_panel_id ); ?>" aria-labelledby="<?php echo esc_attr( $qr_title_id ); ?>" data-qr-url="<?php echo esc_url( $link['url'] ); ?>" data-node-library-qr-dialog>
                <button class="m3-platform-qr-close" type="button" aria-label="QRコードを閉じる" title="閉じる" data-node-library-qr-close>
                    <span class="material-symbols-outlined" aria-hidden="true">close</span>
                </button>
                <h5 id="<?php echo esc_attr( $qr_title_id ); ?>"><?php echo esc_html( $platform ); ?></h5>
                <span class="m3-platform-qr-status" data-node-library-qr-status>QRコードを生成中…</span>
                <canvas class="m3-platform-qr-canvas" aria-label="<?php echo esc_attr( $platform . 'ストアページのQRコード' ); ?>" data-node-library-qr-canvas hidden></canvas>
                <div class="m3-platform-qr-link-row">
                    <a class="m3-platform-qr-url" href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $link['url'] ); ?></a>
                    <button class="m3-platform-qr-copy" type="button" data-copy-text="<?php echo esc_url( $link['url'] ); ?>" data-node-library-qr-copy>
                        <span class="material-symbols-outlined" aria-hidden="true">content_copy</span>
                        コピー
                    </button>
                </div>
                <span class="m3-platform-qr-copy-status" aria-live="polite" data-node-library-qr-copy-status></span>
            </dialog>
        </div>
    <?php elseif ( $badge_always_visible ) : ?>
        <a href="<?php echo esc_url( $link['url'] ); ?>"
           class="m3-platform-store-badge-link m3-platform-store-badge-link--always m3-platform-store-badge-link--<?php echo esc_attr( $platform_slug ); ?>"
           target="_blank"
           rel="noopener"
           aria-label="<?php echo esc_attr( $button_label ); ?>">
            <img class="m3-platform-store-badge" src="<?php echo esc_url( $badge_url ); ?>" alt="<?php echo esc_attr( $button_label ); ?>">
        </a>
    <?php else : ?>
        <?php if ( $platform_warning ) : ?>
            <span class="node-library-platform-link node-library-nintendo-link">
                <span class="node-library-platform-warning node-library-nintendo-warning" role="note" hidden><?php echo esc_html( $platform_warning ); ?></span>
        <?php endif; ?>
        <a href="<?php echo esc_url( $link['url'] ); ?>"
           class="m3-platform-button m3-platform-button--<?php echo esc_attr( $platform_slug ); ?><?php echo $platform_hardware_note ? ' m3-platform-button--has-note' : ''; ?> m3-ripple-host"
           target="_blank"
           rel="noopener"<?php echo $platform_warning ? ' data-node-library-platform-device="' . esc_attr( $platform_warning_device ) . '" data-node-library-platform-warning="' . esc_attr( $platform_warning ) . '" data-node-library-nintendo-device="' . esc_attr( $platform_warning_device ) . '" data-node-library-nintendo-warning="' . esc_attr( $platform_warning ) . '"' : ''; ?>>
            <span class="m3-platform-button__main">
                <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
                <span><?php echo esc_html( $button_label ); ?></span>
            </span>
            <?php if ( $platform_hardware_note ) : ?>
                <span class="m3-platform-button__note">(<?php echo esc_html( $platform_hardware_note ); ?>)</span>
            <?php endif; ?>
        </a>
        <?php if ( $platform_warning ) : ?>
            </span>
        <?php endif; ?>
    <?php endif; ?>
    <?php
};
?>
<section class="m3-game-card m3-reveal node-library-card<?php echo ! empty( $steam_links ) ? ' m3-game-card--has-steam-toggle' : ''; ?>">
    <div class="m3-game-card__header">
        <span class="material-symbols-outlined" aria-hidden="true"><?php echo esc_html( $header_icon ); ?></span>
        <h3><?php echo esc_html( $header_text ); ?></h3>
    </div>
    
    <div class="m3-game-card__body">
        <h4 class="m3-game-card__title"><?php echo esc_html($title); ?></h4>
        <?php if ($summary) : ?>
            <p class="m3-game-card__summary"><?php echo esc_html($summary); ?></p>
        <?php endif; ?>

        <?php if ( $library_permalink ) : ?>
            <a class="m3-game-card__details-link" href="<?php echo esc_url( $library_permalink ); ?>">
                <?php esc_html_e( '詳細を見る', 'node-library' ); ?>
                <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
            </a>
        <?php endif; ?>

        <?php if ( ! empty( $store_groups ) ) : ?>
            <div class="m3-game-card__stores" data-node-library-tabs>
                <?php if ( count( $store_groups ) > 1 ) : ?>
                    <div class="m3-game-card__tabs-shell">
                    <div class="m3-game-card__tabs<?php echo count( $store_groups ) > 3 ? ' m3-game-card__tabs--overflow' : ''; ?>" role="tablist" aria-label="ストアの種類">
                        <?php $tab_index = 0; ?>
                        <?php foreach ( $store_groups as $group_key => $group ) : ?>
                            <?php
                            $tab_id   = $store_tabs_id . '-' . $group_key . '-tab';
                            $panel_id = $store_tabs_id . '-' . $group_key . '-panel';
                            ?>
                            <button class="m3-game-card__tab" id="<?php echo esc_attr( $tab_id ); ?>" type="button" role="tab" aria-label="<?php echo esc_attr( $group['label'] ); ?>" aria-selected="<?php echo 0 === $tab_index ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>" tabindex="<?php echo 0 === $tab_index ? '0' : '-1'; ?>" data-node-library-tab="<?php echo esc_attr( $group_key ); ?>">
                                <span class="material-symbols-outlined" aria-hidden="true"><?php echo esc_html( $group['icon'] ); ?></span>
                                <span class="m3-game-card__tab-label m3-game-card__tab-label--full"><?php echo esc_html( $group['label'] ); ?></span>
                                <span class="m3-game-card__tab-label m3-game-card__tab-label--compact"><?php echo esc_html( $group['short_label'] ); ?></span>
                                <?php if ( 'all' === $group_key ) : ?>
                                    <span class="m3-game-card__tab-all-view-label" aria-hidden="true">すべてのプラットフォームを表示中</span>
                                <?php endif; ?>
                            </button>
                            <?php $tab_index++; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php if ( count( $store_groups ) > 3 ) : ?>
                        <button class="m3-game-card__tab-next" type="button" aria-label="次のタブを表示" title="次のタブを表示" data-node-library-tab-next>
                            <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                        </button>
                    <?php endif; ?>
                    <button class="m3-game-card__tab-back" type="button" aria-label="デバイスタイプ別に戻る" title="デバイスタイプ別に戻る" data-node-library-tab-back hidden>
                        <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
                        <span class="m3-game-card__tab-back-label">デバイスタイプ別に戻る</span>
                    </button>
                    </div>
                <?php endif; ?>

                <?php $panel_index = 0; ?>
                <?php foreach ( $store_groups as $group_key => $group ) : ?>
                    <?php
                    $tab_id   = $store_tabs_id . '-' . $group_key . '-tab';
                    $panel_id = $store_tabs_id . '-' . $group_key . '-panel';
                    ?>
                    <div class="m3-game-card__store-panel" id="<?php echo esc_attr( $panel_id ); ?>" <?php if ( count( $store_groups ) > 1 ) : ?>role="tabpanel" aria-labelledby="<?php echo esc_attr( $tab_id ); ?>" data-node-library-panel="<?php echo esc_attr( $group_key ); ?>"<?php endif; ?> <?php echo 0 === $panel_index ? '' : 'hidden'; ?>>
                        <div class="m3-game-card__actions">
                            <?php foreach ( $group['links'] as $link ) $render_store_link( $link ); ?>
                        </div>
                    </div>
                    <?php $panel_index++; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $steam_links ) ) : ?>
            <div class="node-library-steam-panel" id="<?php echo esc_attr( $steam_panel_id ); ?>" data-node-library-steam-panel hidden>
                <?php foreach ( $steam_links as $steam_link ) $render_steam_embed( $steam_link ); ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $steam_links ) ) : ?>
        <details class="node-library-steam-control" data-node-library-steam-control>
            <summary class="node-library-steam-control__dot" aria-label="Steam埋め込み表示設定" title="Steam埋め込み表示設定">
                <span aria-hidden="true"></span>
            </summary>
            <label class="node-library-steam-control__switch">
                <input type="checkbox" data-node-library-steam-toggle aria-controls="<?php echo esc_attr( $steam_panel_id ); ?>" aria-expanded="false">
                <span class="node-library-steam-control__toggle" aria-hidden="true"></span>
                <span>Steam埋め込みを表示する</span>
            </label>
        </details>
    <?php endif; ?>
</section>
