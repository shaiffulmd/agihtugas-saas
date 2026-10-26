# Panduan pasang demo UrusTugas (cPanel)

Demo ini untuk diberi kepada orang lain menguji. Semua data adalah rekaan dan **dipadam serta dijana semula setiap hari**. Jangan guna pangkalan data sekolah sebenar — pasang demo pada subdomain dan pangkalan data **khas**.

## 1. Subdomain
cPanel → Domains (atau Subdomains) → cipta cth. `demo.domainanda.my`. Tetapkan *Document Root* kepada `urustugasdemo/public_html` (folder `app/`, `vendor/`, `storage/` mesti berada **di luar** web root).

## 2. Muat naik
cPanel → File Manager → folder `urustugasdemo/` (cipta jika tiada) → muat naik `urustugas-v*.zip` → klik kanan → *Extract*. Struktur: `urustugasdemo/app`, `urustugasdemo/public_html`, `urustugasdemo/vendor` …

## 3. Pangkalan data (khas untuk demo)
cPanel → MySQL Databases: cipta pangkalan data baharu (cth. `akaun_urustugasdemo`) dan pengguna baharu, kemudian *Add User To Database* → **ALL PRIVILEGES**.

## 4. SSL
cPanel → SSL/TLS Status → *Run AutoSSL* untuk subdomain tersebut.

## 5. Pasang
Buka `https://demo.domainanda.my` (terus ke `/install.php`). Isi butiran pangkalan data, biarkan bahagian Pentadbir Platform kosong, kemudian klik **Pasang demo**. Pemasang mencipta:

- Panel platform `/platform/`: `platform@demo.test` / `Demo-Platform-2026` (2FA dimatikan dalam demo).
- **DEM0001** (SMK, ketua: Pengetua) dan **DEM0002** (SK, ketua: Guru Besar), setiap satu dengan 12 guru, 24 jawatan, 20 lantikan, 3 syarat jawatan, satu hebahan dan **versi muktamad #1** (surat lantikan & Tugas Saya terus boleh dilihat) yang telah dibuka semula sebagai **draf pindaan** supaya perancangan boleh disunting.
- Halaman utama memaparkan bahagian **Demo tanpa daftar** dengan pautan ke kedua-dua sekolah.

## 6. Akaun penguji
Dipaparkan di halaman log masuk setiap sekolah (klik untuk isi automatik):

| Peranan | E-mel | Kata laluan | Cuba |
|---|---|---|---|
| Pentadbir | `admin@demo.test` | `Demo-Admin-2026` | Semua halaman, Profil Sekolah & Surat, tema |
| Penolong Kanan | `pk@demo.test` | `Demo-PK-2026` | Guru, jawatan, agihan, auto-agihan, hantar untuk semakan |
| Guru Besar / Pengetua | `ketua@demo.test` | `Demo-Ketua-2026` | Luluskan & muktamadkan, surat lantikan |
| Guru | `guru@demo.test` | `Demo-Guru-2026` | Tugas Saya & cetak surat |

Cadangan aliran ujian: PK → *Agihan Automatik* (isi jawatan kosong) → *Semakan & Kelulusan* → hantar; Ketua → luluskan → muktamadkan; Guru → *Tugas Saya*.

## Reset harian
Automatik: lawatan pertama ke setiap sekolah demo selepas tengah malam menjana semula datanya (beberapa saat untuk lawatan itu sahaja). Tiada cron diperlukan.

Pilihan (masa tetap, cth. 3:00 pagi): cPanel → Cron Jobs → `0 3 * * *  php /home/NAMAAKAUN/urustugasdemo/bin/demo-reset.php >/dev/null 2>&1`

Reset segera: `php bin/school.php demo-reset DEM0001` (atau `--all`), atau padam `storage/demo-reset-DEM0001.stamp` kemudian buka laman sekolah itu.

## Disekat dalam demo
- Tukar kata laluan, e-mel dan 2FA akaun demo
- Urus pengguna (tambah, ubah, set semula, lulus pendaftaran)
- Tetapan SMTP dan penghantaran e-mel; sandaran Google Drive
- Semua muat naik fail (logo, avatar, CSV)
- Cipta kunci API

Semua yang lain boleh dicuba: guru, jawatan, tambah pukal melalui tampal teks, agihan manual & automatik, Pusat Syarat, aliran kelulusan, surat & PDF, hebahan, tema.

## Matikan mod demo
Mod demo dikawal oleh baris `DEMO_MODE=1` dalam `.env`. Pemasangan sebenar menggunakan butang **Pasang** biasa dan tiada baris ini. `bin/demo-reset.php` menolak untuk berjalan tanpa `DEMO_MODE=1`, dan hanya sekolah bertanda demo yang direset — sekolah sebenar dalam pemasangan demo tidak disentuh.
