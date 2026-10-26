<?php
/*
 * Pemasang web UrusTugas — tanpa edit fail secara manual.
 *  1. Semak pelayan  2. Butiran pangkalan data (diuji terus)  3. Pentadbir Platform  → Pasang (sekolah ditambah di /platform/)
 * Pemasang menjana APP_KEY, menulis .env, memasang skema, mencipta pentadbir dan mengunci diri.
 *
 * Keselamatan:
 *  - Hanya berfungsi selagi sistem belum dipasang; selepas itu storage/installed.lock + jadual sedia ada
 *    mengunci pemasang secara kekal, dan fail ini cuba menamakan semula dirinya.
 *  - Memerlukan butiran pangkalan data yang sah pada hos tempatan/rangkaian peribadi sahaja (hanya pemilik akaun
 *    hosting mengetahuinya); hos DB awam ditolak supaya pemasang tidak boleh dihalakan ke pelayan orang lain.
 *  - CSRF, had 10 cubaan gagal / 15 minit per IP, kunci fail semasa pemasangan, pangkalan data mesti kosong.
 *  - Mod naik taraf memerlukan kata laluan pangkalan data semasa.
 */
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/settings.php';
require dirname(__DIR__).'/app/install.php';
require dirname(__DIR__).'/app/images.php';
require dirname(__DIR__).'/app/demo.php';
foreach(['integrity','users','platform'] as $f) require_once dirname(__DIR__).'/app/'.$f.'.php'; // data demo & school_create()
header('X-Robots-Tag: noindex, nofollow');

$root=dirname(__DIR__); $envExists=is_file($root.'/.env'); $locked=is_file(install_lock_path());
$mode='install'; $dbDown=false;
if($envExists){
    try { db()->query('SELECT 1'); $st=app_status(); $mode=match($st){'not_installed'=>'install','needs_upgrade'=>'upgrade',default=>'locked'}; }
    catch(Throwable) { $dbDown=true; $mode=$locked?'locked':'install'; }
} elseif($locked) $mode='locked';

$error=''; $notice=''; $done=null; $envFallback=null; $ip=(string)($_SERVER['REMOTE_ADDR']??'');
$action=input('action');
if($_SERVER['REQUEST_METHOD']==='POST' && $mode!=='locked'){
    try {
        verify_csrf();
        if(!install_attempts_allowed($ip)) throw new DomainException('Terlalu banyak cubaan gagal. Cuba semula selepas 15 minit.');
        if($mode==='upgrade'){
            if(env('DB_PASS')==='' || !hash_equals(env('DB_PASS'),input('db_pass_confirm'))){ install_attempts_allowed($ip,true); throw new DomainException('Kata laluan pangkalan data tidak sepadan dengan konfigurasi semasa.'); }
            $lines=run_upgrade(); audit('upgrade','settings');
            $done=['Naik taraf selesai',$lines]; $mode='locked';
        } else {
            $req=web_requirements(); if(!requirements_ok($req)) throw new DomainException('Betulkan item bertanda ✗ dalam semakan pelayan dahulu.');
            $c=db_config_from_input();
            try { $pdo=install_connect($c); } catch(DomainException $ex) { install_attempts_allowed($ip,true); throw $ex; }
            check_db_ready($pdo);
            if($action==='test') $notice='✓ Sambungan berjaya. Pangkalan data kosong dan sedia untuk dipasang.';
            else {
                $demo=$action==='install_demo'; // Demo: pentadbir platform + 2 sekolah berdata rekaan, 2FA dimatikan, reset harian
                $domain=strtolower(trim(input('platform_domain'))); if($domain!=='' && !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*(:\d{1,5})?$/D',$domain)) throw new DomainException('Domain induk tidak sah (cth. urustugas.sekolah.my, tanpa https://).');
                if(!$demo) [$name,$email]=validate_admin(input('name'),input('email'),input('password'),input('password_confirm'));
                $fh=fopen($root.'/storage/install.lck','c'); if(!$fh || !flock($fh,LOCK_EX|LOCK_NB)) throw new DomainException('Pemasangan lain sedang berjalan. Cuba sebentar lagi.');
                try {
                    if(is_file(install_lock_path())) throw new DomainException('Sistem telah dipasang.');
                    $existing=env('APP_KEY'); $raw=base64_decode($existing,true);
                    $appKey=($raw!==false && strlen($raw)===SODIUM_CRYPTO_SECRETBOX_KEYBYTES)?$existing:base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
                    $content=env_content($c,$appKey,request_is_https(),($demo?['REQUIRE_2FA'=>'0','DEMO_MODE'=>'1']:[])+['PLATFORM_DOMAIN'=>$domain,'TENANT_PRIMARY'=>'path']);
                    if(!write_env_file($content)) { $envFallback=$content; throw new DomainException('Folder projek tidak membenarkan PHP menulis fail .env. Salin kandungan di bawah ke fail .env (di sebelah folder app/), kemudian klik Pasang sekali lagi.'); }
                    foreach(['APP_KEY'=>$appKey,'DB_HOST'=>$c['host'],'DB_PORT'=>$c['port'],'DB_NAME'=>$c['name'],'DB_USER'=>$c['user'],'DB_PASS'=>$c['pass'],'APP_ENV'=>'production','SESSION_SECURE'=>request_is_https()?'1':'0','REQUIRE_2FA'=>$demo?'0':'1','DEMO_MODE'=>$demo?'1':'0','PLATFORM_DOMAIN'=>$domain,'TENANT_PRIMARY'=>'path'] as $k=>$v) $_ENV[$k]=$v;
                    db($pdo);
                    try {
                        install_schema();
                        if($demo) demo_platform_seed();
                        else create_platform_admin($name,$email,input('password'));
                        write_install_lock($demo?'web-demo':'web');
                    } catch(Throwable $ex) {
                        error_log('UrusTugas install gagal: '.get_class($ex).': '.$ex->getMessage().' @ '.$ex->getFile().':'.$ex->getLine()); // punca sebenar untuk log pelayan
                        // Pulihkan: DB disahkan kosong sebelum ini, jadi semua jadual yang wujud kini dicipta oleh pemasang.
                        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                        foreach($pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec('DROP TABLE `'.str_replace('`','',$t).'`');
                        $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); @unlink(install_lock_path());
                        throw $ex instanceof DomainException?$ex:new DomainException('Pemasangan gagal dan telah dibatalkan sepenuhnya. Cuba sekali lagi.');
                    }
                } finally { flock($fh,LOCK_UN); fclose($fh); }
                $_SESSION=[]; session_regenerate_id(true);
                $selfLocked=@rename(__FILE__,__DIR__.'/install.php.locked-'.bin2hex(random_bytes(4)));
                $done=['Pemasangan selesai',['Konfigurasi .env dicipta dengan APP_KEY rawak (salinan lama, jika ada, disimpan sebagai .env.bak-*).',$demo?'Mod demo aktif: 2 sekolah demo ('.implode(', ',array_column(DEMO_SCHOOLS,0)).') dengan data rekaan terasing, setiap satu dengan 4 akaun demo. Panel platform: '.DEMO_PLATFORM[1].' / '.DEMO_PLATFORM[2].'.':'Pangkalan data dipasang dan akaun Pentadbir Platform '.$email.' dicipta. Log masuk di /platform/ untuk menambah sekolah.',$selfLocked?'Fail install.php telah dinyahaktifkan secara automatik.':'Pemasang dikunci oleh storage/installed.lock. Anda boleh memadam public_html/install.php.']];
                $mode='locked';
            }
        }
    } catch(DomainException $ex) { $error=$ex->getMessage(); }
}
$req=($mode==='install')?web_requirements():[];
if($mode==='locked' && !$done) http_response_code(403);
$v=fn(string $k,string $d='')=>e(input($k)!==''?input($k):$d);
?><!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Pemasang · UrusTugas</title><link rel="stylesheet" href="<?= e(asset('app.css')) ?>"><script src="<?= e(asset('app.js')) ?>" defer></script></head><body class="installer">
<main class="install-wrap"><div class="install-head"><span class="brand"><span class="brand-icon">u.</span><span class="brand-text">urus<span class="brand-light">tugas</span></span></span><span class="small-pill">Pemasang v<?= e(APP_VERSION) ?></span></div>
<?php if($done): ?>
 <section class="panel padded"><h1>✓ <?= e($done[0]) ?></h1><ul class="done-list"><?php foreach($done[1] as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul>
 <?php if($done[0]==='Pemasangan selesai' && demo_mode()): ?><div class="notice"><strong>Demo sedia.</strong> Kongsi pautan laman ini dengan penguji. Data dijana semula secara automatik setiap hari pada lawatan pertama selepas tengah malam. Untuk reset pada masa tetap, tambah Cron Job di cPanel: <code>php <?= e(dirname(__DIR__)) ?>/bin/demo-reset.php</code></div><?php elseif($done[0]==='Pemasangan selesai'): ?><div class="notice"><strong>Langkah seterusnya:</strong> log masuk, imbas kod QR 2FA dengan aplikasi pengesah di telefon, dan simpan 8 kod pemulihan. Kemudian buka <strong>Tetapan & Tema</strong> untuk logo dan warna, dan <strong>Pengguna & Audit</strong> untuk menambah guru.</div><?php endif; ?>
 <a class="btn primary" href="/platform/">Pergi ke panel platform →</a> <a class="btn outline" href="/">Direktori sekolah</a></section>
<?php elseif($mode==='locked'): ?>
 <section class="panel padded"><h1>Pemasang dikunci</h1><p>Sistem ini telah dipasang. Untuk keselamatan, pemasang tidak boleh dijalankan semula.</p><?php if($dbDown): ?><div class="notice error">Pangkalan data tidak dapat dihubungi. Semak pelayan MySQL dan butiran DB dalam .env.</div><?php endif; ?><a class="btn outline" href="/">Ke halaman log masuk</a></section>
<?php elseif($mode==='upgrade'): ?>
 <section class="panel padded"><h1>Naik taraf pangkalan data</h1><p class="muted">Kod baharu memerlukan beberapa jadual/lajur tambahan. Tiada data dipadam. Sahkan dengan kata laluan pangkalan data yang digunakan oleh sistem ini.</p>
 <?php if($error): ?><div role="status" class="notice error"><?= e($error) ?></div><?php endif; ?>
 <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><label class="field">Kata laluan pangkalan data (<?= e(env('DB_USER')) ?>@<?= e(env('DB_NAME')) ?>)<input name="db_pass_confirm" type="password" required autocomplete="off"></label><button class="btn primary" type="submit">Naik taraf sekarang →</button></form></section>
<?php else: ?>
 <form method="post" class="install-form" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
 <section class="panel padded"><h1>Pasang UrusTugas</h1><p class="muted">Isi tiga bahagian di bawah dan klik <strong>Pasang</strong>. Pemasang akan mencipta konfigurasi, kunci enkripsi dan pangkalan data secara automatik — tiada fail perlu diedit.</p>
 <?php if($error): ?><div role="status" class="notice error"><?= e($error) ?></div><?php endif; ?>
 <?php if($notice): ?><div role="status" class="notice success"><?= e($notice) ?></div><?php endif; ?>
 <?php if($envFallback): ?><label class="field">Kandungan .env<textarea readonly rows="12" class="env-box"><?= e($envFallback) ?></textarea></label><?php endif; ?>
 <h3 class="subhead"><span class="step-no">1</span> Semakan pelayan</h3>
 <ul class="req-list"><?php foreach($req as [$label,$status,$detail]): ?><li class="req-<?= $status ?>"><span><?= ['ok'=>'✓','warn'=>'!','fail'=>'✗'][$status] ?></span><div><strong><?= e($label) ?></strong><small><?= e($detail) ?></small></div></li><?php endforeach; ?></ul>
 </section>
 <?php if(requirements_ok($req)): ?>
 <section class="panel padded spaced"><h3 class="subhead first"><span class="step-no">2</span> Pangkalan data</h3>
  <p class="muted">Cipta pangkalan data kosong dan pengguna di <strong>cPanel → MySQL® Databases</strong>, kemudian tambah pengguna ke pangkalan data dengan <strong>ALL PRIVILEGES</strong>. Nama biasanya berawalan nama akaun cPanel, contoh <code>apasaja_koku</code>.</p>
  <div class="form-grid install-grid">
   <label class="field">Nama pangkalan data<input name="db_name" required maxlength="64" value="<?= $v('db_name',env('DB_NAME')==='urustugas'?'':env('DB_NAME')) ?>" placeholder="akaun_urustugas" spellcheck="false"></label>
   <label class="field">Pengguna pangkalan data<input name="db_user" required maxlength="80" value="<?= $v('db_user',env('DB_USER')) ?>" placeholder="akaun_urustugasuser" spellcheck="false"></label>
   <label class="field">Kata laluan pangkalan data<input name="db_pass" type="password" required maxlength="200" value="<?= e(input('db_pass')) ?>" autocomplete="off"></label>
   <details class="field advanced"><summary>Tetapan lanjutan (hos & port)</summary><div class="form-grid install-grid"><label class="field">Hos<input name="db_host" maxlength="120" value="<?= $v('db_host',env('DB_HOST','localhost')) ?>"></label><label class="field">Port<input name="db_port" inputmode="numeric" maxlength="5" value="<?= $v('db_port',env('DB_PORT','3306')) ?>"></label></div></details>
  </div>
  <button class="btn outline" type="submit" name="action" value="test" formnovalidate>Uji sambungan</button>
 </section>
 <section class="panel padded spaced"><h3 class="subhead first"><span class="step-no">3</span> Pentadbir Platform</h3>
  <div class="form-grid install-grid">
   <label class="field full">Domain induk (pilihan — untuk pautan e-mel & subdomain sekolah)<input name="platform_domain" maxlength="120" value="<?= $v('platform_domain') ?>" placeholder="urustugas.contoh.my"></label>
   <label class="field">Nama pentadbir platform<input name="name" maxlength="120" required value="<?= $v('name') ?>"></label>
   <label class="field">E-mel (untuk log masuk /platform/)<input name="email" type="email" required maxlength="190" value="<?= $v('email') ?>" autocomplete="username"></label>
   <label class="field">Kata laluan (minimum 12 aksara)<input name="password" type="password" minlength="12" required maxlength="128" autocomplete="new-password"></label>
   <label class="field">Ulang kata laluan<input name="password_confirm" type="password" required minlength="12" maxlength="128" autocomplete="new-password"></label>
  </div>
  <p class="muted">2FA (aplikasi pengesah di telefon) akan disediakan semasa log masuk pertama.</p>
  <button class="btn primary install-go" type="submit" name="action" value="install">Pasang & kunci →</button>
 </section>
 <section class="panel padded spaced install-demo"><h3 class="subhead first"><span class="step-no">★</span> Atau: pasang sebagai demo</h3>
  <p class="muted">Untuk diberi kepada orang lain menguji. Bahagian 3 tidak perlu diisi. Pemasang akan:</p>
  <ul class="done-list"><li>Mencipta 2 sekolah demo (DEM0001, DEM0002), setiap satu dengan data rekaan sendiri.</li><li>Mencipta 4 akaun kongsi (pentadbir, PK, GB/Pengetua, guru) di setiap sekolah dan akaun panel platform demo. 2FA dimatikan.</li><li>Menjana semula semua data setiap hari.</li><li>Menyekat tukar kata laluan/e-mel, urus pengguna, tetapan e-mel dan muat naik fail.</li></ul>
  <div class="notice error"><strong>Jangan guna untuk sekolah sebenar.</strong> Reset harian memadam SEMUA data dalam pangkalan data ini. Gunakan pangkalan data berasingan khas untuk demo.</div>
  <button class="btn outline install-go" type="submit" name="action" value="install_demo" formnovalidate>Pasang demo →</button>
 </section>
 <?php endif; ?>
 </form>
<?php endif; ?>
<p class="install-foot muted">Halaman ini tidak diindeks enjin carian dan terkunci kekal selepas pemasangan.</p></main></body></html>
