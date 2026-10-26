# Panduan agihan automatik & jawatan pukal

Auto-agihan & Pusat Syarat ialah ciri Pro secara lalai (pentadbir platform memilih ciri Pro di Tetapan Platform → "Ciri Pro sahaja"). Jawatan pukal tersedia untuk semua pakej melainkan "pukal" dipilih sebagai ciri Pro; had Free (guru/jawatan) terpakai pada setiap tambahan.

## Pusat Syarat

1. Semak profil/kepakaran dan kapasiti setiap guru di Pengurusan Guru.
2. Di Pusat Syarat, tetapkan sama ada kapasiti mata mesti dipatuhi, maksimum tugas, maksimum tugas berat, pemberat minimum bagi tugas berat dan had setiap bidang. Had bidang 0 menghalang lantikan automatik baharu dalam bidang itu.
3. Pilih guru yang dikecualikan, jika perlu.
4. Pilih jawatan di bahagian syarat khusus dan tekan Buka syarat. Hadkan senarai guru layak, masukkan kepakaran yang mesti wujud dalam profil, atau tetapkan seorang penyandang tetap. Kosongkan pilihan dan simpan untuk membuang sekatan khusus. Pengecualian dan had umum masih terpakai; penyandang tetap bukan jalan pintas untuk mengatasi syarat.

Had ini untuk auto-agihan sahaja. Pentadbir masih boleh melantik secara manual. Guru yang dirujuk dalam syarat perlu dibuang daripada rujukan dahulu sebelum profilnya boleh dipadam.

## Jana dan semak

- **Isi jawatan kosong** mengekalkan semua lantikan sedia ada. Beban lantikan itu dikira dahulu dan pelanggaran syarat dipaparkan sebagai perhatian. Penyandang tetap yang berbeza tidak memindahkan lantikan sedia ada dalam mod ini.
- **Susun semula semua** mengira agihan semula daripada kosong. Penyandang boleh berubah, dan jawatan yang tidak dapat memenuhi syarat boleh menjadi kosong walaupun pernah diisi. Semak jumlah lantikan dilepaskan.

Klik Jana pratonton. Semak sebelum/cadangan, sebab pemilihan, jawatan belum diisi, amaran lantikan sedia ada dan beban guru selepas cadangan. Klik Simpan agihan ini apabila berpuas hati. Rekod yang disimpan masih draf. Surat lantikan menggunakan versi yang telah diluluskan dan dimuktamadkan melalui Semakan & Kelulusan.

Draf tamat selepas 30 minit, sekali guna dan mesti dijana semula jika data atau syarat berubah. Penjanaan semula tidak melantik guru secara automatik.

## Kaedah enjin

Enjin deterministik mendahulukan penyandang tetap, kemudian jawatan yang calonnya terhad, kemudian jawatan berat. Bagi setiap jawatan, guru yang memenuhi semua syarat ditapis dahulu. Calon dipilih mengikut nisbah `(mata semasa + pemberat jawatan) / kapasiti`; seri dipecahkan mengikut bilangan tugas, bilangan tugas bidang itu dan ID guru.

Ini heuristik, bukan penyelesaian matematik optimum. Ia mungkin meninggalkan jawatan kosong walaupun susunan lain boleh mengisinya. Semak sebab dan laraskan syarat atau gunakan lantikan manual. Sistem tidak menilai pengalaman, jantina, umur, kesihatan, waktu mengajar atau sejarah sesi kerana maklumat itu belum dimodelkan. Kepakaran dipadankan sebagai teks tanpa membezakan huruf besar/kecil.

## Jawatan pukal

Dari Jawatan & Pemberat, pilih Tambah pukal. Muat turun templat CSV, tampal senarai daripada Excel (tab) atau muat naik CSV UTF-8. Pilih satu sumber pada satu masa. Lajur:

```text
name,category,weight,count
AJK Disiplin,HEM,5,3
Penyelaras STEM,Kurikulum,7,1
```

Header pilihan; susunan lajur tetap. Bidang mestilah Pentadbiran, Kurikulum, HEM atau Kokurikulum. Nama maksimum 145 aksara, pemberat 1–10 dan count 1–50. count boleh ditinggalkan, lalai 1. Had: 128 KB, 500 baris dan 1,000 tempat lantikan untuk UI.

Count 3 menghasilkan tiga rekod: AJK Disiplin (1/3), AJK Disiplin (2/3), AJK Disiplin (3/3). Setiap tempat mendapat pemberat penuh. Nama/bidang yang sudah ada atau berulang ditolak. Semua baris mesti lulus sebelum ada rekod ditambah; pratonton tidak menambah rekod. Format fail .xlsx tidak disokong, tetapi kandungan lajur boleh disalin dan ditampal.

Auto-agihan, pusat syarat dan import hanya boleh disunting oleh Pentadbir/PK semasa status Draf. Setelah dihantar, kembalikan ke draf sebelum membuat perubahan.
