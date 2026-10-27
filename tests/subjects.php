<?php
// Ujian katalog mata pelajaran (tambah, sunting + kemas kini kepakaran guru, padam, import senarai standard, pengasingan sekolah).
// Berjalan dalam sekolah sementara UJM9105 & UJN9106 (dipadam selepas ujian).
//   php tests/subjects.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }
function rejects(callable $fn,string $label): void { try { $fn(); } catch(EngineError $e){ check(true,$label); return; } throw new RuntimeException('Sepatutnya ditolak: '.$label); }

foreach(['UJM9105','UJN9106'] as $c) if($s=school_find($c)) school_delete($s);
[$A]=school_create('UJM9105','SMK Ujian MP','SMK','Pentadbir','admin@ujm9105.test');
[$B]=school_create('UJN9106','SK Ujian MP','SK','Pentadbir','admin@ujn9106.test');
try {
with_school($A,function(){
    echo "Senarai standard\n";
    $all=fn(string $k)=>array_merge(...array_values(SUBJECT_TEMPLATES[$k][3]));
    foreach(SUBJECT_TEMPLATES as $k=>$t){ $names=$all($k); check(count($names)===count(array_unique(array_map('mb_strtolower',$names))) && !array_filter($names,fn($n)=>str_contains($n,',') || mb_strlen($n)>100),"templat $k: tiada pendua, koma atau nama > 100 aksara"); }
    $n=subjects_import('kssm',$all('kssm')); check($n===count($all('kssm')),'import KSSM penuh');
    $n=subjects_import('kp2027_sm',$all('kp2027_sm')); check($n===4,'import KP2027 menengah: hanya MP baharu ditambah (yang sama dilangkau)');
    rejects(fn()=>subjects_import('kssm',$all('kssm')),'import semula tanpa MP baharu ditolak');
    rejects(fn()=>subjects_import('tiada',['Matematik']),'templat tidak sah ditolak');
    check((string)query('SELECT grp FROM subjects WHERE school_id='.sid().' AND name="Fizik"')->fetchColumn()==='STEM (elektif)','kumpulan dibawa daripada templat');

    echo "Tambah, sunting & padam\n";
    rejects(fn()=>subject_save(0,['name'=>'Sains, Teknologi']),'nama dengan koma ditolak');
    rejects(fn()=>subject_save(0,['name'=>'matematik']),'pendua (tanpa beza huruf) ditolak');
    [$id]=subject_save(0,['name'=>'Pendidikan  Kewangan','grp'=>'Elektif sekolah']); check(subject_find($id)['name']==='Pendidikan Kewangan','MP baharu disimpan (ruang berganda dikemaskan)');
    $t1=teacher_save(0,['name'=>'Guru Satu','email'=>'','specialty'=>'Pendidikan Kewangan, Matematik','capacity'=>20]);
    $t2=teacher_save(0,['name'=>'Guru Dua','email'=>'','specialty'=>'Pendidikan Kewangan Lanjutan','capacity'=>20]);
    check((subject_usage()['pendidikan kewangan']??0)===1,'bilangan guru dikira ikut nama tepat, bukan sebahagian teks');
    $who=subject_teachers(); check(array_column($who['pendidikan kewangan'],'id')===[$t1] && array_column($who['matematik'],'name')===['Guru Satu'] && !isset($who['pendidikan kewangan lanjutan'][1]),'senarai nama guru setiap MP (padanan tepat, tersusun ikut nama)');
    [,$n]=subject_save($id,['name'=>'Literasi Kewangan','grp'=>'Elektif sekolah']);
    check($n===1 && teacher_find($t1)['specialty']==='Literasi Kewangan, Matematik' && teacher_find($t2)['specialty']==='Pendidikan Kewangan Lanjutan','tukar nama mengemas kini kepakaran guru yang tepat sahaja');
    subject_delete($id); check(!subject_find($id) && teacher_find($t1)['specialty']==='Literasi Kewangan, Matematik','padam daripada senarai; kepakaran guru kekal');
    rejects(fn()=>subject_delete($id),'padam rekod yang tiada → 404');
    echo "Cadangan ikut mata pelajaran\n";
    $S=array_column(subjects_all(),'name');
    check(position_subject('Ketua Panitia Matematik',$S)==='Matematik' && position_subject('Ketua Panitia Matematik Tambahan',$S)==='Matematik Tambahan' && position_subject('Setiausaha Peperiksaan',$S)===null,'MP dikesan daripada nama jawatan (padanan terpanjang)');
    check(position_subject('Penyelaras Sejarahwan',$S)===null && position_subject('KP Bahasa Melayu (T1)',$S)==='Bahasa Melayu','sempadan perkataan dihormati');
    check(teacher_teaches(['specialty'=>'Fizik, Matematik'],'matematik') && !teacher_teaches(['specialty'=>'Matematik Tambahan'],'Matematik'),'guru mengajar MP: padanan tepat kepakaran');
    foreach(['allocation_plans','assignments','positions','teachers'] as $tb) query("DELETE FROM $tb WHERE school_id=".sid());
    $ringan=teacher_save(0,['name'=>'Guru Ringan','email'=>'','specialty'=>'Sejarah','capacity'=>30]);
    $mat=teacher_save(0,['name'=>'Guru Matematik','email'=>'','specialty'=>'Matematik','capacity'=>30]);
    assign_position(position_save(0,['name'=>'Penyelaras Data','category'=>'Pentadbiran','weight'=>6]),$mat);
    $kp=position_save(0,['name'=>'Ketua Panitia Matematik','category'=>'Kurikulum','weight'=>5]); $lain=position_save(0,['name'=>'Penyelaras Kantin','category'=>'HEM','weight'=>2]);
    $pv=allocation_preview('vacant'); $row=array_column($pv['rows'],null,'position_id');
    check($row[$kp]['teacher_id']===$mat && str_starts_with($row[$kp]['reason'],'Mengajar Matematik'),'agihan automatik: KP Matematik kepada guru Matematik walaupun bebannya lebih tinggi');
    check($row[$lain]['teacher_id']===$ringan,'jawatan tanpa MP kekal ikut nisbah beban terendah');
    $GLOBALS['A_SUBJ']=(int)query('SELECT id FROM subjects WHERE school_id='.sid().' AND name="Fizik"')->fetchColumn();
});
echo "Pengasingan sekolah\n";
with_school($B,function(){
    check(subjects_all()===[],'sekolah B bermula tanpa MP sekolah A');
    rejects(fn()=>subject_save($GLOBALS['A_SUBJ'],['name'=>'Diubah']),'sekolah B tidak boleh menyunting MP sekolah A');
    rejects(fn()=>subject_delete($GLOBALS['A_SUBJ']),'sekolah B tidak boleh memadam MP sekolah A');
    subjects_import('kssr',['Matematik','Bahasa Melayu']); check(count(subjects_all())===2,'import terpilih: hanya MP yang ditanda');
});
with_school($A,fn()=>check((bool)subject_find($GLOBALS['A_SUBJ']),'MP sekolah A tidak terjejas'));
} finally { foreach(['UJM9105','UJN9106'] as $c) if($s=school_find($c)) school_delete($s); }
echo "\n$checks semakan mata pelajaran lulus.\n";
