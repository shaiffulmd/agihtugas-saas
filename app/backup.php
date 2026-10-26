<?php
declare(strict_types=1);
/* Sandaran (backup) automatik ke Google Drive.
 *  - Semua data & gambar (laporan, galeri, logo, avatar) berada dalam pangkalan data, jadi sandaran = dump MySQL.
 *  - Dump dijana dalam PHP (tiada SSH / mysqldump), dimampat (gzip) dan DISULITKAN sebelum keluar dari pelayan:
 *    libsodium secretstream (XChaCha20-Poly1305), kunci daripada kata laluan sandaran (Argon2id). Google tidak boleh membacanya.
 *  - Akaun Google: Gmail (@gmail.com) atau Workspace sekolah. OAuth 2.0 dengan skop drive.file sahaja:
 *    sistem hanya nampak fail yang ia cipta sendiri, bukan keseluruhan Drive.
 *  - Jadual tanpa cron: dicetuskan selepas respons apabila pentadbir log masuk atau pemantau uptime (sihat.php?k=…)
 *    menghubungi sistem. Cron pilihan: php bin/backup.php. Pulih: php bin/restore.php FAIL.kbak
 *  - Semua tetapan dalam jadual settings (kunci sys_backup_*) — tiada perubahan skema. */

const BACKUP_SCOPE='https://www.googleapis.com/auth/drive.file openid email';
const BACKUP_GMAIL_DOMAINS=['gmail.com','googlemail.com'];
const BACKUP_ACCOUNT_TYPES=[
    'gmail'=>['Gmail (@gmail.com)','Akaun Google biasa. Paling mudah: tiada pentadbir yang boleh menyekat. Disyorkan akaun Gmail khas untuk sandaran sekolah. Kuota percuma 15 GB.'],
    'workspace'=>['Google Workspace sekolah','Akaun Google rasmi sekolah dengan domain sendiri (cth. @sekolah.edu.my). Projek Google Cloud boleh ditetapkan "Internal".'],
];
const BACKUP_POLICY_MSG='Pentadbir Google Workspace menyekat akaun ini daripada memberi akses kepada aplikasi luar. Minta pentadbir Workspace sekolah menandakan Client ID ini sebagai "Trusted" (Admin console → Security → Access and data control → API controls → Manage Third-Party App Access), atau gunakan akaun Gmail.';
const BACKUP_MAGIC='URUSBAK1';
const BACKUP_CHUNK=1048576;          // saiz blok penyulitan (1 MB)
const BACKUP_UPLOAD_CHUNK=8388608;   // saiz blok muat naik boleh-sambung (8 MB, gandaan 256 KB)
const BACKUP_INTERVAL=86400;         // sekali sehari
const BACKUP_RETRY=10800;            // cuba semula 3 jam selepas gagal

/* ---------- Simpanan tetapan ---------- */
function bk_get(string $k): string { return sys_get('backup_'.$k); }
function bk_set(string $k,string $v): void { sys_set('backup_'.$k,$v); }
function bk_cfg(): array { $c=json_decode(bk_get('cfg'),true); $c=is_array($c)?$c:[]; if(isset($c['type']) && !isset(BACKUP_ACCOUNT_TYPES[$c['type']])) $c['type']='gmail'; return $c+['client_id'=>'','client_secret'=>'','type'=>'gmail','domain'=>'','auto'=>'1','keep_daily'=>7,'keep_weekly'=>4]; }
function bk_secret(array $c): string { return $c['client_secret']!==''?decrypt_value($c['client_secret']):''; }
function bk_kdf(): ?array { $k=json_decode(bk_get('kdf'),true); return is_array($k) && isset($k['salt'],$k['key'],$k['ops'],$k['mem'])?$k:null; }
function bk_token(): ?array { $t=json_decode(bk_get('token'),true); return is_array($t) && !empty($t['refresh'])?$t:null; }
function bk_last(): ?array { $l=json_decode(bk_get('last'),true); return is_array($l)?$l:null; }
/** Edisi multi: sandaran ialah urusan platform; alamat kembali Google di domain induk (PLATFORM_DOMAIN). */
function bk_redirect_uri(): ?string { return platform_domain()!==''?tenant_scheme().'://'.platform_domain().'/platform/?p=sandaran_oauth':null; }
function bk_ready(): bool { return bk_cfg()['client_id']!=='' && bk_kdf()!==null && bk_token()!==null; }
function bk_bytes(?int $b): string { if($b===null) return '—'; foreach(['B','KB','MB','GB'] as $u){ if($b<1024) return number_format($b,$u==='B'?0:1).' '.$u; $b/=1024; } return number_format($b,1).' TB'; }
function bk_ago(int $ts): string { $d=time()-$ts; if($d<90) return 'baru sahaja'; if($d<3600) return intdiv($d,60).' minit lalu'; if($d<172800) return intdiv($d,3600).' jam lalu'; return intdiv($d,86400).' hari lalu'; }
function bk_audit(?int $uid,string $action): void {
    try { audit_append(0,['admin_id'=>$uid,'admin_ref'=>$uid,'action'=>$action,'detail'=>'sandaran','ip_hash'=>hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'cli',key_bytes())]); } catch(Throwable) {}
}

/* ---------- HTTP ke Google (curl, TLS disahkan, HTTPS sahaja) ---------- */
function bk_http(string $method,string $url,array $headers=[],?string $body=null,int $timeout=60): array {
    if(!function_exists('curl_init')) throw new RuntimeException('Sambungan PHP curl diperlukan untuk Google Drive. Aktifkan di cPanel → Select PHP Version → Extensions.');
    $ch=curl_init($url); $rh=[];
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>15,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION=>function($c,string $h) use(&$rh){ $p=explode(':',$h,2); if(count($p)===2) $rh[strtolower(trim($p[0]))]=trim($p[1]); return strlen($h); }]);
    if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $out=curl_exec($ch);
    if($out===false){ $err=curl_error($ch); curl_close($ch); throw new RuntimeException('Sambungan ke Google gagal: '.$err); }
    $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$code,(string)$out,$rh];
}
function bk_json(array $r): array { $j=json_decode($r[1],true); return is_array($j)?$j:[]; }
function bk_gerr(array $r): string {
    $j=bk_json($r); $m=$j['error']['message']??($j['error_description']??($j['error']??''));
    if(($j['error']??'')==='invalid_client') $m='Client ID atau Client Secret salah.';
    if(($j['error']??'')==='redirect_uri_mismatch') $m='Authorized redirect URI dalam Google Cloud tidak sama dengan URI yang dipaparkan di halaman Sandaran.';
    return 'Google ('.$r[0].'): '.(is_string($m) && $m!==''?$m:'ralat tidak diketahui');
}
function bk_access_token(): string {
    static $tok=null; if($tok && $tok[1]>time()+60) return $tok[0];
    $c=bk_cfg(); $t=bk_token(); if(!$t) throw new DomainException('Google Drive belum disambungkan.');
    $r=bk_http('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id'=>$c['client_id'],'client_secret'=>bk_secret($c),'refresh_token'=>decrypt_value($t['refresh']),'grant_type'=>'refresh_token']));
    $j=bk_json($r);
    if($r[0]!==200 || empty($j['access_token'])){
        if(($j['error']??'')==='invalid_grant') throw new DomainException('Kebenaran Google Drive telah tamat atau ditarik balik. Sambung semula Google Drive. (Jika projek Google Cloud masih berstatus "Testing", tukar kepada "In production" — token Testing tamat selepas 7 hari.)');
        throw new RuntimeException(bk_gerr($r));
    }
    $tok=[(string)$j['access_token'],time()+(int)($j['expires_in']??3600)]; return $tok[0];
}
function bk_api(string $method,string $url,?array $json=null,array $extra=[]): array {
    $h=array_merge(['Authorization: Bearer '.bk_access_token()],$extra); $body=null;
    if($json!==null){ $h[]='Content-Type: application/json; charset=UTF-8'; $body=json_encode($json,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    elseif(in_array($method,['POST','PUT','PATCH'],true)){ $h[]='Content-Length: 0'; $body=''; }
    return bk_http($method,$url,$h,$body);
}
function bk_revoke(string $token): void { try { bk_http('POST','https://oauth2.googleapis.com/revoke',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['token'=>$token]),15); } catch(Throwable) {} }
function bk_jwt_claims(string $jwt): array {
    $p=explode('.',$jwt); if(count($p)<2) return [];
    $j=json_decode((string)base64_decode(strtr($p[1],'-_','+/').str_repeat('=',(4-strlen($p[1])%4)%4),true),true); return is_array($j)?$j:[];
}

/* ---------- Google Drive ---------- */
function bk_folder(): string {
    $t=bk_token(); if(!$t) throw new DomainException('Google Drive belum disambungkan.');
    $id=(string)($t['folder']??'');
    if($id!==''){ $r=bk_api('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?fields=id,trashed'); $j=bk_json($r); if($r[0]===200 && empty($j['trashed'])) return $id; }
    $r=bk_api('POST','https://www.googleapis.com/drive/v3/files?fields=id',['name'=>'Sandaran UrusTugas — '.school_name(),'mimeType'=>'application/vnd.google-apps.folder','description'=>'Sandaran pangkalan data tersulit daripada Sekolah Koku. Jangan ubah nama fail.']);
    $j=bk_json($r); if($r[0]!==200 || empty($j['id'])) throw new RuntimeException(bk_gerr($r));
    $t['folder']=(string)$j['id']; bk_set('token',json_encode($t)); return $t['folder'];
}
function bk_list(string $folder): array {
    $q="'".str_replace(['\\',"'"],['\\\\',"\\'"],$folder)."' in parents and trashed=false";
    $r=bk_api('GET','https://www.googleapis.com/drive/v3/files?'.http_build_query(['q'=>$q,'fields'=>'files(id,name,size,createdTime,webViewLink)','orderBy'=>'createdTime desc','pageSize'=>200,'spaces'=>'drive']));
    if($r[0]!==200) throw new RuntimeException(bk_gerr($r));
    return array_values(array_filter(bk_json($r)['files']??[],fn($f)=>preg_match('/^urustugas-sandaran-\d{8}-\d{6}\.kbak$/D',(string)($f['name']??''))));
}
/** Muat naik boleh-sambung (resumable) dalam blok 8 MB, dengan cubaan semula untuk ralat 5xx. */
function bk_upload(string $path,string $name,string $folder): array {
    $size=(int)filesize($path);
    $r=bk_api('POST','https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,name,size,webViewLink',
        ['name'=>$name,'parents'=>[$folder],'mimeType'=>'application/octet-stream','description'=>'Sandaran tersulit UrusTugas ('.school_name().'). Pulihkan dengan: php bin/restore.php '.$name],
        ['X-Upload-Content-Type: application/octet-stream','X-Upload-Content-Length: '.$size]);
    $loc=(string)($r[2]['location']??''); if($r[0]!==200 || !str_starts_with($loc,'https://')) throw new RuntimeException(bk_gerr($r));
    $fh=fopen($path,'rb'); $off=0; $guard=0; $max=intdiv($size,BACKUP_UPLOAD_CHUNK)*3+10;
    try {
        while($off<$size){
            if(++$guard>$max) throw new RuntimeException('Muat naik tidak dapat diselesaikan (terlalu banyak cubaan).');
            fseek($fh,$off); $chunk=(string)fread($fh,BACKUP_UPLOAD_CHUNK); $len=strlen($chunk);
            for($try=1;;$try++){
                try { $res=bk_http('PUT',$loc,['Content-Length: '.$len,'Content-Range: bytes '.$off.'-'.($off+$len-1).'/'.$size],$chunk,300); }
                catch(RuntimeException $ex){ if($try>=3) throw $ex; sleep(3*$try); continue; }
                if($res[0]<500 || $try>=3) break; sleep(3*$try);
            }
            if($res[0]===308){ $off=preg_match('/bytes=0-(\d+)/',(string)($res[2]['range']??''),$m)?(int)$m[1]+1:0; continue; }
            if($res[0]===200 || $res[0]===201) return bk_json($res);
            throw new RuntimeException(bk_gerr($res));
        }
    } finally { fclose($fh); }
    throw new RuntimeException('Muat naik tidak lengkap.');
}
/** Simpan N sandaran terbaharu + satu sandaran bagi setiap minggu (W minggu). Fail lain dibuang. */
function bk_prune(string $folder): int {
    $c=bk_cfg(); $files=bk_list($folder); $keepD=max(3,(int)$c['keep_daily']); $keepW=max(0,(int)$c['keep_weekly']); $keep=[]; $weeks=[];
    foreach($files as $i=>$f){
        if($i<$keepD){ $keep[$f['id']]=true; continue; }
        $wk=date('o-W',(int)strtotime((string)$f['createdTime']));
        if(!isset($weeks[$wk]) && count($weeks)<$keepW){ $weeks[$wk]=true; $keep[$f['id']]=true; }
    }
    $n=0; foreach($files as $f) if(!isset($keep[$f['id']])){ $r=bk_api('DELETE','https://www.googleapis.com/drive/v3/files/'.rawurlencode((string)$f['id'])); if(in_array($r[0],[200,204],true)) $n++; }
    return $n;
}

/* ---------- Dump pangkalan data (PHP tulen, tanpa penimbal penuh) ---------- */
function bk_pdo(): PDO {
    $buf=defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')?constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY'):PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
    return new PDO('mysql:host='.env('DB_HOST','127.0.0.1').';port='.env('DB_PORT','3306').';dbname='.env('DB_NAME','urustugas').';charset=utf8mb4',env('DB_USER'),env('DB_PASS'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,$buf=>false]);
}
/** Satu pernyataan SQL bagi setiap baris (nilai teks di-escape, binari sebagai hex) supaya bin/restore.php boleh membaca baris demi baris. */
function bk_dump(string $gzPath): array {
    $pdo=bk_pdo(); $gz=gzopen($gzPath,'wb6'); if(!$gz) throw new RuntimeException('Tidak dapat menulis fail sementara sandaran.');
    $tables=0; $rows=0;
    try {
        gzwrite($gz,"-- UrusTugas · sandaran pangkalan data\n-- Versi ".(defined('APP_VERSION')?APP_VERSION:'?').' · '.date('c').' · '.str_replace(["\r","\n"],' ',school_name())."\n-- Pulihkan pada pangkalan data KOSONG: php bin/restore.php FAIL.kbak\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        $list=$pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE="BASE TABLE" ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
        foreach($list as $t){
            $q='`'.str_replace('`','``',(string)$t).'`';
            $st=$pdo->query('SHOW CREATE TABLE '.$q); $create=(string)$st->fetch(PDO::FETCH_NUM)[1]; $st->closeCursor();
            gzwrite($gz,'DROP TABLE IF EXISTS '.$q.";\n".preg_replace('/\s*\R\s*/',' ',$create).";\n");
            $st=$pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION'); $st->execute([$t]); $cols=$st->fetchAll(PDO::FETCH_NUM);
            $isBin=array_map(fn($c)=>in_array(strtolower((string)$c[1]),['tinyblob','blob','mediumblob','longblob','binary','varbinary'],true),$cols);
            $tables++; if($t==='rate_limits') continue; // data sementara
            $colList='('.implode(',',array_map(fn($c)=>'`'.str_replace('`','``',(string)$c[0]).'`',$cols)).')';
            $st=$pdo->query('SELECT * FROM '.$q); $buf='';
            while(($r=$st->fetch(PDO::FETCH_NUM))!==false){
                $vals=[]; foreach($r as $i=>$v) $vals[]=$v===null?'NULL':(is_int($v) || is_float($v)?(string)$v:(($isBin[$i]??false)?($v===''?"''":'0x'.bin2hex((string)$v)):$pdo->quote((string)$v)));
                $tuple='('.implode(',',$vals).')';
                if($buf!=='' && strlen($buf)+strlen($tuple)>1048576){ gzwrite($gz,'INSERT INTO '.$q.' '.$colList.' VALUES '.$buf.";\n"); $buf=''; }
                $buf.=($buf===''?'':',').$tuple; $rows++;
            }
            $st->closeCursor(); if($buf!=='') gzwrite($gz,'INSERT INTO '.$q.' '.$colList.' VALUES '.$buf.";\n");
        }
        gzwrite($gz,"SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- SELESAI\n");
    } finally { gzclose($gz); }
    return ['tables'=>$tables,'rows'=>$rows];
}

/* ---------- Penyulitan fail (.kbak) ----------
 * Format: "URUSBAK1" | opslimit (u32) | memlimit (u32) | garam (16) | kepala secretstream (24) | [panjang u32 | blok]…
 * Kunci = Argon2id(kata laluan sandaran, garam). Blok terakhir bertanda FINAL: fail terpotong/diusik ditolak. */
function bk_set_pass(string $pass): void {
    $salt=random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES); $ops=SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE; $mem=SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
    $key=sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,$pass,$salt,$ops,$mem,SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    bk_set('kdf',json_encode(['salt'=>base64_encode($salt),'ops'=>$ops,'mem'=>$mem,'key'=>encrypt_value($key),'set'=>date('c')]));
    sodium_memzero($key);
}
function bk_encrypt_file(string $in,string $out,array $kdf): void {
    $key=decrypt_value($kdf['key']); [$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key); sodium_memzero($key);
    $fi=fopen($in,'rb'); $fo=fopen($out,'wb'); if(!$fi || !$fo) throw new RuntimeException('Tidak dapat menulis fail sandaran.');
    try {
        fwrite($fo,BACKUP_MAGIC.pack('NN',(int)$kdf['ops'],(int)$kdf['mem']).base64_decode((string)$kdf['salt']).$header);
        $size=(int)filesize($in); $read=0;
        do { $chunk=$size>0?(string)fread($fi,BACKUP_CHUNK):''; $read+=strlen($chunk); $final=$read>=$size || feof($fi);
            $c=sodium_crypto_secretstream_xchacha20poly1305_push($state,$chunk,'',$final?SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL:SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            fwrite($fo,pack('N',strlen($c)).$c);
        } while(!$final);
    } finally { fclose($fi); fclose($fo); }
}
function bk_read_exact($fh,int $n): string { $s=''; while(strlen($s)<$n && !feof($fh)){ $p=fread($fh,$n-strlen($s)); if($p===false || $p==='') break; $s.=$p; } return $s; }
function bk_decrypt_file(string $in,string $out,string $pass): void {
    $fi=@fopen($in,'rb'); if(!$fi) throw new RuntimeException('Fail sandaran tidak dapat dibuka.');
    try {
        $head=bk_read_exact($fi,56);
        if(strlen($head)!==56 || substr($head,0,8)!==BACKUP_MAGIC) throw new RuntimeException('Bukan fail sandaran UrusTugas (.kbak).');
        ['o'=>$ops,'m'=>$mem]=unpack('No/Nm',substr($head,8,8));
        if($ops<1 || $ops>20 || $mem<8192 || $mem>1073741824) throw new RuntimeException('Kepala fail sandaran rosak.');
        $key=sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,$pass,substr($head,16,16),$ops,$mem,SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $state=sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($head,32,24),$key); sodium_memzero($key);
        $fo=fopen($out,'wb'); $done=false;
        try {
            while(!$done){
                $l=bk_read_exact($fi,4); if(strlen($l)!==4) throw new RuntimeException('Fail sandaran terpotong (tidak lengkap).');
                $n=unpack('N',$l)[1]; if($n<SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $n>BACKUP_CHUNK+SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) throw new RuntimeException('Fail sandaran rosak.');
                $c=bk_read_exact($fi,$n); if(strlen($c)!==$n) throw new RuntimeException('Fail sandaran terpotong (tidak lengkap).');
                $r=sodium_crypto_secretstream_xchacha20poly1305_pull($state,$c);
                if($r===false) throw new RuntimeException('Kata laluan sandaran salah, atau fail telah rosak/diubah.');
                fwrite($fo,$r[0]); $done=$r[1]===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
        } finally { fclose($fo); }
    } finally { fclose($fi); }
}

/* ---------- Jana & jalankan sandaran ---------- */
function bk_dir(): string {
    $d=dirname(__DIR__).'/storage/backup'; if(!is_dir($d) && !mkdir($d,0700,true) && !is_dir($d)) throw new RuntimeException('Folder storage/backup tidak dapat dicipta.');
    foreach(glob($d.'/*')?:[] as $f) if(is_file($f) && filemtime($f)<time()-86400) @unlink($f); // sisa sandaran lama yang terputus
    return $d;
}
/** Jana fail .kbak setempat: [laluan, nama, meta]. Pemanggil mesti memadam fail selepas digunakan. */
function bk_make(): array {
    $kdf=bk_kdf(); if(!$kdf) throw new DomainException('Tetapkan kata laluan sandaran dahulu.');
    $dir=bk_dir(); $name='urustugas-sandaran-'.date('Ymd-His').'.kbak'; $gz=$dir.'/'.bin2hex(random_bytes(8)).'.sql.gz'; $out=$dir.'/'.bin2hex(random_bytes(8)).'.kbak';
    try { $meta=bk_dump($gz); bk_encrypt_file($gz,$out,$kdf); } catch(Throwable $ex){ @unlink($out); throw $ex; } finally { @unlink($gz); }
    return [$out,$name,$meta+['size'=>(int)filesize($out)]];
}
/** Sandaran penuh ke Google Drive. Tidak melontar: pulangkan keputusan dan rekod dalam settings + audit. */
function backup_run(string $source): array {
    @set_time_limit(0); @ignore_user_abort(true);
    $lockPath=dirname(__DIR__).'/storage/backup.lck'; $lock=@fopen($lockPath,'c');
    if(!$lock) return ['ok'=>false,'time'=>time(),'source'=>$source,'error'=>'Tidak dapat menulis storage/backup.lck — semak kebenaran tulis folder storage/.'];
    if(!flock($lock,LOCK_EX|LOCK_NB)){ fclose($lock); return ['ok'=>false,'error'=>'Sandaran lain sedang berjalan. Cuba sebentar lagi.','busy'=>true]; }
    $t0=microtime(true); $path=null;
    try {
        if(!bk_ready()) throw new DomainException('Sandaran belum lengkap disediakan (Client ID, kata laluan sandaran dan sambungan Google Drive).');
        [$path,$name,$meta]=bk_make();
        $folder=bk_folder(); $f=bk_upload($path,$name,$folder);
        if((int)($f['size']??-1)!==$meta['size']) throw new RuntimeException('Saiz fail di Google Drive tidak sepadan dengan fail yang dihantar.');
        $pruned=0; try { $pruned=bk_prune($folder); } catch(Throwable $ex){ error_log('Sekolah backup prune: '.$ex->getMessage()); }
        $res=['ok'=>true,'time'=>time(),'source'=>$source,'name'=>$name,'size'=>$meta['size'],'tables'=>$meta['tables'],'rows'=>$meta['rows'],'secs'=>round(microtime(true)-$t0,1),'link'=>(string)($f['webViewLink']??''),'pruned'=>$pruned];
        bk_set('last',json_encode($res,JSON_UNESCAPED_SLASHES)); bk_set('last_ok',(string)time()); audit('backup_ok','system');
        return $res;
    } catch(Throwable $ex){
        $msg=$ex instanceof DomainException || str_starts_with($ex->getMessage(),'Google') || str_starts_with($ex->getMessage(),'Sambungan')?$ex->getMessage():'Ralat semasa sandaran: '.$ex->getMessage();
        $res=['ok'=>false,'time'=>time(),'source'=>$source,'error'=>mb_substr($msg,0,400)];
        bk_set('last',json_encode($res,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); audit('backup_failed','system'); error_log('Sekolah backup: '.$ex->getMessage());
        return $res;
    } finally { if($path) @unlink($path); flock($lock,LOCK_UN); fclose($lock); }
}
/** Sandaran berkala (malas): sekali sehari, atau 3 jam selepas kegagalan. Dijalankan selepas respons dihantar. */
function backup_lazy(): void {
    try {
        if(function_exists('demo_mode') && demo_mode()) return;
        if(bk_cfg()['auto']!=='1' || !bk_ready()) return;
        $last=bk_last(); $okAt=(int)bk_get('last_ok');
        $due=($last && empty($last['ok']))?(int)($last['time']??0)<time()-BACKUP_RETRY:$okAt<time()-BACKUP_INTERVAL;
        if(!$due || (int)bk_get('claim')>time()-1800) return;
        bk_set('claim',(string)time());
        after_response(fn()=>backup_run('auto'));
    } catch(Throwable $ex){ error_log('Sekolah backup lazy: '.$ex->getMessage()); }
}
/** Sandaran automatik gagal atau lapuk (> 2 hari)? Untuk amaran pentadbir & sihat.php. */
function backup_stale(): bool {
    try { if(bk_cfg()['auto']!=='1' || !bk_ready()) return false; $l=bk_last(); if(!$l) return false; if(empty($l['ok'])) return true; return (int)bk_get('last_ok')<time()-2*86400; } catch(Throwable) { return false; }
}
function backup_alert(): ?string {
    if(!backup_stale()) return null; $l=bk_last();
    return !empty($l['ok'])?'Tiada sandaran berjaya sejak '.date('d/m/Y H:i',(int)bk_get('last_ok')).'.':'Sandaran terakhir gagal: '.($l['error']??'ralat tidak diketahui');
}

/* ---------- OAuth 2.0 ---------- */
function bk_state_key(): string { return hash_hkdf('sha256',platform_key(),32,'urustugas/sandaran-oauth'); }
/** GET /platform/?p=sandaran&sambung=1 → ke skrin kebenaran Google. (Pautan, bukan borang: CSP form-action menyekat ubah hala borang ke domain luar.) */
function bk_oauth_start(): never {
    require_platform_admin();
    if(function_exists('demo_mode') && demo_mode()){ flash('Mod demo: sambungan Google Drive dimatikan.'); redirect('/platform/?p=sandaran'); }
    $c=bk_cfg(); $uri=bk_redirect_uri();
    if($c['client_id']==='' || bk_secret($c)==='' || !$uri){ flash('Lengkapkan Langkah 1 (URL laman, Client ID & Client Secret) dahulu.'); redirect('/platform/?p=sandaran'); }
    $n=bin2hex(random_bytes(16)); $uid=(int)platform_user()['id']; $exp=time()+600;
    bk_set('oauth_state',json_encode(['n'=>hash('sha256',$n),'u'=>$uid,'e'=>$exp]));
    $state=$n.'.'.$uid.'.'.$exp.'.'.hash_hmac('sha256',$n.'.'.$uid.'.'.$exp,bk_state_key());
    $hd=$c['type']==='workspace'?(string)$c['domain']:'';
    $q=['client_id'=>$c['client_id'],'redirect_uri'=>$uri,'response_type'=>'code','scope'=>BACKUP_SCOPE,'access_type'=>'offline','prompt'=>'consent select_account','state'=>$state]+($hd!==''?['hd'=>$hd]:[]);
    audit('backup_connect_start','system');
    redirect('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($q));
}
/** Halaman ringkas selepas kembali dari Google. Pautan "Teruskan" ialah navigasi tapak sama, jadi kuki sesi SameSite=Strict dihantar semula. */
function bk_cb_page(bool $ok,string $title,string $text): never {
    while(ob_get_level()>0) @ob_end_clean();
    if(!headers_sent()){ http_response_code($ok?200:400); header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); }
    $theme=''; try { $theme='<link rel="stylesheet" href="'.e(theme_css_url()).'">'; } catch(Throwable) {}
    echo '<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>'.e($title).'</title><link rel="stylesheet" href="'.e(asset('app.css')).'">'.$theme.'</head>'
        .'<body class="error-body"><main class="error-shell"><section class="error-card"><span class="eyebrow teal">SANDARAN · GOOGLE DRIVE</span><h1>'.($ok?'✓ ':'').e($title).'</h1><p class="error-text">'.e($text).'</p>'
        .'<div class="error-actions"><a class="btn primary" href="/platform/?p=sandaran">Teruskan ke halaman Sandaran →</a></div></section></main></body></html>';
    exit;
}
/** /platform/?p=sandaran_oauth — dijalankan TANPA sesi (NO_SESSION): keadaan disahkan melalui token bertandatangan + nonce sekali guna di pelayan. */
function bk_oauth_callback(): never {
    $fail=function(string $m): never { bk_cb_page(false,'Sambungan Google Drive gagal',$m); };
    try {
        $parts=explode('.',input('state','get'));
        if(count($parts)!==4) $fail('Pautan tidak sah. Mulakan semula dari halaman Sandaran.');
        [$n,$u,$e,$sig]=$parts; $srv=json_decode(bk_get('oauth_state'),true);
        $valid=hash_equals(hash_hmac('sha256',$n.'.'.$u.'.'.$e,bk_state_key()),$sig) && (int)$e>=time() && is_array($srv)
            && hash_equals((string)($srv['n']??''),hash('sha256',$n)) && (int)($srv['u']??0)===(int)$u;
        if(!$valid) $fail('Sesi sambungan telah tamat atau digunakan (had 10 minit). Mulakan semula dari halaman Sandaran.');
        bk_set('oauth_state',''); $uid=(int)$u;
        if(!query('SELECT 1 FROM platform_admins WHERE id=? AND active=1',[$uid])->fetchColumn()) $fail('Akaun pentadbir platform tidak sah.');
        $err=input('error','get');
        if($err!==''){ bk_audit($uid,'backup_connect_failed'); $fail(match($err){ 'access_denied'=>'Kebenaran tidak diberikan. Klik "Benarkan / Allow" pada skrin Google untuk menyambung.', 'admin_policy_enforced'=>BACKUP_POLICY_MSG, 'org_internal'=>'Projek Google Cloud ditetapkan "Internal", jadi akaun Gmail biasa tidak dibenarkan. Di Google Cloud → Audience, tukar kepada External dan klik Publish app → In production.', default=>'Google memulangkan ralat: '.mb_substr($err,0,80) }); }
        $c=bk_cfg();
        $r=bk_http('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['code'=>input('code','get'),'client_id'=>$c['client_id'],'client_secret'=>bk_secret($c),'redirect_uri'=>(string)bk_redirect_uri(),'grant_type'=>'authorization_code']));
        $j=bk_json($r);
        if($r[0]!==200 || empty($j['access_token'])){ bk_audit($uid,'backup_connect_failed'); $fail(bk_gerr($r)); }
        if(empty($j['refresh_token'])) $fail('Google tidak memberi token kekal. Buang akses aplikasi ini di myaccount.google.com/permissions, kemudian sambung semula.');
        if(!str_contains(' '.($j['scope']??'').' ',' https://www.googleapis.com/auth/drive.file ')){ bk_revoke((string)$j['refresh_token']); $fail('Akses Google Drive tidak diberikan. Pada skrin kebenaran Google, pastikan kotak akses Google Drive ditanda.'); }
        $email=strtolower((string)(bk_jwt_claims((string)($j['id_token']??''))['email']??''));
        $domain=(string)substr((string)strrchr($email,'@'),1);
        $allowed=$c['type']==='gmail'?BACKUP_GMAIL_DOMAINS:($c['domain']!==''?[(string)$c['domain']]:[]);
        if($email==='' || ($allowed && !in_array($domain,$allowed,true))){ bk_revoke((string)$j['refresh_token']); bk_audit($uid,'backup_connect_failed'); $fail('Akaun '.($email?:'ini').' bukan akaun '.($c['type']==='gmail'?'@gmail.com':'@'.$c['domain']).'. Klik Teruskan, kemudian sambung semula dan pilih akaun yang betul.'); }
        $old=bk_token();
        bk_set('token',json_encode(['refresh'=>encrypt_value((string)$j['refresh_token']),'email'=>$email,'connected'=>date('c'),'by'=>$uid,'folder'=>($old && ($old['email']??'')===$email)?(string)($old['folder']??''):'']));
        if($old) bk_revoke(decrypt_value($old['refresh']));
        bk_audit($uid,'backup_connected');
        bk_cb_page(true,'Google Drive disambungkan','Sandaran akan disimpan dalam Google Drive '.$email.' (folder "Sandaran UrusTugas"). Klik Teruskan, kemudian tekan "Sandaran sekarang" untuk menguji.');
    } catch(Throwable $ex){ error_log('Sekolah backup oauth: '.$ex->getMessage()); $fail('Ralat: '.$ex->getMessage()); }
}

/* ---------- Tindakan pentadbir ---------- */
function handle_backup(string $action): never {
    require_platform_admin();
    if($action==='backup_cfg_save'){
        $c=bk_cfg(); $old=$c['client_id'];
        $id=trim(input('client_id')); if($id!=='' && !preg_match('/^[0-9]{6,30}-[a-z0-9]{10,64}\.apps\.googleusercontent\.com$/D',$id)) throw new DomainException('Client ID tidak sah. Contoh: 1234567890-abcd….apps.googleusercontent.com');
        $sec=trim(input('client_secret')); if($sec!=='' && !preg_match('/^[A-Za-z0-9_-]{8,100}$/D',$sec)) throw new DomainException('Client Secret tidak sah.');
        $type=input('type'); if(!isset(BACKUP_ACCOUNT_TYPES[$type])) throw new DomainException('Jenis akaun tidak sah.');
        $dom=strtolower(trim(ltrim(trim(input('domain')),'@'))); if($dom!=='' && !preg_match('/^(?=.{3,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/D',$dom)) throw new DomainException('Domain tidak sah. Contoh: smkmuadzam.edu.my');
        if($type==='workspace' && in_array($dom,BACKUP_GMAIL_DOMAINS,true)){ $type='gmail'; $dom=''; }
        $kd=(int)input('keep_daily'); $kw=(int)input('keep_weekly');
        if(!in_array($kd,[7,14,30],true) || !in_array($kw,[0,4,8,12],true)) throw new DomainException('Tempoh simpanan tidak sah.');
        $c=['client_id'=>$id,'client_secret'=>$sec!==''?encrypt_value($sec):($id===$old?$c['client_secret']:''),'type'=>$type,'domain'=>$type==='gmail'?'':$dom,'auto'=>input('auto')==='1'?'1':'0','keep_daily'=>$kd,'keep_weekly'=>$kw];
        bk_set('cfg',json_encode($c,JSON_UNESCAPED_SLASHES));
        $msg='Tetapan sandaran disimpan.';
        if($id!==$old && ($t=bk_token())){ bk_revoke(decrypt_value($t['refresh'])); bk_set('token',''); $msg.=' Client ID bertukar: sambung semula Google Drive.'; }
        audit('backup_cfg','settings'); flash($msg); redirect('/platform/?p=sandaran');
    }
    if($action==='backup_pass_save'){
        platform_confirm_password();
        $p=input('backup_pass'); if(!valid_password($p)) throw new DomainException('Kata laluan sandaran mesti 12 hingga 128 aksara.');
        if(!hash_equals($p,input('backup_pass_confirm'))) throw new DomainException('Pengesahan kata laluan sandaran tidak sepadan.');
        if(password_verify($p,(string)query('SELECT password_hash FROM platform_admins WHERE id=?',[platform_user()['id']])->fetchColumn())) throw new DomainException('Jangan guna kata laluan log masuk anda sebagai kata laluan sandaran.');
        if(bk_kdf() && input('pass_ack')!=='yes') throw new DomainException('Tandakan pengesahan: sandaran lama kekal menggunakan kata laluan lama.');
        bk_set_pass($p); audit('backup_pass','settings');
        flash('Kata laluan sandaran disimpan. Catat dan simpan di tempat selamat (contoh: pengurus kata laluan atau sampul bermeterai di pejabat) — tanpanya sandaran TIDAK boleh dipulihkan.'); redirect('/platform/?p=sandaran');
    }
    if($action==='backup_run'){
        $r=backup_run('manual');
        if($r['ok']) flash('Sandaran berjaya dimuat naik ke Google Drive: '.$r['name'].' ('.bk_bytes((int)$r['size']).', '.$r['secs'].' saat).'.($r['pruned']?' '.$r['pruned'].' sandaran lama dibuang mengikut tempoh simpanan.':''));
        else throw new DomainException('Sandaran gagal: '.$r['error']);
        redirect('/platform/?p=sandaran');
    }
    if($action==='backup_download'){
        platform_confirm_password(); @set_time_limit(0);
        [$path,$name]=bk_make();
        try { audit('backup_download','system'); while(ob_get_level()>0) @ob_end_clean();
            header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="'.$name.'"'); header('Content-Length: '.filesize($path)); readfile($path);
        } finally { @unlink($path); }
        exit;
    }
    if($action==='backup_disconnect'){
        platform_confirm_password(); $t=bk_token(); if($t) bk_revoke(decrypt_value($t['refresh']));
        bk_set('token',''); audit('backup_disconnected','system');
        flash('Google Drive diputuskan. Fail sandaran yang sedia ada di Drive tidak dipadam.'); redirect('/platform/?p=sandaran');
    }
    throw new DomainException('Tindakan tidak sah.');
}

/* ---------- Halaman ---------- */
function sandaran_page(): void {
    $c=bk_cfg(); $kdf=bk_kdf(); $tok=bk_token(); $last=bk_last(); $uri=bk_redirect_uri(); $okAt=(int)bk_get('last_ok');
    $curl=function_exists('curl_init'); $hasSecret=$c['client_secret']!==''; $step1=$c['client_id']!=='' && $hasSecret && $uri;
    $files=null; $listErr=null;
    if($tok){ try { $files=bk_list(bk_folder()); } catch(Throwable $ex){ $listErr=$ex->getMessage(); } }
    $folderUrl=$tok && !empty(bk_token()['folder'])?'https://drive.google.com/drive/folders/'.rawurlencode((string)bk_token()['folder']):null;
    ?><div class="page-heading"><div><span class="eyebrow teal">SISTEM</span><h1>Sandaran Google Drive</h1><p class="muted">Sandaran harian automatik pangkalan data <strong>semua sekolah</strong> ke Google Drive platform — disulitkan sebelum meninggalkan pelayan.</p></div></div>
    <?php if(demo_mode()) notice('Mod demo: sandaran dan sambungan Google Drive dimatikan.'); ?>
    <?php if(!$curl): ?><div class="notice error"><?= icon('alert') ?><span>Sambungan PHP <strong>curl</strong> tiada. Aktifkan di cPanel → Select PHP Version → Extensions sebelum menyambung Google Drive.</span></div><?php endif; ?>
    <section class="stats-grid">
     <article class="stat-card"><div class="stat-top"><span>Google Drive</span><span class="stat-icon <?= $tok?'ok':'' ?>"><?= icon('globe') ?></span></div><strong><?= $tok?'Aktif':'—' ?></strong><small><?= $tok?e($tok['email']?:'akaun Google'):'Belum disambungkan' ?></small></article>
     <article class="stat-card <?= $last && empty($last['ok'])?'alert':'' ?>"><div class="stat-top"><span>Sandaran terakhir</span><span class="stat-icon <?= $last && !empty($last['ok'])?'ok':'warn' ?>"><?= icon('shield') ?></span></div><strong><?= !$last?'—':(!empty($last['ok'])?'Berjaya':'Gagal') ?></strong><small><?= $last?e(bk_ago((int)$last['time']).' · '.(['auto'=>'automatik','manual'=>'manual','cron'=>'cron'][$last['source']??'']??'')):'Belum pernah' ?></small></article>
     <article class="stat-card"><div class="stat-top"><span>Saiz sandaran</span><span class="stat-icon"><?= icon('download') ?></span></div><strong><?= $last && !empty($last['ok'])?e(bk_bytes((int)$last['size'])):'—' ?></strong><small><?= $last && !empty($last['ok'])?number_format((int)$last['rows']).' rekod · '.(int)$last['tables'].' jadual':'Tersulit (.kbak)' ?></small></article>
     <article class="stat-card"><div class="stat-top"><span>Jadual</span><span class="stat-icon"><?= icon('calendar') ?></span></div><strong><?= $c['auto']==='1'?'Harian':'Manual' ?></strong><small>Simpan <?= (int)$c['keep_daily'] ?> terbaharu<?= (int)$c['keep_weekly']?' + '.(int)$c['keep_weekly'].' mingguan':'' ?></small></article>
    </section>
    <?php if($last && empty($last['ok'])): ?><div class="notice error"><?= icon('alert') ?><span><strong>Sandaran terakhir gagal</strong> (<?= e(date('d/m/Y H:i',(int)$last['time'])) ?>): <?= e($last['error']??'') ?></span></div><?php endif; ?>
    <?php if($okAt && $last && !empty($last['ok']) && $last['link']!==''): ?><p class="muted">Sandaran berjaya terakhir: <strong><?= e($last['name']) ?></strong> · <?= e(date('d/m/Y H:i',$okAt)) ?> · <a class="text-link" href="<?= e($last['link']) ?>" target="_blank" rel="noopener">Buka di Drive ↗</a></p><?php endif; ?>

    <section class="panel padded spaced" id="langkah-1"><h3><span class="step-no">1</span> Akaun Google &amp; projek Google Cloud <?= $step1?'<span class="badge green">Lengkap</span>':'' ?></h3>
     <?php if(!$uri): ?><div class="notice error"><?= icon('info') ?><span>Tetapkan <strong>PLATFORM_DOMAIN</strong> dalam fail <code>.env</code> dahulu (cth. urustugas.sekolah.my). Domain induk digunakan sebagai alamat kembali (redirect) dari Google.</span></div><?php endif; ?>
     <details class="manual-key"<?= $step1?'':' open' ?>><summary>Panduan: cipta Client ID OAuth di Google Cloud (sekali sahaja, percuma)</summary>
      <ol class="setup-steps">
       <li>Log masuk <strong>console.cloud.google.com</strong> menggunakan akaun yang akan menyimpan sandaran (Gmail atau Workspace sekolah). Cipta projek baharu, cth. <em>Sandaran Koku</em>.</li>
       <li><strong>APIs &amp; Services → Library</strong> → cari <em>Google Drive API</em> → <strong>Enable</strong>.</li>
       <li><strong>Google Auth Platform → Branding</strong>: isi nama aplikasi (cth. <em>Sandaran UrusTugas</em>) dan e-mel sokongan.</li>
       <li><strong>Audience</strong>: <strong>Gmail</strong> — pilih <strong>External</strong>, kemudian klik <strong>Publish app → In production</strong>. <strong>Workspace sekolah</strong> — boleh pilih <strong>Internal</strong>. <span class="warn-text">Jangan biarkan "Testing": token tamat selepas 7 hari dan sandaran akan berhenti.</span></li>
       <li><strong>Data Access → Add or remove scopes</strong>: tandakan <code>.../auth/drive.file</code>, <code>openid</code> dan <code>email</code>. Skop ini tidak memerlukan pengesahan Google dan hanya membenarkan akses kepada fail yang dicipta sistem ini.</li>
       <li><strong>Clients → Create client</strong> → jenis <em>Web application</em> → <strong>Authorized redirect URIs</strong>: tampal alamat di bawah, kemudian <strong>Create</strong>.</li>
       <li>Salin <strong>Client ID</strong> dan <strong>Client Secret</strong> ke borang di bawah.</li>
      </ol>
      <?php if($uri): ?><label class="field">Authorized redirect URI<span class="copy-row"><input id="bk-uri" class="copy-field" readonly value="<?= e($uri) ?>" aria-label="Authorized redirect URI"><button type="button" class="btn outline" data-copy-target="#bk-uri">Salin</button></span></label><?php endif; ?>
      <p class="muted"><strong>Gmail:</strong> disyorkan cipta akaun Gmail <em>khas</em> untuk sandaran sekolah (cth. sandaran.urustugas.sekolah@gmail.com), bukan e-mel peribadi harian — mudah diserahkan kepada pentadbir lain jika bertukar sekolah. Aktifkan pengesahan 2 langkah pada akaun itu. Kuota percuma 15 GB dikongsi dengan Gmail &amp; Foto. Jika skrin Google memaparkan <em>"Google hasn't verified this app"</em>, klik <em>Advanced → Go to … (unsafe)</em>: ini aplikasi anda sendiri dan hanya mendapat akses kepada fail sandaran yang ia cipta.</p>
     </details>
     <form method="post" class="form-grid spaced"><?= csrf_field() ?><input type="hidden" name="action" value="backup_cfg_save">
      <fieldset class="field full surat-opt"><legend>Jenis akaun Google</legend><div class="mode-grid twofa-grid"><?php foreach(BACKUP_ACCOUNT_TYPES as $k=>[$t,$d]): ?><label class="mode-card twofa-card"><input type="radio" name="type" value="<?= $k ?>" <?= $c['type']===$k?'checked':'' ?>><span class="mode-icon"><?= icon($k==='gmail'?'user':'school') ?></span><span><strong><?= e($t) ?></strong><small><?= e($d) ?></small></span></label><?php endforeach; ?></div></fieldset>
      <label class="field">Domain Workspace sekolah (pilihan)<input name="domain" maxlength="190" value="<?= e($c['domain']) ?>" placeholder="cth. smkmuadzam.edu.my" spellcheck="false"><small class="muted">Untuk Workspace sahaja: hanya akaun @domain ini diterima. Bagi Gmail, hanya akaun @gmail.com diterima.</small></label>
      <label class="field">Client ID<input name="client_id" maxlength="200" value="<?= e($c['client_id']) ?>" placeholder="1234567890-xxxx.apps.googleusercontent.com" spellcheck="false" autocomplete="off"></label>
      <label class="field">Client Secret<input type="password" name="client_secret" maxlength="100" autocomplete="new-password" placeholder="<?= $hasSecret?'•••••••• (disimpan & disulitkan; kosongkan untuk kekal)':'GOCSPX-…' ?>"></label>
      <label class="field">Simpan sandaran terbaharu<select name="keep_daily"><?php foreach([7,14,30] as $n): ?><option value="<?= $n ?>"<?= (int)$c['keep_daily']===$n?' selected':'' ?>><?= $n ?> sandaran</option><?php endforeach; ?></select></label>
      <label class="field">Tambah sandaran mingguan<select name="keep_weekly"><?php foreach([0=>'Tiada',4=>'4 minggu',8=>'8 minggu',12=>'12 minggu'] as $n=>$l): ?><option value="<?= $n ?>"<?= (int)$c['keep_weekly']===$n?' selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label class="check full"><input type="checkbox" name="auto" value="1"<?= $c['auto']==='1'?' checked':'' ?>> <span>Sandaran automatik setiap hari — berjalan apabila pentadbir log masuk atau pemantau uptime menghubungi sistem (cron pilihan: php bin/backup.php)</span></label>
      <div class="form-actions full"><button class="btn primary"><?= icon('check') ?>Simpan tetapan</button></div></form></section>

    <section class="panel padded spaced" id="langkah-2"><h3><span class="step-no">2</span> Kata laluan sandaran <?= $kdf?'<span class="badge green">Ditetapkan</span>':'' ?></h3>
     <p class="muted">Setiap fail sandaran disulitkan dengan kata laluan ini sebelum dihantar ke Google Drive — Google atau sesiapa yang mendapat akses Drive tidak boleh membaca data sekolah. <strong>Kata laluan ini diperlukan untuk memulihkan sandaran dan tidak boleh ditetapkan semula.</strong> Simpan berasingan daripada Drive (pengurus kata laluan, atau sampul bermeterai di pejabat), bersama salinan <code>APP_KEY</code> daripada fail .env.</p>
     <?php if($kdf): ?><p class="muted">Ditetapkan pada <?= e(date('d/m/Y H:i',(int)strtotime($kdf['set']))) ?>.</p><?php endif; ?>
     <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="backup_pass_save">
      <label class="field"><?= $kdf?'Kata laluan sandaran baharu':'Kata laluan sandaran' ?> (12–128 aksara)<input type="password" name="backup_pass" minlength="12" maxlength="128" autocomplete="new-password" required></label>
      <label class="field">Ulang kata laluan sandaran<input type="password" name="backup_pass_confirm" minlength="12" maxlength="128" autocomplete="new-password" required></label>
      <?php if($kdf): ?><label class="confirm-check full"><input type="checkbox" name="pass_ack" value="yes" required> Saya faham: sandaran lama di Drive masih memerlukan kata laluan LAMA untuk dipulihkan.</label><?php endif; ?>
      <div class="full"><?= admin_confirm_field() ?></div>
      <div class="form-actions full"><button class="btn primary"><?= icon('lock') ?><?= $kdf?'Tukar kata laluan sandaran':'Tetapkan kata laluan sandaran' ?></button></div></form></section>

    <section class="panel padded spaced" id="langkah-3"><h3><span class="step-no">3</span> Sambung Google Drive <?= $tok?'<span class="badge green">Bersambung</span>':'' ?></h3>
     <?php if($tok): ?><p>Bersambung dengan <strong><?= e($tok['email']) ?></strong> sejak <?= e(date('d/m/Y H:i',(int)strtotime($tok['connected']))) ?>.<?= $folderUrl?' <a class="text-link" href="'.e($folderUrl).'" target="_blank" rel="noopener">Buka folder sandaran ↗</a>':'' ?></p>
      <div class="button-group"><a class="btn outline" href="/platform/?p=sandaran&amp;sambung=1"><?= icon('link') ?>Sambung semula / tukar akaun</a></div>
      <details class="manual-key"><summary>Putuskan sambungan</summary><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="backup_disconnect"><p class="muted">Sandaran automatik berhenti. Fail sedia ada di Drive tidak dipadam.</p><?= admin_confirm_field() ?><button class="btn outline danger" type="submit">Putuskan Google Drive</button></form></details>
     <?php elseif($step1): ?><p class="muted">Anda akan dibawa ke Google. Log masuk dengan akaun <?= $c['type']==='gmail'?'<strong>Gmail</strong> (@gmail.com) sandaran sekolah':'<strong>Workspace sekolah</strong>'.($c['domain']!==''?' (@'.e($c['domain']).')':'') ?>, kemudian klik <strong>Allow / Benarkan</strong>.</p>
      <a class="btn primary" href="/platform/?p=sandaran&amp;sambung=1"><?= icon('link') ?>Sambung Google Drive</a>
     <?php else: ?><p class="muted">Lengkapkan Langkah 1 dahulu.</p><?php endif; ?></section>

    <section class="panel padded spaced"><h3>Sandaran sekarang</h3>
     <div class="dashboard-grid">
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="backup_run"><p class="muted">Jana dan muat naik sandaran ke Google Drive sekarang. Mungkin mengambil masa seminit atau lebih jika banyak gambar.</p><button class="btn primary"<?= bk_ready()?'':' disabled' ?>><?= icon('upload') ?>Sandaran sekarang</button></form>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="backup_download"><p class="muted">Muat turun fail sandaran tersulit (.kbak) ke komputer ini — contohnya untuk simpanan luar talian dalam pemacu USB.</p><?= admin_confirm_field() ?><button class="btn outline"<?= $kdf?'':' disabled' ?>><?= icon('download') ?>Muat turun sandaran</button></form>
     </div></section>

    <?php if($tok): ?><section class="panel spaced"><div class="panel-heading"><div><h3>Sandaran di Google Drive <span class="count"><?= $files===null?'—':count($files) ?></span></h3><p class="muted">Hanya fail yang dicipta oleh sistem ini dipaparkan.</p></div></div>
     <?php if($listErr!==null): ?><p class="notice error"><?= e($listErr) ?></p><?php elseif(!$files): ?><div class="empty compact"><span><?= icon('file') ?></span><p>Belum ada sandaran di Drive. Tekan "Sandaran sekarang" untuk menguji.</p></div><?php else: ?>
     <div class="table-wrap"><table><thead><tr><th>Fail</th><th>Tarikh</th><th>Saiz</th><th></th></tr></thead><tbody><?php foreach($files as $f): ?><tr><td><code><?= e($f['name']) ?></code></td><td><?= e(date('d/m/Y H:i',(int)strtotime((string)$f['createdTime']))) ?></td><td><?= e(bk_bytes(isset($f['size'])?(int)$f['size']:null)) ?></td><td><?php if(!empty($f['webViewLink'])): ?><a class="text-link" href="<?= e($f['webViewLink']) ?>" target="_blank" rel="noopener">Buka ↗</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?>

    <section class="panel padded spaced"><h3>Cara memulihkan</h3><ol class="setup-steps">
     <li>Muat turun fail <code>urustugas-sandaran-….kbak</code> daripada folder Google Drive.</li>
     <li>Sediakan pelayan dengan kod UrusTugas dan fail <code>.env</code> yang mengandungi <strong>APP_KEY yang sama</strong> serta pangkalan data <strong>kosong</strong>.</li>
     <li>Jalankan <code>php bin/restore.php urustugas-sandaran-….kbak</code> (SSH / Terminal cPanel) dan masukkan kata laluan sandaran.</li>
     <li>Tanpa akses SSH: jalankan <code>php bin/restore.php FAIL.kbak --sql pulih.sql</code> di mana-mana komputer yang ada PHP, kemudian import <code>pulih.sql</code> melalui phpMyAdmin.</li>
    </ol><p class="muted">Tanpa kata laluan sandaran, fail tidak boleh dibuka. Tanpa APP_KEY, nombor pengenalan, telefon dan gambar laporan tidak boleh dibaca walaupun pangkalan data dipulihkan.</p></section><?php
}
