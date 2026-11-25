<?php
/**
 * Optional catalogue metadata. Legacy summaries, links and renderers stay unchanged.
 *
 * @package Node_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Library_Structured_Meta {
	/** Register once for both standalone and embedded bootstraps. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'add_meta_boxes_node_library', array( self::class, 'add_meta_box' ) );
		add_action( 'save_post_node_library', array( self::class, 'save' ) );
	}

	/** @return array<string, string> Supported store identifiers. */
	public static function stores(): array {
		return array(
			'microsoft'   => 'Microsoft Store',
			'steam'       => 'Steam',
			'nintendo'    => 'Nintendo',
			'ps'          => 'PlayStation Store',
			'amazon_asin' => 'Amazon ASIN',
			'epic'        => 'Epic Games Store',
			'gog'         => 'GOG',
			'apple'       => 'Apple App Store',
			'googleplay'  => 'Google Play',
		);
	}

	/** @return array<string, string> Supported subscription service identifiers. */
	public static function subscriptions(): array {
		return array(
			'gamepass'     => 'Xbox / PC Game Pass',
			'psplus'       => 'PlayStation Plus',
			'nso'          => 'Nintendo Switch Online',
			'ea_play'      => 'EA Play',
			'ubisoft_plus' => 'Ubisoft+',
			'apple_arcade' => 'Apple Arcade',
		);
	}

	/** @return array<string, array<string, string>> Fields shared by registration and forms. */
	public static function fields(): array {
		return array(
			'store_ids'     => array( 'label' => __( 'ストアID', 'node-library' ), 'type' => 'object' ),
			'developer'     => array( 'label' => __( '開発元', 'node-library' ), 'type' => 'string' ),
			'publisher'     => array( 'label' => __( '発売元', 'node-library' ), 'type' => 'string' ),
			'release_date'  => array( 'label' => __( '発売日', 'node-library' ), 'type' => 'string' ),
			'genres'        => array( 'label' => __( 'ジャンル（1行に1つ）', 'node-library' ), 'type' => 'array' ),
			'rating'        => array( 'label' => __( 'レーティング', 'node-library' ), 'type' => 'object' ),
			'japanese'      => array( 'label' => __( '日本語対応', 'node-library' ), 'type' => 'object' ),
			'capabilities'  => array( 'label' => __( '能力・映像仕様', 'node-library' ), 'type' => 'object' ),
			'subscriptions' => array( 'label' => __( 'サブスク在籍', 'node-library' ), 'type' => 'object' ),
		);
	}

	/** CERO codes shared by the editor, validation and public display. */
	public static function cero_options(): array {
		return array(
			'A'                  => __( 'CERO A（全年齢対象）', 'node-library' ),
			'B'                  => __( 'CERO B（12歳以上対象）', 'node-library' ),
			'C'                  => __( 'CERO C（15歳以上対象）', 'node-library' ),
			'D'                  => __( 'CERO D（17歳以上対象）', 'node-library' ),
			'Z'                  => __( 'CERO Z（18歳以上のみ対象）', 'node-library' ),
			'審査予定'           => __( 'CERO 審査予定', 'node-library' ),
			'教育・データベース' => __( 'CERO 教育・データベース', 'node-library' ),
		);
	}

	/** ESRB codes supported by the existing catalogue. */
	private const ESRB_CODES = array( 'EC', 'E', 'E10+', 'T', 'M', 'AO', 'RP', 'RP 17+' );

	/** Register protected metadata; no new public REST fields are exposed in L1. */
	public static function register(): void {
		foreach ( self::fields() as $field => $definition ) {
			register_post_meta(
				'node_library',
				'_node_library_' . $field,
				array(
					'type'              => $definition['type'],
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => static function ( $value ) use ( $field ) {
						return self::sanitize( $field, $value );
					},
					'auth_callback'     => static function ( $allowed, $key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/** @param mixed $value Raw input. */
	private static function text( $value ): string {
		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	/** @param mixed $value Raw flag. @return bool|null Unknown is omitted, never false. */
	private static function flag( $value ): ?bool {
		if ( in_array( $value, array( true, 1, '1' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( false, 0, '0' ), true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * Normalize values for admin saves and future catalogue adapters.
	 *
	 * @param string $field Field without the metadata prefix.
	 * @param mixed  $value Input value.
	 * @return string|array Normalized metadata.
	 */
	public static function sanitize( string $field, $value ) {
		if ( in_array( $field, array( 'developer', 'publisher' ), true ) ) {
			return self::text( $value );
		}
		if ( 'release_date' === $field ) {
			$date = self::text( $value );
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
				return $date;
			}
			return '';
		}
		if ( 'genres' === $field ) {
			$values = is_string( $value ) ? preg_split( '/\R/u', $value ) : $value;
			return is_array( $values ) ? array_values( array_unique( array_filter( array_map( array( self::class, 'text' ), $values ), static fn( $genre ) => '' !== $genre ) ) ) : array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		if ( 'store_ids' === $field ) {
			foreach ( self::stores() as $store => $label ) {
				$id = self::text( $value[ $store ] ?? '' );
				// Treat IDs as opaque strings. Reject URLs and whitespace, preserve leading zeroes.
				if ( '' !== $id && strlen( $id ) <= 255 && preg_match( '/^[A-Za-z0-9._:\-]+$/D', $id ) ) {
					$result[ $store ] = $id;
				}
			}
		} elseif ( 'rating' === $field ) {
			$options = array( 'cero' => array_keys( self::cero_options() ), 'esrb' => self::ESRB_CODES );
			foreach ( $options as $system => $allowed ) {
				$rating = self::text( $value[ $system ] ?? '' );
				if ( in_array( $rating, $allowed, true ) ) {
					$result[ $system ] = $rating;
				}
			}
		} elseif ( in_array( $field, array( 'japanese', 'capabilities' ), true ) ) {
			$flags = 'japanese' === $field ? array( 'audio', 'subtitles', 'ui' ) : array( 'crossplay', 'crosssave', 'online', 'hdr' );
			foreach ( $flags as $flag ) {
				$normalized = self::flag( $value[ $flag ] ?? null );
				if ( null !== $normalized ) {
					$result[ $flag ] = $normalized;
				}
			}
			if ( 'capabilities' === $field ) {
				$fps = $value['max_fps'] ?? '';
				if ( ( is_int( $fps ) || is_string( $fps ) ) && preg_match( '/^[0-9]{1,4}$/D', (string) $fps ) && (int) $fps > 0 ) {
					$result['max_fps'] = (int) $fps;
				}
				$resolution = self::text( $value['resolution'] ?? '' );
				if ( '' !== $resolution ) {
					$result['resolution'] = $resolution;
				}
			}
		} elseif ( 'subscriptions' === $field ) {
			foreach ( self::subscriptions() as $service => $label ) {
				if ( true === self::flag( $value[ $service ] ?? null ) ) {
					$result[ $service ] = true;
				}
			}
		}
		return $result;
	}

	/** Add a separate box so existing summary/link markup and scripts remain compatible. */
	public static function add_meta_box(): void {
		add_meta_box( 'node_library_structured', __( 'カタログ情報（任意）', 'node-library' ), array( self::class, 'render' ), 'node_library', 'normal', 'default' );
	}

	/** @param WP_Post $post Library item. */
	public static function render( $post ): void {
		wp_nonce_field( 'node_library_save_structured', 'node_library_structured_nonce' );
		echo '<p>' . esc_html__( '空欄は未設定です。紹介文・既存リンクと対応機種の分類は引き続き利用できます。', 'node-library' ) . '</p><table class="form-table" role="presentation"><tbody>';
		foreach ( self::fields() as $field => $definition ) {
			$value = self::sanitize( $field, get_post_meta( $post->ID, '_node_library_' . $field, true ) );
			echo '<tr><th scope="row">' . esc_html( $definition['label'] ) . '</th><td>';
			if ( 'store_ids' === $field ) {
				foreach ( self::stores() as $store => $label ) {
					self::input( $field, $store, $label, $value[ $store ] ?? '' );
				}
			} elseif ( 'rating' === $field ) {
				$cero         = $value['cero'] ?? '';
				$cero_options = self::cero_options();
				// Keep the legacy value selected when editing existing catalogue data.
				if ( '教育・データベース' !== $cero ) {
					unset( $cero_options['教育・データベース'] );
				}
				self::select( $field, 'cero', 'CERO', $cero, $cero_options );
				self::select( $field, 'esrb', 'ESRB', $value['esrb'] ?? '', array_combine( self::ESRB_CODES, self::ESRB_CODES ) );
			} elseif ( in_array( $field, array( 'japanese', 'capabilities' ), true ) ) {
				$labels = 'japanese' === $field ? array( 'audio' => __( '音声', 'node-library' ), 'subtitles' => __( '字幕', 'node-library' ), 'ui' => 'UI' ) : array( 'crossplay' => __( 'クロスプレイ', 'node-library' ), 'crosssave' => __( 'クロスセーブ', 'node-library' ), 'online' => __( 'オンライン対応', 'node-library' ), 'hdr' => 'HDR' );
				foreach ( $labels as $key => $label ) {
					$selected = array_key_exists( $key, $value ) ? ( $value[ $key ] ? '1' : '0' ) : '';
					self::select( $field, $key, $label, $selected, array( '1' => __( '対応', 'node-library' ), '0' => __( '非対応', 'node-library' ) ) );
				}
				if ( 'capabilities' === $field ) {
					self::input( $field, 'max_fps', __( '最大FPS', 'node-library' ), $value['max_fps'] ?? '', 'number' );
					self::input( $field, 'resolution', __( '解像度（例：3840×2160）', 'node-library' ), $value['resolution'] ?? '' );
				}
			} elseif ( 'subscriptions' === $field ) {
				echo '<input type="hidden" name="node_library_structured[subscriptions][_present]" value="1">';
				foreach ( self::subscriptions() as $service => $label ) {
					$id = 'node-library-structured-subscriptions-' . $service;
					echo '<p><label for="' . esc_attr( $id ) . '"><input id="' . esc_attr( $id ) . '" name="node_library_structured[subscriptions][' . esc_attr( $service ) . ']" type="checkbox" value="1"' . checked( ! empty( $value[ $service ] ), true, false ) . '> ' . esc_html( $label ) . '</label></p>';
				}
			} elseif ( 'genres' === $field ) {
				echo '<label class="screen-reader-text" for="node-library-structured-genres">' . esc_html( $definition['label'] ) . '</label><textarea id="node-library-structured-genres" name="node_library_structured[genres]" rows="3" class="large-text">' . esc_textarea( implode( "\n", $value ) ) . '</textarea>';
			} else {
				self::input( $field, '', $definition['label'], $value, 'release_date' === $field ? 'date' : 'text' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Render a labeled scalar input. */
	private static function input( string $field, string $key, string $label, $value, string $type = 'text' ): void {
		$id   = 'node-library-structured-' . $field . ( '' !== $key ? '-' . $key : '' );
		$name = 'node_library_structured[' . $field . ']' . ( '' !== $key ? '[' . $key . ']' : '' );
		echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br><input class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( (string) $value ) . '"' . ( 'number' === $type ? ' min="1" max="9999" step="1"' : '' ) . '></p>';
	}

	/** Render an optional selection; an empty value means unknown. */
	private static function select( string $field, string $key, string $label, string $value, array $options ): void {
		$id = 'node-library-structured-' . $field . '-' . $key;
		echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br><select id="' . esc_attr( $id ) . '" name="node_library_structured[' . esc_attr( $field ) . '][' . esc_attr( $key ) . ']">';
		echo '<option value="">' . esc_html__( '未設定', 'node-library' ) . '</option>';
		foreach ( $options as $option => $text ) {
			echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( $value, (string) $option, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></p>';
	}

	/** Save only submitted fields on an authorized, explicit form save. */
	public static function save( int $post_id ): void {
		if ( 'node_library' !== get_post_type( $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['node_library_structured_nonce'] ) || ! is_string( $_POST['node_library_structured_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['node_library_structured_nonce'] ) ), 'node_library_save_structured' ) ) {
			return;
		}
		if ( ! isset( $_POST['node_library_structured'] ) || ! is_array( $_POST['node_library_structured'] ) ) {
			return;
		}
		$data = wp_unslash( $_POST['node_library_structured'] );
		foreach ( self::fields() as $field => $definition ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$value = self::sanitize( $field, $data[ $field ] );
			$key   = '_node_library_' . $field;
			if ( '' === $value || array() === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				// Metadata APIs unslash values; preserve literal backslashes in names.
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}
	}
}
