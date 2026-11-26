<?php
/** Stored genres take precedence over the library item type. */
$id     = get_the_ID();
$genres = array_filter( (array) get_post_meta( $id, '_node_library_genres', true ), 'is_string' );
$type   = get_post_meta( $id, '_node_library_type', true );
?>
<span class="library-category"><?php echo esc_html( implode( '・', array_filter( $genres ) ) ?: ( 'app' === $type ? __( 'アプリ', 'node' ) : __( 'ゲーム', 'node' ) ) ); ?></span>
