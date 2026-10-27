<?php
// Ujian guru ditanda semasa dikembalikan: tandakan selesai / batal, dibawa semasa dihantar semula, dikosongkan selepas lulus.
// Berjalan dalam sekolah sementara UJF9107 (dipadam selepas ujian).
//   php tests/workflow_flags.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }
function rejects(callable $fn,string $label): void { try { $fn(); } catch(EngineError $e){ check(true,$label); return; } throw new RuntimeException('Sepatutnya ditolak: '.$label); }
function tx(callable $fn): mixed { db()->beginTransaction(); try { $r=$fn(); db()->commit(); return $r; } catch(Throwable $e){ db()->rollBack(); throw $e; } }

if($s=school_find('UJF9107')) school_delete($s);
[$S]=school_create('UJF9107','SMK Ujian Tanda','SMK','Pentadbir','admin@ujf9107.test');
try {
with_school($S,function(){
    $T=[]; foreach(['Guru Berat','Guru Sederhana','Guru Ringan'] as $n) $T[]=teacher_save(0,['name'=>$n,'email'=>'','specialty'=>'','capacity'=>20]);
    foreach([[9,0],[8,0],[5,1]] as $i=>[$w,$h]) assign_position(position_save(0,['name'=>'Jawatan '.$i,'category'=>'HEM','weight'=>$w]),$T[$h]);
    query('INSERT INTO users(school_id,name,email,password_hash,role) VALUES ('.sid().',"Pengetua","head@ujf9107.test","x","head")'); $head=['id'=>(int)db()->lastInsertId(),'name'=>'Pengetua','role'=>'head'];
    $admin=query('SELECT id,name,role FROM users WHERE school_id='.sid().' AND role="admin"')->fetch();
    $ver=fn()=>workflow_state()['version'];

    echo "Dikembalikan dengan guru ditanda\n";
    tx(fn()=>workflow_transition('submit',$ver(),$admin));
    tx(fn()=>workflow_transition('return',$ver(),$head,'Kurangkan beban',[$T[0],$T[1]]));
    $ri=workflow_return_info(); check($ri['flags']===[$T[0],$T[1]] && $ri['resolved']===[],'2 guru ditanda, belum selesai');
    check(workflow_flagged()===[$T[0]=>true,$T[1]=>true],'kedua-dua guru diserlahkan');

    echo "Tandakan selesai\n";
    tx(fn()=>workflow_flag_resolve($T[0],true,$admin));
    $ri=workflow_return_info(); check(array_keys($ri['resolved'])===[$T[0]] && $ri['resolved'][$T[0]]['by']===$admin['name'],'guru pertama ditanda selesai (siapa & bila direkod)');
    check(workflow_flagged()===[$T[1]=>true],'guru selesai tidak lagi diserlahkan');
    tx(fn()=>workflow_flag_resolve($T[0],false,$admin)); check(workflow_return_info()['resolved']===[],'batal tanda selesai');
    rejects(fn()=>tx(fn()=>workflow_flag_resolve($T[2],true,$admin)),'guru yang tidak ditanda ditolak');
    tx(fn()=>workflow_flag_resolve($T[0],true,$admin)); tx(fn()=>workflow_flag_resolve($T[1],true,$admin));
    check(workflow_flagged()===[],'semua guru ditanda selesai');

    echo "Hantar semula & lulus\n";
    tx(fn()=>workflow_transition('submit',$ver(),$admin));
    check(workflow_return_info()===null,'notis draf hilang selepas dihantar');
    $sub=workflow_return_info('submitted'); check($sub && count($sub['resolved'])===2 && $sub['reason']==='Kurangkan beban','penyemak nampak guru ditanda & tanda selesai');
    rejects(fn()=>tx(fn()=>workflow_flag_resolve($T[0],false,$admin)),'tanda selesai tidak boleh diubah selepas dihantar');
    tx(fn()=>workflow_transition('approve',$ver(),$head)); check(workflow_state()['status']==='approved' && workflow_return_info('submitted')===null,'diluluskan; maklumat pemulangan dikosongkan');

    echo "Guru ditanda dipadam\n";
    tx(fn()=>workflow_transition('return',$ver(),$head,'Semak semula',[$T[0],$T[1]]));
    tx(function()use($T){ query('DELETE FROM assignments WHERE school_id='.sid().' AND teacher_id=?',[$T[1]]); teacher_delete($T[1]); });
    check(workflow_return_info()['flags']===[$T[0]] && workflow_flagged()===[$T[0]=>true],'guru dipadam (cth. bertukar) tidak lagi dikira ditanda');
    tx(fn()=>workflow_flag_resolve($T[0],true,$admin)); check(workflow_flagged()===[] && count(workflow_return_info()['resolved'])===1,'baki guru ditanda selesai → semua selesai boleh dicapai');
});
} finally { if($s=school_find('UJF9107')) school_delete($s); }
echo "\n$checks semakan guru ditanda lulus.\n";
