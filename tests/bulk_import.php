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
});
} finally { foreach($tmp as $f) @unlink($f); if($s=school_find('UJP9108')) school_delete($s); }
echo "\n$checks semakan import pukal lulus.\n";
