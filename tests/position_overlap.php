<?php
// Ujian jawatan bertindan (Jawatan & Pemberat): nama penuh hampir sama / guru sama memegang jawatan sama dua kali, dan padam jawatan dipilih.
// Berjalan dalam sekolah sementara UJB9121 (dipadam selepas ujian).
//   php tests/position_overlap.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan','organisasi'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }

if($s=school_find('UJB9121')) school_delete($s);
[$S]=school_create('UJB9121','SMK Ujian Bertindan','SMK','Pentadbir','admin@ujb9121.test');
try {
with_school($S,function(){
    $n=fn(array $g)=>array_map(fn($p)=>$p['name'],$g['positions']);
    $find=function(string $name){ foreach(positions_overlap() as $g) if(in_array($name,array_column($g['positions'],'name'),true)) return $g; return null; };
    bulk_agihan_apply(bulk_agihan_plan(bulk_agihan_parse(implode("\n",[
        'Guru A,AJK JK Kewangan dan audit,Pentadbiran','Guru B,AJK JK Kewangan dan audit,Pentadbiran','Guru A,AJK JK Kewangan dan Audit (JPKA),Pentadbiran',
        'Guru C,Setiausaha JK Pengurusan PAK 21 & KBAT,Kurikulum','Guru C,Setiausaha JK Pengurusan PAK 21 dan KBAT,Kurikulum',
        'Guru D,Ketua Panitia Sains,Kurikulum','Guru E,Ketua Panitia Sains Sukan,Kurikulum','Guru F,Ketua Panitia Matematik,Kurikulum','Guru G,Ketua Panitia Matematik Tambahan,Kurikulum',
        'Guru H,AJK JK PKA Ting 2,Kurikulum','Guru I,AJK JK PKA Ting 3,Kurikulum',
        'Guru J,Ketua Unit JK Kesihatan PPKI,HEM,,PPKI','Guru K,Ketua Unit JK Pendidikan Jasmani dan Kesihatan PPKI,HEM,,PPKI',
        'Guru L,Ketua Unit JK Bahasa Melayu Komunikasi PPKI,Kurikulum,,PPKI','Guru M,Ketua Unit Persatuan Bahasa Melayu PPKI,Kokurikulum,,PPKI',
        'Guru N,AJK (SU Disiplin) JK Induk Pengurusan HEM,HEM','Guru O,AJK (SU Pengawas) JK Induk Pengurusan HEM,HEM',
        'Guru P,AJK JK Disiplin,HEM','Guru Q,AJK JK Disiplin,HEM',
        'Guru R,Makmal Sains 1 JK Prasarana,Pentadbiran','Guru S,Makmal Sains 2 JK Prasarana,Pentadbiran',
    ]))));
    echo "Pengesanan\n";
    $g=$find('AJK JK Kewangan dan Audit (JPKA)');
    check($g && count($g['positions'])===3 && str_starts_with($g['reason'],'Nama hampir sama') && $g['dup_teachers']===[(int)teacher_id_by('Guru A')],'singkatan dalam kurungan & huruf besar/kecil: tiga tempat satu kumpulan, guru sama ditanda');
    $pid=fn(string $name)=>(int)array_values(array_filter(positions_with_holder(),fn($p)=>$p['name']===$name))[0]['id'];
    check($g['suggest']===[$pid('AJK JK Kewangan dan audit (1/2)')],'cadangan: nama terkini (JPKA) dikekalkan; tempat lama Guru A (sudah ada dalam JPKA) dicadang padam, Guru B (hanya dalam nama lama) tidak');
    $g=$find('Setiausaha JK Pengurusan PAK 21 dan KBAT'); check($g && count($g['positions'])===2,'"&" = "dan"');
    check(!$find('Ketua Panitia Sains') && !$find('Ketua Panitia Matematik'),'panitia berlainan (Sains / Sains Sukan, Matematik / Matematik Tambahan) tidak ditanda');
    check(!$find('AJK JK PKA Ting 2') && !$find('Makmal Sains 1 JK Prasarana'),'nombor berbeza (Ting 2 / Ting 3, Makmal Sains 1 / 2) tidak ditanda');
    check(!$find('Ketua Unit JK Kesihatan PPKI') && !$find('Ketua Unit Persatuan Bahasa Melayu PPKI'),'PPKI: JK berlainan & JK vs persatuan tidak ditanda');
    check(!$find('AJK (SU Disiplin) JK Induk Pengurusan HEM') && !$find('AJK JK Disiplin (1/2)'),'catatan berbeza & jawatan berbilang tempat biasa tidak ditanda');
    // guru sama memegang dua tempat jawatan sama nama
    $ids=array_column(array_filter(positions_with_holder(),fn($p)=>str_starts_with($p['name'],'AJK JK Disiplin')),'id');
    query('UPDATE assignments SET teacher_id=? WHERE school_id='.sid().' AND position_id=?',[teacher_id_by('Guru P'),$ids[1]]);
    $g=$find('AJK JK Disiplin (2/2)'); check($g && $g['reason']==='Guru yang sama memegang lebih daripada satu tempat' && count($g['positions'])===2 && count($g['suggest'])===1,'guru sama dua kali dalam jawatan berbilang tempat ditanda');
    echo "Padam dipilih\n";
    $old=array_values(array_filter(positions_with_holder(),fn($p)=>$p['name']==='AJK JK Kewangan dan audit (1/2)' || $p['name']==='AJK JK Kewangan dan audit (2/2)'));
    $before=engine_counts(); [$np,$na]=positions_delete_many(array_column($old,'id')); $after=engine_counts();
    check($np===2 && $na===2 && $after['positions']===$before['positions']-2 && !$find('AJK JK Kewangan dan Audit (JPKA)'),'jawatan & lantikan dipadam; kumpulan hilang');
    $threw=false; try { positions_delete_many([999999]); } catch(EngineError $e){ $threw=true; } check($threw,'ID tidak wujud / sekolah lain ditolak');
});
} finally { school_delete($S); }
function teacher_id_by(string $n): int { return (int)query('SELECT id FROM teachers WHERE school_id='.sid().' AND name=?',[$n])->fetchColumn(); }
echo "\n$checks semakan jawatan bertindan lulus.\n";
