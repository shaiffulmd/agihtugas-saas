<?php
declare(strict_types=1);
/* Teks kaya (editor WYSIWYG) yang selamat.
   Pelayan ialah pertahanan utama: HTML daripada editor dibersihkan dengan senarai putih yang ketat
   (tiada atribut kecuali href pautan http/https/mailto). Kandungan lama (teks biasa) dipaparkan seperti dahulu. */

const RICH_TAGS=['p','br','strong','em','u','s','ul','ol','li','h3','h4','blockquote','a'];
const RICH_MAP=['b'=>'strong','i'=>'em','strike'=>'s','del'=>'s','h1'=>'h3','h2'=>'h3','h5'=>'h4','h6'=>'h4','div'=>'p'];
const RICH_DROP=['script','style','iframe','object','embed','svg','math','template','head','title','meta','link','form','input','button','select','textarea','noscript','img','video','audio','canvas','picture','source'];

function rich_is_html(string $s): bool { return (bool)preg_match('~<(p|br|strong|em|u|s|ul|ol|li|h3|h4|blockquote|a)\b[^>]*>~i',$s); }
/** Bersihkan HTML editor kepada subset selamat. Teks biasa (tanpa tag) dipulangkan tanpa perubahan. */
function sanitize_rich(string $html): string {
    $html=trim(str_replace("\r\n","\n",$html));
    if($html==='' || !preg_match('~<[a-z!/]~i',$html)) return $html;
    $doc=new DOMDocument(); $prev=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="rich-root">'.$html.'</div>',LIBXML_NONET|LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_COMPACT);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    $root=$doc->getElementById('rich-root'); if(!$root) return e(strip_tags($html));
    $out=rich_walk($root,0);
    $out=preg_replace(['~<p>(\s|<br>|&nbsp;)*</p>~','~(<br>){3,}~','~\n{3,}~'],['','<br><br>',"\n\n"],$out);
    return trim((string)$out);
}
function rich_walk(DOMNode $node,int $depth): string {
    if($depth>20) return '';
    $h='';
    foreach($node->childNodes as $c){
        if($c instanceof DOMText){ $h.=e($c->nodeValue); continue; }
        if(!$c instanceof DOMElement) continue; // komen, arahan pemprosesan dsb. dibuang
        $tag=strtolower($c->tagName); $tag=RICH_MAP[$tag]??$tag;
        if(in_array($tag,RICH_DROP,true)) continue;
        $inner=rich_walk($c,$depth+1);
        if(!in_array($tag,RICH_TAGS,true)){ $h.=$inner; continue; } // span, font, table dsb.: kekalkan teks sahaja
        if($tag==='br'){ $h.='<br>'; continue; }
        if($tag==='a'){
            $href=preg_replace('/[\x00-\x20\x7f]+/','',(string)$c->getAttribute('href'));
            if(!preg_match('~^(https?://|mailto:)[^"<>]+$~i',$href)){ $h.=$inner; continue; }
            $h.='<a href="'.e($href).'" rel="noopener nofollow" target="_blank">'.($inner!==''?$inner:e($href)).'</a>'; continue;
        }
        if(in_array($tag,['ul','ol'],true)) $inner=preg_replace('~^\s+|\s+$~','',$inner);
        // Blok di dalam <p> (cth. <p><ul>…) tidak sah: buka balutan <p> supaya struktur kekal kemas.
        if($tag==='p' && preg_match('~<(p|ul|ol|h3|h4|blockquote)>~',$inner)){ $h.=$inner; continue; }
        if(in_array($tag,['h3','h4'],true) && preg_match('~<(p|ul|ol|h3|h4|blockquote)>~',$inner)){ $h.=$inner; continue; }
        $h.='<'.$tag.'>'.$inner.'</'.$tag.'>';
    }
    return $h;
}
/** Papar medan laporan: HTML dibersihkan semula (pertahanan berlapis) atau teks biasa lama. */
function rich_html(?string $s): string { $s=(string)$s; return rich_is_html($s)?sanitize_rich($s):nl2br(e($s)); }
/** Teks biasa (untuk ringkasan, CSV, meta). */
function rich_plain(?string $s): string { $s=(string)$s; if(!rich_is_html($s)) return $s; $t=preg_replace(['~<br>~','~</(p|li|h3|h4|blockquote)>~'],["\n","\n"],$s); return trim(html_entity_decode(strip_tags((string)$t),ENT_QUOTES|ENT_HTML5,'UTF-8')); }
/** Panjang teks sebenar (tanpa markup) untuk had aksara. */
function rich_length(string $s): int { return mb_strlen(rich_plain($s)); }
