<?php
/**
 * Luna writer avatar scope.
 *
 * @package LunaFrontier
 */

class Luna_Writer_Avatar_Test extends WP_UnitTestCase {
	public function test_admin_bar_keeps_the_native_user_avatar(): void {
		$avatar = '<img src="https://secure.gravatar.com/avatar/example" alt="User" />';
		$this->assertStringContainsString( '<svg', luna_frontier_writer_avatar( $avatar, 1, 28, '', '', array() ) );

		$original_hook = $GLOBALS['wp_filter']['admin_bar_menu'] ?? null;
		remove_all_actions( 'admin_bar_menu' );
		$admin_avatar = null;
		add_action(
			'admin_bar_menu',
			static function () use ( &$admin_avatar, $avatar ): void {
				$admin_avatar = luna_frontier_writer_avatar( $avatar, 1, 28, '', '', array() );
			}
		);

		try {
			do_action( 'admin_bar_menu' );
		} finally {
			if ( null === $original_hook ) {
				unset( $GLOBALS['wp_filter']['admin_bar_menu'] );
			} else {
				$GLOBALS['wp_filter']['admin_bar_menu'] = $original_hook;
			}
		}

		$this->assertSame( $avatar, $admin_avatar );
	}
}
