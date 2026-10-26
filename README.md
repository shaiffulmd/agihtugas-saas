# UrusTugas SaaS

Sistem agihan tugas guru berbilang sekolah (SK & SMK). Rangka platform (penyewa, panel platform, langganan Free/Pro, bayaran, pendaftaran awam, keselamatan, kemas kini automatik, sandaran) disalin sekali daripada koku-multi commit `5fb9ba9` dan dijenamakan semula. **UrusTugas berdiri sendiri** — tiada fail, pangkalan data, kuki atau kunci yang dikongsi dengan koku-multi; integrasi (jika ada) hanya melalui API.

## Status

- **Fasa 1 (rangka platform) — siap.** Lihat [pelan](docs/PELAN_SETARA_KOKU_MULTI.md) dan [nota kod](docs/NOTA_KOD.md).
- Enjin agihan tugas (guru, jawatan, lantikan, auto-agihan, kelulusan, surat, API) belum dipindahkan — kod lama satu sekolah (SQLite) disimpan di [`legacy/`](legacy/) sebagai rujukan sehingga Fasa 3 siap.

## Keperluan

PHP 8.2+ (pdo_mysql, sodium/Argon2id, mbstring, zip, dom, gd, curl), MariaDB 10.6+ / MySQL 8, Composer (`composer install --no-dev` untuk mPDF). Document root = `public_html/` sahaja.

## Pasang

1. Cipta pangkalan data kosong + pengguna.
2. Buka `/install.php` → isi butiran DB dan Pentadbir Platform (atau **Pasang demo**: 2 sekolah demo DEM0001 SMK & DEM0002 SK).
3. Panel platform: `/platform/` (2FA wajib). Sekolah: `/KOD/` (kod KPM, cth. `/CEA1234/`) atau `kod.domain` jika subdomain dihidupkan.

Peranan sekolah: Pentadbir, Penolong Kanan, Guru Besar (SK) / Pengetua (SMK), Guru.

## Ujian setempat

```sh
php -S 127.0.0.1:8091 -t public_html public_html/router.php
TEST_URL=http://127.0.0.1:8091 DB_PORT=… DB_NAME=… DB_USER=… DB_PASS=… python3 tests/smoke_platform.py
python3 tests/tenant_sql_audit.py
```

`smoke_platform.py` memerlukan DB kosong dan tiada `.env`. Pemasang menamakan semula `public_html/install.php` selepas siap — pulihkan sebelum commit: `mv public_html/install.php.locked-* public_html/install.php`.

## Keluaran

Setiap fail baharu mesti ditambah ke `release/zip-files.txt` (`sh release/build-zip.sh` → `urustugas-v<versi>-<tarikh>.zip`). Naikkan `APP_SCHEMA` (app/install.php) setiap kali skema berubah.
