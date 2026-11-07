<?php
declare(strict_types=1);
/* Halaman awam platform: navigasi terapung, hero dengan skrin sebenar + carian sekolah, ciri (keratan skrin sebenar),
 * galeri skrin, langkah, keselamatan, pakej Free/Pro, FAQ dan footer. lp_head()/lp_foot() juga digunakan oleh /daftar,
 * /direktori, /faq, /terma, /polisi dan /lapor-keselamatan supaya semua halaman awam serupa.
 * Gaya: assets/landing.css (+ warna/fon daripada Rupa Halaman Utama via /?page=lp-css); skrip: assets/landing.js.
 * Patuh CSP: tiada style= atau skrip sebaris. Gambar skrin: assets/landing/*.jpg (sekolah demo). */
require_once __DIR__.'/icons.php';

function lp_brand(): string { return '<a class="lx-brand" href="/">'.plat_brand_mark().'<span>'.plat_brand_name('b').'<small>'.e(plat_tagline()).'</small></span></a>'; }
/** Gambar skrin sebenar (assets/landing/NAMA.jpg). */
function lp_shot(string $name): string { return asset('landing/'.$name.'.jpg'); }
/** $seo: desc, path (kanonik), noindex, ld (JSON-LD), home (halaman utama: tiada ruang bawah nav). Tanpa 'path' → noindex. */
function lp_head(string $title,bool $directory=false,array $seo=[]): void {
    $can=isset($seo['path'])?seo_platform_abs($seo['path']):''; $img=seo_platform_abs(lp_has_hero()?'/?page=lp-hero&v='.rawurlencode(pset('lp_hero_ver')):lp_shot('dashboard'));
    $meta=seo_meta(['title'=>$title.' · '.platform_name(),'desc'=>$seo['desc']??lp_text('teks'),'canonical'=>$can,'image'=>$img,'site'=>platform_name(),'noindex'=>!empty($seo['noindex']) || !isset($seo['path'])]).implode('',array_map('seo_json_ld',$seo['ld']??[]));
    $faq=(bool)faq_items(true); $daftar=registration_open(); $here=$seo['path']??'';
    p_head($title,'<link rel="stylesheet" href="/?page=lp-css&amp;v='.e(lp_css_ver()).'"><link rel="stylesheet" href="'.e(asset('landing.css')).'">'.($directory?'<link rel="stylesheet" href="'.e(asset('directory.css')).'">':'').'<script src="'.e(asset('landing.js')).'" defer></script>',$meta);
    $links=[['/direktori','Direktori',$directory]];
    // Menu sama pada semua halaman awam (utama, direktori, daftar, FAQ, terma …) supaya lebar & susunan tidak berubah.
    array_push($links,['/#ciri','Ciri',false],['/#skrin','Lihat sistem',false],['/#pakej','Pakej',false]);
    if($faq) $links[]=['/faq','FAQ',$here==='/faq']; ?>
<?= function_exists('icons_sprite')?icons_sprite():'' ?><div class="lx-page"><a class="lx-skip" href="#utama">Langkau ke kandungan</a>
<header class="lx-nav" aria-label="Navigasi utama"><?= lp_brand() ?>
<nav class="lx-links" aria-label="Halaman awam"><?php foreach($links as [$u,$l,$cur]): ?><a href="<?= e($u) ?>"<?= $cur?' aria-current="page"':'' ?>><?= e($l) ?></a><?php endforeach; ?></nav>
<div class="lx-cta"><?php if($daftar): ?><a class="lx-btn lx-btn-primary" href="/daftar"<?= $here==='/daftar'?' aria-current="page"':'' ?>>Daftar sekolah</a><?php endif; ?>
<button class="lx-menu" type="button" aria-expanded="false" aria-controls="lx-sheet" aria-label="Buka menu"><span></span><span></span><span></span></button></div></header>
<div class="lx-sheet" id="lx-sheet" aria-hidden="true"><?php foreach($links as [$u,$l]): ?><a href="<?= e($u) ?>"><?= e($l) ?></a><?php endforeach; ?><a href="/#cari">Cari sekolah</a><?php if($daftar): ?><a class="lx-btn lx-btn-primary lx-btn-lg" href="/daftar">Daftar sekolah</a><?php endif; ?></div>
<main class="lp-main lx-main" id="utama"><?php if(empty($seo['home'])): ?><div class="lx-nav-gap" aria-hidden="true"></div><?php endif;
}
function lp_foot(): void {
    $email=pset('emel_notis'); $links=lp_links(); ?></main>
<footer class="lx-footer"><div class="lx-wrap"><div class="lx-fgrid<?= $links?' lp-f5':'' ?>">
<div class="lx-fcol"><?= lp_brand() ?><p>Agihan tugas guru dalam talian untuk sekolah SK dan SMK. Guru, jawatan, lantikan, kelulusan dan surat lantikan di satu tempat.</p>
<ul class="lx-badges"><li><?= icon('lock') ?>Data setiap sekolah diasingkan</li><li><?= icon('shield') ?>Selaras PDPA 2010</li></ul></div>
<nav class="lx-fcol" aria-label="Platform"><h3>Platform</h3><a href="/direktori">Cari sekolah</a><?php if(registration_open()): ?><a href="/daftar">Daftar sekolah</a><?php endif; ?><?php if(faq_items(true)): ?><a href="/faq"><?= e(faq_title()) ?></a><?php endif; ?><a href="/lapor-keselamatan">Lapor isu keselamatan</a></nav>
<?php if($links): ?><nav class="lx-fcol" aria-label="<?= e(lp_links_title()) ?>"><h3><?= e(lp_links_title()) ?></h3><?php foreach($links as [$l,$u]): $ext=preg_match('~^https?://~i',$u); ?><a href="<?= e($u) ?>"<?= $ext?' target="_blank" rel="noopener noreferrer"':'' ?>><?= e($l) ?></a><?php endforeach; ?></nav><?php endif; ?>
<nav class="lx-fcol" aria-label="Dokumen"><h3>Dokumen</h3><a href="/terma">Terma &amp; Syarat</a><a href="/polisi">Polisi Perkhidmatan &amp; Privasi</a><a href="/.well-known/security.txt">security.txt</a></nav>
<div class="lx-fcol"><h3>Hubungi</h3><?php if($email!==''): ?><a href="mailto:<?= e($email) ?>"><?= icon('mail') ?><?= e($email) ?></a><?php endif; ?>
<a class="lx-admin" href="/platform/"><span class="lx-ic"><?= icon('shield') ?></span><span class="lx-tx"><b>Log masuk pentadbir platform</b><small>Untuk pentadbir platform sahaja · 2FA wajib</small></span></a></div>
</div><div class="lx-fbot"><span>© <?= date('Y') ?> <?= e(platform_name()) ?>. Hak cipta terpelihara.</span><span>Bukan produk rasmi KPM.</span></div></div></footer></div></body></html><?php
}

/** Panel "Cuba demo" (DEMO_MODE=1): pautan sekolah demo; akaun kongsi dipaparkan di halaman log masuk sekolah. */
function lp_demo_section(): void {
    $schools=array_values(array_filter(array_map(fn($d)=>school_find($d[0]),DEMO_SCHOOLS))); if(!$schools) return; ?>
<section class="lx-sec lx-tight" id="demo"><div class="lx-wrap"><div class="lx-demo lx-rv">
<div class="lx-demo-copy"><span class="lx-tag is-sky lx-c3">Cuba sekarang</span><h2 class="lx-h lx-h4">Demo tanpa daftar</h2>
<p class="lx-b3 lx-muted">Log masuk sebagai Pentadbir, Penolong Kanan, Guru Besar atau Pengetua, atau Guru. Butang akaun demo tersedia di halaman log masuk. Data rekaan dijana semula setiap hari.</p></div>
<div class="lx-demo-links"><?php foreach($schools as $s): ?><a href="<?= e(school_url($s['code'],'/?page=login')) ?>"><?= icon('school') ?><span><b class="lx-t4"><?= e($s['name']) ?></b><br><small class="lx-c5"><?= e($s['code']) ?></small></span></a><?php endforeach; ?></div>
</div></div></section><?php
}

/** / — halaman utama platform: cari sekolah (kod tepat → terus ke laman sekolah), ciri, skrin, pakej & keselamatan. */
function platform_landing(): never {
    $q=mb_substr(trim(input('q','get')),0,80); $rows=[];
    if($q!==''){
        $code=strtoupper((string)preg_replace('/\s+/','',$q));
        if(preg_match(SCHOOL_CODE_RE,$code) && ($s=school_find($code)) && $s['status']==='aktif') redirect(school_url($s['code'],'/'));
        if(mb_strlen($q)>=3) $rows=array_slice(array_values(array_filter(platform_schools($q),fn($s)=>$s['status']==='aktif')),0,20);
    }
    $total=(int)query('SELECT COUNT(*) FROM schools WHERE status="aktif"')->fetchColumn();
    $free=pset('free_aktif')==='1'; $trial=(int)pset('percubaan_hari'); $price=str_replace('.00','',ringgit(pay_price())); $daftar=registration_open();
    $locked=pro_locked_features(); $lock=fn(string $k): string=>$free && in_array($k,$locked,true)?'<span class="lx-pro">Pro</span>':'';
    $ck='<span class="lx-ck">'.icon('check').'</span>'; $ok=icon('check');
    $host=(string)preg_replace('~^https?://~','',rtrim(school_url('KODSEKOLAH','/'),'/'));
    $org=seo_clean(['@type'=>'Organization','name'=>platform_name(),'url'=>seo_platform_abs('/'),'logo'=>plat_logo_has()?seo_platform_abs(plat_logo_url()):null,'email'=>pset('emel_notis')]);
    $app=seo_clean(['@type'=>'SoftwareApplication','name'=>platform_name(),'applicationCategory'=>'BusinessApplication','operatingSystem'=>'Web','inLanguage'=>'ms','url'=>seo_platform_abs('/'),'description'=>lp_text('teks'),'screenshot'=>seo_platform_abs(lp_shot('dashboard')),
        'offers'=>array_values(array_filter([$free?['@type'=>'Offer','name'=>'Free','price'=>'0','priceCurrency'=>'MYR']:null,['@type'=>'Offer','name'=>'Pro','price'=>number_format(pay_price()/100,2,'.',''),'priceCurrency'=>'MYR']]))]);
    // ?q=… memaparkan semula teks carian: noindex supaya pautan spam ke /?q=kata-kunci tidak menjadi halaman terindeks (SEO poisoning).
    lp_head('Sistem agihan tugas guru sekolah',false,['path'=>'/','home'=>true,'noindex'=>$q!=='','desc'=>lp_text('teks'),'ld'=>[$org,seo_clean(['@type'=>'WebSite','name'=>platform_name(),'url'=>seo_platform_abs('/'),'inLanguage'=>'ms']),$app]]); ?>
<section class="lx-hero"><div class="lx-wrap">
<div class="lx-hero-copy">
<span class="lx-pill lx-c4"><b><?= e(date('Y')) ?></b><?= e(lp_text('lencana')) ?></span>
<h1 class="lx-h lx-h1"><?= e(lp_text('t1')) ?><br><span class="lx-hl"><?= e(lp_text('t2')) ?></span></h1>
<p class="lx-lead lx-blg"><?= e(lp_text('teks')) ?></p>
<div class="lx-actions"><?php if($daftar): ?><a class="lx-btn lx-btn-primary lx-btn-lg" href="/daftar">Daftar sekolah percuma <?= icon('arrow','lx-go') ?></a><?php endif; ?><a class="lx-btn lx-btn-ghost lx-btn-lg" href="#skrin">Lihat sistem sebenar</a></div>
<ul class="lx-proof lx-c4"><?php if($trial>0): ?><li><?= icon('check') ?>Pro percuma <?= $trial ?> hari</li><?php endif; ?><li><?= icon('lock') ?>Data setiap sekolah diasingkan</li><li><?= icon('shield') ?>Selaras PDPA 2010</li><?php if($total): ?><li><?= icon('school') ?><?= $total ?> sekolah menggunakan <?= e(platform_name()) ?></li><?php endif; ?></ul>
<form class="lx-find" id="cari" method="get" action="/" role="search">
<label class="lx-find-lb" for="lx-q">Pilih sekolah anda: cari dengan kod KPM atau nama sekolah untuk log masuk</label>
<div class="lx-find-box"><?= icon('search') ?><input id="lx-q" name="q" value="<?= e($q) ?>" maxlength="80" placeholder="cth. CEA1234 atau SMK Muadzam" autocomplete="off" autocapitalize="characters" required minlength="3"><button class="lx-btn lx-btn-dark" type="submit">Cari</button></div>
<?php if($q!==''): ?><div class="lx-res" aria-live="polite"><?php if(!$rows): ?><p class="lx-b6">Tiada sekolah dijumpai untuk “<?= e($q) ?>”.<?= mb_strlen($q)<3?' Taip sekurang-kurangnya 3 aksara.':' Sekolah anda mungkin belum mendaftar.' ?></p><?php endif; ?>
<?php foreach($rows as $s): ?><a href="<?= e(school_url($s['code'],'/')) ?>"><span class="lx-kd"><?= e($s['code']) ?></span><span><span class="lx-t4"><?= e($s['name']) ?></span><?php if($s['state']!==''): ?><span class="lx-c5 lx-faint"><?= e($s['state']) ?></span><?php endif; ?></span><?= icon('chev-r') ?></a><?php endforeach; ?></div><?php endif; ?>
<p class="lx-find-hint lx-c5">Laman sekolah anda: <code><?= e(school_url('KODSEKOLAH','/')) ?></code></p>
<p class="lx-find-more"><a href="/direktori">Lihat semua sekolah →</a><?php if($daftar): ?><a href="/daftar">Daftar sekolah baharu →</a><?php endif; ?></p>
</form></div>
<div class="lx-stage"><span class="lx-blob lx-bl1" aria-hidden="true"></span><span class="lx-blob lx-bl2" aria-hidden="true"></span><span class="lx-blob lx-bl3" aria-hidden="true"></span>
<div class="lx-float lx-fl-badge" aria-hidden="true"><span class="lx-ic"><?= icon('scale') ?></span><span><span class="lx-t4">Indeks beban setiap guru</span><span class="lx-c5 lx-muted">Seimbang: 0.70 hingga 1.30</span></span></div>
<div class="lx-float lx-fl-kpi" aria-hidden="true"><div class="lx-crop crop-kpi"><img src="<?= e(lp_shot('analisis')) ?>" alt="" loading="lazy" width="1600" height="1000"></div></div>
<div class="lx-float lx-fl-phone"><img src="<?= e(lp_shot('tugas-saya')) ?>" alt="Tugas Saya di telefon guru: surat pelantikan tugas" loading="lazy" width="555" height="1200"></div>
<div class="lx-frame"><div class="lx-frame-bar" aria-hidden="true"><i></i><i></i><i></i><span class="lx-frame-url lx-c5"><?= e($host) ?></span></div>
<img src="<?= e(lp_shot('dashboard')) ?>" alt="Dashboard sekolah: jumlah guru, jawatan tersedia, jawatan telah diisi dan guru terbeban" width="1600" height="1000" fetchpriority="high"></div>
</div></div></section>

<div class="lx-strip" aria-label="Bidang tugas"><div class="lx-wrap lx-strip-in"><span class="lx-lb">Jawatan disusun ikut bidang</span><b><i></i>Pentadbiran</b><b><i></i>Kurikulum</b><b><i></i>HEM</b><b><i></i>Kokurikulum</b></div></div>

<section class="lx-sec" id="ciri"><div class="lx-wrap">
<div class="lx-head lx-center lx-rv"><span class="lx-tag lx-c3">Ciri</span><h2 class="lx-h lx-h2">Semua yang PK perlukan<br>untuk agihan tugas</h2><p class="lx-blg lx-muted">Setiap gambar di bawah diambil terus daripada <?= e(platform_name()) ?>, menggunakan sekolah demo.</p></div>
<div class="lx-bento">
<article class="lx-tile is-peach lx-s4 lx-rv"><div class="lx-shot lx-crop crop-auto"><img src="<?= e(lp_shot('auto')) ?>" alt="Pratonton agihan automatik: jawatan kosong, cadangan guru dan sebab setiap cadangan" loading="lazy" width="1600" height="1000"></div>
<div class="lx-tile-copy"><div class="lx-row"><h3 class="lx-h lx-h6">Agihan automatik dengan sebab</h3><?= $lock('auto') ?></div><p class="lx-b6 lx-muted">Jana pratonton untuk isi jawatan kosong atau susun semula. Setiap cadangan menyatakan sebabnya, contohnya indeks beban selepas lantikan terendah antara calon yang layak. Tiada yang berubah sehingga anda simpan.</p></div></article>
<article class="lx-tile is-sky lx-s2 lx-rv d1"><div class="lx-phone-wrap"><div class="lx-phone"><img src="<?= e(lp_shot('tugas-saya')) ?>" alt="Tugas Saya di telefon guru" loading="lazy" width="555" height="1200"></div></div>
<div class="lx-tile-copy"><h3 class="lx-h lx-h6">Tugas Saya di telefon</h3><p class="lx-b6 lx-muted">Guru log masuk dan terus nampak surat pelantikan sendiri. Cetak atau muat turun PDF.</p></div></article>
<article class="lx-tile is-mint lx-s2 lx-rv"><div class="lx-shot lx-crop crop-syarat"><img src="<?= e(lp_shot('syarat')) ?>" alt="Pusat Syarat: had tugas setiap guru, had tugas berat dan had mengikut bidang" loading="lazy" width="1600" height="1000"></div>
<div class="lx-tile-copy"><div class="lx-row"><h3 class="lx-h lx-h6">Pusat Syarat</h3><?= $lock('auto') ?></div><p class="lx-b6 lx-muted">Had tugas setiap guru, had tugas berat, had ikut bidang dan guru yang dikecualikan daripada agihan automatik.</p></div></article>
<article class="lx-tile is-sun lx-s4 lx-rv d1"><div class="lx-shot lx-crop crop-analisis"><img src="<?= e(lp_shot('analisis')) ?>" alt="Analisis Beban: guru terbeban, seimbang dan kurang beban berdasarkan indeks" loading="lazy" width="1600" height="1000"></div>
<div class="lx-tile-copy"><h3 class="lx-h lx-h6">Indeks beban yang adil</h3><p class="lx-b6 lx-muted">Indeks menggabungkan masa mengajar dan tugas, dibandingkan dengan guru biasa di sekolah anda. 1.00 bermaksud setara guru biasa; melebihi 1.30 ditanda terbeban.</p></div></article>
<article class="lx-tile is-grape lx-s3 lx-rv"><div class="lx-shot lx-crop crop-kelulusan"><img src="<?= e(lp_shot('kelulusan')) ?>" alt="Semakan dan Kelulusan: langkah Draf, Semakan, Lulus dan Muktamad" loading="lazy" width="1600" height="1000"></div>
<div class="lx-tile-copy"><h3 class="lx-h lx-h6">Kelulusan empat peringkat</h3><p class="lx-b6 lx-muted">Draf, Semakan, Lulus, Muktamad. Guru Besar atau Pengetua boleh membuka draf pindaan dan menanda guru yang perlu disemak semula.</p></div></article>
<article class="lx-tile is-rose lx-s3 lx-rv d1"><div class="lx-shot lx-crop crop-surat"><img src="<?= e(lp_shot('surat-guru')) ?>" alt="Surat pelantikan tugas dengan Jata Negara, nama sekolah dan senarai jawatan" loading="lazy" width="1600" height="1111"></div>
<div class="lx-tile-copy"><div class="lx-row"><h3 class="lx-h lx-h6">Surat lantikan berjata</h3><?= $lock('surat_pukal') ?></div><p class="lx-b6 lx-muted">Jata Negara, alamat sekolah, nombor rujukan dan jadual jawatan. Cetak seorang, atau semua guru dalam satu PDF.</p></div></article>
</div></div></section>

<section class="lx-sec lx-soft" id="skrin"><div class="lx-wrap">
<div class="lx-head lx-center lx-rv"><span class="lx-tag is-sky lx-c3">Lihat sistem sebenar</span><h2 class="lx-h lx-h2">Skrin yang digunakan sekolah setiap hari</h2><p class="lx-blg lx-muted">Tangkapan skrin sebenar daripada sekolah demo. Pilih skrin untuk melihatnya.</p></div>
<?php $tabs=[
    ['analisis','Analisis Beban','sun','analisis','Penolong Kanan','Tahu siapa terbeban sebelum mesyuarat',['Kiraan terbeban, seimbang dan kurang beban','Senarai perlu perhatian dengan cadangan tindakan','Indeks setiap guru berbanding median sekolah']],
    ['auto','Agihan Automatik','brand','auto','Penolong Kanan','Cadangan dahulu, simpan kemudian',['Mod isi jawatan kosong atau susun semula','Sebab ditunjukkan bagi setiap perubahan','Lihat beban guru selepas cadangan']],
    ['kelulusan','Semakan & Kelulusan','grape','kelulusan','PK, Guru Besar dan Pengetua','Satu versi yang dipercayai semua',['Draf dikunci selepas dihantar untuk semakan','Draf pindaan dengan guru yang ditanda','Amaran guru terbeban sebelum meluluskan']],
    ['syarat','Pusat Syarat','mint','syarat','Pentadbir dan PK','Peraturan sekolah anda, dipatuhi setiap kali',['Jangan melebihi kapasiti mata guru','Had tugas berat dan had mengikut bidang','Kecualikan guru tertentu daripada agihan automatik']],
    ['surat','Surat Lantikan','rose','surat','Guru Besar dan Pengetua','Surat siap daripada versi muktamad',['Jata Negara, alamat dan nombor rujukan','Cari guru, cetak seorang atau semua','Pindaan draf tidak mengubah surat yang telah diterbitkan']],
]; $tagc=['sun'=>'is-sun','brand'=>'','grape'=>'is-grape','mint'=>'is-mint','rose'=>'is-rose']; ?>
<div class="lx-tabs" role="tablist" aria-label="Skrin sistem"><?php foreach($tabs as $i=>[$id,$label,$c]): ?><button type="button" role="tab" id="lx-t-<?= $id ?>" aria-controls="lx-p-<?= $id ?>" aria-selected="<?= $i?'false':'true' ?>"<?= $i?' tabindex="-1"':'' ?> class="is-c-<?= $c ?>"><i></i><?= e($label) ?></button><?php endforeach; ?></div>
<?php foreach($tabs as $i=>[$id,$label,$c,$img,$who,$h,$pts]): ?><div class="lx-panel is-c-<?= $c ?>" id="lx-p-<?= $id ?>" role="tabpanel" aria-labelledby="lx-t-<?= $id ?>"<?= $i?' hidden':'' ?>>
<div class="lx-frame"><div class="lx-frame-bar" aria-hidden="true"><i></i><i></i><i></i><span class="lx-frame-url lx-c5"><?= e($label) ?></span></div><img src="<?= e(lp_shot($img)) ?>" alt="Skrin <?= e($label) ?>" loading="lazy" width="1600" height="1000"></div>
<div class="lx-cap"><span class="lx-tag <?= $tagc[$c] ?> lx-c3"><?= e($who) ?></span><h3 class="lx-h lx-h5"><?= e($h) ?></h3><ul class="lx-b6 lx-muted"><?php foreach($pts as $p): ?><li><?= icon('check') ?><?= e($p) ?></li><?php endforeach; ?></ul></div>
</div><?php endforeach; ?>
</div></section>

<section class="lx-tagline" aria-label="Janji <?= e(platform_name()) ?>"><div class="lx-wrap"><p class="lx-h lx-h2" data-acc="seimbang">Beban yang seimbang, kelulusan yang jelas,<br>dan surat yang sama dengan versi<br>yang diluluskan.</p></div></section>

<section class="lx-sec lx-tight" id="cara"><div class="lx-wrap">
<div class="lx-head lx-rv"><span class="lx-tag is-grape lx-c3">Cara ia berfungsi</span><h2 class="lx-h lx-h2">Empat peringkat, satu senarai muktamad</h2><p class="lx-b3 lx-muted">Setiap perubahan direkod; setiap guru menerima surat daripada versi yang sama yang diluluskan.</p></div>
<ol class="lx-steps"><?php foreach([['Pentadbir · PK','Draf','Masukkan guru dan jawatan, atau tampal terus daripada Excel. Guna jawatan standard SK atau SMK.'],['PK','Semakan','Agih secara manual atau automatik, kemudian hantar. Data dikunci supaya tiada perubahan senyap.'],['Guru Besar · Pengetua','Lulus','Semak beban guru dan luluskan, atau buka draf pindaan dengan guru yang ditanda.'],['Semua guru','Muktamad','Surat lantikan diterbitkan dan setiap guru melihat tugasnya di Tugas Saya.']] as $i=>[$w,$t,$d]): ?>
<li class="lx-step is-<?= $i+1 ?> lx-rv<?= $i%2?' d1':'' ?>"><span class="lx-no"><?= $i+1 ?></span><span class="lx-c4 lx-who"><?= e($w) ?></span><h3 class="lx-h lx-h6"><?= e($t) ?></h3><p class="lx-b6 lx-muted"><?= e($d) ?></p></li><?php endforeach; ?></ol>
</div></section>
<?php if(demo_env()) lp_demo_section(); ?>

<section class="lx-sec lx-tight" id="keselamatan"><div class="lx-wrap"><div class="lx-secure lx-rv">
<div class="lx-head"><span class="lx-tag lx-c3">Keselamatan dan privasi</span><h2 class="lx-h lx-h3">Data guru dilindungi seperti fail peribadi</h2><p class="lx-b3 lx-muted">Direka mengikut prinsip PDPA: simpan sesedikit mungkin, sulitkan apa yang disimpan, rekod setiap tindakan.</p></div>
<div class="lx-sec-list">
<div class="lx-sec-item"><span class="lx-ic"><?= icon('lock') ?></span><span class="lx-t3">Kunci penyulitan setiap sekolah</span><span class="lx-b6 lx-muted">Data sulit seperti rahsia 2FA dan kunci API disimpan tersulit dan tidak boleh dipadankan dengan sekolah lain.</span></div>
<div class="lx-sec-item"><span class="lx-ic"><?= icon('key') ?></span><span class="lx-t3">Pengesahan dua langkah</span><span class="lx-b6 lx-muted">2FA untuk pentadbir, kata laluan kuat dan had cubaan log masuk untuk semua akaun.</span></div>
<div class="lx-sec-item"><span class="lx-ic"><?= icon('list') ?></span><span class="lx-t3">Log audit kalis usik</span><span class="lx-b6 lx-muted">Setiap tindakan direkod dalam rantai log bertandatangan. Pengubahan atau pemadaman dapat dikesan.</span></div>
<div class="lx-sec-item"><span class="lx-ic"><?= icon('database') ?></span><span class="lx-t3">Sandaran tersulit</span><span class="lx-b6 lx-muted">Sandaran berkala disulitkan sebelum disimpan di luar pelayan, dengan semakan integriti automatik.</span></div>
<div class="lx-sec-item"><span class="lx-ic"><?= icon('shield') ?></span><span class="lx-t3">Perlindungan IP dan CAPTCHA</span><span class="lx-b6 lx-muted">Sekatan IP automatik dan CAPTCHA menghalang percubaan meneka kata laluan.</span></div>
<div class="lx-sec-item"><span class="lx-ic"><?= icon('flag') ?></span><span class="lx-t3">Laporan keselamatan</span><span class="lx-b6 lx-muted">Jumpa kelemahan? Laporkan secara sulit melalui <a href="/lapor-keselamatan">borang laporan keselamatan</a>.</span></div>
</div></div></div></section>

<section class="lx-sec lx-tight" id="pakej"><div class="lx-wrap">
<div class="lx-head lx-center lx-rv"><span class="lx-tag is-mint lx-c3">Pakej</span><h2 class="lx-h lx-h2"><?= $free?'Mula percuma, naik taraf<br>bila sekolah bersedia':'Satu harga, semua ciri' ?></h2>
<p class="lx-blg lx-muted"><?= $trial>0?'Sekolah baharu mendapat Pro percuma '.$trial.' hari. ':'' ?><?= $free?'Selepas itu kekal di Free secara automatik. Data tidak hilang, tiada caj tersembunyi.':'Semua ciri untuk setiap sekolah.' ?></p></div>
<div class="lx-plans<?= $free?'':' is-one' ?>">
<?php if($free): $open=array_diff(array_keys(PRO_FEATURES),$locked); ?><article class="lx-plan lx-rv"><div class="lx-plan-top"><h3 class="lx-h lx-h6">Free</h3><span class="lx-chip">Untuk mencuba</span></div>
<div><div class="lx-price"><b>RM0</b><span class="lx-b6 lx-muted">selamanya</span></div><p class="lx-b6 lx-muted">Untuk sekolah kecil yang mahu mula dengan agihan manual.</p></div>
<?php if($daftar): ?><a class="lx-btn lx-btn-ghost lx-btn-lg" href="/daftar">Daftar percuma</a><?php endif; ?>
<ul class="lx-b6"><li><?= $ck ?><span>Sehingga <b><?= (int)pset('free_guru') ?> guru</b> dan <?= (int)pset('free_jawatan') ?> jawatan</span></li><li><?= $ck ?><span>Sehingga <?= (int)pset('free_pengguna') ?> pengguna aktif</span></li><li><?= $ck ?><span>Agihan manual, aliran kelulusan dan cetak surat</span></li><li><?= $ck ?><span>Analisis beban dan Tugas Saya</span></li><?php foreach($open as $k): ?><li><?= $ck ?><span><?= e(PRO_FEATURES[$k][0]) ?></span></li><?php endforeach; ?></ul></article><?php endif; ?>
<article class="lx-plan is-pro lx-rv d1"><div class="lx-plan-top"><h3 class="lx-h lx-h6">Pro</h3><span class="lx-pro">Disyorkan</span></div>
<div><div class="lx-price"><b><?= e($price) ?></b><span class="lx-b6 lx-muted">setahun, setiap sekolah</span></div><p class="lx-b6 lx-muted">Semua ciri, tanpa had guru, jawatan atau pengguna.</p></div>
<?php if($daftar): ?><a class="lx-btn lx-btn-primary lx-btn-lg" href="/daftar"><?= $trial>0?'Cuba Pro '.$trial.' hari percuma':'Daftar sekolah' ?></a><?php endif; ?>
<ul class="lx-b6"><li><?= $ck ?><span><b>Guru, jawatan dan pengguna tanpa had</b></span></li><?php foreach($locked as $k): ?><li><?= $ck ?><span><?= e(PRO_FEATURES[$k][0]) ?></span></li><?php endforeach; ?><li><?= $ck ?><span>Sokongan melalui tiket</span></li></ul></article>
</div>
<div class="lx-pay"><span class="lx-chip">FPX</span><span class="lx-chip">Pindahan bank</span><span class="lx-chip">Pesanan belian (PO)</span><span class="lx-chip">Ditaja PPD</span><?php if($free): ?><span class="lx-chip">Langganan tamat: kembali ke Free</span><?php endif; ?></div>
</div></section>
<?php faq_landing_section(); ?>

<section class="lx-sec lx-tight" id="mula"><div class="lx-wrap"><div class="lx-final lx-rv">
<span class="lx-blob f1" aria-hidden="true"></span><span class="lx-blob f2" aria-hidden="true"></span>
<div class="lx-final-copy"><span class="lx-tag lx-c3">Sedia untuk sesi baharu</span><h2 class="lx-h lx-h2">Siapkan agihan tugas sebelum mesyuarat guru pertama</h2>
<p class="lx-blg"><?= $daftar?'Daftar dengan kod sekolah KPM. Ruang sekolah anda sedia selepas diluluskan'.($trial>0?', dengan Pro percuma '.$trial.' hari.':'.'):'Cari sekolah anda di direktori dan log masuk.' ?></p>
<?php if($daftar): ?><a class="lx-btn lx-btn-dark lx-btn-lg" href="/daftar">Daftar sekolah percuma <?= icon('arrow','lx-go') ?></a><?php else: ?><a class="lx-btn lx-btn-dark lx-btn-lg" href="/direktori">Cari sekolah anda <?= icon('arrow','lx-go') ?></a><?php endif; ?></div>
<div class="lx-letter" aria-hidden="true"><div class="lx-crop crop-letter"><img src="<?= e(lp_shot('surat-guru')) ?>" alt="" loading="lazy" width="1600" height="1111"></div></div>
</div></div></section>
<?php lp_foot(); exit;
}
