<?php
// Kemas kini daripada GitHub Release (lihat app/updater.php & docs/KEMASKINI-GITHUB.md).
//   php bin/update.php semak        — banding versi dipasang dengan Release terkini
//   php bin/update.php kemaskini    — pasang jika ada versi baharu (tambah --paksa untuk pasang semula)
//   php bin/update.php rollback     — pulihkan fail sebelum kemas kini terakhir
// Cron (sandaran jika webhook tidak sampai): */15 * * * *  php /laluan/ke/bin/update.php kemaskini >/dev/null 2>&1
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['settings','install','integrity','updater'] as $f) if(is_file(dirname(__DIR__)."/app/$f.php")) require_once dirname(__DIR__)."/app/$f.php";
if(is_file(dirname(__DIR__).'/app/tenant.php')) require_once dirname(__DIR__).'/app/tenant.php';
$cmd=$argv[1]??'semak';
try {
    if($cmd==='semak'){ $m=upd_latest(); $c=upd_current(); echo 'Dipasang : '.($c?substr((string)$c['sha'],0,8).' v'.($c['version']??''):'(zip manual)')."\nTerkini  : ".substr($m['sha'],0,8).' v'.($m['version']??'').' — '.($m['message']??'')."\n"; echo ($c && ($c['sha']??'')===$m['sha'])?"Sudah terkini.\n":"Kemas kini tersedia: php bin/update.php kemaskini\n"; exit(0); }
    if($cmd==='kemaskini'){ $r=upd_apply('cli',in_array('--paksa',$argv,true)); echo $r['message']."\n"; exit($r['status']==='gagal'?1:0); }
    if($cmd==='rollback'){ $r=upd_rollback('cli'); echo $r['message']."\n"; exit($r['status']==='gagal'?1:0); }
    fwrite(STDERR,"Arahan: semak | kemaskini [--paksa] | rollback\n"); exit(2);
} catch(Throwable $ex){ fwrite(STDERR,'Ralat: '.$ex->getMessage()."\n"); exit(1); }
