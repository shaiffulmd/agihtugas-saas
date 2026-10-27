<?php
// Ujian indeks beban (masa mengajar + skor tugas berstruktur ÷ median sekolah) pada pemasangan sedia ada (.env).
// Berjalan dalam sekolah sementara UJI9103 (dipadam selepas ujian).
//   php tests/load_index.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }
function rejects(callable $fn,string $label): void { try { $fn(); } catch(EngineError $e){ check(true,$label); return; } throw new RuntimeException('Sepatutnya ditolak: '.$label); }
function near(?float $a,float $b): bool { return $a!==null && abs($a-$b)<0.005; }
/** Guru: [nama, masa, peperiksaan, status, dikecualikan]. Jawatan: [nama, pemberat, tahap, tempoh, indeks guru penyandang|null]. */
function fixture(array $teachers,array $positions): array {
    foreach(['allocation_plans','position_rules','allocation_config','assignments','positions','teachers'] as $t) query("DELETE FROM $t WHERE school_id=".sid());
    $T=[]; foreach($teachers as [$n,$per,$exam,$st,$ex]) $T[]=teacher_save(0,['name'=>$n,'email'=>'','specialty'=>'','capacity'=>50,'teaching_periods'=>$per,'exam_class'=>$exam,'status'=>$st,'index_exempt'=>$ex]);
    foreach($positions as [$n,$w,$lv,$tm,$holder]){ $p=position_save(0,['name'=>$n,'category'=>'HEM','weight'=>$w,'level'=>$lv,'term'=>$tm]); if($holder!==null) assign_position($p,$T[$holder]); }
    return $T;
}

foreach(['UJI9103'] as $c) if($s=school_find($c)) school_delete($s);
[$S]=school_create('UJI9103','SMK Ujian Indeks','SMK','Pentadbir','admin@uji9103.test');
try {
with_school($S,function(){
    echo "Skor tugas & median\n";
    check(position_score(['weight'=>5,'level'=>'sokongan','term'=>'tahunan'])===5.0,'lalai sokongan × tahunan = pemberat (mata lama kekal)');
    check(position_score(['weight'=>9,'level'=>'utama','term'=>'bermusim'])===8.1,'9 × utama 1.5 × bermusim 0.6 = 8.1');
    check(position_score(['weight'=>4,'level'=>'sampingan','term'=>'program'])===0.6,'4 × sampingan 0.5 × program 0.3 = 0.6');
    check(median([3,1,2])===2.0 && median([4,1,3,2])===2.5 && median([])===0.0,'median ganjil, genap & kosong');

    echo "Indeks asas (60% mengajar)\n";
    // Median masa: A28 B30 C26 D24 → (26+28)/2 = 27; skor: A10 B5 C3 D2 → (3+5)/2 = 4.
    $T=fixture([['A',28,false,'aktif',false],['B',30,false,'aktif',false],['C',26,false,'aktif',false],['D',24,false,'aktif',false],['Pengetua',0,false,'aktif',true],['Cuti',30,false,'cuti',false]],
        [['P1',10,'sokongan','tahunan',0],['P2',5,'sokongan','tahunan',1],['P3',2,'utama','tahunan',2],['P4',4,'sampingan','tahunan',3],['P5',8,'utama','tahunan',4],['P6',3,'sokongan','tahunan',5]]);
    $x=load_index(); $t=$x['teachers'];
    check($x['basis']['counted']===4 && near($x['basis']['teaching'],27) && near($x['basis']['score'],4),'median hanya daripada guru aktif yang tidak dikecualikan');
    check(near($t[$T[0]]['index'],round(0.6*28/27+0.4*10/4,2)) && $t[$T[0]]['label']==='terbeban' && in_array('terbeban',$t[$T[0]]['warnings'],true),'A: 0.6×1.04 + 0.4×2.5 = 1.62 → Terbeban');
    check(near($t[$T[3]]['index'],round(0.6*24/27+0.4*2/4,2)) && $t[$T[3]]['label']==='seimbang','D: 0.6×0.89 + 0.4×0.5 = 0.73 → Seimbang');
    check($t[$T[4]]['index']===null && $t[$T[4]]['label']==='dikecualikan','pentadbir dikecualikan tiada indeks walaupun memegang jawatan');
    check($t[$T[5]]['label']==='tidak_aktif' && in_array('tidak_aktif',$t[$T[5]]['warnings'],true),'guru cuti yang memegang lantikan diberi amaran');

    echo "Tetapan\n";
    db()->beginTransaction(); index_settings_save(['teach_share'=>'100','high'=>'1.5','low'=>'0.5','exam_factor'=>'1.2','max_tasks'=>'1']); db()->commit();
    $s=index_settings(); check($s['teach_share']===1.0 && $s['high']===1.5 && $s['exam_factor']===1.2 && $s['max_tasks']===1,'tetapan disimpan & dibaca');
    $t=load_index()['teachers']; check(near($t[$T[0]]['index'],round(28/27,2)),'100% mengajar: indeks = nisbah mengajar sahaja');
    rejects(fn()=>index_settings_save(['teach_share'=>'60','high'=>'1.3','low'=>'1.3','exam_factor'=>'1','max_tasks'=>'8']),'ambang rendah ≥ tinggi ditolak');
    rejects(fn()=>index_settings_save(['teach_share'=>'120','high'=>'1.3','low'=>'0.7','exam_factor'=>'1','max_tasks'=>'8']),'bahagian mengajar > 100 ditolak');
    rejects(fn()=>index_settings_save(['teach_share'=>'60','high'=>'1.3','low'=>'0.7','exam_factor'=>'abc','max_tasks'=>'8']),'faktor bukan nombor ditolak');
    engine_setting_set(['index_high'=>'99']); check(index_settings()['high']===1.3,'nilai rosak dalam settings → lalai');
    db()->beginTransaction(); index_settings_save(['teach_share'=>'60','high'=>'1.3','low'=>'0.7','exam_factor'=>'1.2','max_tasks'=>'1']); db()->commit();

    echo "Kelas peperiksaan, had jawatan & masa kosong\n";
    $T=fixture([['A',30,true,'aktif',false],['B',30,false,'aktif',false],['C',0,false,'aktif',false]],[['P1',5,'sokongan','tahunan',0],['P2',5,'sokongan','tahunan',0],['P3',5,'sokongan','tahunan',1],['P4',5,'sokongan','tahunan',2]]);
    $t=load_index()['teachers'];
    check(near($t[$T[0]]['teaching'],36) && near($t[$T[0]]['teach_ratio'],1.2),'masa kelas peperiksaan × 1.2 (terlaras)');
    check(in_array('banyak',$t[$T[0]]['warnings'],true) && !in_array('banyak',$t[$T[1]]['warnings'],true),'amaran Banyak jawatan melebihi had');
    check(in_array('tiada_masa',$t[$T[2]]['warnings'],true),'amaran masa mengajar belum diisi');

    echo "Median sifar → purata; tiada masa → tugas sahaja\n";
    $T=fixture([['A',0,false,'aktif',false],['B',0,false,'aktif',false],['C',0,false,'aktif',false]],[['P1',6,'sokongan','tahunan',0]]);
    $x=load_index(); $t=$x['teachers'];
    check($x['basis']['score_basis']==='purata' && near($x['basis']['score'],2) && $x['basis']['teaching_basis']==='tiada' && $x['basis']['share']===0.0,'purata digunakan & bahagian mengajar 0');
    check(near($t[$T[0]]['index'],3.0) && near($t[$T[1]]['index'],0.0),'indeks ikut tugas sahaja (6 ÷ 2 = 3.00)');
    $T=fixture([['A',0,false,'aktif',false]],[]); check(load_index()['teachers'][$T[0]]['label']==='belum','tiada data langsung → Belum dikira');

    echo "Pengesahan & agihan automatik\n";
    rejects(fn()=>teacher_save(0,['name'=>'X','email'=>'','specialty'=>'','capacity'=>10,'teaching_periods'=>61]),'masa mengajar > 60 ditolak');
    rejects(fn()=>teacher_save(0,['name'=>'X','email'=>'','specialty'=>'','capacity'=>10,'status'=>'pencen']),'status tidak sah ditolak');
    rejects(fn()=>position_save(0,['name'=>'X','category'=>'HEM','weight'=>3,'level'=>'tinggi']),'tahap tidak sah ditolak');
    $id=teacher_save(0,['name'=>'Kekal','email'=>'','specialty'=>'','capacity'=>10,'teaching_periods'=>20,'status'=>'cuti']);
    teacher_save($id,['name'=>'Kekal Dikemas','email'=>'','specialty'=>'','capacity'=>10]); $r=teacher_find($id);
    check((int)$r['teaching_periods']===20 && $r['status']==='cuti','medan indeks yang tidak dihantar dikekalkan');
    $T=fixture([['Cuti',30,false,'cuti',false],['Aktif',30,false,'aktif',false]],[['P1',3,'sokongan','tahunan',null]]);
    $p=allocation_preview('rebalance'); check($p['rows'][0]['teacher_id']===$T[1],'agihan automatik tidak mencalonkan guru cuti');
});
} finally { if($s=school_find('UJI9103')) school_delete($s); }
echo "\n$checks semakan indeks beban lulus.\n";
