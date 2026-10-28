<?php
// Ujian surat semasa percubaan: kenal pasti sekolah percubaan, tetapan global & khusus sekolah, had muat turun PDF (atomik), tanda air.
// Berjalan dalam sekolah sementara UJR9110 (percubaan), UJR9111 (berbayar) & UJR9112 (demo); tetapan platform asal dipulihkan.
//   php tests/trial_letters.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }
function tx(callable $fn): mixed { db()->beginTransaction(); try { $r=$fn(); db()->commit(); return $r; } catch(Throwable $e){ db()->rollBack(); throw $e; } }

$codes=['UJR9110','UJR9111','UJR9112']; foreach($codes as $c) if($s=school_find($c)) school_delete($s);
$keys=['percubaan_cetak','percubaan_pdf','percubaan_pdf_had','percubaan_tanda_air']; $orig=array_intersect_key(pset_all(),array_flip($keys));
[$T]=school_create('UJR9110','SMK Ujian Percubaan','SMK','Pentadbir','admin@ujr9110.test');
[$P]=school_create('UJR9111','SMK Ujian Berbayar','SMK','Pentadbir','admin@ujr9111.test');
[$D]=school_create('UJR9112','SMK Ujian Demo','SMK','Pentadbir','admin@ujr9112.test');
try {
    echo "Sekolah percubaan\n";
    query('INSERT INTO payments (school_id,school_code,method,amount_sen,status,paid_at) VALUES (?,?,"manual",12000,"berjaya",NOW())',[$P['id'],$P['code']]);
    with_school($D,fn()=>sys_set('demo','1'));
    $T=school_find('UJR9110'); $P=school_find('UJR9111'); $D=school_find('UJR9112');
    check(school_plan($T)==='pro' && school_on_trial($T),'Pro tanpa bayaran = percubaan');
    check(!school_on_trial($P) && trial_letter($P)===null,'sekolah berbayar: tiada sekatan');
    check(!school_on_trial($D),'sekolah demo dikecualikan');

    echo "Tetapan global & khusus sekolah\n";
    pset_set(['percubaan_cetak'=>'1','percubaan_pdf'=>'1','percubaan_pdf_had'=>'0','percubaan_tanda_air'=>'0']);
    with_school($T,function(){ check(trial_letter()['cetak'] && trial_letter()['baki']===null && trial_letter_notice(trial_letter())==='','lalai: tiada sekatan & tiada notis'); });
    pset_set(['percubaan_cetak'=>'0','percubaan_pdf_had'=>'2','percubaan_tanda_air'=>'1']);
    with_school($T,function(){ $t=trial_letter(); check(!$t['cetak'] && $t['had']===2 && $t['baki']===2 && $t['tanda'] && $t['sumber']==='global','tetapan global: cetak dimatikan, had 2, tanda air');
        check(str_contains(trial_letter_notice($t),'cetak surat dimatikan') && str_contains(trial_letter_notice($t),'2 daripada 2 berbaki'),'notis percubaan dipaparkan'); });
    with_school($T,fn()=>sys_set('percubaan_surat',json_encode(['cetak'=>true,'pdf'=>true,'had'=>3,'tanda'=>false])));
    with_school($T,function(){ $t=trial_letter(); check($t['cetak'] && $t['had']===3 && !$t['tanda'] && $t['sumber']==='sekolah','tetapan khusus sekolah mengatasi tetapan global'); });

    echo "Had muat turun PDF\n";
    with_school($T,function(){
        check(trial_pdf_take()===null && trial_pdf_take()===null && trial_pdf_take()===null,'3 muat turun dibenarkan (had 3)');
        $m=trial_pdf_take(); check($m!==null && str_contains($m,'(3)'),'muat turun ke-4 ditolak');
        check(trial_letter()['guna']===3 && trial_letter()['baki']===0,'kiraan tidak melebihi had');
        sys_set('percubaan_pdf_kira','0'); check(trial_pdf_take()===null,'set semula kiraan: boleh muat turun semula');
        sys_set('percubaan_surat',json_encode(['cetak'=>false,'pdf'=>false,'had'=>0,'tanda'=>true]));
        check(str_contains((string)trial_pdf_take(),'dimatikan') && trial_letter()['baki']===0,'PDF dimatikan: ditolak tanpa mengira');
    });
    with_school($P,function(){ check(trial_pdf_take()===null && trial_pdf_take()===null && sys_get('percubaan_pdf_kira')==='','sekolah berbayar: tiada had & tiada kiraan'); });

    echo "Tanda air\n";
    with_school($T,function(){
        $t=teacher_save(0,['name'=>'Guru Percubaan','email'=>'','specialty'=>'','capacity'=>20]); assign_position(position_save(0,['name'=>'Setiausaha Peperiksaan','category'=>'Kurikulum','weight'=>5]),$t);
        $admin=query('SELECT id,name,role FROM users WHERE school_id='.sid().' AND role="admin"')->fetch();
        query('INSERT INTO users(school_id,name,email,password_hash,role) VALUES ('.sid().',"Pengetua","head@ujr9110.test","x","head")'); $hd=['id'=>(int)db()->lastInsertId(),'name'=>'Pengetua','role'=>'head'];
        tx(fn()=>workflow_transition('submit',workflow_state()['version'],$admin)); tx(fn()=>workflow_transition('approve',workflow_state()['version'],$hd)); tx(fn()=>workflow_transition('finalize',workflow_state()['version'],$hd));
        $h=letter_html(letter_docs(workflow_published())[0],fn($a)=>''); check(str_contains($h,'class="letter is-trial"'),'surat muktamad bertanda air "PERCUBAAN"');
        sys_set('percubaan_surat',json_encode(['cetak'=>true,'pdf'=>true,'had'=>0,'tanda'=>false]));
        check(!str_contains(letter_html(letter_docs(workflow_published())[0],fn($a)=>''),'is-trial'),'tanda air dimatikan: surat biasa');
    });
} finally {
    pset_set($orig);
    foreach($codes as $c) if($s=school_find($c)) school_delete($s);
}
echo "\n$checks semakan surat percubaan lulus.\n";
