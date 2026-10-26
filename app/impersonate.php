<?php
declare(strict_types=1);
/*
 * "Masuk sebagai admin sekolah" (sokongan) — panel platform → laman sekolah.
 *  1. Platform (2FA, alasan wajib) menjana token sekali guna: nonce.pid.sekolah.pengguna.tamat.HMAC (kunci induk, bukan subkunci sekolah),
 *     hash nonce disimpan dalam platform_settings dan dipadam semasa digunakan; sah 120 saat.
 *  2. /KOD/?page=masuk_platform&t=… → sesi sekolah baharu untuk pentadbir itu, ditanda $_SESSION['imp'].
 *  3. Setiap permintaan: had keras 60 minit, akaun platform masih aktif & auth_version sama, sekolah sama, dan tanda imp_live_{pid}
 *     masih sepadan (dipadam apabila pentadbir platform log keluar atau memulakan sesi sokongan lain) — jika tidak, sesi dibatalkan.
 *  4. Setiap audit() semasa sesi ini merekod audit_logs.via_platform; mula & tamat dilog di platform_log dan audit_logs sekolah.
 *  Disekat semasa sesi: tukar kata laluan / 2FA akaun pentadbir itu.
 */
const IMP_TTL=120, IMP_MAX=3600;
const IMP_BLOCKED=['password','twofa_disable','enroll'];

function imp_key(): string { return hash_hkdf('sha256',master_key(),32,'urustugas/masuk-sebagai'); }
/** Token untuk pengguna $uid (mesti pentadbir aktif sekolah $school). */
function imp_issue(int $pid,array $school,int $uid,string $reason): string {
    if(!query('SELECT 1 FROM users WHERE id=? AND school_id=? AND role="admin" AND active=1',[$uid,$school['id']])->fetchColumn()) throw new DomainException('Pentadbir sekolah tidak dijumpai atau tidak aktif.');
    query('DELETE FROM platform_settings WHERE name LIKE "imp\\_%" AND updated_at<DATE_SUB(NOW(),INTERVAL 1 DAY)');
    $n=bin2hex(random_bytes(16)); $exp=time()+IMP_TTL; $body=$n.'.'.$pid.'.'.(int)$school['id'].'.'.$uid.'.'.$exp;
    query('INSERT INTO platform_settings(name,value) VALUES (?,?)',['imp_'.substr(hash('sha256',$n),0,40),json_encode(['reason'=>$reason])]);
    return $body.'.'.hash_hmac('sha256',$body,imp_key());
}
/** Sahkan & guna token (sekali sahaja). Pulangkan [pid, uid, alasan] atau null. */
function imp_consume(string $token): ?array {
    $p=explode('.',$token); if(count($p)!==6) return null;
    [$n,$pid,$sch,$uid,$exp,$sig]=$p;
    if(!hash_equals(hash_hmac('sha256',implode('.',array_slice($p,0,5)),imp_key()),$sig) || (int)$exp<time() || (int)$sch!==sid()) return null;
    $k='imp_'.substr(hash('sha256',$n),0,40); $v=query('SELECT value FROM platform_settings WHERE name=?',[$k])->fetchColumn();
    if($v===false || query('DELETE FROM platform_settings WHERE name=?',[$k])->rowCount()!==1) return null; // sudah digunakan
    return [(int)$pid,(int)$uid,(string)(json_decode((string)$v,true)['reason']??'')];
}
function impersonating(): ?array { return isset($_SESSION['imp']) && is_array($_SESSION['imp'])?$_SESSION['imp']:null; }
/** Semak semula setiap permintaan; batalkan sesi jika had masa / akaun platform tidak sah lagi. */
function imp_check(): void {
    $i=impersonating(); if(!$i) return;
    $a=query('SELECT auth_version FROM platform_admins WHERE id=? AND active=1',[$i['pid']])->fetch();
    $live=query('SELECT value FROM platform_settings WHERE name=?',['imp_live_'.(int)$i['pid']])->fetchColumn();
    if(time()>(int)$i['until'] || !$a || (int)$a['auth_version']!==(int)$i['pauth'] || (int)$i['school']!==sid() || $live===false || !hash_equals((string)$live,(string)$i['live'])){
        $sec=time()>(int)$i['until']?'tamat_masa':'dibatalkan';
        try { audit('platform_impersonate_end','users',(int)($_SESSION['uid']??0)); platform_log('school_impersonate_end',sid(),$sec,(int)$i['pid']); } catch(Throwable) {}
        $_SESSION=[]; session_regenerate_id(true);
        error_page(401,'Sesi sokongan pentadbir platform telah tamat. Mulakan semula dari panel platform.');
    }
}
/** GET /?page=masuk_platform&t=… (konteks sekolah). */
function imp_enter(): never {
    $r=imp_consume(input('t','get'));
    if(!$r) error_page(403,'Pautan masuk sebagai pentadbir tidak sah, telah digunakan atau tamat tempoh (2 minit). Mulakan semula dari panel platform.');
    [$pid,$uid,$reason]=$r;
    $a=query('SELECT id,name,auth_version FROM platform_admins WHERE id=? AND active=1',[$pid])->fetch();
    $u=query('SELECT id,auth_version FROM users WHERE id=? AND school_id=? AND role="admin" AND active=1',[$uid,sid()])->fetch();
    if(!$a || !$u) error_page(403,'Akaun tidak sah.');
    $live=bin2hex(random_bytes(16)); // satu sesi sokongan aktif bagi setiap pentadbir platform
    query('INSERT INTO platform_settings(name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['imp_live_'.(int)$a['id'],$live]);
    session_regenerate_id(true);
    $_SESSION=['uid'=>(int)$u['id'],'auth_version'=>(int)$u['auth_version'],'csrf'=>bin2hex(random_bytes(32)),'started'=>time(),'last'=>time(),'school_id'=>sid(),
        'imp'=>['pid'=>(int)$a['id'],'name'=>(string)$a['name'],'pauth'=>(int)$a['auth_version'],'school'=>sid(),'until'=>time()+IMP_MAX,'reason'=>$reason,'live'=>$live]];
    audit('platform_impersonate_start','users',(int)$u['id']);
    platform_log('school_impersonate_start',sid(),'pengguna '.(int)$u['id'].': '.$reason,(int)$a['id']);
    // Bukan lencongan HTTP: permintaan ini tiba daripada lencongan panel platform (laman lain), dan kuki sesi SameSite=Strict
    // tidak dihantar dalam rantaian lencongan merentas laman. Halaman ini beralih sendiri (meta refresh, patuh CSP), jadi
    // navigasi seterusnya bermula dari laman sekolah ini dan kuki dihantar.
    header('Cache-Control: no-store'); $next=tenant_location('/?page=dashboard');
    echo '<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><meta http-equiv="refresh" content="0;url='.e($next).'"><title>Sesi sokongan dimulakan</title><link rel="stylesheet" href="'.e(asset('app.css')).'"></head><body><main class="main-content platform-dir"><p class="notice success">Sesi sokongan dimulakan. Membuka papan pemuka sekolah…</p><p><a class="btn primary" href="/?page=dashboard">Teruskan →</a></p></main></body></html>';
    exit;
}
/** Panggil sebelum handle_action(): akaun pentadbir yang dipinjam tidak boleh ditukar kata laluan / 2FA. */
function imp_guard(string $action): void { if(impersonating() && in_array($action,IMP_BLOCKED,true)) throw new DomainException('Sesi sokongan platform: kata laluan dan 2FA akaun ini tidak boleh diubah.'); }
/** Log keluar daripada sesi sokongan. */
function imp_end_log(): void { $i=impersonating(); if($i){ audit('platform_impersonate_end','users',(int)($_SESSION['uid']??0)); platform_log('school_impersonate_end',sid(),'log keluar',(int)$i['pid']); query('DELETE FROM platform_settings WHERE name=? AND value=?',['imp_live_'.(int)$i['pid'],(string)$i['live']]); } }
/** URL mutlak halaman sekolah ini di panel platform (domain induk). */
function imp_platform_url(int $schoolId): string {
    $host=platform_domain()?:request_host(); return ($host!==''?tenant_scheme().'://'.$host:'').'/platform/?p=sekolah&id='.$schoolId;
}
/** Selepas sesi sokongan ditamatkan: kembali ke panel platform (bukan laman sekolah). Halaman beralih sendiri — CSP form-action
 *  laman sekolah tidak membenarkan lencongan selepas POST ke domain induk dalam mod subdomain. */
function imp_exit_page(int $schoolId): never {
    $u=imp_platform_url($schoolId); while(ob_get_level()>0) @ob_end_clean(); header('Cache-Control: no-store');
    echo '<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><meta http-equiv="refresh" content="0;url='.e($u).'"><title>Sesi sokongan ditamatkan</title><link rel="stylesheet" href="'.e(asset('app.css')).'"></head><body><main class="main-content platform-dir"><p class="notice success">Sesi sokongan ditamatkan. Kembali ke panel platform…</p><p><a class="btn primary" href="'.e($u).'">Kembali ke panel platform →</a></p></main></body></html>';
    exit;
}
/** Batalkan sesi sokongan aktif pentadbir platform ini (log keluar platform). */
function imp_revoke(int $pid): void { query('DELETE FROM platform_settings WHERE name=?',['imp_live_'.$pid]); }
function imp_banner(): string {
    $i=impersonating(); if(!$i) return '';
    return '<div class="notice error imp-banner" role="note"><span><strong>Sesi sokongan platform</strong> — '.e($i['name']).' sedang menggunakan akaun ini (tamat '.e(date('H:i',(int)$i['until'])).'). Semua tindakan direkod. Alasan: '.e($i['reason'])
        .'</span> <form method="post" class="inline-form">'.csrf_field().'<button class="btn sm outline" name="action" value="logout">Tamatkan sesi &amp; kembali ke platform</button></form></div>';
}
