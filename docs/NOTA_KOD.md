# Nota kod sebelum naik taraf

> Dirakam 26 Okt 2026 selepas membaca keseluruhan kod UrusTugas (`843c7af`) dan lapisan platform koku-multi (`5fb9ba9`, working tree bersih).
> Rujukan kerja untuk [PELAN_SETARA_KOKU_MULTI.md](PELAN_SETARA_KOKU_MULTI.md). Apa-apa yang perlu dikekalkan semasa port ditanda **KEKAL**.

---

## A. UrusTugas sekarang

### A1. Struktur & aliran permintaan
- `public/index.php` (53 baris, sangat panjang setiap baris): `/api/*` → `app/api.php`; selainnya `app/bootstrap.php` kemudian HTML monolitik dengan `if($page===…)` untuk dashboard, teachers, positions, assignments, settings. Halaman lain dipanggil dari `planning_views.php` (rules, auto, bulk) dan `workflow_views.php` (workflow, accounts, profile, letters, my_tasks).
- `app/bootstrap.php`: sesi (nama kuki ikut hash laluan DB), header asas, `e()`, `csrf()`, `auth_gate()`, kemudian **semua tindakan POST** dalam satu `switch($action)` dalam satu transaksi, diikuti `INSERT INTO history` dan PRG (`$_SESSION['flash']`, lencong 303 ke `?page=`).
- Tindakan POST: `account_save`, `profile_password`, `workflow`, `teacher`, `position`, `assign`, `unassign`, `delete_teacher`, `delete_position`, `allocation_rules`, `position_rule`, `auto_preview`, `auto_apply`, `bulk_preview`, `bulk_apply`, `school_logo_upload`, `school_logo_remove`, `letter_branding`, `settings`.
- Kawalan tindakan: `account_save` → admin; `settings`/logo/kepala surat → admin; lain-lain → admin|pk **dan** `workflow_require_draft()`. `workflow` dan `profile_password` semak sendiri.
- `router.php` (pelayan dev) & `.htaccess`: bukan-fail → `index.php`; `Authorization` dihantar kepada PHP.
- `config/local.php` (pilihan) → `database`, `seed_demo`; env `AGIHTUGAS_DB`, `AGIHTUGAS_SEED_DEMO`, `AGIHTUGAS_CORS_ORIGINS`.

### A2. Skema SQLite (15 jadual, `app/database.php`, migrasi tambahan `CREATE … IF NOT EXISTS`)
| Jadual | Nota untuk port |
|---|---|
| `settings(key,value)` | Kunci: `school`, `principal`, `year`, `reference`, `demo`, `school_type` (SK/SMK), `school_logo_id`, `national_crest_id`, `letter_header` (dual/school/national) |
| `teachers(id,name,email,specialty,capacity 1–100,external_id)` | `external_id` unik global → mesti `(school_id,external_id)` |
| `positions(id,name,category,weight 1–10)` | `CATEGORIES`=Pentadbiran, Kurikulum, HEM, Kokurikulum |
| `assignments(id,teacher_id,position_id UNIQUE)` | Satu jawatan = satu penyandang; FK `RESTRICT` |
| `history(id,description,created_at)` | Log teks bebas. `audit_logs` koku-multi tiada medan teks → perlu lajur `detail` atau kekal jadual `history` ber-`school_id` |
| `api_keys(name,token_hash sha256,prefix,scope read/write,last_used_at,revoked_at)` | Token `agt_` + 64 hex |
| `allocation_config(id=1,payload JSON)` | Tunggal → `PK school_id` |
| `position_rules(position_id,payload JSON)` | `allowed_teachers[]`, `fixed_teacher_id`, `required_specialty` |
| `allocation_plans(id hex48,snapshot,payload,created_at,applied_at)` | Pratonton 30 min, dibuang selepas 24 j |
| `accounts(email NOCASE UNIQUE,role admin/pk/head/teacher,teacher_id UNIQUE,active,auth_version)` | Digabung ke `users` koku-multi |
| `login_attempts`, `setup_codes(id=1)` | Diganti `rate_limits` & Pentadbir Utama platform |
| `workflow_revisions(status,payload JSON,source_hash,submitted/approved/finalized/returned_by+at,return_reason)` | FK ke `accounts` |
| `workflow_state(id=1,status,revision_id,version)` | Tunggal → `PK school_id` |
| `school_assets(id sha256,mime,width,height,bytes BLOB)` | Logo & Jata; id = hash kandungan → kongsi merentas sekolah tidak selamat, tambah `school_id` |

Data demo (`AGIHTUGAS_SEED_DEMO`): 12 guru, 24 jawatan, 20 lantikan — **KEKAL** sebagai seed `demo.php` baharu.

### A3. Peraturan perniagaan — **KEKAL**
**Peranan** (`auth.php`): admin=Pentadbir, pk=Penolong Kanan, head=Guru Besar (SK) / Pengetua (SMK), teacher=Guru.
- Halaman: admin → semua + accounts/settings; pk → perancangan + letters + workflow; head → workflow, letters, profile; teacher → my_tasks, profile.
- Akaun guru wajib dipaut kepada satu profil guru unik. Tidak boleh nyahaktif/turun peranan diri sendiri; ≥1 admin aktif. Kata laluan 12–72 aksara.

**Aliran kelulusan** (`workflow.php`): Draf → Semakan → Lulus → Muktamad.
- submit: admin|pk, status draf, ≥1 lantikan; simpan snapshot JSON (`school_profile`, teachers, positions+teacher_id, rules, position_rules) + sha256.
- approve/finalize: **head sahaja**; penghantar tidak boleh lulus sendiri; hash snapshot mesti sama dengan data semasa.
- return: admin|head, sebab wajib ≤500; dari muktamad → draf pindaan (revisi muktamad tidak ditanda returned).
- Kunci optimistik `version`. Data perancangan dikunci (UI `fieldset disabled` + `workflow_require_draft()`) bila bukan draf.
- Surat & "Tugas Saya" guna **revisi muktamad terkini** (snapshot tetap), bukan data semasa. Tarikh surat = `finalized_at` UTC → MYT.

**Auto-agihan** (`allocation.php`, lihat `docs/AGIHAN_AUTO.md`):
- Syarat umum lalai: `respect_capacity=true`, `max_tasks=13`, `max_heavy=2`, `heavy_threshold=8`, had bidang 4 setiap satu, `excluded_teachers=[]`.
- Mod `vacant` (kekal lantikan sedia ada, beri amaran pelanggaran) dan `rebalance` (kira semula dari kosong).
- Susunan: penyandang tetap dahulu → calon paling sedikit → pemberat tinggi → id. Pilih nisbah `(beban+pemberat)/kapasiti` terendah; seri: bil. tugas, bil. tugas bidang, id.
- Pratonton disimpan dengan hash keadaan (`allocation_state()` termasuk `workflow` & `settings`); apply menolak jika keadaan berubah, sudah digunakan, atau >30 min.
- Guru yang dirujuk dalam syarat tidak boleh dipadam.

**Jawatan pukal** (`bulk_positions.php`): CSV/tab, lajur `name,category,weight,count`; ≤128 KB, ≤500 baris, ≤1,000 tempat; count n → "Nama (i/n)"; pendua nama+bidang ditolak.

**Jenama/surat** (`branding.php`): logo PNG/JPG/WebP ≤2 MB, ≤4096 px & 4 MP, diproses semula GD → PNG ≤640 px. Jata Negara dalam `public/assets/shared/jata-negara.png` (sumber AKM). Gaya kepala surat dual/school/national. Logo surat muktamad lama kekal (id hash kandungan).

**API v1** (`api.php`, `docs/API.md`, `public/openapi.json`): Bearer `agt_…`; tiada sesi/kuki; CORS ikut senarai; JSON ≤64 KB, medan tidak dikenali ditolak.
- Endpoint: `teachers`, `positions`, `assignments` (CRUD + paginasi + tapisan), `teachers/sync` (upsert external_id), `teachers/{id}/assignments`, `positions/{id}/assignment` (PUT), `positions/{id}/rules`, `positions/bulk`, `allocation/rules|preview|apply`, `workflow`, `workflow/published`, `letters/{teacherId}`, `summary`, `school`, `assets/{sha256}`.
- Semua tulisan hanya ketika Draf; API tidak boleh lulus/muktamad. Kod ralat stabil (`workflow_locked`, `stale_or_used_plan`, `duplicate_external_id`, `record_in_use` …) — **KEKAL** kontrak supaya klien koku-multi tidak pecah.
- `integrations/koku-multi/AgihTugasClient.php`: `external_id = koku-multi:{school_id}:{user_id}`, guru baharu kapasiti 25.

### A4. Ujian sedia ada (PHP, pelayan `php -S` + SQLite sementara `sys_get_temp_dir()/agihtugas-*`)
`integration`, `allocation`, `auth_setup`, `workflow`, `session_isolation`, `school_types`, `branding`, `installer`, `api`. Fixture akaun: `tests/fixtures.php` (kata laluan ujian sahaja). Senario ini perlu ditulis semula untuk MariaDB + dua sekolah.

### A5. Hutang teknikal yang perlu dibaiki semasa port
- `public/index.php` & `bootstrap.php` monolitik — pecah ikut modul.
- **CSP:** `index.php` ada `style="padding:0 24px 24px"` (panel API di Tetapan); koku-multi guna `style-src 'self'` → pindah ke CSS.
- Kata laluan `PASSWORD_DEFAULT` (bcrypt) → Argon2id `hash_password()`; tiada 2FA, tiada lupa kata laluan.
- Had cubaan log masuk ikut `REMOTE_ADDR` sahaja (tiada `TRUSTED_PROXIES`).
- `history` menyimpan nama/e-mel dalam teks bebas; tiada rantai audit.
- Nama "AgihTugas" masih dalam UI, kuki, nama zip, `agihtugas-private`, realm API, `agihtugas.css`.
- `base.css` (1810 baris) ialah salinan `app.css` koku-multi; fon sama — boleh terus guna aset koku-multi + `agihtugas.css` (13 baris) sebagai tambahan.

---

## B. koku-multi — apa yang diambil

### B1. Asas (`app/bootstrap.php`, `app/tenant.php`)
- `.env` (dibaca sendiri, tiada Dotenv): `APP_KEY` base64 32 bait, `DB_*`, `SESSION_SECURE`, `REQUIRE_2FA`, `PLATFORM_DOMAIN`, `TENANT_PRIMARY`, `UPDATE_*`, `DEMO_MODE`, `TRUSTED_PROXIES`, `PAYMENT_TEST_BASE`.
- Helper: `db()`, `query()`, `input()`, `e()`, `encrypt_value()/decrypt_value()` (secretbox, subkunci sekolah), `hash_password()` Argon2id, `asset()` berversi, `redirect()`, `csrf_field()/verify_csrf()`, `flash()`, `audit()`, `current_user()`, `require_admin()`, TOTP, `rate_key/rate_allowed/rate_hit`.
- Nama berbeza daripada UrusTugas: `q()`→`query()`, `csrf()` mengembalikan token (bukan input HTML) → `csrf_field()`. Port perlu tukar semua panggilan.
- Tenant: `sid()`, `school()`, `tenant_boot()` (buang `/KOD` daripada `REQUEST_URI`), `tenant_rewrite_html()` (awalan pautan `href/src/action/data-*="/…"` kecuali `/assets/`), `with_school()`, `school_url()`, `platform_url()`.
- Subkunci HKDF: `'koku-multi/school/{id}'` dan `'koku-multi/platform'` — **tukar kepada `urustugas/…`** (pemasangan baharu, tiada data lama).
- Kuki: `koku_sesi` / `koku_platform` (+ `__Host-`/`__Secure-`); sesi terikat `school_id`; tamat 30 min tidak aktif / 8 jam.
- CSP ketat dalam bootstrap: `default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; …` — tiada skrip/gaya sebaris.
- `PSET_DEFAULTS` dalam `tenant.php`: `nama_platform`, harga Pro (sen), percubaan, had Free (`free_murid`, `free_pengguna`) … → tukar `free_murid` kepada `free_guru` / `free_jawatan`.
- `SCHOOL_TABLES` + `school_wipe()` — senarai mesti dikemas kini dengan jadual UrusTugas, anak dahulu.

### B2. Skema (`database/schema.sql`, 46 jadual; `APP_SCHEMA 2.15-m10` dalam `app/install.php`)
- Corak setiap jadual sekolah: `school_id BIGINT UNSIGNED NOT NULL`, `UNIQUE KEY school_row (school_id,id)`, FK komposit `(school_id,x_id) → induk(school_id,id)`, `ON DELETE CASCADE` ke `schools`.
- Platform kekal: `schools`, `platform_admins`, `platform_log`, `platform_settings`, `rate_limits`, `health_log`, `consents`, `support_tickets`, `support_messages`, `payments`, `discounts`, `sponsors`, `sponsorships`, `ip_blocks`, `security_reports`, `audit_heads`.
- Sekolah kekal: `users`, `recovery_codes`, `auth_tokens`, `settings(school_id,name,value)`, `audit_logs`, `user_avatars`, `staff_notices*` (hebahan).
- Dibuang (koku): `students`, `units`, `memberships`, `membership_history`, `activities`, `attendance`, `achievements`, `pajsk`, `pajsk_assessments`, `reports`, `report_photos`, `student_access`, `student_extras`, `external_programs`, `unit_teachers`, `unit_logos`, `calendar_events`, `announcements`, `gallery_photos`, `consent_letters`, `agm_minutes`.
- `users.role ENUM('admin','guru','pembaca')` → tukar kepada `('admin','pk','head','teacher')` + lajur `teacher_id` (FK komposit ke `teachers`, unik dalam sekolah).
- `audit_logs`: `action VARCHAR(60)`, `entity VARCHAR(60)`, `entity_id`, tiada teks bebas → tambah `detail VARCHAR(255)` (rantai audit `audit_fields()` mesti turut merangkumnya) **atau** kekal `history`.
- `run_upgrade()` membawa langkah naik taraf koku lama (agm, PAJSK grandfather, students…) — **buang semua**, mulakan `APP_SCHEMA` baharu (cth. `1.0-m1`) kerana pemasangan bermula kosong.

### B3. Laluan & titik masuk (`public_html/index.php`)
Susunan penting: `NO_SESSION` untuk OAuth sandaran & callback bayaran → bootstrap → `/kemaskini-hook` → `app_status()` (pemasang/naik taraf) → `ip_guard()` → `security.txt` → tiada sekolah ⇒ `platform_main()` → `bayar_kembali` → `emergency_guard()` → `masuk_platform`/`imp_check()` → `subscription_gate()` → semakan halaman/peranan → `views.php`.
- Fail awam: `index.php`, `install.php`, `router.php`, `sihat.php`, `kalendar.php` (koku — buang), `ralat.html`.

### B4. Gandingan dengan modul koku (perlu disunting semasa Fasa 1)
Fail platform kebanyakannya bebas; yang merujuk jadual koku:
- `subscription.php:27` `free_count('murid')` → kira guru/jawatan.
- `platform.php:52-53, 320` statistik sekolah (murid, unit, aktiviti) → guru, jawatan, lantikan, status agihan.
- `tenant.php` `SCHOOL_TABLES`; `install.php` `run_upgrade()`; `addons.php` (addon PAJSK/Program Luar/Semakan PAJSK) → kosongkan senarai addon, kekal rangka; `pdf.php` (dokumen koku) → kekal enjin mPDF sahaja; `demo.php` (31 rujukan) → tulis semula.
- **Monolitik & khusus koku, tidak boleh disalin terus:** `public_html/index.php`, `app/views.php` (shell + nav + halaman log masuk/2FA/enroll), `app/actions.php` (`handle_auth()` log masuk/2FA/logout bercampur `handle_action()` modul), `app/modules.php`, `app/settings.php` (tema & `setting()` berguna; kunci lalai koku). Ekstrak bahagian auth/shell, buang bahagian modul.
- **Peranan berkod keras `'guru'`/`'pembaca'`** dalam `bootstrap.php` (`can_write()`), `hebahan.php` (penerima = `guru`), `users.php`, `admin_views.php`, `actions.php`, `views.php`, `demo.php` — semua perlu dipetakan kepada admin/pk/head/teacher.
- **Pertembungan nama:** koku sudah ada `app/agihan.php` (agihan *murid* ke unit), `app/surat.php` (surat perlantikan koku), `app/teacher_import.php` (import *pengguna* guru). Jangan guna nama ini untuk modul UrusTugas — cadangan: `tugas_auto.php`, `surat_lantikan.php`, `guru.php`.

### B5. Operasi
- `release/zip-files.txt` (1,001 baris senarai fail eksplisit) + `release/build-zip.sh` → `koku-multi-v<versi>-<tarikh>.zip`. Fail baharu yang tertinggal = laman rosak di hosting (pernah berlaku). **Wajib** kemas kini senarai ini.
- `.github/workflows/terbit.yml`: PHP 8.3, `composer install --no-dev`, lint, bina zip → Release `terkini` + manifest SHA-256 → webhook HMAC (`DEPLOY_HOOKS`, `DEPLOY_SECRET` = `UPDATE_SECRET`).
- `composer.json`: mPDF 8.2.6 (+ psr, setasign, paragonie, myclabs); `vendor/` 94 MB tidak dijejak git kecuali `vendor/.htaccess`. QR: `lib/php-qrcode`.
- Pemasang web menamakan semula `public_html/install.php` → `install.php.locked-*`; **pulihkan nama sebelum commit**.
- Ujian: Python stdlib (`urllib`) pada pemasangan MariaDB setempat; `tests/tenant_sql_audit.py` (regex `TABLES` + senarai `REVIEWED`) → kemas kini senarai jadual. Persekitaran ujian: handover koku-multi §4 (MariaDB soket `/tmp/km/m.sock`, port 33411, `php -S 127.0.0.1:8090`).

---

## C. Senarai semak Fasa 1 (diperoleh daripada nota ini)
1. Salin rangka koku-multi ke repo ini (tanpa `.env`, `storage/*`, `*.zip`, `generated_images/`, `vendor/` kecuali `.htaccess`); simpan kod UrusTugas lama di `legacy/` sehingga Fasa 3 siap.
2. Buang modul koku (B2 senarai dibuang, B4 fail koku) dan rujukannya dalam `index.php`, `views.php`, `actions.php`, `release/zip-files.txt`.
3. Jenama: nama platform, kuki, HKDF, `composer.json name`, `ASSET` workflow, nama zip, resit `KK…`.
4. `schema.sql` platform + `users`(peranan UrusTugas) + `settings` + `audit_logs`(+`detail`); `APP_SCHEMA` baharu; `run_upgrade()` kosong.
5. Shell sekolah minimum: log masuk, 2FA, profil, Pengguna & Audit, Tetapan, Langganan, Sokongan, Sekatan IP — dashboard kosong sementara.
6. Lulus: pemasang web pada DB kosong, `/platform/` log masuk + 2FA, cipta sekolah, log masuk sekolah (laluan & subdomain), `tenant_sql_audit.py` bersih, `php -l` semua fail.
