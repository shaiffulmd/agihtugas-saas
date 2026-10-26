<?php
// Ujian klien integrations/koku-multi/UrusTugasClient.php terhadap pelayan UrusTugas yang berjalan (pemasangan UJIAN, .env sama).
//   php tests/integration_client.php KOD http://127.0.0.1:8091/KOD [KOD_SEKOLAH_LAIN]
// Kunci ujian dicipta terus dalam DB dan dipadam selepas ujian. Sekolah mesti mempunyai versi muktamad dan aliran Draf.
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
require dirname(__DIR__).'/integrations/koku-multi/UrusTugasClient.php';
use UrusTugas\Integration\{UrusTugasClient,UrusTugasError};
if(env('APP_ENV')!=='local' && env('DEMO_MODE')!=='1') exit("Pemasangan ujian sahaja.\n");
[$code,$url,$other]=array_pad(array_slice($argv,1),3,'');
$s=school_find((string)$code) ?? exit("Sekolah $code tiada.\n"); $o=$other!==''?(school_find($other) ?? exit("Sekolah $other tiada.\n")):null;
$ok=0; function check(bool $c,string $l): void { global $ok; if(!$c){ fwrite(STDERR,"GAGAL: $l\n"); exit(1); } $ok++; echo "  ✓ $l\n"; }
function code_of(callable $fn): string { try { $fn(); return 'tiada-ralat'; } catch(UrusTugasError $e){ return $e->apiCode; } catch(Throwable $e){ return get_class($e).': '.$e->getMessage(); } }
$mk=fn(array $sc,string $scope)=>with_school($sc,fn()=>api_key_create('Ujian klien '.$scope,$scope)[1]);
$w=$mk($s,'write'); $r=$mk($s,'read'); $x=$o?$mk($o,'write'):null; $synced=null;
try {
    echo "Pembina\n";
    foreach([['ftp://x/CEA1234',$w],['http://contoh.my/CEA1234',$w],['https://contoh.my/bukan-kod',$w],['https://contoh.my',$w],[$url,'agt_'.str_repeat('a',64)]] as [$u,$k])
        check(code_of(fn()=>new UrusTugasClient($u,$k))!=='tiada-ralat','ditolak: '.$u.' / '.substr($k,0,4).'…');
    $c=new UrusTugasClient($url,$w);
    echo "Bacaan\n";
    $sum=$c->summary(); check($sum['teachers']===free_count('guru',(int)$s['id']),'summary: '.$sum['teachers'].' guru');
    check($c->school()['school']===$s['name'],'profil sekolah');
    check(count($c->all('teachers'))===$sum['teachers'] && count($c->all('positions'))===$sum['positions'],'all() berhalaman: guru & jawatan');
    $pub=$c->publishedAllocation(); check($pub['revision_id']>0 && isset($pub['snapshot']['teachers']),'versi muktamad');
    $tid=(int)$pub['snapshot']['teachers'][0]['id']; $letter=$c->publishedLetter($tid);
    check($letter['teacher']['id']===$tid && isset($letter['school']['branding']),'surat muktamad guru #'.$tid);
    $crest=$letter['school']['branding']['national_crest']['id']??null;
    check($crest!==null && str_starts_with($c->assetBytes($crest),"\x89PNG"),'assetBytes: Jata Negara PNG');
    check(isset($c->allocationRules()['general']['max_tasks']),'syarat auto-agihan');
    echo "Tulisan\n";
    $t1=$c->syncKokuTeacher(77,['id'=>5,'name'=>'Guru Koku Ujian','email'=>'koku5@ujian.test']); $synced=(int)$t1['id'];
    check($t1['external_id']==='koku-multi:77:5' && $t1['capacity']===25,'syncKokuTeacher: guru baharu (25 mata)');
    $t2=$c->syncTeacher('koku-multi:77:5','Guru Koku Dikemas','koku5@ujian.test');
    check($t2['id']===$synced && $t2['name']==='Guru Koku Dikemas','sync semula: guru sama dikemas kini');
    $pos=$c->all('positions'); $vac=array_values(array_filter($pos,fn($p)=>$p['teacher_id']===null));
    if($vac){ $a=$c->assign($vac[0]['id'],$synced); check($a['teacher_id']===$synced,'assign jawatan kosong'); check(count($c->teacherAssignments($synced))===1,'teacherAssignments');
        with_school($s,fn()=>query('DELETE FROM assignments WHERE school_id='.sid().' AND teacher_id=?',[$synced])); }
    $plan=$c->previewAllocation('vacant'); check(strlen($plan['plan_id'])===48,'previewAllocation');
    echo "Ralat & sempadan\n";
    check(code_of(fn()=>(new UrusTugasClient($url,$r))->syncTeacher('x:1','X'))==='insufficient_scope','kunci baca: insufficient_scope');
    check(code_of(fn()=>$c->request('POST','/teachers',['name'=>'X','capacity'=>10,'external_id'=>'koku-multi:77:5']))==='duplicate_external_id','duplicate_external_id');
    if($x) check(code_of(fn()=>(new UrusTugasClient($url,$x))->summary())==='unauthorized','kunci sekolah lain → unauthorized');
    $h=parse_url($url); $port=isset($h['port'])?':'.$h['port']:'';
    if(platform_domain()==='localhost'.$port){ // bentuk URL lain (bukan utama) → pelayan melencong; klien tidak mengikut lencongan dengan kunci
        $alt=tenant_primary($s['code'])==='path'?$h['scheme'].'://'.strtolower($s['code']).'.localhost'.$port:$h['scheme'].'://localhost'.$port.'/'.$s['code'];
        $msg=code_of(fn()=>(new UrusTugasClient($alt,$w))->summary());
        check(str_contains($msg,'melencong'),'URL bukan bentuk utama ('.$alt.') → ralat lencongan, kunci tidak diikut');
    }
    check(code_of(fn()=>$c->request('GET','/../platform'))!=='tiada-ralat','endpoint tidak sah ditolak');
} finally {
    with_school($s,function() use($synced){ if($synced) query('DELETE FROM teachers WHERE school_id='.sid().' AND id=? AND NOT EXISTS (SELECT 1 FROM assignments a WHERE a.teacher_id=teachers.id)',[$synced]); query('DELETE FROM api_keys WHERE school_id='.sid().' AND name LIKE "Ujian klien %"'); });
    if($o) with_school($o,fn()=>query('DELETE FROM api_keys WHERE school_id='.sid().' AND name LIKE "Ujian klien %"'));
}
echo "\n$ok semakan klien integrasi lulus.\n";
