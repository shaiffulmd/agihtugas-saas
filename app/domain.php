<?php
declare(strict_types=1);
/* Panel Platform → Subdomain Sekolah (corak platform/domain.php delima-multi).
 * Domain induk + subdomain sekolah KOD.domain (semua sekolah atau sekolah Pro sahaja). Tetapan ini mengatasi PLATFORM_DOMAIN &
 * TENANT_PRIMARY dalam .env. Sebelum dihidupkan, pemasangan menguji DNS wildcard, SSL wildcard dan vhost dengan meminta
 * uji0000.<domain>/hos-semak dan mengesahkan jawapan bertandatangan HMAC (kunci platform) daripada pemasangan ini. */

function host_check_sig(string $c): string { return hash_hmac('sha256','hos-semak:'.$c,platform_key()); }
/** GET /hos-semak?c=… — jawapan bertandatangan (tiada data lain didedahkan). */
function host_check_reply(): never {
    while(ob_get_level()>0) @ob_end_clean();
    $c=(string)($_GET['c']??''); header('Content-Type: application/json'); header('Cache-Control: no-store');
    if(!preg_match('/^[a-f0-9]{32}$/D',$c)){ http_response_code(400); exit('{}'); }
    echo json_encode(['tanda'=>host_check_sig($c)]); exit;
}
function domain_clean(string $d): string {
    $d=strtolower(trim($d)); $d=(string)preg_replace('#^https?://|/.*$#','',$d); return (string)preg_replace('/^(www|\*)\./','',$d);
}
function domain_no_port(string $d): string { return (string)preg_replace('/:\d+$/','',$d); }
/**
 * Uji uji0000.<domain>/hos-semak: DNS, SSL & vhost. IP dipin (CURLOPT_RESOLVE); hanya alamat awam dibenarkan kecuali APP_ENV=local.
 * Pulangkan [ok, mesej teks biasa].
 */
function subdomain_test(string $domain): array {
    if(!function_exists('curl_init')) return [false,'Sambungan cURL tiada pada pelayan — semakan automatik tidak dapat dibuat.'];
    $host='uji0000.'.domain_no_port($domain); $port=preg_match('/:(\d+)$/',$domain,$m)?(int)$m[1]:(tenant_scheme()==='https'?443:80);
    $ips=@gethostbynamel($host);
    if(!$ips) return [false,"DNS: $host tidak ditemui. Tambah rekod DNS wildcard * untuk ".domain_no_port($domain).' (langkah 1).'];
    $ip=$ips[0];
    if(env('APP_ENV')!=='local' && !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return [false,"DNS: $host menghala ke alamat dalaman ($ip) — tidak dibenarkan."];
    $c=bin2hex(random_bytes(16)); $url=tenant_scheme().'://uji0000.'.$domain.'/hos-semak?c='.$c;
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS|CURLPROTO_HTTP,CURLOPT_USERAGENT=>'UrusTugas-SemakSubdomain',CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ip]]);
    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $eno=curl_errno($ch); $err=curl_error($ch);
    $d=domain_no_port($domain);
    if(in_array($eno,[35,51,58,60],true)) return [false,"SSL: sijil tidak meliputi *.$d (langkah 2). $err"];
    if($eno!==0) return [false,"Sambungan ke $host gagal: $err"];
    if($code>=300 && $code<400) return [false,"Pelayan melencongkan $host (HTTP $code) — subdomain wildcard belum dihalakan ke folder aplikasi ini (langkah 3)."];
    $j=json_decode((string)$body,true);
    if($code!==200 || !is_array($j) || !isset($j['tanda'])) return [false,"$host menjawab HTTP $code tetapi bukan daripada aplikasi ini — subdomain wildcard belum dihalakan ke folder yang sama (langkah 3)."];
    if(!hash_equals(host_check_sig($c),(string)$j['tanda'])) return [false,"$host dijawab oleh pemasangan UrusTugas yang lain."];
    return [true,"Berjaya: $host sampai ke pemasangan ini (DNS, SSL & subdomain wildcard OK)."];
}

function handle_domain_platform(string $action,array $me): never {
    $domain=domain_clean(input('domain_induk'));
    if($domain!=='' && !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+(:\d{1,5})?$/D',$domain) && !preg_match('/^localhost(:\d{1,5})?$/D',$domain)) throw new DomainException('Domain tidak sah (cth. urustugas.my, tanpa www atau https://).');
    if($action==='p_dom_test'){
        if($domain==='') throw new DomainException('Masukkan domain induk dahulu.');
        [$ok,$msg]=subdomain_test($domain); $_SESSION['dom_test']=[$ok,$msg,$domain]; platform_log($ok?'subdomain_test_ok':'subdomain_test_failed',null,$msg);
        redirect('/platform/?p=domain');
    }
    if($action!=='p_dom_save') error_page(400);
    $on=input('subdomain_aktif')==='1'; $who=input('subdomain_siapa')==='pro'?'pro':'semua';
    if($on && $domain==='') throw new DomainException('Masukkan domain induk untuk menghidupkan subdomain.');
    if($domain!=='' && domain_no_port($domain)!==domain_no_port(domain_clean(request_host())) && input('paksa')!=='1')
        throw new DomainException('Anda membuka panel ini melalui '.request_host().', bukan '.$domain.'. Buka panel melalui domain induk tersebut, atau tandakan "Simpan juga" jika pasti.');
    $was=subdomain_on();
    if($on && !$was && input('paksa')!=='1'){
        [$ok,$msg]=subdomain_test($domain); $_SESSION['dom_test']=[$ok,$msg,$domain];
        if(!$ok){ platform_log('subdomain_test_failed',null,$msg); throw new DomainException('Subdomain belum berfungsi — tidak dihidupkan. '.$msg); }
    }
    $before=[platform_domain(),$was,subdomain_who()];
    pset_set(['domain_induk'=>$domain,'subdomain_aktif'=>$on?'1':'0','subdomain_siapa'=>$who]);
    platform_log('subdomain_saved',null,'domain '.($domain?:'(.env)').', subdomain '.($on?'HIDUP ('.($who==='pro'?'Pro sahaja':'semua sekolah').')':'mati').($on && !$was && input('paksa')==='1'?' — dipaksa tanpa ujian':''));
    flash($on?'Subdomain sekolah aktif: pautan sekolah kini KOD.'.domain_no_port($domain).($who==='pro'?' (sekolah Pro sahaja)':'').'. Pautan lama /KOD/ dilencongkan secara automatik; pengguna perlu log masuk semula sekali.':'Tetapan disimpan. Pautan sekolah: '.($domain?:platform_domain()).'/KOD/.');
    redirect($before[0]!==platform_domain() && $domain!==''?tenant_scheme().'://'.$domain.'/platform/?p=domain':'/platform/?p=domain');
}

function platform_page_domain(): void {
    $test=$_SESSION['dom_test']??null; unset($_SESSION['dom_test']);
    $domain=platform_domain(); $form=$domain!==''?$domain:domain_clean(request_host()); $show=domain_no_port($form?:'urustugas.my');
    $ex=strtolower((string)(query('SELECT code FROM schools WHERE status="aktif" ORDER BY id LIMIT 1')->fetchColumn()?:'CEA1234'));
    $fromEnv=pset('domain_induk')==='' && env('PLATFORM_DOMAIN')!=='';
    $proCount=(int)query('SELECT COUNT(*) FROM schools WHERE status="aktif" AND expires_on>=CURDATE()')->fetchColumn(); ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Subdomain Sekolah</h1><p class="muted">Pautan sekolah dalam bentuk <code><?= e($ex) ?>.<?= e($show) ?></code> sebagai ganti <code><?= e($show) ?>/<?= e(strtoupper($ex)) ?>/</code>. Kedua-dua bentuk sentiasa berfungsi — bentuk lama dilencongkan, jadi pautan dan kod QR yang sudah diedarkan tidak rosak.</p></div></div>
<?php if($test): ?><div class="notice <?= $test[0]?'success':'error' ?>" role="status"><?= e($test[1]) ?></div><?php endif; ?>
<?php if($domain!=='' && domain_no_port($domain)!==domain_no_port(domain_clean(request_host()))): ?><div class="notice info" role="status">Anda membuka panel ini melalui <strong><?= e(request_host()) ?></strong>. Domain induk ialah <a class="text-link" href="<?= e(tenant_scheme().'://'.$domain.'/platform/?p=domain') ?>"><?= e($domain) ?></a>.</div><?php endif; ?>
<div class="dashboard-grid pay-grid">
<section class="panel padded"><h3>Tetapan</h3>
<p class="notice <?= subdomain_on()?'success':'info' ?>"><span><?php if(subdomain_on()): ?><strong>Aktif</strong> untuk <?= subdomain_who()==='pro'?'<strong>'.$proCount.'</strong> sekolah Pro':'semua sekolah' ?>. Contoh: <code><?= e($ex.'.'.domain_no_port($domain)) ?></code><?php else: ?><strong>Tidak aktif.</strong> Pautan sekolah: <code><?= e(($domain?:request_host()).'/'.strtoupper($ex).'/') ?></code><?php endif; ?></span></p>
<?php if($fromEnv): ?><p class="muted">Kini mengikut <code>.env</code> (PLATFORM_DOMAIN<?= env('TENANT_PRIMARY')==='subdomain'?', TENANT_PRIMARY=subdomain':'' ?>). Simpan di sini untuk mengurusnya dari panel.</p><?php endif; ?>
<form method="post" class="form-grid" autocomplete="off"><?= csrf_field() ?>
<label class="field full">Domain induk<input name="domain_induk" value="<?= e($form) ?>" placeholder="urustugas.my" spellcheck="false"><small class="muted">Tanpa <code>www</code> atau <code>https://</code>. Halaman platform kekal di domain ini.</small></label>
<label class="check full"><input type="checkbox" name="subdomain_aktif" value="1"<?= subdomain_on()?' checked':'' ?>> <span><strong>Hidupkan subdomain sekolah</strong> — cth. <code><?= e($ex.'.'.$show) ?></code></span></label>
<fieldset class="pay-gw full"><legend>Siapa mendapat subdomain?</legend>
<label class="check"><input type="radio" name="subdomain_siapa" value="semua"<?= subdomain_who()==='semua'?' checked':'' ?>> <span><strong>Semua sekolah</strong></span></label>
<label class="check"><input type="radio" name="subdomain_siapa" value="pro"<?= subdomain_who()==='pro'?' checked':'' ?>> <span><strong>Sekolah Pro sahaja</strong> — sekolah Free kekal <code><?= e($show) ?>/KOD/</code>; apabila Pro tamat, pautan subdomain dilencongkan ke /KOD/ secara automatik.</span></label></fieldset>
<label class="check full"><input type="checkbox" name="paksa" value="1"> <span>Simpan juga walaupun ujian automatik gagal atau panel dibuka melalui domain lain (cth. pelayan tidak dapat menyambung ke dirinya sendiri).</span></label>
<div class="button-group full"><button class="btn primary" name="action" value="p_dom_save">Simpan</button><button class="btn outline" name="action" value="p_dom_test">Uji sekarang</button></div></form>
<p class="muted">Ujian dijalankan secara automatik sebelum subdomain dihidupkan. Selepas ditukar, pengguna perlu log masuk semula sekali (kuki sesi mengikut alamat).</p></section>
<section class="panel padded"><h3>Persediaan pelayan (sekali sahaja)</h3><ol class="lp-langkah">
<li><strong>DNS wildcard.</strong> Di Cloudflare (atau pengurus DNS <?= e($show) ?>), tambah rekod <code>A</code> bernama <code>*</code> → IP pelayan hosting (sama seperti rekod utama <code>@</code>). Boleh dibiar <em>Proxied</em> (awan oren).</li>
<li><strong>SSL wildcard.</strong> Cloudflare Universal SSL meliputi <code>*.<?= e($show) ?></code> secara automatik — tetapkan SSL/TLS kepada <em>Full</em>. Tanpa Cloudflare: AutoSSL cPanel / Let's Encrypt wildcard untuk <code>*.<?= e($show) ?></code>.</li>
<li><strong>Subdomain wildcard di hosting.</strong> cPanel → <em>Domains</em> → <em>Create A New Domain</em>: <code>*.<?= e($show) ?></code>, nyahtanda "Share document root" dan tetapkan folder yang <strong>sama</strong> dengan domain utama (folder <code>public_html</code> aplikasi ini). DirectAdmin: <em>Custom HTTPD</em> → <code>ServerAlias *.<?= e($show) ?></code>.</li>
<li><strong>CAPTCHA.</strong> Kunci Turnstile / reCAPTCHA untuk <code><?= e($show) ?></code> turut sah untuk subdomainnya — tiada perubahan.</li>
<li>Tekan <strong>Uji sekarang</strong>. Apabila berjaya, tandakan <strong>Hidupkan</strong> dan Simpan.</li></ol>
<p class="muted">Ujian meminta <code>uji0000.<?= e($show) ?>/hos-semak</code> dan mengesahkan jawapan bertandatangan daripada pemasangan ini.</p></section></div><?php
}
