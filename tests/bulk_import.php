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

    echo "Guru unit (jawatan PPKI / Tingkatan 6 hanya untuk guru unit itu)\n";
    $d=teacher_find((int)query('SELECT id FROM teachers WHERE school_id='.sid().' AND name="Guru D"')->fetchColumn());
    check(in_array('t6',teacher_units($d),true) && in_array('ppki',teacher_units($d),true),'import agihan: guru dilantik ke jawatan unit ditanda guru unit itu');
    $fx=teacher_save(0,['name'=>'Guru Biasa X','email'=>'','specialty'=>'','capacity'=>25]); $pt6=position_save(0,['name'=>'AJK T6 Ujian','category'=>'HEM','weight'=>1,'unit'=>'t6']);
    rejects(fn()=>assign_position($pt6,$fx),'lantikan manual: guru bukan Tingkatan 6 ditolak','hanya untuk guru Tingkatan 6');
    teacher_save($fx,['name'=>'Guru Biasa X','email'=>'','specialty'=>'','capacity'=>25,'units'=>['t6']]);
    check(assign_position($pt6,$fx)>0 && teacher_find($fx)['units']==='t6','ditanda guru Tingkatan 6 → boleh dilantik');
    check(allocation_eligible(['id'=>1,'status'=>'aktif','specialty'=>'','units'=>''],['unit'=>'ppki'],[],['excluded_teachers'=>[]])!==null && allocation_eligible(['id'=>1,'status'=>'aktif','specialty'=>'','units'=>'ppki'],['unit'=>'ppki'],[],['excluded_teachers'=>[]])===null,'agihan automatik: hanya guru unit sepadan layak');
    $pu=bulk_guru_update_plan(bulk_guru_update_parse("Nama Guru,Unit\nGuru Biasa X,Tiada\nGuru E,\"T6, PPKI\"\n"));
    check(count($pu['changes'])===2 && $pu['changes'][0]['new']['units']==='' && $pu['changes'][1]['new']['units']==='ppki,t6','kemas kini pukal: lajur Unit (Tiada / T6, PPKI)');
    rejects(fn()=>teacher_units_value(['asrama']),'unit guru tidak sah ditolak','ppki atau t6');
    echo "Semakan pematuhan syarat\n";
    $fx2=(int)query('SELECT id FROM teachers WHERE school_id='.sid().' AND name="Guru Biasa X"')->fetchColumn(); query('UPDATE teachers SET units="" WHERE id=?',[$fx2]);
    $c=allocation_compliance(); $hit=array_values(array_filter($c['positions'],fn($p)=>$p['teacher']==='Guru Biasa X'));
    check($c['assigned']>0 && $hit && str_contains($hit[0]['issue'],'Bukan guru Tingkatan 6'),'lantikan di luar kelayakan (unit) dilaporkan');
    query('UPDATE teachers SET capacity=1 WHERE id=?',[$fx2]); query('UPDATE positions SET weight=3 WHERE school_id='.sid().' AND name="AJK T6 Ujian"'); $t=array_values(array_filter(allocation_compliance()['teachers'],fn($t)=>$t['id']===$fx2));
    check($t && str_contains(implode(' ',$t[0]['issues']),'melebihi kapasiti'),'guru melebihi kapasiti dilaporkan');
    query('UPDATE teachers SET capacity=25,units="t6" WHERE id=?',[$fx2]);
    echo "Guru MP & nama jawatan berulang\n";
    [$sk]=subject_save(0,['name'=>'Kimia Ujian','grp'=>'STEM']); $g1=$fx2; $g2=(int)query('SELECT id FROM teachers WHERE school_id='.sid().' AND name="Guru D"')->fetchColumn();
    check(subject_set_teachers($sk,[$g1,$g2])===[2,0] && str_contains(teacher_find($g1)['specialty'],'Kimia Ujian'),'MP ditambah pada kepakaran guru yang ditanda');
    check(subject_set_teachers($sk,[$g2])===[0,1] && !str_contains(teacher_find($g1)['specialty'],'Kimia Ujian'),'guru dinyahtanda: MP dibuang daripada kepakaran');
    $pk=position_save(0,['name'=>'Ketua Panitia Panitia Kimia (1/2)','category'=>'Kurikulum','weight'=>5]); $rw=array_column(positions_repeated_words(),'new','id');
    check(($rw[$pk]??'')==='Ketua Panitia Kimia (1/2)' && positions_fix_repeated_words()>=1 && position_find($pk)['name']==='Ketua Panitia Kimia (1/2)','perkataan berulang dalam nama jawatan dikemaskan');
    echo "Senarai tugas (buku panduan)\n";
    check(position_committee('Setiausaha JK Disiplin (1/2)')===['Setiausaha','JK Disiplin',8] && position_committee('Ketua Panitia Kimia')[1]==='Panitia Kimia' && position_committee('AJK (BOSS) JK Prasarana')===['AJK (BOSS)','JK Prasarana',30],'nama jawatan dipecah kepada peranan & jawatankuasa');
    $bk=task_book('HEM'); $secs=array_column($bk,null,'unit'); $com=$secs['ppki']['cats']['HEM']['Kebersihan']??null;
    check(isset($secs['sekolah'],$secs['ppki']) && $secs['sekolah']['title']==='BIDANG HEM' && $com && count($com)===2 && !isset($secs['sekolah']['cats']['HEM']['Kebersihan']),'jawatan PPKI di bahagian PPKI sendiri (bukan bawah bidang); akhiran unit dibuang');
    check(task_book_final()===false && str_contains(task_book_html($bk),'PENDIDIKAN KHAS (PPKI)') && array_column(task_book(null,'sekolah'),'unit')===array_fill(0,count(task_book(null,'sekolah')),'sekolah'),'penapis unit Sekolah: bidang sahaja; draf apabila belum muktamad');
    echo "Kemas kini maklumat guru pukal\n";
    $r=bulk_guru_update_parse("Nama Guru,Masa mengajar,Kelas peperiksaan\nnur aisyah  abdullah,28,Ya\nSiti Hajar Saad,,\nTiada Orang,10,\n");
    check(count($r)===3 && $r[0]['set']===['teaching_periods'=>28,'exam_class'=>1] && $r[1]['set']===[],'tajuk dikenali; sel kosong = kekal');
    $plan=bulk_guru_update_plan($r);
    check(count($plan['changes'])===1 && $plan['changes'][0]['new']===['teaching_periods'=>28,'exam_class'=>1] && $plan['same']===1 && $plan['missing']===['Tiada Orang'],'padan nama tanpa beza huruf; tiada perubahan dikira; nama tidak dijumpai disenaraikan');
    $id=$plan['changes'][0]['id'];
    check(bulk_guru_update_apply($plan)===1 && !bulk_guru_update_plan($r)['changes'],'kemas kini disimpan; import semula tiada perubahan');
    $t=teacher_find($id); check((int)$t['teaching_periods']===28 && (int)$t['exam_class']===1,'masa & kelas peperiksaan disimpan');
    $plan=bulk_guru_update_plan(bulk_guru_update_parse("Nur Aisyah Abdullah\t30\n"));
    check($plan['changes'][0]['new']===['teaching_periods'=>30],'tanpa tajuk: Nama, Masa mengajar (peperiksaan kekal)');
    $plan=bulk_guru_update_plan(bulk_guru_update_parse("NAMA GURU\tE-MEL\tKEPAKARAN\tKAPASITI\nNur Aisyah Abdullah\tAisyah@Moe.gov.my\tBahasa Melayu\t20\nSiti Hajar Saad\tsiti@moe.gov.my\t\t\n"));
    check(count($plan['changes'])===2 && $plan['changes'][0]['new']['email']==='aisyah@moe.gov.my' && $plan['changes'][0]['new']['capacity']===20 && $plan['fields']===['email','specialty','capacity'],'e-mel (huruf kecil), kepakaran & kapasiti ikut tajuk');
    bulk_guru_update_apply($plan);
    check(teacher_find($id)['email']==='aisyah@moe.gov.my' && (int)teacher_find($id)['capacity']===20,'e-mel & kapasiti disimpan');
    rejects(fn()=>bulk_guru_update_plan(bulk_guru_update_parse("Nama,Emel\nAhmad Faiz Rahman,aisyah@moe.gov.my\n")),'e-mel milik guru lain ditolak','sudah digunakan');
    check(count(bulk_guru_update_plan(bulk_guru_update_parse("Nama,Emel\nNur Aisyah Abdullah,siti@moe.gov.my\nSiti Hajar Saad,aisyah@moe.gov.my\n"))['changes'])===2,'tukar e-mel antara dua guru dibenarkan');
    rejects(fn()=>bulk_guru_update_parse("Nama,Emel\nA,bukan-emel\n"),'e-mel tidak sah ditolak','tidak sah');
    rejects(fn()=>bulk_guru_update_parse("Nama,Emel\nA,x@y.my\nB,x@y.my\n"),'e-mel berulang ditolak','berulang');
    rejects(fn()=>bulk_guru_update_parse("A,b@c.my,Sains,20\n"),'lebih 3 lajur tanpa tajuk ditolak','baris tajuk');
    rejects(fn()=>bulk_guru_update_parse("Nama,Emel,Hobi\nA,b@c.my,x\n"),'lajur tidak dikenali ditolak','tiada tajuk');
    rejects(fn()=>bulk_guru_update_parse("A,61\n"),'masa > 60 ditolak','0–60');
    rejects(fn()=>bulk_guru_update_parse("A,10,mungkin\n"),'tanpa tajuk: nilai peperiksaan tidak sah ditolak','dikenal pasti');
    rejects(fn()=>bulk_guru_update_parse("Nama,Kelas peperiksaan\nA,mungkin\n"),'kelas peperiksaan tidak sah ditolak','Ya / Tidak');
    rejects(fn()=>bulk_guru_update_parse("A,10\na,12\n"),'nama berulang ditolak','berulang');
    $r=bulk_guru_update_parse("Nur Aisyah Abdullah\tA@B.my\nSiti Hajar Saad\tsiti2@b.my\n");
    check($r[0]['set']===['email'=>'a@b.my'],'tanpa tajuk: lajur e-mel diteka daripada isi (@)');
    rejects(fn()=>bulk_guru_update_parse("A\tSains\nB\tFizik\n"),'tanpa tajuk: lajur teks tidak dikenal pasti → minta tajuk','baris tajuk');
    $m=teacher_matcher([['id'=>1,'name'=>"NOR'AINI BINTI ABDULLAH"],['id'=>2,'name'=>'ABDUL AZIZ BIN JAMALUDDIN'],['id'=>3,'name'=>'MOHD ZUHAILI KAMARIDIN'],['id'=>4,'name'=>'NURRUL FAIRUZ BINTI SETOPA @ SETAPA'],['id'=>5,'name'=>'NOZIRA BINTI ABD RAHMAN'],['id'=>6,'name'=>'SHALELAWATE BINTI HUSSIN'],['id'=>7,'name'=>'SALMIAH BINTI BULIA'],['id'=>8,'name'=>'NAVAMANI A/P PARIASAMY'],['id'=>9,'name'=>'NURUL HAKIMAH BINTI ISMAIL'],['id'=>10,'name'=>'NURUL HAKIMAH BINTI ISMAIL (PPKI)']]);
    $ok=true; foreach(["NOR' AINI BINTI ABDULLAH"=>1,'ABDUL AZIZ BIN JAMALUDIN'=>2,'MOHD ZUHAILI BIN KAMARIDIN'=>3,'NURRUL FAIRUZ BINTI SETOPA@SETAPA'=>4,'NOZIRA BINTI ABDUL RAHMAN'=>5,'SHALELAWATE BINTI HUSSIN @ MUKHTAR'=>6,'SALMIAH BT BULIA'=>7,'navamani a/p pariasamy'=>8,'NURUL HAKIMAH BINTI ISMAIL'=>9] as $n=>$want){ [$t,$how]=$m($n); $ok=$ok && $t && (int)$t['id']===$want; }
    check($ok,'padanan nama longgar: apostrof, BT/BIN, ABD, @ alias, ejaan hampir sama, A/P');
    check($m('NURUL HAKIMAH BINTI ISMAIL')[1]==='tepat' && $m('SALMIAH BT BULIA')[1]==='anggaran' && $m('SITI AMINAH BINTI OMAR')[0]===null,'tepat vs anggaran dibezakan; nama lain tidak dipadankan');
    echo "Akaun guru pukal\n";
    $ap=teacher_accounts_plan(); $cands=array_column($ap['create'],'id');
    check(in_array($id,array_map('intval',$cands),true) && in_array('Tiada e-mel',array_column($ap['skip'],1),true),'guru ber-e-mel tanpa akaun disenaraikan; tanpa e-mel dilangkau');
    [$made,$nl]=teacher_accounts_create([$id],false,false); $u=query('SELECT * FROM users WHERE school_id='.sid().' AND teacher_id=?',[$id])->fetch();
    check(count($made)===1 && $u && $u['role']==='teacher' && (int)$u['must_change']===1 && password_verify($made[0][2],$u['password_hash']) && strlen($made[0][2])===16,'akaun Guru dicipta, dipautkan, kata laluan sementara wajib ditukar');
    check(!in_array($id,array_map('intval',array_column(teacher_accounts_plan()['create'],'id')),true),'guru yang sudah ada akaun tidak disenaraikan lagi');
    check(teachers_periods_update([$id=>[12,false]])===1 && teachers_periods_update([$id=>[12,false]])===0,'grid masa mengajar: hanya baris berubah');
    rejects(fn()=>teachers_periods_update([$id=>[61,false]]),'grid: masa > 60 ditolak','0–60');
});
} finally { foreach($tmp as $f) @unlink($f); if($s=school_find('UJP9108')) school_delete($s); }
echo "\n$checks semakan import pukal lulus.\n";
