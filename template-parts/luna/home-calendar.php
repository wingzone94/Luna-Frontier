<?php
/** Monthly archive calendar with a progressively enhanced month selector. @package LunaFrontier */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$lf_current_month = current_time( 'Ym' );
$lf_first_post = get_posts( array( 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'ASC', 'post_status' => 'publish' ) );
$lf_first_month = $lf_first_post ? substr( str_replace( '-', '', $lf_first_post[0]->post_date ), 0, 6 ) : $lf_current_month;
$lf_months = array();
$lf_cursor = new DateTimeImmutable( $lf_current_month . '01', wp_timezone() );
while ( $lf_cursor->format( 'Ym' ) >= $lf_first_month && count( $lf_months ) < 1200 ) {
 $lf_months[ $lf_cursor->format( 'Ym' ) ] = $lf_cursor->format( 'Y年n月' );
 $lf_cursor = $lf_cursor->modify( '-1 month' );
}
$lf_requested = isset( $_GET['lf_calendar'] ) && is_string( $_GET['lf_calendar'] ) ? sanitize_text_field( wp_unslash( $_GET['lf_calendar'] ) ) : '';
$lf_month = isset( $lf_months[ $lf_requested ] ) ? $lf_requested : $lf_current_month;
?>
<form class="lf-home__calendar-form" method="get" action="<?php echo esc_url( $args['home_url'] . '#lf-calendar-title' ); ?>">
 <label class="screen-reader-text" for="lf-calendar-month"><?php esc_html_e( '表示する年月', 'node' ); ?></label>
 <div class="lf-home__calendar-controls">
  <select name="lf_calendar" id="lf-calendar-month">
   <?php foreach ( $lf_months as $lf_value => $lf_label ) : ?>
    <option value="<?php echo esc_attr( $lf_value ); ?>" <?php selected( (string) $lf_value, $lf_month ); ?>><?php echo esc_html( $lf_label ); ?></option>
   <?php endforeach; ?>
  </select>
  <?php if ( ! empty( $args['category'] ) ) : ?><input type="hidden" name="lf_category" value="<?php echo esc_attr( $args['category'] ); ?>"><?php endif; ?>
  <button type="submit"><?php esc_html_e( '表示', 'node' ); ?></button>
 </div>
</form>
<?php
// get_calendar reads these globals; restore them so other templates keep their query context.
( static function ( $selected_month ) {
 global $m, $year, $monthnum;
 $saved = array( $m, $year, $monthnum );
 $m = $selected_month;
 $year = (int) substr( $selected_month, 0, 4 );
 $monthnum = (int) substr( $selected_month, 4, 2 );
 echo get_calendar( true, false );
 list( $m, $year, $monthnum ) = $saved;
} )( $lf_month );
