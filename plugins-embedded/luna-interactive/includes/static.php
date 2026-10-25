<?php
if(!defined('ABSPATH'))exit;
/** Categorical series colors. Keep in sync with assets/core.js palette. Not the brand orange. */
function luna_interactive_palette(){return array('#0F8F86','#3B6FE0','#8A4FD0','#E0567A','#3C8C48','#D06A1C','#1E88B5','#C43B8A');}
function luna_interactive_axis_label($label){
 if(preg_match('/^\d{4}-(\d{2})-(\d{2})$/',(string)$label,$m))return (int)$m[1].'/'.(int)$m[2];
 $text=function_exists('mb_strimwidth')?mb_strimwidth((string)$label,0,12,'…','UTF-8'):(string)$label;
 return $text;
}
function luna_interactive_pie_description($s,$ds,$title){
 $name=$ds['label']?:'系列';
 $prefix=$title.'、'.$name.'。';
 if(!$s['labels'])return $prefix.'表示する項目がありません。';
 $sum=0;foreach($ds['data'] as $value)if($value!==null&&$value>0)$sum+=(float)$value;
 if($sum<=0)return $prefix.'円に表示できる値がありません。';
 $parts=array();
 foreach($s['labels'] as $i=>$label){
  $label=$label?:'（名称なし）';$value=$ds['data'][$i]??null;
  if($value===null)$parts[]=$label.'は欠損';
  elseif($value<0)$parts[]=$label.'は対象外';
  else $parts[]=$label.' '.rtrim(rtrim(number_format((float)$value/$sum*100,1,'.',''),'0'),'.').'%';
 }
 return $prefix.implode('、',$parts).'。';
}
function luna_interactive_pie_svg_grid($s,$title){
 $p=luna_interactive_palette();$out='<div class="luna-pie-grid" data-series-count="'.count($s['datasets']).'">';
 if(!$s['datasets'])return $out.'<p>データがありません</p></div>';
 foreach($s['datasets'] as $j=>$ds){
  $name=$ds['label']?:'系列 '.($j+1);
  $out.='<div class="luna-pie-panel"><div class="luna-pie-canvas"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 320" role="img" aria-label="'.esc_attr(luna_interactive_pie_description($s,$ds,$title)).'" preserveAspectRatio="xMidYMid meet"><title>'.esc_html($title.'、'.$name).'</title>';
  $sum=0;foreach($ds['data'] as $v)if($v!==null&&$v>0)$sum+=(float)$v;
  if($sum<=0){$out.='<text x="160" y="164" text-anchor="middle" fill="currentColor">データがありません</text>';}else{
   $angle=-M_PI/2;
   foreach($ds['data'] as $i=>$v){if($v===null||$v<=0)continue;$sweep=(float)$v/$sum*2*M_PI;$end=$angle+$sweep;$color=$p[$i%count($p)];
    if($sweep>=2*M_PI-0.000001)$out.='<circle cx="160" cy="160" r="154" fill="'.$color.'"/>';
    else{$large=$sweep>M_PI?1:0;$x1=160+154*cos($angle);$y1=160+154*sin($angle);$x2=160+154*cos($end);$y2=160+154*sin($end);
     $out.='<path d="M 160 160 L '.$x1.' '.$y1.' A 154 154 0 '.$large.' 1 '.$x2.' '.$y2.' Z" fill="'.$color.'" stroke="var(--luna-surface-low, #fff)" stroke-width="2" stroke-linejoin="round"/>';
    }
    $angle=$end;
   }
  }
  $out.='</svg></div><span class="luna-pie-series-name">'.esc_html($name).'</span></div>';
 }
 return $out.'</div>';
}
/** Server-rendered fallback, always based on current data; no stored data URI. */
function luna_interactive_static_svg($s,$title,$height=320){
 if($s['type']==='pie')return luna_interactive_pie_svg_grid($s,$title);
 $p=luna_interactive_palette();$w=800;$h=$height;$out='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" role="img" aria-label="'.esc_attr($title).'" preserveAspectRatio="xMidYMid meet"><title>'.esc_html($title).'</title>';
 $n=count($s['labels']);$k=count($s['datasets']);
 if(!$n||!$k)return $out.'<text x="'.($w/2).'" y="'.($h/2).'" text-anchor="middle" fill="currentColor">データがありません</text></svg>';
 {
  $min=0;$max=0;foreach($s['datasets'] as $d)foreach($d['data'] as $v){if($v!==null){$min=min($min,$v);$max=max($max,$v);}}
  if(isset($s['yMin']))$min=$s['yMin'];if(isset($s['yMax']))$max=$s['yMax'];
  if($max<=$min){if(isset($s['yMax'])&&!isset($s['yMin']))$min=$max-1;else $max=$min+1;}$left=empty($s['yTitle'])?72:88;$right=784;$top=16;$bottom=$h-(empty($s['xTitle'])?36:60);$width=$right-$left;
  $numeric=($s['xScale']??'')==='linear';foreach($s['labels'] as $label)if(!is_numeric($label)||!is_finite((float)$label))$numeric=false;
  $xmin=$numeric?($s['xMin']??min(array_map('floatval',$s['labels']))):0;
  $xmax=$numeric?($s['xMax']??max(array_map('floatval',$s['labels']))):1;if($xmax<=$xmin){if(isset($s['xMax'])&&!isset($s['xMin']))$xmin=$xmax-1;else $xmax=$xmin+1;}
  $xpos=static function($i)use($s,$numeric,$xmin,$xmax,$left,$width,$n){return $numeric?$left+((float)$s['labels'][$i]-$xmin)/($xmax-$xmin)*$width:$left+($i+.5)*$width/$n;};
  if(!empty($s['xTitle']))$out.='<text x="'.($left+$width/2).'" y="'.($h-8).'" text-anchor="middle" fill="currentColor" font-size="13">'.esc_html($s['xTitle']).'</text>';
  if(!empty($s['yTitle'])){$cy=($top+$bottom)/2;$out.='<text transform="translate(18 '.$cy.') rotate(-90)" text-anchor="middle" fill="currentColor" font-size="13">'.esc_html($s['yTitle']).'</text>';}
  $y=static function($v)use($min,$max,$top,$bottom){return $bottom-($v-$min)/($max-$min)*($bottom-$top);};
  for($tick=0;$tick<=4;$tick++){$v=$min+($max-$min)*$tick/4;$yy=$y($v);$out.='<line x1="'.$left.'" x2="'.$right.'" y1="'.$yy.'" y2="'.$yy.'" stroke="currentColor" opacity=".16"/><text x="'.($left-8).'" y="'.($yy+4).'" text-anchor="end" fill="currentColor" font-size="12">'.esc_html(sprintf('%.4g',$v)).'</text>';}
  $step=$width/$n;$stride=max(1,(int)ceil($n/8));
  if($numeric){for($tick=0;$tick<=4;$tick++){$v=$xmin+($xmax-$xmin)*$tick/4;$x=$left+$width*$tick/4;$out.='<text x="'.$x.'" y="'.($bottom+22).'" text-anchor="middle" fill="currentColor" font-size="12">'.esc_html(sprintf('%.4g',$v)).'</text>';}}
  else foreach($s['labels'] as $i=>$label){$x=$xpos($i);if($i%$stride===0)$out.='<text x="'.$x.'" y="'.($bottom+22).'" text-anchor="middle" fill="currentColor" font-size="12">'.esc_html(luna_interactive_axis_label($label)).'</text>';}
  $clip=wp_unique_id('luna-plot-clip-');$out.='<defs><clipPath id="'.esc_attr($clip).'"><rect x="'.$left.'" y="'.$top.'" width="'.$width.'" height="'.($bottom-$top).'"/></clipPath></defs><g clip-path="url(#'.esc_attr($clip).')">';
  if($numeric&&$n>1){$gap=INF;for($i=1;$i<$n;$i++)$gap=min($gap,abs($xpos($i)-$xpos($i-1)));$step=max(1,$gap);}
  foreach($s['datasets'] as $j=>$d){$color=$p[$j%8];$previous=null;foreach($d['data'] as $i=>$v){if($v===null){$previous=null;continue;}$x=$xpos($i);$yy=$y($v);
    if($s['type']==='bar'){$bw=max(1,$step*.78/$k);$bx=$x-$step*.39+$j*$bw;$by=min($yy,$y(0));$bh=max(0.5,abs($yy-$y(0)));$out.='<rect x="'.$bx.'" y="'.$by.'" width="'.$bw.'" height="'.$bh.'" rx="3" fill="'.$color.'"/>';}
    else{if($previous)$out.='<line x1="'.$previous[0].'" y1="'.$previous[1].'" x2="'.$x.'" y2="'.$yy.'" stroke="'.$color.'" stroke-width="2.5" stroke-linecap="round"/>';$out.='<circle cx="'.$x.'" cy="'.$yy.'" r="2.5" fill="'.$color.'"/>';$previous=array($x,$yy);}
  }}$out.='</g>';
 }
 return $out.'</svg>';
}
