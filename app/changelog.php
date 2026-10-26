<?php
declare(strict_types=1);
/* Sejarah versi yang dipaparkan di halaman "Versi & Perubahan" (/?page=versi).
 * Setiap kali menerbitkan perubahan: naikkan APP_VERSION & APP_RELEASE dalam app/install.php (dan APP_SCHEMA jika skema berubah),
 * kemudian tambah entri baharu di ATAS senarai ini. Naik versi tanpa perubahan pangkalan data
 * tidak memerlukan langkah naik taraf (fail kunci dikemas kini secara automatik). */

const APP_CHANGELOG=[
    '0.1'=>['2026-10-26',[
        'Rangka berbilang sekolah baharu (diambil daripada koku-multi): panel platform, sekolah dengan kod KPM (/KOD/ atau subdomain), langganan Free/Pro, bayaran, pendaftaran awam, keselamatan dan kemas kini automatik.',
        'Pangkalan data MariaDB/MySQL dengan school_id pada setiap jadual sekolah dan kunci asing komposit.',
        'Peranan sekolah UrusTugas: Pentadbir, Penolong Kanan, Guru Besar / Pengetua dan Guru. Log audit kini menyimpan butiran ringkas dalam rantai audit.',
        'Enjin agihan tugas (guru, jawatan, lantikan, auto-agihan, aliran kelulusan, surat, API) akan dipindahkan dalam fasa seterusnya.',
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
