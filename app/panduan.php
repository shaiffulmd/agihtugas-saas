<?php
declare(strict_types=1);
/* =====================================================================
 * Panduan Penggunaan dalam aplikasi sekolah (/?page=panduan).
 * Dibuka oleh semua peranan dari menu; bahagian ditapis mengikut peranan pengguna
 * (Pentadbir melihat semua, Guru hanya bahagian yang berkaitan). Pautan ke halaman
 * hanya dipaparkan jika peranan itu boleh membukanya. Kandungan statik — tiada data sekolah.
 * Kemas kini panduan ini apabila aliran atau halaman berubah (lihat juga docs/).
 * ===================================================================== */

/** Pautan ke halaman sistem jika peranan dibenarkan; jika tidak, nama halaman sahaja (tebal). */
function pd_link(string $page,string $label,string $role): string {
    $ok=in_array($page,engine_pages($role),true) || in_array($page,pd_role_pages($role),true);
    return $ok?'<a class="text-link" href="/?page='.e($page).'">'.e($label).'</a>':'<strong>'.e($label).'</strong>';
}
/** Halaman bukan enjin yang boleh dibuka setiap peranan (selaras dengan menu dalam views.php). */
function pd_role_pages(string $role): array {
    $all=['security','hebahan','versi','panduan'];
    return $role==='admin'?array_merge($all,['dashboard','users','settings','urus_akaun','langganan','sokongan','ipsekat']):array_merge($all,$role==='pk'?['dashboard']:[]);
}

/** Bahagian panduan: [id, tajuk, peranan yang melihat, fungsi kandungan]. */
function pd_sections(string $role): array {
    $L=fn(string $p,string $t)=>pd_link($p,$t,$role);
    $head=function_exists('head_title')?head_title():'Guru Besar / Pengetua';
    return [
    ['mula','Mula di sini',['admin','pk'],function() use($L,$head){ ?>
<p>UrusTugas membantu sekolah mengagih tugas (jawatan) kepada guru secara seimbang, meluluskannya, dan menerbitkan surat lantikan. Aliran biasa satu sesi persekolahan:</p>
<ol class="pd-steps">
<li><strong>Sediakan data asas</strong> — <?= $L('subjek','Mata Pelajaran') ?>, kemudian <?= $L('guru','Pengurusan Guru') ?> (tambah satu-satu atau import Excel).</li>
<li><strong>Senaraikan jawatan</strong> — <?= $L('jawatan','Jawatan & Pemberat') ?>: guna <em>Jawatan standard</em> atau <?= $L('pukal','Tambah Jawatan Pukal') ?>, dan tetapkan pemberat setiap jawatan.</li>
<li><strong>Tanda jawatan utama</strong> — <?= $L('organisasi','Carta Organisasi') ?>: <?= e($head) ?>, PK dan Ketua Bidang.</li>
<li><strong>Agih tugas</strong> — manual di <?= $L('agihan','Agihan Tugas') ?>, atau tetapkan <?= $L('syarat','Pusat Syarat') ?> dahulu kemudian <?= $L('auto','Agihan Automatik') ?>.</li>
<li><strong>Semak keseimbangan</strong> — <?= $L('analisis','Analisis Beban') ?>: pastikan tiada guru terlalu terbeban atau kurang beban.</li>
<li><strong>Hantar untuk semakan</strong> — <?= $L('kelulusan','Semakan & Kelulusan') ?>. <?= e($head) ?> meluluskan dan memuktamadkan.</li>
<li><strong>Cetak surat</strong> — <?= $L('surat','Surat Lantikan') ?> (cetak atau PDF). Guru melihat tugas sendiri di <em>Tugas Saya</em>.</li>
</ol>
<div class="notice info">Semua penyuntingan agihan hanya boleh dibuat ketika status <strong>Draf</strong>. Selepas dihantar, data dikunci sehingga dikembalikan ke draf.</div>
<?php }],
    ['peranan','Peranan & akaun pengguna',['admin'],function() use($L,$head){ ?>
<table class="pd-table"><thead><tr><th>Peranan</th><th>Boleh buat</th></tr></thead><tbody>
<tr><td>Pentadbir</td><td>Semua: akaun, tetapan, profil sekolah & surat, data, agihan; hantar draf; buka semula pindaan.</td></tr>
<tr><td>Penolong Kanan (PK)</td><td>Urus guru, mata pelajaran, jawatan, syarat, import & agihan; hantar draf.</td></tr>
<tr><td><?= e($head) ?></td><td>Semak, <strong>luluskan</strong>, <strong>muktamadkan</strong> atau kembalikan dengan sebab. Tidak menyunting draf.</td></tr>
<tr><td>Guru</td><td>Lihat & cetak tugas serta surat muktamad sendiri.</td></tr>
</tbody></table>
<ol class="pd-steps">
<li>Buka <?= $L('users','Pengguna & Audit') ?> → <em>Tambah pengguna</em>. Cipta akaun berasingan untuk PK dan <?= e($head) ?> — penghantar tidak boleh meluluskan agihannya sendiri.</li>
<li>Akaun <strong>Guru</strong> mesti dipautkan kepada satu profil guru (cipta profil di Pengurusan Guru dahulu).</li>
<li>Kata laluan sementara wajib ditukar pada log masuk pertama. Untuk reset kata laluan atau 2FA pengguna lain: Pengguna & Audit → <em>Urus</em>.</li>
<li>Guru boleh mendaftar sendiri jika dibuka di <?= $L('urus_akaun','Pendaftaran & E-mel') ?> (pengesahan e-mel dan/atau kelulusan pentadbir).</li>
</ol>
<div class="notice info">Log masuk menggunakan <strong>e-mel tepat</strong> akaun itu, di alamat sekolah ini. Jika "E-mel atau kata laluan tidak sah", semak ejaan e-mel (nombor tertukar, autofill pelayar dari sekolah lain).</div>
<?php }],
    ['sekolah','Profil sekolah, surat & sesi baharu',['admin'],function() use($L){ ?>
<ul class="pd-list">
<li><?= $L('settings','Tetapan & Tema') ?>: nama sekolah, jenis <strong>SK</strong> (Guru Besar) atau <strong>SMK</strong> (Pengetua), warna tema dan dasar 2FA.</li>
<li><?= $L('sekolah','Profil Sekolah & Surat') ?>: alamat, rujukan surat, logo sekolah, gaya kepala surat (logo + Jata Negara) dan teks surat. Maklumat ini dicetak pada surat lantikan.</li>
<li><strong>Mula sesi baharu</strong> (hujung/awal tahun, di Profil Sekolah & Surat): pilih tahun, kekalkan atau kosongkan lantikan tahun lepas, lepaskan lantikan guru bertukar keluar. Surat sesi lepas kekal boleh dicetak.</li>
</ul>
<?php }],
    ['subjek','Mata pelajaran',['admin','pk'],function() use($L){ ?>
<p><?= $L('subjek','Mata Pelajaran') ?> ialah katalog untuk memilih <strong>kepakaran guru</strong>. Import senarai standard KPM atau tambah sendiri.</p>
<p>Katalog ini juga membantu agihan: jawatan yang menyebut mata pelajaran (cth. <em>Ketua Panitia Matematik</em>) mendahulukan guru yang mengajar MP itu.</p>
<?php }],
    ['guru','Pengurusan guru & import Excel',['admin','pk'],function() use($L){ ?>
<p>Tambah guru di <?= $L('guru','Pengurusan Guru') ?> satu-satu, atau <em>Import pukal</em> daripada Excel (.xlsx) / CSV — muat turun templat di halaman itu. Lajur:</p>
<table class="pd-table"><thead><tr><th>Lajur</th><th>Maksud</th></tr></thead><tbody>
<tr><td>Nama</td><td>Nama penuh guru (wajib).</td></tr>
<tr><td>Emel</td><td>Pilihan. Diperlukan jika guru akan diberi akaun.</td></tr>
<tr><td>Kepakaran</td><td>Mata pelajaran diajar, dipisah koma (cth. <em>Matematik, Sains</em>).</td></tr>
<tr><td>Kapasiti</td><td><strong>Had mata beban tugas</strong> guru itu, 1–100 (kosong = 25). Beban guru = jumlah pemberat semua jawatannya; kapasiti ialah sasaran maksimum. Panduan SMK: GAB 16–20, GKMP 12–15, PK / Pengetua 8–12.</td></tr>
<tr><td>Masa mengajar</td><td>Bilangan waktu mengajar seminggu — digunakan untuk <a href="#analisis" class="text-link">indeks beban</a>.</td></tr>
</tbody></table>
<ul class="pd-list">
<li><strong>Status guru</strong>: Aktif, Cuti (lantikan dikekalkan, amaran sahaja) atau Bertukar keluar (mesti dilepaskan daripada semua jawatan sebelum draf dihantar).</li>
<li>Kapasiti ialah ukuran sekunder (lencana "Melebihi kapasiti mata"); penilaian utama beban ialah indeks beban.</li>
</ul>
<?php }],
    ['jawatan','Jawatan & pemberat',['admin','pk'],function() use($L){ ?>
<ul class="pd-list">
<li>Setiap jawatan ada <strong>bidang</strong> (Pentadbiran, Kurikulum, HEM, Kokurikulum) dan <strong>pemberat 1–10</strong> mengikut beban sebenar. Jawatan standard: Berat 4–5, Sederhana 3, Ringan 1–2.</li>
<li><?= $L('jawatan','Jawatan & Pemberat') ?> → <em>Jawatan standard</em> (SMK): tanda jawatan yang ada di sekolah dan tambah serentak. Jawatan berbilang (cth. Guru Tingkatan) ada medan bilangan.</li>
<li><?= $L('pukal','Tambah Jawatan Pukal') ?>: tampal daripada Excel atau muat naik fail dengan lajur <code>nama, bidang, pemberat, bilangan</code>. Bilangan 3 menghasilkan "Nama (1/3)…(3/3)".</li>
</ul>
<?php }],
    ['organisasi','Carta organisasi',['admin','pk','head'],function() use($L,$head){ ?>
<p><?= $L('organisasi','Carta Organisasi') ?>: pilih guru bagi jawatan utama — <?= e($head) ?>, PK Pentadbiran, PK HEM, PK Kokurikulum dan Ketua Bidang. Jawatan ini dilantik oleh JPN: tiada pemberat dan tiada surat lantikan.</p>
<p>Guru yang dipilih ditanda <strong>Pentadbir</strong> (dikecualikan daripada indeks beban) dan <?= e($head) ?> tidak diberi tugas oleh agihan automatik. Nama <?= e($head) ?> digunakan pada surat.</p>
<?php }],
    ['agihan','Agihan tugas (manual)',['admin','pk'],function() use($L){ ?>
<ol class="pd-steps">
<li>Buka <?= $L('agihan','Agihan Tugas') ?>. Setiap jawatan dipaparkan dengan penyandang semasa.</li>
<li><em>Tetapkan lantikan</em> untuk memilih guru, atau <em>Pindahkan</em> kepada guru lain. Guru yang mengajar MP jawatan itu ditanda ★.</li>
<li>Beban guru dikemas kini selepas disimpan — semak lajur indeks / mata.</li>
</ol>
<?php }],
    ['auto','Pusat Syarat & agihan automatik',['admin','pk'],function() use($L){ ?>
<p class="muted">Ciri pakej Pro (bergantung tetapan platform).</p>
<ol class="pd-steps">
<li><?= $L('syarat','Pusat Syarat') ?>: tetapkan sama ada kapasiti mesti dipatuhi, maksimum tugas, maksimum tugas berat dan had setiap bidang. Kecualikan guru jika perlu.</li>
<li>Syarat khusus jawatan: hadkan guru layak, wajibkan kepakaran, atau tetapkan penyandang tetap.</li>
<li><?= $L('auto','Agihan Automatik') ?>: pilih <em>Isi jawatan kosong</em> (kekalkan lantikan sedia ada) atau <em>Susun semula semua</em> (kira dari kosong), kemudian <em>Jana pratonton</em>.</li>
<li>Semak cadangan, sebab pemilihan dan beban selepas cadangan, kemudian <em>Simpan agihan ini</em>. Pratonton sah 30 minit.</li>
</ol>
<div class="notice info">Agihan automatik ialah cadangan berasaskan peraturan, bukan keputusan muktamad. Laraskan secara manual jika perlu.</div>
<?php }],
    ['analisis','Analisis beban (indeks)',['admin','pk','head'],function() use($L){ $s=function_exists('index_settings')?index_settings():['high'=>1.3,'low'=>0.7]; ?>
<p><?= $L('analisis','Analisis Beban') ?> menggabungkan masa mengajar dan skor tugas, dibandingkan dengan guru biasa di sekolah ini. <strong>1.00 = setara guru biasa.</strong></p>
<ul class="pd-list">
<li><strong>Terbeban</strong>: indeks melebihi <?= e(number_format((float)$s['high'],2)) ?>.</li>
<li><strong>Kurang beban</strong>: indeks di bawah <?= e(number_format((float)$s['low'],2)) ?>.</li>
<li><strong>Seimbang</strong>: di antara kedua-duanya. Guru Pentadbir (dari Carta Organisasi) dikecualikan.</li>
<li>Isi <em>masa mengajar</em> setiap guru supaya indeks tepat — tanpanya indeks "Belum dikira".</li>
</ul>
<?php }],
    ['kelulusan','Semakan, kelulusan & muktamad',['admin','pk','head'],function() use($L,$head){ ?>
<ol class="pd-steps">
<li><strong>Draf</strong> — Pentadbir/PK menyediakan agihan, kemudian <em>Hantar untuk semakan</em> di <?= $L('kelulusan','Semakan & Kelulusan') ?>. Data dikunci.</li>
<li><strong>Semakan</strong> — <?= e($head) ?> menyemak jawatan & beban. <em>Luluskan</em>, atau <em>Kembalikan ke draf</em> dengan sebab dan tanda guru yang perlu disemak semula.</li>
<li><strong>Lulus</strong> — <?= e($head) ?> klik <em>Muktamadkan & terbitkan surat</em>.</li>
<li><strong>Muktamad</strong> — surat dan Tugas Saya menggunakan versi ini. Untuk pindaan, buka draf pindaan dengan sebab; surat lama kekal sehingga versi baharu dimuktamadkan.</li>
</ol>
<div class="notice info">Penghantar tidak boleh meluluskan agihannya sendiri. Guru berstatus Bertukar keluar mesti dilepaskan daripada jawatan sebelum draf boleh dihantar.</div>
<?php }],
    ['surat','Surat lantikan',['admin','pk','head'],function() use($L){ ?>
<p><?= $L('surat','Surat Lantikan') ?> menggunakan salinan tetap agihan <strong>muktamad</strong>. Cetak surat setiap guru atau semua sekali; muat turun PDF (Pro). Pilih versi muktamad lama (sesi lepas) untuk cetak semula. Tarikh surat = tarikh pemuktamadan.</p>
<?php }],
    ['tugas','Tugas Saya',['admin','pk','head','teacher'],function() use($L){ ?>
<p><strong>Tugas Saya</strong> memaparkan jawatan anda dalam agihan muktamad terkini, beserta surat lantikan yang boleh dicetak. Jika kosong, agihan sesi ini belum dimuktamadkan atau anda belum dilantik.</p>
<?php }],
    ['hebahan','Hebahan',['admin','pk','head','teacher'],function() use($L,$role){ ?>
<?php if($role==='admin'): ?><p><?= $L('hebahan','Hebahan Guru') ?>: hantar makluman kepada semua atau peranan tertentu. Hebahan baharu muncul sebagai pop timbul kepada penerima.</p>
<?php else: ?><p><?= $L('hebahan','Hebahan') ?> memaparkan makluman daripada pentadbir sekolah. Bilangan belum dibaca ditunjukkan di menu.</p><?php endif; ?>
<?php }],
    ['keselamatan','Akaun & keselamatan',['admin','pk','head','teacher'],function() use($L){ ?>
<ul class="pd-list">
<li><?= $L('security','Profil & Keselamatan') ?>: tukar kata laluan (12–128 aksara), gambar profil, dan aktifkan <strong>2FA</strong> dengan aplikasi pengesah (Google Authenticator, Microsoft Authenticator).</li>
<li>Simpan <strong>kod pemulihan</strong> 2FA di tempat selamat — setiap kod sekali guna jika telefon hilang.</li>
<li>Terlupa kata laluan: pautan <em>Lupa kata laluan?</em> di halaman log masuk, atau minta pentadbir sekolah menetapkan semula.</li>
<li>Sesi tamat selepas 30 minit tidak aktif.</li>
</ul>
<?php }],
    ['langganan','Langganan & sokongan',['admin'],function() use($L){ ?>
<ul class="pd-list">
<li><?= $L('langganan','Langganan') ?>: status pakej (Free/Pro), tarikh tamat, bayaran dalam talian atau resit, dan muat turun resit.</li>
<li><?= $L('sokongan','Sokongan') ?>: hantar tiket kepada pentadbir platform dan ikuti balasan.</li>
<li><?= $L('versi','Versi & Perubahan') ?>: senarai ciri baharu setiap keluaran.</li>
</ul>
<?php }],
    ['soalan','Soalan lazim',['admin','pk','head','teacher'],function() use($head,$role){ $staf=$role!=='teacher'; ?>
<dl class="pd-faq">
<?php if($staf): ?><dt>Kenapa saya tidak boleh menyunting guru / jawatan / agihan?</dt><dd>Agihan sedang dalam Semakan, Lulus atau Muktamad. Data hanya boleh disunting dalam Draf — minta <?= e($head) ?> mengembalikannya ke draf, atau buka draf pindaan.</dd>
<dt>Apakah beza kapasiti dan indeks beban?</dt><dd>Kapasiti = had mata tugas yang ditetapkan untuk seorang guru. Indeks = perbandingan beban keseluruhan (mengajar + tugas) dengan guru biasa di sekolah. Indeks ialah ukuran utama.</dd><?php endif; ?>
<dt>"E-mel atau kata laluan tidak sah"?</dt><dd>Semak e-mel ditaip tepat seperti akaun (pelayar mungkin mengisi e-mel lain secara automatik) dan log masuk di alamat sekolah ini. Kata laluan sementara: salin dengan butang Salin.</dd>
<?php if($staf): ?><dt>Surat tidak dikemas kini selepas saya tukar agihan?</dt><dd>Surat menggunakan versi muktamad. Perubahan baharu perlu dihantar, diluluskan dan dimuktamadkan semula.</dd>
<?php else: ?><dt>Tugas saya tiada atau tidak betul?</dt><dd>Tugas Saya hanya memaparkan agihan yang telah dimuktamadkan. Hubungi pentadbir sekolah atau PK jika ada kesilapan.</dd><?php endif; ?>
</dl>
<?php }],
    ];
}

function panduan_page(array $user): void {
    $role=(string)($user['role']??'teacher');
    $secs=array_values(array_filter(pd_sections($role),fn($s)=>in_array($role,$s[2],true)));
    $roleName=function_exists('role_label')?role_label($role):$role;
    ?><div class="page-heading"><div><span class="eyebrow teal">BANTUAN</span><h1>Panduan Penggunaan</h1><p class="muted">Panduan untuk peranan anda: <strong><?= e($roleName) ?></strong>. Klik tajuk untuk terus ke bahagian.</p></div><div class="button-group"><button type="button" class="btn outline print-btn">Cetak panduan</button></div></div>
<nav class="pd-toc panel padded" aria-label="Kandungan panduan"><ol><?php foreach($secs as [$id,$t]): ?><li><a href="#pd-<?= e($id) ?>"><?= e($t) ?></a></li><?php endforeach; ?></ol></nav>
<?php foreach($secs as [$id,$t,,$fn]): ?><section class="panel padded spaced pd-sec" id="pd-<?= e($id) ?>"><h2><?= e($t) ?></h2><?php $fn(); ?><p class="pd-top"><a class="text-link" href="#">↑ Kembali ke atas</a></p></section>
<?php endforeach;
}
