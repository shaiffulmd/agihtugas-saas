# Akaun, peranan dan kelulusan agihan

## Pentadbir pertama

Jalankan `php bin/setup-admin.php` dari terminal projek. Kod sekali guna sah 30 minit. Buka `/?page=setup`, masukkan kod dan cipta akaun menggunakan e-mel serta kata laluan sendiri (12–72 aksara). Tiada akaun/kata laluan lalai. Kod disimpan sebagai hash, bukan teks asal. Selepas satu akaun dicipta, persediaan pertama ditutup. Kata laluan tidak dihantar melalui URL atau direkod dalam log.

Log masuk dan buka Akaun Pengguna. Cipta akaun PK dan Guru Besar/Pengetua yang berasingan. Akaun Guru wajib dipautkan kepada satu profil guru yang unik. Pentadbir boleh menyahaktifkan akaun atau menetapkan kata laluan baharu. Pengguna boleh menukar kata laluan sendiri di Profil & Kata Laluan dengan mengesahkan kata laluan semasa. Tiada pemulihan e-mel atau pendaftaran awam disediakan.

## Hak akses

| Peranan | Akses |
| --- | --- |
| Pentadbir | Urus akaun/tetapan; sediakan dan hantar draf; buka semula pindaan dengan sebab |
| Penolong Kanan | Urus guru, jawatan, syarat, import dan agihan; hantar draf |
| Guru Besar/Pengetua | Semak salinan dihantar; luluskan, muktamadkan atau kembalikan dengan sebab |
| Guru | Lihat/cetak tugas serta surat muktamad sendiri; tukar kata laluan sendiri |

Guru Besar/Pengetua tidak menyunting draf secara terus. Penghantar tidak boleh meluluskan agihan sendiri walaupun peranannya ditukar selepas penghantaran. Hanya Guru Besar/Pengetua mempunyai tindakan Lulus/Muktamad. Pentadbir yang ingin meluluskan memerlukan akaun Guru Besar/Pengetua berasingan untuk orang yang bertanggungjawab; sistem tidak mengesahkan identiti manusia di luar akaun yang disediakan sekolah.

Sesi diasingkan mengikut pemasangan/pangkalan data dan tamat selepas dua jam tidak aktif. Perubahan akaun menyemak semula versi sesi; nyahaktif atau perubahan kata laluan membatalkan sesi lama. Lima cubaan log masuk gagal bagi e-mel/IP sama dalam 15 minit menyebabkan sekatan sementara. Sesi mempunyai cookie HttpOnly/SameSite Strict dan Secure apabila PHP menerima HTTPS. Semua perubahan UI memerlukan CSRF.

## Aliran keputusan

1. Pentadbir/PK menyediakan agihan dalam **Draf**.
2. Klik **Hantar untuk semakan**. Salinan data disimpan dan data kerja dikunci.
3. Guru Besar/Pengetua menyemak semua jawatan dan beban. Klik **Luluskan agihan**, atau kembalikan ke draf dengan sebab.
4. Dari **Lulus**, Guru Besar/Pengetua klik **Muktamadkan & terbitkan surat**.
5. Surat dan paparan tugas guru menggunakan salinan versi muktamad.

Penghantaran memerlukan sekurang-kurangnya satu lantikan; jawatan kosong dan beban berlebihan tidak menghalang keputusan tetapi perlu disemak oleh Guru Besar/Pengetua. Snapshot merangkumi guru, jawatan, penyandang, maklumat sekolah dan syarat. Jika data berubah di luar aplikasi selepas penghantaran, keputusan kelulusan ditolak dan draf perlu dihantar semula. Setiap keputusan merekodkan akaun pelaku dan masa; UI mengelakkan tindakan berdasarkan versi status lama.

Pentadbir/Guru Besar/Pengetua boleh membuka draf pindaan dengan sebab. Surat muktamad terdahulu kekal sama sehingga versi baharu dimuktamadkan. Sejarah versi disimpan; pemilih untuk mencetak semula versi lama belum disediakan. Tarikh surat ialah tarikh pemuktamadan dalam zon Asia/Kuala_Lumpur, bukan tarikh pelayar dibuka.

## Integrasi dan pemasangan

API key kekal berasingan daripada akaun. Key baca/tulis hanya boleh meminda ketika status Draf; key tidak boleh meluluskan atau memuktamadkan. Akaun pengguna tidak memberikan akses kepada API tanpa key. Data kerja API boleh berupa draf; gunakan endpoint published atau letters untuk paparan rasmi.

Data pengguna, hash kata laluan, sesi, kod persediaan dan snapshot runtime tidak dimuat naik ke Git. Sandarkan SQLite berasingan. Aplikasi ini satu sekolah; tiada pengasingan tenant. Pelayan pratonton PHP bukan pelayan produksi. Untuk pelayan sekolah gunakan HTTPS, document root public/, konfigurasi proksi yang betul dan sandaran.

Pilih jenis sekolah dalam Tetapan Sekolah: SK menggunakan Guru Besar dan SMK menggunakan Pengetua. Gelaran dalam surat mengikut versi muktamad, bukan tetapan terkini.
