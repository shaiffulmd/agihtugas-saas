<?php
declare(strict_types=1);
/* Kemas kini automatik daripada GitHub (sama untuk shared hosting/cPanel dan VPS; tiada SSH, git atau Composer di pelayan).
 *
 *  GitHub Actions (.github/workflows/terbit.yml) membina zip keluaran setiap push ke main dan menerbitkannya ke Release
 *  bertag UPDATE_TAG (lalai "terkini") bersama manifest.json {sha, version, built_at, zip, zip_sha256, message},
 *  kemudian memaklumkan laman melalui webhook bertandatangan HMAC.
 *
 *  Laman ini: muat turun manifest + zip (token GitHub baca sahaja) → sahkan SHA-256 → semak setiap laluan zip →
 *  sandarkan fail yang akan ditindih → salin fail baharu (tulis .tmp kemudian rename) → run_upgrade() →
 *  kosongkan OPcache → terima asas integriti baharu. Gagal di tengah jalan = fail lama dipulihkan automatik.
 *
 *  Pencetus: (1) webhook POST /kemaskini-hook (X-UrusTugas-Signature: sha256=HMAC(UPDATE_SECRET, badan)),
 *  (2) butang di panel, (3) CLI/cron: php bin/update.php [semak|kemaskini|rollback].
 *
 *  .env: UPDATE_REPO=pemilik/repo  UPDATE_TOKEN=github_pat_… (fine-grained, Contents: Read-only)
 *        UPDATE_SECRET=rahsia-webhook  [UPDATE_TAG=terkini]  [UPDATE_AUTO=1 → cron.php juga menyemak]
 *        [UPDATE_API=https://api.github.com — tukar untuk GitHub Enterprise]
 *
 *  Modul ini bebas aplikasi: hanya memerlukan env(); run_upgrade(), integrity_accept(), platform_log()/audit()
 *  dipanggil jika wujud — boleh disalin ke sistem lain. */

const UPD_KEEP_BACKUPS=3;
const UPD_MAX_ZIP=209715200; // 200 MB
const UPD_PROTECT='~^(\.env($|\.)|\.git/|storage/|tmp/|\.user\.ini$|public_html/\.user\.ini$|error_log$)~'; // tidak pernah ditindih oleh kemas kini

function upd_root(): string { return dirname(__DIR__); }
function upd_dir(): string { $d=upd_root().'/storage/update'; if(!is_dir($d)) @mkdir($d,0700,true); return $d; }
function upd_cfg(): array {
    return ['repo'=>trim(env('UPDATE_REPO')),'token'=>trim(env('UPDATE_TOKEN')),'secret'=>trim(env('UPDATE_SECRET')),'tag'=>trim(env('UPDATE_TAG','terkini'))?:'terkini','api'=>rtrim(trim(env('UPDATE_API','https://api.github.com')),'/'),'auto'=>env('UPDATE_AUTO')==='1'];
}
function upd_ready(): bool { $c=upd_cfg(); return (bool)preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~',$c['repo']) && $c['token']!==''; }
function upd_current(): ?array { $j=json_decode((string)@file_get_contents(upd_dir().'/deployed.json'),true); return is_array($j)?$j:null; }
function upd_log(string $event,array $data=[]): void {
    $row=['at'=>date('c'),'event'=>$event]+$data;
    @file_put_contents(upd_dir().'/log.jsonl',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
    $detail=mb_substr(trim(($data['sha']??'').' '.($data['version']??'').' '.($data['error']??'').' '.($data['by']??'')),0,250);
    try { if(function_exists('platform_log') && function_exists('sid') && sid()===0) platform_log('update_'.$event,null,$detail); elseif(function_exists('audit')) audit('update_'.$event,'system'); } catch(Throwable) {}
}
function upd_history(int $n=10): array {
    $lines=@file(upd_dir().'/log.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]; $out=[];
    foreach(array_slice(array_reverse($lines),0,$n) as $l){ $j=json_decode($l,true); if(is_array($j)) $out[]=$j; } return $out;
}

/** HTTP GitHub. $save = laluan fail (strim) atau null (pulang badan). Pengalihan ke CDN tidak membawa token (curl lalai). */
function upd_http(string $url,string $accept='application/vnd.github+json',?string $save=null): string {
    $c=upd_cfg(); if(!function_exists('curl_init')) throw new RuntimeException('Sambungan PHP curl diperlukan untuk kemas kini.');
    $proto=env('APP_ENV')==='local'?(CURLPROTO_HTTPS|CURLPROTO_HTTP):CURLPROTO_HTTPS; // HTTP biasa hanya untuk ujian setempat
    $ch=curl_init($url); $fh=null;
    $opt=[CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>300,CURLOPT_FAILONERROR=>false,CURLOPT_PROTOCOLS=>$proto,CURLOPT_REDIR_PROTOCOLS=>$proto,
        CURLOPT_HTTPHEADER=>['Accept: '.$accept,'User-Agent: urustugas-updater','X-GitHub-Api-Version: 2022-11-28']+($c['token']!==''?[3=>'Authorization: Bearer '.$c['token']]:[])];
    if($save!==null){ $fh=fopen($save,'wb'); if(!$fh) throw new RuntimeException('Tidak dapat menulis fail sementara kemas kini.'); $opt[CURLOPT_FILE]=$fh; $opt[CURLOPT_NOPROGRESS]=false;
        $opt[CURLOPT_PROGRESSFUNCTION]=fn($r,$dt)=>$dt>UPD_MAX_ZIP?1:0; } else $opt[CURLOPT_RETURNTRANSFER]=true;
    curl_setopt_array($ch,$opt); $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $err=curl_error($ch); unset($ch); if($fh) fclose($fh);
    if($body===false) throw new RuntimeException('Sambungan ke GitHub gagal: '.$err);
    if($code===401 || $code===403) throw new RuntimeException('GitHub menolak token (HTTP '.$code.'). Semak UPDATE_TOKEN: perlu akses Contents: Read-only untuk repo '.$c['repo'].'.');
    if($code===404) throw new RuntimeException('Release "'.$c['tag'].'" atau repo '.$c['repo'].' tidak dijumpai (HTTP 404). Pastikan GitHub Actions sudah berjaya sekali.');
    if($code>=400) throw new RuntimeException('GitHub memulangkan HTTP '.$code.'.');
    return $save===null?(string)$body:'';
}
/** Release terkini: manifest + id aset zip. */
function upd_latest(): array {
    if(!upd_ready()) throw new RuntimeException('Kemas kini belum dikonfigurasi: tetapkan UPDATE_REPO dan UPDATE_TOKEN dalam .env.');
    $c=upd_cfg(); $rel=json_decode(upd_http($c['api'].'/repos/'.$c['repo'].'/releases/tags/'.rawurlencode($c['tag'])),true);
    if(!is_array($rel) || !isset($rel['assets'])) throw new RuntimeException('Jawapan Release GitHub tidak sah.');
    $assets=[]; foreach($rel['assets'] as $a) $assets[(string)$a['name']]=(string)$a['url'];
    if(!isset($assets['manifest.json'])) throw new RuntimeException('Release tiada manifest.json — jalankan semula GitHub Actions.');
    $m=json_decode(upd_http($assets['manifest.json'],'application/octet-stream'),true);
    if(!is_array($m) || !preg_match('/^[0-9a-f]{40}$/',(string)($m['sha']??'')) || !preg_match('/^[0-9a-f]{64}$/',(string)($m['zip_sha256']??'')) || !isset($assets[$m['zip']??''])) throw new RuntimeException('manifest.json tidak sah atau zip tiada dalam Release.');
    $m['zip_url']=$assets[$m['zip']]; return $m;
}
/** Laluan dalam zip selamat & dibenarkan ditulis? */
function upd_path_ok(string $p): bool {
    return $p!=='' && !str_contains($p,"\0") && !str_contains($p,'\\') && $p[0]!=='/' && !preg_match('~(^|/)\.\.(/|$)~',$p) && !preg_match(UPD_PROTECT,$p);
}
/** Semak setiap fail di akar boleh ditulis (gagal awal sebelum menyentuh apa-apa). */
function upd_writable_problems(?ZipArchive $z=null): array {
    $root=upd_root(); $bad=[];
    foreach(['app','public_html','bin','vendor'] as $d) if(is_dir("$root/$d") && !is_writable("$root/$d")) $bad[]=$d.'/';
    if($z) for($i=0;$i<$z->numFiles && count($bad)<10;$i++){ $n=(string)$z->getNameIndex($i); if(str_ends_with($n,'/') || !upd_path_ok($n)) continue; $t="$root/$n"; if(is_file($t) && !is_writable($t)) $bad[]=$n; }
    return array_values(array_unique($bad));
}
/** Salin isi zip ke akar. Pulang senarai fail ditulis. $backup = ZipArchive untuk menyimpan versi lama (null = tiada sandaran). */
function upd_extract(ZipArchive $z,?ZipArchive $backup,array &$created): array {
    $root=upd_root(); $written=[];
    for($i=0;$i<$z->numFiles;$i++){
        $n=(string)$z->getNameIndex($i);
        if(str_ends_with($n,'/')){ if(upd_path_ok($n) && !is_dir("$root/$n")) @mkdir("$root/$n",0755,true); continue; }
        if(!upd_path_ok($n)) continue;
        $t="$root/$n"; $data=$z->getFromIndex($i); if($data===false) throw new RuntimeException('Fail rosak dalam zip: '.$n);
        if(is_file($t)){ if(hash_file('sha256',$t)===hash('sha256',$data)) continue; if($backup) $backup->addFromString($n,(string)file_get_contents($t)); }
        else $created[]=$n;
        if(!is_dir(dirname($t)) && !@mkdir(dirname($t),0755,true)) throw new RuntimeException('Tidak dapat mencipta folder untuk '.$n);
        $tmp=$t.'.upd-'.bin2hex(random_bytes(4));
        if(@file_put_contents($tmp,$data)===false){ throw new RuntimeException('Tidak dapat menulis '.$n.' (semak kebenaran fail).'); }
        @chmod($tmp,0644); if(!@rename($tmp,$t)){ @unlink($tmp); throw new RuntimeException('Tidak dapat menggantikan '.$n.'.'); }
        if(function_exists('opcache_invalidate') && str_ends_with($n,'.php')) @opcache_invalidate($t,true);
        $written[]=$n;
    }
    return $written;
}
function upd_finish(string $by,string $sha,string $version): void {
    if(function_exists('opcache_reset')) @opcache_reset();
    if(!function_exists('run_upgrade') && is_file(upd_root().'/app/install.php')) require_once upd_root().'/app/install.php';
    if(function_exists('run_upgrade')) run_upgrade();
    if(function_exists('integrity_accept')) integrity_accept('kemas kini '.substr($sha,0,8).' ('.$by.')');
}

/** Kemas kini ke Release terkini. $force = pasang semula walaupun sha sama. Pulang ringkasan. */
function upd_apply(string $by,bool $force=false): array {
    @set_time_limit(600); @ignore_user_abort(true);
    $lock=fopen(upd_dir().'/lock','c'); if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) return ['status'=>'sibuk','message'=>'Kemas kini lain sedang berjalan.'];
    $zipPath=upd_dir().'/baharu-'.bin2hex(random_bytes(4)).'.zip'; $bk=null; $bkPath=''; $created=[]; $m=null;
    try {
        $m=upd_latest(); $cur=upd_current();
        if(!$force && $cur && ($cur['sha']??'')===$m['sha']) return ['status'=>'terkini','message'=>'Sistem sudah versi terkini ('.substr($m['sha'],0,8).').','sha'=>$m['sha']];
        upd_http($m['zip_url'],'application/octet-stream',$zipPath);
        if(!hash_equals($m['zip_sha256'],(string)hash_file('sha256',$zipPath))) throw new RuntimeException('SHA-256 zip tidak sepadan dengan manifest — muat turun dibatalkan.');
        $z=new ZipArchive(); if($z->open($zipPath,ZipArchive::RDONLY)!==true) throw new RuntimeException('Zip keluaran tidak boleh dibuka.');
        if($z->locateName('app/bootstrap.php')===false) throw new RuntimeException('Zip bukan keluaran sistem ini (app/bootstrap.php tiada).');
        if($p=upd_writable_problems($z)) throw new RuntimeException('Fail/folder tidak boleh ditulis oleh PHP: '.implode(', ',array_slice($p,0,5)).'. Betulkan pemilik/kebenaran (VPS: chown -R pengguna-php '.upd_root().').');
        $bkPath=upd_dir().'/sandaran-'.date('Ymd-His').'-'.substr((string)($cur['sha']??'asal'),0,8).'.zip';
        $bk=new ZipArchive(); if($bk->open($bkPath,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Tidak dapat mencipta fail sandaran.');
        upd_log('mula',['sha'=>$m['sha'],'version'=>$m['version']??'','by'=>$by]);
        try { $written=upd_extract($z,$bk,$created); }
        catch(Throwable $ex){ $bk->close(); $bk=null; upd_restore($bkPath,$created); throw new RuntimeException('Gagal menyalin fail; versi lama dipulihkan. '.$ex->getMessage()); }
        $bk->addFromString('.urustugas-update.json',json_encode(['created'=>$created,'from'=>$cur,'to'=>$m['sha']])); $bk->close(); $bk=null; $z->close();
        upd_finish($by,$m['sha'],(string)($m['version']??''));
        $dep=['sha'=>$m['sha'],'version'=>$m['version']??'','built_at'=>$m['built_at']??'','message'=>$m['message']??'','at'=>date('c'),'by'=>$by,'files'=>count($written),'backup'=>basename($bkPath)];
        file_put_contents(upd_dir().'/deployed.json',json_encode($dep,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
        upd_prune(); upd_log('berjaya',['sha'=>$m['sha'],'version'=>$m['version']??'','by'=>$by,'files'=>count($written)]);
        return ['status'=>'berjaya','message'=>count($written).' fail dikemas kini ke '.substr($m['sha'],0,8).($m['message']??''?' — '.$m['message']:'').'.','sha'=>$m['sha']];
    } catch(Throwable $ex){
        if($bk instanceof ZipArchive) @$bk->close();
        if($bkPath!=='' && is_file($bkPath) && filesize($bkPath)<100) @unlink($bkPath);
        upd_log('gagal',['sha'=>$m['sha']??'','error'=>$ex->getMessage(),'by'=>$by]);
        return ['status'=>'gagal','message'=>$ex->getMessage()];
    } finally { @unlink($zipPath); flock($lock,LOCK_UN); fclose($lock); }
}
/** Pulihkan fail daripada zip sandaran; padam fail yang dicipta oleh kemas kini yang gagal. */
function upd_restore(string $bkPath,array $created): void {
    $root=upd_root(); $z=new ZipArchive();
    if(is_file($bkPath) && $z->open($bkPath)===true){ $none=[]; upd_extract($z,null,$none); $meta=json_decode((string)$z->getFromName('.urustugas-update.json'),true); $z->close(); if(is_array($meta)) $created=array_merge($created,(array)($meta['created']??[])); }
    foreach($created as $n) if(upd_path_ok($n) && is_file("$root/$n")) @unlink("$root/$n");
    if(function_exists('opcache_reset')) @opcache_reset();
}
/** Kembali ke versi sebelum kemas kini terakhir (fail sahaja; perubahan pangkalan data kekal — migrasi serasi ke belakang). */
function upd_rollback(string $by): array {
    $lock=fopen(upd_dir().'/lock','c'); if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) return ['status'=>'sibuk','message'=>'Kemas kini lain sedang berjalan.'];
    try {
        $cur=upd_current(); $bk=$cur['backup']??''; $path=upd_dir().'/'.basename((string)$bk);
        if($bk==='' || !is_file($path)) return ['status'=>'gagal','message'=>'Tiada sandaran untuk dipulihkan.'];
        $z=new ZipArchive(); $z->open($path); $meta=json_decode((string)$z->getFromName('.urustugas-update.json'),true); $z->close();
        upd_restore($path,[]); $prev=is_array($meta)?($meta['from']??null):null;
        if(function_exists('integrity_accept')) integrity_accept('rollback ('.$by.')');
        $dep=is_array($prev)?$prev+['rolled_back_at'=>date('c')]:['sha'=>'','version'=>'(sebelum kemas kini automatik)','at'=>date('c'),'by'=>$by];
        $dep['backup']=''; file_put_contents(upd_dir().'/deployed.json',json_encode($dep,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
        @rename($path,$path.'.dipulihkan');
        upd_log('rollback',['sha'=>(string)($dep['sha']??''),'by'=>$by]);
        return ['status'=>'berjaya','message'=>'Fail dipulihkan ke versi sebelum kemas kini '.substr((string)($cur['sha']??''),0,8).'.'];
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function upd_prune(): void { $f=glob(upd_dir().'/sandaran-*.zip')?:[]; rsort($f); foreach(array_slice($f,UPD_KEEP_BACKUPS) as $x) @unlink($x); foreach(glob(upd_dir().'/*.dipulihkan')?:[] as $x) if(filemtime($x)<time()-30*86400) @unlink($x); }

/** POST /kemaskini-hook — dipanggil oleh GitHub Actions selepas Release diterbitkan. */
function upd_webhook(): never {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $c=upd_cfg(); if($c['secret']==='' || !upd_ready()){ http_response_code(404); echo '{"ok":false}'; exit; }
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){ http_response_code(405); echo '{"ok":false}'; exit; }
    $body=(string)file_get_contents('php://input',false,null,0,8192); $sig=(string)($_SERVER['HTTP_X_URUSTUGAS_SIGNATURE']??'');
    if(!hash_equals('sha256='.hash_hmac('sha256',$body,$c['secret']),$sig)){ http_response_code(401); upd_log('webhook_ditolak',['error'=>'tandatangan tidak sah']); echo '{"ok":false,"error":"signature"}'; exit; }
    $j=json_decode($body,true); if(!is_array($j) || ($j['event']??'')!=='release'){ http_response_code(400); echo '{"ok":false}'; exit; }
    http_response_code(202); echo '{"ok":true,"queued":true}';
    $run=fn()=>upd_apply('webhook');
    if(function_exists('after_response')) after_response($run); else { if(function_exists('fastcgi_finish_request')) fastcgi_finish_request(); $run(); }
    exit;
}

/** Bahagian panel (platform / pentadbir). $actionPrefix menentukan nama tindakan borang (upd_semak, upd_kemaskini, upd_rollback). */
function upd_panel_html(string $hookUrl): string {
    $c=upd_cfg(); $cur=upd_current(); $ready=upd_ready(); $h='';
    $latest=null; $err='';
    if($ready && isset($_SESSION['upd_check']) && ($_SESSION['upd_check']['at']??0)>time()-300) $latest=$_SESSION['upd_check']['m']??null;
    $err=(string)($_SESSION['upd_check']['error']??'');
    $badge=fn($ok,$t)=>'<span class="badge '.($ok?'green':'warn').'">'.e($t).'</span>';
    $h.='<section class="panel padded spaced" id="kemaskini"><div class="upd-head"><div><h3>Versi dipasang</h3>';
    $h.=$cur&&($cur['sha']??'')!==''?'<p><code>'.e(substr((string)$cur['sha'],0,8)).'</code> · v'.e((string)($cur['version']??'')).($cur['message']??''?' · '.e((string)$cur['message']):'').'<br><small class="muted">Dipasang '.e(date('d/m/Y H:i',strtotime((string)$cur['at']))).' oleh '.e((string)($cur['by']??'')).'</small></p>':'<p class="muted">Belum pernah dikemas kini secara automatik (dipasang melalui zip manual).</p>';
    $h.='</div>';
    if($latest){ $same=$cur && ($cur['sha']??'')===$latest['sha']; $h.='<div><h3>Terkini di GitHub</h3><p><code>'.e(substr($latest['sha'],0,8)).'</code> · v'.e((string)($latest['version']??'')).' '.$badge($same,$same?'Sudah terkini':'Kemas kini tersedia').'<br><small class="muted">'.e((string)($latest['message']??'')).' · dibina '.e(date('d/m/Y H:i',strtotime((string)($latest['built_at']??'now')))).'</small></p></div>'; }
    $h.='</div>';
    if($err!=='') $h.='<div class="notice error">'.e($err).'</div>';
    $h.='<form method="post" class="button-group">'.csrf_field().'<button class="btn outline" name="action" value="upd_semak"'.($ready?'':' disabled').'>Semak versi terkini</button><button class="btn primary" name="action" value="upd_kemaskini"'.($ready?'':' disabled').' data-confirm-simple="Kemas kini sistem ke versi terkini daripada GitHub sekarang? Fail lama disandarkan dahulu.">Kemas kini sekarang</button>'.(($cur['backup']??'')!==''?'<button class="btn outline" name="action" value="upd_rollback" data-confirm-simple="Pulihkan fail ke versi sebelum kemas kini terakhir?">Rollback</button>':'').'</form></section>';
    $wr=upd_writable_problems();
    $rows=[['UPDATE_REPO',$c['repo']!=='',$c['repo']?:'Belum ditetapkan (cth. pemilik/repo)'],['UPDATE_TOKEN',$c['token']!=='',$c['token']!==''?'Ditetapkan ('.substr($c['token'],0,11).'…)':'Belum ditetapkan — token GitHub fine-grained, Contents: Read-only'],
        ['UPDATE_SECRET (webhook)',$c['secret']!=='',$c['secret']!==''?'Ditetapkan':'Belum — webhook dimatikan; guna butang atau cron'],['Release',true,$c['tag']],['Semakan cron (UPDATE_AUTO)',$c['auto'],$c['auto']?'Aktif — bin/cron.php turut menyemak':'Mati'],
        ['curl & zip',function_exists('curl_init') && class_exists('ZipArchive'),function_exists('curl_init') && class_exists('ZipArchive')?'Tersedia':'Perlu sambungan PHP curl & zip'],['Fail boleh ditulis oleh PHP',!$wr,$wr?'Tidak: '.implode(', ',$wr):'Ya']];
    $h.='<section class="panel spaced"><div class="panel-heading"><div><h3>Tetapan kemas kini</h3><p class="muted">URL webhook untuk GitHub (secret <code>DEPLOY_HOOKS</code>): <code>'.e($hookUrl).'</code></p></div></div><div class="table-wrap"><table><tbody>';
    foreach($rows as [$l,$ok,$d]) $h.='<tr><td>'.e($l).'</td><td>'.$badge((bool)$ok,$ok?'OK':'Semak').'</td><td>'.e((string)$d).'</td></tr>';
    $h.='</tbody></table></div></section>';
    $hist=upd_history(8);
    if($hist){ $h.='<section class="panel spaced"><div class="panel-heading"><div><h3>Sejarah kemas kini</h3></div></div><div class="table-wrap"><table><thead><tr><th>Masa</th><th>Peristiwa</th><th>Versi</th><th>Oleh</th><th>Butiran</th></tr></thead><tbody>';
        foreach($hist as $r) $h.='<tr class="'.(($r['event']??'')==='gagal'?'row-alert':'').'"><td>'.e(date('d/m/Y H:i',strtotime((string)$r['at']))).'</td><td>'.e((string)$r['event']).'</td><td><code>'.e(substr((string)($r['sha']??''),0,8)).'</code></td><td>'.e((string)($r['by']??'')).'</td><td>'.e((string)($r['error']??(isset($r['files'])?$r['files'].' fail':''))).'</td></tr>';
        $h.='</tbody></table></div></section>'; }
    return $h;
}
/** Tindakan panel. Pulang mesej flash. */
function upd_action(string $action,string $by): string {
    if($action==='upd_semak'){
        try { $m=upd_latest(); $_SESSION['upd_check']=['at'=>time(),'m'=>array_intersect_key($m,array_flip(['sha','version','built_at','message']))]; $cur=upd_current(); return ($cur && ($cur['sha']??'')===$m['sha'])?'Sistem sudah versi terkini.':'Versi baharu tersedia: '.substr($m['sha'],0,8).'.'; }
        catch(Throwable $ex){ $_SESSION['upd_check']=['at'=>time(),'error'=>$ex->getMessage()]; return 'Semakan gagal.'; }
    }
    unset($_SESSION['upd_check']);
    if($action==='upd_kemaskini'){ $r=upd_apply($by); if($r['status']==='gagal') throw new DomainException('Kemas kini gagal: '.$r['message']); return $r['message']; }
    if($action==='upd_rollback'){ $r=upd_rollback($by); if($r['status']==='gagal') throw new DomainException($r['message']); return $r['message']; }
    throw new DomainException('Tindakan tidak sah.');
}
