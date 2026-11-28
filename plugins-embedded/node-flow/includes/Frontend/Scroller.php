<?php
namespace Node\Flow\Frontend;

class Scroller {
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // JSのエンキュー
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        // WP REST API のエンドポイント登録
        add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );
    }

    public function enqueue_scripts() {
        // 記事一覧が表示されるページ（アーカイブ、検索、2ページ目以降のホーム）のみ読み込む。
        // トップ1ページ目・/library/・luna-archive-flow 対象ページでは不要な出力を抑止（M-7）。
        if ( ! is_home() && ! is_archive() && ! is_search() ) {
            return;
        }
        if ( is_front_page() && ! is_paged() ) {
            return;
        }
        if ( is_post_type_archive( 'node_library' ) || is_tax( 'node_library_category' ) ) {
            return;
        }
        if ( function_exists( 'luna_frontier_uses_archive_flow' ) && luna_frontier_uses_archive_flow() ) {
            return;
        }

        wp_enqueue_script(
            'node-flow-scroller',
            NODE_FLOW_PLUGIN_URL . 'assets/js/scroller.js',
            [],
            NODE_FLOW_VERSION,
            true
        );

        // 公開 GET エンドポイントのため未ログイン時は wp_rest nonce を出力しない（M-7）。
        $settings = [
            'restUrl'     => esc_url_raw( rest_url( 'node-flow/v1/posts' ) ),
            'currentPage' => max( 1, (int) get_query_var( 'paged' ) ),
            'query'       => $this->get_current_query_vars(),
        ];
        if ( is_user_logged_in() ) {
            $settings['nonce'] = wp_create_nonce( 'wp_rest' );
        }
        wp_localize_script( 'node-flow-scroller', 'nodeFlowSettings', $settings );
    }

    /**
     * 現在のクエリ変数をJSに渡せる形に整形
     */
    private function get_current_query_vars() {
        global $wp_query;
        $vars = $wp_query->query_vars;
        // メインクエリの posts_per_page はページネーション境界を一致させるため保持する
        $posts_per_page = (int) $wp_query->get( 'posts_per_page' );
        if ( $posts_per_page > 0 ) {
            $vars['posts_per_page'] = $posts_per_page;
        }
        // 不要なパラメータを整理
        unset( $vars['paged'], $vars['error'], $vars['m'] );
        return $vars;
    }

    public function register_rest_route() {
        register_rest_route( 'node-flow/v1', '/posts', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_posts_html' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'page' => [
                    'default'           => 1,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * クライアントから届く query 配列を、無限スクロールに必要な既知キーだけに絞る。
     *
     * get_current_query_vars() が実際にJSへ渡すのは標準アーカイブ（カテゴリー / タグ /
     * 検索 / 投稿者 / 日付 / シリーズ）の絞り込み変数のみ。ここではその許可キーだけを
     * 通し、meta_query / post_type / post_status など WP_Query への任意注入を遮断する。
     * post_type / post_status は呼び出し側で publish・post に強制するため許可しない。
     *
     * @param array $query_params クライアント由来の query 配列。
     * @return array 許可キーのみを残したクエリ変数。
     */
    private function whitelist_query_vars( array $query_params ) {
        $allowed_keys = [
            // カテゴリーアーカイブ
            'cat',
            'category_name',
            // タグアーカイブ
            'tag',
            'tag_id',
            // 検索
            's',
            // 投稿者アーカイブ
            'author',
            'author_name',
            // 日付アーカイブ
            'year',
            'monthnum',
            'day',
            'w',
            // シリーズ（node_series タクソノミー）
            'node_series',
            // メインクエリと一致させる1ページあたり件数
            'posts_per_page',
        ];

        $safe = [];
        foreach ( $allowed_keys as $key ) {
            if ( isset( $query_params[ $key ] ) && '' !== $query_params[ $key ] && [] !== $query_params[ $key ] ) {
                if ( 'posts_per_page' === $key ) {
                    $per_page = absint( $query_params[ $key ] );
                    if ( $per_page > 0 ) {
                        $safe[ $key ] = min( 100, $per_page );
                    }
                    continue;
                }
                $safe[ $key ] = $query_params[ $key ];
            }
        }

        return $safe;
    }

    /**
     * REST API コールバック: 投稿のHTML片を返す
     */
    public function get_posts_html( \WP_REST_Request $request ) {
        $page = $request->get_param( 'page' );
        $query_params = $request->get_param( 'query' );
        
        if ( ! is_array( $query_params ) ) {
            $query_params = [];
        }

        // セキュリティ: クライアント由来の query は既知キーだけに絞り込む。
        // meta_query / post_type / post_status などの任意注入を防ぐ（F-4）。
        $safe_query = $this->whitelist_query_vars( $query_params );

        $args = array_merge( $safe_query, [
            'paged'               => $page,
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'ignore_sticky_posts' => true,
        ]);

        $query = new \WP_Query( $args );

        if ( ! $query->have_posts() ) {
            return new \WP_REST_Response( [
                'html'    => '',
                'hasMore' => false
            ], 200 );
        }

        ob_start();
        while ( $query->have_posts() ) {
            $query->the_post();
            // テーマの template-parts/card.php などを呼び出してHTMLを生成
            // ここでテーマ（Node）のファイルに依存するが、これはプレゼンテーションへの委譲として許容される
            get_template_part( 'template-parts/card', null, [ 'card_class' => 'card-standard' ] );
        }
        $html = ob_get_clean();

        $has_more = $query->max_num_pages > $page;

        return new \WP_REST_Response( [
            'html'    => $html,
            'hasMore' => $has_more
        ], 200 );
    }
}
