<?php
// Sandaran ke Google Drive melalui Cron Job (pilihan — tanpa cron, sandaran berjalan sendiri sekali sehari).
//   cPanel → Cron Jobs, cth. setiap hari 2:30 pagi:  30 2 * * *  php /home/AKAUN/urustugas/bin/backup.php >/dev/null 2>&1
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','mailer','backup'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php';
if(function_exists('demo_mode') && demo_mode()) exit("Mod demo: sandaran dimatikan.\n");
$r=backup_run('cron');
if($r['ok']) { echo 'Sandaran berjaya: '.$r['name'].' ('.bk_bytes((int)$r['size']).', '.$r['secs']." saat)\n"; exit(0); }
fwrite(STDERR,'Sandaran gagal: '.$r['error']."\n"); exit(1);
