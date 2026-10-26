<?php
// Semakan statik: fungsi & pemalar yang DIPANGGIL tetapi tidak ditakrif dalam app/, bin/, public_html/ (cth. tinggalan modul koku
// yang dibuang). Ralat sebegini hanya muncul semasa laluan kod itu dijalankan, jadi ujian HTTP mudah terlepas.
//   php tests/static_symbols.php      → kod keluar 1 jika ada simbol tidak ditakrif
if(PHP_SAPI!=='cli') exit;
$root=dirname(__DIR__); $files=[];
foreach(['app','bin','public_html'] as $d) foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$d,FilesystemIterator::SKIP_DOTS)) as $f) if(str_ends_with((string)$f,'.php')) $files[]=(string)$f;
// Fungsi pilihan sambungan PHP yang disemak dengan function_exists() sebelum dipanggil.
$optional=['fastcgi_finish_request','litespeed_finish_request'];
$defF=[]; $defC=[]; $useF=[]; $useC=[];
$skip=fn(array $t,int $i)=>$t[$i]; $ws=fn($x)=>is_array($x) && $x[0]===T_WHITESPACE;
foreach($files as $f){
    $t=token_get_all((string)file_get_contents($f)); $n=count($t); $rel=substr($f,strlen($root)+1); $inConst=false;
    for($i=0;$i<$n;$i++){
        $tok=$t[$i];
        if(!is_array($tok)){ if($tok===';') $inConst=false; continue; }
        if($tok[0]===T_CONST){ $inConst=true; continue; }
        $j=$i+1; while($j<$n && $ws($t[$j])) $j++; $next=$t[$j]??null;
        $k=$i-1; while($k>=0 && $ws($t[$k])) $k--; $prev=$k>=0?(is_array($t[$k])?$t[$k][0]:$t[$k]):null;
        if($tok[0]===T_FUNCTION){ if(is_array($next) && $next[0]===T_STRING) $defF[strtolower($next[1])]=1; continue; }
        if($tok[0]===T_STRING && $inConst && $next==='=' ) { $defC[$tok[1]]=1; continue; }
        if($tok[0]===T_STRING && strtolower($tok[1])==='define' && $next==='('){ $q=$t[$j+1]??null; if(is_array($q) && $q[0]===T_CONSTANT_ENCAPSED_STRING) $defC[trim($q[1],'\'"')]=1; continue; }
        if($tok[0]!==T_STRING || in_array($prev,[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON,T_NEW,T_FUNCTION,T_CONST,T_CLASS,T_EXTENDS,T_IMPLEMENTS,T_INSTANCEOF,T_USE,T_NAMESPACE,T_GOTO,'?',':'],true) && $prev!==':' ) { if($tok[0]!==T_STRING) continue; }
        if(in_array($prev,[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON,T_NEW,T_FUNCTION,T_CONST,T_CLASS,T_EXTENDS,T_IMPLEMENTS,T_INSTANCEOF,T_CATCH],true)) continue;
        if(is_array($next) && $next[0]===T_DOUBLE_COLON) continue;            // Kelas::
        if($next==='(') $useF[strtolower($tok[1])][]=$rel.':'.$tok[2];
        elseif(preg_match('/^[A-Z][A-Z0-9_]+$/',$tok[1])) $useC[$tok[1]][]=$rel.':'.$tok[2];
    }
}
$out=[];
foreach($useF as $fn=>$w) if(!isset($defF[$fn]) && !function_exists($fn) && !in_array($fn,$optional,true) && !in_array($fn,['isset','empty','list','array','exit','die','eval','unset','match','fn','echo','print','include','require','require_once','include_once','declare','self','static','parent','catch'],true)) $out[]='fungsi '.$fn.'  '.implode(', ',array_slice(array_unique($w),0,3));
foreach($useC as $c=>$w) if(!isset($defC[$c]) && !defined($c) && !class_exists($c) && !in_array($c,['NULL','TRUE','FALSE'],true)) $out[]='pemalar '.$c.'  '.implode(', ',array_slice(array_unique($w),0,3));
foreach($out as $l) echo $l,"\n";
echo count($out).' simbol tidak ditakrif'.($out?'':' — bersih')."\n";
exit($out?1:0);
