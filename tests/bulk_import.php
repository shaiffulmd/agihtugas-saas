<?php
// Ujian import pukal guru & jawatan: tajuk lajur Bahasa Melayu / Inggeris, CSV / lajur bertab, fail Excel (.xlsx).
// Berjalan dalam sekolah sementara UJP9108 (dipadam selepas ujian).
//   php tests/bulk_import.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }
function rejects(callable $fn,string $label,string $has=''): void { try { $fn(); } catch(EngineError $e){ check($has==='' || str_contains($e->getMessage(),$has),$label); return; } throw new RuntimeException('Sepatutnya ditolak: '.$label); }
/** Bina .xlsx minimum: $sheet = XML sheetData; $shared = senarai rentetan kongsi; $workbook = sertakan workbook.xml + rels. */
function xlsx_make(string $sheet,array $shared=[],bool $workbook=true): string {
    $p=tempnam(sys_get_temp_dir(),'ujx'); $z=new ZipArchive(); $z->open($p,ZipArchive::OVERWRITE);
    $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $z->addFromString('xl/worksheets/data.xml','<?xml version="1.0"?><worksheet xmlns="'.$ns.'"><sheetData>'.$sheet.'</sheetData></worksheet>');
    if($workbook){
        $z->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="'.$ns.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Guru" sheetId="1" r:id="rId7"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Target="worksheets/data.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
    } else $z->renameName('xl/worksheets/data.xml','xl/worksheets/sheet1.xml');
    if($shared) $z->addFromString('xl/sharedStrings.xml','<?xml version="1.0"?><sst xmlns="'.$ns.'">'.implode('',array_map(fn($s)=>'<si><t>'.htmlspecialchars($s).'</t></si>',$shared)).'</sst>');
    $z->close(); return $p;
}

if($s=school_find('UJP9108')) school_delete($s);
[$S]=school_create('UJP9108','SMK Ujian Pukal','SMK','Pentadbir','admin@ujp9108.test');
$tmp=[];
try {
with_school($S,function()use(&$tmp){
    echo "Tajuk lajur\n";
    $r=bulk_teachers_parse("Nama,Emel,Kepakaran,Kapasiti,Masa mengajar\nGuru A,a@x.test,Sains,20,28\n");
    check(count($r)===1 && $r[0]['name']==='Guru A' && $r[0]['teaching_periods']===28,'tajuk Bahasa Melayu dilangkau ("Masa mengajar" berjarak)');
    $r=bulk_teachers_parse("name,email,specialty,capacity\nGuru A,,Sains,\n");
    check(count($r)===1 && $r[0]['capacity']===25,'tajuk Inggeris lama masih diterima; kapasiti kosong = 25');
    $r=bulk_teachers_parse("NAMA\tE-MEL\tKEPAKARAN\tKAPASITI\nGuru A\t\tSains\t22\n");
    check(count($r)===1 && $r[0]['capacity']===22,'tampal daripada Excel (bertab), tajuk huruf besar & "E-MEL"');
    $r=bulk_teachers_parse("Guru A,,Sains,20\nGuru B,,Fizik,20\n");
    check(count($r)===2,'tanpa baris tajuk: semua baris dibaca');
    $r=bulk_positions_parse("Nama,Bidang,Pemberat,Bilangan\nAJK Disiplin,hem,5,2\n");
    check(count($r)===2 && $r[0]['category']==='HEM' && $r[1]['name']==='AJK Disiplin (2/2)','jawatan: tajuk BM, bidang tanpa beza huruf (hem → HEM)');
    $r=bulk_positions_parse("name,category,weight,count\nAJK Disiplin,HEM,5,1\n");
    check(count($r)===1,'jawatan: tajuk Inggeris lama masih diterima');

    echo "Fail Excel (.xlsx)\n";
    $root=dirname(__DIR__).'/public_html/assets/templat/';
    $r=bulk_teachers_parse(xlsx_text($root.'guru.xlsx'));
    check(count($r)===2 && $r[0]['name']==='Nur Aisyah Abdullah' && $r[0]['capacity']===25 && $r[0]['teaching_periods']===28 && $r[1]['email']==='' && $r[1]['specialty']==='Matematik, Matematik Tambahan',
        'templat guru.xlsx: tajuk dilangkau, nombor → integer, emel kosong, kepakaran berkoma kekal satu sel');
    $r=bulk_positions_parse(xlsx_text($root.'jawatan.xlsx'));
    check(count($r)===8 && $r[0]['name']==='AJK Disiplin (1/3)','templat jawatan.xlsx: 3 baris → 8 tempat lantikan');
    $tmp[]=$p=xlsx_make('<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="inlineStr"><is><t>Sains</t></is></c><c r="D1"><v>25.0</v></c><c r="F1" t="s"><v>1</v></c></row>'
        .'<row r="2"/><row r="3"><c r="A3" t="inlineStr"><is><r><t>Guru </t></r><r><t>"Petik"</t></r></is></c><c r="C3" t="s"><v>2</v></c><c r="D3"><v>2.2E1</v></c></row>',['Guru Satu','','Fizik, Kimia']);
    $r=bulk_teachers_parse(xlsx_text($p));
    check(count($r)===2 && $r[0]['email']==='' && $r[0]['specialty']==='Sains' && $r[0]['capacity']===25 && $r[1]['name']==='Guru "Petik"' && $r[1]['capacity']===22 && $r[1]['specialty']==='Fizik, Kimia',
        'sel kosong di tengah, sel kosong di hujung & baris kosong diabaikan; teks sebaris berjalur; 25.0 / 2.2E1 → integer; petikan dikekalkan');
    $tmp[]=$p=xlsx_make('<row r="1"><c r="A1" t="inlineStr"><is><t>Guru Tanpa Workbook</t></is></c><c r="C1" t="inlineStr"><is><t>Sejarah</t></is></c><c r="D1"><v>20</v></c></row>',[],false);
    check(bulk_teachers_parse(xlsx_text($p))[0]['name']==='Guru Tanpa Workbook','tanpa workbook.xml: jatuh balik ke sheet1.xml');
    $tmp[]=$p=xlsx_make('<row r="1"><c r="A1" t="inlineStr"><is><t>X</t></is></c></row>');
    rejects(fn()=>bulk_teachers_parse(xlsx_text($p)),'bilangan lajur tidak sah dilaporkan','Bilangan lajur');
    $tmp[]=$p=xlsx_make('');
    rejects(fn()=>xlsx_text($p),'helaian kosong ditolak','kosong');
    $tmp[]=$p=tempnam(sys_get_temp_dir(),'ujx'); file_put_contents($p,"PK\x03\x04bukan zip sebenar");
    rejects(fn()=>xlsx_text($p),'fail .xlsx rosak ditolak dengan mesej jelas','tidak');
    $rows=''; for($i=1;$i<=502;$i++) $rows.='<row r="'.$i.'"><c r="A'.$i.'" t="inlineStr"><is><t>G'.$i.'</t></is></c><c r="C'.$i.'" t="inlineStr"><is><t>Sains</t></is></c><c r="D'.$i.'"><v>20</v></c></row>';
    $tmp[]=$p=xlsx_make($rows);
    rejects(fn()=>xlsx_text($p),'lebih 500 baris ditolak','500');

    echo "Import agihan (guru + jawatan + lantikan)\n";
    $root=dirname(__DIR__).'/public_html/assets/templat/';
    $rows=bulk_agihan_parse(xlsx_text($root.'agihan.xlsx',BULK_AGIHAN_MAX));
    check(count($rows)===5 && $rows[0]['teacher']==='Nur Aisyah Abdullah' && $rows[0]['weight']===3 && $rows[4]['weight']===null,'templat agihan.xlsx: tajuk dilangkau, pemberat kosong = null');
    $plan=bulk_agihan_plan($rows);
    check(count($plan['teachers_new'])===3 && count($plan['positions_new'])===5 && $plan['positions_new'][1]['name']==='AJK JK Disiplin (1/2)' && $plan['positions_new'][4]['weight']===1,
        'rancangan: 3 guru & 5 jawatan baharu, jawatan berkongsi → (1/2), pemberat kosong ikut peranan (Guru Penasihat = 1)');
    [$nt,$np,$na]=bulk_agihan_apply($plan);
    check($nt===3 && $np===5 && $na===5 && engine_counts()['assigned']===5,'laksana: guru, jawatan & 5 lantikan dicipta');
    $again=bulk_agihan_plan(bulk_agihan_parse("nur  aisyah ABDULLAH,Setiausaha JK Disiplin,hem,3\n".implode("\n",array_map(fn($r)=>$r['teacher'].','.$r['position'].','.$r['category'],array_slice($rows,1)))));
    check(!$again['teachers_new'] && !$again['positions_new'] && !array_filter($again['items'],fn($i)=>$i['status']==='baharu'),'import semula: nama dipadan tanpa beza huruf/ruang, lantikan sedia ada dilangkau');
    rejects(fn()=>bulk_agihan_plan(bulk_agihan_parse("Guru D,AJK JK Disiplin,HEM\n")),'jawatan sedia ada tanpa tempat kosong ditolak','kosong');
    bulk_positions_insert(bulk_positions_validate([['name'=>'AJK Kebersihan','category'=>'HEM','weight'=>2,'count'=>2]]));
    $p2=bulk_agihan_plan(bulk_agihan_parse("Guru D,AJK Kebersihan,HEM\nSiti Hajar Saad,AJK Kebersihan,HEM\nGuru D,AJK Kebersihan,HEM\n"));
    check(!$p2['positions_new'] && $p2['reused']===2 && $p2['duplicates']===1 && $p2['items'][0]['position']==='AJK Kebersihan (1/2)' && count($p2['teachers_new'])===1,'tempat kosong jawatan sedia ada diisi; baris berulang dilangkau');
    bulk_agihan_apply($p2);
    check(engine_counts()['assigned']===7 && engine_counts()['teachers']===4,'tempat sedia ada dilantik tanpa jawatan pendua');
    $p3=bulk_agihan_plan(bulk_agihan_parse("Guru D,AJK Rumah Biru,Kokurikulum\n,AJK Rumah Biru,Kokurikulum\nGuru E,AJK Rumah Biru,Kokurikulum\n"));
    check(count($p3['positions_new'])===3 && $p3['items'][2]['status']==='kosong' && $p3['items'][2]['position']==='AJK Rumah Biru (3/3)' && count($p3['teachers_new'])===1,'nama guru kosong → tempat kosong dikira dalam (k/n), dicipta terakhir');
    $before=engine_counts(); bulk_agihan_apply($p3); $after=engine_counts();
    check($after['positions']-$before['positions']===3 && $after['assigned']-$before['assigned']===2 && $after['vacant']-$before['vacant']===1,'tempat kosong dicipta tanpa lantikan');
    rejects(fn()=>bulk_agihan_plan(bulk_agihan_parse("A,Ketua X,HEM,3\nB,Ketua X,HEM,4\n")),'pemberat berbeza bagi jawatan sama ditolak','pemberat berbeza');
    rejects(fn()=>bulk_agihan_parse("A,AJK X,PPKI\n"),'bidang tidak sah ditolak','bidang');
    rejects(fn()=>bulk_agihan_parse("A,AJK X,HEM,11\n"),'pemberat di luar 1–10 ditolak','pemberat');
    rejects(fn()=>bulk_agihan_parse(str_repeat("A,AJK X,HEM\n",2001)),'lebih 2,000 baris ditolak','2,000');

    echo "Unit jawatan (Sekolah / PPKI / Tingkatan 6)\n";
    check(unit_from_text('')===null && unit_from_text('F6')==='t6' && unit_from_text('tingkatan 6')==='t6' && unit_from_text('PPU')==='t6' && unit_from_text('Pendidikan Khas')==='ppki' && unit_from_text('sekolah')==='sekolah','alias unit dikenali');
    rejects(fn()=>unit_from_text('asrama'),'unit tidak dikenali ditolak','unit mesti');
    $r=bulk_positions_parse("Nama,Bidang,Pemberat,Bilangan,Unit\nAJK Kelab PPKI,Kokurikulum,1,2,PPKI\nAJK Kelab Sains,Kokurikulum,1,1,\n");
    check($r[0]['unit']==='ppki' && $r[1]['unit']==='ppki' && $r[2]['unit']==='sekolah','jawatan pukal: lajur Unit (kosong = Sekolah)');
    bulk_positions_insert($r); school_units(true);
    check(units_on() && in_array('ppki',school_units(),true) && !in_array('t6',school_units(),true),'unit dipaparkan apabila sudah digunakan');
    $p4=bulk_agihan_plan(bulk_agihan_parse("Nama Guru,Jawatan,Bidang,Pemberat,Unit\nGuru D,AJK Kelab Rakan Muda,Kokurikulum,1,Tingkatan 6\nGuru E,AJK Kelab Rakan Muda,Kokurikulum,1,\nGuru D,AJK Kelab PPKI,Kokurikulum,1,PPKI\n"));
    check($p4['positions_new'][0]['unit']==='t6' && $p4['positions_new'][1]['unit']==='t6' && !$p4['unit_updates'],'import agihan: unit dikongsi kumpulan (baris kosong ikut kumpulan)');
    bulk_agihan_apply($p4);
    check((int)query('SELECT COUNT(*) FROM positions WHERE school_id='.sid().' AND unit="t6"')->fetchColumn()===2,'jawatan baharu disimpan dengan unit');
    $p5=bulk_agihan_plan(bulk_agihan_parse("Siti Hajar Saad,AJK Kebersihan,HEM,,PPKI\n"));
    check(count($p5['unit_updates'])===2 && !$p5['positions_new'],'jawatan sedia ada ditanda unit (import semula dengan lajur Unit)');
    bulk_agihan_apply($p5);
    check((int)query('SELECT COUNT(*) FROM positions WHERE school_id='.sid().' AND unit="ppki" AND name LIKE "AJK Kebersihan%"')->fetchColumn()===2,'unit jawatan sedia ada dikemas kini');
    rejects(fn()=>bulk_agihan_plan(bulk_agihan_parse("A,AJK Y,HEM,,PPKI\nB,AJK Y,HEM,,T6\n")),'unit berbeza dalam kumpulan ditolak','unit berbeza');
    $pid=position_save(0,['name'=>'Penyelaras T6','category'=>'Kurikulum','weight'=>3,'unit'=>'t6']);
    check(position_find($pid)['unit']==='t6','position_save menerima unit');
    rejects(fn()=>position_save(0,['name'=>'X','category'=>'HEM','weight'=>1,'unit'=>'asrama']),'unit tidak sah ditolak (position_validate)','Unit mesti');

    echo "Masa mengajar pukal\n";
    $r=bulk_masa_parse("Nama Guru,Masa mengajar,Kelas peperiksaan\nnur aisyah  abdullah,28,Ya\nSiti Hajar Saad,,\nTiada Orang,10,\n");
    check(count($r)===3 && $r[0]['periods']===28 && $r[0]['exam']===true && $r[1]['periods']===0 && $r[1]['exam']===null,'tajuk dilangkau; kosong = 0; peperiksaan kosong = kekal');
    $plan=bulk_masa_plan($r);
    check(count($plan['changes'])===1 && $plan['changes'][0]['new']===28 && $plan['changes'][0]['exam_new']===1 && $plan['same']===1 && $plan['missing']===['Tiada Orang'],'padan nama tanpa beza huruf; tiada perubahan dikira; nama tidak dijumpai disenaraikan');
    $id=$plan['changes'][0]['id'];
    check(teachers_periods_update([$id=>[28,true]])===1 && teachers_periods_update([$id=>[28,true]])===0,'kemas kini hanya baris berubah');
    $t=teacher_find($id); check((int)$t['teaching_periods']===28 && (int)$t['exam_class']===1,'masa & kelas peperiksaan disimpan');
    $plan=bulk_masa_plan(bulk_masa_parse("Nur Aisyah Abdullah\t30\n"));
    check($plan['changes'][0]['exam_new']===1,'tampal bertab tanpa lajur peperiksaan: peperiksaan kekal');
    rejects(fn()=>bulk_masa_parse("A,61\n"),'masa > 60 ditolak','0–60');
    rejects(fn()=>bulk_masa_parse("A,10,mungkin\n"),'kelas peperiksaan tidak sah ditolak','Ya / Tidak');
    rejects(fn()=>bulk_masa_parse("A,10\na,12\n"),'nama berulang ditolak','berulang');
    rejects(fn()=>teachers_periods_update([$id=>[61,false]]),'grid: masa > 60 ditolak','0–60');
});
} finally { foreach($tmp as $f) @unlink($f); if($s=school_find('UJP9108')) school_delete($s); }
echo "\n$checks semakan import pukal lulus.\n";
