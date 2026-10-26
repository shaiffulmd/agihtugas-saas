# Pelan naik taraf: UrusTugas SaaS setara koku-multi

> Disediakan 26 Okt 2026. Status: **Fasa 1–3 siap (26 Okt 2026, cawangan `fasa1-rangka` → `fasa2-skema` → `fasa3-enjin`); Fasa 4 seterusnya.** Menggantikan garis kasar dalam [SAAS_ROADMAP.md](SAAS_ROADMAP.md).
> Rujukan: `/Users/babah/Documents/projek/koku-multi` (v2.15, `APP_SCHEMA 2.15-m10`), terutama `handover.md` §2–§6.

## 0. Jurang semasa

| Perkara | UrusTugas sekarang | koku-multi |
|---|---|---|
| Saiz kod | ~1,100 baris, 14 fail `app/` | ~11,600 baris, 72 fail `app/` |
| Pangkalan data | SQLite, 15 jadual, tiada `school_id`; 3 jadual tunggal `CHECK(id=1)` (`allocation_config`, `setup_codes`, `workflow_state`) | MariaDB/MySQL, 46 jadual, `school_id NOT NULL` + FK komposit `(school_id, x_id)` |
| Tenant | Satu sekolah satu pemasangan | `/KOD/` atau `kod.domain`, `sid()`, `tenant_boot()`, subkunci HKDF setiap sekolah |
| Platform | Tiada | `/platform/` — sekolah, pentadbir platform, log, tetapan, 2FA |
| Langganan & bayaran | Tiada | Free/Pro, percubaan, ToyyibPay/Billplz/CHIP/SecurePay + manual, diskaun, tajaan, resit |
| Pendaftaran awam | Tiada | `/daftar` + CAPTCHA + pengesahan e-mel + kelulusan |
| Keselamatan | `PASSWORD_DEFAULT` (bcrypt), sesi, CSRF, had cubaan log masuk; tiada 2FA | 2FA TOTP wajib, rantai audit HMAC, sekatan IP + Cloudflare, CAPTCHA pihak ketiga, mod kecemasan, security.txt |
| Operasi | Pakej ZIP cPanel/VPS | Kemas kini automatik GitHub (manifest SHA-256 + rollback), sandaran tersulit ke Google Drive, integriti fail, `sihat.php`, cron harian |
| Awam | Tiada | Halaman utama, direktori 3 paparan, FAQ, terma/polisi + persetujuan, rupa boleh suai |

## 1. Keputusan asas

> 26 Okt 2026: pengguna **bersetuju** dengan perkara 1 (rangka koku-multi) dan 2 (MariaDB). Perkara 3–6 ialah lalai cadangan; sahkan semasa fasa berkaitan.

1. **Pendekatan — disyorkan: guna rangka platform koku-multi, port enjin UrusTugas ke dalamnya.**
   Lapisan platform koku-multi (tenant, platform, langganan, bayaran, keselamatan, updater, sandaran) sudah diuji (~650+ semakan). Enjin UrusTugas kecil (guru, jawatan, lantikan, auto-agihan, aliran kelulusan, surat, API) — lebih murah ditulis semula di atas `school_id` berbanding membina semula 10,000 baris platform di atas SQLite.
   Modul khusus koku (murid, unit, kehadiran, PAJSK, takwim, laman awam sekolah, AGM, Program Luar dsb.) **tidak dibawa**.
2. **Pangkalan data: MariaDB 10.6+/MySQL 8** (sama seperti koku-multi). SQLite tidak menyokong beban berbilang sekolah serentak dengan baik dan semua kod platform koku-multi bergantung pada MySQL (`FOR UPDATE`, `DATE_ADD(NOW())`, FK komposit InnoDB).
3. **Pengenalan sekolah:** kod KPM `[A-Z]{3}[0-9]{4}`, laluan `/KOD/` + subdomain pilihan (sama seperti koku-multi).
4. **Pakej:** Free & Pro. Had Free dicadang: bilangan guru aktif, bilangan jawatan, pengguna; ciri Pro: auto-agihan, surat pukal/PDF, API, sejarah versi. (Angka ditetapkan di Tetapan Platform.)
5. **Peranan sekolah kekal:** pentadbir, PK, GB/Pengetua, guru (`app/auth.php`). Pentadbir platform berasingan.
6. **Integrasi koku-multi:** API key per sekolah; `external_id` unik dalam `(school_id, external_id)`. Pilihan lanjut: SSO/sync guru antara dua platform jika dipasang pada domain sama.

## 2. Fasa pelaksanaan

Setiap fasa = satu cawangan/PR, boleh diuji sendiri. Susunan ikut kebergantungan.

### Fasa 1 — Rangka baharu daripada koku-multi
- Salin struktur koku-multi: `public_html/`, `app/{bootstrap,tenant,platform,platform_accounts,install,security,audit_chain,captcha,mailer,errors,icons,changelog}.php`, `bin/`, `database/schema.sql`, `lib/`, `composer.json`, `release/`, `.github/workflows/terbit.yml`.
- Buang semua modul koku dan jadualnya daripada `schema.sql` (students, units, memberships, activities, attendance, achievements, pajsk, reports, takwim, site, agm, …). Kekalkan jadual platform: `schools`, `platform_admins`, `platform_log`, `platform_settings`, `rate_limits`, `health_log`, `users`, `recovery_codes`, `audit_logs`, `audit_heads`, `settings`, `consents`, sokongan, bayaran, keselamatan.
- Jenama: nama aplikasi "UrusTugas", kuki `urustugas_sesi` / `urustugas_platform`, HKDF `urustugas/school/{id}`, awalan resit, nama zip.
- Pengesahan: pemasang web pada DB kosong → `/platform/` + satu sekolah kosong boleh log masuk; `tenant_sql_audit.py` bersih.
- **Siap 26 Okt 2026.** Kod lama di `legacy/`. Skema `0.1-m1`: 13 jadual sekolah/platform tanpa jadual koku, `users.role` = admin/pk/head/teacher, `audit_logs.detail` (dalam rantai audit). Jenis sekolah SK/SMK (`school_type`, gelaran Guru Besar/Pengetua). Had Free ditukar kepada guru/jawatan/pengguna (`free_guru`, `free_jawatan`; guru & jawatan dikira apabila jadual Fasa 2 wujud). Addon: rangka kekal, senarai kosong. Demo: 4 akaun kongsi setiap sekolah, tiada data enjin lagi. Diuji: `tests/smoke_platform.py` 65/65 (pemasang web → platform + 2FA → 2 sekolah → Pentadbir Utama 2FA & tukar kata laluan → semua halaman → peranan → pengasingan sesi → lencongan subdomain), pemasang demo + 4 peranan × 2 sekolah + 23 halaman panel platform tanpa 5xx, `tenant_sql_audit.py` bersih, `php -l` semua fail. Belum: `multi_tenant_integration.py`/ujian platform koku lain (Fasa 8).

### Fasa 2 — Skema enjin UrusTugas dengan `school_id`
Jadual baharu (semua `school_id NOT NULL`, PK `id`, indeks unik `school_row(school_id,id)`, FK komposit):
- `teachers` (+ `external_id`, unik `(school_id,external_id)`), `positions`, `assignments` (unik `(school_id,position_id)`), `position_rules`.
- Jadual tunggal → satu baris setiap sekolah: `allocation_config(school_id PK)`, `workflow_state(school_id PK)`. `setup_codes` dibuang (diganti Pentadbir Utama yang dicipta platform/pendaftaran).
- `allocation_plans`, `workflow_revisions`, `history` → gabung ke `audit_logs` atau kekal dengan `school_id`.
- `school_assets` (logo, kepala surat) → ikut corak logo sekolah koku-multi.
- `api_keys` dengan `school_id`; hash berkunci subkunci sekolah.
- `accounts` UrusTugas digabung ke `users` koku-multi (tambah nilai peranan `pk`, `gb`; `teacher_id` pilihan untuk peranan guru).
- Naikkan `APP_SCHEMA`; kemas kini `SCHOOL_TABLES` + `school_wipe()` (susunan FK).
- **Siap 26 Okt 2026 (skema `0.1-m2`).** Keputusan: `history` → `audit_logs.detail` (tiada jadual `history`); `setup_codes` dibuang; `allocation_plans` & `workflow_revisions` kekal dengan `school_id`, masa dalam `DATETIME` (dikira DB, bukan PHP); `api_keys.token_hash` = HMAC subkunci platform, unik global (sekolah ditentukan oleh kunci, kemudian disemak sama dengan sekolah dalam URL); `school_assets` PK `(school_id,id)` — hash sama tidak dikongsi antara sekolah; `users.teacher_id` FK komposit RESTRICT + unik dalam sekolah; CHECK kapasiti 1–100, pemberat 1–10, JSON_VALID pada payload; `workflow_state` dicipta oleh `school_engine_init()` (cipta sekolah, reset demo, naik taraf). Naik taraf `run_upgrade()` mengambil DDL daripada `schema.sql` (`schema_ddl()`), idempoten. Diuji: `tests/schema_engine.php` 53/53 (FK silang sekolah ditolak, RESTRICT/CASCADE, kekangan, kunci optimistik, `school_wipe` tidak menyentuh sekolah lain, `school_delete`), naik taraf 0.1-m1 → m2 pada pemasangan demo (2 kali, idempoten), pemasangan baharu + `smoke_platform.py` 65/65, `tenant_sql_audit.py` bersih.

### Fasa 3 — Port modul enjin
Tulis semula setiap fail `app/` lama sebagai modul berasaskan `sid()` mengikut peraturan query koku-multi (handover §3):
| Lama | Baharu | Nota |
|---|---|---|
| `public/index.php` (halaman dashboard/teachers/positions/assignments) | `app/guru.php`, `app/jawatan.php`, `app/agihan_tugas.php`, `app/views.php` | Pecahkan fail monolitik; patuh CSP (tiada `style=`/skrip sebaris) |
| `allocation.php`, `planning_views.php`, `bulk_positions.php` | `app/auto_agihan.php` | Snapshot & pelan per sekolah |
| `workflow.php`, `workflow_views.php` | `app/kelulusan.php` | Kunci versi optimistik `WHERE school_id=? AND version=?` |
| surat (dalam `index.php`) | `app/surat_lantikan.php` | Guna `pdf.php` koku-multi untuk PDF terus |
| `branding.php`, `school.php` | Tetapan sekolah koku-multi (`settings.php`, logo) | Jenis SK/SMK kekal |
| `api.php`, `public/openapi.json` | `app/api.php` | Bearer key → tentukan `school_id` daripada key, bukan daripada klien |
| `auth.php`, `auth_gate.php` | `users.php`, `accounts.php` koku-multi | 2FA, kata laluan sementara, sekatan IP akaun |
- Import guru CSV/Excel: guna `teacher_import.php` koku-multi.
- Ujian: port `tests/{allocation,workflow,api,school_types,branding}.php` ke pemasangan berbilang sekolah; tambah ujian silang sekolah (UI, API, surat, logo, eksport).
- **Siap 26 Okt 2026 (versi 0.2, tiada perubahan skema).** Modul: `app/enjin.php` (guru, jawatan, lantikan, guru pukal CSV, kunci API), `app/tugas_auto.php` (auto-agihan & jawatan pukal — algoritma sama), `app/kelulusan.php`, `app/surat_lantikan.php` (logo/Jata per sekolah, surat HTML & PDF mPDF), `app/enjin_actions.php`, `app/enjin_views.php`, `app/api.php` (API v1 di `/KOD/api/v1`, kunci dicipta di UI, bukan CLI). Halaman `?page=` guru, jawatan, agihan, auto, syarat, pukal, kelulusan, surat, tugas_saya, sekolah, api; akses mengikut `engine_pages()`; GB/Pengetua → kelulusan, Guru → tugas_saya. Akaun Pengguna lama digabung ke Pengguna & Audit (pilihan profil guru, wajib untuk peranan Guru). Nama/jenis sekolah dan tetapan surat dikunci bukan Draf. `NOW()` DB diset `+08:00` supaya selaras dengan PHP. Import guru guna CSV ringkas (`name,email,specialty,capacity`), bukan `teacher_import.php` koku (yang mengimport akaun pengguna). Diuji: `tests/engine_integration.py` 85/85 (peranan, CRUD, pukal, syarat, auto-agihan lapuk/sekali guna, aliran penuh & kunci, surat HTML/PDF, Tugas Saya, API & kunci per sekolah, pengasingan), `tests/allocation_engine.php` 45/45 (port ujian algoritma lama + pengasingan), `smoke_platform.py` 67/67, `schema_engine.php` 53/53, audit SQL bersih, semakan visual dashboard/guru/agihan/surat. Belum: had Pro (auto-agihan/API/surat pukal untuk Pro sahaja) → Fasa 4; klien integrasi koku-multi → Fasa 7.

### Fasa 4 — Platform & langganan (ambil terus, sesuaikan had)
- `subscription.php`: tukar `free_count()` kepada guru/jawatan/pengguna; `free_guard()` pada tambah guru, import, tambah jawatan, cipta pengguna; sekat auto-agihan/API/surat pukal bila Free.
- `payments.php`, `payment_pages.php`: tiada perubahan logik; tukar label & harga lalai.
- `registration.php`, `legal.php`: jenis sekolah SK/SMK, teks terma UrusTugas.
- `support.php`, `impersonate.php`, `hebahan.php` (hebahan platform → sekolah), `addons.php` (rangka addon untuk ciri berbayar masa depan).
- `bin/cron.php`: `subscription_daily()` + pembersihan.

### Fasa 5 — Keselamatan & operasi
- Ambil `security.php`, `security_pages.php`, `audit_chain.php`, `integrity.php`, `backup.php`, `updater.php`, `domain.php`, `sihat.php`, `bin/security.php`, `bin/backup.php`, `bin/restore.php`.
- Sediakan repo GitHub baharu UrusTugas (bukan remote AgihTugas) untuk kemas kini automatik; rahsia webhook baharu.
- Senarai `release/zip-files.txt` dikemas kini.

### Fasa 6 — Halaman awam
- `landing.php`, `appearance.php`, `directory.php`, `faq.php`, `links.php`: kandungan pemasaran agihan tugas guru, pakej Free/Pro, FAQ contoh.
- Mod demo: `demo.php` dengan 12 guru, 24 jawatan, 20 lantikan rekaan (data seed AgihTugas sedia ada) bagi 2 sekolah demo; reset harian.

### Fasa 7 — Migrasi & integrasi
- `bin/import-agihtugas.php --db=/laluan/agihtugas.sqlite --code=KOD`: bawa satu pemasangan AgihTugas/UrusTugas SQLite ke satu sekolah (petakan ID, `--dry-run`, semak bilangan baris).
- Klien `integrations/koku-multi/`: sokong API key per sekolah dan URL `/KOD/api/v1`.

### Fasa 8 — QA & keluaran
- Ujian penuh: `multi_tenant_integration.py`, `tenant_sql_audit.py`, `platform_integration.py`, `payment_integration.py`, `security_integration.py` (disesuaikan) + ujian enjin.
- Rangkak semua halaman sebagai setiap peranan × 2 sekolah: tiada 5xx, tiada data sekolah lain.
- Semakan visual desktop & 390 px; `README.md`, `handover.md`, `docs/PEMASANGAN.md` ditulis semula; `APP_VERSION` 1.0.

## 3. Risiko & langkah kawalan
- **Kebocoran silang sekolah** — FK komposit, `NOT NULL school_id`, audit SQL statik, ujian rangkak; API menentukan sekolah daripada key sahaja.
- **Saiz kerja** — fasa 1, 4, 5, 6 kebanyakannya salin + jenama semula; fasa 2–3 ialah kerja reka bentuk sebenar.
- **Gerbang bayaran & CAPTCHA/Cloudflare sebenar** belum diuji dalam koku-multi juga — sahkan dengan akaun sandbox di staging sebelum produksi.
- **Pembetulan berganda** — pepijat yang dibaiki dalam lapisan platform koku-multi kemudian perlu disalin manual; catat versi koku-multi asal (commit `5fb9ba9`) dalam handover.

## 4. Anggaran susunan sesi kerja
1. Fasa 1 → 2 (satu sesi): rangka + skema, pemasang berjalan.
2. Fasa 3 (1–2 sesi): enjin + ujian enjin.
3. Fasa 4 + 6 (satu sesi): langganan, bayaran, halaman awam, demo.
4. Fasa 5 + 7 (satu sesi): keselamatan, operasi, import.
5. Fasa 8 (satu sesi): QA & dokumentasi.
