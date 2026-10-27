<?php
declare(strict_types=1);
/* Sejarah versi yang dipaparkan di halaman "Versi & Perubahan" (/?page=versi).
 * Setiap kali menerbitkan perubahan: naikkan APP_VERSION & APP_RELEASE dalam app/install.php (dan APP_SCHEMA jika skema berubah),
 * kemudian tambah entri baharu di ATAS senarai ini. Naik versi tanpa perubahan pangkalan data
 * tidak memerlukan langkah naik taraf (fail kunci dikemas kini secara automatik). */

const APP_CHANGELOG=[
    '1.10'=>['2026-10-27',[
        'Cadangan guru ikut mata pelajaran: jawatan yang menyebut MP (cth. Ketua Panitia Matematik) dikesan daripada katalog Mata Pelajaran. Jawatan kosong di Agihan Tugas memaparkan "Cadangan" — guru yang mengajar MP itu dengan indeks terendah dan masih dalam kapasiti — dengan butang Lantik sekali klik.',
        'Borang Tetapkan lantikan: memilih jawatan MP menyusun guru yang mengajarnya di atas (★). Panel Urus beban menandakan jawatan kosong yang sepadan dengan MP guru.',
        'Agihan automatik mengutamakan guru yang mengajar MP jawatan (keutamaan, bukan syarat) dan mengisi jawatan MP dahulu supaya guru MP tidak habis digunakan jawatan lain.',
    ]],
    '1.9'=>['2026-10-27',[
        'Prestasi: agihan automatik 5× lebih laju bagi sekolah besar (900+ jawatan: susun semula 337 → 65 ms) dengan keputusan yang sama persis.',
        'Halaman dimampatkan gzip dan aset statik disimpan oleh pelayar (public_html/.htaccess) — cth. senarai 900 jawatan 906 KB → 25 KB dipindahkan.',
        'Panel Urus beban lebih ringan: 25 calon pindahan dengan indeks terendah, dan "ambil tugas" hanya daripada guru yang lebih berat.',
        'Kurang kerja berulang: indeks beban tidak dikira dua kali di Agihan Tugas, semakan modul hebahan tanpa query information_schema setiap permintaan, carian jadual lebih pantas.',
    ]],
    '1.8'=>['2026-10-27',[
        'Jadual skor peranan (Jawatan & Pemberat): senarai standard daripada sistem asal — Ketua Panitia 5; Pengerusi, Guru Kelas, Timbalan Ketua Warden, Setiausaha Warden 4; Setiausaha, Penyelaras, Bendahari, Warden 3; penolong 2; AJK, Juruaudit, Guru Penasihat 1 — boleh disunting, ditambah dan dipadam.',
        'Borang jawatan: pilih peranan dan pemberat diisi ikut skor peranan. Menukar skor peranan mengemas kini semua jawatan peranan itu.',
        '"Padankan jawatan ikut nama": cadangan peranan bagi jawatan sedia ada (cth. "Ketua Panitia Sains" → Ketua Panitia) dengan pratonton perubahan pemberat sebelum disimpan.',
    ]],
    '1.7'=>['2026-10-27',[
        'Panel Urus beban guru: butang "+ Tambah tugas" sentiasa tersedia. Pilih jawatan kosong, atau ambil satu jawatan daripada guru lain — guru paling terbeban disenarai dahulu, dengan anggaran indeks kedua-dua guru selepas pemindahan.',
    ]],
    '1.6'=>['2026-10-27',[
        'Guru ditanda oleh Guru Besar / Pengetua kini boleh ditandakan "selesai dibaiki" oleh PK atau Pentadbir — dari notis pemulangan (Agihan Tugas, Semakan & Kelulusan) atau panel Urus beban guru. Tanda boleh dibatalkan selagi draf belum dihantar.',
        'Notis memaparkan indeks semasa setiap guru ditanda dan bilangan yang selesai; apabila semua selesai, pautan terus untuk menghantar semula.',
        'Selepas dihantar semula, Guru Besar / Pengetua melihat senarai guru ditanda dengan status selesai (siapa & bila) di Semakan & Kelulusan sebelum meluluskan.',
    ]],
    '1.5'=>['2026-10-27',[
        'Urus beban guru: butang "Urus beban" di Pengurusan Guru (dan klik indeks atau nama di Analisis Beban / Dashboard) membuka panel beban guru itu di Agihan Tugas.',
        'Dalam panel: pindahkan setiap jawatan kepada guru lain — calon disusun daripada indeks paling rendah dengan anggaran indeks sekarang → selepas — atau lepaskan sahaja; guru kurang beban boleh terus dilantik ke jawatan kosong. Selepas disimpan, halaman kembali ke panel guru yang sama.',
    ]],
    '1.4'=>['2026-10-27',[
        'Halaman baharu Mata Pelajaran (Pentadbir & PK): tambah, sunting dan padam mata pelajaran sekolah, dengan kumpulan dan bilangan guru bagi setiap mata pelajaran.',
        'Senarai standard untuk diimport secara terpilih: KSSR (Semakan 2017), KSSM termasuk elektif menengah atas, Kurikulum Persekolahan 2027 sekolah rendah (senarai BPK) dan sekolah menengah (senarai awal — semak DSKP). Mata pelajaran yang sudah ada dilangkau.',
        'Borang guru: pilih kepakaran daripada senarai mata pelajaran (teks bebas masih dibenarkan). Menukar nama mata pelajaran turut mengemas kini kepakaran guru berkaitan.',
        'Pusat Syarat: cadangan nama mata pelajaran untuk "Kepakaran diperlukan".',
    ]],
    '1.3'=>['2026-10-27',[
        'Format surat lantikan (Profil Sekolah & Surat): empat gaya kepala surat — Jata Negara + logo sekolah, Jata Negara + no. telefon/faks, logo sekolah di tengah, Jata Negara di tengah — dengan nama dan alamat sekolah.',
        'Pilihan bilangan surat: satu surat (semua jawatan) atau satu surat bagi setiap jawatan, bernombor dalam rujukan. Cetak & PDF semua surat sekaligus mengikut pilihan ini.',
        'Tetapan baharu: alamat, telefon & faks sekolah, tarikh berkuat kuasa, perenggan tanggungjawab dan slogan. Pratonton contoh surat dengan format semasa sebelum dimuktamadkan.',
        'Format disimpan dalam versi muktamad: surat muktamad terdahulu tidak berubah, dan draf yang sedang menunggu kelulusan tidak perlu dihantar semula.',
    ]],
    '1.2'=>['2026-10-27',[
        'Indeks Beban: setiap guru diberi satu nombor yang menggabungkan masa mengajar seminggu (60%) dan tugas jawatan (40%), dibandingkan dengan median guru di sekolah sendiri. 1.00 = setara guru biasa; lebih 1.30 Terbeban, bawah 0.70 Kurang beban.',
        'Halaman baharu Analisis Beban (Pentadbir, PK, Guru Besar / Pengetua): bilangan guru terbeban / seimbang / kurang beban, senarai perlu perhatian, pecahan nisbah setiap guru dan tetapan indeks (bahagian mengajar, ambang, faktor kelas peperiksaan, had bilangan jawatan).',
        'Profil guru: masa mengajar seminggu, kelas peperiksaan, status (Aktif / Cuti / Bertukar keluar) dan pengecualian pentadbir. Guru Cuti / Bertukar keluar tidak dicalonkan oleh agihan automatik.',
        'Jawatan: tahap (Utama / Sokongan / Sampingan) dan tempoh (Sepanjang tahun / Bermusim / Program). Skor tugas = pemberat × tahap × tempoh; mata kapasiti sedia ada tidak berubah.',
        'Amaran dalam bahasa biasa: Terbeban, Kurang beban, Banyak jawatan, Tidak aktif tetapi memegang lantikan, Masa mengajar belum diisi.',
        'Agihan Tugas: calon disusun daripada indeks paling rendah, dengan anggaran indeks sekarang → jika dilantik.',
        'API: guru memaparkan load_index dan menerima teaching_periods, exam_class, status, index_exempt; jawatan menerima level dan term serta memaparkan score; /summary menyertakan ringkasan load_index.',
    ]],
    '1.1'=>['2026-10-26',[
        'API: GET /letters/{id} memulangkan kod 409 not_finalized (seperti /workflow/published dan dokumentasi) apabila belum ada versi muktamad, bukan workflow_locked.',
        'Guru Besar / Pengetua boleh menanda guru yang bebannya perlu disemak semula semasa mengembalikan draf (guru melebihi kapasiti ditanda secara automatik).',
        'PK dan Pentadbir melihat notis "Dikembalikan untuk semakan semula" — siapa, bila, sebab dan senarai guru ditanda dengan beban semasa — di Agihan Tugas, Semakan & Kelulusan dan Dashboard; guru ditanda diserlahkan dan boleh ditapis di Agihan Tugas. Notis hilang apabila draf dihantar semula.',
        'Istilah "Ketua Sekolah" diganti dengan Guru Besar / Pengetua mengikut jenis sekolah.',
    ]],
    '1.0'=>['2026-10-26',[
        'Keluaran 1.0: UrusTugas berbilang sekolah — agihan tugas guru, auto-agihan, aliran kelulusan, surat lantikan, API, langganan Free/Pro, keselamatan & operasi — diuji penuh merentas sekolah, peranan, mod laluan (/KOD/) dan subdomain.',
        'Nombor versi semakan & muktamad kini dikira bagi setiap sekolah (#1, #2, …) pada halaman Kelulusan, sejarah versi dan surat lantikan.',
        'Dibaiki: amaran PHP dan catatan julat tarikh semasa eksport log audit, serta lajur Butiran dalam CSV log audit sekolah.',
        'Panduan pemasangan, akses & kelulusan, dan serahan pembangun (handover.md) ditulis semula untuk edisi berbilang sekolah.',
    ]],
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
