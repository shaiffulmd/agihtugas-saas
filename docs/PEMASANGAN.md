# Pemasangan VPS dan cPanel

Satu pemasangan mewakili satu sekolah SK atau SMK. SQLite digunakan; tiada MySQL, Composer atau Node diperlukan pada pelayan. Pemasang mencipta ruang kerja kosong dan kod pentadbir sekali guna (30 minit). Ia tidak menetapkan kata laluan dan tidak menindih pangkalan data sedia ada.

## Keperluan

- PHP 8.2+ bersama pdo_sqlite, mbstring, gd, fileinfo dan session. cURL diperlukan jika menggunakan klien integrasi koku-multi.
- HTTPS, domain/subdomain khusus pada akar domain. Pemasangan di URL subfolder seperti `/agihtugas` belum disokong kerana laluan aset/API menggunakan `/`.
- PHP mesti boleh menulis `storage/` dan `config/`. Kod aplikasi tidak memerlukan akses tulis. Jangan gunakan chmod 777.
- Apache 2.4 dengan mod_rewrite/AllowOverride untuk cPanel, atau Nginx + PHP-FPM untuk VPS.
- Had PHP `upload_max_filesize` sekurang-kurangnya 2M dan `post_max_size` sekurang-kurangnya 3M. Maksimum imej aplikasi tetap 2 MB.

## Bina kedua-dua pakej

Pada komputer pembangunan dengan PHP zip:

```sh
php bin/build-release.php
```

Hasil: `dist/agihtugas-cpanel.zip` dan `dist/agihtugas-vps.zip`. ZIP hanya mengandungi kod/aset yang disenaraikan; DB, logo sekolah runtime, akaun, API key, config setempat dan fail ujian tidak dibawa bersama. Setiap ZIP cPanel mempunyai kunci pemasangan rawak tersendiri. Jangan kongsi ZIP yang sudah diberi kepada pemilik sekolah lain; bina pakej baharu untuk mereka.

```sh
php bin/build-release.php --target=cpanel --output=/tmp/pakej-sekolah-b
```

Pembina enggan menindih ZIP sedia ada. Untuk bina semula, gunakan folder output baharu atau alihkan arkib lama dahulu. Simpan pakej cPanel seperti rahsia sehingga pemasangan selesai.

## cPanel: File Manager, tanpa terminal

Pakej menyediakan dua folder bersebelahan:

```text
/home/USERNAME/
  INSTALL.txt                      ← kunci unik, bukan fail awam
  agihtugas-private/
    app/ installer/ bin/ public/
    config/                        ← konfigurasi peribadi
    storage/                       ← SQLite, logo dan sejarah
  public_html/                     ← document root domain khusus
    index.php install.php .htaccess
    assets/ api-docs.html openapi.json
```

1. Sandarkan laman sedia ada. Gunakan akaun/domain khusus; jangan gabungkan atau tindih fail laman lain. Pilih PHP 8.2+ dan aktifkan modul di atas melalui panel hosting. Nama menu modul berbeza mengikut penyedia; minta hosting mengaktifkan pdo_sqlite jika tiada pilihan.
2. Muat naik ZIP melalui File Manager ke `/home/USERNAME`, **bukan** dalam public_html. Ekstrak di situ. Pastikan fail tersembunyi `.htaccess` turut disalin. Kod dan storage mesti berada di luar semua document root yang boleh dicapai umum.
3. Kekalkan domain pada `/home/USERNAME/public_html`. Jika menggunakan subdomain pada laluan seperti `/home/USERNAME/tapak/public_html`, ekstrak dua folder pakej dalam `/home/USERNAME/tapak` supaya public_html dan agihtugas-private tetap bersebelahan. Document root mesti menunjuk kepada public_html tersebut. Jangan dedahkan folder private melalui domain/alias lain.
4. Aktifkan sijil TLS/AutoSSL dan pengalihan HTTPS melalui cPanel. Buka `https://DOMAIN/install.php`. Pemasang memeriksa PHP web (yang mungkin berbeza daripada PHP CLI), akses tulis, pemisahan webroot dan HTTPS.
5. Masukkan kunci daripada INSTALL.txt. Pemasang menyediakan pangkalan data kosong dan memaparkan kod persediaan pentadbir sekali sahaja.
6. Klik pautan persediaan akaun. Masukkan kod dalam tempoh 30 minit dan cipta nama/e-mel/kata laluan sendiri. Log masuk dan pilih SK/SMK, profil sekolah serta logo di Tetapan Sekolah.
7. Pemasang dikunci sebaik konfigurasi ditulis; lawatan seterusnya memberi 404. Padam public_html/install.php, INSTALL.txt dan ZIP muat naik selepas selesai. Kunci peribadi dipadam oleh pemasang secara automatik.

Fail/folder mesti dimiliki pengguna akaun hosting supaya PHP boleh membacanya. Lazimnya folder 755 dan fail 644; ketatkan config/storage kepada 700 dan fail rahsia kepada 600 jika PHP berjalan sebagai pengguna akaun. Pemasang menulis DB/config dengan mod 600. Jika hosting menggunakan pengguna PHP berbeza, minta pentadbir menetapkan pemilik/kumpulan yang sesuai.

Jika kod akaun luput sebelum akaun pertama dicipta, jalankan `php bin/setup-admin.php` dari agihtugas-private menggunakan Terminal cPanel, atau minta penyedia hosting menjalankannya. Ia mengeluarkan kod baharu dan tidak mencipta kata laluan. Jangan padam DB untuk menetapkan semula kod.

Document root kekal **public_html** untuk kedua-dua pakej. Jangan arahkan domain ke `agihtugas-private/public`; folder public dalaman menyimpan sumber aplikasi dan aset rujukan sahaja. Skrip root VPS tidak digunakan dalam cPanel.

## VPS: Apache + PHP-FPM (lalai)

Pemasang menyokong Linux dengan PHP-FPM dan `runuser` (contohnya Debian/Ubuntu). Ia tidak memasang pakej OS, mengubah firewall, meminta sijil atau mengaktifkan/memuat semula Apache/Nginx secara automatik.

1. Gunakan Apache 2.4, PHP CLI, PHP-FPM dan modul yang diperlukan menggunakan pengurus pakej rasmi OS. Pastikan CLI dan FPM menggunakan versi/modul yang sama. Pilih pengguna worker PHP-FPM (lazimnya www-data) dan soket pool sebenar. Rujuk konfigurasi pool, bukan meneka nombor versi PHP.
2. Ekstrak ZIP dalam folder tapak khusus, contohnya `/var/www/agihtugas`. Susun aturnya:

```text
/var/www/agihtugas/
  public_html/                 ← document root Apache, termasuk untuk HTTPS
    index.php .htaccess assets/
  agihtugas-private/            ← bukan document root
    app/ bin/ config/ storage/ deploy/ public/
```

Jika document root semasa anda ialah `/home/USER/public_html`, kekalkan laluan itu dan letakkan `agihtugas-private` di `/home/USER/agihtugas-private`. Pemasang mengesan public_html di sebelah folder private; ia tidak memindahkan atau menukar document root pelayan secara automatik. Gunakan tapak khusus dan sandarkan fail sedia ada sebelum mengekstrak pakej.
3. Masuk ke `/var/www/agihtugas/agihtugas-private` (atau folder private di sebelah public_html semasa), kemudian jalankan (ubah domain, soket dan pengguna):

```sh
sudo bash deploy/install-vps.sh tugas.sekolah.edu.my /run/php/php8.3-fpm.sock www-data
```

Skrip menyemak keperluan, menyediakan pemilikan kod root dan runtime pengguna PHP, menjalankan pemasang, memaparkan kod akaun dan menghasilkan `deploy/apache.generated.conf`. Ia enggan berjalan pada pemasangan sedia ada. Laluan dengan ruang dan symlink tersuai tidak disokong oleh skrip ini; gunakan laluan biasa seperti `/var/www/agihtugas/agihtugas-private`.

4. Semak `deploy/apache.generated.conf`. Templat menggunakan `DocumentRoot .../public_html`, `mod_rewrite` untuk UI/API, dan `mod_proxy_fcgi` untuk menghantar index.php kepada soket PHP-FPM pilihan. Ia tidak menggunakan mod_php. Soket mesti membenarkan pengguna worker **Apache** menyambung; pemilik storage/config pula mestilah pengguna worker **PHP-FPM**. Kedua-dua pengguna mungkin berbeza. Tetapkan `listen.owner`, `listen.group` dan `listen.mode` dalam pool FPM mengikut pelayan anda jika perlu.

Contoh Debian/Ubuntu (semak domain dan soket dahulu):

```sh
sudo a2enmod proxy proxy_fcgi rewrite
sudo cp deploy/apache.generated.conf /etc/apache2/sites-available/agihtugas.conf
sudo a2ensite agihtugas.conf
sudo apachectl configtest
sudo systemctl reload apache2
```

Jangan tindih virtual host sedia ada. Templat memasukkan peraturan terus dalam VirtualHost dan menggunakan `AllowOverride None`; .htaccess tidak diperlukan untuk VPS ini. cPanel masih menggunakan .htaccess pakej cPanel dan tetapan PHP-FPM daripada panel hosting. Untuk RHEL/AlmaLinux, letakkan vhost dalam `/etc/httpd/conf.d/agihtugas.conf`, pastikan modul dimuatkan, gunakan `apachectl configtest` dan servis `httpd`; utiliti a2enmod/a2ensite adalah khusus Debian/Ubuntu. Jika SELinux aktif, tetapkan label runtime/socket yang sesuai mengikut polisi OS, tanpa mematikannya.

5. Pasang sijil TLS dan pengalihan HTTP→HTTPS menggunakan kaedah rasmi pelayan anda (contohnya Certbot dengan plugin Apache). Templat terjana hanya vhost HTTP untuk bootstrap sijil; **aktifkan TLS sebelum menggunakan aplikasi**. Pastikan vhost HTTPS turut mempunyai Directory, RewriteRule dan handler PHP-FPM yang sama. PHP akan menerima HTTPS daripada Apache apabila TLS ditamatkan terus pada Apache. Jangan masukkan kata laluan melalui HTTP.
6. Jika kod 30 minit telah luput ketika menyiapkan TLS, jana semula:

```sh
sudo -u www-data php bin/setup-admin.php
```

Buka `https://DOMAIN/?page=setup` dan cipta akaun sendiri. Pilih jenis sekolah di Tetapan Sekolah.

Jika Nginx diperlukan pada pelayan lain, nyatakan argumen keempat:

```sh
sudo bash deploy/install-vps.sh tugas.sekolah.edu.my /run/php/php8.3-fpm.sock www-data nginx
```

Ini menghasilkan `deploy/nginx.generated.conf`. Aktifkan mengikut susun atur Nginx OS, semak dengan `nginx -t` dan sediakan TLS. Mengabaikan argumen keempat memilih **Apache**; argumen `apache` juga boleh dinyatakan secara jelas.

Pemasangan manual: kekalkan document root ke public_html dan gunakan peraturan Apache/Nginx yang disertakan. Jalankan pemasang dari agihtugas-private sebagai pengguna PHP; CLI mengesan public_html di sebelahnya. Tetapkan pemilik config/storage kepada pengguna PHP. `php bin/install.php --check` tidak mencipta DB. Untuk menyatakan laluan secara jelas, tambah `--webroot=/home/USERNAME/public_html`.

## Semakan selepas pemasangan

- Buka domain melalui HTTPS, log masuk dan pastikan tiada guru contoh.
- Pilih SK/SMK, tambah seorang guru/jawatan, muat naik logo dan semak surat.
- `GET /api/v1/summary` mesti sampai ke API (401 tanpa key adalah dijangka). Jika 404 daripada Apache, semak mod_rewrite dan .htaccess. Jana key menggunakan bin/api-key.php sebagai pengguna yang mempunyai akses DB.
- `/app/database.php`, `/config/local.php`, `/storage/agihtugas.sqlite` dan `/.git/config` tidak boleh mendedahkan fail dalaman. Dalam pakej cPanel, folder tersebut memang tiada dalam document root.
- Di belakang reverse proxy, konfigurasikan HTTPS pada PHP melalui proksi yang dipercayai. Aplikasi tidak mempercayai X-Forwarded-Proto daripada klien secara automatik.

## Sandaran dan kemas kini

Sandarkan config/local.php dan DB SQLite yang mengandungi akaun, key hash, logo serta versi surat. Gunakan SQLite backup API/`.backup` atau hentikan penulisan semasa mengambil salinan; jangan menyalin DB aktif secara mentah tanpa mengambil kira jurnal. Simpan sandaran di luar webroot dan uji pemulihan.

Pemasang ini untuk pemasangan baharu. Untuk naik taraf, sandarkan dahulu, aktifkan penyelenggaraan dan ganti kod/aset sahaja. Kekalkan config/local.php dan keseluruhan storage. Migrasi skema aplikasi bersifat tambahan. Dalam kedua-dua pakej, salin juga public/index.php aplikasi dalaman dan aset awam yang baharu; kekalkan wrapper public_html/index.php dengan laluan private yang betul. Jangan muat naik kunci pemasang baharu pada pemasangan aktif.

Rujukan rasmi: [Apache mod_proxy_fcgi](https://httpd.apache.org/docs/2.4/mod/mod_proxy_fcgi.html), [cPanel Document Root](https://docs.cpanel.net/knowledge-base/cpanel-product/how-domains-serve-content/), [domain cPanel](https://docs.cpanel.net/cpanel/domains/domains/manage-the-domain/110/), [PHP PDO SQLite](https://www.php.net/manual/en/ref.pdo-sqlite.php).
