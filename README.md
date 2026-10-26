# UrusTugas SaaS

Sistem agihan tugas guru berbilang sekolah (SK & SMK). Rangka platform (penyewa, panel platform, langganan Free/Pro, bayaran, pendaftaran awam, keselamatan, kemas kini automatik, sandaran) disalin sekali daripada koku-multi commit `5fb9ba9` dan dijenamakan semula. **UrusTugas berdiri sendiri** — tiada fail, pangkalan data, kuki atau kunci yang dikongsi dengan koku-multi; integrasi (jika ada) hanya melalui API.

## Status

- **Fasa 1–7 siap** (rangka platform, skema enjin, modul enjin, langganan Free/Pro, keselamatan & operasi, halaman awam & demo, import AgihTugas & klien integrasi). Panduan demo: [docs/PANDUAN-DEMO.md](docs/PANDUAN-DEMO.md). Lihat [pelan](docs/PELAN_SETARA_KOKU_MULTI.md), [nota kod](docs/NOTA_KOD.md) dan [API](docs/API.md).
- Enjin: guru, jawatan, lantikan, auto-agihan, Pusat Syarat, aliran kelulusan, surat lantikan (cetak/PDF), Tugas Saya dan API v1 per sekolah. Kod lama satu sekolah (SQLite) kekal di [`legacy/`](legacy/) sebagai rujukan sejarah.

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
php tests/static_symbols.php       # fungsi & pemalar tidak ditakrif (tinggalan modul)
TEST_URL=http://127.0.0.1:8091 python3 tests/public_demo.py   # pemasangan DEMO_MODE=1
php tests/schema_engine.php        # pada pemasangan sedia ada (.env): FK & kekangan enjin
php tests/allocation_engine.php    # algoritma auto-agihan & jawatan pukal
php tests/setup_engine_test.php > ujian.json && SETUP_JSON=ujian.json python3 tests/engine_integration.py
```

`smoke_platform.py` memerlukan DB kosong dan tiada `.env`. Pemasang menamakan semula `public_html/install.php` selepas siap — pulihkan sebelum commit: `mv public_html/install.php.locked-* public_html/install.php`.

### Ujian keselamatan & operasi (pemasangan berasingan)

Ujian ini mengubah tetapan platform, jadi jalankan pada **salinan projek** dengan `.env` & DB sendiri: `APP_ENV=local`, `DEMO_MODE=0`, `REQUIRE_2FA=1`, `TENANT_PRIMARY=subdomain`, `PLATFORM_DOMAIN=localhost:8092`, `PAYMENT_TEST_BASE=http://127.0.0.1:8095`, kemudian `php tests/setup_test_install.php` dan `php -d session.save_path=DIR -S 127.0.0.1:8092 -t public_html public_html/router.php`.

```sh
TEST_URL=http://dem0001.localhost:8092 SESSION_DIR=DIR python3 tests/security_integration.py
TEST_URL=http://dem0001.localhost:8092 python3 tests/integrity_integration.py
UPD_TEST_ENV=.env python3 tests/updater_local.py
php tests/security.php
php tests/backup_roundtrip.php DB_KOSONG_SASARAN
php tests/setup_engine_test.php > ujian.json && TEST_URL=http://127.0.0.1:8092 python3 tests/api_security.py
```

Panduan kemas kini automatik daripada GitHub: [docs/KEMASKINI-GITHUB.md](docs/KEMASKINI-GITHUB.md).

## Pindah daripada AgihTugas / UrusTugas satu sekolah

```sh
php bin/import-agihtugas.php --db=/laluan/agihtugas.sqlite --code=CEA1234 --dry-run   # semak dahulu
php bin/import-agihtugas.php --db=/laluan/agihtugas.sqlite --code=CEA1234 [--name="…"] [--type=SK|SMK] [--state=Pahang]
```

Sekolah baharu dicipta dengan semua guru, jawatan, lantikan, akaun (kata laluan lama), syarat, versi muktamad & surat, logo dan sejarah. Kunci API lama perlu dicipta semula (API & Integrasi). Ujian: `php tests/import_agihtugas.php`. Sistem lain yang bersambung: [integrations/koku-multi/README.md](integrations/koku-multi/README.md).

## Keluaran

Setiap fail baharu mesti ditambah ke `release/zip-files.txt` (`sh release/build-zip.sh` → `urustugas-v<versi>-<tarikh>.zip`). Naikkan `APP_SCHEMA` (app/install.php) setiap kali skema berubah, tambah langkah idempoten dalam `run_upgrade()`, dan selaraskan `SCHOOL_TABLES` (app/tenant.php) serta `TABLES` dalam `tests/tenant_sql_audit.py`.
