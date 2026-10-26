<?php
declare(strict_types=1);
/* Sejarah versi yang dipaparkan di halaman "Versi & Perubahan" (/?page=versi).
 * Setiap kali menerbitkan perubahan: naikkan APP_VERSION & APP_RELEASE dalam app/install.php (dan APP_SCHEMA jika skema berubah),
 * kemudian tambah entri baharu di ATAS senarai ini. Naik versi tanpa perubahan pangkalan data
 * tidak memerlukan langkah naik taraf (fail kunci dikemas kini secara automatik). */

const APP_CHANGELOG=[
    '0.6'=>['2026-10-26',[
        'Import pemasangan AgihTugas / UrusTugas satu sekolah (SQLite) ke sekolah baharu: php bin/import-agihtugas.php --db=FAIL --code=KOD [--dry-run]. Guru, jawatan, lantikan, akaun (kata laluan lama), syarat, revisi & surat muktamad, logo dan sejarah dibawa dengan ID dipetakan semula.',
        'Klien integrasi baharu integrations/koku-multi/UrusTugasClient.php untuk API per sekolah (/KOD/api/v1 atau subdomain, kunci ut_).',
    ]],
    '0.5'=>['2026-10-26',[
        'Halaman utama: bahagian "Cara ia berfungsi" (4 langkah) dan "Demo tanpa daftar" (mod demo); 11 contoh soalan lazim khusus agihan tugas guru.',
        'Demo lebih lengkap: syarat jawatan contoh, hebahan, dan versi muktamad #1 (surat & Tugas Saya terus boleh dilihat) yang dibuka semula sebagai draf pindaan; panduan docs/PANDUAN-DEMO.md.',
        'Dibaiki: pop-up hebahan guru gagal (500) kerana pemalar bulan daripada modul takwim lama.',
    ]],
    '0.4'=>['2026-10-26',[
        'Keselamatan & operasi disahkan untuk UrusTugas: rantai audit, sekatan IP, CAPTCHA, wajib 2FA, mod kecemasan, integriti fail, semakan kesihatan, kemas kini automatik GitHub dan sandaran tersulit (termasuk data enjin & logo surat).',
        'API mematuhi mod kecemasan, sekatan IP, sekolah digantung dan had cubaan kunci salah (respons JSON).',
        'Kunci API tidak boleh dicipta dalam mod demo atau sesi sokongan platform.',
        'Binaan zip keluaran gagal jika fail aplikasi baharu tertinggal daripada senarai keluaran.',
    ]],
    '0.3'=>['2026-10-26',[
        'Pakej Free/Pro: ciri Pro boleh dipilih oleh pentadbir platform (lalai: agihan automatik & Pusat Syarat, surat pukal & PDF, API, sejarah versi semakan; pilihan: import pukal). Sekolah Free melihat panel naik taraf; data kekal.',
        'Had Free dikira daripada guru, jawatan dan pengguna aktif (lalai 30 / 60 / 10) di semua titik tambah termasuk import pukal dan API.',
        'API sekolah Free menjawab 403 pro_required; kunci sedia ada sah semula selepas naik taraf.',
        'Resit bayaran berawalan UT; halaman utama, terma dan halaman Langganan menyenaraikan ciri Pro semasa; kerja harian membuang pratonton auto-agihan lama.',
    ]],
    '0.2'=>['2026-10-26',[
        'Enjin agihan tugas dipindahkan ke platform berbilang sekolah: Pengurusan Guru (termasuk tambah pukal CSV), Jawatan & Pemberat, Agihan Tugas, Pusat Syarat, Agihan Automatik (pratonton & simpan) dan Tambah Jawatan Pukal.',
        'Semakan & Kelulusan: Draf → Semakan → Lulus → Muktamad dengan kunci versi; data perancangan, nama & jenis sekolah dikunci semasa semakan.',
        'Surat Lantikan daripada versi muktamad (cetak & PDF), Tugas Saya untuk guru, logo sekolah & Jata Negara bagi setiap sekolah.',
        'API v1 per sekolah (/KOD/api/v1) dengan kunci API yang dicipta di halaman API & Integrasi; kunci sekolah lain ditolak.',
        'Akaun Guru wajib dipautkan kepada profil guru; had pakej Free dikira daripada bilangan guru & jawatan.',
    ]],
    '0.1'=>['2026-10-26',[
        'Rangka berbilang sekolah baharu (diambil daripada koku-multi): panel platform, sekolah dengan kod KPM (/KOD/ atau subdomain), langganan Free/Pro, bayaran, pendaftaran awam, keselamatan dan kemas kini automatik.',
        'Pangkalan data MariaDB/MySQL dengan school_id pada setiap jadual sekolah dan kunci asing komposit.',
        'Peranan sekolah UrusTugas: Pentadbir, Penolong Kanan, Guru Besar / Pengetua dan Guru. Log audit kini menyimpan butiran ringkas dalam rantai audit.',
        'Skema enjin agihan tugas berbilang sekolah: guru, jawatan, lantikan, syarat & pratonton auto-agihan, aliran kelulusan, aset surat dan kunci API — setiap jadual dengan school_id dan kunci asing komposit. Halaman enjin akan dipindahkan dalam fasa seterusnya.',
    ]],
];

function app_version_label(): string { return 'v'.APP_VERSION.(defined('APP_RELEASE')?' · '.app_release_date():''); }
/** Tarikh Y-m-d → "26 Okt 2026". */
function app_date_fmt(string $ymd): string { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$ymd); if(!$d) return $ymd; $bulan=['','Jan','Feb','Mac','Apr','Mei','Jun','Jul','Ogo','Sep','Okt','Nov','Dis']; return $d->format('j').' '.$bulan[(int)$d->format('n')].' '.$d->format('Y'); }
function app_release_date(): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',APP_RELEASE); if(!$d) return APP_RELEASE;
    $bulan=['','Jan','Feb','Mac','Apr','Mei','Jun','Jul','Ogo','Sep','Okt','Nov','Dis']; return $d->format('j').' '.$bulan[(int)$d->format('n')].' '.$d->format('Y');
}
function versi_page(): void {
    ?><div class="page-heading"><div><span class="eyebrow teal">VERSI SISTEM</span><h1>Versi &amp; Perubahan</h1><p class="muted">Anda sedang menggunakan <strong><?= e(app_version_label()) ?></strong>.</p></div></div>
    <section class="panel padded changelog"><?php $first=true; foreach(APP_CHANGELOG as $ver=>[$date,$items]): ?>
    <article class="cl-entry<?= $first?' current':'' ?>" id="v<?= e(str_replace('.','-',(string)$ver)) ?>"><header><h3>v<?= e((string)$ver) ?></h3><?php if($first): ?><span class="tag t-aktiviti">Terkini</span><?php endif; ?><?php if($date!==''): ?><time datetime="<?= e($date) ?>" class="muted"><?= e(app_date_fmt($date)) ?></time><?php endif; ?></header>
    <ul><?php foreach($items as $i): ?><li><?= e($i) ?></li><?php endforeach; ?></ul></article>
    <?php $first=false; endforeach; ?></section><?php
}
