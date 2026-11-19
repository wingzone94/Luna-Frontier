<?php
/**
 * Explicit, one-time migration of legacy store links. Never rewrites old links.
 *
 * @package Node_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Library_Store_ID_Migration {
	private const VERSION = '1';
	private const OPTION  = 'node_library_store_id_migration';

	/**
	 * Extract an actual catalogue identifier without network requests or guessing.
	 *
	 * @return array{store?:string,id?:string,reason?:string}
	 */
	public static function extract( string $url ): array {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'https', 'http' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return array( 'reason' => 'invalid_url' );
		}
		$host = strtolower( $parts['host'] );
		$path = $parts['path'] ?? '';
		$query = array();
		parse_str( $parts['query'] ?? '', $query );
		$is_host = static function ( string $domain ) use ( $host ): bool {
			return $host === $domain || str_ends_with( $host, '.' . $domain );
		};
		if ( $is_host( 'microsoft.com' ) || $is_host( 'xbox.com' ) ) {
			if ( preg_match( '~/(?:games/store|store/(?:p|apps|games)|detail|p)/~i', $path ) && preg_match( '~/(?:[^/]+/)*([A-Z0-9]{12})/?$~iD', $path, $match ) ) {
				return array( 'store' => 'microsoft', 'id' => strtoupper( $match[1] ) );
			}
			$id = $query['productid'] ?? $query['ProductId'] ?? '';
			if ( is_string( $id ) && preg_match( '/^[A-Z0-9]{12}$/iD', $id ) ) {
				return array( 'store' => 'microsoft', 'id' => strtoupper( $id ) );
			}
		} elseif ( 'store.steampowered.com' === $host || 'steamcommunity.com' === $host ) {
			if ( preg_match( '~^/app/([0-9]+)(?:/|$)~D', $path, $match ) ) {
				return array( 'store' => 'steam', 'id' => $match[1] );
			}
		} elseif ( $is_host( 'amazon.co.jp' ) || $is_host( 'amazon.com' ) ) {
			if ( preg_match( '~/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})(?:/|$)~iD', $path, $match ) ) {
				return array( 'store' => 'amazon_asin', 'id' => strtoupper( $match[1] ) );
			}
		} elseif ( 'store-jp.nintendo.com' === $host ) {
			if ( preg_match( '~^/item/software/(?:[A-Z]_?)?([0-9]{14})(?:/|$)~iD', $path, $match ) || preg_match( '~^/item/software/((?:HAC|BEE)_[A-Z]_[A-Z0-9]{5})(?:/|$)~iD', $path, $match ) ) {
				return array( 'store' => 'nintendo', 'id' => strtoupper( $match[1] ) );
			}
		} elseif ( 'store.playstation.com' === $host ) {
			if ( preg_match( '~^/(?:[a-z]{2}-[a-z]{2}/)?product/([A-Z0-9_\-]+)(?:/|$)~iD', $path, $match ) ) {
				return array( 'store' => 'ps', 'id' => strtoupper( $match[1] ) );
			}
			if ( str_contains( $path, '/concept/' ) ) {
				return array( 'reason' => 'concept_requires_edition_product_id' );
			}
		} elseif ( 'apps.apple.com' === $host || 'itunes.apple.com' === $host ) {
			if ( preg_match( '~^/(?:[a-z]{2}/)?app/(?:[^/]+/)?id([0-9]+)(?:/|$)~iD', $path, $match ) ) {
				return array( 'store' => 'apple', 'id' => $match[1] );
			}
		} elseif ( 'play.google.com' === $host && '/store/apps/details' === rtrim( $path, '/' ) ) {
			$id = $query['id'] ?? '';
			if ( is_string( $id ) && preg_match( '/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+$/D', $id ) ) {
				return array( 'store' => 'googleplay', 'id' => $id );
			}
		}
		return array( 'reason' => 'catalogue_id_not_in_url' );
	}

	/** Inspect one item. Existing identifiers win; ambiguity is always reported. */
	public static function inspect( int $item_id ): array {
		$existing = get_post_meta( $item_id, '_node_library_store_ids', true );
		$ids      = is_array( $existing ) ? $existing : array();
		$links    = get_post_meta( $item_id, '_node_library_links', true );
		$issues   = array();
		$candidates = array();
		$can_apply = '' === $existing || is_array( $existing );
		$normalized_ids = Node_Library_Structured_Meta::sanitize( 'store_ids', $existing );
		foreach ( $ids as $store => $id ) {
			if ( ! array_key_exists( $store, $normalized_ids ) || $normalized_ids[ $store ] !== $id ) {
				$can_apply = false;
			}
		}
		if ( ! $can_apply ) {
			$issues[] = array( 'url' => '', 'reason' => 'invalid_existing_store_ids' );
		}
		if ( ! is_array( $links ) || array() === $links ) {
			if ( array() === $ids ) {
				$issues[] = array( 'url' => '', 'reason' => 'no_links_or_store_ids' );
			}
		} else {
			foreach ( $links as $link ) {
				$url = is_array( $link ) && is_string( $link['url'] ?? null ) ? $link['url'] : '';
				$result = self::extract( $url );
				if ( isset( $result['reason'] ) ) {
					$issues[] = array( 'url' => $url, 'reason' => $result['reason'] );
					continue;
				}
				$candidates[ $result['store'] ][ $result['id'] ][] = $url;
			}
		}
		foreach ( $candidates as $store => $matches ) {
			$has_existing = isset( $ids[ $store ] ) && '' !== $ids[ $store ];
			if ( ! $has_existing && 1 === count( $matches ) ) {
				// PHP converts numeric array keys; always restore the catalogue ID to a string.
				$ids[ $store ] = (string) array_key_first( $matches );
				continue;
			}
			foreach ( $matches as $id => $urls ) {
				$id = (string) $id;
				$old = $ids[ $store ] ?? null;
				$is_same = is_string( $old ) && ( $old === $id || ( in_array( $store, array( 'microsoft', 'amazon_asin', 'ps' ), true ) && strtoupper( $old ) === strtoupper( $id ) ) );
				if ( ! $is_same ) {
					foreach ( $urls as $url ) {
						$issues[] = array( 'url' => $url, 'reason' => 'conflicting_store_id', 'store' => $store, 'candidate_id' => $id );
					}
				}
			}
		}

		if ( $can_apply ) {
			$ids = Node_Library_Structured_Meta::sanitize( 'store_ids', $ids );
		}

		return array(
			'item_id'   => $item_id,
			'title'     => get_the_title( $item_id ),
			'status'    => $issues ? 'needs_manual' : 'success',
			'store_ids' => $ids,
			'can_apply' => $can_apply,
			'issues'    => $issues,
		);
	}

	/**
	 * Preview or apply all items, including drafts, private entries and trash.
	 *
	 * @throws RuntimeException On repeated application or another active migration.
	 */
	public static function run( bool $apply = false, bool $force = false ): array {
		global $wpdb;
		if ( $apply && get_option( self::OPTION ) && ! $force ) {
			throw new RuntimeException( '移行は実行済みです。再確認はプレビュー、再適用は --apply --force を使用してください。' );
		}
		$lock = 'node_library_store_id_migration_lock';
		if ( $apply && ! add_option( $lock, time(), '', false ) ) {
			throw new RuntimeException( '別の移行が実行中か、前回が中断しています。実行中プロセスを確認し、中断時は移行ロックoptionを削除してください。' );
		}
		try {
			$max_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = %s", 'node_library' ) );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( '移行対象の範囲を取得できません。' );
			}
			$cursor = 0;
			$report = array( 'version' => self::VERSION, 'mode' => $apply ? 'apply' : 'preview', 'total' => 0, 'success' => 0, 'needs_manual' => 0, 'updated' => 0, 'write_errors' => 0, 'items' => array() );
			do {
				$batch = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT 100", 'node_library', $cursor, $max_id ) );
				if ( $wpdb->last_error ) {
					throw new RuntimeException( '移行対象の読み込みに失敗しました。レポートを完了できません。' );
				}
				foreach ( $batch as $id ) {
					$cursor = (int) $id;
					$row = self::inspect( $cursor );
					$old = get_post_meta( $cursor, '_node_library_store_ids', true );
					if ( $apply && $row['can_apply'] && $row['store_ids'] && $old !== $row['store_ids'] ) {
						update_post_meta( $cursor, '_node_library_store_ids', wp_slash( $row['store_ids'] ) );
						if ( get_post_meta( $cursor, '_node_library_store_ids', true ) !== $row['store_ids'] ) {
							$row['issues'][] = array( 'url' => '', 'reason' => 'database_write_failed' );
							$row['status'] = 'needs_manual';
							++$report['write_errors'];
						} else {
							++$report['updated'];
						}
					}
					++$report['total'];
					++$report[ $row['status'] ];
					$report['items'][] = $row;
				}
			} while ( 100 === count( $batch ) );
			if ( $apply && 0 === $report['write_errors'] ) {
				update_option( self::OPTION, array( 'version' => self::VERSION, 'completed_at' => gmdate( 'c' ), 'total' => $report['total'], 'needs_manual' => $report['needs_manual'] ), false );
			}
			return $report;
		} finally {
			if ( $apply ) {
				delete_option( $lock );
			}
		}
	}

	/**
	 * Extract store identifiers from legacy Library URLs.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write identifiers. Without this flag, only preview.
	 *
	 * [--force]
	 * : Reapply a previously completed migration without overwriting existing IDs.
	 *
	 * [--format=<format>]
	 * : Report format: json (default), csv, or table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp node-library migrate-store-ids --format=json > library-preview.json
	 *     wp node-library migrate-store-ids --apply --format=json > library-migration.json
	 */
	public static function command( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'json';
		if ( ! in_array( $format, array( 'json', 'csv', 'table' ), true ) ) {
			WP_CLI::error( '--format は json / csv / table を指定してください。' );
		}
		try {
			$report = self::run( isset( $assoc_args['apply'] ), isset( $assoc_args['force'] ) );
		} catch ( RuntimeException $error ) {
			WP_CLI::error( $error->getMessage() );
			return;
		}
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
		} else {
			$rows = $report['items'];
			foreach ( $rows as &$row ) {
				$row['store_ids'] = wp_json_encode( $row['store_ids'], JSON_UNESCAPED_UNICODE );
				$row['issues'] = wp_json_encode( $row['issues'], JSON_UNESCAPED_UNICODE );
			}
			unset( $row );
			\WP_CLI\Utils\format_items( $format, $rows, array( 'item_id', 'title', 'status', 'store_ids', 'issues' ) );
		}
		if ( $report['write_errors'] ) {
			WP_CLI::halt( 1 );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'node-library migrate-store-ids', array( Node_Library_Store_ID_Migration::class, 'command' ) );
}
