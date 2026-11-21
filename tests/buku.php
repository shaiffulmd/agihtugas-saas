<?php
// Ujian Buku Pengurusan Sekolah (app/buku.php): hurai pendahuluan, kulit/kandungan/bahagian bernombor, carta, panitia, guru kelas, PDF.
// Berjalan dalam sekolah sementara UJK9126 (dipadam selepas ujian).
//   php tests/buku.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan','organisasi'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }

echo "Hurai pendahuluan\n";
$g=book_intro_parse("# PENGENALAN\n\n## Dari Meja Pengetua\nPerenggan satu\nsambungan.\n\nPerenggan dua **tebal**.\n\n## Profil Sekolah\nNama : SMK A\nKod : ABC1234\n\n# HALA TUJU\n## Moto\n> Berilmu\n> Memimpin\n### Senarai\n- satu\n- dua\n");
check(count($g)===2 && $g[0]['title']==='PENGENALAN' && array_column($g[0]['items'],'title')===['Dari Meja Pengetua','Profil Sekolah'],'# kumpulan, ## halaman; baris kosong selepas tajuk tidak mencipta halaman');
check($g[0]['items'][0]['blocks']===[['p',"Perenggan satu\nsambungan."],['p','Perenggan dua **tebal**.']] && $g[0]['items'][1]['blocks']===[['dl',[['Nama','SMK A'],['Kod','ABC1234']]]],'perenggan & jadual "Label : nilai"');
check($g[1]['items'][0]['blocks']===[['center',['Berilmu','Memimpin']],['h','Senarai'],['ul',['satu','dua']]],'"> " tengah, ### subtajuk, "- " senarai');
check(book_inline('a **b** <c>')==='a <strong>b</strong> &lt;c&gt;' && book_intro_parse('')===[],'teks di-escape; kosong = tiada bahagian');

if($s=school_find('UJK9126')) school_delete($s);
[$S]=school_create('UJK9126','SMK Ujian Buku','SMK','Pentadbir','admin@ujk9126.test');
try {
with_school($S,function(){
    echo "Buku\n";
    bulk_agihan_apply(bulk_agihan_plan(bulk_agihan_parse(implode("\n",[
        'Cikgu A,Setiausaha JK Disiplin,HEM','Cikgu B,AJK JK Disiplin,HEM','Cikgu C,AJK JK Disiplin,HEM','Cikgu D,Ketua Panitia Kimia,Kurikulum','Cikgu E,Ketua Panitia Bahasa Melayu,Kurikulum',
        'Cikgu B,Guru Kelas Intan 1,HEM','Cikgu C,Guru Kelas Firus 1,HEM','Cikgu A,Setiausaha JK Pengurusan Sekolah,Pentadbiran']))));
    $id=fn($n)=>(int)query('SELECT id FROM teachers WHERE school_id='.sid().' AND name=?',[$n])->fetchColumn();
    teacher_save(0,['name'=>'Cikgu P','email'=>'','specialty'=>'','capacity'=>12]); teacher_save(0,['name'=>'Cikgu Q','email'=>'','specialty'=>'','capacity'=>12]);
    leadership_save(['pengetua'=>$id('Cikgu P'),'pk_hem'=>$id('Cikgu Q')],[['Sains & Matematik',$id('Cikgu D')]]);
    $h=task_book_html(task_book(),false,true,true,'');
    check(str_contains($h,'BUKU</div>') && str_contains($h,'SENARAI KANDUNGAN') && str_contains($h,'>1.1<') && str_contains($h,'SENARAI GURU') && !str_contains($h,'DARI MEJA'),'tanpa pendahuluan: kulit & kandungan; Perkara Am hanya Senarai Guru jika ada guru');
    book_intro_save("# PENGENALAN\n## Dari Meja Pengetua\nSalam.");
    $h=task_book_html(task_book(),false,true,true,'');
    check(str_contains($h,'>1.0<') && str_contains($h,'>1.1.1<') && str_contains($h,'DARI MEJA PENGETUA') && str_contains($h,'Kepimpinan Sekolah'),'1.0 Perkara Am: pendahuluan + senarai guru (kepimpinan)');
    check(str_contains($h,'>2.0<') && str_contains($h,'PENGURUSAN PENTADBIRAN') && str_contains($h,'>4.0<') && str_contains($h,'PENGURUSAN HAL EHWAL MURID') && str_contains($h,'CARTA ORGANISASI PENGURUSAN HAL EHWAL MURID'),'bahagian bernombor tetap (Pentadbiran 2.0, HEM 4.0) dengan carta organisasi');
    check(str_contains($h,'JAWATANKUASA DISIPLIN') && str_contains($h,'rowspan="2"') && str_contains($h,'PK HEM'),'jadual JK: tajuk penuh, AJK digabung, pimpinan dari Carta Organisasi');
    check(str_contains($h,'SENARAI KETUA PANITIA') && str_contains($h,'GKMP : CIKGU D') && str_contains($h,'SAINS &amp; MATEMATIK') && str_contains($h,'BAHASA'),'Senarai Ketua Panitia ikut bidang (kumpulan lalai) dengan GKMP');
    check(str_contains($h,'SENARAI GURU KELAS') && strpos($h,'FIRUS 1')<strpos($h,'INTAN 1'),'Senarai Guru Kelas disusun ikut kelas');
    $f=task_book_html(task_book('HEM'),false,true,false,'');
    check(!str_contains($f,'bk-cover') && str_contains($f,'>4.0<') && !str_contains($f,'PENGURUSAN PENTADBIRAN'),'penapis bidang: bahagian itu sahaja, nombor kekal');
    $p=task_book_html(task_book(),true,true,true,'');
    check(str_contains($p,'<tocpagebreak') && str_contains($p,'htmlpageheader name="bkh"') && substr_count($p,'<pagebreak />')>=5,'PDF: kandungan bernombor halaman, kepala & kaki halaman buku');
    $threw=false; try { book_intro_save(str_repeat('a',BOOK_INTRO_MAX+1)); } catch(EngineError $e){ $threw=true; } check($threw,'had panjang pendahuluan');
});
} finally { school_delete($S); }
echo "\n$checks semakan buku pengurusan lulus.\n";
