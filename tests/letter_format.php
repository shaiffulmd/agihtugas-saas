<?php
// Ujian format surat lantikan (gaya kepala, satu surat / satu bagi setiap jawatan, snapshot lama) pada pemasangan sedia ada (.env).
// Berjalan dalam sekolah sementara UJS9104 (dipadam selepas ujian).
//   php tests/letter_format.php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','images','users','demo','platform','subscription','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(app_status()!=='ready') exit("Pemasangan belum sedia.\n");
$checks=0;
function check(bool $c,string $label): void { global $checks; if(!$c) throw new RuntimeException('GAGAL: '.$label); $checks++; echo "  ✓ $label\n"; }
function rejects(callable $fn,string $label): void { try { $fn(); } catch(EngineError $e){ check(true,$label); return; } throw new RuntimeException('Sepatutnya ditolak: '.$label); }
function tx(callable $fn): mixed { db()->beginTransaction(); try { $r=$fn(); db()->commit(); return $r; } catch(Throwable $e){ db()->rollBack(); throw $e; } }
$fmt=['letter_header'=>'dual','letter_mode'=>'satu','letter_effective'=>'','letter_para'=>'Perenggan ujian.','letter_slogan'=>'"BERKHIDMAT UNTUK NEGARA"'];
$img=fn(array $a)=>'/img/'.$a['id'];

if($s=school_find('UJS9104')) school_delete($s);
[$S]=school_create('UJS9104','SMK Ujian Surat','SMK','Pentadbir','admin@ujs9104.test');
try {
with_school($S,function()use($fmt,$img){
    echo "Pengesahan tetapan\n";
    rejects(fn()=>letter_settings_save(['letter_header'=>'x']+$fmt),'gaya kepala tidak sah ditolak');
    rejects(fn()=>letter_settings_save(['letter_mode'=>'x']+$fmt),'mod tidak sah ditolak');
    rejects(fn()=>letter_settings_save(['letter_effective'=>'2026-02-30']+$fmt),'tarikh tidak wujud ditolak');
    rejects(fn()=>letter_settings_save(['letter_slogan'=>str_repeat('a',201)]+$fmt),'slogan terlalu panjang ditolak');
    tx(fn()=>letter_settings_save(['letter_mode'=>'jawatan','letter_effective'=>'2026-01-05']+$fmt));
    engine_setting_set(['principal'=>'Dr. Pengetua Ujian','school_address'=>'Jalan Ujian, 25200 Kuantan','school_tel'=>'09-111 2222','school_fax'=>'09-111 3333']);
    $L=letter_profile(); check($L['mode']==='jawatan' && $L['effective']==='2026-01-05' && $L['tel']==='09-111 2222','format disimpan dalam profil surat');
    check(school_profile()['letter']===$L,'format masuk ke profil sekolah (snapshot revisi)');

    echo "Surat & mod satu surat setiap jawatan\n";
    $t=teacher_save(0,['name'=>'Guru Surat','email'=>'','specialty'=>'','capacity'=>20]);
    foreach([['Setiausaha Peperiksaan','Kurikulum'],['Penyelaras Disiplin','HEM']] as [$n,$c]) assign_position(position_save(0,['name'=>$n,'category'=>$c,'weight'=>5]),$t);
    $head=(int)query('INSERT INTO users(school_id,name,email,password_hash,role) VALUES ('.sid().',"Pengetua","head@ujs9104.test","x","head")')->rowCount()?(int)db()->lastInsertId():0;
    $admin=query('SELECT id,name,role FROM users WHERE school_id='.sid().' AND role="admin"')->fetch(); $hd=['id'=>$head,'name'=>'Pengetua','role'=>'head'];
    tx(fn()=>workflow_transition('submit',workflow_state()['version'],$admin));
    tx(fn()=>workflow_transition('approve',workflow_state()['version'],$hd)); tx(fn()=>workflow_transition('finalize',workflow_state()['version'],$hd));
    $docs=letter_docs(workflow_published());
    check(count($docs)===2 && $docs[0]['part']===1 && count($docs[1]['assignments'])===1,'2 jawatan → 2 surat bernombor');
    $h=letter_html($docs[1],$img);
    check(str_contains($h,'('.$t.' / V1 / 2)') && str_contains($h,'PELANTIKAN SEBAGAI PENYELARAS DISIPLIN'),'rujukan bernombor & tajuk jawatan tunggal');
    check(str_contains($h,'5 Januari 2026') && str_contains($h,'JALAN UJIAN, 25200 KUANTAN') && str_contains($h,'Perenggan ujian.'),'tarikh berkuat kuasa, alamat & perenggan');
    check(substr_count($h,'class="letter-emblem"')===1 && str_contains($h,'alt="Jata Negara"') && str_contains($h,'is-row'),'kepala dual: Jata di kiri (tiada logo sekolah → ruang kanan kosong)');

    echo "Gaya kepala & surat lama\n";
    $d=$docs[0]; $d['school']['branding']['layout']='dual_tel';
    check(str_contains(letter_html($d,$img),'NO. TEL : 09-111 2222') && str_contains(letter_html($d,$img),'NO. FAKS : 09-111 3333'),'kepala dual_tel memaparkan telefon & faks');
    $d['school']['branding']['layout']='school'; check(str_contains(letter_html($d,$img),'is-center'),'kepala logo sekolah di tengah');
    $old=$docs[0]; unset($old['school']['letter'],$old['part']); $old['assignments']=workflow_letter($t)['assignments']; $h=letter_html($old,$img);
    check(count(letter_split($old))===1 && str_contains($h,'PEJABAT PENGETUA') && str_contains($h,'BERKHIDMAT UNTUK NEGARA') && !str_contains($h,'Saya yang menjalankan amanah'),'snapshot lama: satu surat, kepala & teks asal');

    echo "Keserasian revisi sebelum 1.3\n";
    tx(fn()=>workflow_transition('return',workflow_state()['version'],$hd,'Pindaan format surat'));
    tx(fn()=>workflow_transition('submit',workflow_state()['version'],$admin));
    // Tiru revisi yang dihantar oleh versi lama: tiada school.letter dalam payload & hash.
    $rid=workflow_state()['revision_id']; $src=workflow_source(); unset($src['school']['letter']);
    query('UPDATE workflow_revisions SET payload=?,source_hash=? WHERE id=? AND school_id='.sid(),[json_encode($src,JSON_UNESCAPED_UNICODE),workflow_hash($src),$rid]);
    tx(fn()=>workflow_transition('approve',workflow_state()['version'],$hd)); check(workflow_state()['status']==='approved','revisi lama boleh diluluskan tanpa dihantar semula');
});
} finally { if($s=school_find('UJS9104')) school_delete($s); }
echo "\n$checks semakan format surat lulus.\n";
