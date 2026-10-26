<?php
declare(strict_types=1);
/* Mod demo (DEMO_MODE=1 dalam .env) — untuk diberi kepada orang lain menguji sistem.
 *  - 4 akaun kongsi (pentadbir, PK, GB/Pengetua, guru) dengan kata laluan tetap dipaparkan di halaman log masuk; 2FA dimatikan.
 *  - Semua data adalah rekaan dan dijana semula setiap hari (permintaan pertama selepas tengah malam, atau cron bin/demo-reset.php).
 *  - Disekat: tukar kata laluan/e-mel/2FA, urus pengguna, tetapan SMTP & e-mel, semua muat naik fail.
 * JANGAN aktifkan pada sistem sebenar: reset akan MEMADAM semua data sekolah yang dibuka (edisi multi: setiap sekolah direset berasingan). */

const DEMO_ACCOUNTS=[
    ['admin','Cikgu Aina (Pentadbir)','admin@demo.test','Demo-Admin-2026','Semua halaman, akaun pengguna & tetapan sekolah'],
    ['pk','En. Rahman (Penolong Kanan)','pk@demo.test','Demo-PK-2026','Merancang jawatan & lantikan, hantar untuk semakan'],
    ['head','Hj. Ahmad Fauzi (Ketua Sekolah)','ketua@demo.test','Demo-Ketua-2026','Meluluskan & memuktamadkan senarai tugas'],
    ['teacher','En. Hakim bin Ismail (Guru)','guru@demo.test','Demo-Guru-2026','Melihat tugas sendiri & surat lantikan'],
];
const DEMO_BLOCKED=[
    'password'=>'akaun','twofa_disable'=>'akaun','enroll'=>'akaun','lupa'=>'akaun','reset'=>'akaun','daftar'=>'akaun',
    'user_create'=>'pengguna','user_update'=>'pengguna','user_reset_2fa'=>'pengguna','user_reset_password'=>'pengguna','avatar_admin_remove'=>'pengguna',
    'reg_approve'=>'pengguna','reg_reject'=>'pengguna',
    'reg_settings_save'=>'emel','reg_test_mail'=>'emel',
];
const DEMO_BLOCK_MSG=[
    'akaun'=>'Mod demo: akaun demo dikongsi oleh semua penguji, jadi kata laluan, e-mel dan 2FA tidak boleh diubah.',
    'pengguna'=>'Mod demo: pengurusan pengguna (tambah, ubah, padam, set semula) dimatikan.',
    'emel'=>'Mod demo: tetapan SMTP dan penghantaran e-mel dimatikan.',
    'fail'=>'Mod demo: muat naik fail (gambar, logo, avatar, CSV) dimatikan. Data contoh sudah disediakan.',
    'sandaran'=>'Mod demo: sandaran Google Drive dimatikan.',
];

/** Pemasangan dibenarkan menjalankan demo (DEMO_MODE=1 dalam .env). */
function demo_env(): bool { return env('DEMO_MODE')==='1'; }
/** Mod demo aktif untuk konteks semasa: platform (sid 0) atau sekolah yang ditanda sebagai sekolah demo. Sekolah sebenar dalam pemasangan demo tidak terjejas. */
function demo_mode(): bool { static $c=[]; if(!demo_env()) return false; return $c[sid()]??=(sid()===0 || sys_get('demo')==='1'); }
function demo_is_account(string $email): bool { foreach(DEMO_ACCOUNTS as $a) if($a[2]===strtolower(trim($email))) return true; return false; }
function demo_stamp_path(): string { return dirname(__DIR__).'/storage/demo-reset-'.(school()['code']??'platform').'.stamp'; }
function demo_last_reset(): ?string { $s=@file_get_contents(demo_stamp_path()); return $s!==false && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',trim($s))?trim($s):null; }

/** Panggil sebelum handle_action(): tolak tindakan yang disekat & sebarang muat naik fail. */
function demo_guard(string $action): void {
    if(!demo_mode()) return;
    if(str_starts_with($action,'backup_')) throw new DomainException(DEMO_BLOCK_MSG['sandaran']);
    if(isset(DEMO_BLOCKED[$action])) throw new DomainException(DEMO_BLOCK_MSG[DEMO_BLOCKED[$action]]);
    foreach($_FILES as $f){ foreach((array)($f['error']??[]) as $err) if((int)$err!==UPLOAD_ERR_NO_FILE) throw new DomainException(DEMO_BLOCK_MSG['fail']); }
}

/** Reset harian tanpa cron: permintaan pertama pada hari baharu menjana semula data (kunci fail elak reset berganda). */
function demo_maybe_reset(): void {
    if(!demo_mode() || sid()<=0) return;
    $last=demo_last_reset(); if($last!==null && substr($last,0,10)===date('Y-m-d')) return;
    $fh=@fopen(dirname(__DIR__).'/storage/demo-reset-'.(school()['code']??'platform').'.lck','c'); if(!$fh || !flock($fh,LOCK_EX|LOCK_NB)) return;
    try { clearstatcache(); $last=demo_last_reset(); if($last===null || substr($last,0,10)!==date('Y-m-d')) demo_reset(); }
    finally { flock($fh,LOCK_UN); fclose($fh); }
}

/** Kosongkan data sekolah semasa dan jana semula data demo. */
function demo_reset(): void {
    if(!demo_env()) throw new RuntimeException('demo_reset() hanya dibenarkan apabila DEMO_MODE=1.');
    $type=school_type(); // jenis sekolah (SK/SMK) ditetapkan semasa sekolah dicipta — kekalkan selepas data dikosongkan
    school_wipe();
    demo_seed($type);
    sys_set('demo','1'); // tanda sekolah demo (reset harian, akaun kongsi, sekatan demo)
    @file_put_contents(demo_stamp_path(),date('Y-m-d H:i:s'),LOCK_EX);
}

/** Jana data rekaan: tetapan sekolah & akaun kongsi. Data enjin (guru, jawatan, lantikan) ditambah apabila modul enjin dipindahkan. */
function demo_seed(string $type='SMK'): void {
    $school=(string)(school()['name']??'SMK Demo UrusTugas');
    save_settings(['school_name'=>$school,'school_type'=>$type==='SK'?'SK':'SMK','school_tagline'=>'Demo agihan tugas guru','reg_mode'=>'off','mail_method'=>'mail','site_url'=>'']);
    db()->beginTransaction();
    try {
        school_engine_init();
        foreach(DEMO_ACCOUNTS as [$role,$name,$email,$pass]) query('INSERT INTO users(school_id,name,email,password_hash,role,last_login_at) VALUES ('.sid().',?,?,?,?,NOW())',[$name,$email,hash_password($pass),$role]);
        db()->commit();
    } catch(Throwable $ex) { if(db()->inTransaction()) db()->rollBack(); throw $ex; }
}

/** Panel akaun demo di halaman log masuk. */
function demo_login_panel(): void {
    if(!demo_mode()) return;
    echo '<section class="demo-accounts" aria-label="Akaun demo"><h3>Akaun demo</h3><p class="muted">Klik untuk isi borang secara automatik.</p><ul>';
    foreach(DEMO_ACCOUNTS as [$role,$name,$email,$pass,$desc]) echo '<li><button type="button" class="demo-fill" data-email="'.e($email).'" data-password="'.e($pass).'"><span class="demo-role r-'.e($role).'">'.e(role_label($role)).'</span><span class="demo-cred"><code>'.e($email).'</code><code>'.e($pass).'</code></span><small>'.e($desc).'</small></button></li>';
    echo '</ul></section>';
}
/** Jalur makluman di atas setiap halaman. */
function demo_banner(): string {
    if(!demo_mode()) return '';
    $last=demo_last_reset();
    return '<div class="demo-banner" role="note"><strong>Mod demo</strong><span>Semua data adalah rekaan dan dijana semula setiap hari'.($last?' (terakhir: '.e(date('j/n/Y g:i a',strtotime($last))).')':'').'. Muat naik fail, e-mel dan perubahan akaun dimatikan.</span></div>';
}
