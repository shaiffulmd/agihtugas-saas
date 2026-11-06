<?php
// Ujian jawatan utama sekolah (Pengetua, PK, Ketua Bidang) & data carta organisasi ikut bidang.
// Berjalan dalam sekolah sementara UJO9111 (SMK) & UJP9112 (SK), dipadam selepas ujian.
//   php tests/organisasi.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan','organisasi'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }
function rejects(callable $fn,string $label): void { try { $fn(); } catch(EngineError $e){ check(true,$label); return; } throw new RuntimeException('Sepatutnya ditolak: '.$label); }

foreach(['UJO9111','UJP9112','UJQ9113'] as $c) if($s=school_find($c)) school_delete($s);
[$A]=school_create('UJO9111','SMK Ujian Organisasi','SMK','Pentadbir','admin@ujo9111.test');
[$B]=school_create('UJP9112','SK Ujian Organisasi','SK','Pentadbir','admin@ujp9112.test');
[$C]=school_create('UJQ9113','SMK Ujian Amalan','SMK','Pentadbir','admin@ujq9113.test');
try {
with_school($A,function(){
    echo "Lalai\n";
    $l=leadership(); $posts=leader_posts();
    check(array_keys($posts)===['pengetua','pk_pentadbiran','pk_hem','pk_koku','pk_t6','pk_ppki'] && $posts['pengetua']==='Pengetua','SMK: Pengetua + 5 PK termasuk Tingkatan 6 & PPKI');
    check(!array_filter($l['posts']) && array_column($l['bidang'],0)===KETUA_BIDANG_DEFAULT,'belum ditetapkan; 4 Ketua Bidang KPM lalai');

    $t=[]; foreach(['Aminah','Badrul','Chong','Devi','Ezani','Farah','Ghani'] as $n) $t[$n]=teacher_save(0,['name'=>"Cikgu $n",'email'=>'','specialty'=>'','capacity'=>20]);
    echo "Simpan\n";
    $n=leadership_save(['pengetua'=>(string)$t['Aminah'],'pk_pentadbiran'=>(string)$t['Badrul'],'pk_hem'=>(string)$t['Chong'],'pk_koku'=>'','pk_t6'=>null],[['Bahasa',(string)$t['Devi']],['  Sains  &  Matematik ',(string)$t['Ezani']],['',(string)$t['Farah']],['Kemanusiaan','']]);
    $l=leadership();
    check($n===5 && $l['posts']['pengetua']===$t['Aminah'] && $l['posts']['pk_koku']===null,'jawatan disimpan; 5 guru ditanda Pentadbir');
    check(array_column($l['bidang'],0)===['Bahasa','Sains & Matematik','Kemanusiaan'] && $l['bidang'][1][1]===$t['Ezani'],'bidang: ruang dikemaskan, nama kosong dibuang');
    check((int)teacher_find($t['Devi'])['index_exempt']===1 && (int)teacher_find($t['Farah'])['index_exempt']===0,'Ketua Bidang ditanda Pentadbir; guru bidang dibuang tidak');
    check(setting('principal')==='Cikgu Aminah','nama Pengetua mengisi nama pada surat');
    check((int)query('SELECT COUNT(*) FROM assignments WHERE school_id='.sid())->fetchColumn()===0 && (int)query('SELECT COUNT(*) FROM positions WHERE school_id='.sid())->fetchColumn()===0,'jawatan utama bukan lantikan / jawatan katalog — tiada surat lantikan (dilantik JPN)');
    check(leadership_by_teacher()[$t['Chong']]==='PK HEM' && leadership_by_teacher()[$t['Devi']]==='Ketua Bidang Bahasa','lencana jawatan utama setiap guru (label pendek PK …)');
    check(leader_posts(true)['pk_pentadbiran']==='PK Pentadbiran' && leader_posts()['pk_pentadbiran']==='Penolong Kanan Pentadbiran' && leader_posts(true)['pengetua']==='Pengetua','label pendek untuk borang, penuh untuk carta');
    rejects(fn()=>leadership_save(['pengetua'=>(string)$t['Aminah'],'pk_hem'=>(string)$t['Aminah']],[]),'seorang guru dua jawatan utama ditolak');
    rejects(fn()=>leadership_save(['pk_hem'=>'999999'],[]),'guru tidak wujud ditolak');
    rejects(fn()=>leadership_save(['ketua_kantin'=>(string)$t['Ghani']],[]),'jawatan tidak dikenali ditolak');
    rejects(fn()=>leadership_save([],[['Bahasa',null],['bahasa',null]]),'bidang berulang ditolak');
    leadership_save(['pengetua'=>(string)$t['Aminah'],'pk_pentadbiran'=>(string)$t['Badrul'],'pk_hem'=>(string)$t['Chong'],'pk_koku'=>(string)$t['Ghani']],[]);
    check(leadership()['bidang']===[],'semua bidang boleh dibuang (tidak kembali ke lalai)');

    echo "Carta organisasi\n";
    $p1=position_save(0,['name'=>'SU Kurikulum','category'=>'Pentadbiran','weight'=>5]); position_save(0,['name'=>'Ketua Panitia Sejarah','category'=>'Kurikulum','weight'=>4]);
    $p3=position_save(0,['name'=>'Penyelaras Kantin','category'=>'HEM','weight'=>3]); position_save(0,['name'=>'Setiausaha Sukan','category'=>'Kokurikulum','weight'=>5]);
    assign_position($p1,$t['Farah']); assign_position($p3,$t['Devi']);
    $c=org_chart_data(); $g=array_column($c['groups'],null,'key');
    check($c['head']===['label'=>'Pengetua','teacher'=>'Cikgu Aminah'],'Pengetua di puncak');
    check(array_keys($g['pk_pentadbiran']['cats'])===['Pentadbiran','Kurikulum'] && array_keys($g['pk_hem']['cats'])===['HEM'] && array_keys($g['pk_koku']['cats'])===['Kokurikulum'],'bidang tugas di bawah PK masing-masing');
    check($g['pk_pentadbiran']['cats']['Pentadbiran'][0]===['name'=>'SU Kurikulum','teacher'=>'Cikgu Farah'] && $g['pk_pentadbiran']['cats']['Kurikulum'][0]['teacher']===null,'penyandang semasa / belum diisi');
    check($c['total']===4 && $c['filled']===2,'kiraan jawatan diisi');
    check(array_column($c['pk'],'key')===['pk_pentadbiran','pk_hem','pk_koku'],'PK T6 & PPKI tidak dipaparkan jika kosong');
    teacher_delete($t['Ghani']); check(leadership()['posts']['pk_koku']===null,'guru dipadam → jawatan utama dikosongkan');
});
echo "Sekolah rendah\n";
with_school($B,function(){
    $p=leader_posts(); check($p['pengetua']==='Guru Besar' && !isset($p['pk_t6']) && isset($p['pk_ppki']),'SK: Guru Besar, tiada PK Tingkatan 6');
    check(leadership()['bidang']===[],'SK: tiada Ketua Bidang lalai');
    check(!array_filter(leadership()['posts']),'sekolah lain tidak nampak jawatan utama sekolah A');
});
echo "Amalan sekolah\n";
with_school($C,function(){
    $id=fn(string $n)=>teacher_save(0,['name'=>$n,'email'=>'','specialty'=>'','capacity'=>25]);
    $head=$id('Pengetua X'); $pk=$id('PK Y'); $kb=$id('KB Z'); $g1=$id('Guru Satu'); $g2=$id('Guru Dua');
    query('UPDATE teachers SET teaching_periods=0 WHERE school_id='.sid()); // indeks ikut skor tugas sahaja
    leadership_save(['pengetua'=>(string)$head,'pk_pentadbiran'=>(string)$pk],[['Bahasa',(string)$kb]]);
    allocation_save_rules(['respect_capacity'=>false,'max_tasks'=>50,'category_limits'=>array_fill_keys(CATEGORIES,50)]);
    $ps=[]; for($i=1;$i<=8;$i++) $ps[]=position_save(0,['name'=>"AJK $i",'category'=>'Pentadbiran','weight'=>1]);
    $pv=allocation_preview('rebalance'); $who=array_column($pv['rows'],'teacher_id');
    check(!in_array($head,$who,true) && count(array_filter($who))===8,'agihan automatik tidak memberi tugas kepada Pengetua');
    $row=array_column($pv['rows'],null,'position_id');
    assign_position($ps[0],$head); check(position_find($ps[0]) && (int)query('SELECT teacher_id FROM assignments WHERE school_id='.sid().' AND position_id='.$ps[0])->fetchColumn()===$head,'lantikan manual kepada Pengetua masih dibenarkan');

    echo "Kapasiti panduan\n";
    query('UPDATE teachers SET status="bertukar" WHERE school_id='.sid().' AND id='.$g2);
    $n=capacity_apply_guide('smk'); $cap=array_column(query('SELECT id,capacity FROM teachers WHERE school_id='.sid())->fetchAll(),'capacity','id');
    check($n===4 && (int)$cap[$head]===12 && (int)$cap[$pk]===12 && (int)$cap[$kb]===15 && (int)$cap[$g1]===20 && (int)$cap[$g2]===25,'Pengetua/PK 12, Ketua Bidang 15, guru lain 20; guru bertukar tidak diubah');
    check(capacity_apply_guide('smk')===0,'jalan semula: tiada perubahan');

    echo "Guru bertukar keluar\n";
    assign_position($ps[1],$g2); assign_position($ps[2],$g1);
    check(array_column(assignments_of_transferred(),'teacher_id')===[$g2],'lantikan guru bertukar dikesan');
    $admin=(int)query('SELECT id FROM users WHERE school_id='.sid().' AND role="admin"')->fetchColumn();
    try { workflow_transition('submit',workflow_state()['version'],['id'=>$admin,'role'=>'admin']); throw new RuntimeException('GAGAL: hantar sepatutnya ditolak'); } catch(WorkflowError $e){ check(str_contains($e->getMessage(),'bertukar keluar'),'hantar untuk semakan ditolak selagi guru bertukar memegang jawatan'); }
    $pv=allocation_preview('vacant'); $row=array_column($pv['rows'],null,'position_id');
    check($row[$ps[1]]['before_teacher_id']===$g2 && $row[$ps[1]]['teacher_id']!==null && $row[$ps[1]]['teacher_id']!==$g2 && $row[$ps[2]]['teacher_id']===$g1,'Isi jawatan kosong: lantikan guru bertukar dilepas & diisi semula; lantikan lain dikekalkan');
    check((bool)array_filter($pv['warnings'],fn($w)=>str_contains($w,'bertukar keluar')),'amaran pelepasan dipaparkan');
    allocation_apply($pv['plan_id']); check(!assignments_of_transferred(),'selepas disimpan tiada lagi lantikan guru bertukar');
    $st=workflow_transition('submit',workflow_state()['version'],['id'=>$admin,'role'=>'admin']); check(workflow_state()['status']==='submitted','draf boleh dihantar selepas dibaiki');
});
} finally { foreach([$A,$B,$C] as $s) school_delete($s); }
echo "\n$checks semakan carta organisasi lulus.\n";
