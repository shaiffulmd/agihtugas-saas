<?php
// Ujian pusingan penuh sandaran (.kbak): bk_make() → bk_decrypt_file() → import ke pangkalan data KOSONG → bandingkan.
// Pemasangan UJIAN sahaja (APP_ENV=local). Sasaran: pangkalan data kosong dengan pengguna & kata laluan DB yang sama (.env).
//   php tests/backup_roundtrip.php NAMA_DB_SASARAN_KOSONG
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','mailer','backup','images','users','demo','platform','enjin','tugas_auto','kelulusan','surat_lantikan'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(env('APP_ENV')!=='local') exit("Hanya untuk APP_ENV=local.\n");
$target=$argv[1]??''; if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$target) || $target===env('DB_NAME')) exit("Guna: php tests/backup_roundtrip.php NAMA_DB_SASARAN_KOSONG\n");
$ok=0; function check(bool $c,string $l): void { global $ok; if(!$c){ fwrite(STDERR,"GAGAL: $l\n"); exit(1); } $ok++; echo "  ✓ $l\n"; }
$dst=new PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT').';dbname='.$target.';charset=utf8mb4',env('DB_USER'),env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
check((int)$dst->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()===0,'pangkalan data sasaran kosong');

// Data enjin lengkap dalam satu sekolah sementara: guru, jawatan, lantikan, syarat, revisi muktamad, logo (BLOB), kunci API.
if($s=school_find('UJR9201')) school_delete($s);
[$S]=school_create('UJR9201','SMK Ujian Sandaran','SMK','Pentadbir','admin@ujr9201.test','Sandaran-Ujian-2026',['must_change'=>false]);
$token=''; $png='';
with_school($S,function() use(&$token,&$png){
    $_SESSION['uid']=(int)query('SELECT id FROM users WHERE school_id='.sid())->fetchColumn(); $u=query('SELECT * FROM users WHERE id=?',[$_SESSION['uid']])->fetch();
    query('UPDATE users SET totp_secret=? WHERE id=?',[encrypt_value('JBSWY3DPEHPK3PXP'),$u['id']]);
    $t=teacher_save(0,['name'=>'Guru Sandaran','email'=>'g@ujr.test','specialty'=>'Sains','capacity'=>20,'external_id'=>'luar:1']);
    $p=position_save(0,['name'=>'Penyelaras Sandaran','category'=>'HEM','weight'=>6]); assign_position($p,$t);
    allocation_save_position_rule($p,['required_specialty'=>'Sains']);
    $im=imagecreatetruecolor(40,30); imagefill($im,0,0,imagecolorallocate($im,200,30,30)); ob_start(); imagepng($im); $png=(string)ob_get_clean();
    engine_setting_set(['school_logo_id'=>branding_normalize($png),'principal'=>'Pengetua Sandaran']);
    [,$token]=api_key_create('Sandaran','read');
    db()->beginTransaction(); workflow_transition('submit',1,['id'=>$u['id'],'role'=>'admin','name'=>'A'],''); db()->commit();
    audit('qa_sandaran','settings',null,'butiran ujian');
});

$tables=fn(PDO $pdo)=>$pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE="BASE TABLE" ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
$sig=function(PDO $pdo,string $t){ $r=$pdo->query('SELECT * FROM `'.$t.'`')->fetchAll(); usort($r,fn($a,$b)=>strcmp(json_encode(array_map('bin2hex',array_map('strval',$a))),json_encode(array_map('bin2hex',array_map('strval',$b))))); return [count($r),hash('sha256',serialize($r))]; };
$pass='Kata-Laluan-Sandaran-Ujian-2026';
bk_set_pass($pass);
[$path,$name]=bk_make();
try {
    check(str_starts_with($name,'urustugas-sandaran-') && str_ends_with($name,'.kbak') && filesize($path)>0,'fail sandaran tersulit dijana: '.$name);
    check(substr((string)file_get_contents($path,false,null,0,8),0,8)===BACKUP_MAGIC,'penanda format URUSBAK1');
    $gz=tempnam(sys_get_temp_dir(),'urusbk');
    try { bk_decrypt_file($path,$gz,'salah-kata-laluan'); check(false,'kata laluan salah'); } catch(Throwable) { check(true,'kata laluan salah ditolak'); }
    bk_decrypt_file($path,$gz,$pass);
    $h=gzopen($gz,'rb'); $n=0; $done=false;
    while(($line=gzgets($h))!==false){ $line=rtrim($line,"\r\n"); if($line==='-- SELESAI') $done=true; if($line==='' || str_starts_with($line,'--')) continue; $dst->exec($line); $n++; }
    gzclose($h); @unlink($gz);
    check($done && $n>0,"sandaran lengkap dipulihkan ($n pernyataan)");
    $src=$tables(db()); check($src===$tables($dst),'semua '.count($src).' jadual dipulihkan');
    $diff=[]; foreach(array_diff($src,['rate_limits']) as $t) if($sig(db(),$t)!==$sig($dst,$t)) $diff[]=$t; // rate_limits: baldi sementara (berubah semasa ujian)
    check(!$diff,'kandungan setiap jadual sama (termasuk BLOB & JSON)'.($diff?': '.implode(',',$diff):''));
    $sid=(int)$S['id'];
    $bytes=(string)$dst->query('SELECT a.bytes FROM school_assets a JOIN settings s ON s.school_id=a.school_id AND s.name="school_logo_id" AND s.value=a.id WHERE a.school_id='.$sid)->fetchColumn();
    check($bytes!=='' && imagecreatefromstring($bytes)!==false,'logo surat (MEDIUMBLOB) boleh dibaca selepas pulih');
    check((int)$dst->query('SELECT COUNT(*) FROM api_keys WHERE school_id='.$sid.' AND token_hash='.$dst->quote(api_key_hash($token)))->fetchColumn()===1,'kunci API kekal sah (APP_KEY sama)');
    $cipher=(string)$dst->query('SELECT totp_secret FROM users WHERE school_id='.$sid.' AND totp_secret IS NOT NULL')->fetchColumn();
    check(with_school($S,fn()=>decrypt_value($cipher))==='JBSWY3DPEHPK3PXP','medan tersulit (2FA) boleh dinyahsulit dengan subkunci sekolah');
    $prev=db(); db($dst); try { check(audit_verify($sid)['ok'] && audit_verify(0)['ok'],'rantai audit sekolah & platform sah selepas pulih'); } finally { db($prev); }
    check((string)$dst->query('SELECT status FROM workflow_state WHERE school_id='.$sid)->fetchColumn()==='submitted','keadaan aliran kelulusan dipulihkan');
} finally {
    @unlink($path); if($s=school_find('UJR9201')) school_delete($s);
}
echo "\n$ok semakan sandaran lulus.\n";
