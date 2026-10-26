<?php
// Kerja harian platform (Cron Job, cth. 0 4 * * *  php /home/AKAUN/urustugas/bin/cron.php >/dev/null 2>&1).
// Tanpa cron, kerja ini juga dicetus sekali sehari apabila pentadbir platform membuka panel atau pemantau memanggil sihat.php?k=.
//  - E-mel amaran langganan tamat (30 / 7 / 0 hari) & amaran pemadaman data (30 / 7 hari)
//  - Arkib sekolah yang tempohnya tamat (Free dimatikan) atau tidak aktif (Free)
//  - Buang pendaftaran sekolah yang e-melnya tidak disahkan
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','mailer','platform','subscription'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(is_file(dirname(__DIR__).'/app/registration.php')) require_once dirname(__DIR__).'/app/registration.php';
if(!is_installed()) { fwrite(STDERR,"Sistem belum dipasang.\n"); exit(1); }
$r=subscription_daily(true);
if(env('UPDATE_AUTO')==='1'){ require_once dirname(__DIR__).'/app/updater.php'; $u=upd_apply('cron'); echo date('Y-m-d H:i:s').' — kemas kini: '.$u['message']."\n"; }
echo date('Y-m-d H:i:s')." — e-mel dihantar: {$r['emel']}, sekolah diarkib: {$r['arkib']}, pratonton auto-agihan dibuang: ".(int)($r['pratonton']??0)."\n";
