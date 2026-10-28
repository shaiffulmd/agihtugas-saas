<?php
declare(strict_types=1);
/*
 * Peringkat platform (tanpa kod sekolah dalam URL):
 *   /            → direktori sekolah (cari ikut kod / nama)
 *   /platform/   → panel Pentadbir Platform: senarai sekolah, tambah sekolah (dengan Pentadbir Utama), gantung/aktif,
 *                  reset kata laluan admin sekolah, reset demo, padam sekolah, log platform, profil & 2FA.
 * Pentadbir platform TIDAK log masuk ke data sekolah; akaun sekolah diurus oleh Pentadbir Utama sekolah itu.
 */

require_once __DIR__.'/platform_accounts.php'; require_once __DIR__.'/subscription.php'; require_once __DIR__.'/registration.php'; require_once __DIR__.'/legal.php'; require_once __DIR__.'/support.php'; require_once __DIR__.'/payment_pages.php'; require_once __DIR__.'/security_pages.php'; require_once __DIR__.'/landing.php'; require_once __DIR__.'/domain.php'; require_once __DIR__.'/appearance.php'; require_once __DIR__.'/faq.php'; require_once __DIR__.'/mailer.php';

/* ---------- Sekolah ---------- */
function school_code_valid(string $code): bool { return (bool)preg_match(SCHOOL_CODE_RE,$code); }
function platform_temp_password(): string { return rtrim(strtr(base64_encode(random_bytes(12)),'+/','-_'),'='); }

/**
 * Cipta sekolah + Pentadbir Utama (kata laluan sementara, wajib tukar pada log masuk pertama).
 * Pulangkan [school, kata laluan sementara]. Konteks sekolah dipulihkan kepada asal selepas selesai.
 */
/** $opt: status (aktif|emel|menunggu), expires_on, state, pic_name, pic_email, pic_phone. Lalai: aktif, Pro percubaan (Tetapan Platform). */
/** $type: jenis sekolah SK (rendah) | SMK (menengah) — menentukan gelaran Guru Besar / Pengetua. */
function school_create(string $code,string $name,string $type,string $adminName,string $adminEmail,?string $password=null,array $opt=[]): array {
    $code=strtoupper(trim($code)); $name=trim($name); $adminEmail=strtolower(trim($adminEmail)); $adminName=trim($adminName);
    if(!school_code_valid($code)) throw new DomainException('Kod sekolah mesti kod KPM: 3 huruf + 4 digit (cth. CEA1234).');
    if($name==='' || mb_strlen($name)>150) throw new DomainException('Nama sekolah perlu diisi (maksimum 150 aksara).');
    if(school_find($code)) throw new DomainException('Kod sekolah '.$code.' sudah didaftarkan.');
    if($adminName==='' || mb_strlen($adminName)>120) throw new DomainException('Nama Pentadbir Utama tidak sah.');
    if(strlen($adminEmail)>190 || !filter_var($adminEmail,FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mel Pentadbir Utama tidak sah.');
    $password??=platform_temp_password(); if(!valid_password($password)) throw new DomainException('Kata laluan mesti 12 hingga 128 aksara.');
    $prev=school(); $prevMode=tenant_mode();
    $status=$opt['status']??'aktif'; $exp=$opt['expires_on']??date('Y-m-d',strtotime('+'.(int)pset('percubaan_hari').' days'));
    query('INSERT INTO schools(code,name,status,expires_on,approved_at,state,pic_name,pic_email,pic_phone) VALUES (?,?,?,?,?,?,?,?,?)',[$code,$name,$status,$exp,$status==='aktif'?date('Y-m-d H:i:s'):null,mb_substr((string)($opt['state']??''),0,40),mb_substr((string)($opt['pic_name']??$adminName),0,120),strtolower((string)($opt['pic_email']??$adminEmail)),mb_substr((string)($opt['pic_phone']??''),0,30)]);
    $school=school_by_id((int)db()->lastInsertId());
    try {
        tenant_use($school,'path');
        create_admin($adminName,$adminEmail,$password,$opt['must_change']??true); // kata laluan sementara: wajib tukar pada log masuk pertama
        save_settings(['school_name'=>$name,'school_type'=>$type==='SK'?'SK':'SMK']);
        school_engine_init();
        audit('school_created','settings');
    } catch(Throwable $ex) { tenant_use($school,'path'); school_wipe(); query('DELETE FROM schools WHERE id=?',[$school['id']]); tenant_use($prev,$prevMode); throw $ex; }
    tenant_use($prev,$prevMode);
    return [$school,$password];
}
/** Padam sekolah dan SEMUA datanya (tidak boleh dibatalkan). */
function school_delete(array $school): void {
    $prev=school(); $prevMode=tenant_mode();
    tenant_use($school,'path'); school_wipe(); tenant_use($prev,$prevMode);
    query('DELETE FROM schools WHERE id=?',[(int)$school['id']]);
}
function platform_schools(string $q=''): array {
    $w=''; $p=[];
    if($q!==''){ $w=' WHERE s.code LIKE ? OR s.name LIKE ?'; $like='%'.addcslashes($q,'%_\\').'%'; $p=[$like,$like]; }
    $rows=query('SELECT s.*,(SELECT COUNT(*) FROM users u WHERE u.school_id=s.id) users,(SELECT MAX(u.last_login_at) FROM users u WHERE u.school_id=s.id) last_login FROM schools s'.$w.' ORDER BY s.code',$p)->fetchAll();
    foreach($rows as &$r) $r['teachers']=free_count('guru',(int)$r['id']);
    return $rows;
}

/* ---------- Demo (DEMO_MODE=1) ---------- */
const DEMO_PLATFORM=['Pentadbir Platform Demo','platform@demo.test','Demo-Platform-2026'];
const DEMO_SCHOOLS=[['DEM0001','SMK Demo UrusTugas Satu','SMK'],['DEM0002','SK Demo UrusTugas Dua','SK']];
/** Pemasangan demo: pentadbir platform + dua sekolah berdata rekaan (akaun demo sama di kedua-dua sekolah, data terasing). */
function demo_platform_seed(): void {
    if(!demo_env()) throw new RuntimeException('Hanya untuk DEMO_MODE=1.');
    create_platform_admin(...DEMO_PLATFORM);
    foreach(DEMO_SCHOOLS as [$code,$name,$type]){ [$s]=school_create($code,$name,$type,DEMO_ACCOUNTS[0][1],DEMO_ACCOUNTS[0][2]); demo_addons_on($s); with_school($s,'demo_reset'); }
}
/** Sekolah demo: semua addon diaktifkan untuk tunjuk cara. */
function demo_addons_on(array $s): void {
    if(!function_exists('addons_ready') || !addons_ready()) return;
    query('UPDATE schools SET addons=? WHERE id=?',[implode(',',array_keys(SCHOOL_ADDONS)),(int)$s['id']]);
}

/* ---------- Pengesahan pentadbir platform ---------- */
function platform_user(): ?array {
    static $u=false;
    if($u===false){
        $u=isset($_SESSION['pid'])?query('SELECT id,name,email,totp_secret,auth_version,last_totp_step,allowed_ips FROM platform_admins WHERE id=? AND active=1',[$_SESSION['pid']])->fetch():null;
        if($u && (int)$u['auth_version']!==(int)($_SESSION['pauth']??0)){ $_SESSION=[]; $u=null; }
    }
    return $u?:null;
}
function platform_login_ok(array $a): never {
    $next=(string)($_SESSION['p_next']??''); // halaman yang diminta sebelum log masuk (cth. kembali dari sesi sokongan)
    session_regenerate_id(true);
    $_SESSION=['pid'=>(int)$a['id'],'pauth'=>(int)$a['auth_version'],'csrf'=>bin2hex(random_bytes(32)),'started'=>time(),'last'=>time(),'school_id'=>0];
    query('UPDATE platform_admins SET last_login_at=NOW() WHERE id=?',[(int)$a['id']]); platform_log('login');
    redirect(preg_match('~^/platform/\?p=[a-z]+(&id=\d+)?$~D',$next)?$next:'/platform/');
}
/** CAPTCHA log masuk platform selepas 3 cubaan gagal dari IP ini (atau sentiasa jika CAPTCHA pihak ketiga aktif). */
function platform_login_captcha(): bool { return cap_external() || rate_count(rate_key('platform-login-ip',client_ip()))>=3; }
function platform_need_2fa(): bool { return env('REQUIRE_2FA','1')!=='0'; }
/** Pengawal untuk tindakan platform (sandaran, integriti): log masuk + 2FA jika diwajibkan. */
function require_platform_admin(): void { $me=platform_user(); if(!$me || (platform_need_2fa() && !$me['totp_secret'])) error_page(403,'Untuk pentadbir platform sahaja.'); }
/** Pengesahan semula kata laluan pentadbir platform (medan admin_password daripada admin_confirm_field()). */
function platform_confirm_password(): void {
    $me=platform_user(); $rk=rate_key('platform-confirm',(string)($me['id']??0));
    if(!rate_allowed($rk)) throw new DomainException('Terlalu banyak cubaan pengesahan. Cuba selepas 15 minit.');
    if(!$me || !password_verify(input('admin_password'),(string)query('SELECT password_hash FROM platform_admins WHERE id=?',[$me['id']])->fetchColumn())){ rate_hit($rk); platform_log('confirm_failed'); throw new DomainException('Kata laluan anda (pengesahan pentadbir) tidak sah.'); }
}

/* ---------- Tindakan POST ---------- */
function platform_action(string $action): never {
    $ip=client_ip();
    if($action==='p_login'){
        $email=mb_substr(strtolower(trim(input('email'))),0,190); $kIp=rate_key('platform-login-ip',$ip); $kUser=rate_key('platform-login-user',$email);
        if(!rate_allowed($kIp,10)){ ip_block_auto($ip,null,'Terlalu banyak cubaan log masuk platform'); throw new DomainException('Terlalu banyak cubaan. Cuba lagi selepas 15 minit.'); }
        if(!rate_allowed($kUser,6)) throw new DomainException('Terlalu banyak cubaan. Cuba lagi selepas 15 minit.');
        if(platform_login_captcha()) form_guard('plogin');
        $a=query('SELECT * FROM platform_admins WHERE email=? AND active=1',[$email])->fetch();
        $hash=$a['password_hash']??'$argon2id$v=19$m=65536,t=3,p=1$c29tZXNhbHRzb21lc2FsdA$5Ezu7b5QZr2rxnZAU5t6wK1u0VZyUZ0b6jG4fFuoN8c';
        if(!password_verify(input('password'),$hash) || !$a){ rate_hit($kIp); rate_hit($kUser); platform_log('login_failed',null,$email); throw new DomainException('E-mel atau kata laluan tidak sah.'); }
        if(!account_ip_ok($a['allowed_ips'])){ rate_hit($kIp); platform_log('login_ip_denied',null,$email.' dari '.$ip,(int)$a['id']); throw new DomainException('Log masuk akaun ini tidak dibenarkan dari alamat IP anda ('.$ip.').'); }
        if($a['totp_secret']){ session_regenerate_id(true); $_SESSION['pending_pid']=(int)$a['id']; redirect('/platform/?p=verify'); }
        platform_login_ok($a);
    }
    if($action==='p_verify'){
        $pid=(int)($_SESSION['pending_pid']??0); $a=$pid?query('SELECT * FROM platform_admins WHERE id=? AND active=1',[$pid])->fetch():null; if(!$a || !$a['totp_secret']) redirect('/platform/');
        $k=rate_key('platform-2fa',(string)$pid); if(!rate_allowed($k,6)) throw new DomainException('Terlalu banyak cubaan. Cuba lagi selepas 15 minit.');
        $step=totp_step(decrypt_value($a['totp_secret']),trim(input('code')));
        if($step===null || query('UPDATE platform_admins SET last_totp_step=? WHERE id=? AND last_totp_step < ?',[$step,$pid,$step])->rowCount()!==1){ rate_hit($k); platform_log('2fa_failed'); throw new DomainException('Kod 2FA tidak sah.'); }
        unset($_SESSION['pending_pid']); platform_login_ok($a);
    }
    $me=platform_user(); if(!$me) redirect('/platform/');
    if($action==='p_logout'){ platform_log('logout'); imp_revoke((int)$me['id']); /* sesi sokongan di laman sekolah turut tamat */ $_SESSION=[]; session_regenerate_id(true); redirect('/platform/'); }
    if($action==='p_enroll'){
        $secret=(string)($_SESSION['p_enroll']??''); $step=$secret!==''?totp_step($secret,trim(input('code'))):null;
        if($step===null) throw new DomainException('Kod tidak sah. Imbas semula kod QR dan cuba lagi.');
        query('UPDATE platform_admins SET totp_secret=?,last_totp_step=? WHERE id=? AND totp_secret IS NULL',[encrypt_value($secret),$step,$me['id']]);
        unset($_SESSION['p_enroll']); platform_log('2fa_enabled'); flash('2FA diaktifkan.'); redirect('/platform/?p=profil');
    }
    if($action==='p_password'){
        $a=query('SELECT password_hash FROM platform_admins WHERE id=?',[$me['id']])->fetch();
        if(!password_verify(input('current'),(string)$a['password_hash'])) throw new DomainException('Kata laluan semasa salah.');
        $new=input('new'); if(!valid_password($new) || !hash_equals($new,input('confirm'))) throw new DomainException('Kata laluan baharu mesti 12–128 aksara dan sepadan dengan pengesahan.');
        query('UPDATE platform_admins SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[hash_password($new),$me['id']]); $_SESSION['pauth']=(int)$me['auth_version']+1;
        platform_log('password_changed'); flash('Kata laluan dikemas kini.'); redirect('/platform/?p=profil');
    }
    if(platform_need_2fa() && !$me['totp_secret']) redirect('/platform/?p=profil');
    if(str_starts_with($action,'p_rupa_')){ require_platform_admin(); handle_appearance_platform($action); }
    if(str_starts_with($action,'p_faq_')){ require_platform_admin(); handle_faq_platform($action); }
    if(str_starts_with($action,'upd_')){ require_platform_admin(); if(demo_env()) throw new DomainException('Mod demo: kemas kini sistem dimatikan.'); require_once __DIR__.'/updater.php'; flash(upd_action($action,'platform: '.(platform_user()['email']??''))); redirect('/platform/?p=kemaskini'); }
    if(str_starts_with($action,'p_dom_')){ require_platform_admin(); handle_domain_platform($action,$me); }
    if(str_starts_with($action,'p_sec_')){ require_platform_admin(); handle_security_platform($action,$me); }
    if(preg_match('/^p_(pay|disc|tj)_/',$action)){ require_platform_admin(); handle_payments_platform($action,$me); }
    if($action==='p_settings_save'){
        require_platform_admin();
        $int=function(string $k,int $min,int $max,string $label): string { $v=trim(input($k)); if(!preg_match('/^\d+$/',$v) || (int)$v<$min || (int)$v>$max) throw new DomainException($label.' mesti nombor '.$min.'–'.$max.'.'); return (string)(int)$v; };
        $name=trim(input('nama_platform')); if($name==='' || mb_strlen($name)>80) throw new DomainException('Nama platform perlu diisi (maksimum 80 aksara).');
        $harga=str_replace(',','',trim(input('harga_pro'))); if(!preg_match('/^\d{1,6}(\.\d{1,2})?$/',$harga)) throw new DomainException('Harga Pro tidak sah (cth. 120 atau 120.00).');
        $notis=strtolower(trim(input('emel_notis'))); if($notis!=='' && !filter_var($notis,FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mel notis platform tidak sah.');
        $dom=array_values(array_unique(array_filter(array_map(fn($d)=>strtolower(trim(ltrim(trim($d),'@'))),preg_split('/[\s,]+/',input('daftar_domain'))))));
        foreach($dom as $d) if(!preg_match('/^(?=.{3,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/D',$d)) throw new DomainException('Domain e-mel tidak sah: '.$d);
        $kv=['nama_platform'=>$name,'emel_notis'=>$notis,'harga_pro_sen'=>(string)(int)round((float)$harga*100),'percubaan_hari'=>$int('percubaan_hari',0,365,'Tempoh percubaan'),
            'percubaan_cetak'=>input('percubaan_cetak')==='1'?'1':'0','percubaan_pdf'=>input('percubaan_pdf')==='1'?'1':'0','percubaan_pdf_had'=>$int('percubaan_pdf_had',0,10000,'Had muat turun PDF percubaan'),'percubaan_tanda_air'=>input('percubaan_tanda_air')==='1'?'1':'0',
            'free_aktif'=>input('free_aktif')==='1'?'1':'0','free_guru'=>$int('free_guru',1,100000,'Had guru Free'),'free_jawatan'=>$int('free_jawatan',1,100000,'Had jawatan Free'),'free_pengguna'=>$int('free_pengguna',1,1000,'Had pengguna Free'),'pro_ciri'=>implode(',',array_values(array_intersect(array_keys(PRO_FEATURES),array_filter((array)($_POST['pro_ciri']??[]),'is_string')))),
            'free_tidak_aktif_bulan'=>$int('free_tidak_aktif_bulan',1,60,'Tempoh tidak aktif Free'),'padam_hari'=>$int('padam_hari',0,3650,'Padam data selepas'),
            'terma_teks'=>mb_substr(str_replace("\r\n","\n",trim(input('terma_teks'))),0,20000),'polisi_teks'=>mb_substr(str_replace("\r\n","\n",trim(input('polisi_teks'))),0,20000),
            'daftar_buka'=>input('daftar_buka')==='1'?'1':'0','daftar_kelulusan'=>input('daftar_kelulusan')==='auto'?'auto':'manual','daftar_domain'=>implode(', ',$dom),'daftar_had_harian'=>$int('daftar_had_harian',1,1000,'Had pendaftaran sehari')];
        if((int)$kv['harga_pro_sen']<100) throw new DomainException('Harga Pro minimum RM1.00.');
        $m=input('mail_method'); if(!in_array($m,['mail','smtp']+(env('APP_ENV')==='local'?[2=>'log']:[]),true)) throw new DomainException('Kaedah e-mel tidak sah.');
        $from=strtolower(trim(input('mail_from'))); if($from!=='' && !filter_var($from,FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mel pengirim tidak sah.');
        $kv+=['mail_method'=>$m,'mail_from'=>$from,'mail_from_name'=>mb_substr(mail_clean_header(input('mail_from_name')),0,80)];
        // Medan SMTP disimpan walaupun kaedah semasa mail() (supaya tidak hilang); disahkan jika diisi atau jika SMTP dipilih.
        if($m==='smtp' || trim(input('smtp_host'))!==''){
            $host=strtolower(trim(input('smtp_host'))); if(!preg_match('/^[a-z0-9.-]{3,190}$/',$host)) throw new DomainException('Hos SMTP tidak sah (cth. smtp-relay.brevo.com).');
            $sec=input('smtp_secure'); if(!in_array($sec,['tls','ssl','none'],true)) throw new DomainException('Penyulitan SMTP tidak sah.');
            $kv+=['smtp_preset'=>smtp_preset_valid(input('smtp_preset')),'smtp_host'=>$host,'smtp_port'=>$int('smtp_port',1,65535,'Port SMTP'),'smtp_secure'=>$sec,'smtp_user'=>mb_substr(trim(input('smtp_user')),0,190)];
            if(trim(input('smtp_pass'))!=='') $kv['smtp_pass']=pencrypt(trim(input('smtp_pass')));
        }
        pset_set($kv); platform_log('settings_saved'); flash('Tetapan platform disimpan.'); redirect('/platform/?p=tetapan');
    }
    if($action==='p_opcache_reset'){
        require_platform_admin();
        if(!function_exists('opcache_reset') || !(@opcache_get_status(false)['opcache_enabled']??false)) throw new DomainException('OPcache tidak aktif pada pelayan ini — tiada cache untuk dikosongkan.');
        $ok=@opcache_reset(); clearstatcache(true);
        platform_log($ok?'opcache_reset':'opcache_reset_failed');
        if(!$ok) throw new DomainException('OPcache tidak dapat dikosongkan (mungkin dihadkan oleh opcache.restrict_api pada pelayan). Hubungi penyedia hosting atau mulakan semula PHP.');
        flash('Cache OPcache dikosongkan. Fail PHP terkini akan dimuat semula pada permintaan seterusnya.'); redirect('/platform/?p=maklumat');
    }
    if($action==='p_mail_test'){
        require_platform_admin(); $err=null;
        if(!pset('mail_from')) throw new DomainException('Isi dan simpan E-mel pengirim dahulu.');
        $ok=send_mail($me['email'],$me['name'],'Ujian e-mel '.platform_name(),"Ini e-mel ujian daripada panel platform ".platform_name().".\n\nJika anda menerima e-mel ini, tetapan e-mel platform berfungsi.",$err);
        platform_log($ok?'mail_test_ok':'mail_test_failed',null,(string)$err);
        if(!$ok) throw new DomainException('E-mel ujian gagal: '.$err); flash('E-mel ujian dihantar ke '.$me['email'].'.'); redirect('/platform/?p=tetapan');
    }
    if(str_starts_with($action,'backup_')){ if(demo_env()) throw new DomainException('Mod demo: sandaran dimatikan.'); handle_backup($action); }
    if(str_starts_with($action,'system_')) handle_system($action);
    if(str_starts_with($action,'pa_')) handle_platform_admins($action,$me);
    if(str_starts_with($action,'p_sk_')) handle_support_platform($action,$me);
    if($action==='p_consents_csv') consents_csv();
    if($action==='p_school_create'){
        [$s,$pw]=school_create(input('code'),input('name'),input('type'),input('admin_name'),input('admin_email'));
        platform_log('school_created',(int)$s['id'],$s['code']);
        $demo=demo_env() && input('demo')==='1'; if($demo){ demo_addons_on($s); with_school($s,'demo_reset'); }
        $_SESSION['p_created']=['code'=>$s['code'],'email'=>strtolower(trim(input('admin_email'))),'password'=>$pw,'demo'=>$demo];
        redirect('/platform/?p=sekolah&id='.(int)$s['id']);
    }
    $s=school_by_id((int)input('school_id')); if(!$s) error_page(404,'Sekolah tidak dijumpai.');
    if($action==='p_impersonate'){
        $reason=trim(input('reason')); if(mb_strlen($reason)<5 || mb_strlen($reason)>200) throw new DomainException('Nyatakan alasan sokongan (5–200 aksara), cth. "Tiket #12: auto-agihan gagal".');
        if($s['status']!=='aktif') throw new DomainException('Sekolah digantung — aktifkan dahulu.');
        $t=imp_issue((int)$me['id'],$s,(int)input('user_id'),$reason); platform_log('school_impersonate_issue',(int)$s['id'],'pengguna '.(int)input('user_id'));
        header('Location: '.school_url($s['code'],'/?page=masuk_platform&t='.rawurlencode($t))); exit; // URL penuh (laluan /KOD/ atau subdomain)
    }
    if($action==='p_approve'){
        if(!in_array($s['status'],['menunggu','emel'],true)) throw new DomainException('Sekolah ini tidak menunggu kelulusan.');
        registration_activate($s,'platform'); flash('Sekolah '.$s['code'].' diluluskan; Pro percubaan bermula.');
    }
    elseif($action==='p_reject'){
        if(!in_array($s['status'],['menunggu','emel'],true)) throw new DomainException('Sekolah ini tidak menunggu kelulusan.');
        require_once __DIR__.'/mailer.php'; $e=null; $why=mb_substr(trim(input('reason')),0,300);
        send_mail((string)$s['pic_email'],(string)$s['pic_name'],'Pendaftaran sekolah tidak diluluskan',"Pendaftaran {$s['name']} ({$s['code']}) di ".platform_name()." tidak diluluskan.".($why!==''?"\n\nSebab: $why":'')."\n\nHubungi pentadbir platform untuk maklumat lanjut.",$e);
        school_delete($s); platform_log('school_rejected',null,$s['code'].($why!==''?': '.$why:'')); flash('Pendaftaran '.$s['code'].' ditolak dan dipadam.'); redirect('/platform/');
    }
    elseif($action==='p_trial_letter'){
        if(input('mode')==='reset'){ with_school($s,fn()=>sys_set('percubaan_pdf_kira','0')); platform_log('school_trial_letter_reset',(int)$s['id'],$s['code']); flash('Kiraan muat turun PDF percubaan '.$s['code'].' diset semula.'); }
        else {
            $had=trim(input('had')); if(!preg_match('/^\d{1,5}$/',$had)) throw new DomainException('Had muat turun PDF mesti nombor 0–99999.');
            $o=input('mode')==='sekolah'?json_encode(['cetak'=>input('cetak')==='1','pdf'=>input('pdf')==='1','had'=>(int)$had,'tanda'=>input('tanda')==='1']):'';
            with_school($s,fn()=>sys_set('percubaan_surat',(string)$o)); platform_log('school_trial_letter',(int)$s['id'],$o===''?'ikut global':$o);
            flash($o===''?'Surat percubaan '.$s['code'].' kini mengikut tetapan platform.':'Tetapan surat percubaan khusus disimpan untuk '.$s['code'].'.');
        }
        redirect('/platform/?p=sekolah&id='.(int)$s['id'].'#surat-percubaan');
    }
    elseif($action==='p_addon'){
        platform_addon_set($s,input('addon'),input('state')==='1'); flash(input('state')==='1'?'Addon diaktifkan untuk '.$s['code'].'.':'Addon dimatikan untuk '.$s['code'].'.');
        if(input('kembali')==='addon') redirect('/platform/?p=addon');
    }
    elseif($action==='p_school_status'){
        $st=$s['status']==='aktif'?'digantung':'aktif'; query('UPDATE schools SET status=? WHERE id=?',[$st,$s['id']]);
        platform_log($st==='aktif'?'school_activated':'school_suspended',(int)$s['id'],$s['code']); flash($st==='aktif'?'Sekolah diaktifkan semula.':'Sekolah digantung: semua pengguna sekolah ini tidak boleh mengakses sistem.');
    }
    elseif($action==='p_expiry'){
        $mode=input('mode'); $cur=(string)($s['expires_on']??''); $base=$cur!=='' && $cur>=date('Y-m-d')?$cur:date('Y-m-d');
        $new=match($mode){ 'tahun'=>date('Y-m-d',strtotime($base.' +1 year')), 'tarikh'=>trim(input('expires_on')), 'free'=>date('Y-m-d',strtotime('-1 day')), default=>'' };
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$new); if(!$dt || $dt->format('Y-m-d')!==$new || $new<'2020-01-01' || $new>'2100-12-31') throw new DomainException('Tarikh tamat tidak sah.');
        query('UPDATE schools SET expires_on=? WHERE id=?',[$new,$s['id']]); platform_log('school_expiry_set',(int)$s['id'],($cur?:'—').' → '.$new.' ('.$mode.')');
        flash('Tarikh tamat Pro ditetapkan kepada '.date('d/m/Y',strtotime($new)).'.');
    }
    elseif($action==='p_archive'){
        if(strtoupper(trim(input('confirm_code')))!==$s['code']) throw new DomainException('Taip kod sekolah '.$s['code'].' untuk mengesahkan.');
        platform_confirm_password(); school_archive($s,'manual oleh platform'); flash('Data '.$s['code'].' dipadam; rekod sekolah diarkib.');
    }
    elseif($action==='p_school_rename'){
        $name=trim(input('name')); if($name==='' || mb_strlen($name)>150) throw new DomainException('Nama sekolah tidak sah.');
        query('UPDATE schools SET name=?,notes=? WHERE id=?',[$name,mb_substr(trim(input('notes')),0,255),$s['id']]); platform_log('school_renamed',(int)$s['id'],$name); flash('Butiran sekolah disimpan.');
    }
    elseif($action==='p_admin_reset'){
        $uid=(int)input('user_id'); $pw=platform_temp_password();
        $n=query('UPDATE users SET password_hash=?,must_change=1,auth_version=auth_version+1,totp_secret=NULL,last_totp_step=-1,active=1 WHERE id=? AND school_id=? AND role="admin"',[hash_password($pw),$uid,$s['id']])->rowCount();
        if(!$n) throw new DomainException('Pentadbir sekolah tidak dijumpai.');
        query('DELETE FROM recovery_codes WHERE school_id=? AND user_id=?',[$s['id'],$uid]);
        with_school($s,fn()=>audit('platform_admin_reset','users',$uid)); platform_log('school_admin_reset',(int)$s['id'],'user '.$uid);
        $_SESSION['p_reset']=['user'=>$uid,'password'=>$pw];
    }
    elseif($action==='p_demo_reset'){
        if(!demo_env()) throw new DomainException('Reset demo hanya dibenarkan apabila DEMO_MODE=1.');
        with_school($s,'demo_reset'); platform_log('school_demo_reset',(int)$s['id'],$s['code']); flash('Data demo sekolah '.$s['code'].' dijana semula.');
    }
    elseif($action==='p_school_delete'){
        if(strtoupper(trim(input('confirm_code')))!==$s['code']) throw new DomainException('Taip kod sekolah '.$s['code'].' untuk mengesahkan pemadaman.');
        if(!password_verify(input('password'),(string)query('SELECT password_hash FROM platform_admins WHERE id=?',[$me['id']])->fetchColumn())) throw new DomainException('Kata laluan anda salah.');
        school_delete($s); platform_log('school_deleted',null,$s['code'].' '.$s['name']); flash('Sekolah '.$s['code'].' dan semua datanya dipadam.'); redirect('/platform/');
    }
    else error_page(400);
    redirect('/platform/?p=sekolah&id='.(int)$s['id']);
}

/* ---------- Paparan ---------- */
// views.php (konteks sekolah) tidak dimuatkan di platform; fungsi kecil yang digunakan oleh system_page()/sandaran_page().
if(!function_exists('notice')){ function notice(string $text,string $class='info'): void { echo '<div role="status" class="notice '.e($class).'">'.e($text).'</div>'; } }
if(!function_exists('badge')){ function badge(mixed $text): string { return '<span class="badge '.(in_array($text,['Aktif','Selesai'],true)?'green':'').'">'.e($text).'</span>'; } }
function p_notice(string $text,string $class='info'): void { echo '<div role="status" class="notice '.e($class).'">'.e($text).'</div>'; }
function p_head(string $title,string $extraHead=''): void {
    ?><!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title><?= e($title) ?> · <?= e(platform_name()) ?></title><link rel="stylesheet" href="<?= e(asset('app.css')) ?>"><link rel="stylesheet" href="<?= e(asset('tugas.css')) ?>"><link rel="stylesheet" href="/?page=theme-css"><script src="<?= e(asset('app.js')) ?>" defer></script><?= function_exists('plat_logo_has') && plat_logo_has()?'<link rel="icon" type="image/png" href="'.e(plat_logo_url()).'">':'' ?><?= $extraHead ?></head><body><?php
}
function p_auth(string $title,string $sub,callable $form,string $error): never {
    p_head($title); ?>
<div class="auth-shell"><div class="auth-story"><a class="brand" href="/"><?= plat_brand_mark() ?> <span class="brand-text"><?= plat_logo_has() || platform_name()!=='UrusTugas'?e(platform_name()):'urus<span class="brand-light">tugas</span>' ?></span></a><div><span class="hero-pill"><span class="dot"></span>PANEL PLATFORM · BERBILANG SEKOLAH</span><h1>Satu platform.<br><em>Banyak sekolah.</em></h1><p>Urus pendaftaran sekolah, status akaun dan Pentadbir Utama setiap sekolah.</p></div><small>Data setiap sekolah diasingkan.</small></div>
<main class="auth-panel"><div class="auth-form"><span class="eyebrow teal">PENTADBIR PLATFORM</span><h2><?= e($title) ?></h2><p class="muted"><?= e($sub) ?></p><?php if(!empty($_SESSION['flash'])){ p_notice((string)$_SESSION['flash'],'success'); unset($_SESSION['flash']); } if($error!=='') p_notice($error,'error'); $form(); ?><a class="auth-back" href="/direktori">← Direktori sekolah</a></div></main></div></body></html><?php exit;
}
/** Menu panel platform: [kumpulan => [[p, ikon, label, lencana]]]. */
function platform_nav(): array {
    return [
        'Sekolah'=>[['','school','Senarai sekolah'],['baru','plus','Tambah sekolah'],['addon','star','Addon Sekolah'],['sokongan','help','Sokongan',support_unread_platform()]],
        'Langganan'=>[['bayaran','card','Bayaran',pay_pending_count()],['kaedah','key','Kaedah Bayaran'],['diskaun','tag','Kod Diskaun'],['tajaan','gift','Tajaan']],
        'Keselamatan'=>[['keselamatan','shield','Keselamatan'],['ipsekat','ban','Perlindungan IP'],['audit','list','Rantai Audit'],['laporan','flag','Laporan Keselamatan',security_reports_new()],['sistem','activity','Integriti & Status']],
        'Sistem'=>[['tetapan','sliders','Tetapan Platform'],['domain','globe','Subdomain Sekolah'],['rupa','palette','Rupa Halaman Utama'],['faq','help','Soalan Lazim (FAQ)'],['pentadbir','users','Pentadbir Platform'],['sandaran','database','Sandaran'],['persetujuan','check','Persetujuan'],['log','clipboard','Log platform'],['maklumat','server','Maklumat Sistem'],['kemaskini','download','Kemas Kini Sistem']],
        'Akaun'=>[['profil','user','Profil & 2FA']],
    ];
}
function p_shell(array $me,string $page,callable $body,string $error,string $title=''): never {
    $GLOBALS['P_ERROR']=$error;
    // Mod subdomain: "Masuk sebagai pentadbir" mengalih (selepas POST) ke KOD.domain — origin lain. CSP form-action meliputi
    // lencongan selepas POST, jadi benarkan subdomain sekolah platform ini sahaja (bukan hos lain).
    if(subdomain_on() && !headers_sent()) header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self' ".tenant_scheme().'://*.'.platform_domain()."; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    if($title===''){ $title='Panel Platform'; foreach(platform_nav() as $items) foreach($items as $it) if($it[0]===$page) $title=$it[2]; }
    p_head($title); ?>
<?= function_exists('icons_sprite')?icons_sprite():'' ?><aside class="sidebar" id="sidebar" aria-label="Menu platform"><div class="side-atas"><a class="side-brand" href="/platform/"><?= plat_brand_mark() ?> <span class="brand-text"><?= plat_logo_has() || platform_name()!=='UrusTugas'?e(platform_name()):'urus<span class="brand-light">tugas</span>' ?></span></a><button type="button" class="side-tutup" aria-label="Tutup menu"><?= icon('x') ?></button></div><nav class="side-nav" aria-label="Navigasi platform"><?php foreach(platform_nav() as $group=>$items): ?><div class="kump"><?= e($group) ?></div><ul><?php foreach($items as $it): [$k,$ic,$l]=$it; $badge=(int)($it[3]??0); $on=$page===$k; ?><li><a class="nav-item<?= $on?' active':'' ?>" href="/platform/<?= $k!==''?'?p='.$k:'' ?>"<?= $on?' aria-current="page"':'' ?>><?= icon($ic) ?><span><?= e($l) ?></span><?php if($badge): ?><b class="nav-badge" aria-label="<?= $badge ?> perlu tindakan"><?= $badge ?></b><?php endif; ?></a></li><?php endforeach; ?></ul><?php endforeach; ?></nav></aside><div class="scrim" hidden></div>
<div class="workspace"><header class="topbar"><button class="mobile-toggle" aria-label="Buka menu" aria-expanded="false" aria-controls="sidebar"><?= icon('menu') ?></button><div class="top-title"><p class="top-h"><?= e($title) ?></p><p>Panel Platform · v<?= e(APP_VERSION) ?></p></div><div class="top-right"><div class="user-meta"><strong><?= e($me['name']) ?></strong><small>Pentadbir Platform</small></div><form method="post"><?= csrf_field() ?><button class="btn sm outline-ink" name="action" value="p_logout" title="Log keluar"><?= icon('log-out') ?><span class="hide-sm">Log keluar</span></button></form></div></header>
<main class="main-content"><?php if($page!=='maklumat' && php_cve_vulnerable()) p_notice('Keselamatan PHP: versi '.PHP_VERSION.' terdedah kepada '.PHP_CVE_IDS.' (RCE tanpa log masuk). Naik taraf PHP dan mulakan semula PHP-FPM — lihat Maklumat Sistem.','error'); if($page!=='maklumat' && function_exists('db_cve_vulnerable') && db_cve_vulnerable()) p_notice('Keselamatan pangkalan data: versi MariaDB terdedah kepada CVE Oktober 2026 (CVSS sehingga 9.9). Naik taraf ke versi dibaiki — lihat Maklumat Sistem.','error'); if($page!=='sistem' && ($ir=integrity_last()) && $ir['problem']) p_notice('Amaran integriti: fail aplikasi berubah atau fail mencurigakan dikesan. Semak di Integriti & Status.','error'); if($page!=='sandaran' && ($ba=backup_alert())) p_notice('Sandaran: '.$ba,'error'); if(!empty($_SESSION['flash'])){ p_notice((string)$_SESSION['flash'],'success'); unset($_SESSION['flash']); } if($error!=='') p_notice($error,'error'); $body(); ?></main></div></body></html><?php exit;
}

/** / — halaman utama platform (app/landing.php). */
function platform_directory(): never { platform_landing(); }

const SCHOOL_FILTERS=['semua'=>'Semua','pro'=>'Pro','hampir'=>'Pro tamat ≤ 30 hari','free'=>'Free','tamat'=>'Tamat','menunggu'=>'Menunggu kelulusan','emel'=>'E-mel belum disahkan','digantung'=>'Digantung','diarkib'=>'Diarkib'];
function platform_page_schools(): void {
    $q=mb_substr(trim(input('q','get')),0,80); $f=input('tapis','get'); if(!isset(SCHOOL_FILTERS[$f])) $f='semua';
    $all=platform_schools($q); $count=[]; foreach($all as $s){ $pl=school_plan($s); $count[$pl]=($count[$pl]??0)+1; $d=school_days_left($s); if($pl==='pro' && $d!==null && $d<=30) $count['hampir']=($count['hampir']??0)+1; }
    $rows=array_values(array_filter($all,function($s) use($f){ $pl=school_plan($s); $d=school_days_left($s); return $f==='semua' || $pl===$f || ($f==='hampir' && $pl==='pro' && $d!==null && $d<=30); })); ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Sekolah</h1><p class="muted"><?= count($all) ?> sekolah · data setiap sekolah diasingkan dengan school_id.</p></div><div class="button-group"><a class="btn primary" href="/platform/?p=baru">＋ Tambah sekolah</a></div></div>
<nav class="takwim-filter" aria-label="Tapis sekolah"><?php foreach(SCHOOL_FILTERS as $k=>$l): ?><a class="chip<?= $f===$k?' on':'' ?>" href="/platform/?tapis=<?= $k ?>&amp;q=<?= e(rawurlencode($q)) ?>"><?= e($l) ?><?= $k!=='semua' && !empty($count[$k])?' ('.(int)$count[$k].')':'' ?></a><?php endforeach; ?></nav>
<form method="get" class="form-grid flush"><input type="hidden" name="tapis" value="<?= e($f) ?>"><label class="field full">Cari<input name="q" value="<?= e($q) ?>" placeholder="Kod atau nama"></label></form>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>Kod</th><th>Nama</th><th>Pakej</th><th>Tamat Pro</th><th>Pengguna</th><th>Guru</th><th>Log masuk terakhir</th><th></th></tr></thead><tbody>
<?php foreach($rows as $s): $pl=school_plan($s); $d=school_days_left($s); ?><tr class="<?= in_array($pl,['pro','free'],true)?'':'row-muted' ?>"><td><strong><?= e($s['code']) ?></strong></td><td><?= e($s['name']) ?><?= $s['state']!==''?'<small class="cell-sub">'.e($s['state']).'</small>':'' ?></td><td><?= plan_badge($pl) ?></td><td><?= $s['expires_on']?e(date('d/m/Y',strtotime($s['expires_on']))).($d!==null && $d>=0 && $d<=30?' <span class="badge warn">'.$d.' hari</span>':''):'—' ?></td><td><?= (int)$s['users'] ?></td><td><?= (int)$s['teachers'] ?></td><td><?= $s['last_login']?e(date('d/m/Y H:i',strtotime($s['last_login']))):'<span class="muted">—</span>' ?></td><td><a class="text-link" href="/platform/?p=sekolah&amp;id=<?= (int)$s['id'] ?>">Urus</a></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="8" class="muted">Tiada sekolah bagi penapis ini.</td></tr><?php endif; ?></tbody></table></div></section><?php
}
function platform_page_new(): void { ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Tambah sekolah</h1><p class="muted">Sekolah terus aktif. Pentadbir Utama menerima kata laluan sementara (dipaparkan sekali) dan wajib menukarnya serta menyediakan 2FA.</p></div></div>
<section class="panel spaced"><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_school_create">
<label class="field">Kod sekolah (KPM)<input name="code" maxlength="7" placeholder="CEA1234" pattern="[A-Za-z]{3}[0-9]{4}" required></label>
<label class="field">Jenis sekolah<select name="type"><option value="SMK">Sekolah Menengah (ketua: Pengetua)</option><option value="SK">Sekolah Rendah (ketua: Guru Besar)</option></select></label>
<label class="field full">Nama sekolah<input name="name" maxlength="150" required></label>
<label class="field">Nama Pentadbir Utama<input name="admin_name" maxlength="120" required></label>
<label class="field">E-mel Pentadbir Utama<input name="admin_email" type="email" maxlength="190" required></label>
<?php if(demo_env()): ?><label class="check full"><input type="checkbox" name="demo" value="1"> Isi dengan data demo (DEMO_MODE=1)</label><?php endif; ?>
<div class="button-group full"><button class="btn primary" type="submit">Cipta sekolah</button></div></form></section><?php
}
function platform_page_school(array $s): void {
    $admins=query('SELECT id,name,email,active,must_change,last_login_at,totp_secret IS NOT NULL twofa FROM users WHERE school_id=? AND role="admin" ORDER BY id',[$s['id']])->fetchAll();
    $stat=query('SELECT (SELECT COUNT(*) FROM users WHERE school_id=?) u,(SELECT COUNT(*) FROM users WHERE school_id=? AND active=1 AND role="admin") a,(SELECT MAX(last_login_at) FROM users WHERE school_id=?) l',[$s['id'],$s['id'],$s['id']])->fetch();
    $stat['g']=free_count('guru',(int)$s['id']); $stat['j']=free_count('jawatan',(int)$s['id']);
    $created=$_SESSION['p_created']??null; unset($_SESSION['p_created']); $reset=$_SESSION['p_reset']??null; unset($_SESSION['p_reset']);
    $pl=school_plan($s); $dl=school_days_left($s); $del=school_delete_on($s); $sid=(int)$s['id']; $aktif=$s['status']==='aktif';
    $boleh_masuk=$admins && $aktif; $url=school_url($s['code'],'/');
    $hidden=csrf_field().'<input type="hidden" name="school_id" value="'.$sid.'">';
    $st_badge=['aktif'=>'<span class="badge green">Aktif</span>','digantung'=>'<span class="badge warn">Digantung</span>','menunggu'=>'<span class="badge warn">Menunggu kelulusan</span>','emel'=>'<span class="badge warn">E-mel belum disahkan</span>','diarkib'=>'<span class="badge">Diarkib</span>'][$s['status']]??'';
    $nav=['langganan'=>'Langganan']+(function_exists('addons_ready') && addons_ready()?['addon'=>'Addon']:[])+['pentadbir'=>'Pentadbir']+($boleh_masuk?['sokongan'=>'Sokongan']:[])+['butiran'=>'Butiran & status','bahaya'=>'Zon bahaya']; ?>
<section class="sch-hero" aria-labelledby="sch-nama">
  <span class="sch-mono" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(preg_replace('/^(SMK|SK|SMA|SJK\S*|SM|SR)\s+/u','',$s['name']),0,1))) ?></span>
  <div class="sch-hero-main">
    <p class="sch-badges"><code class="sch-code"><?= e($s['code']) ?></code><?= $st_badge ?><?= plan_badge($pl) ?><?= $s['state']!==''?'<span class="badge">'.e($s['state']).'</span>':'' ?></p>
    <h1 id="sch-nama"><?= e($s['name']) ?></h1>
    <p class="muted sch-meta"><a class="text-link" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($url) ?> ↗</a><span>Didaftar <?= e(date('d/m/Y',strtotime((string)$s['created_at']))) ?></span><span>Log masuk terakhir: <?= $stat['l']?e(date('d/m/Y H:i',strtotime((string)$stat['l']))):'belum pernah' ?></span></p>
  </div>
  <div class="sch-hero-act"><a class="btn outline" href="/platform/">← Senarai</a><?php if($aktif): ?><a class="btn outline" href="<?= e($url) ?>" target="_blank" rel="noopener">Buka laman</a><?php endif; ?><?php if($boleh_masuk): ?><a class="btn primary" href="#sokongan">Masuk sebagai pentadbir</a><?php endif; ?></div>
</section>
<nav class="sch-tabs" aria-label="Bahagian halaman"><?php foreach($nav as $k=>$l): ?><a class="chip<?= $k==='bahaya'?' chip-danger':'' ?>" href="#<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
<?php if($created && $created['code']===$s['code']): ?><div class="notice success" role="status"><strong>Sekolah dicipta.</strong> Log masuk Pentadbir Utama: <code><?= e($created['email']) ?></code> · kata laluan sementara <code class="secret"><?= e($created['password']) ?></code> (dipaparkan sekali sahaja).<?= $created['demo']?' Data demo dijana (akaun demo: admin@demo.test / Demo-Admin-2026).':'' ?></div><?php endif; ?>
<?php if($reset): ?><div class="notice success" role="status">Kata laluan sementara baharu: <code class="secret"><?= e($reset['password']) ?></code> (2FA akaun itu juga dikosongkan; dipaparkan sekali sahaja).</div><?php endif; ?>
<section class="stats-grid stats-4 sch-stats"><article class="stat-card"><div class="stat-top"><small>Pengguna</small><?= icon('users') ?></div><strong><?= (int)$stat['u'] ?></strong></article><article class="stat-card"><div class="stat-top"><small>Pentadbir aktif</small><?= icon('key') ?></div><strong><?= (int)$stat['a'] ?></strong></article><article class="stat-card"><div class="stat-top"><small>Guru</small><?= icon('user') ?></div><strong><?= (int)$stat['g'] ?></strong></article><article class="stat-card"><div class="stat-top"><small>Jawatan</small><?= icon('clipboard') ?></div><strong><?= (int)$stat['j'] ?></strong></article></section>
<?php if(in_array($s['status'],['menunggu','emel'],true)): ?><section class="panel padded spaced sch-pending"><h3>Pendaftaran <?= $s['status']==='emel'?'(e-mel belum disahkan)':'menunggu kelulusan' ?></h3>
<dl class="sch-kv"><div><dt>Pegawai</dt><dd><?= e($s['pic_name']) ?></dd></div><div><dt>E-mel</dt><dd><?= e($s['pic_email']) ?></dd></div><div><dt>Telefon</dt><dd><?= e($s['pic_phone']) ?: '—' ?></dd></div><div><dt>Negeri</dt><dd><?= e($s['state']) ?: '—' ?></dd></div><div><dt>Didaftar</dt><dd><?= e(date('d/m/Y H:i',strtotime((string)$s['created_at']))) ?></dd></div></dl>
<form method="post" class="form-grid"><?= $hidden ?><label class="field full">Sebab penolakan (pilihan, dihantar kepada pegawai)<input name="reason" maxlength="300"></label><div class="button-group"><button class="btn primary" name="action" value="p_approve">Luluskan (Pro percubaan <?= (int)pset('percubaan_hari') ?> hari)</button><button class="btn danger" name="action" value="p_reject" data-confirm-simple="Tolak dan padam pendaftaran ini?">Tolak</button></div></form></section><?php endif; ?>
<div class="sch-grid">
<div class="sch-col">
<section class="panel padded" id="langganan"><div class="sch-h"><h3>Langganan</h3><?= plan_badge($pl) ?></div>
<div class="sch-sub">
  <div class="sch-tile"><small>Pakej</small><strong><?= e(PLAN_LABELS[$pl]??$pl) ?></strong></div>
  <div class="sch-tile"><small><?= $s['expires_on'] && $dl!==null && $dl<0?'Pro tamat pada':'Pro sah hingga' ?></small><strong><?= $s['expires_on']?e(date('d/m/Y',strtotime($s['expires_on']))):'—' ?></strong></div>
  <div class="sch-tile<?= $dl!==null && $dl>=0 && $dl<=30?' warn':'' ?>"><small>Baki</small><strong><?= $s['expires_on'] && $dl!==null && $dl>=0?$dl.' hari':'—' ?></strong></div>
</div>
<?php if($s['expires_on'] && $dl!==null && $dl>=0): ?><progress class="sch-bar<?= $dl<=30?' warn':'' ?>" max="365" value="<?= min(365,$dl) ?>" aria-label="Baki langganan <?= $dl ?> hari"></progress><?php endif; ?>
<p class="muted sch-note">Pro <?= $s['expires_on']?($dl>=0?'sah hingga ':'tamat pada ').'<strong>'.e(date('d/m/Y',strtotime($s['expires_on']))).'</strong>'.($dl>=0?' ('.$dl.' hari lagi)':''):'—' ?><?= $del?' · <span class="badge warn">Data dijadual dipadam '.e(date('d/m/Y',strtotime($del))).'</span>':'' ?></p>
<?php if($aktif): ?><form method="post" class="sch-expiry"><?= $hidden ?><input type="hidden" name="action" value="p_expiry">
<label class="field">Tetapkan tarikh tamat Pro<input type="date" name="expires_on" value="<?= e((string)$s['expires_on']) ?>"></label>
<div class="button-group"><button class="btn primary" name="mode" value="tarikh">Simpan tarikh</button><button class="btn outline" name="mode" value="tahun">+1 tahun</button><button class="btn outline danger-text" name="mode" value="free" data-confirm-simple="Tamatkan Pro sekarang?">Tamatkan Pro sekarang</button></div></form><?php endif; ?>
<?php if($aktif) platform_trial_letter_panel($s,$hidden); ?>
<?php if(function_exists('platform_payment_panel')) platform_payment_panel($s); ?></section>
<?php if(function_exists('platform_addon_panel')) platform_addon_panel($s); ?>
<section class="panel" id="pentadbir"><div class="panel-heading"><div><h3>Pentadbir sekolah</h3><p class="muted">Reset menjana kata laluan sementara dan mengosongkan 2FA (contoh: Pentadbir Utama hilang telefon).</p></div></div>
<?php if(!$admins): ?><p class="padded muted">Tiada akaun pentadbir.</p><?php else: ?><ul class="sch-admins"><?php foreach($admins as $a): ?><li>
<span class="sch-av" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($a['name'],0,1))) ?></span>
<div class="sch-adm-main"><strong><?= e($a['name']) ?></strong><small><?= e($a['email']) ?></small><span class="sch-adm-tags"><?= $a['active']?($a['must_change']?'<span class="badge warn">Kata laluan sementara</span>':'<span class="badge green">Aktif</span>'):'<span class="badge">Tidak aktif</span>' ?><?= $a['twofa']?'<span class="badge green">2FA ✓</span>':'<span class="badge">Tiada 2FA</span>' ?></span><small class="muted">Log masuk terakhir: <?= $a['last_login_at']?e(date('d/m/Y H:i',strtotime($a['last_login_at']))):'Belum pernah' ?></small></div>
<form method="post" class="delete-form" data-confirm="Jana kata laluan sementara baharu untuk <?= e($a['email']) ?>?"><?= $hidden ?><input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>"><button class="btn sm outline" name="action" value="p_admin_reset">Reset</button></form>
</li><?php endforeach; ?></ul><?php endif; ?></section>
</div>
<div class="sch-col">
<?php if($boleh_masuk): ?><section class="panel padded sch-support" id="sokongan"><h3>Masuk sebagai pentadbir sekolah</h3><p class="muted">Sesi sokongan maksimum 60 minit. Alasan, mula dan tamat dilog di Log platform dan Log audit sekolah; setiap tindakan direkod "melalui platform". Kata laluan &amp; 2FA akaun itu tidak boleh diubah. Log keluar platform menamatkan sesi ini.</p>
<form method="post" class="stack"><?= $hidden ?><input type="hidden" name="action" value="p_impersonate">
<label class="field">Akaun pentadbir<select name="user_id"><?php foreach($admins as $a) if($a['active']): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name'].' · '.$a['email']) ?></option><?php endif; ?></select></label>
<label class="field">Alasan / no. tiket<input name="reason" minlength="5" maxlength="200" required placeholder="cth. Tiket #12: tetapan tema rosak"></label><button class="btn primary">Masuk sebagai pentadbir →</button></form></section><?php endif; ?>
<section class="panel padded" id="butiran"><h3>Butiran sekolah</h3><form method="post" class="stack"><?= $hidden ?><input type="hidden" name="action" value="p_school_rename">
<label class="field">Nama sekolah<input name="name" value="<?= e($s['name']) ?>" maxlength="150" required></label><label class="field">Catatan dalaman<input name="notes" value="<?= e($s['notes']) ?>" maxlength="255" placeholder="Hanya dilihat oleh pentadbir platform"></label><div><button class="btn primary">Simpan</button></div></form>
<?php if($s['pic_name']!=='' || $s['pic_email']!==''): ?><dl class="sch-kv"><div><dt>Pegawai</dt><dd><?= e($s['pic_name']) ?: '—' ?></dd></div><div><dt>E-mel</dt><dd><?= $s['pic_email']!==''?'<a class="text-link" href="mailto:'.e($s['pic_email']).'">'.e($s['pic_email']).'</a>':'—' ?></dd></div><div><dt>Telefon</dt><dd><?= e($s['pic_phone']) ?: '—' ?></dd></div></dl><?php endif; ?></section>
<?php if(in_array($s['status'],['aktif','digantung'],true)): ?><section class="panel padded sch-status"><div class="sch-h"><h3>Status akses</h3><?= $st_badge ?></div><p class="muted"><?= $aktif?'Menggantung menutup laman sekolah buat sementara; data kekal dan boleh diaktifkan semula bila-bila masa.':'Laman sekolah ditutup. Aktifkan semula untuk membuka akses kepada pentadbir dan guru.' ?></p><form method="post"><?= $hidden ?><input type="hidden" name="action" value="p_school_status"><button class="btn <?= $aktif?'outline danger-text':'primary' ?>"<?= $aktif?' data-confirm-simple="Gantung sekolah ini? Pengguna tidak boleh log masuk sehingga diaktifkan semula."':'' ?>><?= $aktif?'Gantung sekolah':'Aktifkan semula' ?></button></form></section><?php endif; ?>
<?php if(demo_env()): ?><section class="panel padded"><h3>Data demo</h3><p class="muted">Padam data sekolah ini sahaja dan jana semula data demo. Sekolah lain tidak terjejas.</p><form method="post" class="delete-form" data-confirm="Padam semua data <?= e($s['code']) ?> dan jana semula data demo?"><?= $hidden ?><button class="btn outline" name="action" value="p_demo_reset">Reset data demo</button></form></section><?php endif; ?>
</div>
</div>
<section class="panel danger-zone sch-danger" id="bahaya"><div class="panel-heading"><div><h3>Zon bahaya</h3><p class="muted">Tindakan di bawah memadam data dan tidak boleh dibatalkan. Buat sandaran dahulu.</p></div></div>
<div class="sch-danger-grid">
<?php if($s['status']!=='diarkib'): ?><details class="sch-dz"><summary><strong>Arkib sekolah</strong><small>Padam semua data (guru, jawatan, lantikan, akaun…); rekod sekolah &amp; sejarah bayaran kekal dengan status Diarkib. Kod sekolah tidak dibebaskan.</small></summary><form method="post" class="stack"><?= $hidden ?><input type="hidden" name="action" value="p_archive">
<label class="field">Taip kod sekolah (<?= e($s['code']) ?>)<input name="confirm_code" maxlength="7" required autocomplete="off"></label><label class="field">Kata laluan anda<input name="admin_password" type="password" required autocomplete="current-password"></label><div><button class="btn danger">Arkib sekolah</button></div></form></details><?php endif; ?>
<details class="sch-dz"><summary><strong>Padam sekolah</strong><small>Memadam sekolah dan SEMUA datanya (guru, jawatan, lantikan, akaun). Tidak boleh dibatalkan.</small></summary><form method="post" class="stack"><?= $hidden ?><input type="hidden" name="action" value="p_school_delete">
<label class="field">Taip kod sekolah (<?= e($s['code']) ?>)<input name="confirm_code" maxlength="7" required autocomplete="off"></label><label class="field">Kata laluan anda<input name="password" type="password" required autocomplete="current-password"></label><div><button class="btn danger">Padam sekolah</button></div></form></details>
</div></section><?php
}
function platform_page_log(): void {
    $rows=query('SELECT l.*,a.name admin,s.code FROM platform_log l LEFT JOIN platform_admins a ON a.id=l.admin_id LEFT JOIN schools s ON s.id=l.school_id ORDER BY l.id DESC LIMIT 200')->fetchAll(); ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Log platform</h1><p class="muted">200 tindakan terkini pentadbir platform.</p></div></div>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>Masa</th><th>Pentadbir</th><th>Tindakan</th><th>Sekolah</th><th>Butiran</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr class="<?= str_contains($r['action'],'failed')?'row-alert':'' ?>"><td><?= e(date('d/m/Y H:i:s',strtotime($r['created_at']))) ?></td><td><?= e($r['admin']??'—') ?></td><td><?= e($r['action']) ?></td><td><?= e($r['code']??'') ?></td><td><?= e($r['detail']) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php
}
function platform_page_profile(array $me): void {
    $need=platform_need_2fa() && !$me['totp_secret']; ?>
<div class="page-heading"><div><span class="eyebrow teal">AKAUN</span><h1>Profil & 2FA</h1><p class="muted"><?= e($me['email']) ?></p></div></div>
<?php if($need) p_notice('2FA diwajibkan untuk pentadbir platform. Aktifkan 2FA sebelum mengurus sekolah.','error'); ?>
<?php if(!$me['totp_secret']): $_SESSION['p_enroll']??=base32_encode(random_bytes(20)); ?><section class="panel spaced"><div class="panel-heading"><div><h3>Aktifkan 2FA</h3><p class="muted">Imbas kod QR dengan aplikasi pengesah, kemudian masukkan kod 6 digit.</p></div></div><div class="padded"><?= qr_svg(totp_uri($_SESSION['p_enroll'],$me['email'])) ?><p class="muted">Kunci manual: <code><?= e(chunk_split($_SESSION['p_enroll'],4,' ')) ?></code></p></div><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_enroll"><label class="field">Kod pengesahan<input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required></label><div class="button-group"><button class="btn primary">Aktifkan 2FA</button></div></form></section>
<?php else: ?><section class="panel spaced"><div class="padded"><span class="badge green">2FA aktif</span></div></section><?php endif; ?>
<section class="panel spaced"><div class="panel-heading"><div><h3>Tukar kata laluan</h3></div></div><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_password"><label class="field">Kata laluan semasa<input name="current" type="password" required autocomplete="current-password"></label><label class="field">Kata laluan baharu (12–128)<input name="new" type="password" minlength="12" maxlength="128" required autocomplete="new-password"></label><label class="field">Sahkan<input name="confirm" type="password" required autocomplete="new-password"></label><div class="button-group"><button class="btn primary">Simpan</button></div></form></section><?php
}

function platform_page_settings(): void {
    $s=pset_all(); $err=(string)($GLOBALS['P_ERROR']??'');
    if($err!=='' && input('action')==='p_settings_save'){ // simpan gagal: kekalkan apa yang ditaip (kecuali kata laluan) supaya tidak perlu diisi semula
        foreach($_POST as $k=>$val) if(is_string($val) && isset($s[$k]) && $k!=='smtp_pass') $s[$k]=$val;
        foreach(['free_aktif','daftar_buka','percubaan_cetak','percubaan_pdf','percubaan_tanda_air'] as $k) $s[$k]=input($k)==='1'?'1':'0';
        $s['pro_ciri']=implode(',',array_filter((array)($_POST['pro_ciri']??[]),'is_string'));
        $h=str_replace(',','',trim(input('harga_pro'))); if(preg_match('/^\d{1,6}(\.\d{1,2})?$/',$h)) $s['harga_pro_sen']=(string)(int)round((float)$h*100);
    }
    $v=fn($k)=>e($s[$k]??''); $chk=fn($k,$val)=>($s[$k]??'')===$val?' checked':''; $sel=fn($k,$val)=>($s[$k]??'')===$val?' selected':'';
    ?><div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Tetapan Platform</h1><p class="muted">Langganan, pakej Free, pendaftaran sekolah dan e-mel platform.</p></div></div>
<form method="post" class="settings-form" id="platform-settings"><?= csrf_field() ?><input type="hidden" name="action" value="p_settings_save">
<section class="panel padded spaced"><h3>Umum</h3><div class="form-grid">
<label class="field">Nama platform<input name="nama_platform" value="<?= $v('nama_platform') ?>" maxlength="80" required></label>
<label class="field">E-mel notis platform<input name="emel_notis" type="email" value="<?= $v('emel_notis') ?>" maxlength="190" placeholder="Untuk notis pendaftaran sekolah baharu"></label></div></section>
<section class="panel padded spaced"><h3>Langganan Pro</h3><div class="form-grid">
<label class="field">Harga Pro setahun (RM)<input name="harga_pro" value="<?= e(number_format((int)$s['harga_pro_sen']/100,2,'.','')) ?>" inputmode="decimal" required></label>
<label class="field">Tempoh percubaan (hari)<input name="percubaan_hari" value="<?= $v('percubaan_hari') ?>" inputmode="numeric" required><small class="muted">Sekolah baharu mendapat Pro percuma selama tempoh ini.</small></label>
<fieldset class="full pro-ciri"><legend>Surat lantikan semasa percubaan</legend><p class="muted small-note">Sekolah percubaan = Pro tanpa sebarang bayaran atau tajaan berjaya. Boleh diganti bagi setiap sekolah di halaman sekolah (Langganan).</p>
<label class="check"><input type="checkbox" name="percubaan_cetak" value="1"<?= $chk('percubaan_cetak','1') ?>> <span><strong>Benarkan cetak surat</strong><small>Jika dimatikan, butang Cetak disembunyikan dan cetakan pelayar memaparkan notis.</small></span></label>
<label class="check"><input type="checkbox" name="percubaan_pdf" value="1"<?= $chk('percubaan_pdf','1') ?>> <span><strong>Benarkan muat turun PDF</strong><small>Surat individu &amp; semua surat, termasuk Tugas Saya guru.</small></span></label>
<label class="check"><input type="checkbox" name="percubaan_tanda_air" value="1"<?= $chk('percubaan_tanda_air','1') ?>> <span><strong>Tanda air "PERCUBAAN"</strong><small>Pada surat di skrin, cetakan dan PDF.</small></span></label>
<label class="field">Had muat turun PDF (0 = tiada had)<input name="percubaan_pdf_had" value="<?= $v('percubaan_pdf_had') ?>" inputmode="numeric" required><small class="muted">Setiap PDF yang dijana dikira satu, sepanjang tempoh percubaan.</small></label></fieldset></div></section>
<section class="panel padded spaced"><h3>Pakej Free</h3><p class="muted">Apabila Pro tamat, sekolah jatuh ke Free (data kekal) dengan had berikut. Jika dimatikan, laman sekolah ditutup selepas tamat dan data dipadam selepas tempoh di bawah.</p><div class="form-grid">
<label class="check full"><input type="checkbox" name="free_aktif" value="1"<?= $chk('free_aktif','1') ?>> Tawarkan pakej Free</label>
<label class="field">Had bilangan guru<input name="free_guru" value="<?= $v('free_guru') ?>" inputmode="numeric" required></label>
<label class="field">Had jawatan<input name="free_jawatan" value="<?= $v('free_jawatan') ?>" inputmode="numeric" required></label>
<label class="field">Had pengguna aktif (akaun log masuk)<input name="free_pengguna" value="<?= $v('free_pengguna') ?>" inputmode="numeric" required></label>
<fieldset class="full pro-ciri"><legend>Ciri Pro sahaja (dikunci bagi sekolah Free)</legend><?php $pc=explode(',',(string)($s['pro_ciri']??'')); foreach(PRO_FEATURES as $k=>[$l,$d]): ?><label class="check"><input type="checkbox" name="pro_ciri[]" value="<?= e($k) ?>"<?= in_array($k,$pc,true)?' checked':'' ?>> <span><strong><?= e($l) ?></strong> <small class="muted"><?= e($d) ?></small></span></label><?php endforeach; ?><small class="muted">Sekolah Pro, percubaan dan tajaan sentiasa mendapat semua ciri. Data sedia ada tidak dipadam apabila ciri dikunci.</small></fieldset>
<label class="field">Free: padam data jika tiada log masuk (bulan)<input name="free_tidak_aktif_bulan" value="<?= $v('free_tidak_aktif_bulan') ?>" inputmode="numeric" required></label>
<label class="field">Padam data selepas tamat / tidak aktif (hari, 0 = tidak dipadam)<input name="padam_hari" value="<?= $v('padam_hari') ?>" inputmode="numeric" required><small class="muted">Amaran e-mel dihantar 30 &amp; 7 hari sebelum pemadaman.</small></label></div></section>
<section class="panel padded spaced"><h3>Pendaftaran sekolah awam (/daftar)</h3><div class="form-grid">
<label class="check full"><input type="checkbox" name="daftar_buka" value="1"<?= $chk('daftar_buka','1') ?>> Buka pendaftaran sekolah awam</label>
<label class="field">Kelulusan selepas e-mel disahkan<select name="daftar_kelulusan"><option value="manual"<?= $sel('daftar_kelulusan','manual') ?>>Manual oleh pentadbir platform</option><option value="auto"<?= $sel('daftar_kelulusan','auto') ?>>Automatik</option></select></label>
<label class="field">Had pendaftaran sehari<input name="daftar_had_harian" value="<?= $v('daftar_had_harian') ?>" inputmode="numeric" required></label>
<label class="field full">Domain e-mel dibenarkan (kosong = semua)<input name="daftar_domain" value="<?= $v('daftar_domain') ?>" placeholder="cth. moe-dl.edu.my, moe.gov.my"></label></div></section>
<section class="panel padded spaced"><h3>Terma &amp; Polisi</h3><p class="muted">Kosongkan untuk teks lalai (dijana daripada tetapan semasa). Format: <code>## </code> tajuk, <code>- </code> senarai. Semak dengan penasihat undang-undang. <a class="text-link" href="/terma" target="_blank">Lihat Terma</a> · <a class="text-link" href="/polisi" target="_blank">Lihat Polisi</a></p><div class="form-grid">
<label class="field full">Terma &amp; Syarat<textarea name="terma_teks" rows="8" maxlength="20000" placeholder="(teks lalai)"><?= $v('terma_teks') ?></textarea></label>
<label class="field full">Polisi Perkhidmatan &amp; Privasi<textarea name="polisi_teks" rows="8" maxlength="20000" placeholder="(teks lalai)"><?= $v('polisi_teks') ?></textarea></label></div></section>
<section class="panel padded spaced"><h3>E-mel platform</h3><p class="muted">Digunakan untuk e-mel platform dan oleh semua sekolah yang memilih "E-mel platform" (sekolah hanya boleh memilih e-mel platform atau SMTP luar sendiri).</p><div class="form-grid">
<label class="field">Kaedah<select name="mail_method" data-mail-method><option value="mail"<?= $sel('mail_method','mail') ?>>PHP mail() (pelayan)</option><option value="smtp"<?= $sel('mail_method','smtp') ?>>SMTP</option><?php if(env('APP_ENV')==='local'): ?><option value="log"<?= $sel('mail_method','log') ?>>Log (ujian setempat)</option><?php endif; ?></select></label>
<label class="field">E-mel pengirim<input name="mail_from" type="email" value="<?= $v('mail_from') ?>" maxlength="190"></label>
<label class="field">Nama pengirim<input name="mail_from_name" value="<?= $v('mail_from_name') ?>" maxlength="80" placeholder="<?= e(platform_name()) ?>"></label>
<div class="full smtp-fields form-grid"><?= smtp_preset_field($s['smtp_preset']??'custom') ?>
<label class="field">Hos SMTP<input name="smtp_host" value="<?= $v('smtp_host') ?>" placeholder="smtp-relay.brevo.com"></label>
<label class="field">Port SMTP<input name="smtp_port" value="<?= $v('smtp_port') ?>" inputmode="numeric"></label>
<label class="field">Penyulitan<select name="smtp_secure"><option value="tls"<?= $sel('smtp_secure','tls') ?>>STARTTLS (587)</option><option value="ssl"<?= $sel('smtp_secure','ssl') ?>>SSL (465)</option><option value="none"<?= $sel('smtp_secure','none') ?>>Tiada (localhost sahaja)</option></select></label>
<label class="field">Pengguna SMTP<input name="smtp_user" value="<?= $v('smtp_user') ?>" autocomplete="off"></label>
<label class="field">Kata laluan SMTP<input name="smtp_pass" type="password" autocomplete="new-password" placeholder="<?= $s['smtp_pass']!==''?'•••••••• (disimpan; kosongkan untuk kekal)':'' ?>"></label></div></div></section>
<?php if($err!==''): ?><div class="notice error form-foot" role="alert"><span><strong>Tidak disimpan:</strong> <?= e($err) ?></span></div><?php endif; ?><div class="button-group form-foot"><button class="btn primary">Simpan tetapan</button></div></form>
<form method="post" class="padded"><?= csrf_field() ?><button class="btn outline" name="action" value="p_mail_test">Hantar e-mel ujian kepada saya</button></form>
<section class="panel padded spaced" id="logo"><h3>Logo &amp; ikon</h3><p class="muted">Logo menggantikan ikon "k." di header dan footer halaman utama, direktori, halaman awam, panel platform dan halaman log masuk platform, serta digunakan sebagai ikon tab pelayar (favicon). Teks di sebelah logo ialah <strong>Nama platform</strong> (di atas). PNG lut sinar berbentuk segi empat disyorkan; maks 2 MB, dikecilkan ke 512 px.</p>
<div class="plat-logo-prev"><span class="plat-logo-box"><?= plat_brand_mark() ?></span><span><?= plat_brand_name('strong') ?><small class="muted"><?= e(plat_tagline()) ?></small></span></div>
<form method="post" enctype="multipart/form-data" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_rupa_logo_save">
<label class="field">Logo baharu<input type="file" name="lp_logo" accept="image/png,image/jpeg,image/webp"></label>
<label class="field">Tagline (baris kecil di bawah nama)<input name="lp_tagline" maxlength="60" value="<?= e(pset('lp_tagline')) ?>" placeholder="Agihan tugas guru"></label>
<div class="button-group full"><button class="btn primary">Simpan logo &amp; tagline</button></div></form>
<?php if(plat_logo_has()): ?><form method="post" class="button-group spaced" data-confirm="Buang logo dan kembali ke ikon lalai?"><?= csrf_field() ?><button class="btn outline" name="action" value="p_rupa_logo_remove">Buang logo</button></form><?php endif; ?></section><?php
}

/** Lupa / reset / kecemasan (tanpa log masuk). Reset kecemasan sentiasa 404 kecuali berjaya (tiada oracle fail). */
function platform_public_page(string $p): never {
    $error=''; $notice='';
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        try {
            if($p==='lupa') $notice=platform_forgot();
            elseif($p==='reset'){ platform_reset(input('t','get')); flash('Kata laluan dikemas kini. Log masuk dengan kata laluan baharu.'); redirect('/platform/'); }
            elseif($p==='kecemasan'){ if(platform_emergency()){ http_response_code(200); $notice='Reset kecemasan berjaya. Fail kod telah dipadam; 2FA dikosongkan. Log masuk dan daftar semula 2FA.'; } else $error='Reset tidak dapat dilakukan. Semak kod rahsia dan arahan dalam dokumentasi, kemudian cuba lagi.'; }
        } catch(DomainException $ex){ $error=$ex->getMessage(); }
    }
    if($p==='kecemasan' && $notice==='') http_response_code(404);
    $form=match($p){
        'lupa'=>function() use($notice){ if($notice!=='') p_notice($notice,'success'); ?><form method="post" class="stack"><?= csrf_field() ?><label class="field">E-mel akaun platform<input name="email" type="email" maxlength="190" required autocomplete="username"></label><button class="btn primary">Hantar pautan</button></form><?php },
        'reset'=>function(){ if(!platform_reset_token(input('t','get'))){ p_notice('Pautan tidak sah atau telah tamat tempoh.','error'); return; } ?><form method="post" class="stack"><?= csrf_field() ?><label class="field">Kata laluan baharu (12–128)<input name="password" type="password" minlength="12" maxlength="128" required autocomplete="new-password"></label><label class="field">Sahkan<input name="password_confirm" type="password" required autocomplete="new-password"></label><button class="btn primary">Simpan kata laluan</button></form><?php },
        default=>function() use($notice){ if($notice!==''){ p_notice($notice,'success'); return; } ?><form method="post" class="stack"><?= csrf_field() ?><label class="field">E-mel akaun platform<input name="email" type="email" maxlength="190" required></label><label class="field">Kod rahsia<input name="kod" type="password" required autocomplete="off"></label><label class="field">Kata laluan baharu (12–128)<input name="password" type="password" minlength="12" maxlength="128" required autocomplete="new-password"></label><label class="field">Sahkan<input name="password_confirm" type="password" required autocomplete="new-password"></label><button class="btn primary">Set semula</button></form><?php },
    };
    p_auth(['lupa'=>'Lupa kata laluan.','reset'=>'Kata laluan baharu.','kecemasan'=>'Reset kecemasan.'][$p],['lupa'=>'Pautan set semula dihantar ke e-mel akaun pentadbir platform.','reset'=>'Pilih kata laluan baharu. Semua sesi lama akan ditamatkan.','kecemasan'=>''][$p],$form,$error);
}

function platform_page_info(): void {
    $ok=fn(bool $b)=>$b?'<span class="badge green">OK</span>':'<span class="badge warn">Semak</span>';
    $op=function_exists('opcache_get_status')?@opcache_get_status(false):false; $free=@disk_free_space(dirname(__DIR__)); $last=(string)query('SELECT value FROM platform_settings WHERE name="run_subscription"')->fetchColumn();
    $rows=[
        ['PHP 8.2 atau lebih baharu',version_compare(PHP_VERSION,'8.2.0','>='),PHP_VERSION],
        ['Sambungan (pdo_mysql, sodium, mbstring, gd, zip, curl)',!array_filter(['pdo_mysql','sodium','mbstring','gd','zip','curl'],fn($x)=>!extension_loaded($x)),implode(', ',array_filter(['pdo_mysql','sodium','mbstring','gd','zip','curl'],fn($x)=>!extension_loaded($x)))?:'Semua ada'],
        ['OPcache',(bool)($op['opcache_enabled']??false),($op['opcache_enabled']??false)?'Aktif':'Tidak aktif — disyorkan untuk prestasi'],
        ['display_errors dimatikan',!filter_var(ini_get('display_errors'),FILTER_VALIDATE_BOOLEAN),(string)ini_get('display_errors')],
        ['expose_php dimatikan',!filter_var(ini_get('expose_php'),FILTER_VALIDATE_BOOLEAN),(string)ini_get('expose_php')],
        ['HTTPS',request_is_https(),request_is_https()?'Aktif':'Tidak aktif'],
        ['APP_ENV=production',env('APP_ENV')==='production',env('APP_ENV')],
        ['PLATFORM_DOMAIN ditetapkan',platform_domain()!=='',platform_domain()?:'—'],
        ['E-mel platform',pset('mail_from')!=='',pset('mail_from')?:'Belum ditetapkan (Tetapan Platform)'],
        ['Kerja harian (cron / malas)',$last===date('Y-m-d'),$last?:'Belum pernah'],
        ['Folder storage/ boleh ditulis',is_writable(dirname(__DIR__).'/storage'),''],
        ['Ruang cakera kosong',$free===false || $free>1024**3,$free!==false?number_format($free/1024**3,1).' GB':'—'],
    ]; ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Maklumat Sistem</h1><p class="muted">Senarai semak pelayan. Kerja harian: <code>php <?= e(dirname(__DIR__)) ?>/bin/cron.php</code> (cth. 0 4 * * *).</p></div></div>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>Perkara</th><th>Status</th><th>Butiran</th></tr></thead><tbody>
<?php foreach($rows as [$l,$b,$d]): ?><tr><td><?= e($l) ?></td><td><?= $ok($b) ?></td><td><?= e($d) ?></td></tr><?php endforeach; ?>
<tr><td>Versi aplikasi / skema</td><td></td><td><?= e(APP_VERSION.' · '.APP_SCHEMA) ?></td></tr><tr><td>Pangkalan data</td><td></td><td><?= e((string)query('SELECT VERSION()')->fetchColumn()) ?></td></tr>
<tr><td>Sekolah (aktif / jumlah)</td><td></td><td><?= (int)query('SELECT COUNT(*) FROM schools WHERE status="aktif"')->fetchColumn() ?> / <?= (int)query('SELECT COUNT(*) FROM schools')->fetchColumn() ?></td></tr></tbody></table></div></section>
<?php $cve=php_cve_checks(); $bad=php_cve_vulnerable(); ?><section class="panel spaced<?= $bad?' cve-bad':'' ?>" id="cve-php"><div class="panel-heading"><div><h3>Keselamatan PHP · <?= e(PHP_CVE_IDS) ?></h3><p class="muted">PHP2Shell: pelaksanaan kod jauh tanpa log masuk (CVSS 9.2), dibaiki 21 Okt 2026 dalam PHP 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12. Versi di bawah ialah PHP yang menjalankan laman ini (bukan <code>php -v</code> CLI). Hanya kemas kini PHP membaiki sepenuhnya; tetapan lain mengurangkan risiko.</p></div><?= $bad?'<span class="badge danger">Terdedah</span>':'<span class="badge green">Dibaiki</span>' ?></div>
<div class="table-wrap"><table><thead><tr><th>Perkara</th><th>Status</th><th>Butiran</th></tr></thead><tbody><?php foreach($cve as [$l,$s,$d]): ?><tr class="<?= $s==='bad'?'row-alert':'' ?>"><td><?= e($l) ?></td><td><?= $s==='ok'?'<span class="badge green">OK</span>':($s==='bad'?'<span class="badge danger">Terdedah</span>':'<span class="badge warn">Disyorkan</span>') ?></td><td><?= e($d) ?></td></tr><?php endforeach; ?></tbody></table></div>
<p class="padded muted cve-steps">Selepas naik taraf: mulakan semula <strong>semua</strong> pool PHP-FPM, tekan "Kosongkan cache OPcache" di bawah, kemudian jalankan <a class="text-link" href="/platform/?p=sistem">Integriti &amp; Status → Semak sekarang</a> untuk mengesan fail asing yang mungkin ditanam sebelum kemas kini.</p></section>
<?php $dbc=db_cve_checks(); $dbBad=db_cve_vulnerable(); $dbWarn=!$dbBad && in_array('bad',array_column($dbc,1),true); ?><section class="panel spaced<?= $dbBad||$dbWarn?' cve-bad':'' ?>" id="cve-db"><div class="panel-heading"><div><h3>Keselamatan pangkalan data · <?= e(DB_CVE_IDS) ?></h3><p class="muted">MariaDB 16 Okt 2026: limpahan memori, use-after-free dan capaian fail (CVSS sehingga 9.9) oleh pengguna DB yang telah log masuk, dibaiki dalam 10.6.28 / 10.11.19 / 11.4.13 / 11.8.9 / 12.3.3 / 13.0.2. Risiko utama jika kelayakan DB aplikasi bocor; hadkan keistimewaan pengguna DB untuk mengurangkan kesan. MySQL tidak terjejas oleh senarai ini.</p></div><?= $dbBad?'<span class="badge danger">Terdedah</span>':($dbWarn?'<span class="badge danger">Perlu tindakan</span>':'<span class="badge green">Dibaiki</span>') ?></div>
<div class="table-wrap"><table><thead><tr><th>Perkara</th><th>Status</th><th>Butiran</th></tr></thead><tbody><?php foreach($dbc as [$l,$s,$d]): ?><tr class="<?= $s==='bad'?'row-alert':'' ?>"><td><?= e($l) ?></td><td><?= $s==='ok'?'<span class="badge green">OK</span>':($s==='bad'?'<span class="badge danger">Terdedah</span>':'<span class="badge warn">Disyorkan</span>') ?></td><td><?= e($d) ?></td></tr><?php endforeach; ?></tbody></table></div>
<p class="padded muted cve-steps">Selepas naik taraf MariaDB: jalankan <code>mariadb-upgrade</code>, mulakan semula perkhidmatan pangkalan data, kemudian muat semula halaman ini untuk mengesahkan versi baharu.</p></section>
<?php $opOn=(bool)($op['opcache_enabled']??false); $ms=$op['memory_usage']??[]; $st=$op['opcache_statistics']??[]; ?>
<section class="panel padded spaced"><h3>Cache OPcache</h3>
<p class="muted">OPcache menyimpan kod PHP yang telah dikompil dalam memori. Selepas memuat naik fail baharu (zip kemas kini), kosongkan cache supaya pelayan terus menggunakan kod terkini — tanpa perlu menunggu atau memulakan semula PHP.</p>
<?php if($opOn): ?><p class="muted">Skrip dalam cache: <strong><?= (int)($st['num_cached_scripts']??0) ?></strong> · Memori digunakan: <strong><?= e(number_format(((int)($ms['used_memory']??0))/1048576,1)) ?> MB</strong><?= isset($ms['free_memory'])?' / bebas '.e(number_format(((int)$ms['free_memory'])/1048576,1)).' MB':'' ?> · Kadar hit: <strong><?= e(number_format((float)($st['opcache_hit_rate']??0),1)) ?>%</strong><?= !empty($st['last_restart_time'])?' · Kali terakhir dikosongkan: '.e(date('d/m/Y H:i',(int)$st['last_restart_time'])):'' ?></p>
<form method="post" data-confirm="Kosongkan cache OPcache sekarang? Halaman mungkin sedikit perlahan seketika semasa kod dikompil semula."><?= csrf_field() ?><button class="btn primary" name="action" value="p_opcache_reset">Kosongkan cache OPcache</button></form>
<?php else: ?><p class="notice info">OPcache tidak aktif pada pelayan ini, jadi tiada cache untuk dikosongkan. Hidupkan OPcache di cPanel → Select PHP Version → Extensions untuk prestasi yang lebih baik.</p><?php endif; ?></section><?php
}

/** Titik masuk konteks platform (dipanggil oleh public_html/index.php apabila tiada kod sekolah). */
function platform_main(): never {
    header('X-Robots-Tag: noindex');
    $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');
    if(input('page','get')==='theme-css') serve_theme_css();
    if(input('page','get')==='lp-css') lp_css_serve(); // rupa halaman awam (Rupa Halaman Utama)
    if(input('page','get')==='lp-hero') lp_hero_serve();
    if(input('page','get')==='lp-logo') lp_logo_serve();
    if(input('page','get')==='sch-logo') directory_logo_serve(); // logo sekolah untuk direktori
    if(input('page','get')==='captcha') serve_captcha(input('id','get'),isset($_GET['baru'])); // CAPTCHA pendaftaran sekolah
    if(input('p','get')==='sandaran_oauth' && ($path==='/platform/' || $path==='/platform/index.php')) bk_oauth_callback(); // tanpa sesi (NO_SESSION, lihat index.php)
    if($path==='/direktori' || $path==='/direktori/') platform_search_directory();
    if($path==='/' || $path==='/index.php') platform_directory();
    if($path==='/terma' || $path==='/polisi') legal_page(ltrim($path,'/'));
    if($path==='/faq' || $path==='/faq/') faq_page();
    if($path==='/daftar' || $path==='/daftar/') registration_page();
    if($path==='/daftar/sah') registration_verify_page();
    if($path==='/hos-semak') host_check_reply(); // ujian subdomain (Subdomain Sekolah)
    if($path==='/bayar-callback') pay_callback();
    if($path==='/.well-known/security.txt' || $path==='/security.txt') security_txt();
    if($path==='/lapor-keselamatan') security_report_page();
    if(str_starts_with($path,'/platform') && !ip_platform_allowed()){ platform_log('platform_ip_denied',null,client_ip()); error_page(403,'Panel platform hanya boleh diakses dari alamat IP yang dibenarkan.'); } // gerbang bayaran (pelayan-ke-pelayan, tanpa sesi; tandatangan + API)
    if($path==='/platform'){ redirect('/platform/'); }
    if($path!=='/platform/' && $path!=='/platform/index.php') error_page(404);
    $error='';
    // Halaman tanpa log masuk: lupa / reset kata laluan & reset kecemasan.
    $pp=input('p','get');
    if(in_array($pp,['lupa','reset','kecemasan'],true) && !platform_user()) platform_public_page($pp);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        try { platform_action((string)($_POST['action']??'')); }
        catch(DomainException $ex){ if(db()->inTransaction()) db()->rollBack(); $error=$ex->getMessage(); }
    }
    $me=platform_user(); $p=input('p','get');
    if($me && !account_ip_ok($me['allowed_ips'])){ platform_log('platform_ip_denied',null,client_ip()); $_SESSION=[]; error_page(403,'Akaun anda tidak dibenarkan dari alamat IP ini.'); }
    if(!$me && ($_SERVER['REQUEST_METHOD']??'')==='GET' && preg_match('/^[a-z]+$/D',$p) && !in_array($p,['verify','lupa','reset','kecemasan'],true)) $_SESSION['p_next']='/platform/?p='.$p.(($id=(int)input('id','get'))>0?'&id='.$id:''); // kembali ke sini selepas log masuk
    if(!$me){
        if(!empty($_SESSION['pending_pid']) && $p==='verify') p_auth('Sahkan identiti anda.','Masukkan kod 6 digit daripada aplikasi pengesah.',function(){ ?><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="p_verify"><label class="field">Kod pengesahan<input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required autofocus></label><button class="btn primary">Sahkan →</button></form><?php },$error);
        p_auth('Log masuk platform.','Untuk pentadbir platform sahaja — guru log masuk melalui laman sekolah masing-masing.',function(){ ?><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="p_login"><label class="field">Alamat e-mel<input name="email" type="email" maxlength="190" autocomplete="username" required></label><label class="field">Kata laluan<input name="password" type="password" maxlength="128" autocomplete="current-password" required></label><?= platform_login_captcha()?captcha_field('plogin'):'' ?><button class="btn primary">Log masuk →</button></form><p class="muted"><a class="text-link" href="/platform/?p=lupa">Lupa kata laluan?</a></p><?php },$error);
    }
    if(platform_need_2fa() && !$me['totp_secret']) $p='profil';
    else { integrity_lazy(); backup_lazy(); after_response(fn()=>subscription_daily()); } // kerja berkala dicetus oleh pentadbir platform (sekali sehari)
    require_once __DIR__.'/admin_views.php'; require_once __DIR__.'/icons.php';
    if($p==='sandaran' && input('sambung','get')==='1'){ if(demo_env()){ flash('Mod demo: sambungan Google Drive dimatikan.'); redirect('/platform/?p=sandaran'); } bk_oauth_start(); }
    if($p==='sistem') p_shell($me,'sistem','system_page',$error);
    if($p==='tetapan') p_shell($me,'tetapan','platform_page_settings',$error);
    if($p==='pentadbir') p_shell($me,'pentadbir',fn()=>platform_page_admins($me),$error);
    if($p==='sokongan') p_shell($me,'sokongan','platform_page_support',$error);
    if($p==='bayaran' && (int)input('bukti','get')>0){ require_platform_admin(); $bp=payment_get((int)input('bukti','get')); if(!$bp) error_page(404); platform_log('payment_proof_viewed',$bp['school_id']?(int)$bp['school_id']:null,'#'.$bp['id']); proof_serve($bp); }
    if($p==='bayaran' && (int)input('resit','get')>0){ $bp=payment_get((int)input('resit','get')); if(!$bp) error_page(404); pay_receipt_page($bp,true); }
    if($p==='bayaran') p_shell($me,'bayaran','platform_page_payments',$error);
    if($p==='domain') p_shell($me,'domain','platform_page_domain',$error);
    if($p==='rupa') p_shell($me,'rupa','platform_page_appearance',$error);
    if($p==='faq') p_shell($me,'faq','platform_page_faq',$error);
    if($p==='addon') p_shell($me,'addon','platform_page_addons',$error);
    if($p==='kemaskini'){ require_once __DIR__.'/updater.php'; p_shell($me,'kemaskini',function(){ ?><div class="page-heading"><div><span class="eyebrow teal">SISTEM</span><h1>Kemas Kini Sistem</h1><p class="muted">Kemas kini daripada GitHub: setiap push ke <code>main</code> dibina oleh GitHub Actions dan dipasang di sini secara automatik (webhook), atau tekan butang di bawah. Fail lama disandarkan dan boleh di-rollback.</p></div></div><?= upd_panel_html((request_is_https()?'https':'http').'://'.($_SERVER['HTTP_HOST']??'').'/kemaskini-hook') ?><?php },$error); }
    if($p==='keselamatan') p_shell($me,'keselamatan',fn()=>platform_page_security($me),$error);
    if($p==='ipsekat') p_shell($me,'ipsekat','platform_page_ipblocks',$error);
    if($p==='audit') p_shell($me,'audit','platform_page_audit',$error);
    if($p==='laporan') p_shell($me,'laporan','platform_page_reports',$error);
    if($p==='kaedah') p_shell($me,'kaedah','platform_page_paymethods',$error);
    if($p==='diskaun') p_shell($me,'diskaun','platform_page_discounts',$error);
    if($p==='tajaan') p_shell($me,'tajaan','platform_page_sponsors',$error);
    if($p==='persetujuan') p_shell($me,'persetujuan','platform_page_consents',$error);
    if($p==='maklumat') p_shell($me,'maklumat','platform_page_info',$error);
    if($p==='sandaran') p_shell($me,'sandaran','sandaran_page',$error); // sandaran_page() memaparkan notis mod demo sendiri
    if($p==='sekolah'){ $s=school_by_id((int)input('id','get')); if(!$s) error_page(404,'Sekolah tidak dijumpai.'); p_shell($me,'',fn()=>platform_page_school($s),$error,$s['name']); }
    if($p==='baru') p_shell($me,'baru','platform_page_new',$error);
    if($p==='log') p_shell($me,'log','platform_page_log',$error);
    if($p==='profil') p_shell($me,'profil',fn()=>platform_page_profile($me),$error);
    p_shell($me,'','platform_page_schools',$error);
}

/** Panel "Surat semasa percubaan" di halaman sekolah: ikut tetapan platform atau tetapan khusus sekolah, kiraan PDF & set semula. */
function platform_trial_letter_panel(array $s,string $hidden): void {
    [$o,$used]=trial_letter_school($s); $g=trial_letter_global(); $r=$o??$g; $trial=school_on_trial($s);
    $desc=fn(array $x)=>implode(' · ',[$x['cetak']?'Cetak dibenarkan':'Cetak dimatikan',!$x['pdf']?'PDF dimatikan':($x['had']?'PDF had '.$x['had']:'PDF tanpa had'),$x['tanda']?'tanda air':'tiada tanda air']); ?>
<div class="sch-trial" id="surat-percubaan"><h4>Surat semasa percubaan <?= $trial?'<span class="badge warn">Sekolah percubaan</span>':'<span class="badge">Tidak berkuat kuasa</span>' ?></h4>
<p class="muted small-note"><?= $trial?'Berkuat kuasa sekarang':'Sekolah ini telah membayar / ditaja atau bukan Pro — tiada sekatan; tetapan di bawah hanya berkuat kuasa semasa percubaan' ?>. Muat turun PDF digunakan: <strong><?= (int)$used ?><?= $r['pdf'] && $r['had']?' / '.(int)$r['had']:'' ?></strong>.</p>
<form method="post" class="form-grid"><?= $hidden ?><input type="hidden" name="action" value="p_trial_letter">
<label class="field full">Tetapan<select name="mode"><option value="global"<?= $o?'':' selected' ?>>Ikut tetapan platform (<?= e($desc($g)) ?>)</option><option value="sekolah"<?= $o?' selected':'' ?>>Khusus untuk sekolah ini</option></select></label>
<label class="check"><input type="checkbox" name="cetak" value="1"<?= $r['cetak']?' checked':'' ?>> Benarkan cetak</label><label class="check"><input type="checkbox" name="pdf" value="1"<?= $r['pdf']?' checked':'' ?>> Benarkan PDF</label>
<label class="check"><input type="checkbox" name="tanda" value="1"<?= $r['tanda']?' checked':'' ?>> Tanda air "PERCUBAAN"</label><label class="field">Had PDF (0 = tiada had)<input name="had" value="<?= (int)$r['had'] ?>" inputmode="numeric"></label>
<p class="full muted small-note">Kotak di atas hanya digunakan jika "Khusus untuk sekolah ini" dipilih.</p>
<div class="full button-group"><button class="btn primary" type="submit">Simpan</button><?php if($used): ?><button class="btn outline" name="mode" value="reset" data-confirm-simple="Set semula kiraan muat turun PDF kepada 0?">Set semula kiraan (<?= (int)$used ?>)</button><?php endif; ?></div></form></div>
<?php }
