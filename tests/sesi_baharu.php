<?php
// Ujian Mula sesi baharu: draf dibuka daripada versi muktamad, surat sesi lepas kekal & boleh dicetak semula (pemilih versi),
// pilihan kekalkan / kosongkan lantikan, lepaskan guru bertukar, kosongkan masa mengajar.
// Berjalan dalam sekolah sementara UJS9114 (dipadam selepas ujian).
//   php tests/sesi_baharu.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan','organisasi'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }
function rejects(callable $fn,string $label,string $class=EngineError::class): void { try { $fn(); } catch(Throwable $e){ if($e instanceof $class){ check(true,$label); return; } throw $e; } throw new RuntimeException('Sepatutnya ditolak: '.$label); }
function tx(callable $fn): mixed { db()->beginTransaction(); try { $r=$fn(); db()->commit(); return $r; } catch(Throwable $e){ db()->rollBack(); throw $e; } }

if($s=school_find('UJS9114')) school_delete($s);
[$S]=school_create('UJS9114','SMK Ujian Sesi','SMK','Pentadbir','admin@ujs9114.test');
try {
with_school($S,function(){
    engine_setting_set(['session_year'=>'2026','letter_effective'=>'2026-01-05']);
    $T=[]; foreach(['Guru Satu','Guru Dua','Guru Tiga'] as $n) $T[]=teacher_save(0,['name'=>$n,'email'=>'','specialty'=>'','capacity'=>20]);
    query('UPDATE teachers SET teaching_periods=24,exam_class=1 WHERE school_id='.sid());
    $P=[]; foreach(['SU Kurikulum','Penyelaras Kantin','Setiausaha Sukan'] as $i=>$n){ $P[]=position_save(0,['name'=>$n,'category'=>CATEGORIES[$i],'weight'=>3]); assign_position($P[$i],$T[$i]); }
    query('INSERT INTO users(school_id,name,email,password_hash,role) VALUES ('.sid().',"Pengetua","head@ujs9114.test","x","head")'); $head=['id'=>(int)db()->lastInsertId(),'name'=>'Pengetua','role'=>'head'];
    $admin=query('SELECT id,name,role FROM users WHERE school_id='.sid().' AND role="admin"')->fetch(); $pk=['id'=>$admin['id'],'name'=>'PK','role'=>'pk'];
    $ver=fn()=>workflow_state()['version'];
    $finalize=function()use($ver,$admin,$head){ tx(fn()=>workflow_transition('submit',$ver(),$admin)); tx(fn()=>workflow_transition('approve',$ver(),$head)); tx(fn()=>workflow_transition('finalize',$ver(),$head)); };
    $finalize(); $v2026=workflow_published(); check($v2026['data']['school']['year']==='2026' && !$v2026['archived'],'sesi 2026 dimuktamadkan');

    echo "Syarat\n";
    rejects(fn()=>tx(fn()=>session_start_new(2027,[],$pk)),'PK tidak boleh memulakan sesi baharu',WorkflowError::class);
    rejects(fn()=>tx(fn()=>session_start_new(2026,[],$admin)),'tahun sama dengan sesi semasa ditolak',WorkflowError::class);

    echo "Sesi 2027 (kekalkan lantikan)\n";
    query('UPDATE teachers SET status="bertukar" WHERE school_id='.sid().' AND id='.$T[1]);
    $r=tx(fn()=>session_start_new(2027,['release_transferred'=>true],$admin));
    check(workflow_state()['status']==='draft' && setting('session_year')==='2027','draf dibuka; label sesi 2027');
    check($r['released']===1 && (int)query('SELECT COUNT(*) FROM assignments WHERE school_id='.sid())->fetchColumn()===2,'lantikan guru bertukar dilepaskan, lain dikekalkan');
    check((int)query('SELECT teaching_periods FROM teachers WHERE id='.$T[0])->fetchColumn()===24,'masa mengajar dikekalkan jika tidak dipilih');
    $pub=workflow_published(); check($pub['id']===$v2026['id'] && $pub['data']['school']['year']==='2026','surat & Tugas Saya masih sesi 2026 sehingga 2027 dimuktamadkan');
    check(workflow_letter($T[1],$pub)['assignments'][0]['name']==='Penyelaras Kantin','surat 2026 guru yang kini bertukar masih boleh dicetak');

    echo "Semakan sedang berjalan\n";
    tx(fn()=>workflow_transition('submit',$ver(),$admin));
    rejects(fn()=>tx(fn()=>session_start_new(2028,[],$admin)),'sesi baharu ditolak semasa agihan dalam semakan',WorkflowError::class);
    tx(fn()=>workflow_transition('approve',$ver(),$head)); engine_setting_set(['letter_effective'=>'2027-01-04']); tx(fn()=>workflow_transition('finalize',$ver(),$head));

    echo "Pemilih versi\n";
    $list=workflow_finalized_list(); check(array_column($list,'year')===['2027','2026'] && $list[0]['latest'] && !$list[1]['latest'],'senarai versi muktamad ikut sesi (terkini dahulu)');
    $old=workflow_published($v2026['id']); check($old['archived'] && $old['data']['school']['year']==='2026','versi 2026 dibuka sebagai arkib');
    $L=workflow_letter($T[0],$old); check($L['school']['letter']['effective']==='2026-01-05','surat arkib guna tarikh berkuat kuasa sesi itu, bukan format semasa');
    check(workflow_letter($T[0],workflow_published())['school']['letter']['effective']==='2027-01-04','surat terkini guna format semasa');
    check(workflow_published(999999999)===null,'versi tidak wujud → null');

    echo "Sesi 2028 (kosongkan semua)\n";
    $r=tx(fn()=>session_start_new(2028,['clear_all'=>true,'reset_periods'=>true],$admin));
    check($r['cleared']===2 && !(int)query('SELECT COUNT(*) FROM assignments WHERE school_id='.sid())->fetchColumn(),'semua lantikan dikosongkan');
    check($r['periods']===3 && !(int)query('SELECT SUM(teaching_periods)+SUM(exam_class) FROM teachers WHERE school_id='.sid())->fetchColumn(),'masa mengajar & kelas peperiksaan dikosongkan');
    check((int)query('SELECT COUNT(*) FROM positions WHERE school_id='.sid())->fetchColumn()===3,'katalog jawatan dikekalkan');
    check(count(workflow_finalized_list())===2,'versi 2026 & 2027 kekal dalam arkib');
});
} finally { school_delete($S); }
echo "\n$checks semakan sesi baharu lulus.\n";
