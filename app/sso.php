<?php
declare(strict_types=1);
/*
 * Log masuk tunggal (SSO) melalui Portal Akaun + segerak langganan. Pilihan: aktif hanya jika SSO_PORTAL_URL,
 * SSO_CLIENT_ID dan SSO_CLIENT_SECRET diisi dalam .env. Log masuk kata laluan tempatan kekal berfungsi.
 *
 *  /KOD/?page=sso_mula  → jana state + PKCE (sesi) → 302 ke PORTAL/authorize
 *  /KOD/?page=sso       → sahkan state → POST PORTAL/token (pelayan-ke-pelayan) → padankan users(school_id,email) → sesi
 *  POST /sso-hook       → portal menolak status langganan (HMAC) → schools.expires_on dikemas kini
 *
 * Kuki sesi UrusTugas ialah SameSite=Strict: ia TIDAK dihantar apabila portal (domain lain) melencong balik, dan rantaian
 * lencongan merentas laman kekal "cross-site" hingga ke 302 terakhir. Oleh itu:
 *   - state + PKCE verifier disimpan dalam kuki aliran berasingan (SameSite=Lax, tersulit, 10 minit), bukan dalam sesi;
 *   - selepas log masuk, navigasi ke dashboard dibuat dengan meta refresh dari origin sendiri (bukan 302) supaya
 *     permintaan seterusnya same-origin dan kuki sesi Strict yang baharu dihantar.
 * Peranan & kebenaran kekal dalam UrusTugas: portal hanya mengesahkan identiti (e-mel) dan langganan sekolah.
 * 2FA: jika portal melaporkan amr "otp", 2FA tempatan dilangkau; jika tidak, aliran 2FA UrusTugas biasa terpakai.
 * Protokol: rujuk portal-akaun/docs/SSO-PROTOKOL.md.
 */
const SSO_FLOW_TTL=600, SSO_HOOK_SKEW=300;

function sso_portal(): string { return rtrim(env('SSO_PORTAL_URL'),'/'); }
function sso_enabled(): bool { return sso_portal()!=='' && env('SSO_CLIENT_ID')!=='' && env('SSO_CLIENT_SECRET')!==''; }
function sso_b64url(string $raw): string { return rtrim(strtr(base64_encode($raw),'+/','-_'),'='); }
function sso_redirect_uri(): string { return school_url(school()['code'],'/?page=sso'); }
/** Sesi semasa dibuka melalui portal; ['mfa'=>bool] atau null. */
function sso_session(): ?array { return isset($_SESSION['sso']) && is_array($_SESSION['sso'])?$_SESSION['sso']:null; }
function sso_mfa(): bool { return !empty(sso_session()['mfa']); }

/* ---------- Kuki aliran (state + PKCE), SameSite=Lax, disulitkan dengan subkunci HKDF ---------- */
function sso_flow_cookie(): string { return (env('SESSION_SECURE',request_is_https()?'1':'0')==='1'?'__Secure-':'').'urustugas_sso'; }
function sso_flow_params(int $expires): array {
    return ['expires'=>$expires,'path'=>tenant_prefix()!==''?tenant_prefix().'/':'/','secure'=>env('SESSION_SECURE',request_is_https()?'1':'0')==='1','httponly'=>true,'samesite'=>'Lax'];
}
function sso_flow_key(): string { return hash_hkdf('sha256',master_key(),32,'urustugas/sso-aliran'); }
function sso_flow_set(array $flow): void {
    $n=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    setcookie(sso_flow_cookie(),sso_b64url($n.sodium_crypto_secretbox(json_encode($flow),$n,sso_flow_key())),sso_flow_params(time()+SSO_FLOW_TTL));
}
/** Baca & padam kuki aliran (sekali guna). Terikat pada sekolah semasa. */
function sso_flow_take(): ?array {
    $raw=(string)($_COOKIE[sso_flow_cookie()]??''); setcookie(sso_flow_cookie(),'',sso_flow_params(time()-3600));
    $b=base64_decode(strtr($raw,'-_','+/'),true); if($b===false || strlen($b)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+16) return null;
    $j=sodium_crypto_secretbox_open(substr($b,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($b,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),sso_flow_key());
    $f=$j===false?null:json_decode($j,true);
    return is_array($f) && (int)($f['school']??0)===sid() && time()-(int)($f['at']??0)<=SSO_FLOW_TTL ? $f : null;
}
/** Teruskan ke halaman dalaman dengan meta refresh (memutuskan rantaian lencongan merentas laman — lihat kepala fail). */
function sso_continue(string $path): never {
    $GLOBALS['URUS_SKIP_REWRITE']=true; $u=e(tenant_location($path));
    echo '<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta http-equiv="refresh" content="0;url='.$u.'"><title>Log masuk…</title></head><body><p><a href="'.$u.'">Teruskan →</a></p></body></html>';
    exit;
}

/** Butang pada halaman log masuk. */
function sso_login_button(): void {
    if(!sso_enabled()) return;
    echo '<div class="sso-login"><a class="btn primary sso-btn" href="/?page=sso_mula">Log masuk melalui Portal Akaun</a><p class="muted sso-or"><span>atau guna kata laluan UrusTugas</span></p></div>';
}

/** GET /?page=sso_mula */
function sso_start(): never {
    if(!sso_enabled()) error_page(404);
    if(current_user()) redirect('/?page=dashboard');
    $state=sso_b64url(random_bytes(24)); $verifier=sso_b64url(random_bytes(32));
    sso_flow_set(['state'=>$state,'verifier'=>$verifier,'school'=>sid(),'at'=>time()]);
    $q=['response_type'=>'code','client_id'=>env('SSO_CLIENT_ID'),'redirect_uri'=>sso_redirect_uri(),'state'=>$state,
        'code_challenge'=>sso_b64url(hash('sha256',$verifier,true)),'code_challenge_method'=>'S256','school'=>school()['code']];
    header('Location: '.sso_portal().'/authorize?'.http_build_query($q)); exit;
}

function sso_http_post(string $url,array $fields): array {
    $body=http_build_query($fields);
    if(function_exists('curl_init')){
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS|CURLPROTO_HTTP,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json']]);
        $out=curl_exec($ch); return [(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE),is_string($out)?$out:''];
    }
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json",'content'=>$body,'timeout'=>10,'ignore_errors'=>true,'follow_location'=>0]]);
    $out=@file_get_contents($url,false,$ctx); $code=preg_match('#^HTTP/\S+ (\d{3})#',($http_response_header[0]??''),$m)?(int)$m[1]:0;
    return [$code,is_string($out)?$out:''];
}

function sso_fail(string $msg,string $log='',int $code=403): never {
    if($log!=='') try { audit('login_sso_failed','users',null,mb_substr($log,0,250)); } catch(Throwable) {}
    error_page($code,$msg.' Anda masih boleh log masuk dengan kata laluan UrusTugas.',['title'=>'Log masuk Portal gagal']);
}

/** GET /?page=sso&code=…&state=… */
function sso_callback(): never {
    if(!sso_enabled()) error_page(404);
    $flow=sso_flow_take(); // sekali guna, terikat pada sekolah ini
    if(!$flow || !hash_equals((string)($flow['state']??''),input('state','get')))
        sso_fail('Permintaan log masuk tamat tempoh atau tidak sah. Cuba semula dari halaman log masuk.','state tidak sepadan / tamat',400);
    $code=input('code','get'); if($code==='' || strlen($code)>200) sso_fail('Kod log masuk tiada.','tiada kod',400);
    [$status,$raw]=sso_http_post(sso_portal().'/token',['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>sso_redirect_uri(),
        'code_verifier'=>(string)$flow['verifier'],'client_id'=>env('SSO_CLIENT_ID'),'client_secret'=>env('SSO_CLIENT_SECRET')]);
    $t=json_decode($raw,true);
    if($status!==200 || !is_array($t)) sso_fail('Portal Akaun tidak dapat mengesahkan log masuk.','token HTTP '.$status.' '.(is_array($t)?($t['error']??''):mb_substr($raw,0,80)),502);
    // Semak respons ditujukan kepada aplikasi & sekolah ini.
    if(($t['aud']??'')!==env('SSO_CLIENT_ID') || rtrim((string)($t['iss']??''),'/')!==sso_portal() || strtoupper((string)($t['school']??''))!==strtoupper(school()['code']))
        sso_fail('Respons portal tidak sepadan dengan sekolah ini.','aud/iss/school tidak sepadan',400);
    $email=mb_substr(strtolower(trim((string)($t['email']??''))),0,190);
    if(!filter_var($email,FILTER_VALIDATE_EMAIL) || empty($t['email_verified'])) sso_fail('E-mel akaun portal tidak sah.','e-mel tidak sah',400);
    sso_apply_subscription(is_array($t['subscription']??null)?$t['subscription']:[]);

    $u=query('SELECT * FROM users WHERE school_id='.sid().' AND email=?',[$email])->fetch();
    if(!$u) sso_fail('Akaun '.$email.' belum didaftarkan dalam UrusTugas sekolah ini. Minta pentadbir sekolah menambah anda di Pengguna & Audit.','tiada akaun: '.$email);
    if(!(int)$u['active']) sso_fail(($u['approval']??'')==='pending'?'Akaun anda sedang menunggu kelulusan pentadbir.':'Akaun ini tidak aktif. Hubungi pentadbir sistem.','akaun tidak aktif: '.$email);
    if(!user_ip_ok($u)){ audit('login_ip_denied','users',(int)$u['id']); sso_fail('Log masuk akaun ini tidak dibenarkan dari alamat IP anda ('.client_ip().').'); }
    if(function_exists('shield_success')) shield_success($email);

    $mfa=in_array('otp',(array)($t['amr']??[]),true);
    session_regenerate_id(true);
    if(!$mfa && ($u['totp_secret'] || twofa_required((string)$u['role']))){
        // Portal tidak mengesahkan 2FA → teruskan dengan 2FA UrusTugas (kata laluan diganti oleh portal sahaja).
        $_SESSION=['csrf'=>bin2hex(random_bytes(32)),'pending_uid'=>(int)$u['id'],'pending_version'=>(int)$u['auth_version'],'pending_at'=>time(),'school_id'=>sid()];
        audit('login_sso_pwd','users',(int)$u['id'],'portal tanpa 2FA → 2FA tempatan');
        sso_continue($u['totp_secret']?'/?page=verify':'/?page=enroll');
    }
    $_SESSION=['uid'=>(int)$u['id'],'auth_version'=>(int)$u['auth_version'],'csrf'=>bin2hex(random_bytes(32)),'started'=>time(),'last'=>time(),'school_id'=>sid(),
        'sso'=>['mfa'=>$mfa,'sub'=>mb_substr((string)($t['sub']??''),0,40),'at'=>time()]];
    query('UPDATE users SET last_login_at=NOW() WHERE id=? AND school_id='.sid(),[(int)$u['id']]);
    audit('login_sso','users',(int)$u['id'],'portal'.($mfa?' + 2FA':''));
    sso_continue('/?page=dashboard');
}

/** Selepas log keluar sesi SSO: tamatkan juga sesi portal. Meta refresh, bukan 302: log keluar ialah POST borang, dan CSP
 *  form-action 'self' dikuatkuasakan pada seluruh rantaian lencongannya — 302 ke domain portal akan disekat oleh pelayar. */
function sso_logout_redirect(): never {
    if(!sso_enabled()) redirect('/');
    $GLOBALS['URUS_SKIP_REWRITE']=true; $u=e(sso_portal().'/logout');
    echo '<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta http-equiv="refresh" content="0;url='.$u.'"><title>Log keluar…</title></head><body><p><a href="'.$u.'">Teruskan ke Portal Akaun →</a></p></body></html>';
    exit;
}

/** Status langganan daripada portal → schools.expires_on (pro: tarikh; free/none: NULL). Gate sedia ada (school_plan) terus terpakai. */
function sso_apply_subscription(array $sub,?int $schoolId=null): ?string {
    $plan=(string)($sub['plan']??''); if(!in_array($plan,['pro','free','none'],true)) return null;
    $exp=null;
    if($plan==='pro'){ $exp=(string)($sub['expires_on']??''); $d=DateTime::createFromFormat('!Y-m-d',$exp); if(!$d || $d->format('Y-m-d')!==$exp) return null; }
    $id=$schoolId??sid(); $cur=query('SELECT expires_on FROM schools WHERE id=?',[$id])->fetchColumn();
    if($cur===false) return null;
    if(($cur?:null)!==$exp){
        query('UPDATE schools SET expires_on=? WHERE id=?',[$exp,$id]);
        if(function_exists('platform_log')) try { platform_log('school_sso_subscription',$id,$plan.($exp?' hingga '.$exp:'')); } catch(Throwable) {}
    }
    return $plan;
}

/** POST /sso-hook — tanpa sesi; JSON. */
function sso_hook(): never {
    header('Content-Type: application/json; charset=utf-8');
    $out=function(int $c,array $d): never { http_response_code($c); echo json_encode($d); exit; };
    if(!sso_enabled()) $out(404,['error'=>'sso_disabled']);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST') $out(405,['error'=>'method']);
    $body=(string)file_get_contents('php://input',false,null,0,65536);
    $ts=(string)($_SERVER['HTTP_X_PORTAL_TIMESTAMP']??''); $sig=strtolower((string)($_SERVER['HTTP_X_PORTAL_SIGNATURE']??''));
    if(!ctype_digit($ts) || abs(time()-(int)$ts)>SSO_HOOK_SKEW) $out(401,['error'=>'stale']);
    if(!hash_equals(hash_hmac('sha256',$ts.'.'.$body,env('SSO_CLIENT_SECRET')),$sig)) $out(401,['error'=>'signature']);
    $d=json_decode($body,true);
    if(!is_array($d) || ($d['event']??'')!=='subscription.updated' || !preg_match('/^[A-Z]{3}[0-9]{4}$/D',strtoupper((string)($d['school']??'')))) $out(400,['error'=>'payload']);
    $id=query('SELECT id FROM schools WHERE code=?',[strtoupper((string)$d['school'])])->fetchColumn();
    if($id===false) $out(404,['error'=>'school_not_found']);
    $plan=sso_apply_subscription(is_array($d['subscription']??null)?$d['subscription']:[],(int)$id);
    if($plan===null) $out(400,['error'=>'subscription']);
    $out(200,['ok'=>true,'school'=>strtoupper((string)$d['school']),'plan'=>$plan]);
}
