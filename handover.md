# Serahan pembangun — UrusTugas SaaS 1.12

Dokumen ini untuk pembangun yang menyambung kerja. Pengguna akhir: [README.md](README.md) dan [docs/PEMASANGAN.md](docs/PEMASANGAN.md). Sejarah naik taraf (Fasa 1–8, 26 Okt 2026): [docs/PELAN_SETARA_KOKU_MULTI.md](docs/PELAN_SETARA_KOKU_MULTI.md); nota analisis asal: [docs/NOTA_KOD.md](docs/NOTA_KOD.md).

## 1. Prinsip

- **Berdiri sendiri.** UrusTugas dan koku-multi tidak bergantung antara satu sama lain: tiada fail, pangkalan data, kuki, sesi atau kunci dikongsi. Lapisan platform disalin sekali daripada koku-multi commit `5fb9ba9` (v2.15); pembetulan di sana kemudian **mesti disalin secara manual**. Integrasi hanya melalui API HTTP (`integrations/koku-multi/UrusTugasClient.php`, kunci `ut_` per sekolah).
- **Satu pangkalan data, banyak sekolah.** Setiap jadual sekolah ada `school_id NOT NULL`, `UNIQUE school_row(school_id,id)` dan FK komposit `(school_id,x_id) → induk(school_id,id)`. Sekolah tidak pernah ditentukan daripada input klien — hanya daripada URL/hos (UI) atau kunci API.

## 2. Aliran permintaan

`public_html/index.php` → `app/bootstrap.php` (`.env`, PDO dengan `time_zone='+08:00'`, CSP ketat, helper) → `tenant_boot()` (`app/tenant.php`: buang `/KOD` daripada URI atau baca subdomain, lencong ke bentuk utama) →

- `/kemaskini-hook` → `app/updater.php` (webhook GitHub).
- `/KOD/api/v1/…` atau `kod.DOMAIN/api/v1/…` → `app/api.php` `api_main()` (kunci menentukan sekolah; IP, mod kecemasan, langganan & ciri Pro disemak; respons JSON).
- Tiada sekolah → `app/platform.php` `platform_main()`: halaman utama (`landing.php`), direktori, FAQ, daftar, terma, dan `/platform/` (panel, 2FA wajib).
- Sekolah → log masuk/2FA/peranan, tindakan POST (`actions.php`, `enjin_actions.php` → `handle_engine_action()`), kemudian `views.php` / `enjin_views.php`.

## 3. Peraturan kod (wajib)

1. Setiap query jadual sekolah mengandungi `school_id='.sid()` (atau `school_id=?`), termasuk setiap JOIN (`… AND t.school_id=a.school_id`). `tests/tenant_sql_audit.py` gagal jika tidak.
2. CLI/kerja latar menggunakan `with_school($school, fn() => …)`; jangan set `school()` secara manual.
3. Jadual sekolah baharu: tambah ke `database/schema.sql`, `ENGINE_TABLES`/`run_upgrade()` (`app/install.php`, idempoten), `SCHOOL_TABLES` (`app/tenant.php`, anak dahulu — untuk `school_wipe()`), `TABLES` dalam `tests/tenant_sql_audit.py`, dan naikkan `APP_SCHEMA`.
4. Tiada skrip/gaya sebaris (CSP `script-src 'self'; style-src 'self'`). Gaya enjin dalam `public_html/assets/tugas.css`, tingkah laku dalam `tugas.js`.
5. Output melalui `e()`; setiap POST `verify_csrf()`; perubahan data panggil `audit(action, entity, id, detail)` — log audit berantai HMAC (`audit_verify()`); jika ujian memadam baris audit, panggil `audit_rebuild(school_id)`.
6. Ralat enjin dilempar sebagai `EngineError` / `AllocationError` / `WorkflowError` (`app/enjin.php`) dan dipaparkan sebagai flash (UI) atau JSON (API).
7. Ciri Pro: `feature_on('kunci')` / `require_feature('kunci')` (`app/subscription.php`); had Free melalui `free_guard()` di setiap titik tambah (UI, pukal, API).
8. Mod demo (`DEMO_MODE=1`) menyekat tindakan dalam `DEMO_BLOCKED` (`app/demo.php`); sesi sokongan platform menyekat `IMP_BLOCKED` (`app/impersonate.php`).
9. Teks paparan kepada pengguna dalam Bahasa Melayu. Nombor versi untuk pengguna ialah `workflow_no()` (per sekolah), bukan ID revisi global.

## 4. Peta modul enjin

| Fail | Isi |
|---|---|
| `app/enjin.php` | Kategori, ralat, halaman (`ENGINE_PAGES`), profil sekolah, CRUD guru/jawatan, lantik, pukal CSV, indeks beban (`load_index()`, `index_settings()`), katalog mata pelajaran (`SUBJECT_TEMPLATES`, `subject_save()`), skor peranan (`ROLE_STANDARD`, `role_save()`, `roles_match_preview()`), kunci API |
| `app/tugas_auto.php` | Pusat Syarat, auto-agihan deterministik, pratonton (`allocation_plans`, sekali guna 30 min) |
| `app/kelulusan.php` | Aliran Draf→Semakan→Lulus→Muktamad (`workflow_state.version` optimistik), snapshot revisi, `workflow_published()`, `workflow_letter()`; pemulangan + guru ditanda dalam `workflow_state.return_info` (`workflow_return_info()`; tanda selesai `workflow_flag_resolve()`; dibawa semasa hantar untuk penyemak, dikosongkan semasa lulus) |
| `app/surat_lantikan.php` | Kepala surat & aset (`school_assets`, id = SHA-256), surat HTML/PDF (mPDF) |
| `app/enjin_actions.php`, `app/enjin_views.php` | Tindakan POST & paparan halaman enjin |
| `app/api.php` | API v1 (`docs/API.md`, `public_html/assets/api/openapi.json`) |
| `app/demo.php` | Akaun & data demo, `demo_seed()` |
| `bin/import-agihtugas.php` | Import SQLite lama (baca sahaja, peta semula ID) |

Lapisan platform (tenant, platform, payments, registration, security, audit_chain, updater, backup, integrity, appearance, directory, faq, support) mengikut koku-multi.

## 5. Ujian

`tests/run_all.sh` mengumpul semua suite mengikut persekitaran (arahan penuh di kepala fail):

| Kumpulan | Persekitaran | Suite (semakan pada 1.0) |
|---|---|---|
| `statik` | tiada pelayan | `php -l` semua fail, `tenant_sql_audit.py`, `static_symbols.php` |
| `demo` | `DEMO_MODE=1`, mod path | `public_demo` 27, `multi_tenant_integration` 57, `engine_integration` 98, `plan_integration` 33, `allocation_engine` 45, `load_index` 28, `letter_format` 14, `subjects` 26, `workflow_flags` 11, `roles` 18, `schema_engine` 53, `import_agihtugas` 27, `integration_client` 21 |
| `tempatan` | salinan berasingan: `APP_ENV=local`, `REQUIRE_2FA=1`, subdomain `localhost:8092`, `PAYMENT_TEST_BASE`, `php tests/setup_test_install.php` | `security.php`, `errors` 10, `security_integration` 72, `integrity` 28, `platform` 96, `payment` 69, `accounts` 66, `domain` 16, `appearance` 39, `directory` 33, `updater_local` 13, `backup_roundtrip` 12, `api_security` 17, `multi_tenant` (subdomain) 33 |
| `baharu` | DB kosong, tiada `.env` | `smoke_platform` 67 (pemasang web → platform → 2 sekolah → semua halaman) |

Ujian `tempatan` mengubah tetapan platform dan data — **jangan** jalankan pada pemasangan sebenar. Selepas `baharu`, pulihkan `public_html/install.php` (`mv public_html/install.php.locked-* public_html/install.php`). Ujian log masuk menunggu langkah TOTP 30 s seterusnya (perlindungan main semula), jadi kumpulan `demo`/`tempatan` mengambil beberapa minit.

## 6. Keluaran

1. Naikkan `APP_VERSION` & `APP_RELEASE` (`app/install.php`); `APP_SCHEMA` jika skema berubah.
2. Tambah entri di atas `APP_CHANGELOG` (`app/changelog.php`).
3. Fail baharu → `release/zip-files.txt`; `sh release/build-zip.sh` (gagal jika fail dijejak git tertinggal atau `.env` termasuk).
4. Push ke `main` → `.github/workflows/terbit.yml` membina zip, menerbitkan Release `terkini` dan memanggil webhook laman (`DEPLOY_HOOKS`, `DEPLOY_SECRET`).

## 7. Had diketahui & cadangan seterusnya

- Gerbang bayaran (ToyyibPay, Billplz, CHIP, SecurePay) dan CAPTCHA/Cloudflare diuji dengan pelayan olok-olok sahaja — sahkan dengan akaun sandbox di staging sebelum produksi.
- Auto-agihan ialah heuristik deterministik, bukan pengoptimuman; tiada pemilih untuk mencetak semula surat versi muktamad lama (sejarah versi disimpan).
- Nombor rujukan surat menggunakan ID guru global (`Ruj. Kami: … (ID / Vn)`).
- `legacy/` (kod SQLite satu sekolah) disimpan sebagai rujukan; tidak dimuatkan dan tidak masuk zip.
- Pembetulan lapisan platform dalam koku-multi selepas `5fb9ba9` belum disemak untuk disalin.
