<?php
declare(strict_types=1);
/* Halaman awam platform (corak utama.php delima-multi): bar navigasi gelap, hero + kotak carian sekolah,
 * ciri, pakej Free/Pro, keselamatan dan footer. lp_head()/lp_foot() juga digunakan oleh /daftar, /terma,
 * /polisi dan /lapor-keselamatan supaya semua halaman awam platform serupa. Patuh CSP: tiada style= atau skrip sebaris. */
require_once __DIR__.'/icons.php';

function lp_brand(): string { return '<a class="lp-brand" href="/">'.plat_brand_mark().'<span>'.plat_brand_name('b').'<small>'.e(plat_tagline()).'</small></span></a>'; }
function lp_head(string $title,bool $directory=false): void {
    p_head($title,'<link rel="stylesheet" href="/?page=lp-css&amp;v='.e(lp_css_ver()).'">'.($directory?'<link rel="stylesheet" href="'.e(asset('directory.css')).'">':'')); ?>
<?= function_exists('icons_sprite')?icons_sprite():'' ?><header class="lp-nav"><div class="lp-wrap lp-nav-row"><?= lp_brand() ?>
<nav class="lp-pautan" aria-label="Halaman awam"><a href="/direktori"<?= $directory?' aria-current="page"':'' ?>>Direktori</a><?php if($directory): ?><a href="#bantuan-direktori">Bantuan</a><?php else: ?><a href="/#ciri">Ciri</a><a href="/#pakej">Pakej</a><a href="/#keselamatan">Keselamatan</a><?php endif; ?><?php if(faq_items(true)): ?><a href="/faq">FAQ</a><?php endif; ?><?php if(registration_open()): ?><a class="lp-pill" href="/daftar"><?= icon('school') ?> Daftar sekolah</a><?php endif; ?></nav></div></header>
<main class="lp-main"><?php
}
function lp_foot(): void {
    $email=pset('emel_notis'); ?></main>
<footer class="lp-footer"><div class="lp-wrap lp-fgrid<?= lp_links()?' lp-f5':'' ?>">
<div class="lp-fjenama"><?= lp_brand() ?><p>Agihan tugas guru dalam talian: guru, jawatan, lantikan, auto-agihan, kelulusan dan surat lantikan — satu tempat untuk setiap sekolah.</p>
<ul class="lp-flencana"><li><?= icon('lock') ?> Data setiap sekolah diasingkan</li><li><?= icon('shield') ?> Selaras PDPA 2010</li></ul></div>
<nav class="lp-fkol" aria-label="Platform"><h3>Platform</h3><a href="/direktori">Cari sekolah</a><?php if(registration_open()): ?><a href="/daftar">Daftar sekolah</a><?php endif; ?><?php if(faq_items(true)): ?><a href="/faq"><?= e(faq_title()) ?></a><?php endif; ?><a href="/lapor-keselamatan">Lapor isu keselamatan</a></nav>
<?php if($links=lp_links()): ?><nav class="lp-fkol" aria-label="<?= e(lp_links_title()) ?>"><h3><?= e(lp_links_title()) ?></h3><?php foreach($links as [$l,$u]): $ext=preg_match('~^https?://~i',$u); ?><a href="<?= e($u) ?>"<?= $ext?' target="_blank" rel="noopener noreferrer"':'' ?>><?= e($l) ?></a><?php endforeach; ?></nav><?php endif; ?>
<nav class="lp-fkol" aria-label="Dokumen"><h3>Dokumen</h3><a href="/terma">Terma &amp; Syarat</a><a href="/polisi">Polisi Perkhidmatan &amp; Privasi</a><a href="/.well-known/security.txt">security.txt</a></nav>
<div class="lp-fkol"><h3>Hubungi</h3><?php if($email!==''): ?><a href="mailto:<?= e($email) ?>"><?= icon('info') ?> <?= e($email) ?></a><?php endif; ?><a class="lp-btn-platform" href="/platform/"><?= icon('shield') ?> Log Masuk Pentadbir Platform</a></div>
</div><div class="lp-fbawah"><div class="lp-wrap lp-fbawah-row"><span>© <?= date('Y') ?> <?= e(platform_name()) ?>. Hak cipta terpelihara.</span><span>Bukan produk rasmi KPM.</span></div></div></footer></body></html><?php
}

/** / — halaman utama platform: cari sekolah (kod tepat → terus ke laman sekolah), ciri, pakej & keselamatan. */
function platform_landing(): never {
    $q=mb_substr(trim(input('q','get')),0,80); $rows=[];
    if($q!==''){
        $code=strtoupper((string)preg_replace('/\s+/','',$q));
        if(preg_match(SCHOOL_CODE_RE,$code) && ($s=school_find($code)) && $s['status']==='aktif') redirect(school_url($s['code'],'/'));
        if(mb_strlen($q)>=3) $rows=array_slice(array_values(array_filter(platform_schools($q),fn($s)=>$s['status']==='aktif')),0,20);
    }
    $total=(int)query('SELECT COUNT(*) FROM schools WHERE status="aktif"')->fetchColumn();
    $free=pset('free_aktif')==='1'; $trial=(int)pset('percubaan_hari'); $price=ringgit(pay_price()); $daftar=registration_open(); $y=icon('check');
    lp_head('Agihan tugas guru sekolah'); ?>
<section class="lp-hero" id="cari"><div class="lp-wrap lp-hero-grid">
<div class="lp-hero-kiri"><span class="hero-pill"><span class="dot"></span><?= e(lp_text('lencana')) ?> · <?= date('Y') ?></span>
<h1><?= e(lp_text('t1')) ?><br><em><?= e(lp_text('t2')) ?></em></h1>
<p><?= e(lp_text('teks')) ?></p>
<div class="lp-garis" aria-hidden="true"></div>
<?php if($total): ?><p class="lp-kecil"><?= $total ?> sekolah menggunakan <?= e(platform_name()) ?>.</p><?php endif; ?></div>
<div class="panel padded lp-cari"><h2>Pilih sekolah anda</h2>
<form method="get" action="/direktori" role="search" class="stack"><label class="field">Kod sekolah atau nama sekolah<input name="q" value="<?= e($q) ?>" maxlength="80" placeholder="cth. CEA1234 atau SMK Muadzam" autocomplete="off" autocapitalize="characters" required minlength="3"></label>
<p class="muted lp-hint">Kod sekolah KPM boleh didapati daripada pentadbir sekolah. Laman sekolah anda: <code><?= e(school_url('KODSEKOLAH','/')) ?></code></p>
<button class="btn primary">Cari <?= icon('arrow') ?></button></form><p class="lp-daftar-pautan"><a class="text-link" href="/direktori">Lihat semua sekolah →</a></p>
<?php if($q!==''): ?><div class="lp-senarai"><?php if(!$rows): ?><p class="muted">Tiada sekolah dijumpai untuk “<?= e($q) ?>”.<?= mb_strlen($q)<3?' Taip sekurang-kurangnya 3 aksara.':' Sekolah anda mungkin belum mendaftar.' ?></p><?php endif; ?>
<?php foreach($rows as $s): ?><a class="lp-sek" href="<?= e(school_url($s['code'],'/')) ?>"><span class="lp-sek-kod"><?= e($s['code']) ?></span><span><b><?= e($s['name']) ?></b><?php if($s['state']!==''): ?><small><?= e($s['state']) ?></small><?php endif; ?></span><?= icon('chev-r') ?></a><?php endforeach; ?></div><?php endif; ?>
<?php if($daftar): ?><p class="lp-daftar-pautan"><a class="text-link" href="/daftar">Daftar sekolah baharu →</a></p><?php endif; ?></div>
</div></section>

<section class="lp-seksyen" id="ciri"><div class="lp-wrap">
<div class="lp-kepala"><span class="eyebrow teal">Untuk sekolah</span><h2>Agihan tugas guru di satu tempat</h2><p>Pentadbir & PK merancang, Guru Besar / Pengetua meluluskan, setiap guru melihat tugas muktamadnya.</p></div>
<div class="lp-ciri-grid">
<article class="panel padded lp-ciri"><span class="lp-ikon"><?= icon('users') ?></span><h3>Guru &amp; jawatan</h3><p>Senarai guru dengan opsyen dan kapasiti, jawatan mengikut bidang (Pentadbiran, Kurikulum, HEM, Kokurikulum) dan pemberat beban.</p></article>
<article class="panel padded lp-ciri"><span class="lp-ikon"><?= icon('clipboard') ?></span><h3>Auto-agihan adil</h3><p>Agih jawatan kosong atau imbang semula berdasarkan beban, kapasiti, had tugas berat dan syarat setiap jawatan — pratonton dahulu sebelum guna.</p></article>
<article class="panel padded lp-ciri"><span class="lp-ikon"><?= icon('check') ?></span><h3>Aliran kelulusan</h3><p>Draf → Semakan → Lulus → Muktamad. Data dikunci semasa semakan; GB / Pengetua meluluskan versi yang sama yang dihantar.</p></article>
<article class="panel padded lp-ciri"><span class="lp-ikon"><?= icon('file') ?></span><h3>Surat lantikan</h3><p>Surat lantikan setiap guru daripada senarai muktamad, dengan logo sekolah dan Jata Negara — sedia dicetak.</p></article>
<article class="panel padded lp-ciri"><span class="lp-ikon"><?= icon('calendar') ?></span><h3>API &amp; hebahan</h3><p>API untuk sistem lain (cth. Sekolah Koku) menyegerak guru dan membaca lantikan, serta hebahan kepada guru.</p></article>
<article class="panel padded lp-ciri"><span class="lp-ikon"><?= icon('globe') ?></span><h3>Pautan sekolah sendiri</h3><p>Setiap sekolah mendapat ruang tersendiri berdasarkan kod sekolah KPM: <code><?= e(school_url('KODSEKOLAH','/')) ?></code></p></article>
</div></div></section>

<section class="lp-seksyen lp-alt" id="pakej"><div class="lp-wrap">
<div class="lp-kepala"><span class="eyebrow teal">Pakej</span><h2><?= $free?'Free atau Pro':'Satu harga, semua ciri' ?></h2><p>Sekolah baharu mendapat Pro percuma <?= $trial ?> hari<?= $free?'. Selepas itu kekal di Free secara automatik — data tidak hilang, tiada caj tersembunyi.':'.' ?></p></div>
<div class="lp-pakej-grid<?= $free?'':' satu' ?>">
<?php if($free): ?><article class="panel padded lp-pakej"><h3>Free</h3><p class="lp-harga"><b>RM0</b> <span>selamanya</span></p><p class="muted">Untuk sekolah kecil atau mencuba.</p>
<ul class="lp-senarai"><li><?= $y ?><span>Sehingga <b><?= (int)pset('free_guru') ?> guru aktif</b> &amp; <?= (int)pset('free_jawatan') ?> jawatan</span></li><li><?= $y ?><span>Sehingga <?= (int)pset('free_pengguna') ?> pengguna aktif</span></li><li><?= $y ?><span>Agihan manual &amp; aliran kelulusan</span></li><li><?= $y ?><span>Surat lantikan individu</span></li></ul>
<?php if($daftar): ?><a class="btn outline lp-btn-blok" href="/daftar">Daftar percuma</a><?php endif; ?></article><?php endif; ?>
<article class="panel padded lp-pakej pro"><span class="lp-lencana">Disyorkan</span><h3>Pro</h3><p class="lp-harga"><b><?= e($price) ?></b> <span>/ tahun / sekolah</span></p><p class="muted">Semua ciri, tanpa had guru, jawatan atau pengguna.</p>
<ul class="lp-senarai"><li><?= $y ?><span><b>Guru, jawatan &amp; pengguna tanpa had</b></span></li><li><?= $y ?><span>Auto-agihan &amp; jawatan pukal</span></li><li><?= $y ?><span>Surat lantikan pukal / PDF &amp; API</span></li><li><?= $y ?><span>Sokongan melalui tiket</span></li></ul>
<?php if($daftar): ?><a class="btn primary lp-btn-blok" href="/daftar">Cuba Pro <?= $trial ?> hari percuma</a><?php endif; ?>
<p class="muted lp-nota">Bayar melalui FPX, pindahan bank / pesanan belian (PO) atau ditaja PPD.<?= $free?' Jika langganan tamat, sekolah kembali ke Free.':'' ?></p></article>
</div></div></section>

<section class="lp-seksyen" id="keselamatan"><div class="lp-wrap">
<div class="lp-kepala"><span class="eyebrow teal">Keselamatan &amp; privasi</span><h2>Data guru dilindungi</h2><p>Direka mengikut prinsip PDPA: simpan sesedikit mungkin, sulitkan apa yang disimpan, rekod setiap tindakan.</p></div>
<div class="lp-ciri-grid">
<article class="panel padded lp-ciri"><span class="lp-ikon"><?= icon('lock') ?></span><h3>Diasingkan &amp; disulitkan</h3><p>Setiap sekolah mempunyai kunci penyulitan sendiri. Data sulit (rahsia 2FA, kunci API) disimpan tersulit dan tidak boleh dipadankan dengan sekolah lain.</p></article>
<article class="panel padded lp-ciri"><span class="lp-ikon"><?= icon('key') ?></span><h3>Pengesahan dua langkah</h3><p>2FA wajib untuk pentadbir, kata laluan kuat dan had cubaan log masuk untuk semua akaun.</p></article>
<article class="panel padded lp-ciri"><span class="lp-ikon"><?= icon('list') ?></span><h3>Log audit kalis usik</h3><p>Setiap tindakan direkod dalam rantai log bertandatangan — pengubahan atau pemadaman dapat dikesan.</p></article>
<article class="panel padded lp-ciri"><span class="lp-ikon"><?= icon('shield') ?></span><h3>Perlindungan IP &amp; CAPTCHA</h3><p>Sekatan IP automatik, senarai IP pentadbir dan CAPTCHA menghalang percubaan meneka kata laluan.</p></article>
<article class="panel padded lp-ciri"><span class="lp-ikon"><?= icon('database') ?></span><h3>Sandaran tersulit</h3><p>Sandaran berkala disulitkan sebelum disimpan di luar pelayan. Integriti fail aplikasi disemak secara automatik.</p></article>
<article class="panel padded lp-ciri"><span class="lp-ikon"><?= icon('flag') ?></span><h3>Laporan keselamatan</h3><p>Jumpa kelemahan? Laporkan secara sulit melalui <a class="text-link" href="/lapor-keselamatan">borang laporan keselamatan</a>.</p></article>
</div>
<?php if($daftar): ?><p class="lp-tengah"><a class="btn primary" href="/daftar"><?= icon('school') ?> Daftar sekolah</a></p><?php endif; ?></div></section>
<?php faq_landing_section(); lp_foot(); exit;
}
