# Kemas kini automatik daripada GitHub

Setiap kali anda **push ke `main`**, GitHub Actions membina zip keluaran dan menerbitkannya ke Release `terkini`.
Selepas itu laman anda dimaklumkan melalui webhook, lalu laman memasang versi baharu itu sendiri.
Kaedah ini berfungsi di **shared hosting (cPanel)** dan **VPS**. Pelayan tidak memerlukan SSH, git atau Composer.

```
push ke main ──► GitHub Actions: composer install → semak sintaks → bina zip → Release "terkini" (+ manifest.json)
                                     │
                                     └──► POST https://laman/kemaskini-hook (HMAC)
laman: muat turun zip (token baca sahaja) → sahkan SHA-256 → sandar fail lama → salin → upgrade DB → OPcache → asas integriti
```

Jika webhook tidak sampai (cth. disekat firewall atau Cloudflare), **cron** atau **butang di panel** akan memasang versi yang sama.

## Persediaan sekali sahaja

### 0. Repo GitHub UrusTugas
Cipta repo **peribadi** baharu khas UrusTugas (cth. `pemilik/urustugas`) dan push cawangan `main`. UrusTugas berdiri sendiri: jangan gunakan remote atau rahsia repo AgihTugas/koku-multi, dan jangan kongsi `UPDATE_SECRET` antara sistem. Workflow `.github/workflows/terbit.yml` menerbitkan `urustugas.zip` + `manifest.json` ke Release `terkini`.

### 1. Token GitHub (baca sahaja)
GitHub → Settings → Developer settings → **Fine-grained personal access tokens** → *Generate new token*:
- **Repository access:** *Only select repositories* → pilih repo ini.
- **Permissions → Repository → Contents: Read-only.** Tiada kebenaran lain diperlukan.
- Tempoh tamat: ikut polisi anda, cth. 1 tahun. Tetapkan peringatan untuk menukarnya sebelum tamat.

### 2. `.env` di setiap laman
```
UPDATE_REPO=pemilik/urustugas   # repo GitHub UrusTugas sendiri — BUKAN repo AgihTugas atau koku-multi
UPDATE_TOKEN=github_pat_xxxxxxxx
UPDATE_SECRET=<rentetan rawak panjang, cth. hasil: openssl rand -hex 32>
# UPDATE_AUTO=1   # pilihan: bin/cron.php turut menyemak versi baharu
```

### 3. Rahsia repo GitHub
Repo → Settings → Secrets and variables → **Actions** → *New repository secret*:
- `DEPLOY_SECRET`: **sama** dengan `UPDATE_SECRET` di laman.
- `DEPLOY_HOOKS`: URL webhook, cth. `https://urustugas.my/kemaskini-hook`. Untuk beberapa laman, pisahkan URL dengan ruang (semua laman perlu `UPDATE_SECRET` yang sama).

### 4. Kebenaran fail
PHP mesti boleh menulis folder aplikasi kerana ia yang menyalin fail baharu.
- **cPanel/shared hosting:** biasanya sudah betul, kerana PHP berjalan sebagai pengguna akaun.
- **VPS:** pastikan pemilik fail ialah pengguna PHP-FPM, cth.:
  ```bash
  sudo chown -R www-data:www-data /var/www/urustugas
  ```
  Panel **Kemas Kini Sistem** menunjukkan "Fail boleh ditulis oleh PHP: Ya / Tidak".

### 5. Cron sandaran (disyorkan)
```
*/15 * * * *  php /laluan/ke/urustugas/bin/update.php kemaskini >/dev/null 2>&1
```
Arahan ini hanya memuat turun jika terdapat versi baharu, jadi selamat dijalankan selalu.

## Penggunaan
- **Automatik:** push ke `main` dan tunggu ±2–4 minit. Status boleh dilihat di tab *Actions* GitHub dan di panel **Kemas Kini Sistem → Sejarah kemas kini**.
- **Panel:** Panel Platform → Sistem → **Kemas Kini Sistem**. Ada tiga butang: *Semak versi terkini*, *Kemas kini sekarang* dan *Rollback*.
- **CLI:**
  ```bash
  php bin/update.php semak
  php bin/update.php kemaskini          # --paksa untuk pasang semula versi sama
  php bin/update.php rollback
  ```

## Keselamatan
- Webhook hanya diterima dengan tandatangan **HMAC-SHA256** yang sah (`X-UrusTugas-Signature`). Badan permintaan tidak dipercayai: laman sentiasa memuat turun versi terkini sendiri dari GitHub.
- Zip disahkan dengan **SHA-256** daripada `manifest.json`. Setiap laluan dalam zip disemak (tiada `..` atau laluan mutlak).
- `.env`, `storage/`, `tmp/` dan `.user.ini` **tidak pernah ditindih** oleh kemas kini.
- Fail yang akan ditindih disandarkan ke `storage/update/sandaran-*.zip` (3 terakhir disimpan). Jika penyalinan gagal di tengah jalan, fail lama dipulihkan secara automatik.
- Selepas kemas kini, asas **integriti fail** diterima semula secara automatik supaya tiada amaran palsu.
- Token hanya untuk **membaca** repo ini. Jika laman diceroboh, token itu tidak boleh mengubah kod di GitHub.
- **Rollback** memulihkan fail sahaja. Perubahan skema pangkalan data kekal, kerana migrasi sistem ini serasi ke belakang.

## Guna semula untuk sistem lain
1. Salin `app/updater.php`, `bin/update.php` dan `.github/workflows/terbit.yml`.
2. Dalam `terbit.yml`, tukar `ASSET` dan langkah *Bina zip keluaran* (skrip bina zip sistem itu).
3. Dalam `public_html/index.php`, sebelum semakan pemasangan:
   ```php
   if(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)==='/kemaskini-hook'){ require_once dirname(__DIR__).'/app/updater.php'; upd_webhook(); }
   ```
4. Paparkan panel dengan `upd_panel_html($urlWebhook)` dan kendalikan tindakan `upd_*` dengan `upd_action($tindakan,$oleh)`.
5. Modul ini hanya memerlukan `env()`. Fungsi `run_upgrade()`, `integrity_accept()` dan `platform_log()`/`audit()` dipanggil jika wujud.

Ujian setempat (tanpa rangkaian): `python3 tests/updater_local.py`.
