<?php
declare(strict_types=1);
function env(string $key, string $default=''): string { return $_ENV[$key] ?? (getenv($key) ?: $default); }
$envFile=dirname(__DIR__).'/.env';
if(is_file($envFile)) foreach(file($envFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
    $line=trim($line); if($line==='' || str_starts_with($line,'#')) continue;
    [$key,$value]=array_pad(explode('=',$line,2),2,''); $value=trim($value);
    // Buang sepasang petikan luar sahaja supaya kata laluan yang mengandungi petikan kekal utuh.
    if(strlen($value)>=2 && ($value[0]==='"' || $value[0]==="'") && $value[strlen($value)-1]===$value[0]) $value=substr($value,1,-1);
    $_ENV[trim($key)]=$value;
}
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once __DIR__.'/errors.php';
require_once __DIR__.'/tenant.php'; require_once __DIR__.'/audit_chain.php'; require_once __DIR__.'/security.php';
// Semakan Argon2id/libsodium dibuat oleh pemasang (paparan mesra) dan index.php.
function request_is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || ($_SERVER['SERVER_PORT']??'')==='443' || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https'; }
function db(?PDO $use=null): PDO {
 static $pdo; if($use) $pdo=$use;
 // Pelayan MySQL penuh (1040 / 1203 "Too many connections") biasanya sementara: cuba semula 2 kali sebelum menyerah.
 for($try=0;!$pdo;$try++){
  try { $pdo=new PDO('mysql:host='.env('DB_HOST','127.0.0.1').';port='.env('DB_PORT','3306').';dbname='.env('DB_NAME','urustugas').';charset=utf8mb4',env('DB_USER'),env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); $pdo->exec("SET time_zone='+08:00'"); } // NOW() DB = waktu Malaysia, sama seperti date() PHP
  catch(PDOException $ex){ if($try>=2 || !preg_match('/\b(1040|1203)\b/',$ex->getMessage())) throw $ex; usleep(400000*($try+1)); }
 }
 return $pdo;
}
function query(string $sql,array $params=[]): PDOStatement { $s=db()->prepare($sql); $s->execute($params); return $s; }
/** Nilai POST/GET sebagai rentetan; tatasusunan atau nilai bukan skalar ditolak sebagai kosong. */
function input(string $key, string $source='post'): string { $v=($source==='get'?$_GET:$_POST)[$key]??''; return is_string($v)?$v:''; }
function e(mixed $s): string { return htmlspecialchars((string)($s??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function master_key(): string { $k=base64_decode(env('APP_KEY'),true); if($k===false || strlen($k)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('APP_KEY tidak sah.'); return $k; }
/** Kunci sekolah semasa (subkunci HKDF daripada APP_KEY); konteks platform mendapat subkunci platform. */
function key_bytes(): string { static $c=[]; return $c[sid()]??=tenant_key(master_key()); }
function encrypt_value(string $v): string { $n=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return base64_encode($n.sodium_crypto_secretbox($v,$n,key_bytes())); }
function decrypt_value(?string $v): string { if(!$v) return ''; $b=base64_decode($v,true); if($b===false || strlen($b)<40) throw new RuntimeException('Ciphertext rosak.'); $p=sodium_crypto_secretbox_open(substr($b,24),substr($b,0,24),key_bytes()); if($p===false) throw new RuntimeException('Pengesahan penyulitan gagal.'); return $p; }
function hash_password(string $password): string { return password_hash($password,PASSWORD_ARGON2ID,['memory_cost'=>65536,'time_cost'=>3,'threads'=>1]); }
/** URL aset dengan versi (cache-busting): berubah setiap kali fail dikemas kini, jadi pelayar tidak guna CSS/JS lama. */
function asset(string $file): string { $p=dirname(__DIR__).'/public_html/assets/'.$file; return '/assets/'.$file.(is_file($p)?'?v='.substr(md5(filemtime($p).'-'.filesize($p)),0,10):''); }
function redirect(string $path): never { header('Location: '.tenant_location($path)); exit; }
function csrf(): string { return $_SESSION['csrf']??=bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.e(csrf()).'">'; }
function verify_csrf(): void { if(!hash_equals(csrf(),(string)($_POST['csrf']??''))){ error_page(419); } }
function flash(string $text): void { $_SESSION['flash']=$text; }
/** $detail: teks ringkas pilihan (≤255) untuk log sekolah, cth. nama jawatan — sebahagian daripada rantai audit. */
function audit(string $action,string $entity,?int $id=null,string $detail=''): void {
    if(sid()<=0){ $pid=$_SESSION['pid']??null; audit_append(0,['admin_id'=>$pid,'admin_ref'=>$pid,'school_id'=>null,'school_ref'=>null,'action'=>mb_substr($action,0,60),'detail'=>mb_substr($entity.($id!==null?' #'.$id:'').($detail!==''?' · '.$detail:''),0,255),'ip_hash'=>audit_ip_hash()]); return; }
    $via=$_SESSION['imp']['pid']??null; audit_append(sid(),['user_id'=>$_SESSION['uid']??null,'via_platform'=>$via,'via_ref'=>$via,'action'=>$action,'entity'=>$entity,'entity_id'=>$id,'detail'=>$detail,'ip_hash'=>audit_ip_hash()]);
}
/** $refresh: buang cache (cth. selepas akaun dicipta semula oleh reset demo). */
function current_user(bool $refresh=false): ?array { static $user=false; if($refresh) $user=false; if($user===false) $user=isset($_SESSION['uid'])?query('SELECT id,name,email,role,totp_secret,auth_version,must_change,allowed_ips FROM users WHERE id=? AND school_id=? AND active=1',[$_SESSION['uid'],sid()])->fetch():null; if($user && (int)$user['auth_version']!==(int)($_SESSION['auth_version']??0)){ $_SESSION=[]; $user=null; } return $user?:null; }
function can_write(): bool { return in_array(current_user()['role']??'', ['admin','pk'],true); }
function require_writer(): void { if(!can_write()) error_page(403,'Peranan anda hanya membenarkan paparan. Hubungi pentadbir jika anda perlu mengemas kini rekod.'); }
function require_admin(): void { if((current_user()['role']??'')!=='admin') error_page(403,'Halaman ini untuk pentadbir sistem sahaja.'); }
function base32_encode(string $raw): string { $bits=''; foreach(str_split($raw) as $c) $bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT); $out=''; foreach(str_split($bits,5) as $b) $out.='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[bindec(str_pad($b,5,'0'))]; return $out; }
function base32_decode(string $s): string { $bits=''; foreach(str_split(strtoupper($s)) as $c){ $i=strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',$c); if($i===false) throw new InvalidArgumentException('Rahsia TOTP tidak sah.'); $bits.=str_pad(decbin($i),5,'0',STR_PAD_LEFT); } $out=''; foreach(str_split($bits,8) as $b) if(strlen($b)===8) $out.=chr(bindec($b)); return $out; }
function totp(string $secret,int $step,int $digits=6): string { $h=hash_hmac('sha1',pack('N2',intdiv($step,4294967296),$step%4294967296),base32_decode($secret),true); $o=ord($h[19])&15; $num=unpack('N',substr($h,$o,4))[1]&0x7fffffff; return str_pad((string)($num%(10**$digits)),$digits,'0',STR_PAD_LEFT); }
function totp_step(string $secret,string $code): ?int { if(!preg_match('/^\d{6}$/D',$code)) return null; $now=intdiv(time(),30); foreach([$now-1,$now,$now+1] as $t) if(hash_equals(totp($secret,$t),$code)) return $t; return null; }
function valid_password(string $p): bool { return strlen($p)>=12 && strlen($p)<=128; }
function rate_key(string $scope,string $value): string { return hash_hmac('sha256',$scope.':'.$value,key_bytes()); }
/** Kunci baldi had cubaan menggunakan subkunci sekolah (key_bytes), jadi kiraan tidak dikongsi antara sekolah. */
function rate_allowed(string $key,int $limit=8): bool { $r=query('SELECT attempts,UNIX_TIMESTAMP(window_start) AS started FROM rate_limits WHERE bucket=?',[$key])->fetch(); return !$r || time()-(int)$r['started']>900 || (int)$r['attempts']<$limit; }
function rate_hit(string $key): void { if(random_int(1,50)===1) query('DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 DAY)'); query('INSERT INTO rate_limits (bucket,attempts,window_start) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE),1,attempts+1), window_start=IF(window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE),NOW(),window_start)',[$key]); }
if(PHP_SAPI!=='cli'){
 ob_start('tenant_rewrite_html'); // Kod status (cth. 403) masih boleh ditetapkan selepas kandungan mula dijana; pautan mutlak diberi awalan /KOD dalam mod laluan.
 tenant_boot();
 header_remove('X-Powered-By');
 header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
 header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: no-referrer'); header('Permissions-Policy: camera=(), microphone=(), geolocation=()'); header('Cache-Control: no-store');
 if(env('SESSION_SECURE',request_is_https()?'1':'0')==='1') header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
 ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
 if(!defined('NO_SESSION')){
 // Kuki sesi berasingan: sekolah (subdomain → hos sendiri; laluan → Path=/KOD/) dan platform. __Host- memerlukan Path=/.
 $secure=env('SESSION_SECURE',request_is_https()?'1':'0')==='1'; $cpath=tenant_prefix()!==''?tenant_prefix().'/':'/'; $cname=school()?'urustugas_sesi':'urustugas_platform';
 session_name($secure?($cpath==='/'?'__Host-':'__Secure-').$cname:$cname); session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Strict','path'=>$cpath]); session_start();
 // Sesi terikat pada sekolah: kuki sekolah lain (atau platform) tidak boleh digunakan di sini.
 if(isset($_SESSION['school_id']) && (int)$_SESSION['school_id']!==sid()){ $_SESSION=[]; session_regenerate_id(true); }
 $_SESSION['school_id']=sid();
 if(isset($_SESSION['last']) && (time()-$_SESSION['last']>1800 || time()-($_SESSION['started']??time())>28800)){ $_SESSION=[]; session_regenerate_id(true); }
 $_SESSION['last']=time(); $_SESSION['started']??=time();
 }
 set_exception_handler(function(Throwable $ex){
  $ref=bin2hex(random_bytes(5)); error_log('UrusTugas error '.$ref.': '.get_class($ex).': '.$ex->getMessage().' @ '.$ex->getFile().':'.$ex->getLine());
  // Ralat sambungan pangkalan data: mesej mesra tanpa mendedahkan butiran sulit.
  $db=$ex instanceof PDOException && preg_match('/\b(2002|2003|2005|2006|1040|1044|1045|1049|1203)\b|could not find driver|Connection refused|No such file/i',$ex->getMessage());
  $busy=$db && preg_match('/\b(1040|1203)\b/',$ex->getMessage());
  $hint=$busy?'Pelayan pangkalan data sedang sibuk (terlalu banyak sambungan). Tunggu seminit dan cuba lagi. Pentadbir pelayan: semak had max_connections / max_user_connections MySQL.':($db?'Pangkalan data tidak dapat dihubungi. Semak pelayan MySQL/MariaDB sedang berjalan dan tetapan DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS dalam .env.':'');
  if(!$hint && preg_match('/APP_KEY/',$ex->getMessage())) $hint='APP_KEY dalam .env tidak sah. Gunakan nilai base64 32 bait (php bin/key.php).';
  // Mod local sahaja: paparkan punca sebenar untuk memudahkan pembangunan.
  $extra=env('APP_ENV')==='local'?'<pre class="secret">'.e(get_class($ex).': '.$ex->getMessage()."\n".$ex->getFile().':'.$ex->getLine()).'</pre>':'';
  error_page($db?503:500,$hint,['ref'=>$ref,'extra'=>$extra,'no_db'=>$db,'title'=>$busy?'Pelayan sedang sibuk':($db?'Pangkalan data tidak tersedia':null)]);
 });
 // Ralat PHP maut (bukan pengecualian): paparkan halaman ralat yang sama dan bukan skrin kosong.
 register_shutdown_function(function(){
  $err=error_get_last(); if(!$err || !in_array($err['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)) return;
  $ref=bin2hex(random_bytes(5)); error_log('UrusTugas fatal '.$ref.': '.$err['message'].' @ '.$err['file'].':'.$err['line']);
  try { error_page(500,'',['ref'=>$ref,'no_db'=>true]); } catch(Throwable) {}
 });
}
