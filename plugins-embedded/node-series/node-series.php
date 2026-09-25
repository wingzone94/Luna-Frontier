<?php
/**
 * Plugin Name:  Luna Series
 * Plugin URI:   https://github.com/wingzone94/Node
 * Description:  連載/シリーズ機能。記事をシリーズにまとめ、シリーズ内の前後記事・目次を取得する機能を提供。
 * Version:      1.4.0
 * Author:       Luminous Core Teams
 * License:      MIT
 * Text Domain:  node-series
 *
 * @package Node_Series
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NODE_SERIES_VERSION', '1.4.0' );
define( 'NODE_SERIES_ORDER_META_KEY', '_node_series_order' );
define( 'NODE_SERIES_COLOR_TERM_META_KEY', 'node_series_color' );
define( 'NODE_SERIES_COLOR_OVERRIDE_META_KEY', '_node_series_color_override' );
define( 'NODE_SERIES_DEFAULT_COLOR', '#FF9900' );
define( 'NODE_SERIES_MAX_POSTS', 10 );

/**
 * Node Series Main Class
 */
final class Node_Series {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_taxonomy' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_order_meta_box' ] );
		add_action( 'save_post', [ $this, 'save_order_meta_box' ] );
		add_action( 'node_series_add_form_fields', [ $this, 'render_term_color_field_add' ] );
		add_action( 'node_series_edit_form_fields', [ $this, 'render_term_color_field_edit' ] );
		add_action( 'created_node_series', [ $this, 'save_term_color_field' ] );
		add_action( 'edited_node_series', [ $this, 'save_term_color_field' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_color_picker' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_hide_taxonomy_panel' ] );
		add_action( 'save_post', [ $this, 'enforce_max_posts_per_series' ], 20 );
		add_action( 'admin_notices', [ $this, 'render_limit_notice' ] );
		add_filter( 'rest_pre_insert_post', [ $this, 'validate_series_limit_via_rest' ], 10, 2 );
		add_action( 'wp_ajax_node_series_term_status', [ $this, 'ajax_term_status' ] );
		add_action( 'delete_node_series', [ $this, 'cleanup_post_meta_on_term_delete' ], 10, 4 );
		add_action( 'template_redirect', [ $this, 'handle_legacy_redirect' ], 11 );
	}

	/**
	 * 404化でqueried objectが失われた旧カテゴリ/タグのtaxonomyとslugをURIから復元する。
	 * 旧termが削除済みでもマップ照合できるよう、termの実在は要求しない。
	 *
	 * @return array{taxonomy: string, slug: string}|null
	 */
	private static function resolve_legacy_source_from_request_uri( string $request_uri ): ?array {
		$request_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$home_path    = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$relative     = trim( $request_path, '/' );

		if ( '' !== $home_path ) {
			if ( $relative === $home_path ) {
				$relative = '';
			} elseif ( str_starts_with( $relative, $home_path . '/' ) ) {
				$relative = substr( $relative, strlen( $home_path ) + 1 );
			}
		}

		if ( ! preg_match(
			'#^(category|tag)/([^/]+)(?:/page/[1-9][0-9]*|/feed(?:/(?:feed|rdf|rss|rss2|atom))?)?/?$#i',
			$relative,
			$matches
		) ) {
			return null;
		}

		$slug = sanitize_title( rawurldecode( $matches[2] ) );

		if ( '' === $slug ) {
			return null;
		}

		return [
			'taxonomy' => 'category' === strtolower( $matches[1] ) ? 'category' : 'post_tag',
			'slug'     => $slug,
		];
	}

	/**
	 * 旧カテゴリ/タグアーカイブから移行先シリーズのURLを解決する。
	 *
	 * HTTP操作は行わず、対象外または移行先termが存在しない場合はnullを返す。
	 * 旧term自体は削除済みでもよい（マップのtaxonomy:slugキーだけで照合する）。
	 * ページ番号は引き継がず、feedとクエリ文字列だけを維持する。
	 */
	public static function resolve_legacy_redirect( ?object $queried_object, string $request_uri ): ?string {
		if ( $queried_object instanceof WP_Term
			&& in_array( $queried_object->taxonomy, [ 'category', 'post_tag' ], true )
		) {
			$source = [
				'taxonomy' => $queried_object->taxonomy,
				'slug'     => $queried_object->slug,
			];
		} else {
			$source = self::resolve_legacy_source_from_request_uri( $request_uri );
		}

		if ( null === $source ) {
			return null;
		}

		$redirect_map = get_option( 'node_series_redirect_map', [] );

		if ( ! is_array( $redirect_map ) ) {
			return null;
		}

		$map_key            = $source['taxonomy'] . ':' . $source['slug'];
		$target_series_slug = isset( $redirect_map[ $map_key ] ) && is_string( $redirect_map[ $map_key ] )
			? trim( $redirect_map[ $map_key ] )
			: '';

		if ( '' === $target_series_slug ) {
			return null;
		}

		$target_series = get_term_by( 'slug', $target_series_slug, 'node_series' );

		if ( ! $target_series instanceof WP_Term ) {
			return null;
		}

		$request_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$feed_type    = '';
		$is_feed      = (bool) preg_match(
			'#/(?:feed)(?:/(feed|rdf|rss|rss2|atom))?/?$#i',
			$request_path,
			$feed_match
		);

		if ( $is_feed ) {
			$feed_type  = isset( $feed_match[1] ) ? strtolower( (string) $feed_match[1] ) : '';
			$target_url = get_term_feed_link( $target_series->term_id, 'node_series', $feed_type );
		} else {
			$target_url = get_term_link( $target_series, 'node_series' );
		}

		if ( is_wp_error( $target_url ) || ! is_string( $target_url ) || '' === $target_url ) {
			return null;
		}

		$query_string = wp_parse_url( $request_uri, PHP_URL_QUERY );

		return is_string( $query_string ) && '' !== $query_string
			? $target_url . ( str_contains( $target_url, '?' ) ? '&' : '?' ) . $query_string
			: $target_url;
	}

	/**
	 * フロント側の旧カテゴリ/タグアーカイブだけをシリーズへ301転送する。
	 */
	public function handle_legacy_redirect(): void {
		$is_rest_request = defined( 'REST_REQUEST' ) && REST_REQUEST;

		if ( is_admin() || wp_doing_ajax() || $is_rest_request ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$target_url  = self::resolve_legacy_redirect( get_queried_object(), $request_uri );

		if ( null === $target_url ) {
			return;
		}

		wp_safe_redirect( $target_url, 301 );
		exit;
	}

	/**
	 * ブロックエディタ（REST API経由の保存）向けの上限チェック。
	 * admin_noticesフックはブロックエディタの画面ではコアによって無効化されるため、
	 * 表示されない通知を出す代わりに、保存自体をエラーとして拒否し、
	 * エディタの保存失敗通知（赤いスナックバー）にメッセージを表示させる。
	 */
	public function validate_series_limit_via_rest( $prepared_post, WP_REST_Request $request ) {
		if ( is_wp_error( $prepared_post ) || ! isset( $request['node_series'] ) ) {
			return $prepared_post;
		}

		$post_id = (int) ( $request['id'] ?? 0 );

		foreach ( (array) $request['node_series'] as $term_id ) {
			$term_id = (int) $term_id;
			$already_in_term = $post_id && has_term( $term_id, 'node_series', $post_id );
			$effective_count = node_series_count_posts_in_term( $term_id ) + ( $already_in_term ? 0 : 1 );

			if ( $effective_count > NODE_SERIES_MAX_POSTS ) {
				$term = get_term( $term_id, 'node_series' );
				return new WP_Error(
					'node_series_limit_exceeded',
					sprintf(
						'シリーズ「%1$s」は登録上限（%2$d件）に達しているため、この記事を追加できません。',
						$term instanceof WP_Term ? $term->name : '',
						NODE_SERIES_MAX_POSTS
					),
					[ 'status' => 400 ]
				);
			}
		}

		return $prepared_post;
	}

	/**
	 * シリーズ内の登録上限（NODE_SERIES_MAX_POSTS件）を超えていた場合、
	 * この記事のシリーズ割り当てを取り消す。ステータスを問わず数える（公開済みのみだと
	 * 下書きが多いシリーズで上限判定が緩くなるため）。
	 *
	 * ブロックエディタの保存は validate_series_limit_via_rest() が先に保存自体を拒否するため
	 * 通常はここに到達しない。クラシックエディタやWP-CLI等、REST API経由ではない保存経路に対する
	 * 保険（バックストップ）として残す。
	 */
	public function enforce_max_posts_per_series( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$term = node_series_get_term( $post_id );
		if ( null === $term ) {
			return;
		}

		$count = node_series_count_posts_in_term( $term->term_id );
		if ( $count <= NODE_SERIES_MAX_POSTS ) {
			return;
		}

		wp_remove_object_terms( $post_id, $term->term_id, 'node_series' );
		set_transient( 'node_series_limit_notice_' . get_current_user_id(), $term->name, 60 );
	}

	/**
	 * 上限超過でシリーズ割り当てを取り消した場合の管理画面通知。
	 */
	public function render_limit_notice(): void {
		$user_id   = get_current_user_id();
		$term_name = get_transient( 'node_series_limit_notice_' . $user_id );

		if ( ! $term_name ) {
			return;
		}

		delete_transient( 'node_series_limit_notice_' . $user_id );
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					'シリーズ「%1$s」は登録上限（%2$d件）に達しているため、この記事のシリーズ割り当てを解除しました。',
					$term_name,
					NODE_SERIES_MAX_POSTS
				)
			)
		);
	}

	/**
	 * シリーズ編集画面でカラーピッカーを使うためのアセット読み込み。
	 */
	public function enqueue_color_picker( string $hook_suffix ): void {
		$is_term_screen = in_array( $hook_suffix, [ 'edit-tags.php', 'term.php' ], true )
			&& isset( $_GET['taxonomy'] ) && 'node_series' === $_GET['taxonomy'];
		$is_post_screen = in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true );

		if ( ! $is_term_screen && ! $is_post_screen ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script(
			'wp-color-picker',
			"jQuery(function($){ \$('.node-series-color-field').wpColorPicker(); });"
		);
	}

	/**
	 * ブロックエディタのサイドバーに自動表示される「シリーズ」タクソノミーパネルを非表示にする。
	 * 登録先シリーズの指定は「シリーズ内の表示順序」ボックス側に統合済みのため、
	 * 二重のUI（食い違うと上書きが発生する）を避ける。
	 */
	public function enqueue_hide_taxonomy_panel( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type || ! $screen->is_block_editor() ) {
			return;
		}

		wp_add_inline_script(
			'wp-edit-post',
			"wp.domReady(function(){ if (window.wp && wp.data && wp.data.dispatch('core/edit-post')) { wp.data.dispatch('core/edit-post').removeEditorPanel('taxonomy-panel-node_series'); } });"
		);
	}

	/**
	 * 新規シリーズ作成画面: プライマリカラー入力欄。
	 */
	public function render_term_color_field_add(): void {
		?>
		<div class="form-field">
			<label for="node_series_color">プライマリカラー</label>
			<input
				type="text"
				id="node_series_color"
				name="node_series_color"
				class="node-series-color-field"
				value=""
				data-default-color="<?php echo esc_attr( NODE_SERIES_DEFAULT_COLOR ); ?>"
				placeholder="<?php echo esc_attr( NODE_SERIES_DEFAULT_COLOR ); ?>"
			>
			<p class="description">このシリーズのカード表示などに使う色。未設定の場合は既定のブランドオレンジ（<?php echo esc_html( NODE_SERIES_DEFAULT_COLOR ); ?>）になります。</p>
		</div>
		<?php
	}

	/**
	 * シリーズ編集画面: プライマリカラー入力欄。
	 */
	public function render_term_color_field_edit( WP_Term $term ): void {
		$color = get_term_meta( $term->term_id, NODE_SERIES_COLOR_TERM_META_KEY, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="node_series_color">プライマリカラー</label></th>
			<td>
				<input
					type="text"
					id="node_series_color"
					name="node_series_color"
					class="node-series-color-field"
					value="<?php echo esc_attr( $color ); ?>"
					data-default-color="<?php echo esc_attr( NODE_SERIES_DEFAULT_COLOR ); ?>"
					placeholder="<?php echo esc_attr( NODE_SERIES_DEFAULT_COLOR ); ?>"
				>
				<p class="description">このシリーズのカード表示などに使う色。未設定の場合は既定のブランドオレンジ（<?php echo esc_html( NODE_SERIES_DEFAULT_COLOR ); ?>）になります。</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * シリーズのプライマリカラーをterm metaに保存。
	 */
	public function save_term_color_field( int $term_id ): void {
		if ( ! isset( $_POST['node_series_color'] ) ) {
			return;
		}

		$color = sanitize_text_field( wp_unslash( $_POST['node_series_color'] ) );

		if ( '' === $color || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
			delete_term_meta( $term_id, NODE_SERIES_COLOR_TERM_META_KEY );
			return;
		}

		update_term_meta( $term_id, NODE_SERIES_COLOR_TERM_META_KEY, $color );
	}

	/**
	 * シリーズ（term）が削除された際、そのシリーズに属していた投稿に残る
	 * 表示順・記事別カラー上書きのメタを後片付けする。
	 *
	 * タクソノミーの紐付け（term_relationships）自体はWPコアが自動的に削除するが、
	 * 投稿側の表示順メタ（_node_series_order）は対象外のため、削除せずに放置すると
	 * 別のシリーズに再登録した際に古い回数が残ってしまう。
	 * なお記事別カラー上書き（_node_series_color_override）は、シリーズに依存しない
	 * 「この記事自体の色」という意味合いのため、シリーズ削除後も消さずに残す
	 * （別のシリーズに再登録した場合に引き続き使われる）。
	 *
	 * @param int      $term         削除されたterm ID。
	 * @param int      $tt_id        term_taxonomy ID（未使用）。
	 * @param WP_Term  $deleted_term 削除済みtermのスナップショット（未使用）。
	 * @param int[]    $object_ids   削除前にこのシリーズに属していた投稿IDの一覧。
	 */
	public function cleanup_post_meta_on_term_delete( int $term, int $tt_id, $deleted_term, array $object_ids ): void {
		foreach ( $object_ids as $post_id ) {
			delete_post_meta( $post_id, NODE_SERIES_ORDER_META_KEY );
		}
	}

	/**
	 * カスタムタクソノミー node_series を登録。
	 * 作成・編集・記事への割当はWP標準のtaxonomy管理画面UIをそのまま利用する。
	 */
	public function register_taxonomy(): void {
		register_taxonomy(
			'node_series',
			[ 'post' ],
			[
				'labels'            => [
					'name'          => 'シリーズ',
					'singular_name' => 'シリーズ',
					'search_items'  => 'シリーズを検索',
					'all_items'     => 'すべてのシリーズ',
					'edit_item'     => 'シリーズを編集',
					'add_new_item'  => '新規シリーズを追加',
					'menu_name'     => 'シリーズ',
				],
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'meta_box_cb'       => false, // 登録先シリーズの指定は「シリーズ内の表示順序」ボックスに統合（重複UIを避ける）。
				'rewrite'           => [ 'slug' => 'series' ],
			]
		);

		// リライトルールの一回だけflush（node-libraryと同型のバージョン比較方式）。
		// これが無いと本番で /series/* が404になる（/spotlight/ 事故=CHANGELOG 1.0.3 の再演条件）。
		// rewrite仕様を変えるときは NODE_SERIES_VERSION を必ず上げること。
		$current_flush_version = get_option( 'node_series_flushed_version', '0' );
		if ( version_compare( $current_flush_version, NODE_SERIES_VERSION, '<' ) ) {
			flush_rewrite_rules( false );
			update_option( 'node_series_flushed_version', NODE_SERIES_VERSION );
		}
	}

	/**
	 * シリーズ内の表示順序を設定するメタボックス。
	 */
	public function add_order_meta_box(): void {
		add_meta_box(
			'node_series_order',
			'シリーズ内の表示順序',
			[ $this, 'render_order_meta_box' ],
			'post',
			'side',
			'default'
		);
	}

	/**
	 * 指定シリーズ内で、ある投稿の表示順候補がどう制約されるかを返す。
	 * - used: 他の投稿（自分以外）が既に使っている表示順 => タイトル
	 * - next_min: これより小さい値は「既に公開済みの回より前」になるため選択不可
	 *   （公開済みの回の表示順を後から繰り上げてしまうのを防ぐ）
	 *
	 * @param int $term_id        対象のシリーズterm ID（0の場合は制約なし）。
	 * @param int $exclude_post_id 自分自身の投稿ID（候補集計から除外する）。
	 * @return array{count:int, max:int, next_min:int, used:array<int,string>}
	 */
	private function get_series_order_constraints( int $term_id, int $exclude_post_id ): array {
		if ( $term_id <= 0 ) {
			return [
				'count'    => 0,
				'max'      => NODE_SERIES_MAX_POSTS,
				'next_min' => 1,
				'used'     => [],
			];
		}

		$query = new WP_Query(
			[
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'post__not_in'   => [ $exclude_post_id ],
				'tax_query'      => [
					[
						'taxonomy' => 'node_series',
						'field'    => 'term_id',
						'terms'    => $term_id,
					],
				],
			]
		);

		$used          = [];
		$max_published = 0;

		foreach ( $query->posts as $other_post ) {
			$order = get_post_meta( $other_post->ID, NODE_SERIES_ORDER_META_KEY, true );

			if ( '' === $order ) {
				continue;
			}

			$order          = (int) $order;
			$used[ $order ] = get_the_title( $other_post );

			if ( 'publish' === $other_post->post_status && $order > $max_published ) {
				$max_published = $order;
			}
		}

		return [
			'count'    => node_series_count_posts_in_term( $term_id ),
			'max'      => NODE_SERIES_MAX_POSTS,
			'next_min' => $max_published + 1,
			'used'     => $used,
		];
	}

	/**
	 * 「登録先シリーズ」を切り替えた際に、表示順プルダウンの選択可否と「/ 件数」を
	 * 再計算するためのAjaxエンドポイント。
	 */
	public function ajax_term_status(): void {
		check_ajax_referer( 'node_series_order_status', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [], 403 );
		}

		$term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;

		wp_send_json_success( $this->get_series_order_constraints( $term_id, $post_id ) );
	}

	public function render_order_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'node_series_save_order', 'node_series_order_nonce' );

		$current_term    = node_series_get_term( $post->ID );
		$current_term_id = $current_term ? $current_term->term_id : 0;
		$order           = get_post_meta( $post->ID, NODE_SERIES_ORDER_META_KEY, true );
		$order           = '' === $order ? '' : (int) $order;
		$color_override  = get_post_meta( $post->ID, NODE_SERIES_COLOR_OVERRIDE_META_KEY, true );
		$terms           = get_terms( [ 'taxonomy' => 'node_series', 'hide_empty' => false ] );
		$constraints     = $this->get_series_order_constraints( $current_term_id, $post->ID );
		?>
		<p>
			<label for="node_series_term_select">登録先シリーズ</label>
			<select id="node_series_term_select" name="node_series_term_id" style="width: 100%;">
				<option value=""<?php selected( null === $current_term ); ?>>— 未設定 —</option>
				<?php
				foreach ( $terms as $term ) :
					$term_count    = node_series_count_posts_in_term( $term->term_id );
					$is_over_limit = $term_count >= NODE_SERIES_MAX_POSTS && $term->term_id !== $current_term_id;
					?>
					<option
						value="<?php echo esc_attr( $term->term_id ); ?>"
						<?php selected( $current_term_id === $term->term_id ); ?>
						<?php disabled( $is_over_limit ); ?>
					>
						<?php echo esc_html( $term->name ); ?><?php echo $is_over_limit ? '（上限到達）' : ''; ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="node_series_order_input">シリーズ内の回数</label>
			<span style="display: flex; align-items: center; gap: 6px;">
				<select id="node_series_order_input" name="node_series_order" style="flex: 1;">
					<option value=""<?php selected( '' === $order ); ?>>未設定</option>
					<?php
					for ( $i = 1; $i <= NODE_SERIES_MAX_POSTS; $i++ ) :
						$is_own_value = $order === $i;
						$is_used      = ! $is_own_value && isset( $constraints['used'][ $i ] );
						$is_too_early = ! $is_own_value && $i < $constraints['next_min'];
						?>
						<option
							value="<?php echo esc_attr( $i ); ?>"
							<?php selected( $order, $i ); ?>
							<?php disabled( $is_used || $is_too_early ); ?>
						>
							第<?php echo esc_html( $i ); ?>回<?php echo $is_used ? '（使用済み）' : ( $is_too_early ? '（既刊より前）' : '' ); ?>
						</option>
					<?php endfor; ?>
				</select>
				<span aria-hidden="true">/</span>
				<span id="node_series_count_display"><?php echo esc_html( $constraints['count'] ); ?></span>
			</span>
		</p>
		<p class="description">「/ N」は、上で選択したシリーズに現在登録されている記事数です（上限<?php echo esc_html( NODE_SERIES_MAX_POSTS ); ?>件）。すでに公開済みの回より前の回数は選べません。</p>
		<p>
			<label for="node_series_color_override_input">この記事だけのプライマリカラー（任意）</label>
			<input
				type="text"
				id="node_series_color_override_input"
				name="node_series_color_override"
				class="node-series-color-field"
				value="<?php echo esc_attr( $color_override ); ?>"
				placeholder="シリーズ共通の色を使用"
				style="width: 100%;"
			>
		</p>
		<p class="description">未設定の場合はシリーズ共通のプライマリカラーが使われます。</p>
		<script>
		( function() {
			var postId         = <?php echo (int) $post->ID; ?>;
			var originalTermId = <?php echo (int) $current_term_id; ?>;
			var originalOrder  = '<?php echo esc_js( '' === $order ? '' : (string) $order ); ?>';
			var nonce          = '<?php echo esc_js( wp_create_nonce( 'node_series_order_status' ) ); ?>';
			var termSelect     = document.getElementById( 'node_series_term_select' );
			var orderSelect    = document.getElementById( 'node_series_order_input' );
			var countDisplay   = document.getElementById( 'node_series_count_display' );

			if ( ! termSelect || ! orderSelect || ! countDisplay ) {
				return;
			}

			function rebuildOrderOptions( data, keepValue ) {
				var used      = data.used || {};
				var nextMin   = data.next_min || 1;
				var currentVal = '' !== keepValue ? parseInt( keepValue, 10 ) : null;

				countDisplay.textContent = data.count;

				Array.prototype.forEach.call( orderSelect.options, function( opt ) {
					if ( '' === opt.value ) {
						return;
					}

					var i         = parseInt( opt.value, 10 );
					var isOwn     = currentVal === i;
					var isUsed    = ! isOwn && Object.prototype.hasOwnProperty.call( used, i );
					var isTooEarly = ! isOwn && i < nextMin;

					opt.disabled = isUsed || isTooEarly;
					opt.textContent = '第' + i + '回' + ( isUsed ? '（使用済み）' : ( isTooEarly ? '（既刊より前）' : '' ) );
				} );

				var selectedOpt = orderSelect.options[ orderSelect.selectedIndex ];
				if ( selectedOpt && selectedOpt.disabled ) {
					orderSelect.value = '';
				}
			}

			termSelect.addEventListener( 'change', function() {
				var targetTermId = parseInt( termSelect.value, 10 ) || 0;
				var keepValue    = ( targetTermId === originalTermId ) ? originalOrder : '';
				var body         = new FormData();

				body.append( 'action', 'node_series_term_status' );
				body.append( 'nonce', nonce );
				body.append( 'post_id', postId );
				body.append( 'term_id', targetTermId );

				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function( res ) { return res.json(); } )
					.then( function( json ) {
						if ( json && json.success ) {
							rebuildOrderOptions( json.data, keepValue );
						}
					} );
			} );
		} )();
		</script>
		<?php
	}

	public function save_order_meta_box( int $post_id ): void {
		// F-3: リビジョン/オートセーブ/権限のガード（enforce_max_posts_per_series と同型）。
		// これが無いと save_post がリビジョンIDでも発火した際に、
		// (1) wp_set_object_terms がリビジョンに term を割り当てて object_ids を汚染し、
		// (2) メタ関数（update/delete_post_meta）は親へリダイレクトされるため、
		//     constraints 計算で親自身の表示順が「他記事の使用済み」に見えて拒否され、
		//     delete_post_meta が親の表示順メタを削除する（クラシックエディタで実害確認済み）。
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['node_series_order_nonce'] ) || ! wp_verify_nonce( $_POST['node_series_order_nonce'], 'node_series_save_order' ) ) {
			return;
		}

		// 検証の基準にするため、term割当を変更する前に「変更前の状態」を保持しておく
		// （自分自身が既に持っている表示順は、シリーズを変えない限り常に選択可として扱う）。
		$original_term         = node_series_get_term( $post_id );
		$original_term_id      = $original_term ? $original_term->term_id : 0;
		$original_order        = get_post_meta( $post_id, NODE_SERIES_ORDER_META_KEY, true );
		$original_order        = '' === $original_order ? null : (int) $original_order;

		$term_id = isset( $_POST['node_series_term_id'] ) ? (int) $_POST['node_series_term_id'] : 0;

		if ( isset( $_POST['node_series_term_id'] ) ) {
			if ( $term_id > 0 && term_exists( $term_id, 'node_series' ) ) {
				wp_set_object_terms( $post_id, [ $term_id ], 'node_series', false );
			} else {
				wp_set_object_terms( $post_id, [], 'node_series', false );
			}
		}

		// 表示順は、UI側で選択不可にしている値（重複・既刊より前）が万一送られてきても保存しない
		// （JS無効環境やAjax失敗時の保険。通常はプルダウン側で選択できないため到達しない）。
		if ( ! isset( $_POST['node_series_order'] ) || '' === $_POST['node_series_order'] ) {
			delete_post_meta( $post_id, NODE_SERIES_ORDER_META_KEY );
		} else {
			$order_value = (int) $_POST['node_series_order'];

			if ( $term_id > 0 ) {
				$is_unchanged_own_value = ( $term_id === $original_term_id ) && ( $order_value === $original_order );
				$constraints            = $this->get_series_order_constraints( $term_id, $post_id );

				if ( ! $is_unchanged_own_value
					&& ( isset( $constraints['used'][ $order_value ] ) || $order_value < $constraints['next_min'] )
				) {
					$order_value = null;
				}
			}

			if ( null === $order_value ) {
				delete_post_meta( $post_id, NODE_SERIES_ORDER_META_KEY );
			} else {
				update_post_meta( $post_id, NODE_SERIES_ORDER_META_KEY, $order_value );
			}
		}

		$color_override = isset( $_POST['node_series_color_override'] ) ? sanitize_text_field( wp_unslash( $_POST['node_series_color_override'] ) ) : '';

		if ( '' === $color_override || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $color_override ) ) {
			delete_post_meta( $post_id, NODE_SERIES_COLOR_OVERRIDE_META_KEY );
			return;
		}

		update_post_meta( $post_id, NODE_SERIES_COLOR_OVERRIDE_META_KEY, $color_override );
	}
}

/**
 * 投稿が属する最初の node_series term を返す。
 *
 * @param int $post_id 投稿ID。
 * @return WP_Term|null
 */
function node_series_get_term( int $post_id ): ?WP_Term {
	$terms = get_the_terms( $post_id, 'node_series' );

	if ( ! is_array( $terms ) || empty( $terms ) ) {
		return null;
	}

	return $terms[0];
}

/**
 * シリーズ内の投稿一覧を表示順（_node_series_order昇順→投稿日昇順）で返す。
 *
 * @param int $term_id node_series term ID。
 * @return WP_Post[]
 */
function node_series_get_posts( int $term_id ): array {
	$query = new WP_Query(
		[
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'tax_query'      => [
				[
					'taxonomy' => 'node_series',
					'field'    => 'term_id',
					'terms'    => $term_id,
				],
			],
			'orderby'        => 'date',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		]
	);

	return node_series_sort_posts( $query->posts );
}

/**
 * 投稿一覧をシリーズの表示順（_node_series_order昇順→投稿日昇順）で並べ替える。
 *
 * @param WP_Post[] $posts 並べ替え対象の投稿。
 * @return WP_Post[]
 */
function node_series_sort_posts( array $posts ): array {
	usort( $posts, function ( WP_Post $a, WP_Post $b ) {
		$order_a = get_post_meta( $a->ID, NODE_SERIES_ORDER_META_KEY, true );
		$order_b = get_post_meta( $b->ID, NODE_SERIES_ORDER_META_KEY, true );
		$has_a   = '' !== $order_a;
		$has_b   = '' !== $order_b;

		if ( $has_a !== $has_b ) {
			return $has_a ? -1 : 1;
		}
		if ( $has_a && $has_b ) {
			return (int) $order_a - (int) $order_b;
		}
		return strtotime( $a->post_date ) - strtotime( $b->post_date );
	} );

	return $posts;
}

/**
 * 「この投稿を表示している文脈」でのシリーズ内投稿一覧を返す。
 *
 * 一覧の基本は公開済み記事のみ（node_series_get_posts）だが、表示中の投稿自身が
 * 未公開（下書き・レビュー待ち・予約など＝プレビュー閲覧中）の場合、公開限定の
 * 一覧に含まれず執筆中に表示確認ができないため、表示中の投稿に限って一覧へ合流させる。
 * 他の未公開記事は従来どおり一覧に含めない（下書きの漏えい防止）。
 *
 * @param int $post_id 表示中の投稿ID。
 * @param int $term_id node_series term ID。
 * @return WP_Post[]
 */
function node_series_get_display_posts( int $post_id, int $term_id ): array {
	$posts = node_series_get_posts( $term_id );

	if ( in_array( $post_id, wp_list_pluck( $posts, 'ID' ), true ) ) {
		return $posts;
	}

	$current = get_post( $post_id );

	if ( $current instanceof WP_Post && has_term( $term_id, 'node_series', $current ) ) {
		$posts[] = $current;
		$posts   = node_series_sort_posts( $posts );
	}

	return $posts;
}

/**
 * シリーズ内の登録件数を、投稿ステータスを問わず数える（上限チェック用）。
 *
 * @param int $term_id node_series term ID。
 * @return int
 */
function node_series_count_posts_in_term( int $term_id ): int {
	$query = new WP_Query(
		[
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => [
				[
					'taxonomy' => 'node_series',
					'field'    => 'term_id',
					'terms'    => $term_id,
				],
			],
		]
	);

	return count( $query->posts );
}

/**
 * シリーズ内の前後記事を返す。
 *
 * @param int    $post_id   投稿ID。
 * @param string $direction 'prev' | 'next'。
 * @return WP_Post|null
 */
function node_series_get_adjacent( int $post_id, string $direction ): ?WP_Post {
	$term = node_series_get_term( $post_id );

	if ( null === $term ) {
		return null;
	}

	$posts = node_series_get_display_posts( $post_id, $term->term_id );
	$index = null;

	foreach ( $posts as $i => $post ) {
		if ( $post->ID === $post_id ) {
			$index = $i;
			break;
		}
	}

	if ( null === $index ) {
		return null;
	}

	$target_index = 'prev' === $direction ? $index - 1 : $index + 1;

	return $posts[ $target_index ] ?? null;
}

/**
 * テンプレート表示用のシリーズ目次データを返す。
 * シリーズに属していない場合はnullを返す。第1回のみのシリーズ（1件）でも表示する
 * （1.2.1: 従来は1件以下を非表示にしていたが、連載開始直後の記事でシリーズ設定が
 * 一切反映されず「設定が効いていない」ように見えるため、1件から表示する仕様に変更）。
 *
 * @param int $post_id 投稿ID。
 * @return array{term: WP_Term, items: array<int, array{id:int, title:string, url:string, is_current:bool}>}|null
 */
function node_series_get_toc_data( int $post_id ): ?array {
	$term = node_series_get_term( $post_id );

	if ( null === $term ) {
		return null;
	}

	$posts = node_series_get_display_posts( $post_id, $term->term_id );

	if ( empty( $posts ) ) {
		return null;
	}

	$items = [];

	foreach ( $posts as $post ) {
		$items[] = [
			'id'         => $post->ID,
			'title'      => get_the_title( $post ),
			'url'        => get_permalink( $post ),
			'is_current' => $post->ID === $post_id,
		];
	}

	return [
		'term'  => $term,
		'items' => $items,
	];
}

/**
 * カード等に表示するための、シリーズ内の位置情報を返す。
 *
 * @param int $post_id 投稿ID。
 * @return array{term: WP_Term, index:int, total:int}|null
 */
function node_series_get_position( int $post_id ): ?array {
	$toc = node_series_get_toc_data( $post_id );

	if ( null === $toc ) {
		return null;
	}

	foreach ( $toc['items'] as $i => $item ) {
		if ( $item['is_current'] ) {
			return [
				'term'  => $toc['term'],
				'index' => $i + 1,
				'total' => count( $toc['items'] ),
			];
		}
	}

	return null;
}

/**
 * 投稿に表示すべきシリーズのプライマリカラーを返す。
 * 優先順位: 記事ごとの上書き色 > シリーズ共通色 > 既定のブランドオレンジ。
 *
 * @param int $post_id 投稿ID。
 * @return string|null 投稿がどのシリーズにも属さない場合はnull。
 */
function node_series_get_color( int $post_id ): ?string {
	$term = node_series_get_term( $post_id );

	if ( null === $term ) {
		return null;
	}

	$override = get_post_meta( $post_id, NODE_SERIES_COLOR_OVERRIDE_META_KEY, true );

	if ( $override ) {
		return $override;
	}

	$term_color = get_term_meta( $term->term_id, NODE_SERIES_COLOR_TERM_META_KEY, true );

	if ( $term_color ) {
		return $term_color;
	}

	return NODE_SERIES_DEFAULT_COLOR;
}

function node_series_init() {
	Node_Series::instance();
}
add_action( 'plugins_loaded', 'node_series_init' );
