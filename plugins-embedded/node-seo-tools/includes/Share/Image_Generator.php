<?php
/**
 * OGP share image generator (GD).
 *
 * @package Node_SEO_Tools
 */

namespace Node\SEO\Tools\Share;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Image_Generator {
	/**
	 * 描画ロジックの世代。レイアウト変更時に上げると既存画像が自動再生成される。
	 */
	public const GENERATOR_VERSION = '2026-06-12-ogp-v2';

	/** Threads 等の上下トリミングを考慮したセーフゾーン（1200x630 基準） */
	private const SNS_SAFE_TOP    = 90;
	private const SNS_SAFE_BOTTOM = 540;
	/** Instagram 1:1 中央クロップ領域（1200x630 → 中央 630x630）の内側余白 */
	private const INSTAGRAM_SQUARE_PAD = 44;
	/** テキスト行の最大幅（セーフゾーン幅に対する比率） */
	private const CONTENT_TEXT_WIDTH_RATIO = 0.94;
	/** ブロック全体の垂直位置（0=上寄せ, 0.5=中央, やや上に見せる光学補正） */
	private const LAYOUT_VERTICAL_BIAS = 0.47;
	private const BRAND_LOGO_GAP = 26;
	/** セーフゾーン下端のテキスト余白 */
	private const CONTENT_BOTTOM_PAD = 14;
	/** ロゴ背後のグラデーション切り抜き余白 */
	private const LOGO_CUTOUT_PAD_X = 12;
	private const LOGO_CUTOUT_PAD_Y = 10;

	private const META_GENERATOR_VERSION = '_node_ogp_generator_version';
	private const META_BRAND_FALLBACK    = '_node_ogp_brand_fallback';
	private const BRAND_DISPLAY_NAME     = 'Luminous Core';
	private const BRAND_SITE_URL         = 'https://luminous-core.net/';
	private const BRAND_URL_FONT_RATIO   = 0.36;
	private const BRAND_URL_MIN_FONT     = 20;
	private const BRAND_URL_GAP_RATIO     = 0.22;
	/** 通常 OGP 右下のロゴ＋サイト名 */
	private const CORNER_BRAND_MAX_FONT   = 16;
	private const CORNER_BRAND_MIN_FONT   = 12;
	private const CORNER_BRAND_PAD_INSIDE = 12;
	private const CORNER_BRAND_LOGO_SCALE = 1.15;
	private const CORNER_BRAND_LOGO_GAP   = 10;
	/** ogp-bg.png の下端グラデーション（1024 基準） */
	private const BOTTOM_BAR_TOP_RATIO    = 893 / 1024;
	private const BOTTOM_BAR_HEIGHT_RATIO = 72 / 1024;
	private const BOTTOM_BAR_LEFT_RATIO   = 57 / 1024;
	private const BOTTOM_BAR_WIDTH_RATIO  = 910 / 1024;
	private const BRAND_LOGO_HEIGHT      = 72;
	private const BRAND_TEXT_MAX_FONT    = 74;
	private const BRAND_TEXT_MIN_FONT    = 44;

	private const UNIFY_ALL_LINE_WEIGHT   = true;
	private const UNIFY_MIXED_LINE_WEIGHT = true;
	private const MAX_TITLE_LINES         = 4;
	private const TITLE_MAX_FONT_SIZE     = 56;
	private const TITLE_MIN_FONT_SIZE     = 38;
	/** 句読点優先レイアウトで下げる最小フォント（超長文用） */
	private const TITLE_MIN_FONT_SIZE_FALLBACK = 22;
	/** 1 行収納時に許容する最大トラッキング圧縮（フォントサイズ比・字間あたり） */
	private const SINGLE_LINE_MAX_TRACKING_RATIO = 0.11;
	private const MIN_ORPHAN_LINE_CHARS   = 10;
	/** 改行候補として優先する句読点（右から最後に収まる位置を採用） */
	private const PUNCTUATION_BREAK_CHARS = '、。，．；：';
	private const BOLD_PIXEL_OFFSETS      = array(
		array( 0.0, 0.0 ),
		array( 1.0, 0.0 ),
		array( 0.0, 1.0 ),
		array( 1.0, 1.0 ),
		array( 2.0, 0.0 ),
		array( 0.0, 2.0 ),
	);
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'save_post', array( $this, 'generate_on_save' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_regenerate_stale' ) );
		add_action( 'node_seo_regenerate_stale_ogp', array( $this, 'regenerate_stale_in_background' ) );
	}

	/**
	 * 旧ロジックで生成済みのOGP画像の再生成を予約する。
	 * 画像生成そのものは記事表示中に実行しない。
	 */
	public function maybe_regenerate_stale(): void {
		if ( is_admin() || wp_doing_ajax() || ! is_singular( 'post' ) ) {
			return;
		}
		if ( ! get_option( 'node_ogp_enabled' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		if ( self::GENERATOR_VERSION === get_post_meta( $post_id, self::META_GENERATOR_VERSION, true ) ) {
			return;
		}

		$args = array( $post_id );
		if ( ! wp_next_scheduled( 'node_seo_regenerate_stale_ogp', $args ) ) {
			wp_schedule_single_event( time() + 30, 'node_seo_regenerate_stale_ogp', $args );
		}
	}

	/**
	 * 再生成直前に条件を確認する。保存時生成で更新済みなら重複して描画しない。
	 */
	public function regenerate_stale_in_background( int $post_id ): void {
		if ( ! get_option( 'node_ogp_enabled' ) || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		if ( self::GENERATOR_VERSION === get_post_meta( $post_id, self::META_GENERATOR_VERSION, true ) ) {
			return;
		}

		try {
			$this->generate_ogp( $post_id );
		} catch ( \Exception $e ) {
			error_log( 'Node SEO Tools OGP regenerate error: ' . $e->getMessage() );
		}
	}

	public function generate_on_save( int $post_id, \WP_Post $post ): void {
		if ( ! get_option( 'node_ogp_enabled' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status || 'post' !== $post->post_type ) {
			return;
		}

		try {
			$this->generate_ogp( $post_id );
		} catch ( \Exception $e ) {
			error_log( 'Node SEO Tools OGP error: ' . $e->getMessage() );
		}
	}

	public function generate_ogp( int $post_id ): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			error_log( 'Node SEO Tools: GD is not available. OGP generation skipped.' );
			return;
		}

		$title = get_the_title( $post_id );
		if ( '' === $title ) {
			return;
		}

		$assets = Asset_Syncer::ensure_assets();

		$width  = 1200;
		$height = 630;

		$image = imagecreatetruecolor( $width, $height );
		if ( false === $image ) {
			return;
		}

		$this->apply_background( $image, $assets['background'], $width, $height );

		$used_brand_fallback = ! $this->apply_title(
			$image,
			$title,
			$assets['font_jp'] ?? '',
			$assets['font_latin'] ?? '',
			$width,
			$height
		);
		if ( $used_brand_fallback ) {
			$this->apply_brand_fallback( $image, $assets, $width, $height );
		}

		$this->apply_corner_brand_mark( $image, $assets['logo'] ?? '', $width, $height );

		$upload_dir = wp_upload_dir();
		$ogp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'ogp';
		if ( ! is_dir( $ogp_dir ) ) {
			wp_mkdir_p( $ogp_dir );
		}

		$filename = 'ogp-' . $post_id . '.png';
		$filepath = trailingslashit( $ogp_dir ) . $filename;

		imagepng( $image, $filepath, 6 );
		if ( function_exists( 'imagedestroy' ) ) {
			imagedestroy( $image );
		}

		$mtime = is_file( $filepath ) ? (int) filemtime( $filepath ) : time();

		update_post_meta(
			$post_id,
			'_node_ogp_image_url',
			trailingslashit( $upload_dir['baseurl'] ) . 'ogp/' . $filename
		);
		update_post_meta( $post_id, '_node_ogp_image_mtime', $mtime );
		update_post_meta( $post_id, self::META_GENERATOR_VERSION, self::GENERATOR_VERSION );
		update_post_meta( $post_id, self::META_BRAND_FALLBACK, $used_brand_fallback ? '1' : '0' );
	}

	/**
	 * @param \GdImage|resource $image
	 */
	private function apply_background( $image, string $bg_path, int $width, int $height ): void {
		if ( ! Asset_Syncer::is_valid_image( $bg_path ) ) {
			$white = imagecolorallocate( $image, 255, 255, 255 );
			imagefill( $image, 0, 0, $white );
			return;
		}

		$info = getimagesize( $bg_path );
		if ( ! is_array( $info ) ) {
			return;
		}

		$source = match ( $info[2] ) {
			IMAGETYPE_JPEG => imagecreatefromjpeg( $bg_path ),
			IMAGETYPE_PNG  => imagecreatefrompng( $bg_path ),
			IMAGETYPE_WEBP => function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $bg_path ) : false,
			default        => false,
		};

		if ( false === $source ) {
			return;
		}

		imagecopyresampled( $image, $source, 0, 0, 0, 0, $width, $height, imagesx( $source ), imagesy( $source ) );
		if ( function_exists( 'imagedestroy' ) ) {
			imagedestroy( $source );
		}
	}

	/**
	 * @param \GdImage|resource $image
	 */
	private function apply_logo( $image, string $logo_path, int $width, int $height ): void {
		if ( ! Asset_Syncer::is_valid_image( $logo_path ) ) {
			return;
		}

		$logo_info = getimagesize( $logo_path );
		if ( ! is_array( $logo_info ) ) {
			return;
		}

		$logo_src = match ( $logo_info[2] ) {
			IMAGETYPE_JPEG => imagecreatefromjpeg( $logo_path ),
			IMAGETYPE_PNG  => imagecreatefrompng( $logo_path ),
			IMAGETYPE_WEBP => function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $logo_path ) : false,
			default         => false,
		};

		if ( false === $logo_src ) {
			return;
		}

		$max_h  = 100;
		$orig_w = imagesx( $logo_src );
		$orig_h = imagesy( $logo_src );
		$scale  = $max_h / $orig_h;
		$new_w  = (int) ( $orig_w * $scale );
		$new_h  = $max_h;
		$pos_x  = $width - $new_w - 50;
		$pos_y  = $height - $new_h - 60;

		imagecopyresampled( $image, $logo_src, $pos_x, $pos_y, 0, 0, $new_w, $new_h, $orig_w, $orig_h );
		if ( function_exists( 'imagedestroy' ) ) {
			imagedestroy( $logo_src );
		}
	}

	/**
	 * @param \GdImage|resource $image
	 */
	private function apply_title( $image, string $title, string $font_jp, string $font_latin, int $width, int $height ): bool {
		if ( '' === $font_jp || ! Asset_Syncer::is_valid_font( $font_jp ) ) {
			error_log( 'Node SEO Tools: no valid Japanese font resolved. Title skipped.' );
			return false;
		}
		if ( '' === $font_latin || ! Asset_Syncer::is_valid_font( $font_latin ) ) {
			// Fallback: if Inter cannot be resolved, draw all glyphs with Japanese font.
			$font_latin = $font_jp;
		}

		if ( ! function_exists( 'imagettftext' ) ) {
			error_log( 'Node SEO Tools: FreeType is not available. Title skipped.' );
			return false;
		}

		$title        = self::normalize_text( $title );
		$text_color   = imagecolorallocate( $image, 56, 56, 56 );
		$safe         = $this->get_instagram_safe_rect( $width, $height );
		$text_frame   = $this->get_content_text_frame( $safe );
		$line_spacing = 1.34;
		$title_box_h  = max( 80, $safe['h'] - self::CONTENT_BOTTOM_PAD );

		$layout = $this->select_title_layout(
			$title,
			$font_jp,
			$font_latin,
			$text_frame['w'],
			$title_box_h,
			$line_spacing
		);
		if ( null === $layout ) {
			return false;
		}

		$font_size    = $layout['font_size'];
		$lines        = $layout['lines'];
		$line_height  = $layout['line_height'];
		$tracking_px  = $layout['tracking_px'] ?? 0.0;
		$total_height = count( $lines ) * $line_height;
		$block_top    = $this->compute_block_top( $safe, $total_height );
		$y            = $block_top + ( $line_height * 0.88 );

		foreach ( $lines as $line ) {
			$line_tracking = ( 1 === count( $lines ) ) ? $tracking_px : 0.0;
			$line_width    = $this->measure_line_width( $line, $font_size, $font_jp, $font_latin, $line_tracking );
			$x             = $text_frame['x'] + ( ( $text_frame['w'] - $line_width ) / 2 );
			$this->draw_mixed_line( $image, $line, $font_size, (float) $x, (float) $y, $text_color, $font_jp, $font_latin, $line_tracking );
			$y += $line_height;
		}

		return true;
	}

	/**
	 * 4 行以内に収まらない場合: 背景＋ロゴ＋ Inter で Luminous Core を大きく表示。
	 *
	 * @param array{background:string,logo:string,font_jp:string,font_latin:string} $assets
	 * @param \GdImage|resource $image
	 */
	private function apply_brand_fallback( $image, array $assets, int $width, int $height ): void {
		if ( ! function_exists( 'imagettftext' ) ) {
			return;
		}

		$inter = Asset_Syncer::resolve_inter_font();
		if ( '' === $inter ) {
			error_log( 'Node SEO Tools: Inter font unavailable for brand fallback.' );
			return;
		}

		$safe       = $this->get_instagram_safe_rect( $width, $height );
		$text_frame = $this->get_content_text_frame( $safe );
		$text_color = imagecolorallocate( $image, 56, 56, 56 );
		$logo_path  = $assets['logo'] ?? '';
		$logo_h     = Asset_Syncer::is_valid_image( $logo_path ) ? self::BRAND_LOGO_HEIGHT : 0;
		$logo_gap   = $logo_h > 0 ? self::BRAND_LOGO_GAP : 0;

		$font_size = self::BRAND_TEXT_MIN_FONT;
		for ( $size = self::BRAND_TEXT_MAX_FONT; $size >= self::BRAND_TEXT_MIN_FONT; $size -= 2 ) {
			if ( $this->measure_brand_wordmark_width( self::BRAND_DISPLAY_NAME, $size, $inter, true ) <= $text_frame['w'] ) {
				$font_size = $size;
				break;
			}
		}

		$url_font_size = max( self::BRAND_URL_MIN_FONT, (int) round( $font_size * self::BRAND_URL_FONT_RATIO ) );
		$url_gap       = (int) round( $font_size * self::BRAND_URL_GAP_RATIO );
		$brand_bbox    = imagettfbbox( $font_size, 0, $inter, 'A' );
		$url_bbox      = imagettfbbox( $url_font_size, 0, $inter, 'A' );
		$brand_line_h  = is_array( $brand_bbox ) ? ( $brand_bbox[1] - $brand_bbox[7] ) * 1.12 : (float) $font_size * 1.12;
		$url_line_h    = is_array( $url_bbox ) ? ( $url_bbox[1] - $url_bbox[7] ) * 1.1 : (float) $url_font_size * 1.1;
		$brand_w       = $this->measure_brand_wordmark_width( self::BRAND_DISPLAY_NAME, $font_size, $inter, true );
		$url_w         = $this->measure_brand_wordmark_width( self::BRAND_SITE_URL, $url_font_size, $inter, false );
		$block_h       = $logo_h + $logo_gap + $brand_line_h + $url_gap + $url_line_h;
		$block_top     = $this->compute_block_top( $safe, $block_h );

		if ( $logo_h > 0 ) {
			$this->draw_centered_logo_lockup( $image, $logo_path, $safe, $block_top, self::BRAND_LOGO_HEIGHT );
		}

		$brand_y = $block_top + $logo_h + $logo_gap + ( $brand_line_h * 0.88 );
		$brand_x = $text_frame['x'] + ( ( $text_frame['w'] - $brand_w ) / 2 );
		$this->draw_brand_wordmark(
			$image,
			self::BRAND_DISPLAY_NAME,
			$font_size,
			(float) $brand_x,
			(float) $brand_y,
			$text_color,
			$inter,
			true
		);

		$url_color = imagecolorallocate( $image, 88, 88, 88 );
		$url_y     = $block_top + $logo_h + $logo_gap + $brand_line_h + $url_gap + ( $url_line_h * 0.88 );
		$url_x     = $text_frame['x'] + ( ( $text_frame['w'] - $url_w ) / 2 );
		$this->draw_brand_wordmark(
			$image,
			self::BRAND_SITE_URL,
			$url_font_size,
			(float) $url_x,
			(float) $url_y,
			$url_color,
			$inter,
			false
		);
	}

	/**
	 * テキスト描画領域（セーフゾーン内・左右インセット済み）。
	 *
	 * @param array{x:int,y:int,w:int,h:int} $safe
	 * @return array{x:int,y:int,w:int,h:int}
	 */
	private function get_content_text_frame( array $safe ): array {
		$inset = (int) round( $safe['w'] * ( 1 - self::CONTENT_TEXT_WIDTH_RATIO ) / 2 );

		return array(
			'x' => $safe['x'] + $inset,
			'y' => $safe['y'],
			'w' => max( 280, $safe['w'] - ( $inset * 2 ) ),
			'h' => $safe['h'],
		);
	}

	/**
	 * ロゴ＋テキストの縦積みブロックをセーフゾーン内に配置する起点 Y。
	 *
	 * @param array{x:int,y:int,w:int,h:int} $safe
	 */
	private function compute_block_top( array $safe, float $block_h ): int {
		$free = max( 0.0, (float) $safe['h'] - $block_h );

		return $safe['y'] + (int) round( $free * self::LAYOUT_VERTICAL_BIAS );
	}

	/**
	 * セーフゾーン中央にロゴを配置し、上部グラデーションを白で切り抜く。
	 *
	 * @param array{x:int,y:int,w:int,h:int} $safe
	 * @param \GdImage|resource $image
	 */
	private function draw_centered_logo_lockup( $image, string $logo_path, array $safe, int $top_y, int $icon_h ): void {
		if ( ! Asset_Syncer::is_valid_image( $logo_path ) ) {
			return;
		}

		$logo_src = $this->load_image_resource( $logo_path );
		if ( false === $logo_src ) {
			return;
		}

		$orig_w = imagesx( $logo_src );
		$orig_h = imagesy( $logo_src );
		$icon_w = (int) round( $orig_w * ( $icon_h / $orig_h ) );
		$icon_x = $safe['x'] + (int) round( ( $safe['w'] - $icon_w ) / 2 );
		$icon_y = max( self::SNS_SAFE_TOP - 8, $top_y );

		$cut_w = $icon_w + ( self::LOGO_CUTOUT_PAD_X * 2 );
		$cut_h = $icon_h + ( self::LOGO_CUTOUT_PAD_Y * 2 );
		$white = imagecolorallocate( $image, 255, 255, 255 );
		imagefilledrectangle(
			$image,
			$icon_x - self::LOGO_CUTOUT_PAD_X,
			$icon_y - self::LOGO_CUTOUT_PAD_Y,
			$icon_x - self::LOGO_CUTOUT_PAD_X + $cut_w,
			$icon_y - self::LOGO_CUTOUT_PAD_Y + $cut_h,
			$white
		);

		imagecopyresampled( $image, $logo_src, $icon_x, $icon_y, 0, 0, $icon_w, $icon_h, $orig_w, $orig_h );
		if ( function_exists( 'imagedestroy' ) ) {
			imagedestroy( $logo_src );
		}
	}

	/**
	 * @return \GdImage|resource|false
	 */
	private function load_image_resource( string $path ) {
		$info = getimagesize( $path );
		if ( ! is_array( $info ) ) {
			return false;
		}

		return match ( $info[2] ) {
			IMAGETYPE_JPEG => imagecreatefromjpeg( $path ),
			IMAGETYPE_PNG  => imagecreatefrompng( $path ),
			IMAGETYPE_WEBP => function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $path ) : false,
			default         => false,
		};
	}

	/**
	 * Instagram 1:1 中央クロップ（1200x630 なら x=285, w=630）の内側コンテンツ領域。
	 *
	 * @return array{x:int,y:int,w:int,h:int}
	 */
	private function get_instagram_safe_rect( int $width, int $height ): array {
		$square = min( $width, $height );
		$origin_x = (int) round( ( $width - $square ) / 2 );
		$origin_y = (int) round( ( $height - $square ) / 2 );
		$pad      = self::INSTAGRAM_SQUARE_PAD;

		return array(
			'x' => $origin_x + $pad,
			'y' => $origin_y + $pad,
			'w' => max( 320, $square - ( $pad * 2 ) ),
			'h' => max( 200, $square - ( $pad * 2 ) ),
		);
	}

	/**
	 * Pick the largest font size that minimizes line count and avoids orphan lines.
	 *
	 * @return array{font_size:int,lines:array<int,string>,line_height:float,tracking_px:float}|null
	 */
	private function select_title_layout(
		string $title,
		string $font_jp,
		string $font_latin,
		int $max_width,
		int $title_box_h,
		float $line_spacing
	): ?array {
		$max_font = $this->get_dynamic_title_max_font_size( $title );

		$single_line = $this->try_single_line_layout(
			$title,
			$font_jp,
			$font_latin,
			$max_width,
			$title_box_h,
			$line_spacing,
			$max_font
		);
		if ( null !== $single_line ) {
			return $single_line;
		}

		$best = null;

		for ( $size = $max_font; $size >= self::TITLE_MIN_FONT_SIZE_FALLBACK; $size -= 2 ) {
			$bbox_char = imagettfbbox( $size, 0, $font_latin, 'A' );
			if ( ! is_array( $bbox_char ) ) {
				continue;
			}
			$line_height = ( $bbox_char[1] - $bbox_char[7] ) * $line_spacing;

			$wrap  = $this->wrap_text_with_rules_detailed( $title, $size, $font_jp, $font_latin, $max_width );
			$lines = $wrap['lines'];
			if (
				! $this->is_valid_wrapped_lines( $lines, $wrap['remaining'], $size, $font_jp, $font_latin, $max_width )
				|| $this->has_unnatural_breaks( $title, $lines )
				|| $this->violates_punctuation_break_rules( $title, $lines )
				|| $this->has_orphan_lines( $lines )
				|| ( count( $lines ) * $line_height ) > $title_box_h
			) {
				continue;
			}

			if ( null === $best ) {
				$best = array(
					'font_size'   => $size,
					'lines'       => $lines,
					'line_height' => $line_height,
					'tracking_px' => 0.0,
				);
				continue;
			}

			$best_line_count = count( $best['lines'] );
			$new_line_count  = count( $lines );

			if ( $new_line_count < $best_line_count ) {
				$best = array(
					'font_size'   => $size,
					'lines'       => $lines,
					'line_height' => $line_height,
					'tracking_px' => 0.0,
				);
				continue;
			}

			if ( $new_line_count === $best_line_count && $size > $best['font_size'] ) {
				$best = array(
					'font_size'   => $size,
					'lines'       => $lines,
					'line_height' => $line_height,
					'tracking_px' => 0.0,
				);
			}
		}

		return $best;
	}

	/**
	 * 1 行に収められるなら最優先。収まらない場合のみトラッキング圧縮で幅内に調整する。
	 *
	 * @return array{font_size:int,lines:array<int,string>,line_height:float,tracking_px:float}|null
	 */
	private function try_single_line_layout(
		string $title,
		string $font_jp,
		string $font_latin,
		int $max_width,
		int $title_box_h,
		float $line_spacing,
		int $max_font
	): ?array {
		$best = null;

		for ( $size = $max_font; $size >= self::TITLE_MIN_FONT_SIZE_FALLBACK; $size -= 2 ) {
			$bbox_char = imagettfbbox( $size, 0, $font_latin, 'A' );
			if ( ! is_array( $bbox_char ) ) {
				continue;
			}
			$line_height = ( $bbox_char[1] - $bbox_char[7] ) * $line_spacing;
			if ( $line_height > $title_box_h ) {
				continue;
			}

			$tracking = $this->calculate_fit_tracking( $title, $size, $font_jp, $font_latin, $max_width );
			if ( null === $tracking ) {
				continue;
			}

			$candidate = array(
				'font_size'   => $size,
				'lines'       => array( $title ),
				'line_height' => $line_height,
				'tracking_px' => $tracking,
			);

			if ( null === $best ) {
				$best = $candidate;
				continue;
			}

			$best_pressure = abs( (float) $best['tracking_px'] );
			$cand_pressure = abs( (float) $tracking );

			// 字間圧縮が弱い（自然なカーニングに近い）組み合わせを優先する。
			if ( $cand_pressure + 0.01 < $best_pressure ) {
				$best = $candidate;
				continue;
			}

			if ( abs( $cand_pressure - $best_pressure ) <= 0.01 && $size > $best['font_size'] ) {
				$best = $candidate;
			}
		}

		return $best;
	}

	/**
	 * 文字数に応じたタイトル最大フォント（カーニング自動調整と両立する上限）。
	 */
	private function get_dynamic_title_max_font_size( string $title ): int {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
		$length = max( 1, $length );

		$cap = self::TITLE_MAX_FONT_SIZE;
		if ( $length <= 18 ) {
			$cap = self::TITLE_MAX_FONT_SIZE;
		} elseif ( $length <= 28 ) {
			$cap = 52;
		} elseif ( $length <= 40 ) {
			$cap = 48;
		} elseif ( $length <= 55 ) {
			$cap = 44;
		} elseif ( $length <= 72 ) {
			$cap = 40;
		} elseif ( $length <= 90 ) {
			$cap = 36;
		} else {
			$cap = 32;
		}

		return max( self::TITLE_MIN_FONT_SIZE_FALLBACK, min( self::TITLE_MAX_FONT_SIZE, $cap ) );
	}

	/**
	 * 1 行をセーフ幅に収めるための字間調整量（負値で詰める）。過剰圧縮は null。
	 */
	private function calculate_fit_tracking(
		string $line,
		int $font_size,
		string $font_jp,
		string $font_latin,
		int $max_width
	): ?float {
		$natural = $this->measure_line_width( $line, $font_size, $font_jp, $font_latin );
		if ( $natural <= $max_width ) {
			return 0.0;
		}

		$chars = function_exists( 'mb_str_split' ) ? mb_str_split( $line ) : preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
		$gaps  = is_array( $chars ) ? max( 0, count( $chars ) - 1 ) : 0;
		if ( $gaps <= 0 ) {
			return null;
		}

		$needed_per_gap  = -( $natural - $max_width ) / $gaps;
		$max_compression = -$font_size * self::SINGLE_LINE_MAX_TRACKING_RATIO;
		if ( $needed_per_gap < $max_compression ) {
			return null;
		}

		if ( $this->measure_line_width( $line, $font_size, $font_jp, $font_latin, $needed_per_gap ) > $max_width ) {
			return null;
		}

		return $needed_per_gap;
	}

	private function title_has_punctuation( string $text ): bool {
		$chars = function_exists( 'mb_str_split' ) ? mb_str_split( $text ) : preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return false;
		}
		foreach ( $chars as $ch ) {
			if ( $this->is_line_break_after_char( $ch ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 句読点を含むタイトルでは、最終行以外は句読点で改行していること。
	 *
	 * @param array<int, string> $lines
	 */
	private function violates_punctuation_break_rules( string $full_text, array $lines ): bool {
		if ( ! $this->title_has_punctuation( $full_text ) || count( $lines ) <= 1 ) {
			return false;
		}

		$last_index = count( $lines ) - 1;
		foreach ( $lines as $idx => $line ) {
			if ( $idx === $last_index ) {
				continue;
			}
			$trimmed = trim( $line );
			if ( '' === $trimmed ) {
				continue;
			}
			if ( ! $this->is_line_break_after_char( mb_substr( $trimmed, -1, 1 ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, string> $lines
	 * @return array<int, string>
	 */
	private function merge_orphan_tail_lines( array $lines ): array {
		while ( count( $lines ) > 1 ) {
			$last    = trim( (string) end( $lines ) );
			$length  = $this->visible_line_length( $last );
			if ( $length >= self::MIN_ORPHAN_LINE_CHARS ) {
				break;
			}
			if ( $this->is_line_break_after_char( mb_substr( $last, -1, 1 ) ) ) {
				break;
			}
			$prev = trim( (string) $lines[ count( $lines ) - 2 ] );
			if ( '' !== $prev && $this->is_line_break_after_char( mb_substr( $prev, -1, 1 ) ) ) {
				break;
			}
			$tail = (string) array_pop( $lines );
			$lines[ count( $lines ) - 1 ] .= $tail;
		}
		return $lines;
	}

	/**
	 * @param array<int, string> $lines
	 */
	private function has_orphan_lines( array $lines ): bool {
		if ( count( $lines ) <= 1 ) {
			return false;
		}

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed ) {
				continue;
			}
			$length = $this->visible_line_length( $trimmed );
			if ( $length > 0 && $length < self::MIN_ORPHAN_LINE_CHARS ) {
				if ( $this->is_line_break_after_char( mb_substr( $trimmed, -1, 1 ) ) ) {
					continue;
				}
				return true;
			}
		}

		return false;
	}

	private function visible_line_length( string $line ): int {
		$visible = preg_replace( '/\s+/u', '', $line );
		if ( null === $visible || '' === $visible ) {
			return 0;
		}
		return function_exists( 'mb_strlen' ) ? mb_strlen( $visible ) : strlen( $visible );
	}

	private function is_line_break_after_char( string $ch ): bool {
		if ( '' === $ch ) {
			return false;
		}
		return false !== mb_strpos( self::PUNCTUATION_BREAK_CHARS, $ch );
	}

	private function remaining_has_punctuation( string $text ): bool {
		return $this->title_has_punctuation( $text );
	}

	private static function normalize_text( string $text ): string {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	}

	private function wrap_text_with_rules(
		string $text,
		int $font_size,
		string $font_jp,
		string $font_latin,
		int $max_width
	): array {
		return $this->wrap_text_with_rules_detailed( $text, $font_size, $font_jp, $font_latin, $max_width )['lines'];
	}

	/**
	 * @return array{lines:array<int,string>,remaining:string}
	 */
	private function wrap_text_with_rules_detailed(
		string $text,
		int $font_size,
		string $font_jp,
		string $font_latin,
		int $max_width
	): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array(
				'lines'     => array(),
				'remaining' => '',
			);
		}

		// 短いタイトル: 1行に収まるなら句読点をそのまま保持して返す。
		if ( $this->measure_line_width( $text, $font_size, $font_jp, $font_latin ) <= $max_width ) {
			return array(
				'lines'     => array( $text ),
				'remaining' => '',
			);
		}

		$lines            = array();
		$remaining        = $text;
		$has_punctuation  = $this->title_has_punctuation( $text );

		while ( '' !== $remaining && count( $lines ) < self::MAX_TITLE_LINES ) {
			$is_last_line_slot = ( count( $lines ) >= self::MAX_TITLE_LINES - 1 );
			$force_punct_break = $has_punctuation
				&& ! $is_last_line_slot
				&& $this->remaining_has_punctuation( $remaining );

			$segment = $this->take_next_line_segment(
				$remaining,
				$font_size,
				$font_jp,
				$font_latin,
				$max_width,
				$force_punct_break
			);

			if ( '' === $segment['line'] ) {
				break;
			}

			$lines[]   = $segment['line'];
			$remaining = $segment['remaining'];
		}

		$lines = $this->fix_line_start_forbidden( $lines );
		$lines = $this->merge_orphan_tail_lines( $lines );
		$lines = array_map( static fn( string $line ): string => trim( $line ), $lines );
		$lines = array_values( array_filter( $lines, static fn( string $line ): bool => '' !== $line ) );

		return array(
			'lines'     => $lines,
			'remaining' => trim( $remaining ),
		);
	}

	/**
	 * @param array<int, string> $lines
	 */
	private function is_valid_wrapped_lines(
		array $lines,
		string $remaining,
		int $font_size,
		string $font_jp,
		string $font_latin,
		int $max_width
	): bool {
		if ( '' !== $remaining || count( $lines ) > self::MAX_TITLE_LINES ) {
			return false;
		}
		foreach ( $lines as $line ) {
			if ( $this->measure_line_width( $line, $font_size, $font_jp, $font_latin ) > $max_width ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 長いタイトル向け: 幅内で最長の行を取り、句読点位置を優先して改行する。
	 *
	 * @return array{line:string,remaining:string}
	 */
	private function take_next_line_segment(
		string $remaining,
		int $font_size,
		string $font_jp,
		string $font_latin,
		int $max_width,
		bool $force_punct_break = false
	): array {
		$length       = function_exists( 'mb_strlen' ) ? mb_strlen( $remaining ) : strlen( $remaining );
		$max_fit      = 0;
		$punct_fit    = 0;
		$space_fit    = 0;

		for ( $i = 1; $i <= $length; $i++ ) {
			$prefix = function_exists( 'mb_substr' ) ? mb_substr( $remaining, 0, $i ) : substr( $remaining, 0, $i );
			if ( $this->measure_line_width( $prefix, $font_size, $font_jp, $font_latin ) > $max_width ) {
				break;
			}

			$max_fit = $i;
			$last    = function_exists( 'mb_substr' ) ? mb_substr( $prefix, -1, 1 ) : substr( $prefix, -1 );
			$next    = function_exists( 'mb_substr' ) ? mb_substr( $remaining, $i, 1 ) : substr( $remaining, $i, 1 );
			if (
				$this->is_line_break_after_char( $last )
				&& ( '' === $next || $this->is_natural_break_boundary( $last, $next ) )
			) {
				// 句読点強制時は最初の句読点で区切り（日本語の節単位）、通常時は最後の句読点。
				if ( $force_punct_break ) {
					if ( $punct_fit <= 0 ) {
						$punct_fit = $i;
					}
				} else {
					$punct_fit = $i;
				}
			} elseif ( preg_match( '/\s/u', $last ) ) {
				$space_fit = $i;
			}
		}

		if ( $max_fit <= 0 ) {
			if ( $force_punct_break ) {
				return array(
					'line'      => '',
					'remaining' => $remaining,
				);
			}
			$prefix = function_exists( 'mb_substr' ) ? mb_substr( $remaining, 0, 1 ) : substr( $remaining, 0, 1 );
			return array(
				'line'      => $prefix,
				'remaining' => function_exists( 'mb_substr' ) ? mb_substr( $remaining, 1 ) : substr( $remaining, 1 ),
			);
		}

		if ( $force_punct_break ) {
			if ( $punct_fit <= 0 ) {
				return array(
					'line'      => '',
					'remaining' => $remaining,
				);
			}
			$break_at = $punct_fit;
		} else {
			$break_at = $max_fit;
			if ( $punct_fit > 0 ) {
				$break_at = $punct_fit;
			} elseif ( $this->would_split_latin_word_at( $remaining, $max_fit ) ) {
				$break_at = $this->rightmost_space_before( $remaining, $max_fit ) ?? $max_fit;
			}
		}
		$break_at = $this->refine_break_at( $remaining, $break_at );
		$line     = function_exists( 'mb_substr' ) ? mb_substr( $remaining, 0, $break_at ) : substr( $remaining, 0, $break_at );
		$line     = $this->adjust_break_away_from_latin_word( $line, $remaining, $font_size, $font_jp, $font_latin, $max_width );
		$line_len = function_exists( 'mb_strlen' ) ? mb_strlen( $line ) : strlen( $line );
		$rest     = function_exists( 'mb_substr' ) ? mb_substr( $remaining, $line_len ) : substr( $remaining, $line_len );

		// 空白での改行時は行末・次行頭の空白を除去し、語の欠落を防ぐ。
		if ( $break_at > 0 && $break_at <= $length ) {
			$boundary = function_exists( 'mb_substr' ) ? mb_substr( $remaining, $break_at - 1, 1 ) : substr( $remaining, $break_at - 1, 1 );
			if ( preg_match( '/\s/u', $boundary ) ) {
				$line = rtrim( $line );
				$rest = ltrim( $rest );
			}
		}

		return array(
			'line'      => $line,
			'remaining' => $rest,
		);
	}

	/**
	 * 英数字語の途中で折り返さないよう、直前の句読点・空白まで戻す。
	 */
	private function adjust_break_away_from_latin_word(
		string $line,
		string $full_remaining,
		int $font_size,
		string $font_jp,
		string $font_latin,
		int $max_width
	): string {
		if ( '' === $line ) {
			return $line;
		}

		$line_len = function_exists( 'mb_strlen' ) ? mb_strlen( $line ) : strlen( $line );
		$next_ch  = function_exists( 'mb_substr' ) ? mb_substr( $full_remaining, $line_len, 1 ) : substr( $full_remaining, $line_len, 1 );
		$last_ch  = function_exists( 'mb_substr' ) ? mb_substr( $line, -1, 1 ) : substr( $line, -1 );

		if (
			! preg_match( '/[A-Za-z0-9]/u', $last_ch )
			|| ! preg_match( '/[A-Za-z0-9]/u', $next_ch )
		) {
			return $line;
		}

		$line_length = function_exists( 'mb_strlen' ) ? mb_strlen( $line ) : strlen( $line );
		for ( $i = $line_length; $i > 0; $i-- ) {
			$prefix = function_exists( 'mb_substr' ) ? mb_substr( $line, 0, $i ) : substr( $line, 0, $i );
			if ( '' === $prefix ) {
				break;
			}
			if ( $this->measure_line_width( $prefix, $font_size, $font_jp, $font_latin ) > $max_width ) {
				continue;
			}
			$boundary = function_exists( 'mb_substr' ) ? mb_substr( $prefix, -1, 1 ) : substr( $prefix, -1 );
			if ( $this->is_line_break_after_char( $boundary ) || preg_match( '/\s/u', $boundary ) ) {
				return $prefix;
			}
		}

		return $line;
	}

	private function is_latin_char( string $ch ): bool {
		return (bool) preg_match( '/[A-Za-z0-9]/u', $ch );
	}

	private function is_cjk_char( string $ch ): bool {
		return (bool) preg_match( '/[\p{Hiragana}\p{Katakana}\p{Han}]/u', $ch );
	}

	/**
	 * @param array<int, string> $lines
	 */
	private function has_unnatural_breaks( string $full_text, array $lines ): bool {
		if ( count( $lines ) <= 1 ) {
			return false;
		}

		$offset = 0;
		foreach ( $lines as $idx => $line ) {
			$offset += function_exists( 'mb_strlen' ) ? mb_strlen( $line ) : strlen( $line );
			if ( $idx >= count( $lines ) - 1 ) {
				break;
			}
			$last = function_exists( 'mb_substr' ) ? mb_substr( $full_text, $offset - 1, 1 ) : substr( $full_text, $offset - 1, 1 );
			$next = function_exists( 'mb_substr' ) ? mb_substr( $full_text, $offset, 1 ) : substr( $full_text, $offset, 1 );
			if ( $this->is_natural_break_boundary( $last, $next ) ) {
				continue;
			}
			return true;
		}

		return false;
	}

	private function is_natural_break_boundary( string $last, string $next ): bool {
		if ( $this->is_line_break_after_char( $last ) ) {
			return true;
		}
		if ( preg_match( '/\s/u', $last ) || preg_match( '/\s/u', $next ) ) {
			return true;
		}
		if ( $this->is_latin_char( $last ) && $this->is_latin_char( $next ) ) {
			return false;
		}
		if ( $this->is_cjk_char( $last ) && $this->is_cjk_char( $next ) ) {
			return false;
		}
		return true;
	}

	private function refine_break_at( string $text, int $break_at ): int {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		while ( $break_at > 1 && $break_at < $length ) {
			$last = function_exists( 'mb_substr' ) ? mb_substr( $text, $break_at - 1, 1 ) : substr( $text, $break_at - 1, 1 );
			$next = function_exists( 'mb_substr' ) ? mb_substr( $text, $break_at, 1 ) : substr( $text, $break_at, 1 );
			if ( $this->is_natural_break_boundary( $last, $next ) ) {
				break;
			}
			$break_at--;
		}
		return max( 1, $break_at );
	}

	private function would_split_latin_word_at( string $text, int $break_at ): bool {
		if ( $break_at <= 0 ) {
			return false;
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $break_at >= $length ) {
			return false;
		}
		$last = function_exists( 'mb_substr' ) ? mb_substr( $text, $break_at - 1, 1 ) : substr( $text, $break_at - 1, 1 );
		$next = function_exists( 'mb_substr' ) ? mb_substr( $text, $break_at, 1 ) : substr( $text, $break_at, 1 );
		return $this->is_latin_char( $last ) && $this->is_latin_char( $next );
	}

	private function rightmost_space_before( string $text, int $max_pos ): ?int {
		$limit = min( $max_pos, function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) );
		for ( $i = $limit; $i > 0; $i-- ) {
			$ch = function_exists( 'mb_substr' ) ? mb_substr( $text, $i - 1, 1 ) : substr( $text, $i - 1, 1 );
			if ( preg_match( '/\s/u', $ch ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * 文字ペアごとにカーニング量を決める。CJK はフォント既定幅を尊重し、過剰な詰めを避ける。
	 */
	private function get_pair_kerning_adjustment( string $prev, string $ch, int $font_size ): float {
		if ( '' === $prev ) {
			return 0.0;
		}
		if ( preg_match( '/\s/u', $prev ) || preg_match( '/\s/u', $ch ) ) {
			return 0.0;
		}

		$unit       = $font_size / 100.0;
		$prev_latin = $this->is_latin_char( $prev );
		$curr_latin = $this->is_latin_char( $ch );

		if ( ! $prev_latin && ! $curr_latin ) {
			if ( $this->is_line_break_after_char( $ch ) ) {
				return -0.4 * $unit;
			}
			return 0.0;
		}

		if ( $prev_latin && $curr_latin ) {
			$tight_after = '.,:;!?%)]}›';
			if ( false !== strpos( $tight_after, $ch ) ) {
				return -1.0 * $unit;
			}
			if ( '.' === $prev || '.' === $ch ) {
				return -0.6 * $unit;
			}
			if ( preg_match( '/[0-9]/u', $prev ) && preg_match( '/[0-9]/u', $ch ) ) {
				return 0.2 * $unit;
			}
			if ( preg_match( '/[A-Z]/u', $prev ) && preg_match( '/[A-Z]/u', $ch ) ) {
				return 1.0 * $unit;
			}
			if ( preg_match( '/[ilI1fjrt]/u', $ch ) ) {
				return 0.3 * $unit;
			}
			return 0.7 * $unit;
		}

		if ( $this->is_line_break_after_char( $prev ) || $this->is_line_break_after_char( $ch ) ) {
			return 0.4 * $unit;
		}

		return 0.9 * $unit;
	}

	private function tokenize_text( string $text ): array {
		$chars = function_exists( 'mb_str_split' ) ? mb_str_split( $text ) : preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return array( $text );
		}

		$tokens = array();
		$buffer = '';
		$type   = null;
		foreach ( $chars as $idx => $ch ) {
			$is_latin = (bool) preg_match( '/[A-Za-z0-9]/u', $ch );
			$is_space = (bool) preg_match( '/\s/u', $ch );
			$ch_type  = $is_latin ? 'latin' : 'other';

			if ( '.' === $ch && 'latin' === $type ) {
				$next = $chars[ $idx + 1 ] ?? '';
				if ( preg_match( '/[0-9]$/u', $buffer ) && is_string( $next ) && preg_match( '/^[0-9]/u', $next ) ) {
					$buffer .= $ch;
					continue;
				}
			}

			if ( $is_space && 'latin' === $type ) {
				$next = $chars[ $idx + 1 ] ?? '';
				if ( is_string( $next ) && preg_match( '/[A-Za-z0-9]/u', $next ) ) {
					$buffer .= $ch;
					continue;
				}
			}

			if ( null === $type ) {
				$type   = $ch_type;
				$buffer = $ch;
				continue;
			}
			if ( $type === $ch_type && 'latin' === $type ) {
				$buffer .= $ch;
				continue;
			}
			$tokens[] = $buffer;
			$buffer   = $ch;
			$type     = $ch_type;
		}
		if ( '' !== $buffer ) {
			$tokens[] = $buffer;
		}
		return $tokens;
	}

	private function split_long_token_for_width(
		string $token,
		int $font_size,
		string $font_jp,
		string $font_latin,
		int $max_width
	): array {
		$token_width = $this->measure_line_width( $token, $font_size, $font_jp, $font_latin );
		if ( $token_width <= $max_width ) {
			return array( $token );
		}

		$chars = function_exists( 'mb_str_split' ) ? mb_str_split( $token ) : preg_split( '//u', $token, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return array( $token );
		}

		$fragments = array();
		$current   = '';
		foreach ( $chars as $ch ) {
			$test = $current . $ch;
			$w    = $this->measure_line_width( $test, $font_size, $font_jp, $font_latin );
			if ( '' !== $current && $w > $max_width ) {
				$fragments[] = $current;
				$current     = $ch;
			} else {
				$current = $test;
			}
		}
		if ( '' !== $current ) {
			$fragments[] = $current;
		}
		return $fragments;
	}

	private function fix_line_start_forbidden( array $lines ): array {
		$forbidden_start = '、。，．！？!?)）]｝〕〉》」』】';
		for ( $i = 1; $i < count( $lines ); $i++ ) {
			if ( '' === $lines[ $i ] || '' === $lines[ $i - 1 ] ) {
				continue;
			}
			$first = mb_substr( $lines[ $i ], 0, 1 );
			if ( false !== mb_strpos( $forbidden_start, $first ) ) {
				$lines[ $i - 1 ] .= $first;
				$lines[ $i ]      = mb_substr( $lines[ $i ], 1 );
			}
		}
		return $lines;
	}

	private function measure_line_width(
		string $line,
		int $font_size,
		string $font_jp,
		string $font_latin,
		float $tracking_px = 0.0
	): float {
		$chars = function_exists( 'mb_str_split' ) ? mb_str_split( $line ) : preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return 0.0;
		}
		$width = 0.0;
		$count = count( $chars );
		$line_font = '';
		if ( self::UNIFY_ALL_LINE_WEIGHT ) {
			$line_font = $font_jp;
		} elseif ( self::UNIFY_MIXED_LINE_WEIGHT && $this->has_mixed_scripts( $line ) ) {
			$line_font = $font_jp;
		}
		foreach ( $chars as $idx => $ch ) {
			$font = '' !== $line_font ? $line_font : ( preg_match( '/[A-Za-z0-9]/u', $ch ) ? $font_latin : $font_jp );
			$bbox = imagettfbbox( $font_size, 0, $font, $ch );
			if ( is_array( $bbox ) ) {
				$width += ( $bbox[2] - $bbox[0] );
			}
			if ( $idx < $count - 1 ) {
				$width += $this->get_pair_kerning_adjustment( $ch, $chars[ $idx + 1 ], $font_size );
				$width += $tracking_px;
			}
		}

		// Bold 多 pass 描画分を幅計測に含める（Instagram クロップ対策）。
		if ( $width > 0.0 && count( self::BOLD_PIXEL_OFFSETS ) > 1 ) {
			$max_offset = 0.0;
			foreach ( self::BOLD_PIXEL_OFFSETS as $offset ) {
				$max_offset = max( $max_offset, (float) $offset[0] );
			}
			$width += $max_offset;
		}

		return $width;
	}

	/**
	 * @param \GdImage|resource $image
	 */
	private function draw_mixed_line(
		$image,
		string $line,
		int $font_size,
		float $x,
		float $y,
		int $color,
		string $font_jp,
		string $font_latin,
		float $tracking_px = 0.0
	): void {
		$chars = function_exists( 'mb_str_split' ) ? mb_str_split( $line ) : preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return;
		}
		$line_font = '';
		if ( self::UNIFY_ALL_LINE_WEIGHT ) {
			$line_font = $font_jp;
		} elseif ( self::UNIFY_MIXED_LINE_WEIGHT && $this->has_mixed_scripts( $line ) ) {
			$line_font = $font_jp;
		}
		$count = count( $chars );
		foreach ( $chars as $idx => $ch ) {
			$font = '' !== $line_font ? $line_font : ( preg_match( '/[A-Za-z0-9]/u', $ch ) ? $font_latin : $font_jp );
			$bbox = imagettfbbox( $font_size, 0, $font, $ch );
			if ( ! is_array( $bbox ) ) {
				continue;
			}
			foreach ( self::BOLD_PIXEL_OFFSETS as $offset ) {
				imagettftext(
					$image,
					$font_size,
					0,
					(int) round( $x + $offset[0] ),
					(int) round( $y + $offset[1] ),
					$color,
					$font,
					$ch
				);
			}
			$x += ( $bbox[2] - $bbox[0] );
			if ( $idx < $count - 1 ) {
				$x += $this->get_pair_kerning_adjustment( $ch, $chars[ $idx + 1 ], $font_size );
				$x += $tracking_px;
			}
		}
	}

	private function has_mixed_scripts( string $line ): bool {
		$has_latin = (bool) preg_match( '/[A-Za-z0-9]/u', $line );
		$has_jp    = (bool) preg_match( '/[\p{Hiragana}\p{Katakana}\p{Han}]/u', $line );
		return $has_latin && $has_jp;
	}

	/**
	 * 下端グラデーション内・右端揃えでブランドロゴ＋ Luminous Core を1行配置。
	 *
	 * @param \GdImage|resource $image
	 */
	private function apply_corner_brand_mark( $image, string $logo_path, int $width, int $height ): void {
		if ( ! function_exists( 'imagettftext' ) ) {
			return;
		}

		$inter = Asset_Syncer::resolve_inter_font();
		if ( '' === $inter ) {
			return;
		}

		$bar      = $this->get_bottom_gradient_bar_rect( $width, $height );
		$max_lockup_w = max( 120, $bar['w'] - ( self::CORNER_BRAND_PAD_INSIDE * 2 ) );
		$font_size    = self::CORNER_BRAND_MIN_FONT;

		for ( $size = self::CORNER_BRAND_MAX_FONT; $size >= self::CORNER_BRAND_MIN_FONT; $size-- ) {
			if ( $this->measure_corner_brand_lockup_width( $size, $inter, $logo_path ) <= $max_lockup_w ) {
				$font_size = $size;
				break;
			}
		}

		$probe    = imagettfbbox( $font_size, 0, $inter, 'Ag' );
		$descent  = is_array( $probe ) ? (float) abs( $probe[1] ) : (float) $font_size * 0.25;
		$ascent   = is_array( $probe ) ? (float) abs( $probe[7] ) : (float) $font_size * 0.75;
		$text_h   = $ascent + $descent;
		$logo_h   = (int) round( $font_size * self::CORNER_BRAND_LOGO_SCALE );
		$lockup_h = (float) max( $logo_h, $text_h );
		$lockup_w = $this->measure_corner_brand_lockup_width( $font_size, $inter, $logo_path );
		$bar_right = $bar['x'] + $bar['w'];
		$cursor    = (float) ( $bar_right - self::CORNER_BRAND_PAD_INSIDE ) - $lockup_w;
		$lockup_top = (float) $bar['y'] + ( ( $bar['h'] - $lockup_h ) / 2 );
		$baseline   = $lockup_top + $ascent;
		$name_color = imagecolorallocate( $image, 255, 252, 245 );

		if ( Asset_Syncer::is_valid_image( $logo_path ) && $logo_h > 0 ) {
			$logo_src = $this->load_image_resource( $logo_path );
			if ( false !== $logo_src ) {
				$orig_w = imagesx( $logo_src );
				$orig_h = imagesy( $logo_src );
				$logo_w = (int) round( $orig_w * ( $logo_h / $orig_h ) );
				$logo_y = (int) round( $lockup_top + ( ( $lockup_h - $logo_h ) / 2 ) );
				imagecopyresampled( $image, $logo_src, (int) round( $cursor ), $logo_y, 0, 0, $logo_w, $logo_h, $orig_w, $orig_h );
				if ( function_exists( 'imagedestroy' ) ) {
					imagedestroy( $logo_src );
				}
				$cursor += $logo_w + self::CORNER_BRAND_LOGO_GAP;
			}
		}

		$this->draw_brand_wordmark(
			$image,
			self::BRAND_DISPLAY_NAME,
			$font_size,
			$cursor,
			$baseline,
			$name_color,
			$inter,
			true
		);
	}

	/**
	 * ogp-bg.png の下端グラデーションバー領域（キャンバス座標）。
	 *
	 * @return array{x:int,y:int,w:int,h:int}
	 */
	private function get_bottom_gradient_bar_rect( int $width, int $height ): array {
		return array(
			'x' => (int) round( $width * self::BOTTOM_BAR_LEFT_RATIO ),
			'y' => (int) round( $height * self::BOTTOM_BAR_TOP_RATIO ),
			'w' => max( 320, (int) round( $width * self::BOTTOM_BAR_WIDTH_RATIO ) ),
			'h' => max( 28, (int) round( $height * self::BOTTOM_BAR_HEIGHT_RATIO ) ),
		);
	}

	private function measure_corner_brand_lockup_width( int $font_size, string $font, string $logo_path ): float {
		$width = $this->measure_brand_wordmark_width( self::BRAND_DISPLAY_NAME, $font_size, $font, true );

		if ( ! Asset_Syncer::is_valid_image( $logo_path ) ) {
			return $width;
		}

		$logo_src = $this->load_image_resource( $logo_path );
		if ( false === $logo_src ) {
			return $width;
		}

		$logo_h = (int) round( $font_size * self::CORNER_BRAND_LOGO_SCALE );
		$logo_w = (int) round( imagesx( $logo_src ) * ( $logo_h / imagesy( $logo_src ) ) );
		if ( function_exists( 'imagedestroy' ) ) {
			imagedestroy( $logo_src );
		}

		return (float) ( $logo_w + self::CORNER_BRAND_LOGO_GAP ) + $width;
	}

	/**
	 * ブランド名幅（手動ペアカーニングなし・Inter 既定メトリクス）。
	 */
	private function measure_brand_wordmark_width( string $text, int $font_size, string $font, bool $bold = false ): float {
		$bbox = imagettfbbox( $font_size, 0, $font, $text );
		if ( ! is_array( $bbox ) ) {
			return 0.0;
		}

		$width = (float) ( $bbox[2] - $bbox[0] );
		if ( ! $bold ) {
			return $width;
		}

		$max_offset = 0.0;
		foreach ( self::BOLD_PIXEL_OFFSETS as $offset ) {
			$max_offset = max( $max_offset, (float) $offset[0] );
		}

		return $width + $max_offset;
	}

	/**
	 * ブランド名・URL 描画。ブランド名は Inter 既定字間＋太字、URL はレギュラー。
	 *
	 * @param \GdImage|resource $image
	 */
	private function draw_brand_wordmark(
		$image,
		string $text,
		int $font_size,
		float $x,
		float $y,
		int $color,
		string $font,
		bool $bold = false
	): void {
		$offsets = $bold ? self::BOLD_PIXEL_OFFSETS : array( array( 0.0, 0.0 ) );

		foreach ( $offsets as $offset ) {
			imagettftext(
				$image,
				$font_size,
				0,
				(int) round( $x + $offset[0] ),
				(int) round( $y + $offset[1] ),
				$color,
				$font,
				$text
			);
		}
	}
}
