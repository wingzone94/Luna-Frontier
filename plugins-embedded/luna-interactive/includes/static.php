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
  if($max===$min)$max=$min+1;$left=72;$right=784;$top=16;$bottom=$h-36;$width=$right-$left;
  $y=static function($v)use($min,$max,$top,$bottom){return $bottom-($v-$min)/($max-$min)*($bottom-$top);};
  for($tick=0;$tick<=4;$tick++){$v=$min+($max-$min)*$tick/4;$yy=$y($v);$out.='<line x1="'.$left.'" x2="'.$right.'" y1="'.$yy.'" y2="'.$yy.'" stroke="currentColor" opacity=".16"/><text x="'.($left-8).'" y="'.($yy+4).'" text-anchor="end" fill="currentColor" font-size="12">'.esc_html(sprintf('%.4g',$v)).'</text>';}
  $step=$width/$n;$stride=max(1,(int)ceil($n/8));
  foreach($s['labels'] as $i=>$label){$x=$left+($i+.5)*$step;if($i%$stride===0)$out.='<text x="'.$x.'" y="'.($h-14).'" text-anchor="middle" fill="currentColor" font-size="12">'.esc_html(luna_interactive_axis_label($label)).'</text>';}
  foreach($s['datasets'] as $j=>$d){$color=$p[$j%8];$previous=null;foreach($d['data'] as $i=>$v){if($v===null){$previous=null;continue;}$x=$left+($i+.5)*$step;$yy=$y($v);
    if($s['type']==='bar'){$bw=max(1,$step*.78/$k);$bx=$left+$i*$step+$step*.11+$j*$bw;$by=min($yy,$y(0));$bh=max(0.5,abs($yy-$y(0)));$out.='<rect x="'.$bx.'" y="'.$by.'" width="'.$bw.'" height="'.$bh.'" rx="3" fill="'.$color.'"/>';}
    else{if($previous)$out.='<line x1="'.$previous[0].'" y1="'.$previous[1].'" x2="'.$x.'" y2="'.$yy.'" stroke="'.$color.'" stroke-width="2.5" stroke-linecap="round"/>';$out.='<circle cx="'.$x.'" cy="'.$yy.'" r="2.5" fill="'.$color.'"/>';$previous=array($x,$yy);}
  }}
 }
 return $out.'</svg>';
}
