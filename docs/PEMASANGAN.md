# Pemasangan UrusTugas (edisi berbilang sekolah)

Satu pemasangan melayan banyak sekolah SK & SMK. Setiap sekolah ialah penyewa (`school_id`) dalam satu pangkalan data MariaDB/MySQL, dicapai melalui `https://DOMAIN/KOD/` (kod KPM, cth. `/CEA1234/`) atau `https://kod.DOMAIN/`. UrusTugas berdiri sendiri — ia tidak memerlukan koku-multi atau sistem lain.

## Keperluan

- PHP 8.2+ dengan `pdo_mysql`, `sodium`, `mbstring`, `zip`, `dom`, `gd`; `curl` disyorkan (sandaran Google Drive, gerbang bayaran, kemas kini GitHub).
- MariaDB 10.6+ atau MySQL 8 — satu pangkalan data kosong dan seorang pengguna dengan semua keistimewaan ke atasnya sahaja.
- HTTPS. Untuk URL subdomain (`kod.DOMAIN`): DNS wildcard `*.DOMAIN` dan sijil SSL wildcard.
- Document root domain = folder `public_html/` dalam folder aplikasi **sahaja**. `app/`, `storage/`, `.env` dan `vendor/` mesti berada di luar web.
- Composer tidak diperlukan pada pelayan — zip keluaran sudah mengandungi `vendor/` (mPDF).

## cPanel (tanpa terminal)

1. Cipta pangkalan data & pengguna MySQL (cPanel → MySQL Databases), beri **ALL PRIVILEGES** kepada pengguna itu.
2. Muat naik `urustugas-v<versi>-<tarikh>.zip` ke `/home/AKAUN/` dan ekstrak sehingga terhasil `/home/AKAUN/urustugas/`.
3. cPanel → Domains: tetapkan Document Root domain (atau subdomain) kepada `urustugas/public_html`.
   Untuk mod subdomain, tambah juga subdomain wildcard `*.DOMAIN` dengan document root yang sama.
4. Pilih PHP 8.2+ (Select PHP Version) dan aktifkan sambungan di atas.
5. Buka `https://DOMAIN/install.php`. Pemasang menyemak keperluan, menulis `.env` (APP_KEY dijana), memasang skema, mencipta **Pentadbir Platform** dan mengunci pemasangan (`storage/installed.lock`; `install.php` dinamakan semula).
   - **Pasang demo** mencipta dua sekolah demo (DEM0001 SMK, DEM0002 SK) dengan data contoh — untuk laman demo sahaja (lihat [PANDUAN-DEMO.md](PANDUAN-DEMO.md)).
6. Log masuk `/platform/`, daftar 2FA (wajib), kemudian tambah sekolah di panel atau buka pendaftaran awam.

Folder 755, fail 644 (zip sudah ditetapkan begitu). `storage/` mesti boleh ditulis oleh PHP. Jangan gunakan 777.

## VPS / CLI

```sh
unzip urustugas-v1.0-*.zip -d /var/www && cd /var/www/urustugas
cp .env.example .env && php bin/key.php      # salin output ke APP_KEY; isi DB_*, PLATFORM_DOMAIN, TENANT_PRIMARY
php bin/install.php                           # semak keperluan, pasang skema, cipta Pentadbir Platform
php bin/school.php create CEA1234 "SMK Contoh" admin@sekolah.edu.my   # kata laluan sementara dipaparkan sekali
```

Contoh vhost Apache: `DocumentRoot /var/www/urustugas/public_html`, `AllowOverride All` (guna `public_html/.htaccess`). Nginx: semua permintaan bukan fail → `index.php`, dan nafikan fail bertitik. Pelayan pembangunan: `php -S 127.0.0.1:8091 -t public_html public_html/router.php`.

## Tetapan `.env` penting

| Kunci | Maksud |
|---|---|
| `APP_KEY` | Kunci induk (HKDF → kunci sekolah, platform, API, audit). **Sandarkan**; tanpanya medan tersulit & sandaran tidak boleh dibaca. |
| `PLATFORM_DOMAIN` | Domain induk tanpa `https://`. |
| `TENANT_PRIMARY` | `path` (`/KOD/`) atau `subdomain` (`kod.DOMAIN`). Bentuk lain dilencongkan. Boleh diubah di Panel Platform → Subdomain Sekolah. |
| `REQUIRE_2FA` | `1` = 2FA wajib untuk pentadbir. |
| `SESSION_SECURE` | `1` di HTTPS. |
| `DEMO_MODE` | `1` hanya untuk laman demo awam (data direset harian, tindakan berisiko disekat). |
| `UPDATE_*` | Kemas kini automatik GitHub — [KEMASKINI-GITHUB.md](KEMASKINI-GITHUB.md). |

## Cron (pilihan tetapi disyorkan)

Tanpa cron, kerja harian dicetus apabila panel platform dibuka atau `sihat.php?k=` dipanggil. Dengan cron:

```text
0 4 * * *     php /home/AKAUN/urustugas/bin/cron.php >/dev/null 2>&1          # langganan, arkib, pembersihan
30 2 * * *    php /home/AKAUN/urustugas/bin/backup.php >/dev/null 2>&1        # sandaran tersulit ke Google Drive
*/15 * * * *  php /home/AKAUN/urustugas/bin/update.php kemaskini >/dev/null 2>&1   # jika kemas kini automatik dihidupkan
0 3 * * *     php /home/AKAUN/urustugas/bin/demo-reset.php >/dev/null 2>&1    # laman demo sahaja
```

Pemantau uptime: `https://DOMAIN/sihat.php` (asas) atau `sihat.php?k=KUNCI` (penuh; kunci di Integriti & Status).

## Semakan selepas pemasangan

- `/platform/` meminta 2FA; `/KOD/` sekolah baharu memaparkan log masuk dengan nama sekolah.
- Pentadbir Utama sekolah log masuk dengan kata laluan sementara → wajib tukar → wajib 2FA.
- Tambah seorang guru & jawatan, lantik, hantar → luluskan → muktamadkan, buka Surat Lantikan dan PDF.
- `https://DOMAIN/.env`, `/app/bootstrap.php`, `/storage/installed.lock` mesti 403/404.
- Panel Platform → Integriti & Status: semua hijau.

## Naik taraf & pemulihan

- **Kemas kini**: butang di Panel Platform → Kemas Kini Sistem, `php bin/update.php kemaskini`, atau muat naik zip baharu (tindih fail, kekalkan `.env` & `storage/`) kemudian `php bin/upgrade.php`. Langkah skema bersifat tambahan dan idempoten.
- **Rollback fail**: `php bin/update.php rollback`.
- **Sandaran**: Panel Platform → Sandaran (fail `.kbak` tersulit kata laluan). Pulih: `php bin/restore.php FAIL.kbak` ke pangkalan data kosong dengan `APP_KEY` yang sama.
- **Terkunci keluar**: `php bin/security.php status|unblock IP|ip-off`, `php bin/user.php platform password|reset-2fa`, `php bin/user.php KOD password|reset-2fa`.

## Pindah daripada AgihTugas / UrusTugas satu sekolah (SQLite)

```sh
php bin/import-agihtugas.php --db=/laluan/agihtugas.sqlite --code=CEA1234 --dry-run
php bin/import-agihtugas.php --db=/laluan/agihtugas.sqlite --code=CEA1234 [--name="…"] [--type=SK|SMK] [--state=Pahang] [--admin-email=e-mel]
```

Fail SQLite dibaca sahaja; sekolah baharu dicipta dengan guru, jawatan, lantikan, akaun (kata laluan lama), syarat, versi muktamad, logo dan sejarah. Kunci API lama tidak dibawa — cipta semula di API & Integrasi.
