<?php
declare(strict_types=1);
/*
 * Semakan integriti fail & status sistem.
 *  - Asas (baseline): hash SHA-256 setiap fail aplikasi dalam storage/integrity.json, ditandatangani HMAC
 *    dengan kunci terbitan APP_KEY. Penyerang yang mengubah fail tidak boleh "membetulkan" asas tanpa APP_KEY.
 *  - Semakan: fail diubah / baharu / hilang + fail skrip dalam storage/ atau public_html/assets/ (tanda web shell).
 *  - Semakan berkala tanpa cron: dijalankan selepas respons dihantar, paling kerap setiap 6 jam,
 *    dicetuskan oleh pentadbir yang log masuk atau oleh pemantau uptime (sihat.php?k=…).
 *  - Status kesihatan: rekod dalam jadual health_log untuk peratus uptime & senarai insiden.
 */
const INTEGRITY_SCRIPT = '/\.(php\d?|phtml|phar|pht|phps|inc|cgi|pl|py|sh|asp|aspx|jsp)$/i';
const INTEGRITY_INTERVAL = 6*3600;

function integrity_root(): string { return realpath(dirname(__DIR__)) ?: dirname(__DIR__); }
function integrity_file(): string { return integrity_root().'/storage/integrity.json'; }
function integrity_key(): string { return hash_hkdf('sha256',platform_key(),32,'urustugas/integriti'); } // tetap: sama dalam konteks platform & sekolah
function integrity_rel(string $path): string { return ltrim(str_replace('\\','/',substr($path,strlen(integrity_root()))),'/'); }

/** Folder sementara (berubah sentiasa): tidak dikira sebagai 'fail berubah', tetapi tetap diimbas untuk fail skrip mencurigakan. */
const INTEGRITY_TMP_DIRS=['tmp','temp','cache','public_html/tmp','public_html/temp','public_html/cache'];
const INTEGRITY_SKIP_DIRS=['storage','.git','node_modules',...INTEGRITY_TMP_DIRS];
/** Senarai fail aplikasi => sha256 (storage/, .git dan log ralat hosting dikecualikan). */
function integrity_scan(): array {
    $root=integrity_root(); $out=[];
    $it=new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
        function(SplFileInfo $f) use($root){ $rel=integrity_rel($f->getPathname()); return $f->isDir()?!in_array($rel,INTEGRITY_SKIP_DIRS,true):true; }),RecursiveIteratorIterator::LEAVES_ONLY);
    foreach($it as $f){
        if(!$f->isFile() || $f->isLink()) continue;
        $rel=integrity_rel($f->getPathname());
        if(preg_match('~(^|/)error_log$|^\.env\.bak-|\.zip$|(^|/)\.DS_Store$~',$rel)) continue;
        $out[$rel]=$f->getSize()>20*1024*1024?'besar:'.$f->getSize():hash_file('sha256',$f->getPathname());
    }
    ksort($out); return $out;
}
/** Fail skrip di lokasi yang sepatutnya tiada kod. */
function integrity_suspicious(): array {
    $out=[]; $root=integrity_root();
    foreach(array_merge(['storage','public_html/assets'],INTEGRITY_TMP_DIRS) as $dir){
        $base=$root.'/'.$dir; if(!is_dir($base)) continue;
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS)) as $f){
            if(!$f->isFile()) continue;
            $name=$f->getFilename();
            if(preg_match(INTEGRITY_SCRIPT,$name) || ($name==='.htaccess' && $dir==='storage' && stripos((string)@file_get_contents($f->getPathname(),false,null,0,4096),'php')!==false)) $out[]=integrity_rel($f->getPathname());
        }
    }
    sort($out); return $out;
}
function integrity_baseline(): ?array {
    $f=integrity_file(); if(!is_readable($f)) return null;
    $j=json_decode((string)file_get_contents($f),true);
    if(!is_array($j) || !isset($j['files'],$j['hmac'],$j['created'])) return ['broken'=>true];
    $ok=hash_equals(hash_hmac('sha256',json_encode($j['files']).'|'.$j['created'].'|'.($j['by']??''),integrity_key()),(string)$j['hmac']);
    return $ok?$j:['broken'=>true];
}
function integrity_accept(string $by): int {
    $files=integrity_scan(); $created=date('c');
    $j=['created'=>$created,'by'=>$by,'files'=>$files,'hmac'=>hash_hmac('sha256',json_encode($files).'|'.$created.'|'.$by,integrity_key())];
    if(@file_put_contents(integrity_file(),json_encode($j,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)===false) throw new RuntimeException('Tidak dapat menulis storage/integrity.json. Semak kebenaran folder storage/.');
    @chmod(integrity_file(),0600);
    sys_set('integrity_has_baseline','1');
    return count($files);
}
/** Bandingkan dengan asas. status: ok | changed | no_baseline | baseline_broken | baseline_missing */
function integrity_check(): array {
    $base=integrity_baseline(); $now=integrity_scan();
    $h=['status'=>'ok','changed'=>[],'added'=>[],'removed'=>[],'suspicious'=>integrity_suspicious(),'total'=>count($now),'time'=>time()];
    if($base===null && sys_get('integrity_has_baseline')==='1') $h['status']='baseline_missing';
    elseif($base===null){ integrity_accept('automatik (semakan pertama)'); $h['baseline']=['created'=>date('c'),'by'=>'automatik (semakan pertama)']; }
    elseif(!empty($base['broken'])) $h['status']='baseline_broken';
    else {
        foreach($now as $f=>$x){ if(!isset($base['files'][$f])) $h['added'][]=$f; elseif(!hash_equals((string)$base['files'][$f],$x)) $h['changed'][]=$f; }
        foreach($base['files'] as $f=>$_) if(!isset($now[$f])) $h['removed'][]=$f;
        $h['baseline']=['created'=>$base['created'],'by'=>$base['by']??''];
        if($h['changed'] || $h['added'] || $h['removed']) $h['status']='changed';
    }
    $h['problem']=$h['status']!=='ok' || (bool)$h['suspicious'];
    $sig=hash('sha256',json_encode([$h['status'],$h['changed'],$h['added'],$h['removed'],$h['suspicious']]));
    $old=json_decode(sys_get('integrity_result'),true);
    sys_set('integrity_result',json_encode($h+['sig'=>$sig],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    sys_set('integrity_last',(string)time());
    if($h['problem'] && ($old['sig']??'')!==$sig) audit('integrity_alert','system');   // rekod sekali bagi setiap perubahan hasil
    return $h;
}
function integrity_last(): ?array { $j=json_decode(sys_get('integrity_result'),true); return is_array($j)?$j:null; }

/* ---------- Simpanan ringkas (jadual settings, kunci bermula 'sys_') ---------- */
/** Integriti fail, kunci semakan kesihatan & sandaran ialah urusan pelayan (platform); selebihnya (cth. takwim_feed_key) milik sekolah semasa. */
function sys_is_platform(string $k): bool { return sid()===0 || (bool)preg_match('/^(integrity_|health_|backup_)/',$k); }
function sys_get(string $k): string {
    try { return (string)((sys_is_platform($k)?query('SELECT value FROM platform_settings WHERE name=?',['sys_'.$k]):query('SELECT value FROM settings WHERE school_id=? AND name=?',[sid(),'sys_'.$k]))->fetchColumn() ?: ''); }
    catch(PDOException) { return ''; }
}
function sys_set(string $k,string $v): void {
    if(sys_is_platform($k)) query('INSERT INTO platform_settings(name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['sys_'.$k,$v]);
    else query('INSERT INTO settings(school_id,name,value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[sid(),'sys_'.$k,$v]);
}

/** Jalankan selepas respons dihantar supaya pengguna tidak menunggu. */
function after_response(callable $fn): void {
    register_shutdown_function(function() use($fn){
        // Hantar respons kepada pengguna dahulu, kemudian jalankan kerja latar (PHP-FPM / LiteSpeed LSAPI — biasa di cPanel).
        if(function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); elseif(function_exists('litespeed_finish_request')) @litespeed_finish_request();
        try { @set_time_limit(120); $fn(); } catch(Throwable $ex) { error_log('Sekolah background: '.$ex->getMessage()); }
    });
}
/** Semakan integriti berkala (malas): paling kerap sekali setiap 6 jam. */
function integrity_lazy(): void {
    if((int)sys_get('integrity_last')>time()-INTEGRITY_INTERVAL) return;
    if((int)sys_get('integrity_claim')>time()-300) return;   // elak semakan serentak
    sys_set('integrity_claim',(string)time());
    after_response(fn()=>integrity_check());
}

/* ---------- Kesihatan / uptime ---------- */
/** Semakan kesihatan. Pulangkan [ok, senarai masalah, butiran]. */
function health_check(bool $full): array {
    $problems=[]; $detail=[];
    try { $t=microtime(true); query('SELECT 1')->fetchColumn(); $detail['db_ms']=round((microtime(true)-$t)*1000,1); }
    catch(Throwable) { return [false,['pangkalan data'],[]]; }
    $storage=integrity_root().'/storage';
    if(!is_writable($storage)) $problems[]='storan tidak boleh ditulis';
    $free=@disk_free_space($storage); $detail['disk_free']=$free===false?null:(int)$free;
    if($free!==false && $free<200*1024*1024) $problems[]='ruang cakera rendah';
    if($full){
        $int=integrity_last();
        if($int && $int['problem']) $problems[]='integriti fail';
        if(!$int || (int)($int['time']??0)<time()-2*INTEGRITY_INTERVAL) $detail['integrity_stale']=true;
        if(function_exists('backup_stale') && backup_stale()) $problems[]='sandaran gagal/lapuk';
    }
    return [!$problems,$problems,$detail];
}
function health_log(bool $ok,array $problems,string $source,?float $ms=null): void {
    // Maksimum satu rekod setiap 4 minit bagi pemantau (elak jadual membesar); insiden sentiasa direkod.
    if($source==='monitor' && $ok){ $last=query('SELECT checked_at FROM health_log WHERE source="monitor" ORDER BY id DESC LIMIT 1')->fetchColumn(); if($last && strtotime($last)>time()-240) return; }
    query('INSERT INTO health_log(checked_at,ok,detail,source,response_ms) VALUES (NOW(),?,?,?,?)',[$ok?1:0,mb_substr(implode(', ',$problems),0,250),$source,$ms]);
    if(random_int(1,100)===1) query('DELETE FROM health_log WHERE checked_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
}
/** Peratus uptime mengikut rekod pemeriksaan dalam tempoh (jam). null jika tiada data. */
function uptime_percent(int $hours): ?float {
    $r=query('SELECT COUNT(*) AS n, COALESCE(SUM(ok),0) AS up FROM health_log WHERE checked_at>=DATE_SUB(NOW(), INTERVAL ? HOUR)',[$hours])->fetch();
    return (int)$r['n']>0?round($r['up']/$r['n']*100,2):null;
}
function health_key(): string { return sys_get('health_key'); }

/* ---------- Ujian pendedahan fail sensitif melalui web ---------- */
function exposure_test(): array {
    $host=platform_domain()?:(string)($_SERVER['HTTP_HOST']??''); if(!preg_match('/^[A-Za-z0-9.:-]+$/D',$host)) return [];
    $base=(platform_domain()!==''?tenant_scheme():(request_is_https()?'https':'http')).'://'.$host;
    $out=[];
    foreach(['.env','app/bootstrap.php','storage/integrity.json','storage/installed.lock','database/schema.sql','README.md','composer.json'] as $p){
        $code=0; $body='';
        if(function_exists('curl_init')){
            $ch=curl_init($base.'/'.$p);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_RANGE=>'0-255',CURLOPT_TIMEOUT=>6,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false]);
            $body=(string)curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        }
        // 200 dengan kandungan fail sebenar = terdedah. Halaman aplikasi (HTML) bermakna laluan dihala ke index.php = selamat.
        $exposed=$code>=200 && $code<300 && $body!=='' && stripos($body,'<!doctype html')===false;
        $out[$p]=[$code,$exposed];
    }
    return $out;
}

/* ---------- Tindakan pentadbir ---------- */
function handle_system(string $action): never {
    require_platform_admin();
    if($action==='system_integrity_check'){ $h=integrity_check(); audit('integrity_check','system'); flash($h['problem']?'Semakan selesai: terdapat perubahan atau fail mencurigakan. Lihat butiran di bawah.':'Semakan selesai: semua fail sepadan dengan asas.'); }
    elseif($action==='system_integrity_accept'){
        platform_confirm_password();
        if(input('confirm_accept')!=='yes') throw new DomainException('Tandakan pengesahan bahawa perubahan ini dibuat oleh anda (contohnya kemas kini sistem).');
        $n=integrity_accept(platform_user()['name'].' ('.platform_user()['email'].')'); integrity_check(); audit('integrity_accept','system');
        flash('Asas integriti baharu disimpan ('.$n.' fail).');
    }
    elseif($action==='system_health_key'){ sys_set('health_key',bin2hex(random_bytes(16))); audit('health_key','system'); flash('Kunci pemantau baharu dijana. Kemas kini URL dalam perkhidmatan pemantau anda.'); }
    elseif($action==='system_health_now'){ $t=microtime(true); [$ok,$p]=health_check(true); health_log($ok,$p,'manual',round((microtime(true)-$t)*1000,1)); flash($ok?'Semakan kesihatan: semua OK.':'Semakan kesihatan: '.implode(', ',$p).'.'); }
    elseif($action==='system_exposure'){ $_SESSION['exposure']=['time'=>time(),'result'=>exposure_test()]; audit('exposure_test','system'); flash('Ujian pendedahan fail selesai.'); }
    else throw new DomainException('Tindakan tidak sah.');
    redirect('/platform/?p=sistem');
}

/* Semakan CVE PHP: PHP2Shell (CVE-2026-109618 / CVE-2026-109619, RCE tanpa log masuk, dibaiki 21 Okt 2026).
 * Versi dibaiki mengikut siri; siri lebih baharu daripada senarai dianggap selamat, siri < 8.2 tiada sokongan.
 * Tetapan session.upload_progress & fatal_error_backtraces mengurangkan risiko tetapi bukan pengganti kemas kini. */
const PHP_CVE_IDS='CVE-2026-109618 / CVE-2026-109619';
const PHP_CVE_FIXED=['8.2'=>'8.2.35','8.3'=>'8.3.36','8.4'=>'8.4.27','8.5'=>'8.5.12'];
function php_cve_fixed_for(string $version): ?string { return PHP_CVE_FIXED[implode('.',array_slice(explode('.',$version),0,2))]??null; }
function php_cve_vulnerable(string $version=PHP_VERSION): bool {
    if(version_compare($version,'8.2.0','<')) return true;
    $f=php_cve_fixed_for($version); if($f===null) return false; // siri lebih baharu daripada 8.5
    return version_compare($version,$f,'<');
}
/** Baris semakan: [label, ok|warn|bad, butiran]. */
function php_cve_checks(): array {
    $v=PHP_VERSION; $f=php_cve_fixed_for($v); $vul=php_cve_vulnerable($v); $on=fn(string $k)=>filter_var(ini_get($k),FILTER_VALIDATE_BOOLEAN);
    $rows=[['Versi PHP dibaiki ('.PHP_CVE_IDS.')',$vul?'bad':'ok',$vul?($f?$v.' terdedah — naik taraf ke '.$f.' atau lebih baharu, kemudian mulakan semula PHP-FPM':$v.' tidak lagi disokong — naik taraf ke PHP 8.2.35+'):$v.($f?' (dibaiki: '.$f.'+)':'')]];
    $rows[]=['session.upload_progress.enabled',$on('session.upload_progress.enabled')?'warn':'ok',$on('session.upload_progress.enabled')?'Aktif — disyorkan 0 dalam php.ini / pool PHP-FPM (bukan .user.ini); sistem ini tidak menggunakannya':'Dimatikan'];
    if(PHP_VERSION_ID>=80500) $rows[]=['fatal_error_backtraces',$on('fatal_error_backtraces')?'warn':'ok',$on('fatal_error_backtraces')?'Aktif — disyorkan 0 dalam php.ini / pool PHP-FPM':'Dimatikan'];
    $sapi=PHP_SAPI; $rows[]=['SAPI PHP',$sapi==='apache2handler'?'warn':'ok',$sapi.($sapi==='apache2handler'?' — mod_php berisiko tertinggi; PHP-FPM disyorkan':'')];
    $rows[]=['memory_limit','ok',(string)ini_get('memory_limit')];
    return $rows;
}

/* Semakan CVE MariaDB Oktober 2026 (16 Okt): CVE-2026-92262 / -85746 / -85748 (CVSS 9.9), -97380, -107814/-107818 (FILE),
 * -107815/-85985 (enjin CONNECT), -86047 (mariadb-dump) dan lain-lain. Semua memerlukan log masuk DB — risiko utama jika
 * kelayakan DB aplikasi bocor. Dibaiki dalam 10.6.28 / 10.11.19 / 11.4.13 / 11.8.9 / 12.3.3 / 13.0.2. Tidak terpakai pada MySQL. */
const DB_CVE_IDS='CVE MariaDB Okt 2026 (CVE-2026-92262, -85746, -85748, -107815 …)';
const DB_CVE_FIXED=['10.6'=>'10.6.28','10.11'=>'10.11.19','11.4'=>'11.4.13','11.8'=>'11.8.9','12.3'=>'12.3.3','13.0'=>'13.0.2'];
/** [enjin MariaDB|MySQL, versi x.y.z] daripada SELECT VERSION() (cth. "10.11.6-MariaDB-0+deb12u1", "5.5.5-10.6.19-MariaDB", "8.0.39"). */
function db_server_info(?string $raw=null): array {
    $raw??=(string)query('SELECT VERSION()')->fetchColumn();
    if(stripos($raw,'mariadb')!==false){ preg_match('/(\d+\.\d+\.\d+)-MariaDB/i',$raw,$m); return ['MariaDB',$m[1]??'0.0.0',$raw]; }
    preg_match('/^(\d+\.\d+\.\d+)/',$raw,$m); return ['MySQL',$m[1]??'0.0.0',$raw];
}
/** Status versi: [ok|bad, versi dibaiki atau null, catatan]. */
function db_cve_status(string $engine,string $version): array {
    if($engine!=='MariaDB') return ['ok',null,'CVE MariaDB Okt 2026 tidak terpakai pada MySQL; pastikan kemas kini Oracle Critical Patch Update terkini.'];
    $series=implode('.',array_slice(explode('.',$version),0,2)); $f=DB_CVE_FIXED[$series]??null;
    if($f!==null) return version_compare($version,$f,'<')?['bad',$f,'terdedah — naik taraf ke '.$f.' atau lebih baharu']:['ok',$f,'dibaiki: '.$f.'+'];
    if(version_compare($series,'13.0','>')) return ['ok',null,'siri lebih baharu daripada senarai pembaikan'];
    return ['bad',null,'siri '.$series.' tidak lagi menerima pembaikan keselamatan — naik taraf ke siri LTS (10.11.19+, 11.4.13+, 11.8.9+)'];
}
function db_cve_vulnerable(): bool { static $v=null; if($v!==null) return $v; try { [$e,$ver]=db_server_info(); return $v=db_cve_status($e,$ver)[0]==='bad'; } catch(Throwable){ return $v=false; } }
/** Baris semakan pangkalan data: [label, ok|warn|bad, butiran]. */
function db_cve_checks(): array {
    [$engine,$ver,$raw]=db_server_info(); [$st,,$note]=db_cve_status($engine,$ver);
    $rows=[['Versi '.$engine.' dibaiki',$st,$raw.' — '.$note.($st==='bad' && preg_match('/deb|ubuntu|el\d|rhel|alma|rocky/i',$raw)?' (pakej distro: semak changelog pakej untuk backport pembaikan)':'')]];
    // Keistimewaan global pengguna DB aplikasi: FILE / SUPER / ALL ON *.* membesarkan kesan CVE (tulis fail, ubah akaun lain).
    $grants=[]; try { $grants=query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN); } catch(Throwable){}
    $global=implode(' ',array_filter($grants,fn($g)=>preg_match('/\bON \*\.\* TO\b/i',(string)$g)));
    $risky=array_values(array_filter(['ALL PRIVILEGES','FILE','SUPER','CREATE USER','GRANT OPTION'],fn($p)=>stripos($global,$p)!==false));
    $rows[]=['Keistimewaan global pengguna DB aplikasi',$risky?(array_intersect($risky,['ALL PRIVILEGES','FILE','SUPER'])?'bad':'warn'):'ok',$grants?($risky?'Ada '.implode(', ',$risky).' pada *.* — hadkan kepada pangkalan data aplikasi sahaja (GRANT … ON nama_db.*)':'Tiada FILE / SUPER / CREATE USER pada *.*'):'Tidak dapat dibaca'];
    if($engine==='MariaDB'){
        $conn=''; try { $conn=(string)query('SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE="CONNECT"')->fetchColumn(); } catch(Throwable){}
        $rows[]=['Enjin CONNECT (CVE-2026-107815 / -85985)',in_array(strtoupper($conn),['YES','DEFAULT'],true)?'warn':'ok',in_array(strtoupper($conn),['YES','DEFAULT'],true)?'Dimuatkan — tidak digunakan sistem ini; UNINSTALL SONAME "ha_connect" disyorkan':'Tidak dimuatkan'];
    }
    $sfp=null; try { $sfp=query('SELECT @@secure_file_priv')->fetchColumn(); } catch(Throwable){}
    $rows[]=['secure_file_priv',$sfp===''?'warn':'ok',$sfp===null?'NULL — import/eksport fail dimatikan':($sfp===''?'Kosong — mana-mana folder dibenarkan; tetapkan folder khusus atau NULL':(string)$sfp)];
    $rows[]=['Pulih sandaran .sql','ok','CVE-2026-86047: pulihkan hanya fail sandaran yang dijana sendiri sehingga pelayan DB dikemas kini'];
    return $rows;
}
