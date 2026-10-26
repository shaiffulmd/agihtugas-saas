<?php
declare(strict_types=1);
/* Pautan footer tersuai (halaman awam platform & laman awam sekolah): pengesahan dikongsi. */
/** Pautan footer: baris "Label | URL" → [[label, url], …]. URL https:// / http:// / mailto: / tel: / laluan dalaman (/…). Maks 12. */
function footer_links_parse(string $text,?string &$error=null): array {
    $out=[]; $error=null;
    foreach(preg_split('/\R/',$text) as $n=>$line){
        $line=trim($line); if($line==='') continue;
        $parts=array_map('trim',explode('|',$line,2));
        if(count($parts)<2 || $parts[0]==='' || $parts[1]===''){ $error='Baris '.($n+1).': guna format "Label | URL".'; return []; }
        [$label,$url]=$parts;
        if(mb_strlen($label)>60) { $error='Baris '.($n+1).': label maksimum 60 aksara.'; return []; }
        $ok=preg_match('~^https?://[^\s<>"\']+$~i',$url) && filter_var($url,FILTER_VALIDATE_URL);
        $ok=$ok || preg_match('~^(mailto:[^\s@<>"\']+@[^\s<>"\']+|tel:\+?[0-9 ()-]{6,20}|/[^\s<>"\']*)$~i',$url);
        if(!$ok || str_starts_with($url,'//')){ $error='Baris '.($n+1).': URL tidak sah (guna https://…, mailto:, tel: atau laluan /…).'; return []; }
        $out[]=[$label,mb_substr($url,0,500)];
        if(count($out)>12){ $error='Maksimum 12 pautan.'; return []; }
    }
    return $out;
}
