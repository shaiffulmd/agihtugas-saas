<?php
declare(strict_types=1);
/*
 * Pendaftaran sekolah awam (/daftar) — corak delima-multi:
 *  1. Borang (CAPTCHA wajib + perangkap bot): kod KPM, nama, jenis, negeri, pegawai (nama/e-mel/telefon), kata laluan Pentadbir Utama,
 *     persetujuan terma & polisi (direkod). Had: 5 / jam setiap IP, had harian pendaftaran disahkan (Tetapan Platform), domain e-mel dibenarkan.
 *  2. Status "emel": pautan pengesahan ke e-mel pegawai (48 jam; halaman pautan perlu tekan butang supaya pengimbas e-mel tidak mengesahkan).
 *     Jika e-mel platform belum dikonfigurasi, pengesahan dilangkau.
 *  3. Selepas disahkan: "menunggu" kelulusan platform (lalai) atau terus "aktif" (kelulusan automatik) dengan Pro percubaan.
 *  Laman sekolah 404 sebelum aktif. Pendaftaran tidak disahkan dibuang selepas 48 jam (kod dibebaskan); tempahan tidak disahkan > 1 jam
 *  boleh diambil alih oleh e-mel lain supaya kod KPM tidak boleh "diduduki".
 */
const NEGERI=['Johor','Kedah','Kelantan','Melaka','Negeri Sembilan','Pahang','Perak','Perlis','Pulau Pinang','Sabah','Sarawak','Selangor','Terengganu','W.P. Kuala Lumpur','W.P. Labuan','W.P. Putrajaya'];
const DAFTAR_SAH_JAM=48;

function registration_open(): bool { return pset('daftar_buka')==='1' && emergency_mode()===''; }
function registration_domains(): array { return array_values(array_filter(array_map('trim',explode(',',pset('daftar_domain'))))); }
function registration_domain_ok(string $email): bool {
    $dom=registration_domains(); if(!$dom) return true;
    $d=strtolower((string)substr((string)strrchr($email,'@'),1)); return in_array($d,$dom,true);
}
function registration_mail_ready(): bool { return pset('mail_from')!=='' && platform_domain()!==''; }
function registration_token(int $school): string {
    $t=bin2hex(random_bytes(24));
    query('INSERT INTO platform_settings(name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['daftar_'.substr(hash('sha256',$t),0,40),json_encode(['sid'=>$school,'exp'=>time()+DAFTAR_SAH_JAM*3600])]);
    return $t;
}
function registration_send_link(array $s): void {
    require_once __DIR__.'/mailer.php';
    $t=registration_token((int)$s['id']); $url=tenant_scheme().'://'.platform_domain().'/daftar/sah?t='.$t; $e=null;
    if(!send_mail((string)$s['pic_email'],(string)$s['pic_name'],'Sahkan pendaftaran sekolah',"Terima kasih kerana mendaftarkan {$s['name']} ({$s['code']}) di ".platform_name().".\n\nSahkan e-mel anda dalam ".DAFTAR_SAH_JAM." jam:\n$url\n\nAbaikan e-mel ini jika anda tidak membuat pendaftaran.",$e))
        platform_log('mail_failed',(int)$s['id'],'pengesahan pendaftaran: '.(string)$e);
}
/** Buang pendaftaran yang tidak disahkan selepas tempoh (kod dibebaskan). Dipanggil oleh kerja harian. */
function registration_cleanup(): int {
    $n=0; foreach(query('SELECT * FROM schools WHERE status="emel" AND created_at<DATE_SUB(NOW(),INTERVAL '.DAFTAR_SAH_JAM.' HOUR)')->fetchAll() as $s){ school_delete($s); platform_log('registration_expired',null,$s['code']); $n++; }
    query('DELETE FROM platform_settings WHERE name LIKE "daftar\\_%" AND updated_at<DATE_SUB(NOW(),INTERVAL 3 DAY)');
    return $n;
}
/** Sekolah aktif selepas kelulusan (manual atau automatik): Pro percubaan bermula sekarang. */
function registration_activate(array $s,string $by): void {
    require_once __DIR__.'/mailer.php';
    $exp=date('Y-m-d',strtotime('+'.(int)pset('percubaan_hari').' days'));
    query('UPDATE schools SET status="aktif",approved_at=NOW(),expires_on=? WHERE id=?',[$exp,$s['id']]); platform_log('school_approved',(int)$s['id'],$by);
    $e=null; send_mail((string)$s['pic_email'],(string)$s['pic_name'],'Pendaftaran sekolah diluluskan',"Pendaftaran {$s['name']} ({$s['code']}) di ".platform_name()." telah diluluskan.\n\nPro percubaan sah hingga ".date('d/m/Y',strtotime($exp)).". Log masuk sebagai Pentadbir Utama dengan e-mel ini:\n".school_url($s['code'],'/?page=login'),$e);
}

function registration_submit(): array {
    $ip=client_ip();
    form_guard('daftar'); // perangkap bot + CAPTCHA (sentiasa)
    $kIp=rate_key('daftar-ip',$ip); if(!rate_allowed($kIp,5)) throw new DomainException('Terlalu banyak pendaftaran dari rangkaian ini. Cuba lagi kemudian.');
    rate_hit($kIp);
    $verifiedToday=(int)query('SELECT COUNT(*) FROM platform_log WHERE action IN ("registration_verified","registration_received") AND created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)')->fetchColumn();
    $pendingToday=(int)query('SELECT COUNT(*) FROM schools WHERE status="emel" AND created_at>DATE_SUB(NOW(),INTERVAL 1 DAY)')->fetchColumn();
    if($verifiedToday>=(int)pset('daftar_had_harian') || $pendingToday>=200) throw new DomainException('Pendaftaran baharu ditutup sementara (had harian dicapai). Cuba lagi esok.');
    $v=[]; foreach(['kod','nama','negeri','pic_nama','pic_emel','pic_telefon','jenis'] as $k) $v[$k]=trim(preg_replace('/[\x00-\x1F\x7F]/u','',input($k)));
    $v['kod']=strtoupper(preg_replace('/\s+/','',$v['kod'])); $v['pic_emel']=strtolower($v['pic_emel']); $v['pic_telefon']=preg_replace('/[^0-9+]/','',$v['pic_telefon']);
    if(!school_code_valid($v['kod'])) throw new DomainException('Kod sekolah mesti kod KPM: 3 huruf + 4 digit (cth. CEA1234).');
    if($v['nama']==='' || mb_strlen($v['nama'])>150) throw new DomainException('Nama sekolah diperlukan.');
    if(!in_array($v['negeri'],NEGERI,true)) throw new DomainException('Sila pilih negeri.');
    if($v['pic_nama']==='' || mb_strlen($v['pic_nama'])>120) throw new DomainException('Nama pegawai bertanggungjawab diperlukan.');
    if(strlen($v['pic_emel'])>190 || !filter_var($v['pic_emel'],FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mel tidak sah.');
    if(!registration_domain_ok($v['pic_emel'])) throw new DomainException('Pendaftaran hanya dibenarkan menggunakan e-mel rasmi berdomain: '.implode(', ',registration_domains()).'.');
    if(strlen($v['pic_telefon'])<9 || strlen($v['pic_telefon'])>15) throw new DomainException('No. telefon tidak sah.');
    $pw=input('password'); if(!valid_password($pw) || !hash_equals($pw,input('password_confirm'))) throw new DomainException('Kata laluan mesti 12–128 aksara dan sepadan dengan pengesahan.');
    if(input('setuju')!=='1') throw new DomainException('Anda perlu bersetuju dengan Terma & Syarat dan Polisi Privasi.');
    $ex=school_find($v['kod']);
    if($ex && $ex['status']==='emel' && (strtotime((string)$ex['created_at'])<time()-DAFTAR_SAH_JAM*3600 || (strtotime((string)$ex['created_at'])<time()-3600 && !hash_equals(strtolower((string)$ex['pic_email']),$v['pic_emel'])))){ school_delete($ex); platform_log('registration_replaced',null,$ex['code']); $ex=null; }
    if($ex) throw new DomainException($ex['status']==='emel'?'Pendaftaran untuk kod sekolah ini sedang menunggu pengesahan e-mel. Semak peti masuk (dan folder Spam), atau hantar semula pautan di bawah.':'Kod sekolah ini sudah didaftarkan. Hubungi pentadbir platform jika ini satu kesilapan.');
    $mail=registration_mail_ready();
    [$s]=school_create($v['kod'],$v['nama'],$v['jenis']==='SK'?'SK':'SMK',$v['pic_nama'],$v['pic_emel'],$pw,['status'=>$mail?'emel':'menunggu','expires_on'=>null,'state'=>$v['negeri'],'pic_name'=>$v['pic_nama'],'pic_email'=>$v['pic_emel'],'pic_phone'=>$v['pic_telefon'],'must_change'=>false]);
    if(function_exists('consent_record')) consent_record((int)$s['id'],$v['pic_nama'],$v['pic_emel'],'pendaftaran');
    platform_log('registration_received',(int)$s['id'],$s['code'].' · '.$v['pic_emel']);
    if($mail){ registration_send_link($s); return ['emel',$s]; }
    registration_notify_platform($s); return ['menunggu',$s];
}
function registration_notify_platform(array $s): void {
    $to=pset('emel_notis'); if(!$to) return; require_once __DIR__.'/mailer.php'; $e=null;
    send_mail($to,'Pentadbir Platform','Pendaftaran sekolah baharu: '.$s['code'],"Pendaftaran baharu menunggu kelulusan:\n\n{$s['code']} · {$s['name']}\nPegawai: {$s['pic_name']} <{$s['pic_email']}>\n\n".tenant_scheme().'://'.platform_domain().'/platform/?p=sekolah&id='.(int)$s['id'],$e);
}
function registration_verify(string $t): string {
    if(!preg_match('/^[a-f0-9]{48}$/',$t)) return 'invalid';
    $k='daftar_'.substr(hash('sha256',$t),0,40); $j=json_decode((string)query('SELECT value FROM platform_settings WHERE name=?',[$k])->fetchColumn(),true);
    if(!is_array($j) || (int)($j['exp']??0)<time()) return 'invalid';
    $s=school_by_id((int)$j['sid']); if(!$s || $s['status']!=='emel') return 'invalid';
    if($_SERVER['REQUEST_METHOD']!=='POST') return 'confirm';
    if(query('DELETE FROM platform_settings WHERE name=?',[$k])->rowCount()!==1) return 'invalid';
    query('UPDATE schools SET status="menunggu" WHERE id=?',[$s['id']]); platform_log('registration_verified',(int)$s['id'],$s['code']);
    if(pset('daftar_kelulusan')==='auto'){ registration_activate(school_by_id((int)$s['id']),'automatik'); return 'aktif'; }
    registration_notify_platform($s); return 'menunggu';
}
function registration_resend(): void {
    $ip=client_ip(); $k=rate_key('daftar-semula',$ip); if(!rate_allowed($k,3)) return; rate_hit($k);
    $s=school_find(input('kod')); if(!$s || $s['status']!=='emel' || !hash_equals(strtolower((string)$s['pic_email']),strtolower(trim(input('emel'))))) return;
    $ks=rate_key('daftar-semula-sekolah',(string)$s['id']); if(!rate_allowed($ks,3)) return; rate_hit($ks);
    if(registration_mail_ready()) registration_send_link($s);
}

/* ---------- Halaman ---------- */
function registration_page(): never {
    if(!registration_open()) error_page(404);
    $error=''; $done=null; $resent=false;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        try { if(input('act')==='semula'){ registration_resend(); $resent=true; } else $done=registration_submit(); }
        catch(DomainException $ex){ $error=$ex->getMessage(); }
    }
    $v=fn($k)=>e($done?'':input($k));
    lp_head('Daftar Sekolah'); ?>
<div class="main-content platform-dir"><div class="page-heading"><div><span class="eyebrow teal"><?= e(mb_strtoupper(platform_name())) ?></span><h1>Daftar sekolah</h1><p class="muted">Pro percuma selama <?= (int)pset('percubaan_hari') ?> hari selepas diluluskan. Satu pendaftaran bagi setiap sekolah (kod KPM).</p></div><div class="button-group"><a class="btn outline" href="/direktori">← Direktori sekolah</a></div></div>
<?php if($error!=='') p_notice($error,'error'); if($resent) p_notice('Jika pendaftaran itu masih menunggu pengesahan, pautan baharu telah dihantar ke e-mel pegawai.','success'); ?>
<?php if($done): [$st,$s]=$done; ?><section class="panel padded"><h3>Pendaftaran diterima</h3><p><?= $st==='emel'?'Semak e-mel <strong>'.e($s['pic_email']).'</strong> dan tekan pautan pengesahan dalam '.DAFTAR_SAH_JAM.' jam (semak juga folder Spam).':'Pendaftaran menunggu kelulusan pentadbir platform. Anda akan dimaklumkan melalui e-mel.' ?></p></section>
<?php else: ?><section class="panel padded spaced"><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="act" value="daftar">
<label class="field">Kod sekolah (KPM)<input name="kod" value="<?= $v('kod') ?>" maxlength="7" pattern="[A-Za-z]{3}[0-9]{4}" placeholder="CEA1234" required></label>
<label class="field">Jenis sekolah<select name="jenis"><option value="SMK">Sekolah Menengah</option><option value="SK"<?= input('jenis')==='SK'?' selected':'' ?>>Sekolah Rendah</option></select></label>
<label class="field full">Nama sekolah<input name="nama" value="<?= $v('nama') ?>" maxlength="150" required></label>
<label class="field">Negeri<select name="negeri" required><option value="">— Pilih —</option><?php foreach(NEGERI as $n): ?><option<?= input('negeri')===$n?' selected':'' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
<label class="field">Nama pegawai (Pentadbir Utama)<input name="pic_nama" value="<?= $v('pic_nama') ?>" maxlength="120" required></label>
<label class="field">E-mel pegawai<?= registration_domains()?' <small class="muted">('.e(implode(', ',registration_domains())).')</small>':'' ?><input name="pic_emel" type="email" value="<?= $v('pic_emel') ?>" maxlength="190" required></label>
<label class="field">No. telefon<input name="pic_telefon" value="<?= $v('pic_telefon') ?>" maxlength="20" inputmode="tel" required></label>
<label class="field">Kata laluan Pentadbir Utama (12–128)<input name="password" type="password" minlength="12" maxlength="128" required autocomplete="new-password"></label>
<label class="field">Sahkan kata laluan<input name="password_confirm" type="password" required autocomplete="new-password"></label>
<label class="check full"><input type="checkbox" name="setuju" value="1" required> Saya bersetuju dengan <a class="text-link" href="/terma" target="_blank">Terma &amp; Syarat</a> dan <a class="text-link" href="/polisi" target="_blank">Polisi Privasi</a>, dan mengesahkan saya diberi kuasa mendaftarkan sekolah ini.</label>
<div class="full"><?= captcha_field('daftar') ?></div>
<div class="button-group"><button class="btn primary">Daftar sekolah</button></div></form></section>
<section class="panel padded spaced"><details class="manual-key"><summary>Tidak menerima e-mel pengesahan?</summary><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="act" value="semula"><label class="field">Kod sekolah<input name="kod" maxlength="7" required></label><label class="field">E-mel pegawai<input name="emel" type="email" required></label><div class="button-group"><button class="btn outline">Hantar semula pautan</button></div></form></details></section><?php endif; ?>
</div><?php lp_foot(); exit;
}
function registration_verify_page(): never {
    $error='';
    if($_SERVER['REQUEST_METHOD']==='POST') verify_csrf();
    $r=registration_verify(input('t','get'));
    lp_head('Sahkan Pendaftaran'); ?>
<div class="main-content platform-dir"><section class="panel padded"><h1>Pengesahan pendaftaran</h1>
<?php if($r==='confirm'): ?><p>Tekan butang di bawah untuk mengesahkan e-mel pendaftaran sekolah.</p><form method="post"><?= csrf_field() ?><button class="btn primary">Sahkan e-mel saya</button></form>
<?php elseif($r==='menunggu'): ?><p class="notice success">E-mel disahkan. Pendaftaran kini menunggu kelulusan pentadbir platform; anda akan dimaklumkan melalui e-mel.</p>
<?php elseif($r==='aktif'): ?><p class="notice success">E-mel disahkan dan sekolah anda telah diaktifkan. Butiran log masuk telah dihantar ke e-mel anda.</p>
<?php else: ?><p class="notice error">Pautan tidak sah, telah digunakan atau tamat tempoh. Daftar semula atau hantar semula pautan di halaman pendaftaran.</p><?php endif; ?>
<p><a class="text-link" href="/direktori">← Direktori sekolah</a></p></section></div><?php lp_foot(); exit;
}
