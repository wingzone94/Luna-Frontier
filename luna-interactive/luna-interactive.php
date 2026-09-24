<?php
/**
 * Plugin Name: Luna interactive
 * Description: データ編集・絞り込み・動的／静的切り替えに対応する「グラフ」ブロック。
 * Version: 1.4.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Luminous Core
 * License: GPL-2.0-or-later
 * Text Domain: luna-interactive
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
// テーマ同梱版が先に読み込まれたリクエストで単独版を有効化する場合、
// 同じ関数を二重定義しない。次のリクエストから単独版が先に読み込まれる。
if ( ! function_exists( 'luna_interactive_register_block' ) ) {
define( 'LUNA_INTERACTIVE_VERSION', '1.4.0' );
define( 'LUNA_INTERACTIVE_URL', defined( 'LUNA_INTERACTIVE_EMBEDDED' ) && LUNA_INTERACTIVE_EMBEDDED
 ? get_template_directory_uri() . '/plugins-embedded/luna-interactive/'
 : plugin_dir_url( __FILE__ ) );
require_once __DIR__ . '/includes/static.php';
function luna_interactive_attributes() {
 $result = array();
 foreach (array('title'=>'','caption'=>'','source'=>'','sourceUrl'=>'','chartType'=>'bar','mode'=>'dynamic','fileName'=>'','staticUrl'=>'') as $key=>$value) { $result[$key]=array('type'=>'string','default'=>$value); }
 $result['labels']=array('type'=>'array','default'=>array());
 $result['datasets']=array('type'=>'array','default'=>array());
 $result['allowedTypes']=array('type'=>'array','default'=>array('bar','line','pie'));
 $result['height']=array('type'=>'number','default'=>320);
 $result['staticId']=array('type'=>'number','default'=>0);
 return $result;
}
add_action('init','luna_interactive_register_block');
function luna_interactive_register_block() {
 $url=LUNA_INTERACTIVE_URL; $v=LUNA_INTERACTIVE_VERSION;
 wp_register_script('luna-interactive-chartjs',$url.'assets/vendor/chart.umd.min.js',array(),'4.4.3',true);
 wp_register_script('luna-interactive-xlsx',$url.'assets/vendor/xlsx.full.min.js',array(),'0.20.3',true);
 wp_register_script('luna-interactive-core',$url.'assets/core.js',array(),$v,true);
 wp_register_script('luna-interactive-editor',$url.'assets/editor.js',array('wp-blocks','wp-element','wp-block-editor','wp-components','wp-data','luna-interactive-chartjs','luna-interactive-xlsx','luna-interactive-core'),$v,true);
 wp_register_script('luna-interactive-view',$url.'assets/view.js',array('luna-interactive-chartjs','luna-interactive-core'),$v,true);
 wp_register_style('luna-interactive-style',$url.'assets/style.css',array(),$v);
 wp_register_style('luna-interactive-editor',$url.'assets/editor.css',array('wp-edit-blocks','luna-interactive-style'),$v);
 register_block_type('luna-interactive/chart',array('api_version'=>3,'editor_script'=>'luna-interactive-editor','editor_style'=>'luna-interactive-editor','style'=>'luna-interactive-style','view_script'=>'luna-interactive-view','attributes'=>luna_interactive_attributes(),'render_callback'=>'luna_interactive_render_chart','supports'=>array('html'=>false)));
}
function luna_interactive_clean_spec($a) {
 $labels=array_map(static function($v){return is_scalar($v)?sanitize_text_field((string)$v):'';},array_slice(is_array($a['labels']??null)?$a['labels']:array(),0,1000));
 $datasets=array();
 foreach(array_slice(is_array($a['datasets']??null)?$a['datasets']:array(),0,20) as $i=>$d) {
  if(!is_array($d))continue;
  $values=is_array($d['data']??null)?$d['data']:array();$data=array();
  foreach($labels as $j=>$_){$v=$values[$j]??null;$data[]=is_numeric($v)&&is_finite((float)$v)&&abs((float)$v)<=1e15?(float)$v:null;}
  $datasets[]=array('label'=>is_scalar($d['label']??null)?sanitize_text_field((string)$d['label']):'系列 '.($i+1),'data'=>$data);
 }
 return array('type'=>in_array($a['chartType']??'',array('bar','line','pie'),true)?$a['chartType']:'bar','labels'=>$labels,'datasets'=>$datasets);
}
function luna_interactive_allowed_types($a) {
 $valid=array('bar','line','pie');
 $requested=$a['allowedTypes']??$valid;
 if(!is_array($requested))$requested=$valid;
 $requested=array_filter($requested,'is_string');
 $allowed=array_values(array_intersect($valid,$requested));
 return $allowed?:array('bar');
}
function luna_interactive_icon($name) {
 $icons=array(
  'bar'=>'<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 13h4v7H4zm6-8h4v15h-4zm6 5h4v10h-4z"/></svg>',
  'line'=>'<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 16.5 8.5 11l3.2 3.2L20 6"/><circle cx="4" cy="16.5" r="1.35" fill="currentColor" stroke="none"/><circle cx="8.5" cy="11" r="1.35" fill="currentColor" stroke="none"/><circle cx="11.7" cy="14.2" r="1.35" fill="currentColor" stroke="none"/><circle cx="20" cy="6" r="1.35" fill="currentColor" stroke="none"/></svg>',
  'pie'=>'<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M11 3.1A8.9 8.9 0 1 0 20.9 13H11V3.1z"/><path fill="currentColor" opacity=".45" d="M13 2.2A9 9 0 0 1 21.8 11H13V2.2z"/></svg>',
  'dynamic'=>'<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 15V9m5 6V4m5 4V6M13 11l7 4-3 .8-1.5 3.2z"/></svg>',
  'static'=>'<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3.5" y="5" width="17" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="8.5" cy="9.5" r="1.3" fill="currentColor"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m4 16.2 4.2-3.2 2.8 2 3.6-3.4L20 16.2"/></svg>',
 );
 return $icons[$name]??'';
}
function luna_interactive_render_chart($a) {
 $spec=luna_interactive_clean_spec($a);$allowed=luna_interactive_allowed_types($a);if(!in_array($spec['type'],$allowed,true))$spec['type']=$allowed[0];
 $title=wp_strip_all_tags($a['title']??'');$caption=wp_strip_all_tags($a['caption']??'');$source=wp_strip_all_tags($a['source']??'');
 $id=wp_unique_id('luna-chart-');$title_id=$id.'-title';$caption_id=$id.'-caption';
 $source_url=esc_url($a['sourceUrl']??'',array('http','https'));
 $height=max(200,min(560,(int)($a['height']??320)));$mode=($a['mode']??'')==='static'?'static':'dynamic';
 $legend=$spec['type']==='pie'?$spec['labels']:array_column($spec['datasets'],'label');
 ob_start(); ?>
 <figure <?php echo get_block_wrapper_attributes(array('class'=>'luna-chart luna-m3-card')); ?> draggable="false" data-luna-copy-protected="true" aria-label="<?php echo esc_attr($title?:'グラフ'); ?>" <?php if($caption): ?>aria-describedby="<?php echo esc_attr($caption_id); ?>"<?php endif; ?> data-luna-type="<?php echo esc_attr($spec['type']); ?>" data-luna-mode="<?php echo esc_attr($mode); ?>" data-luna-spec="<?php echo esc_attr(wp_json_encode($spec)); ?>">
 <figcaption class="luna-header">
 <div class="luna-head">
 <?php if($title): ?><span class="luna-m3-title" id="<?php echo esc_attr($title_id); ?>"><?php echo esc_html($title); ?></span><?php endif; ?>
 <?php if($caption): ?><p class="luna-caption" id="<?php echo esc_attr($caption_id); ?>"><?php echo esc_html($caption); ?></p><?php endif; ?>
 <?php if($source||$source_url): ?><p class="luna-source">出典: <?php if($source_url): ?><a href="<?php echo $source_url; ?>" rel="noopener noreferrer"><?php echo esc_html($source?:$source_url); ?></a><?php else: echo esc_html($source); endif; ?></p><?php endif; ?>
 </div>
 <div class="luna-toolbar<?php echo count($allowed)===1?' luna-toolbar-single':''; ?>" hidden>
 <?php if(count($allowed)>1): ?><div class="luna-segment" role="radiogroup" aria-label="グラフ形式">
 <?php foreach($allowed as $type): $type_label=array('bar'=>'棒グラフ','line'=>'折れ線グラフ','pie'=>'円グラフ')[$type]; ?>
 <button type="button" class="luna-segment-btn" role="radio" data-luna-type="<?php echo esc_attr($type); ?>" aria-label="<?php echo esc_attr($type_label); ?>" title="<?php echo esc_attr($type_label); ?>" aria-checked="<?php echo $spec['type']===$type?'true':'false'; ?>" tabindex="<?php echo $spec['type']===$type?'0':'-1'; ?>"><?php echo luna_interactive_icon($type); ?></button>
 <?php endforeach; ?></div><?php endif; ?>
 <div class="luna-segment" role="radiogroup" aria-label="表示モード">
 <button type="button" class="luna-segment-btn" role="radio" data-luna-view="dynamic" aria-label="動的表示" title="動的表示" aria-checked="<?php echo $mode==='dynamic'?'true':'false'; ?>" tabindex="<?php echo $mode==='dynamic'?'0':'-1'; ?>"><?php echo luna_interactive_icon('dynamic'); ?><span>動的</span></button>
 <button type="button" class="luna-segment-btn" role="radio" data-luna-view="static" aria-label="静的表示" title="静的表示" aria-checked="<?php echo $mode==='static'?'true':'false'; ?>" tabindex="<?php echo $mode==='static'?'0':'-1'; ?>"><?php echo luna_interactive_icon('static'); ?><span>静的</span></button>
 </div>
 </div>
 </figcaption>
 <div class="luna-main" style="--luna-h:<?php echo esc_attr($height); ?>px">
 <div class="luna-stage">
 <div class="luna-m3-surface">
 <div class="luna-body">
 <div class="luna-plot">
 <div class="luna-static"><?php echo luna_interactive_static_svg($spec,$title?:'グラフ',$height); ?></div>
 <div class="luna-dynamic" hidden></div>
 </div>
 <details class="luna-aside" open>
 <summary class="luna-legend-toggle">凡例<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7 10l5 5 5-5z"/></svg></summary>
 <div class="luna-legend-panel">
 <div class="luna-fallback-legend">
 <?php foreach($legend as $i=>$label): ?><span><i style="background:<?php echo esc_attr(luna_interactive_palette()[$i%8]); ?>"></i><?php echo esc_html($label); ?></span><?php endforeach; ?></div>
 <div class="luna-chips luna-chip-row" role="group" aria-label="<?php echo $spec['type']==='pie'?'項目の表示':'系列の表示'; ?>" hidden></div>
 </div>
 </details>
 </div>
 </div>
 <p class="luna-status" role="status" aria-live="polite"></p>
 </div>
 </div>
 </figure>
 <?php return ob_get_clean();
}
}
