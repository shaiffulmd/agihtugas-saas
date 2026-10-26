# AgihTugas

MVP setempat pengurusan tugas guru, menggunakan gaya dan aset reka bentuk projek `koku-multi`: sidebar navy, aksen jingga dan fon dihos sendiri. Projek rujukan tidak diubah.

## Jalankan

PHP 8.2+ dengan PDO SQLite, mbstring, GD, fileinfo dan cURL (untuk klien integrasi/ujian). Tiada pemasangan pakej diperlukan.

```sh
php -S 127.0.0.1:8087 -t public public/router.php
```

Sediakan akaun pentadbir pertama dari terminal projek:

```sh
php bin/setup-admin.php
```

Buka http://127.0.0.1:8087/?page=setup, masukkan kod sekali guna yang dipaparkan, dan tetapkan nama, e-mel serta kata laluan sendiri. Tiada kata laluan lalai. Kod sah 30 minit; menjalankan arahan semula membatalkan kod terdahulu. Selepas akaun dicipta, log masuk dan tambah akaun PK, Guru Besar/Pengetua serta Guru melalui Akaun Pengguna.
 Pangkalan data dicipta dalam `storage/agihtugas.sqlite` pada lawatan pertama bersama 12 guru rekaan, 24 jawatan contoh dan 20 lantikan. Data kekal selepas pelayar ditutup. Untuk ruang kerja kosong, gunakan fail SQLite baharu dan padam data contoh melalui UI (lepaskan lantikan dahulu).

## Installer VPS dan cPanel

```sh
php bin/build-release.php
```

Menghasilkan dua ZIP bersih dalam dist/. Pakej cPanel mempunyai pemasang pelayar berkunci unik; pakej VPS mempunyai pemasang terminal serta templat Apache + PHP-FPM (lalai) dan Nginx. Pemasangan baharu bermula kosong tanpa data demo. Kedua-dua pakej menggunakan document root public_html, dengan agihtugas-private di sebelahnya. Panduan lengkap: [docs/PEMASANGAN.md](docs/PEMASANGAN.md). Jangan ekstrak pakej private ke public_html.

## Fungsi

- Tambah, sunting, cari dan padam guru; kapasiti individu 1–100 mata.
- Tambah, sunting dan cari jawatan empat bidang; pemberat 1–10.
- Lantik atau pindah penyandang; satu rekod jawatan mewakili satu tempat. Tambah rekod berasingan untuk jawatan yang memerlukan beberapa penyandang.
- Pengiraan beban, amaran melebihi kapasiti dan penapis jawatan kosong.
- Surat individu atau pukal; gunakan Cetak / Save as PDF pelayar.
- Profil sekolah, label sesi dan log aktiviti.

## Batas versi

UI kini mempunyai log masuk, akses mengikut peranan dan aliran kelulusan. Versi ini masih satu sekolah sahaja; tiada import fail Excel .xlsx, pemilih arkib mengikut sesi atau integrasi e-mel pemulihan kata laluan. Import jawatan menyokong CSV dan tampalan lajur Excel. Sejarah versi muktamad disimpan dan surat menggunakan salinan tetap versi muktamad terkini. Bilangan jawatan ialah 24 contoh, bukan katalog rasmi 143 jawatan.

Pelayan `php -S` ialah pratonton pembangunan. Untuk penggunaan sekolah pada pelayan sebenar, gunakan HTTPS, document root `public_html/` daripada pakej installer, simpan data di luar webroot dan sediakan sandaran. Di belakang proksi HTTPS, pastikan pelayan PHP menerima `HTTPS=on` supaya cookie sesi Secure diaktifkan.

## Semakan

```sh
php tests/integration.php
php tests/allocation.php
php tests/auth_setup.php
php tests/workflow.php
php tests/session_isolation.php
php tests/school_types.php
php tests/branding.php
php tests/installer.php
php -l public/index.php
php -l app/bootstrap.php
```

Fon disertakan bersama lesen asal dalam `public/assets/fonts/OFL.txt`.

## API tersendiri & integrasi koku-multi

API JSON versi `/api/v1` menggunakan Bearer API key. Data API dan UI dikongsi dalam SQLite yang sama. Dokumentasi tersedia di `/api-docs.html`, kontrak mesin di `/openapi.json`, dan panduan penuh dalam [docs/API.md](docs/API.md). Key dicipta melalui terminal, disimpan sebagai hash dan boleh dibatalkan.

```sh
php bin/api-key.php create 'koku-multi' write
php bin/api-key.php list
php tests/api.php
```

Klien PHP dan contoh sambungan koku-multi berada dalam `integrations/koku-multi/`. POST `/teachers/sync` menggunakan external_id unik untuk elak pendua. Klien belum dipasang dalam projek koku-multi dan tiada sync automatik diaktifkan. API key melindungi API; sesi dan peranan melindungi UI. API tidak mempunyai kuasa meluluskan atau memuktamadkan agihan. Semua perubahan data melalui API hanya dibenarkan ketika status Draf. Backend AgihTugas ini satu sekolah sahaja; rujuk panduan integrasi sebelum menghubungkan koku-multi berbilang sekolah.

## Git

Repositori ini menyimpan kod, dokumentasi, ujian, kontrak API dan aset fon bersama lesennya. Data runtime dalam `storage/` (termasuk SQLite, hash API key dan screenshot), fail `.env`, konfigurasi integrasi setempat, log serta arkib diabaikan melalui `.gitignore`.

Sebelum commit, semak `git status --short` dan `git diff --cached`. Jangan masukkan API key sebenar dalam kod atau dokumentasi. Klon baharu mencipta pangkalan data contoh pada lawatan pertama; API key perlu dicipta semula pada pelayan berkenaan. Pangkalan data dan konfigurasi rahsia disandarkan secara berasingan daripada Git.

## Agihan automatik & jawatan pukal

Menu **Pusat Syarat** mengurus had tugas, kapasiti, tugas berat, had setiap bidang, pengecualian guru dan syarat khusus jawatan (guru layak, kepakaran dan penyandang tetap). Menu **Agihan Automatik** menjana pratonton dahulu; hanya butang simpan mengubah lantikan. Mod lalai mengisi jawatan kosong. Mod susun semula boleh memindahkan atau melepaskan lantikan apabila syarat tidak dapat dipenuhi. Pratonton tamat selepas 30 minit dan ditolak jika data atau syarat telah berubah.

Menu **Tambah Jawatan Pukal** boleh dicapai dari Jawatan & Pemberat. Muat turun templat CSV, tampal lajur Excel atau muat naik CSV, semak pratonton, kemudian tambah. Bilangan membina beberapa tempat lantikan berasingan. Semua baris mesti sah, dan nama/bidang berulang ditolak.

Panduan dan batas algoritma: [docs/AGIHAN_AUTO.md](docs/AGIHAN_AUTO.md). API menyediakan rules, preview, apply dan bulk untuk integrasi koku-multi. Agihan menggunakan heuristik deterministik, bukan AI atau pengoptimuman global. Lantikan manual tidak disekat oleh syarat auto.

## Semakan & kelulusan

Aliran: **Draf → Semakan → Lulus → Muktamad**. Pentadbir/PK menyediakan draf dan menghantar; Guru Besar/Pengetua meluluskan kemudian memuktamadkan. Penghantar tidak boleh meluluskan sendiri. Pentadbir/Guru Besar/Pengetua boleh mengembalikan ke draf dengan sebab. Penyuntingan data, tetapan sekolah, syarat, import dan auto-agihan dikunci selepas dihantar, termasuk melalui API.

Guru hanya boleh melihat surat/tugas sendiri daripada versi muktamad terkini. Semasa draf pindaan disediakan, versi muktamad terdahulu kekal tersedia. Panduan penuh: [docs/AKSES_KELULUSAN.md](docs/AKSES_KELULUSAN.md).

## SK dan SMK

Pilih **Jenis sekolah: SK atau SMK** di Tetapan Sekolah sebelum menyediakan akaun ketua sekolah dan menghantar agihan. SK menggunakan gelaran Guru Besar, SMK menggunakan Pengetua; peranan dalaman `head` dan kawalan kelulusan kekal sama. Jawatan, pemberat, kapasiti dan syarat boleh disesuaikan untuk kedua-dua jenis sekolah. Menukar jenis tidak menggantikan atau memadam katalog/data sekolah.

API school dan snapshot baharu menyertakan `school_type` serta `principal_title`. Surat menggunakan jenis sekolah dalam snapshot muktamad, termasuk fallback SK untuk snapshot lama yang belum mempunyai medan jenis sekolah. Pemasangan sedia ada bermula dengan pilihan SK; pentadbir SMK perlu memilih SMK. Draf yang sedang menunggu kelulusan ketika naik taraf mungkin perlu dikembalikan dan dihantar semula kerana profil snapshot bertambah.

## Logo sekolah & Jata Negara

Pentadbir boleh memuat naik logo (PNG/JPG/WebP, maksimum 2 MB) di Tetapan Sekolah. Imej disahkan, diproses semula kepada PNG dan dikecilkan secara berkadar kepada maksimum 640px. SVG tidak diterima. Logo dipaparkan dalam sidebar dan kepala surat mengikut pilihan gaya.

Jata Negara daripada sumber rasmi Akademi Kenegaraan Malaysia disertakan sebagai aset bersama `public/assets/shared/jata-negara.png`, bersama catatan sumber. Semua pemasangan boleh menggunakannya tanpa upload semula; pautan muat turun tersedia pada halaman tetapan. Pilihan kepala surat: jata + logo sekolah, logo sahaja atau jata sahaja.

Imej runtime disimpan sebagai blob ber-ID hash. Snapshot muktamad menyimpan rujukan versi imej; mengganti/membuang logo draf tidak memadam imej pada surat terdahulu. Imej lama dikekalkan untuk dokumen yang telah diterbitkan. Perubahan identiti sekolah hanya dibenarkan oleh Pentadbir dalam status Draf. API school/published/letters mempunyai metadata branding, dan GET `/api/v1/assets/{id}` mengembalikan PNG dengan Bearer key.
