<?php
/**
 * Prepare the single-post AI Summary arguments without changing the renderer.
 */
declare( strict_types=1 );

$ai_summary = get_post_meta( get_the_ID(), '_node_ai_summary', true );
$tone_color = get_post_meta( get_the_ID(), '_node_ai_tone_color', true );
$keywords   = get_post_meta( get_the_ID(), '_node_ai_keywords', true );

$ai_args = array(
    'summary'    => $ai_summary,
    'mode'       => 'single',
    'tone_color' => $tone_color,
    'keywords'   => is_array( $keywords ) ? $keywords : array(),
);

if ( ! empty( $ai_summary ) ) {
    get_template_part( 'template-parts/ai-summary', null, $ai_args );
}
