<?php
/*
 * Titik semakan kesihatan untuk pemantau uptime (UptimeRobot, Better Stack, Hetrix, dll.).
 *   /sihat.php            → semakan asas: pangkalan data + storan. "OK" (200) atau "RALAT" (503). Tiada butiran didedahkan.
 *   /sihat.php?k=KUNCI    → semakan penuh: + ruang cakera & integriti fail, sebab ringkas jika gagal,
 *                           direkod dalam log uptime dan mencetuskan semakan integriti berkala.
 * KUNCI dijana di Integriti & Status (pentadbir).
 */
declare(strict_types=1);
define('NO_SESSION',true);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
try {
    require dirname(__DIR__).'/app/bootstrap.php';
    require dirname(__DIR__).'/app/install.php';
    require dirname(__DIR__).'/app/integrity.php';
    require dirname(__DIR__).'/app/settings.php';
    require dirname(__DIR__).'/app/mailer.php';
    require dirname(__DIR__).'/app/backup.php';
    if(app_status()!=='ready') { http_response_code(503); echo "RALAT\n"; exit; }
    tenant_use(null); // edisi multi: semakan kesihatan, integriti & sandaran ialah urusan platform (sama walaupun dibuka melalui subdomain sekolah)
} catch(Throwable) { http_response_code(503); echo "RALAT\n"; exit; }

$start=microtime(true);
$key=health_key(); $given=input('k','get');
$full=$key!=='' && $given!=='' && hash_equals($key,$given);
[$ok,$problems]=health_check($full);
if($full){
    try { health_log($ok,$problems,'monitor',round((microtime(true)-$start)*1000,1)); integrity_lazy(); backup_lazy();
        foreach(['platform','subscription'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php'; after_response(fn()=>subscription_daily()); } catch(Throwable $ex) { error_log('UrusTugas health: '.$ex->getMessage()); }
}
if(!$ok){ http_response_code(503); echo 'RALAT'.($full?': '.implode(', ',$problems):'')."\n"; exit; }
echo "OK\n";
