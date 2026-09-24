<?php
/**
 * Template rendering data preparation.
 *
 * @package NodeTheme
 */

declare(strict_types=1);

namespace NodeTheme\Controllers;

/**
 * Prepares normalized values consumed by theme templates.
 */
final class TemplateController {
	/**
	 * Build a published-date label with a relative suffix for the first 24 hours.
	 */
	public static function getRelativeDate( int $post_id ): string {
		$post_time    = (int) get_the_time( 'U', $post_id );
		$current_time = (int) current_time( 'timestamp' );
		$difference   = $current_time - $post_time;
		$full_date    = get_the_date( 'Y年n月j日', $post_id );

		if ( 0 >= $difference || DAY_IN_SECONDS <= $difference ) {
			return $full_date;
		}

		if ( HOUR_IN_SECONDS > $difference ) {
			$relative = MINUTE_IN_SECONDS > $difference
				? 'たった今'
				: floor( $difference / MINUTE_IN_SECONDS ) . '分前';
		} else {
			$relative = floor( $difference / HOUR_IN_SECONDS ) . '時間前';
		}

		return $full_date . ' （' . $relative . '）';
	}

	/**
	 * Build the modified-date values used by article and card templates.
	 *
	 * @return array{datetime: string, display: string, display_short: string}|null
	 */
	public static function getPostModifiedDisplay( int $post_id ): ?array {
		$published = get_the_date( 'Y-m-d', $post_id );
		$manual     = get_post_meta( $post_id, '_node_manual_modified_date', true );
		$has_manual = is_string( $manual )
			&& 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $manual, $matches )
			&& checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );

		if ( $has_manual ) {
			if ( $manual < $published ) {
				return null;
			}
			$short = $matches[1] === get_the_date( 'Y', $post_id )
				? sprintf( '%d/%d', (int) $matches[2], (int) $matches[3] )
				: str_replace( '-', '/', $manual );

			return array(
				'datetime'      => $manual,
				'display'       => str_replace( '-', '/', $manual ),
				'display_short' => $short,
			);
		}

		if ( get_the_modified_date( 'Y-m-d', $post_id ) <= $published ) {
			return null;
		}

		$short = get_the_modified_date( 'Y', $post_id ) === get_the_date( 'Y', $post_id )
			? get_the_modified_date( 'n/j', $post_id )
			: get_the_modified_date( 'Y/n/j', $post_id );

		return array(
			'datetime'      => get_the_modified_date( 'c', $post_id ),
			'display'       => get_the_modified_date( 'Y/m/d', $post_id ),
			'display_short' => $short,
		);
	}
}
