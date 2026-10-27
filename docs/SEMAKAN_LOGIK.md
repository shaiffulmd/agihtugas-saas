# Semakan logik UrusTugas — 28 Okt 2026 (versi 1.11)

Semakan menyeluruh selepas 1.11: semua ujian dijalankan semula, kemudian logik enjin (indeks beban, agihan, aliran kelulusan, demo) disemak. Senarai cadangan di bawah **belum dilaksanakan**. Pilih yang hendak dibuat.

## 1. Keputusan ujian

Dijalankan pada pemasangan ujian tempatan (MariaDB + `php -S`, DEMO_MODE=1).

| Kumpulan | Suite | Keputusan |
|---|---|---|
| statik | php -l, audit SQL `school_id`, simbol tidak ditakrif | bersih |
| demo | public_demo | 27 lulus |
| | multi_tenant_integration | 57 lulus |
| | engine_integration | 98 lulus |
| | plan_integration | 33 lulus |
| | allocation_engine | 45 lulus |
| | load_index | 28 lulus |
| | letter_format | 14 lulus |
| | subjects | 26 lulus |
| | workflow_flags | 11 lulus |
| | roles | 18 lulus |
| | schema_engine | 53 lulus |
| | import_agihtugas | 27 lulus |
| | integration_client | 21 lulus |

**Jumlah: 458 semakan, 0 gagal.** Kumpulan `tempatan` (2FA, subdomain, pembayaran) dan `baharu` (pemasang web) tidak dijalankan kerana memerlukan pemasangan berasingan.

## 2. Sudah dibetulkan sebelum semakan ini

- **1.11:** Semakan & Kelulusan dan seed demo menilai beban ikut indeks beban, selaras dengan Dashboard. Sebelum ini Pengetua melihat 2 guru terbeban tetapi PK hanya 1.
- **Ujian import:** ujian menyemak masa MYT yang tepat. Sebelum ini ia menyemak tarikh hari ini, jadi hanya lulus pada 26 Okt.

## 3. Dapatan

### A. Pepijat (disahkan dengan ujian)

**A1. Guru ditanda yang dipadam kekal "belum selesai"** — ✅ dibetulkan dalam 1.12

Langkah ulang (disahkan pada demo):

1. Pengetua kembalikan draf dengan guru X ditanda.
2. PK lepaskan semua tugas X, kemudian padam rekod X (cth. guru bertukar sekolah).
3. Hasilnya:
   - `workflow_flagged()` masih memulangkan X.
   - Jalur status kekal "N guru ditanda".
   - Butang *Tanda selesai* tidak dipaparkan untuk X, kerana `return_notice()` melangkau guru yang tiada.
   - PK tidak dapat mencapai "semua guru ditanda selesai".

Cadangan:

- Dalam `workflow_return_info()`, tapis `flags` kepada guru yang masih wujud.
- Atau, semasa `teacher_delete()`, keluarkan ID guru itu daripada `return_info.flags`.

Tambah satu semakan dalam `tests/workflow_flags.php`.

### B. Ketidakselarasan logik (reka bentuk)

**B1. Agihan automatik masih menimbang dengan kapasiti mata, bukan indeks beban** — ✅ dilaksanakan dalam 1.12 (lihat `docs/AGIHAN_AUTO.md`)

- `tugas_auto.php` memilih calon ikut nisbah `(beban + pemberat) ÷ kapasiti` dan menolak ikut "Had kapasiti mata".
- Dashboard, Analisis, Kelulusan dan cadangan Agihan pula menilai ikut **indeks beban**, iaitu masa mengajar + skor tugas ÷ median.
- Akibatnya, hasil agihan automatik boleh menjadikan guru "Terbeban" pada Dashboard walaupun nisbah kapasitinya rendah. Contoh: guru kelas peperiksaan dengan 30 masa mengajar.

Cadangan:

- Jadikan anggaran indeks selepas lantikan (`index_after()`) kunci utama pemilihan calon, menggantikan nisbah kapasiti.
- Kekalkan had kapasiti sebagai syarat pilihan (`respect_capacity`).
- Sebab pemilihan ditukar kepada "indeks terendah selepas lantikan".
- Perlu: ujian perbandingan output sebelum/selepas dan kemas kini `docs/AGIHAN_AUTO.md`.

**B2. Dua ukuran beban dipaparkan serentak** — ✅ dilaksanakan dalam 1.12 (Senarai Guru: lajur Indeks beban dahulu, lencana kapasiti hanya apabila dilampaui; Dashboard: kad "Guru terbeban")

- Senarai Guru memaparkan lencana kapasiti ("Melebihi had" / "Dalam kapasiti") di sebelah lencana indeks ("Terbeban" / "Seimbang").
- Pengguna boleh melihat "Dalam kapasiti" dan "Terbeban" pada guru yang sama. Contoh demo: Nurul Huda, 13/25 mata, indeks 1.31.

Cadangan:

- Jadikan indeks beban ukuran utama di semua halaman.
- Kapasiti mata hanya dipaparkan sebagai amaran sekunder apabila dilampaui, seperti di Kelulusan 1.11.
- Ubah label lencana kapasiti kepada "Melebihi kapasiti mata" supaya jelas ia ukuran lain.

**B3. Tetapan indeks boleh diubah semasa semakan** — ✅ dilaksanakan dalam 1.12 (pilihan pertama: `indeks_tetapan` dikunci selain draf; halaman Analisis memaparkan notis)

- `indeks_tetapan` dikecualikan daripada kunci draf.
- Jika PK menukar ambang, contohnya 1.30 → 1.50, ketika agihan menunggu kelulusan, senarai guru terbeban yang dilihat Pengetua turut berubah walaupun versi yang dihantar sama.
- Tetapan indeks juga tidak disimpan dalam snapshot revisi, jadi sejarah tidak merekod ambang yang digunakan semasa kelulusan.

Cadangan (salah satu):

- Kunci `indeks_tetapan` selain draf.
- Simpan `index_settings()` dalam payload revisi. Ini mengubah hash; perlukan shim keserasian seperti `school.letter` dalam 1.3.

### C. Demo

**C1. Draf demo kembali kepada keadaan seed sekali sehari sahaja** — ✅ dilaksanakan dalam 1.12 (kedua-dua cadangan)

- Reset berlaku pada lawatan pertama selepas 12 tengah malam (atau cron `bin/demo-reset.php`).
- Jika penguji mengembalikan draf dengan guru lain ditanda, keadaan itu kekal untuk semua penguji sehingga esok.
- Selepas reset, seed sengaja mencipta semula draf "dikembalikan oleh Pengetua". Ini boleh disangka reset tidak berlaku.

Cadangan:

- Butang "Set semula demo sekarang" untuk akaun Pentadbir demo, dengan had sekali setiap 10 minit.
- Atau, nyatakan dalam jalur demo: "Draf contoh dikembalikan oleh Pengetua dengan 2 guru terbeban ditanda".

**C2. Jalur demo** sudah memaparkan masa reset terakhir. Cadangan tambahan: paparkan juga masa reset seterusnya ("reset seterusnya: 12:00 tengah malam"). — ✅ 1.12

Pepijat ditemui semasa melaksanakan C1 dan dibetulkan:

- `current_user()` menyimpan pengguna dalam cache statik. Reset melalui web memadam akaun demo, tetapi audit data seed masih merujuk ID pengguna lama, jadi kekangan kunci asing `audit_logs.user_id` gagal. Reset itu tergendala selepas data dikosongkan, dan penanda `demo` sekolah turut hilang. Pembetulan: sesi ditamatkan dan cache disegarkan (`current_user(true)`) sebelum reset.
- Halaman log masuk tidak memaparkan mesej flash, jadi makluman selepas dialih ke log masuk hilang. Kini dipaparkan.

### D. Operasi & keluaran

- **D1. Webhook tidak dihantar.** Rahsia `DEPLOY_HOOKS` / `DEPLOY_SECRET` belum ditetapkan dalam GitHub. Laman dikemas kini melalui panel Platform atau cron sahaja. Tetapkan kedua-dua rahsia jika mahu laman dikemas kini automatik selepas setiap push. — ⏳ **perlu tindakan anda** (nilai rahsia ada di pelayan):
  1. Di pelayan, jana rahsia dan masukkan ke `.env` laman: `UPDATE_SECRET=<hasil openssl rand -hex 32>`.
  2. Dari komputer ini, tetapkan rahsia repo dengan nilai yang **sama**:
     ```bash
     gh secret set DEPLOY_SECRET --repo shaiffulmd/agihtugas-saas
     gh secret set DEPLOY_HOOKS --repo shaiffulmd/agihtugas-saas --body "https://demourustugas.apasaja.my/kemaskini-hook"
     ```
  3. Push seterusnya: langkah "Maklumkan laman (webhook)" dalam GitHub Actions akan berjalan. Butiran: `docs/KEMASKINI-GITHUB.md`.
- **D2. Naik taraf skema.** Laman yang dipasang sebelum 1.4 / 1.8 memerlukan `php bin/upgrade.php` (skema `0.1-m6`), atau tekan Naik taraf di panel, sebelum halaman Mata Pelajaran dan Peranan berfungsi. — ✅ disemak: kemas kini melalui panel Platform / cron / webhook (`app/updater.php`) sudah menjalankan `run_upgrade()` secara automatik. Hanya muat naik zip secara manual memerlukan `php bin/upgrade.php` atau `/install.php`; laman memaparkan "Penyelenggaraan sedang berjalan" sehingga itu dibuat.
- **D3. Ujian `tempatan`.** Kumpulan ini (2FA, subdomain, pembayaran ujian) belum dijalankan sejak 1.2. Disyorkan sekali sebelum keluaran besar seterusnya. — ✅ dijalankan pada 1.12 dalam salinan berasingan (APP_ENV=local, 2FA wajib, mod subdomain, gerbang bayaran olok-olok): **13 suite, 504 semakan, semua lulus** (ralat 10, keselamatan 72, integriti 28, platform 96, bayaran 69, akaun 66, domain 16, rupa 39, direktori 33, pengemas kini 13, sandaran 12, API 17, berbilang sekolah subdomain 33, serta kriptografi).

Selepas semua perubahan 1.12, kumpulan `demo` pula lulus 13 suite dengan 472 semakan, dan kumpulan `statik` bersih.

## 4. Cadangan susunan kerja

| # | Perkara | Saiz | Kesan |
|---|---|---|---|
| 1 | ✅ A1 — tapis guru ditanda yang dipadam (1.12) | kecil | membetulkan pepijat |
| 2 | ✅ B1 — agihan automatik ikut indeks beban (1.12) | sederhana | hasil auto selaras dengan Dashboard |
| 3 | ✅ B2 — indeks sebagai ukuran utama di Senarai Guru (1.12) | kecil | kurang kekeliruan |
| 4 | ✅ B3 — kunci tetapan indeks semasa semakan (1.12) | kecil | keputusan Pengetua stabil |
| 5 | ✅ C1/C2 — butang reset demo & teks jalur (1.12) | kecil | demo lebih mudah difahami |
| 6 | ⏳ D1 — tetapkan rahsia webhook | kecil | laman dikemas kini automatik selepas push |
