<?php
// Pembantu ujian pakej (pemasangan UJIAN sahaja — mengubah tetapan platform & tarikh tamat sekolah).
//   php tests/plan_helper.php free KOD | pro KOD          → tamatkan Pro semalam / Pro setahun
//   php tests/plan_helper.php limit guru|jawatan|pengguna N | features auto,api,…  | reset  → had & ciri Pro (reset = lalai)
//   php tests/plan_helper.php count KOD                     → bilangan guru/jawatan/pengguna (JSON)
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
[$cmd,$a,$b]=array_pad(array_slice($argv,1),3,'');
$school=fn(string $c)=>school_find($c) ?? exit("Sekolah $c tiada.\n");
match($cmd){
    'free'=>query('UPDATE schools SET expires_on=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE id=?',[$school($a)['id']]),
    'pro'=>query('UPDATE schools SET expires_on=DATE_ADD(CURDATE(),INTERVAL 1 YEAR) WHERE id=?',[$school($a)['id']]),
    'limit'=>pset_set(['free_'.$a=>(string)(int)$b]),
    'features'=>pset_set(['pro_ciri'=>$a]),
    'reset'=>pset_set(['free_guru'=>PSET_DEFAULTS['free_guru'],'free_jawatan'=>PSET_DEFAULTS['free_jawatan'],'free_pengguna'=>PSET_DEFAULTS['free_pengguna'],'pro_ciri'=>PSET_DEFAULTS['pro_ciri'],'free_aktif'=>'1']),
    'count'=>print(json_encode(['guru'=>free_count('guru',(int)$school($a)['id']),'jawatan'=>free_count('jawatan',(int)$school($a)['id']),'pengguna'=>free_count('pengguna',(int)$school($a)['id'])])),
    default=>exit("Arahan tidak dikenali.\n"),
};
echo "\n";
