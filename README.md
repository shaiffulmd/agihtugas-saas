# UrusTugas SaaS

Sistem agihan tugas guru berbilang sekolah (SK & SMK): guru, jawatan & pemberat, lantikan, auto-agihan dengan Pusat Syarat, aliran kelulusan Draf → Semakan → Lulus → Muktamad, surat lantikan (cetak/PDF), Tugas Saya untuk guru, hebahan, dan API v1 per sekolah. Lapisan platform — penyewa, panel platform, langganan Free/Pro & bayaran, pendaftaran awam, direktori, keselamatan, kemas kini automatik, sandaran tersulit — diasaskan daripada koku-multi commit `5fb9ba9` dan dijenamakan semula.

**UrusTugas berdiri sendiri.** Tiada fail, pangkalan data, kuki atau kunci yang dikongsi dengan koku-multi; integrasi (pilihan) hanya melalui API HTTP — lihat [integrations/koku-multi/](integrations/koku-multi/README.md).

Versi semasa: **1.1** (sejarah perubahan dalam aplikasi: `/?page=versi`, sumber `app/changelog.php`).

## Mula

- Pemasangan (cPanel / VPS / cron): [docs/PEMASANGAN.md](docs/PEMASANGAN.md)
- Peranan & aliran kelulusan: [docs/AKSES_KELULUSAN.md](docs/AKSES_KELULUSAN.md)
- Auto-agihan, Pusat Syarat & jawatan pukal: [docs/AGIHAN_AUTO.md](docs/AGIHAN_AUTO.md)
- API v1: [docs/API.md](docs/API.md) · Kemas kini GitHub: [docs/KEMASKINI-GITHUB.md](docs/KEMASKINI-GITHUB.md) · Laman demo: [docs/PANDUAN-DEMO.md](docs/PANDUAN-DEMO.md)
- Serahan pembangun (seni bina, peraturan kod, ujian, keluaran): [handover.md](handover.md)

## Keperluan ringkas

PHP 8.2+ (pdo_mysql, sodium, mbstring, zip, dom, gd; curl disyorkan), MariaDB 10.6+ / MySQL 8, HTTPS. Document root = `public_html/` sahaja. Zip keluaran sudah mengandungi `vendor/` (mPDF).

Sekolah dicapai di `https://DOMAIN/KOD/` (kod KPM, cth. `/CEA1234/`) atau `https://kod.DOMAIN/`; panel platform di `/platform/` (2FA wajib). Peranan sekolah: Pentadbir, Penolong Kanan, Guru Besar (SK) / Pengetua (SMK), Guru.

## Pembangunan

```sh
php -S 127.0.0.1:8091 -t public_html public_html/router.php
sh tests/run_all.sh statik                 # lint, audit SQL penyewa, simbol tidak ditakrif
URL=http://127.0.0.1:8091 sh tests/run_all.sh demo      # pemasangan DEMO_MODE=1
```

Kumpulan `tempatan` (keselamatan, platform, bayaran, akaun, domain, kemas kini, sandaran, API, subdomain) dan `baharu` (pemasang web pada DB kosong) memerlukan pemasangan ujian berasingan — arahan di kepala [tests/run_all.sh](tests/run_all.sh) dan [handover.md](handover.md#5-ujian).

## Keluaran

```sh
sh release/build-zip.sh      # → urustugas-v<APP_VERSION>-<tarikh>.zip
```

Setiap fail baharu dalam `app/ bin/ public_html/ database/` mesti ditambah ke `release/zip-files.txt` (binaan gagal jika tertinggal). Push ke `main` membina dan menerbitkan Release `terkini` melalui `.github/workflows/terbit.yml`.

## Pindah daripada AgihTugas / UrusTugas satu sekolah

```sh
php bin/import-agihtugas.php --db=/laluan/agihtugas.sqlite --code=CEA1234 --dry-run
php bin/import-agihtugas.php --db=/laluan/agihtugas.sqlite --code=CEA1234
```

Kod lama satu sekolah (SQLite) disimpan di [`legacy/`](legacy/) sebagai rujukan sejarah; ia tidak dimuatkan oleh aplikasi dan tidak masuk zip keluaran.
