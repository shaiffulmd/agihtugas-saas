<?php
// Ujian jadual skor peranan (senarai standard, pemberat ikut peranan, padanan nama, pengasingan sekolah).
// Berjalan dalam sekolah sementara UJR9108 & UJT9109 (dipadam selepas ujian).
//   php tests/roles.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }
function rejects(callable $fn,string $label): void { try { $fn(); } catch(EngineError $e){ check(true,$label); return; } throw new RuntimeException('Sepatutnya ditolak: '.$label); }

foreach(['UJR9108','UJT9109'] as $c) if($s=school_find($c)) school_delete($s);
[$A]=school_create('UJR9108','SMK Ujian Peranan','SMK','Pentadbir','admin@ujr9108.test');
[$B]=school_create('UJT9109','SMK Ujian Peranan B','SMK','Pentadbir','admin@ujt9109.test');
try {
with_school($A,function(){
    echo "Senarai standard\n";
    check(roles_install_standard()===count(ROLE_STANDARD) && roles_install_standard()===0,'isi senarai standard sekali; kali kedua tiada pendua');
    $by=array_column(roles_all(),null,'name');
    check((int)$by['Ketua Panitia']['score']===5 && (int)$by['Pengerusi']['score']===4 && (int)$by['Setiausaha']['score']===3 && (int)$by['AJK']['score']===1,'skor standard: Ketua Panitia 5, Pengerusi 4, Setiausaha 3, AJK 1');

    echo "Pemberat ikut peranan\n";
    $p=position_save(0,['name'=>'Setiausaha Peperiksaan','category'=>'Kurikulum','weight'=>9,'role_id'=>(int)$by['Setiausaha']['id']]);
    check((int)position_find($p)['weight']===3 && (int)position_find($p)['role_id']===(int)$by['Setiausaha']['id'],'pilih peranan → pemberat = skor peranan (input 9 diabaikan)');
    rejects(fn()=>position_save(0,['name'=>'X','category'=>'HEM','weight'=>3,'role_id'=>999999999]),'peranan tidak wujud ditolak');
    [,$n]=role_save((int)$by['Setiausaha']['id'],['name'=>'Setiausaha','score'=>4]); check($n===1 && (int)position_find($p)['weight']===4,'tukar skor peranan → pemberat jawatan dikemas kini');
    rejects(fn()=>role_save(0,['name'=>'ajk','score'=>2]),'nama peranan pendua ditolak');
    rejects(fn()=>role_save(0,['name'=>'Baharu','score'=>11]),'skor > 10 ditolak');
    position_save($p,['name'=>'Setiausaha Peperiksaan','category'=>'Kurikulum','weight'=>7,'role_id'=>null]);
    check((int)position_find($p)['weight']===7 && position_find($p)['role_id']===null,'buang peranan → pemberat sendiri');

    echo "Padanan nama\n";
    $roles=roles_all(); $m=fn(string $n)=>role_match($n,$roles)['name']??null;
    check($m('AJK Jadual Waktu Tingkatan 1')==='AJK Jadual Waktu' && $m('AJK Disiplin (1/3)')==='AJK','padanan terpanjang: AJK Jadual Waktu, bukan AJK');
    check($m('Ketua Guru Penasihat Pengakap')==='Ketua Guru Penasihat' && $m('Guru Penasihat Kelab Sains')==='Guru Penasihat','Ketua Guru Penasihat vs Guru Penasihat');
    check($m('Penolong Setiausaha Sukan')==='Penolong Setiausaha' && $m('Pengerusikan')===null && $m('Penyelaras Data Sekolah')==='Penyelaras','sempadan perkataan dihormati');
    $q=position_save(0,['name'=>'Ketua Panitia Sains','category'=>'Kurikulum','weight'=>8]); $r=position_save(0,['name'=>'Penyelaras Aset','category'=>'Pentadbiran','weight'=>6]); position_save(0,['name'=>'Jawatan Lain','category'=>'HEM','weight'=>2]);
    $pv=roles_match_preview(); check(count($pv)===3 && in_array('Jawatan Lain',array_column(array_column($pv,'position'),'name'),true)===false,'pratonton: hanya jawatan tanpa peranan yang sepadan');
    check(roles_match_apply([(string)$q])===1 && (int)position_find($q)['weight']===5 && position_find($r)['role_id']===null,'padankan yang ditanda sahaja (Ketua Panitia 8 → 5)');
    rejects(fn()=>roles_match_apply([]),'tiada pilihan ditolak');

    echo "Padam peranan\n";
    role_delete((int)$by['Ketua Panitia']['id']); check(position_find($q)['role_id']===null && (int)position_find($q)['weight']===5,'padam peranan: jawatan kekal dengan pemberat semasa');
    $GLOBALS['A_ROLE']=(int)$by['Pengerusi']['id'];
});
echo "Pengasingan sekolah\n";
with_school($B,function(){
    check(roles_all()===[],'sekolah B tiada peranan sekolah A');
    rejects(fn()=>role_save($GLOBALS['A_ROLE'],['name'=>'Ubah','score'=>2]),'sekolah B tidak boleh menyunting peranan sekolah A');
    rejects(fn()=>position_save(0,['name'=>'Pengerusi X','category'=>'HEM','weight'=>3,'role_id'=>$GLOBALS['A_ROLE']]),'sekolah B tidak boleh guna peranan sekolah A');
});
} finally { foreach(['UJR9108','UJT9109'] as $c) if($s=school_find($c)) school_delete($s); }
echo "\n$checks semakan peranan lulus.\n";
