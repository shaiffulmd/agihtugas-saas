<?php
// Ujian senarai jawatan standard SMK (templat, kembang Tingkatan / MP, bilangan, langkau pendua, panduan Pusat Syarat, had Free, pengasingan sekolah).
// Berjalan dalam sekolah sementara UJJ9107 & UJK9108 (dipadam selepas ujian).
//   php tests/position_templates.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }
function rejects(callable $fn,string $label): void { try { $fn(); } catch(EngineError|DomainException $e){ check(true,$label); return; } throw new RuntimeException('Sepatutnya ditolak: '.$label); }
$ids=fn(string $cat,array $names)=>array_map(fn($n)=>$cat.'|'.array_search($n,array_column(POSITION_TEMPLATES['smk'][3][$cat],0),true),$names);

foreach(['UJJ9107','UJK9108','UJL9110'] as $c) if($s=school_find($c)) school_delete($s);
[$A]=school_create('UJJ9107','SMK Ujian Jawatan','SMK','Pentadbir','admin@ujj9107.test');
[$B]=school_create('UJK9108','SMK Ujian Jawatan B','SMK','Pentadbir','admin@ujk9108.test');
[$C]=school_create('UJL9110','SK Ujian Jawatan','SK','Pentadbir','admin@ujl9110.test');
try {
with_school($A,function()use($ids){
    echo "Templat\n";
    $all=[]; foreach(POSITION_TEMPLATES['smk'][3] as $cat=>$items){ check(in_array($cat,CATEGORIES,true),"bidang $cat sah"); foreach($items as $it){ $all[]=mb_strtolower($it[0]); check($it[1]>=1 && $it[1]<=5 && mb_strlen($it[0])<=140,"$it[0]: mata 1–5"); } }
    check(count($all)===count(array_unique($all)),'tiada nama berulang dalam templat');
    check(template_load_label(5)==='Berat' && template_load_label(4)==='Berat' && template_load_label(3)==='Sederhana' && template_load_label(2)==='Ringan','kategori beban ikut mata');

    echo "Import\n";
    rejects(fn()=>positions_import_template('tiada',['Pentadbiran|0']),'templat tidak sah ditolak');
    rejects(fn()=>positions_import_template('smk',[]),'tiada pilihan ditolak');
    [$n]=positions_import_template('smk',$ids('Pentadbiran',['SU Kurikulum','Penyelaras Makmal']),['Pentadbiran|'.array_search('Penyelaras Makmal',array_column(POSITION_TEMPLATES['smk'][3]['Pentadbiran'],0),true)=>'3']);
    $rows=query('SELECT name,category,weight FROM positions WHERE school_id='.sid().' ORDER BY id')->fetchAll();
    check($n===4 && $rows[0]===['name'=>'SU Kurikulum','category'=>'Pentadbiran','weight'=>5] && $rows[3]['name']==='Penyelaras Makmal (3/3)' && (int)$rows[3]['weight']===3,'satu rekod + bilangan 3 → (1/3)…(3/3), pemberat = mata');
    [$n]=positions_import_template('smk',$ids('Kurikulum',['Penyelaras Tingkatan']));
    check($n===5 && query('SELECT COUNT(*) FROM positions WHERE school_id='.sid().' AND name="Penyelaras Tingkatan 5" AND weight=3')->fetchColumn()==1,'Penyelaras Tingkatan dikembangkan kepada T1–T5');
    rejects(fn()=>positions_import_template('smk',$ids('Kurikulum',['Ketua Panitia'])),'Ketua Panitia tanpa katalog MP ditolak');
    subjects_import('kssm',['Matematik','Sejarah','Fizik']);
    [$n]=positions_import_template('smk',$ids('Kurikulum',['Ketua Panitia','Setiausaha Panitia']));
    check($n===6 && query('SELECT weight FROM positions WHERE school_id='.sid().' AND name="Ketua Panitia Matematik"')->fetchColumn()==4,'Ketua / Setiausaha Panitia dijana bagi setiap MP katalog');
    check(position_subject('Ketua Panitia Fizik',subject_names())==='Fizik','nama jana dikenali oleh padanan MP enjin');
    [$n,$skip]=positions_import_template('smk',$ids('Pentadbiran',['SU Kurikulum','SU Hal Ehwal Murid']));
    check($n===1 && $skip===1,'yang sudah ada dilangkau, yang baharu ditambah');
    rejects(fn()=>positions_import_template('smk',$ids('Pentadbiran',['SU Kurikulum'])),'semua sudah ada → ditolak');
    rejects(fn()=>positions_import_template('smk',$ids('HEM',['Guru Tingkatan']),['HEM|0'=>'0']),'bilangan 0 ditolak');
    rejects(fn()=>positions_import_template('smk',$ids('HEM',['Guru Tingkatan']),['HEM|0'=>'abc']),'bilangan bukan nombor ditolak');

    echo "Panduan Pusat Syarat\n";
    $g=POSITION_TEMPLATE_GUIDE['smk']; $r=allocation_save_rules(['heavy_threshold'=>$g['heavy_threshold'],'max_heavy'=>$g['max_heavy']]);
    check($r['heavy_threshold']===5 && $r['max_heavy']===1 && $r['max_tasks']===allocation_defaults()['max_tasks'],'had berat 5 mata / maks 1 disimpan; syarat lain kekal');

    echo "Padanan peranan\n";
    check(position_template_points('Ketua Panitia Fizik','Kurikulum')===4 && position_template_points('Penyelaras Tingkatan 3','Kurikulum')===3 && position_template_points('Penyelaras Makmal (2/3)','Pentadbiran')===3 && position_template_points('SU Kurikulum','Pentadbiran')===5,'jawatan standard dikenali (nama asal, T1–T5, MP, bilangan)');
    check(position_template_points('Ketua Panitia','Kurikulum')===null && position_template_points('SU Kurikulum','HEM')===null && position_template_points('Penyelaras Tingkatan 6','Kurikulum')===null && position_template_points('Penyelaras STEM','Kurikulum')===null,'jawatan bukan standard / bidang lain tidak dikenali');
    roles_install_standard(); position_save(0,['name'=>'Penyelaras STEM','category'=>'Kurikulum','weight'=>7]);
    $pv=array_column(array_map(fn($x)=>['n'=>$x['position']['name'],'x'=>$x],roles_match_preview()),'x','n');
    check($pv['Ketua Panitia Matematik']['standard']===4 && $pv['Ketua Panitia Matematik']['weight_after']===5,'pratonton menanda Ketua Panitia standard (4) yang akan bertukar ke skor peranan (5)');
    check($pv['Penyelaras STEM']['standard']===null,'jawatan sendiri kekal dipadankan seperti biasa');
    roles_match_apply([(string)$pv['Penyelaras STEM']['position']['id']]);
    check((int)query('SELECT weight FROM positions WHERE school_id='.sid().' AND name="Ketua Panitia Matematik"')->fetchColumn()===4,'mata standard tidak berubah apabila tidak dipilih');

    echo "Had Free\n";
    $saved=[pset('free_aktif'),pset('free_jawatan')]; $sch=school();
    $before=(int)query('SELECT COUNT(*) FROM positions WHERE school_id='.sid())->fetchColumn();
    pset_set(['free_aktif'=>'1','free_jawatan'=>(string)($before+3)]);
    $GLOBALS['URUS_SCHOOL']=['expires_on'=>date('Y-m-d',strtotime('-1 day'))]+$sch; // Pro tamat → Free
    try {
        check(school_plan()==='free','sekolah kini pakej Free');
        rejects(fn()=>positions_import_template('smk',$ids('HEM',['Guru Tingkatan']),['HEM|0'=>'4']),'import melebihi had pakej Free ditolak');
        check((int)query('SELECT COUNT(*) FROM positions WHERE school_id='.sid())->fetchColumn()===$before,'tiada rekod separa ditambah apabila had dilampaui');
        [$n]=positions_import_template('smk',$ids('HEM',['Guru Tingkatan']),['HEM|0'=>'3']); check($n===3,'import dalam had Free diterima');
    } finally { $GLOBALS['URUS_SCHOOL']=$sch; pset_set(['free_aktif'=>(string)$saved[0],'free_jawatan'=>(string)$saved[1]]); }
    $GLOBALS['A_POS']=(int)query('SELECT COUNT(*) FROM positions WHERE school_id='.sid())->fetchColumn();
});
echo "Pengasingan sekolah\n";
with_school($B,function()use($ids){
    [$n]=positions_import_template('smk',$ids('Pentadbiran',['SU Kurikulum'])); check($n===1,'sekolah B boleh menambah jawatan yang sama namanya dengan sekolah A');
});
with_school($C,function(){
    rejects(fn()=>positions_import_template('smk',['Pentadbiran|0']),'sekolah SK tidak boleh mengimport senarai SMK');
    check(position_template_points('SU Kurikulum','Pentadbiran')===null,'padanan peranan sekolah SK tidak terjejas oleh senarai SMK');
});
with_school($A,fn()=>check((int)query('SELECT COUNT(*) FROM positions WHERE school_id='.sid())->fetchColumn()===$GLOBALS['A_POS'],'katalog sekolah A tidak terjejas'));
} finally { foreach([$A,$B,$C] as $s) school_delete($s); }
echo "\n$checks semakan jawatan standard lulus.\n";
