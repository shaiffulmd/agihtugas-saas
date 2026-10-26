<?php
// Jana fixture AgihTugas/UrusTugas satu sekolah (SQLite) menggunakan KOD LAMA sebenar dalam legacy/, kemudian simpan sebagai
// dump SQL teks tests/fixtures/agihtugas-sample.sql (ujian import tidak bergantung pada legacy/ selepas ini).
//   php tests/fixtures/make_agihtugas_sample.php
// Kandungan: data demo asal (12 guru, 24 jawatan, 20 lantikan), sekolah SMK + logo, 5 akaun (kata laluan dalam ACCOUNTS di bawah),
// syarat umum & jawatan, versi muktamad #1 kemudian draf pindaan (satu lantikan dilepaskan), kunci API, sejarah.
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
$legacy=dirname(__DIR__,2).'/legacy';
if(!is_dir($legacy.'/app')) exit("Folder legacy/ tiada — fixture sedia ada dalam tests/fixtures/agihtugas-sample.sql.\n");
$tmp=sys_get_temp_dir().'/agihtugas-fixture-'.bin2hex(random_bytes(4)).'.sqlite';
putenv('AGIHTUGAS_DB='.$tmp); putenv('AGIHTUGAS_SEED_DEMO=1');
require $legacy.'/app/database.php'; require $legacy.'/app/allocation.php'; require $legacy.'/app/workflow.php'; require $legacy.'/app/auth.php';
const ACCOUNTS=[['Pentadbir Lama','admin@lama.test','admin',null,'Lama-Admin-2026'],['Penolong Kanan Lama','pk@lama.test','pk',null,'Lama-PK-2026'],
    ['Pengetua Lama','ketua@lama.test','head',null,'Lama-Ketua-2026'],['Nur Aisyah Abdullah','guru1@lama.test','teacher',1,'Lama-Guru-2026'],['Muhammad Hafiz Ahmad','guru2@lama.test','teacher',2,'Lama-Guru-2026']];
$db->beginTransaction();
foreach(['school'=>'SMK Lama Contoh','principal'=>'Dr. Hjh. Rohani binti Yusof','year'=>'2026','reference'=>'SMKLC.100-1/5/1'] as $k=>$v) q('UPDATE settings SET value=? WHERE key=?',[$v,$k]);
q('UPDATE settings SET value="SMK" WHERE key="school_type"');
$ids=[]; foreach(ACCOUNTS as [$n,$e,$r,$t,$p]){ q('INSERT INTO accounts(name,email,role,teacher_id,active,password_hash) VALUES (?,?,?,?,1,?)',[$n,$e,$r,$t,password_hash($p,PASSWORD_DEFAULT)]); $ids[$r][]=(int)$db->lastInsertId(); }
q('UPDATE teachers SET external_id="koku-multi:3:41" WHERE id=3');
// Logo sekolah (PNG 64×64) disimpan seperti branding_upload().
$im=imagecreatetruecolor(64,64); imagefill($im,0,0,imagecolorallocate($im,20,90,160)); ob_start(); imagepng($im); $png=(string)ob_get_clean();
$logo=branding_store($png,64,64); q('UPDATE settings SET value=? WHERE key="school_logo_id"',[$logo]);
allocation_save_rules(['max_tasks'=>6,'excluded_teachers'=>[12]]);
allocation_save_position_rule(8,['required_specialty'=>'Matematik','allowed_teachers'=>[2,6],'fixed_teacher_id'=>2]);
allocation_save_position_rule(21,['fixed_teacher_id'=>4]);
q('INSERT INTO api_keys(name,token_hash,prefix,scope) VALUES (?,?,?,?)',['koku-multi',hash('sha256','agt_'.str_repeat('a',64)),'agt_aaaaaaaa','write']);
$acc=fn(string $r)=>q('SELECT * FROM accounts WHERE id=?',[$ids[$r][0]])->fetch();
workflow_transition('submit',workflow_state()['version'],$acc('pk'));
workflow_transition('approve',workflow_state()['version'],$acc('head'));
workflow_transition('finalize',workflow_state()['version'],$acc('head'));
workflow_transition('return',workflow_state()['version'],$acc('admin'),'Pindaan selepas mesyuarat');
q('DELETE FROM assignments WHERE position_id=20'); // pindaan draf: Setiausaha Sukan dilepaskan
$db->commit();
$out=dirname(__FILE__).'/agihtugas-sample.sql';
$dump=(string)shell_exec('sqlite3 '.escapeshellarg($tmp).' .dump');
if(!str_contains($dump,'CREATE TABLE')) exit("Dump gagal (perlu sqlite3).\n");
file_put_contents($out,"-- Fixture AgihTugas satu sekolah (dijana oleh tests/fixtures/make_agihtugas_sample.php daripada kod legacy/).\n-- Akaun ujian: ".implode(', ',array_map(fn($a)=>$a[1].' / '.$a[4],ACCOUNTS))."\n".$dump);
@unlink($tmp); echo "Fixture ditulis: $out (".strlen($dump)." bait)\n";
