<?php
declare(strict_types=1);
// Kembali dari Google (OAuth sandaran): tanpa sesi — kuki SameSite=Strict tidak dihantar dari tapak lain dan sesi baharu akan menimpa sesi pentadbir.
if((string)($_GET['page']??'')==='sandaran_oauth' || (string)($_GET['p']??'')==='sandaran_oauth') define('NO_SESSION',true); // edisi multi: /platform/?p=sandaran_oauth
// Kembali dari gerbang bayaran & callback gerbang: tanpa sesi (kuki SameSite=Strict tidak dihantar dari tapak gerbang; sesi baharu akan menimpa sesi pentadbir).
elseif((string)($_GET['page']??'')==='bayar_kembali' || parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)==='/bayar-callback') define('NO_SESSION',true);
$app=dirname(__DIR__).'/app/'; foreach(['bootstrap','actions','settings','users','install','integrity','images','pdf','mailer','captcha','accounts','richtext','avatars','addons','demo','changelog','hebahan','backup','impersonate','subscription','support'] as $f) require $app.$f.'.php';
// Webhook kemas kini daripada GitHub Actions (HMAC; sebelum semakan pemasangan supaya kemas kini skema juga boleh berjalan).
if(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)==='/kemaskini-hook'){ require_once dirname(__DIR__).'/app/updater.php'; upd_webhook(); }
// Sistem belum dipasang / perlu naik taraf: jangan proses permintaan biasa.
try { $appStatus=app_status(); }
catch(PDOException $ex) { if(is_file(install_lock_path())) throw $ex; $appStatus='not_installed'; } // DB belum disediakan: pemasang akan menunjukkan apa yang perlu dibetulkan.
// Belum dipasang atau perlu naik taraf: terus ke pemasang (ia sendiri dilindungi token & terkunci selepas siap).
if(in_array($appStatus,['not_installed','needs_upgrade'],true) && is_file(__DIR__.'/install.php')){ header('Location: '.platform_url('/install.php')); exit; } // pemasang hanya di domain induk
if(!defined('PASSWORD_ARGON2ID') || !extension_loaded('sodium')) throw new RuntimeException('PHP memerlukan Argon2id dan libsodium.');
if($appStatus!=='ready'){
 $msg=['not_installed'=>['Sistem belum dipasang','Pentadbir pelayan perlu menjalankan pemasang (php bin/install.php atau /install.php).'],'needs_upgrade'=>['Penyelenggaraan sedang berjalan','Pangkalan data perlu dinaik taraf. Pentadbir: jalankan php bin/upgrade.php atau buka /install.php.'],'broken'=>['Konfigurasi tidak lengkap','Fail kunci pemasangan wujud tetapi pangkalan data kosong. Semak tetapan DB dalam .env.']][$appStatus];
 error_page(503,$msg[1],['title'=>$msg[0],'no_db'=>true]);
}
demo_maybe_reset(); // DEMO_MODE=1 sahaja: data dijana semula sekali sehari
// URL yang tidak wujud (cth. /abc atau /fail-hilang.png) → 404, bukan laman utama.
$redirStatus=(int)($_SERVER['REDIRECT_STATUS']??0); if(in_array($redirStatus,[400,401,403,404,500],true)) error_page($redirStatus); // ErrorDocument Apache
$reqPath=(string)parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
// Perlindungan IP (Panel Platform → Perlindungan IP): sekatan platform & sekolah ini.
ip_guard();
if($reqPath==='/.well-known/security.txt'){ require_once dirname(__DIR__).'/app/subscription.php'; security_txt(); }
// Tiada kod sekolah dalam URL/hos → direktori sekolah & panel platform (app/platform.php).
if(!school()){ require dirname(__DIR__).'/app/platform.php'; platform_main(); }
require_once dirname(__DIR__).'/app/payment_pages.php'; require_once dirname(__DIR__).'/app/security_pages.php';
if(input('page','get')==='bayar_kembali') pay_return_page(); // tanpa sesi: status disahkan melalui API gerbang
emergency_guard(); // mod kecemasan platform: baca sahaja / tutup laman sekolah
// Sesi sokongan platform: token sekali guna → sesi pentadbir; semakan semula setiap permintaan.
if(input('page','get')==='masuk_platform') imp_enter();
imp_check();
if(!in_array($reqPath,['/','/index.php'],true)) error_page(404);
// Halaman ralat untuk ErrorDocument Apache (.htaccess).
if(input('page','get')==='ralat'){ $k=(int)input('kod','get'); error_page(in_array($k,[400,401,403,404,500,503],true)?$k:404); }
$page=input('page','get'); if($page==='') $page='dashboard'; $error='';
// Langganan tamat tanpa pakej Free: tindakan ditutup; pentadbir hanya ke Langganan / Profil (sebelum POST).
subscription_gate(current_user(),$page);
// Halaman Langganan: borang bayaran dihantar / dialih ke hos gerbang (CSP form-action meliputi redirect selepas POST).
if($page==='langganan') header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self' ".gw_csp_hosts()."; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
// Aset awam (diperlukan juga oleh halaman log masuk). Tiada data peribadi.
if($page==='theme-css') serve_theme_css();
if($page==='logo') serve_logo();
if($page==='captcha') serve_captcha(input('id','get'),isset($_GET['baru']));
if($page==='sandaran_oauth') error_page(404); // sandaran Google Drive di panel platform
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 try { demo_guard((string)($_POST['action']??'')); imp_guard((string)($_POST['action']??'')); handle_action($page,(string)($_POST['action']??'')); }
 catch(DomainException $ex){ if(db()->inTransaction()) db()->rollBack(); $error=$ex->getMessage(); }
 catch(PDOException $ex){ if(db()->inTransaction()) db()->rollBack(); if(in_array((string)$ex->getCode(),['23000','22001'])) $error='Rekod bertindih atau masih digunakan. Semak maklumat dan hubungan rekod.'; else throw $ex; }
}
$user=current_user();
// Had IP akaun & senarai IP pentadbir sekolah — disemak setiap permintaan (bukan hanya semasa log masuk).
if($user && !impersonating() && !user_ip_ok($user)){ audit('session_ip_denied','users',(int)$user['id']); $_SESSION=[]; error_page(403,'Akaun anda tidak dibenarkan dari alamat IP ini ('.client_ip().').'); }
if(!$user){ if(isset($_SESSION['pending_uid'])) $page=$page==='enroll'?'enroll':'verify'; elseif(!in_array($page,ACCOUNT_PAGES,true)) $page='login'; }
elseif(in_array($page,ACCOUNT_PAGES,true) || in_array($page,['login','verify'],true)) redirect('/?page=dashboard'); // sudah log masuk
elseif(!in_array($page,['dashboard','security','users','enroll','recovery','settings','system','avatar','urus_akaun','versi','langganan','sokongan','bayar_qr','ipsekat','hebahan','sandaran'],true)) error_page(404);
if($user && isset($_GET['pdf'])) download_document_pdf($page);
if($user && $page==='bayar_qr') pay_serve_qr();
if($user && $page==='langganan' && (int)input('resit','get')>0){ require_admin(); $rp=payment_get((int)input('resit','get')); if(!$rp || (int)$rp['school_id']!==sid()) error_page(404); pay_receipt_page($rp); }
if($user && $page==='avatar') serve_avatar((int)input('id','get'));
if($user && in_array($page,['users','settings','system','urus_akaun','sandaran'],true)) require_admin();
// Edisi multi: integriti fail & sandaran ialah urusan platform (fail & pangkalan data dikongsi semua sekolah).
if($user && in_array($page,['system','sandaran'],true)) error_page(403,'Integriti fail dan sandaran diurus oleh pentadbir platform.');
if($user && !impersonating() && !empty($user['must_change']) && $page!=='security') redirect('/?page=security');
// Dasar 2FA diketatkan selepas pengguna log masuk: wajib daftar sebelum meneruskan.
if($user && !impersonating() && empty($user['must_change']) && !$user['totp_secret'] && twofa_required((string)$user['role']) && !in_array($page,['enroll','recovery'],true)) redirect('/?page=enroll');
if($user && $page==='enroll' && $user['totp_secret']) redirect('/?page=security');
require dirname(__DIR__).'/app/views.php';
