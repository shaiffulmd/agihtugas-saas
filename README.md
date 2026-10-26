# UrusTugas SaaS

Projek berasingan untuk membangunkan pengurusan agihan tugas guru sebagai SaaS bagi sekolah SK dan SMK.

Asas kod diambil daripada AgihTugas pada commit `072754e`. Repositori, konfigurasi dan data projek ini berasingan daripada AgihTugas. Tiada database, akaun pengguna, logo sekolah runtime, kunci API atau konfigurasi pelayan asal disalin.

## Status semasa

Ini ialah asas pembangunan SaaS. Enjin yang diwarisi masih **satu sekolah bagi satu pemasangan**, termasuk peranan pentadbir/PK/Guru Besar atau Pengetua/guru, agihan automatik, surat dan API. Pengasingan tenant, pendaftaran sekolah dan langganan belum dilaksanakan. Jangan membuka pendaftaran awam atau menempatkan beberapa sekolah dalam database yang sama sebelum pengasingan tenant siap dan diuji.

Nama dan laluan dalaman AgihTugas masih dikekalkan untuk keserasian enjin asal. Penjenamaan UI SaaS boleh diubah semasa membina portal baharu.

## Jalankan untuk pembangunan

PHP 8.2+ dengan pdo_sqlite, mbstring, gd, fileinfo, session; cURL untuk ujian/integrasi.

```sh
AGIHTUGAS_SEED_DEMO=0 php -S 127.0.0.1:8088 -t public public/router.php
php bin/setup-admin.php
```

Buka http://127.0.0.1:8088/?page=setup dan gunakan kod sekali guna untuk mencipta akaun sendiri. Arahan pertama memulakan ruang kerja kosong; pemboleh ubah seed hanya berkesan semasa DB pertama dicipta. Port 8088 berasingan daripada pratonton AgihTugas di 8087. PHP development server bukan pelayan production.

Pakej deployment yang diwarisi mengekalkan document root **public_html** dengan folder agihtugas-private di sebelahnya. Pakej itu masih memasang enjin satu sekolah, bukan platform SaaS berbilang sekolah.

## Fasa pembangunan

Lihat [pelan SaaS](docs/SAAS_ROADMAP.md). Dokumentasi dan arahan ujian enjin asal tersedia dalam [README AgihTugas](README-AGIHTUGAS.md).

Repositori Git setempat ini tidak mempunyai remote. Sambungkan repositori GitHub khusus UrusTugas SaaS apabila URL baharu tersedia; jangan push ke remote AgihTugas.
