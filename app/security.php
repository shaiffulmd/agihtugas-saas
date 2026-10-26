<?php
declare(strict_types=1);
/*
 * Keselamatan platform (corak delima-multi):
 *  - Perlindungan IP: sekatan platform / sekolah (skop semua halaman atau log masuk & halaman tanpa log masuk), bertempoh atau kekal,
 *    sekatan automatik selepas cubaan log masuk berlebihan, senarai IP pentadbir platform (tidak pernah disekat auto; pilihan: panel
 *    platform hanya dari senarai ini), IP pentadbir sekolah, had IP setiap akaun, suis utama, segerak Cloudflare (IP Access Rules).
 *  - CAPTCHA pihak ketiga (Turnstile / hCaptcha / reCAPTCHA v2) menggantikan CAPTCHA sendiri — diaktifkan hanya selepas lulus ujian.
 *  - Wajib 2FA untuk semua pengguna semua sekolah · mod kecemasan (baca sahaja / tutup laman sekolah).
 *  - security.txt + borang laporan keselamatan (/lapor-keselamatan).
 * Rantai audit HMAC: app/audit_chain.php.
 */

/* ---------- Alamat IP ---------- */
/** IP klien. Di belakang proksi dipercayai (TRUSTED_PROXIES, cth. julat Cloudflare) guna CF-Connecting-IP / X-Forwarded-For. */
function client_ip(): string {
    $r=(string)($_SERVER['REMOTE_ADDR']??''); $tp=ip_list(env('TRUSTED_PROXIES'));
    if($tp && ip_in_list($r,$tp)){
        $c=(string)($_SERVER['HTTP_CF_CONNECTING_IP']??''); if($c===''){ $x=explode(',',(string)($_SERVER['HTTP_X_FORWARDED_FOR']??'')); $c=trim((string)end($x)); }
        if(filter_var($c,FILTER_VALIDATE_IP)) return $c;
    }
    return $r;
}
/** IP atau CIDR → bentuk kanonik, atau null. /0–/7 (IPv4) dan /0–/15 (IPv6) ditolak supaya tidak menyekat seluruh internet. */
function ip_canon(string $s): ?string {
    [$ip,$bits]=array_pad(explode('/',trim($s),2),2,null); $bin=@inet_pton((string)$ip); if($bin===false) return null;
    $max=strlen($bin)*8; if($bits===null) return inet_ntop($bin);
    if(!ctype_digit($bits) || (int)$bits<($max===32?8:16) || (int)$bits>$max) return null;
    return inet_ntop($bin).'/'.(int)$bits;
}
function ip_list(string $text): array { $o=[]; foreach(preg_split('/[\s,;]+/',$text) as $x) if($x!=='' && ($k=ip_canon($x))) $o[]=$k; return array_values(array_unique($o)); }
/** Teks borang → [teks kanonik, ralat|null]. */
function ip_list_form(string $raw): array {
    $bad=array_filter(preg_split('/[\s,;]+/',$raw),fn($x)=>$x!=='' && !ip_canon($x));
    return $bad?['','IP tidak sah: '.implode(', ',array_slice($bad,0,5))]:[implode("\n",ip_list($raw)),null];
}
function ip_in_cidr(string $ip,string $cidr): bool {
    [$net,$bits]=array_pad(explode('/',$cidr,2),2,null); $a=@inet_pton($ip); $b=@inet_pton($net);
    if($a===false || $b===false || strlen($a)!==strlen($b)) return false;
    $bits=$bits===null?strlen($a)*8:(int)$bits; $bytes=intdiv($bits,8); $rem=$bits%8;
    if(substr($a,0,$bytes)!==substr($b,0,$bytes)) return false;
    if($rem===0) return true; $m=(0xFF<<(8-$rem))&0xFF; return (ord($a[$bytes])&$m)===(ord($b[$bytes])&$m);
}
function ip_in_list(string $ip,array $list): bool { foreach($list as $c) if(ip_in_cidr($ip,$c)) return true; return false; }
/** Had IP setiap akaun (kosong = semua IP). Diabaikan jika Perlindungan IP dimatikan. */
/** Pengguna sekolah: had IP akaun + senarai IP pentadbir sekolah (tetapan ip_admin) bagi peranan pentadbir. */
function user_ip_ok(array $u): bool { if(!account_ip_ok($u['allowed_ips']??null)) return false; $l=($u['role']??'')==='admin' && ip_protect_on() && sid()>0?ip_list(setting('ip_admin')):[]; return !$l || ip_in_list(client_ip(),$l); }
function account_ip_ok(?string $list): bool { if(!ip_protect_on()) return true; $l=ip_list((string)$list); return !$l || ip_in_list(client_ip(),$l); }

/* ---------- Sekatan IP ---------- */
const IP_SCOPES=['semua'=>'Semua halaman','akaun'=>'Log masuk & halaman tanpa log masuk'];
const IP_DURATIONS=['60'=>'1 jam','1440'=>'24 jam','10080'=>'7 hari','43200'=>'30 hari','0'=>'Kekal'];
function ip_protect_on(): bool { return pset('ip_lindung','1')==='1'; }
/** Sekatan aktif yang sepadan (platform atau sekolah ini). $guest = permintaan tanpa log masuk (skop 'akaun' berkuat kuasa). */
function ip_blocked(string $ip,int $sid,bool $guest): ?array {
    if(!ip_protect_on() || $ip==='') return null;
    try {
        // Sekatan automatik peringkat platform (serangan log masuk panel) hanya terpakai di konteks platform — elak menyekat IP NAT sekolah.
        $rows=query('SELECT * FROM ip_blocks WHERE ('.($sid>0?'(school_id IS NULL AND auto=0) OR school_id='.$sid:'school_id IS NULL').') AND scope IN ('.($guest?'"semua","akaun"':'"semua"').') AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY id DESC')->fetchAll();
        foreach($rows as $r) if(ip_in_cidr($ip,$r['ip'])) return $r;
    } catch(PDOException) {} // jadual belum wujud (sebelum naik taraf) — jangan halang laman
    return null;
}
/** Pengawal setiap permintaan (index.php). */
function ip_guard(): void {
    if(PHP_SAPI==='cli') return;
    $guest=defined('NO_SESSION')?false:(school()?!isset($_SESSION['uid']):!isset($_SESSION['pid']));
    $b=ip_blocked(client_ip(),sid(),$guest); if(!$b) return;
    error_page(403,'Alamat IP anda ('.client_ip().') disekat'.($b['expires_at']?' sehingga '.date('d/m/Y H:i',strtotime($b['expires_at'])):'').'. Jika ini satu kesilapan, hubungi pentadbir dan sebut rujukan #'.(int)$b['id'].'.',['title'=>'Akses disekat']);
}
/** Tambah sekatan. $min 0 = kekal. $school null = seluruh platform. Pulangkan id, atau null jika tidak sah / pendua auto. */
function ip_block_add(?int $school,string $ip,string $scope,int $min,string $reason,string $by,bool $auto=false): ?int {
    $ip=ip_canon($ip); if(!$ip || !isset(IP_SCOPES[$scope])) return null;
    if($auto && query('SELECT id FROM ip_blocks WHERE school_id <=> ? AND ip=? AND scope=? AND (expires_at IS NULL OR expires_at>NOW())',[$school,$ip,$scope])->fetchColumn()) return null;
    query('INSERT INTO ip_blocks(school_id,ip,scope,reason,expires_at,auto,by_name) VALUES (?,?,?,?,IF(?>0,DATE_ADD(NOW(),INTERVAL ? MINUTE),NULL),?,?)',[$school,$ip,$scope,mb_substr($reason,0,200),$min,$min,$auto?1:0,mb_substr($by,0,120)]); // tempoh dikira oleh DB: zon masa sama dengan NOW()
    $id=(int)db()->lastInsertId();
    if($school===null && cf_on()) try { cf_sync_add(query('SELECT * FROM ip_blocks WHERE id=?',[$id])->fetch()); } catch(Throwable) {}
    return $id;
}
function ip_block_remove(int $id,?int $school=null): bool {
    $r=query('SELECT * FROM ip_blocks WHERE id=?'.($school!==null?' AND school_id='.(int)$school:''),[$id])->fetch(); if(!$r) return false;
    if($r['cf_id']) try { cf_sync_remove($r); } catch(Throwable) {}
    query('DELETE FROM ip_blocks WHERE id=?',[$id]); return true;
}
/** Sekatan automatik (cubaan log masuk berlebihan). IP pentadbir platform tidak pernah disekat secara automatik. */
function ip_block_auto(string $ip,?int $school,string $reason): void {
    if(!ip_protect_on() || pset('ip_auto','1')!=='1' || $ip==='' || ip_in_list($ip,ip_list(pset('ip_platform')))) return;
    $min=max(5,min(43200,(int)pset('ip_auto_minit','60')));
    try {
        if(!ip_block_add($school,$ip,'akaun',$min,$reason,'auto',true)) return;
        platform_log('ip_auto_block',$school,$ip.' ('.$min.' minit): '.$reason);
        if(filter_var(pset('emel_notis'),FILTER_VALIDATE_EMAIL) && pset('ip_auto_emel','1')==='1'){
            require_once __DIR__.'/settings.php'; require_once __DIR__.'/mailer.php'; $e=null;
            send_mail(pset('emel_notis'),platform_name(),'[Platform] IP disekat secara automatik: '.$ip,"Alamat IP $ip disekat secara automatik selama $min minit (log masuk & halaman tanpa log masuk)".($school?' untuk sekolah '.(school_by_id($school)['code']??$school):' untuk panel & halaman awam platform').".\nSebab: $reason\n\nUrus di Panel Platform → Perlindungan IP.",$e);
        }
    } catch(Throwable) {}
}
/** Panel platform hanya dari senarai IP pentadbir platform (jika diwajibkan). */
function ip_platform_allowed(): bool {
    if(!ip_protect_on() || pset('ip_platform_wajib')!=='1') return true;
    $l=ip_list(pset('ip_platform')); return !$l || ip_in_list(client_ip(),$l);
}

/* ---------- Cloudflare (IP Access Rules peringkat zon, sehala) ---------- */
function cf_on(): bool { return ip_protect_on() && pset('cf_aktif')==='1' && pset('cf_zone')!=='' && pset('cf_token')!==''; }
function cf_api(string $method,string $path,?array $json=null): array {
    require_once __DIR__.'/payments.php';
    [$c,$j,$raw]=gw_http($method,gw_base('https://api.cloudflare.com/client/v4').$path,['headers'=>['Authorization: Bearer '.pdecrypt(pset('cf_token'))]]+($json!==null?['json'=>$json]:[]));
    $ok=$c>=200 && $c<300 && ($j['success']??false)===true;
    return [$ok,$j??[],$ok?'':('HTTP '.$c.' '.mb_substr((string)($j['errors'][0]['message']??strip_tags($raw)),0,150))];
}
/** Sasaran Cloudflare: IP tunggal, atau julat /16 /24 (IPv4) dan /32 /48 /64 (IPv6) sahaja. */
function cf_target(string $ip): ?array {
    if(!str_contains($ip,'/')) return ['target'=>str_contains($ip,':')?'ip6':'ip','value'=>$ip];
    [$n,$b]=explode('/',$ip); $v6=str_contains($n,':');
    return in_array((int)$b,$v6?[32,48,64]:[16,24],true)?['target'=>'ip_range','value'=>$ip]:null;
}
function cf_sync_add(array $r): void {
    if($r['school_id']!==null || ($r['auto'] && pset('cf_auto')!=='1')) return;
    $t=cf_target((string)$r['ip']); if(!$t){ query('UPDATE ip_blocks SET cf_error=? WHERE id=?',['Julat tidak disokong Cloudflare',$r['id']]); return; }
    [$ok,$j,$err]=cf_api('POST','/zones/'.rawurlencode(pset('cf_zone')).'/firewall/access_rules/rules',['mode'=>'block','configuration'=>$t,'notes'=>'urustugas #'.$r['id'].' '.mb_substr((string)$r['reason'],0,80)]);
    query('UPDATE ip_blocks SET cf_id=?,cf_error=? WHERE id=?',[$ok?(string)($j['result']['id']??''):null,$err,$r['id']]);
}
function cf_sync_remove(array $r): void { cf_api('DELETE','/zones/'.rawurlencode(pset('cf_zone')).'/firewall/access_rules/rules/'.rawurlencode((string)$r['cf_id'])); }
function cf_test(): array { return cf_api('GET','/zones/'.rawurlencode(pset('cf_zone'))); }

/* ---------- CAPTCHA pihak ketiga ---------- */
const CAP_PROVIDERS=[
    'sendiri'=>['name'=>'CAPTCHA sendiri (terbina dalam)','key'=>''],
    'turnstile'=>['name'=>'Cloudflare Turnstile','key'=>'ts','script'=>'https://challenges.cloudflare.com/turnstile/v0/api.js','class'=>'cf-turnstile','field'=>'cf-turnstile-response',
        'verify'=>['https://challenges.cloudflare.com','/turnstile/v0/siteverify'],'csp'=>'https://challenges.cloudflare.com'],
    'hcaptcha'=>['name'=>'hCaptcha','key'=>'hc','script'=>'https://js.hcaptcha.com/1/api.js','class'=>'h-captcha','field'=>'h-captcha-response',
        'verify'=>['https://api.hcaptcha.com','/siteverify'],'csp'=>'https://hcaptcha.com https://*.hcaptcha.com'],
    'recaptcha'=>['name'=>'Google reCAPTCHA v2','key'=>'rc','script'=>'https://www.google.com/recaptcha/api.js','class'=>'g-recaptcha','field'=>'g-recaptcha-response',
        'verify'=>['https://www.google.com','/recaptcha/api/siteverify'],'csp'=>'https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/ https://recaptcha.google.com/recaptcha/'],
];
function cap_provider(): string { $p=pset('cap_penyedia','sendiri'); return isset(CAP_PROVIDERS[$p])?$p:'sendiri'; }
function cap_ready(?string $p=null): bool { $p??=cap_provider(); $k=CAP_PROVIDERS[$p]['key']; return $k!=='' && pset($k.'_site')!=='' && pdecrypt(pset($k.'_secret'))!==''; }
function cap_fingerprint(string $p): string { $k=CAP_PROVIDERS[$p]['key']; return $p.':'.substr(hash('sha256',pset($k.'_site').'|'.pset($k.'_secret')),0,16); }
/** Pihak ketiga digunakan hanya jika dihidupkan DAN lulus ujian dengan kunci semasa (elak terkunci keluar). */
function cap_external(): bool { $p=cap_provider(); return $p!=='sendiri' && pset('cap_luar')==='1' && cap_ready($p) && hash_equals(cap_fingerprint($p),pset('cap_lulus')); }
/** CSP halaman yang memaparkan widget pihak ketiga (dipanggil sebelum output dihantar; output ditampan). */
function cap_csp(string $p): void {
    $h=CAP_PROVIDERS[$p]['csp'];
    if(!headers_sent()) header("Content-Security-Policy: default-src 'none'; script-src 'self' $h; style-src 'self' 'unsafe-inline' $h; img-src 'self' data: $h; font-src 'self'; connect-src 'self' $h; frame-src $h; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
}
function cap_widget(string $p): string {
    $c=CAP_PROVIDERS[$p]; cap_csp($p);
    return '<div class="captcha-box"><div class="'.e($c['class']).'" data-sitekey="'.e(pset($c['key'].'_site')).'"></div></div><script src="'.e($c['script']).'" async defer></script>';
}
/** Sahkan token pihak ketiga melalui siteverify. Ralat rangkaian: ikut tetapan cap_gagal (lalai tutup = tolak). */
function cap_verify(string $p,string $token): bool {
    if($token==='' || strlen($token)>4096) return false;
    $c=CAP_PROVIDERS[$p]; require_once __DIR__.'/payments.php';
    try { [$code,$j]=gw_http('POST',gw_base($c['verify'][0]).$c['verify'][1],['form'=>['secret'=>pdecrypt(pset($c['key'].'_secret')),'response'=>$token,'remoteip'=>client_ip()]]); }
    catch(Throwable){ return pset('cap_gagal','tutup')==='buka'; }
    if($code>=500) return pset('cap_gagal','tutup')==='buka';
    return $code===200 && ($j['success']??false)===true;
}

/* ---------- 2FA & mod kecemasan ---------- */
function twofa_forced_all(): bool { return pset('wajib_2fa')==='1'; }
const EMERGENCY_MODES=[''=>'Normal','baca'=>'Baca sahaja (tiada perubahan data di semua sekolah)','tutup'=>'Tutup semua laman sekolah'];
function emergency_mode(): string { $m=pset('kecemasan'); return isset(EMERGENCY_MODES[$m])?$m:''; }
/** Pengawal laman sekolah (index.php). Log masuk/keluar dibenarkan dalam mod baca sahaja. */
function emergency_guard(): void {
    $m=emergency_mode(); if($m==='' || PHP_SAPI==='cli') return;
    $msg=pset('kecemasan_mesej')?:'Sistem dalam penyelenggaraan keselamatan. Cuba sebentar lagi.';
    if($m==='tutup') error_page(503,$msg,['title'=>'Ditutup sementara']);
    if(($_SERVER['REQUEST_METHOD']??'')==='POST' && !in_array((string)($_POST['action']??''),['login','verify','logout','enroll'],true)) error_page(503,'Mod baca sahaja: perubahan data dimatikan sementara oleh pentadbir platform. '.$msg,['title'=>'Baca sahaja']);
}
function emergency_banner(): string {
    return emergency_mode()==='baca'?'<div class="notice error" role="status"><span><strong>Mod baca sahaja:</strong> '.e(pset('kecemasan_mesej')?:'perubahan data dimatikan sementara oleh pentadbir platform.').'</span></div>':'';
}

/* ---------- security.txt & laporan keselamatan ---------- */
function security_txt(): never {
    require_once __DIR__.'/payments.php';
    $base=pay_abs(platform_url('/')); $base=rtrim($base,'/');
    while(ob_get_level()>0) @ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: public, max-age=86400');
    echo "Contact: $base/lapor-keselamatan\n".(filter_var(pset('emel_keselamatan')?:pset('emel_notis'),FILTER_VALIDATE_EMAIL)?'Contact: mailto:'.(pset('emel_keselamatan')?:pset('emel_notis'))."\n":'')
        .'Expires: '.gmdate('Y-m-d\T00:00:00\Z',strtotime('+1 year'))."\nPreferred-Languages: ms, en\nCanonical: $base/.well-known/security.txt\nPolicy: $base/lapor-keselamatan\n";
    exit;
}
function security_report_submit(): void {
    form_guard('lapor');
    $rk=rate_key('lapor-ip',client_ip()); if(!rate_allowed($rk,5)) throw new DomainException('Terlalu banyak laporan. Cuba selepas 15 minit.'); rate_hit($rk);
    $email=strtolower(trim(input('emel'))); $title=trim(input('tajuk')); $body=trim(str_replace("\r\n","\n",input('butiran')));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190) throw new DomainException('E-mel tidak sah.');
    if($title==='' || mb_strlen($title)>160) throw new DomainException('Tajuk perlu diisi (maksimum 160 aksara).');
    if(mb_strlen($body)<20 || mb_strlen($body)>10000) throw new DomainException('Butiran mesti 20–10000 aksara.');
    query('INSERT INTO security_reports(name,email,title,body,ip_hash) VALUES (?,?,?,?,?)',[mb_substr(trim(input('nama')),0,120),$email,$title,$body,hash_hmac('sha256',client_ip(),platform_key())]);
    $id=(int)db()->lastInsertId(); platform_log('security_report',null,'#'.$id.' '.$title);
    if(filter_var(pset('emel_keselamatan')?:pset('emel_notis'),FILTER_VALIDATE_EMAIL)){ require_once __DIR__.'/settings.php'; require_once __DIR__.'/mailer.php'; $e=null; send_mail(pset('emel_keselamatan')?:pset('emel_notis'),platform_name(),'[Keselamatan] Laporan #'.$id.': '.$title,"Laporan keselamatan baharu daripada $email.\n\n$title\n\n".mb_substr($body,0,3000)."\n\nUrus di Panel Platform → Laporan Keselamatan.",$e); }
}
function security_report_page(): never {
    $error=''; $ok=false;
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'){ verify_csrf(); try { security_report_submit(); $ok=true; } catch(DomainException $ex){ $error=$ex->getMessage(); } }
    lp_head('Laporkan isu keselamatan'); ?>
<div class="main-content platform-dir"><div class="page-heading"><div><span class="eyebrow teal"><?= e(mb_strtoupper(platform_name())) ?></span><h1>Laporkan isu keselamatan</h1><p class="muted">Jumpa kelemahan keselamatan? Laporkan secara sulit di sini. Jangan akses, ubah atau padam data orang lain, dan beri kami masa yang munasabah untuk membaikinya sebelum mendedahkannya.</p></div><div class="button-group"><a class="btn outline" href="/">← Direktori</a></div></div>
<?php if($ok): ?><div class="notice success" role="status">Terima kasih. Laporan anda diterima dan akan disemak oleh pentadbir platform.</div><?php else: if($error!==''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel padded"><form method="post" class="form-grid"><?= csrf_field() ?>
<label class="field">Nama (pilihan)<input name="nama" maxlength="120" value="<?= e(input('nama')) ?>"></label><label class="field">E-mel untuk dihubungi<input name="emel" type="email" maxlength="190" required value="<?= e(input('emel')) ?>"></label>
<label class="field full">Tajuk<input name="tajuk" maxlength="160" required value="<?= e(input('tajuk')) ?>"></label>
<label class="field full">Butiran (langkah menghasilkan semula, URL terlibat, kesan)<textarea name="butiran" rows="8" maxlength="10000" required><?= e(input('butiran')) ?></textarea></label>
<div class="full"><?= captcha_field('lapor') ?></div><div class="button-group"><button class="btn primary">Hantar laporan</button></div></form></section><?php endif; ?>
<p class="muted"><a class="text-link" href="/.well-known/security.txt">security.txt</a> · <a class="text-link" href="/polisi">Polisi Privasi</a></p></div><?php lp_foot(); exit;
}

/* ---------- Kerja harian ---------- */
function security_daily(): array {
    $n=0; foreach(query('SELECT * FROM ip_blocks WHERE expires_at IS NOT NULL AND expires_at<=NOW()')->fetchAll() as $r){ ip_block_remove((int)$r['id']); $n++; }
    return ['ip_tamat'=>$n];
}
