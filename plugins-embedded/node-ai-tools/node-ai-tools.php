<?php
/**
 * Plugin Name:  Luna AI Tools
 * Plugin URI:   https://github.com/wingzone94/Node
 * Description:  複数AIプロバイダーによる要約生成・ファクトチェック補助・校正・読了時間自動計算。Node テーマと連携。
 * Version:      1.4.0
 * Author:       Luminous Core Teams
 * Author URI:   https://github.com/wingzone94
 * License:      MIT
 * Text Domain:  node-ai-tools
 * Requires PHP: 8.0
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NODE_AI_VERSION', '1.4.0' );
define( 'NODE_AI_DIR', plugin_dir_path( __FILE__ ) );

$node_ai_embedded_dir = get_template_directory() . '/plugins-embedded/node-ai-tools/';
define(
	'NODE_AI_URL',
	is_dir( $node_ai_embedded_dir )
		? get_template_directory_uri() . '/plugins-embedded/node-ai-tools/'
		: content_url( '/plugins/node-ai-tools/' )
);

/**
 * プラグイン初期化
 */
final class Node_AI_Tools {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->register_hooks();
	}

	private function load_dependencies(): void {
		require_once NODE_AI_DIR . 'includes/guidelines-fetcher.php';
		require_once NODE_AI_DIR . 'includes/class-gemini-api.php';
		require_once NODE_AI_DIR . 'includes/providers/interface-node-ai-provider.php';
		require_once NODE_AI_DIR . 'includes/providers/class-provider-gemini.php';
		require_once NODE_AI_DIR . 'includes/providers/class-provider-qwen.php';
		require_once NODE_AI_DIR . 'includes/providers/class-provider-ollama.php';
		require_once NODE_AI_DIR . 'includes/class-ai-core.php';
		// ファクトチェック（無料枠モデル選択・根拠取得・判定の正規化・実行本体）
		require_once NODE_AI_DIR . 'includes/class-fact-check-models.php';
		require_once NODE_AI_DIR . 'includes/class-fact-check-sources.php';
		require_once NODE_AI_DIR . 'includes/class-fact-check-discovery.php';
		require_once NODE_AI_DIR . 'includes/fact-check-verdict.php';
		require_once NODE_AI_DIR . 'includes/class-fact-check-runner.php';
		require_once NODE_AI_DIR . 'includes/fact-check-render.php';
		require_once NODE_AI_DIR . 'includes/ajax-handlers.php';
		require_once NODE_AI_DIR . 'includes/auto-check.php';
		require_once NODE_AI_DIR . 'includes/alt-text.php';

		require_once NODE_AI_DIR . 'includes/meta-handlers.php';

		if ( is_admin() ) {
			require_once NODE_AI_DIR . 'admin/meta-box-ai-summary.php';
			require_once NODE_AI_DIR . 'admin/meta-box-fact-check.php';
			require_once NODE_AI_DIR . 'admin/meta-box-featured-image-ai.php';
			require_once NODE_AI_DIR . 'admin/settings-page-ai.php';
			require_once NODE_AI_DIR . 'admin/post-list-column.php';
		}
	}

	private function register_hooks(): void {
		// テーマの Hook に応答: AI 要約テキストを提供
		add_filter( 'luminous_get_ai_summary', [ $this, 'provide_ai_summary' ], 10, 2 );

		// テーマの Hook に応答: 読了時間を提供
		add_filter( 'luminous_get_reading_time', [ $this, 'provide_reading_time' ], 10, 2 );

		// 投稿保存時に読了時間を自動計算
		add_action( 'save_post', [ $this, 'auto_calculate_reading_time' ], 20, 3 );

        // 投稿保存時にAI要約（キャッチコピー含む）を自動生成（手動トリガーに変更）
        // add_action( 'save_post', [ $this, 'auto_generate_ai_summary' ], 25, 3 );

        // AJAX ハンドラの登録
        add_action( 'wp_ajax_node_generate_ai_summary', 'node_ai_ajax_generate_summary' );
        add_action( 'wp_ajax_node_ai_fact_check', 'node_ai_ajax_fact_check' );
        add_action( 'wp_ajax_node_ai_proofread', 'node_ai_ajax_proofread' );

        // アイキャッチの alt 自動生成（保存時に予約 → cron 実行）。既存 alt は上書きしない
        add_action( 'wp_after_insert_post', 'node_ai_maybe_schedule_alt_generation', 25, 2 );
        add_action( 'node_ai_auto_alt', 'node_ai_run_auto_alt' );

        // Gemini のモデル一覧を日次で自動更新する（新しい Flash が出たら自動で追従するため）
        add_action( 'init', [ $this, 'schedule_model_refresh' ] );
        add_action( 'node_ai_fc_refresh_models', array( 'Node_AI_Fact_Check_Models', 'refresh_catalog' ) );

        // 検索なしで実行された暫定結果を、枠が回復してから取り直す
        add_action( 'node_ai_fc_recheck_degraded', 'node_ai_fc_run_degraded_recheck' );

        // ファクトチェックの自動実行（下書き保存時に予約 → cron 実行）。
        // 公開はブロックしない（未実施なら公開直前に警告を出す「推奨」運用）
        add_action( 'wp_after_insert_post', 'node_ai_maybe_schedule_fact_check', 20, 2 );
        add_action( 'node_ai_auto_fact_check', 'node_ai_run_auto_fact_check' );

        // 公開処理をブロックしないよう、AI要約の生成本体はcronで実行する。
        add_action( 'node_ai_auto_generate_summary', [ $this, 'run_auto_generate_ai_summary' ] );

        // フロント: 編集者確認済みファクトチェックを記事ヘッダー直後に表示
        add_action( 'luminous_after_article_header', [ $this, 'render_front_fact_check' ], 12 );

        // ブロックエディタ右ペイン（PluginDocumentSettingPanel）から編集する post meta
        add_action( 'init', [ $this, 'register_ai_post_meta' ] );

        // メタボックス・エディタ拡張
        if ( is_admin() ) {
            add_action( 'add_meta_boxes', [ $this, 'add_ai_meta_boxes' ] );
            add_action( 'save_post', 'node_ai_save_meta' );
            add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ] );
        }
	}

    /**
     * モデル一覧の日次更新を予約する（未予約のときだけ）。
     */
    public function schedule_model_refresh(): void {
        if ( false === wp_next_scheduled( 'node_ai_fc_refresh_models' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'node_ai_fc_refresh_models' );
        }

        // 無料枠のリセット後に回るよう、モデル更新とは別の時間帯に置く
        if ( false === wp_next_scheduled( 'node_ai_fc_recheck_degraded' ) ) {
            wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'daily', 'node_ai_fc_recheck_degraded' );
        }
    }

    /**
     * ブロックエディタ右ペインのパネルから編集する post meta を REST に公開する。
     * アンダースコア始まりの meta は auth_callback がないと REST に出ないため明示する。
     */
    public function register_ai_post_meta(): void {
        register_post_meta(
            'post',
            '_node_ai_fact_check_approved',
            array(
                'type'          => 'string',
                'single'        => true,
                'default'       => '',
                'show_in_rest'  => true,
                'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
                    return current_user_can( 'edit_post', $post_id );
                },
            )
        );
    }

    /**
     * ブロックエディタ用アセット
     */
    public function enqueue_block_editor_assets(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'post' !== $screen->post_type ) {
            return;
        }

        // アイコン用（dashicons はスタイルのハンドル。スクリプト依存に入れると依存解決に失敗し
        // スクリプト自体が出力されなくなるため、必ず wp_enqueue_style で読み込む）
        wp_enqueue_style( 'dashicons' );

        // 結果の持ち出し（コピー / .md）はファクトチェックと校正で共通
        wp_enqueue_script(
            'node-ai-export',
            NODE_AI_URL . 'assets/js/ai-export.js',
            array(),
            NODE_AI_VERSION,
            true
        );

        wp_enqueue_script(
            'node-ai-editor-featured-image',
            NODE_AI_URL . 'assets/js/editor-featured-image.js',
            array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data' ),
            NODE_AI_VERSION,
            true
        );

        // 記事チェックUI（右ペイン）。ファクトチェックと校正を1パネルに統合している。
        // クラシックメタボックスは context='side' でもブロックエディタでは
        // 下部の「メタボックス」領域に落ちるため、こちらへ移した
        wp_enqueue_script(
            'node-ai-editor-article-check',
            NODE_AI_URL . 'assets/js/editor-article-check.js',
            array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'node-ai-export' ),
            NODE_AI_VERSION,
            true
        );

        $post    = get_post();
        $post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
        $user_id = get_current_user_id();
        $provider_id = function_exists( 'node_ai_core' ) ? node_ai_core()->get_provider_id() : 'gemini';

        // 保存値は `<モデルID>@<思考量>` 形式（1.2 系と互換）。UI では2つに分けて出す
        $stored_model = function_exists( 'node_get_user_gemini_model' ) ? node_get_user_gemini_model( $user_id ) : '';
        $selection    = function_exists( 'node_split_gemini_model' )
            ? node_split_gemini_model( $stored_model )
            : array( 'model' => $stored_model, 'thinking' => '' );

        wp_localize_script(
            'node-ai-editor-article-check',
            'nodeAiFactCheck',
            array(
                'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( 'node_ai_fact_check_action' ),
                'statusLabels'    => function_exists( 'node_ai_fact_check_status_labels' ) ? node_ai_fact_check_status_labels() : array(),
                'riskLabels'      => function_exists( 'node_ai_fact_check_risk_labels' ) ? node_ai_fact_check_risk_labels() : array(),
                'providerId'      => $provider_id,
                // Pro など無料枠で使えないモデルは出さない（選んでも必ず 429 になるため）
                'models'          => 'gemini' === $provider_id ? Node_AI_Fact_Check_Models::usable_options( $user_id ) : array(),
                'currentModel'    => $selection['model'],
                'thinkingLevels'  => 'gemini' === $provider_id && function_exists( 'node_gemini_thinking_levels' ) ? node_gemini_thinking_levels() : array(),
                'currentThinking' => $selection['thinking'],
                'thinkingModels'  => 'gemini' === $provider_id && function_exists( 'node_get_gemini_thinking_models' ) ? node_get_gemini_thinking_models( $user_id ) : array(),
                'data'            => ( $post_id && function_exists( 'node_ai_get_fact_check_data' ) ) ? node_ai_get_fact_check_data( $post_id ) : null,
                'hasKey'          => ( $post_id && function_exists( 'node_ai_author_has_api_key' ) ) ? node_ai_author_has_api_key( (int) $post->post_author ) : false,
                'autoError'       => $post_id ? (string) get_post_meta( $post_id, '_node_ai_fact_check_error', true ) : '',
            )
        );

        // 校正・誤字脱字チェック（読者要望で 1.3 に追加）は同じパネルに統合済み。
        // 設定値だけ統合パネルへ渡す
        wp_localize_script(
            'node-ai-editor-article-check',
            'nodeAiProofread',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'node_ai_proofread_action' ),
                'data'    => ( $post_id && function_exists( 'node_ai_get_proofread_data' ) ) ? node_ai_get_proofread_data( $post_id ) : null,
            )
        );

    }

    /**
     * 単一記事ページにファクトチェックを表示
     *
     * @param int $post_id 投稿ID。
     */
    public function render_front_fact_check( int $post_id ): void {
        if ( ! is_singular( 'post' ) || ! $post_id ) {
            return;
        }

        if ( max( 1, (int) get_query_var( 'page' ) ) !== 1 ) {
            return;
        }

        node_ai_render_fact_check_front( $post_id );
    }

    /**
     * 投稿タイプでブロックエディタが有効か
     */
    private function uses_block_editor_for_posts(): bool {
        return function_exists( 'use_block_editor_for_post_type' )
            && use_block_editor_for_post_type( 'post' );
    }

    public function add_ai_meta_boxes(): void {
        add_meta_box(
            'node_ai_summary',
            'Intelligence Summary (AI要約)',
            'node_ai_render_summary_meta_box',
            'post',
            'side',
            'high'
        );
        // ブロックエディタでは PluginDocumentSettingPanel（右ペイン）を使うためメタボックスは出さない。
        // context='side' を指定してもブロックエディタでは下部の「メタボックス」領域に落ちるため
        if ( ! $this->uses_block_editor_for_posts() ) {
            add_meta_box(
                'node_ai_fact_check',
                'Fact Check (ファクトチェック)',
                'node_ai_render_fact_check_meta_box',
                'post',
                'side',
                'default'
            );

            add_meta_box(
                'node_ai_featured_image',
                'AI アイキャッチ',
                'node_ai_render_featured_image_meta_box',
                'post',
                'side',
                'low'
            );
        }
    }

	public function provide_ai_summary( string $summary, int $post_id ): string {
		$stored = get_post_meta( $post_id, '_node_ai_summary', true );
		return ! empty( $stored ) ? $stored : $summary;
	}

	public function provide_reading_time( string $time, int $post_id ): string {
		$stored = get_post_meta( $post_id, '_node_reading_time', true );
		return ! empty( $stored ) ? $stored : $time;
	}

	public function auto_calculate_reading_time( int $post_id, \WP_Post $post, bool $update ): void {
		if ( function_exists( 'node_ai_calculate_reading_time' ) ) {
            node_ai_calculate_reading_time( $post_id, $post, $update );
        }
	}

    public function auto_generate_ai_summary( int $post_id, \WP_Post $post, bool $update ): void {
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( $post->post_type !== 'post' || $post->post_status !== 'publish' ) return;
        
        // REST API 経由（エディタ保存時）は、同期処理によるタイムアウトを避けるためスキップ
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

        // すでに要約が存在するかチェック
        $existing_summary = get_post_meta($post_id, '_node_ai_summary', true);
        if ( ! empty($existing_summary) ) return;

        if ( false === wp_next_scheduled( 'node_ai_auto_generate_summary', array( $post_id ) ) ) {
            wp_schedule_single_event( time() + 30, 'node_ai_auto_generate_summary', array( $post_id ) );
        }
    }

    /**
     * cron: 公開済み記事の要約をCore経由で生成する。既存要約は上書きしない。
     */
    public function run_auto_generate_ai_summary( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) return;
        if ( '' !== trim( (string) get_post_meta( $post_id, '_node_ai_summary', true ) ) ) return;

        // 本文の取得（ショートコードとタグを除去）
        $content = strip_shortcodes(strip_tags($post->post_content));
        if ( empty(trim($content)) ) return;

        if ( ! function_exists( 'node_ai_core' ) || ! node_ai_core()->is_enabled() ) return;

        $user_id = (int) $post->post_author;
        $result  = node_ai_core()->summarize( $content, array(), $user_id, $post_id );

        if ( is_wp_error( $result ) ) {
            if ( function_exists( 'node_ai_dispatch_connect_event' ) ) {
                node_ai_dispatch_connect_event( 'ai_failed', $post_id, 'summarize', $result->get_error_message() );
            }
            return;
        }

        if ( function_exists( 'node_ai_store_summary_result' ) ) {
            node_ai_store_summary_result( $post_id, $result, $user_id );
        }
    }
}

/**
 * プラグイン起動
 */
function node_ai_core_init(): void {
	Node_AI_Tools::instance();
}
add_action( 'plugins_loaded', 'node_ai_core_init' );

/**
 * 無効化時に自前の定期実行を掃除する（ハンドラのないイベントを残さない）。
 */
function node_ai_clear_scheduled_events(): void {
	foreach ( array( 'node_ai_fc_refresh_models', 'node_ai_fc_recheck_degraded' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}
register_deactivation_hook( __FILE__, 'node_ai_clear_scheduled_events' );
