<?php
declare(strict_types=1);
/*
 * Konteks sekolah (penyewa) — UrusTugas (rangka koku-multi).
 * Satu pemasangan, satu pangkalan data; setiap jadual data sekolah ada `school_id` dan setiap query ditapis dengan sid().
 *
 * Sekolah dikenal pasti melalui kod KPM (3 huruf + 4 digit) dalam:
 *   - subdomain : https://cea1234.domain-induk/            (PLATFORM_DOMAIN=domain-induk)
 *   - laluan    : https://domain-induk/CEA1234/
 * Kedua-dua bentuk sentiasa berfungsi; bentuk utama ditetapkan di Panel Platform → Subdomain Sekolah (domain induk, hidup/mati,
 * Pro sahaja atau semua sekolah) — jika belum ditetapkan di panel, PLATFORM_DOMAIN & TENANT_PRIMARY dalam .env digunakan.
 * Permintaan GET dalam bentuk bukan utama dilencongkan (302). Tanpa kod = konteks platform (direktori sekolah & /platform).
 *
 * Dalam mod laluan, awalan /KOD dibuang daripada REQUEST_URI supaya aplikasi melihat '/…' seperti versi satu sekolah;
 * pautan mutlak dalam HTML ('/?page=…') diberi awalan semula oleh tenant_rewrite_html() dan redirect().
 */
const SCHOOL_CODE_RE = '/^[A-Z]{3}[0-9]{4}$/D';

function school(): ?array { return $GLOBALS['URUS_SCHOOL'] ?? null; }
/** ID sekolah semasa (0 = konteks platform). Selamat untuk disambung terus ke SQL (integer). */
function sid(): int { return (int)(school()['id'] ?? 0); }
/** Mod pengenalan permintaan semasa: 'subdomain' | 'path' | '' (platform / CLI). */
function tenant_mode(): string { return (string)($GLOBALS['URUS_TENANT_MODE'] ?? ''); }
/** Awalan laluan untuk pautan dalam aplikasi: '/CEA1234' dalam mod laluan, '' dalam mod subdomain. */
function tenant_prefix(): string { return tenant_mode()==='path' && school() ? '/'.school()['code'] : ''; }

/** Tetapkan sekolah semasa (CLI, panel platform). Cache tetapan dikosongkan. */
function tenant_use(?array $school,string $mode=''): void {
    $GLOBALS['URUS_SCHOOL']=$school; $GLOBALS['URUS_TENANT_MODE']=$school?$mode:'';
    if(function_exists('settings_all')) settings_all(true);
}
function school_find(string $code): ?array {
    $code=strtoupper(trim($code)); if(!preg_match(SCHOOL_CODE_RE,$code)) return null;
    return query('SELECT * FROM schools WHERE code=?',[$code])->fetch() ?: null;
}
function school_by_id(int $id): ?array { return query('SELECT * FROM schools WHERE id=?',[$id])->fetch() ?: null; }

/** Domain induk (tanpa skema), cth. urustugas.my atau localhost:8000. */
function domain_valid(string $d): bool { return (bool)preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*(:\d{1,5})?$/D',$d); }
/** Tetapan platform yang belum ada (sebelum dipasang) → '' supaya .env digunakan. */
function pset_soft(string $k): string { return function_exists('pset')?pset($k):''; }
function platform_domain(): string {
    $d=strtolower(trim(pset_soft('domain_induk'))); if($d!=='' && domain_valid($d)) return $d; // Panel Platform → Subdomain Sekolah
    $d=strtolower(trim(env('PLATFORM_DOMAIN',''))); return domain_valid($d)?$d:'';
}
/** Subdomain dihidupkan? Tetapan panel mengatasi TENANT_PRIMARY dalam .env. */
function subdomain_on(): bool {
    $p=pset_soft('subdomain_aktif'); $on=$p!==''?$p==='1':env('TENANT_PRIMARY','path')==='subdomain';
    return $on && platform_domain()!=='';
}
/** 'semua' (lalai) atau 'pro' — subdomain hanya untuk sekolah Pro (Free kekal /KOD/). */
function subdomain_who(): string { return pset_soft('subdomain_siapa')==='pro'?'pro':'semua'; }
/** Bentuk URL utama bagi sekolah ini: 'subdomain' | 'path'. */
function tenant_primary(?string $code=null): string {
    if(!subdomain_on()) return 'path';
    if(subdomain_who()==='semua' || $code===null) return 'subdomain';
    static $pro=[]; $code=strtoupper($code);
    if(!isset($pro[$code])){ $s=preg_match(SCHOOL_CODE_RE,$code)?(query('SELECT status,expires_on FROM schools WHERE code=?',[$code])->fetch()?:null):null; $pro[$code]=$s && $s['status']==='aktif' && !empty($s['expires_on']) && $s['expires_on']>=date('Y-m-d'); }
    return $pro[$code]?'subdomain':'path';
}
function tenant_scheme(): string { return env('SESSION_SECURE',request_is_https()?'1':'0')==='1'?'https':'http'; }
function request_host(): string { $h=strtolower((string)($_SERVER['HTTP_HOST']??'')); return preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/D',$h)?$h:''; }

/** URL mutlak halaman sekolah dalam bentuk utama (untuk e-mel, QR, lencongan). */
function school_url(string $code,string $path='/'): string {
    $path='/'.ltrim($path,'/'); $d=platform_domain();
    if(tenant_primary($code)==='subdomain') return tenant_scheme().'://'.strtolower($code).'.'.$d.$path;
    return ($d!==''?tenant_scheme().'://'.$d:'').'/'.strtoupper($code).$path;
}
/** URL mutlak (skema + hos + awalan) bagi sekolah semasa, tanpa garis condong akhir. Pautan e-mel tidak bergantung pada pengepala Host jika PLATFORM_DOMAIN ditetapkan. */
function tenant_base_url(): string {
    if(!school()) return platform_domain()!==''?tenant_scheme().'://'.platform_domain():'';
    if(platform_domain()==='') return (request_host()!==''?tenant_scheme().'://'.request_host():'').tenant_prefix();
    return rtrim(school_url(school()['code'],'/'),'/');
}
function platform_url(string $path='/'): string {
    $path='/'.ltrim($path,'/');
    return tenant_mode()==='subdomain' && platform_domain()!=='' ? tenant_scheme().'://'.platform_domain().$path : $path;
}

/**
 * Kenal pasti sekolah daripada permintaan web. Dipanggil oleh bootstrap sebelum sesi dimulakan.
 * Kod tidak sah / sekolah tidak wujud → 404; sekolah digantung → 503.
 */
function tenant_boot(): void {
    $uri=(string)($_SERVER['REQUEST_URI']??'/'); $path=(string)(parse_url($uri,PHP_URL_PATH)?:'/'); $qs=(string)(parse_url($uri,PHP_URL_QUERY)??'');
    $code=null; $mode='';
    if($path==='/hos-semak') return; // semakan subdomain (Panel Platform → Subdomain Sekolah): dijawab dalam konteks platform
    $d=platform_domain(); $host=request_host();
    if($d!=='' && preg_match('/^([a-z]{3}[0-9]{4})\.'.preg_quote($d,'/').'$/D',$host,$m)){ $code=strtoupper($m[1]); $mode='subdomain'; }
    elseif(preg_match('#^/([A-Za-z]{3}[0-9]{4})(/.*)?$#D',$path,$m)){
        $code=strtoupper($m[1]); $mode='path';
        if(($m[2]??'')===''){ header('Location: /'.$code.'/'.($qs!==''?'?'.$qs:''),true,301); exit; }
        $path=$m[2];
    }
    if($code===null) return;
    try { $school=school_find($code); }
    catch(PDOException) { return; } // belum dipasang: pemasang akan mengendalikan
    if(!$school){ tenant_use(null); error_page(404); }
    // Bentuk URL bukan utama → lencong (GET sahaja; POST tidak dilencong supaya borang tidak hilang).
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET' && $mode!==tenant_primary($code)){
        $GLOBALS['URUS_SKIP_REWRITE']=true;
        header('Location: '.school_url($code,$path.($qs!==''?'?'.$qs:'')),true,302); exit;
    }
    tenant_use($school,$mode);
    $_SERVER['REQUEST_URI']=$path.($qs!==''?'?'.$qs:'');
    if(in_array($school['status'],['emel','menunggu','diarkib'],true)){ tenant_use(null); error_page(404); } // belum diluluskan / diarkib: seolah-olah tiada
    if($school['status']==='digantung') error_page(503,'Akaun sekolah ini digantung buat sementara. Hubungi pentadbir platform.',['title'=>'Sekolah digantung','no_db'=>true]);
}

/** Tambah awalan /KOD pada pautan mutlak dalaman (href, src, action, data-*) — mod laluan sahaja. Aset dikongsi tidak diubah. */
function tenant_rewrite_html(string $html): string {
    $p=tenant_prefix(); if($p==='' || !empty($GLOBALS['URUS_SKIP_REWRITE'])) return $html;
    foreach(headers_list() as $h) if(stripos($h,'Content-Type:')===0 && stripos($h,'text/html')===false) return $html;
    return (string)preg_replace('~(\s(?:href|src|action|formaction|data-[a-z-]+)=)(["\'])/(?![/\\\\]|assets/)~i','$1$2'.$p.'/',$html);
}
/** Awalan untuk lencongan dalaman. */
function tenant_location(string $path): string {
    $p=tenant_prefix();
    return $p!=='' && str_starts_with($path,'/') && !str_starts_with($path,'//') && !str_starts_with($path,$p.'/') ? $p.$path : $path;
}

/** Jadual data sekolah, anak dahulu (padam ikut susunan ini supaya kunci asing dipatuhi). */
const SCHOOL_TABLES=['workflow_state','workflow_revisions','allocation_plans','allocation_config','position_rules','assignments','positions','subjects','api_keys','school_assets',
    'support_messages','support_tickets','staff_notice_reads','staff_notice_targets','staff_notices',
    'user_avatars','auth_tokens','recovery_codes','audit_logs','audit_heads','ip_blocks','settings','users','teachers'];
/** Padam SEMUA data sekolah semasa sahaja (sekolah lain tidak disentuh). */
function school_wipe(): void {
    if(sid()<=0) throw new LogicException('Tiada sekolah dipilih.');
    $own=!db()->inTransaction(); if($own) db()->beginTransaction();
    try { foreach(SCHOOL_TABLES as $t) query('DELETE FROM `'.$t.'` WHERE school_id=?',[sid()]); if($own) db()->commit(); }
    catch(Throwable $ex) { if($own && db()->inTransaction()) db()->rollBack(); throw $ex; }
    if(function_exists('settings_all')) settings_all(true);
}
/** Baris tunggal enjin bagi sekolah semasa (keadaan aliran = draf). Idempoten — dipanggil semasa sekolah dicipta & selepas reset demo. */
function school_engine_init(): void { if(sid()<=0) throw new LogicException('Tiada sekolah dipilih.'); query('INSERT IGNORE INTO workflow_state(school_id) VALUES (?)',[sid()]); }
/** Jalankan $fn dalam konteks sekolah lain, kemudian pulihkan konteks asal. */
function with_school(array $school,callable $fn): mixed {
    $prev=school(); $prevMode=tenant_mode(); tenant_use($school,'path');
    try { return $fn(); } finally { tenant_use($prev,$prevMode); }
}
function platform_log(string $action,?int $school=null,string $detail='',?int $admin=null): void {
    $a=$admin??($_SESSION['pid']??null);
    audit_append(0,['admin_id'=>$a,'admin_ref'=>$a,'school_id'=>$school,'school_ref'=>$school,'action'=>mb_substr($action,0,60),'detail'=>mb_substr($detail,0,255),'ip_hash'=>hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'cli',platform_key())]);
}
/* ---------- Tetapan platform (platform_settings, kunci 'cfg_*') ---------- */
const PSET_DEFAULTS=[
    'nama_platform'=>'UrusTugas','emel_notis'=>'',
    'harga_pro_sen'=>'12000','percubaan_hari'=>'30',
    'free_aktif'=>'1','free_guru'=>'30','free_jawatan'=>'60','free_pengguna'=>'10','pro_ciri'=>'auto,surat_pukal,api,sejarah','free_tidak_aktif_bulan'=>'12','padam_hari'=>'90',
    'sokongan_simpan_hari'=>'90','daftar_buka'=>'0','daftar_kelulusan'=>'manual','daftar_domain'=>'','daftar_had_harian'=>'20',
    'mail_method'=>'mail','mail_from'=>'','mail_from_name'=>'','smtp_preset'=>'custom','smtp_host'=>'','smtp_port'=>'587','smtp_secure'=>'tls','smtp_user'=>'','smtp_pass'=>'',
];
function pset_all(bool $refresh=false): array {
    static $c=null; if($c!==null && !$refresh) return $c;
    $c=PSET_DEFAULTS;
    try { foreach(query('SELECT name,value FROM platform_settings WHERE name LIKE "cfg\\_%"')->fetchAll() as $r) $c[substr($r['name'],4)]=(string)$r['value']; }
    catch(PDOException) {}
    return $c;
}
function pset(string $k,string $d=''): string { return pset_all()[$k]??$d; }
function pset_set(array $kv): void {
    foreach($kv as $k=>$v) query('INSERT INTO platform_settings(name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['cfg_'.$k,(string)$v]);
    pset_all(true);
}
function platform_name(): string { return pset('nama_platform')?:'UrusTugas'; }
/** Sulit / nyahsulit dengan kunci platform tetap (rahsia platform dibaca dari konteks sekolah juga, cth. SMTP platform). */
function pencrypt(string $v): string { $n=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return base64_encode($n.sodium_crypto_secretbox($v,$n,platform_key())); }
function pdecrypt(string $v): string { if($v==='') return ''; $b=base64_decode($v,true); if($b===false || strlen($b)<40) return ''; $p=sodium_crypto_secretbox_open(substr($b,24),substr($b,0,24),platform_key()); return $p===false?'':$p; }

/** Subkunci penyulitan setiap sekolah (HKDF): data sekolah A tidak boleh dinyahsulit / dipadankan dalam konteks sekolah B. */
/** Kunci platform tetap (= key_bytes() dalam konteks platform) — untuk data peringkat pelayan yang mesti sama dari mana-mana konteks. */
function platform_key(): string { return hash_hkdf('sha256',master_key(),32,'urustugas/platform'); }
function tenant_key(string $master): string {
    return hash_hkdf('sha256',$master,32,sid()>0?'urustugas/school/'.sid():'urustugas/platform');
}
