<?php
/** Date order control for article archives. */
$sort_value = isset( $_GET['sort'] ) && is_string( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : '';
$sort_value = 'date-desc' === $sort_value ? 'date-desc' : 'date-asc';
$sort_action = strtok( get_pagenum_link( 1, false ), '?' );
?>
<form class="lf-results-sort" method="get" action="<?php echo esc_url( $sort_action ); ?>">
	<?php luna_frontier_sort_hidden_fields( array( 'sort', 'paged', 'page' ) ); ?>
	<label for="lf-archive-sort"><?php esc_html_e( '並び順', 'node' ); ?></label>
	<select id="lf-archive-sort" name="sort">
		<option value="date-asc" <?php selected( $sort_value, 'date-asc' ); ?>><?php esc_html_e( '日付の昇順（古い順）', 'node' ); ?></option>
		<option value="date-desc" <?php selected( $sort_value, 'date-desc' ); ?>><?php esc_html_e( '日付の降順（新しい順）', 'node' ); ?></option>
	</select>
	<button type="submit"><?php esc_html_e( '適用', 'node' ); ?></button>
</form>
