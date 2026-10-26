# Akaun, peranan dan kelulusan agihan

## Akaun sekolah

Setiap sekolah mempunyai Pentadbir Utama yang dicipta oleh Panel Platform, pendaftaran awam (selepas pengesahan e-mel) atau `php bin/school.php create`. Kata laluan sementara wajib ditukar pada log masuk pertama dan 2FA (TOTP) wajib untuk pentadbir apabila `REQUIRE_2FA=1`. Tiada akaun atau kata laluan lalai.

Pentadbir membuka Pengguna dan mencipta akaun PK dan Guru Besar/Pengetua yang berasingan. Akaun Guru wajib dipautkan kepada satu profil guru yang unik. Pentadbir boleh menyahaktifkan akaun, menetapkan semula kata laluan atau 2FA. Pengguna menukar kata laluan sendiri di Profil. Bilangan pengguna aktif dihadkan oleh pakej Free (lalai 10).

## Hak akses

| Peranan | Akses |
| --- | --- |
| Pentadbir | Urus akaun/tetapan; sediakan dan hantar draf; buka semula pindaan dengan sebab |
| Penolong Kanan | Urus guru, jawatan, syarat, import dan agihan; hantar draf |
| Guru Besar/Pengetua | Semak salinan dihantar; luluskan, muktamadkan atau kembalikan dengan sebab |
| Guru | Lihat/cetak tugas serta surat muktamad sendiri; tukar kata laluan sendiri |

Guru Besar/Pengetua tidak menyunting draf secara terus. Penghantar tidak boleh meluluskan agihan sendiri walaupun peranannya ditukar selepas penghantaran. Hanya Guru Besar/Pengetua mempunyai tindakan Lulus/Muktamad. Pentadbir yang ingin meluluskan memerlukan akaun Guru Besar/Pengetua berasingan untuk orang yang bertanggungjawab; sistem tidak mengesahkan identiti manusia di luar akaun yang disediakan sekolah.

Sesi dan kuki diasingkan mengikut sekolah: log masuk di `/KOD-A/` tidak memberi akses kepada `/KOD-B/`, dan semua data dibaca melalui `school_id` sekolah semasa. Perubahan akaun menyemak semula versi sesi; nyahaktif atau perubahan kata laluan membatalkan sesi lama. Cubaan log masuk gagal dihadkan dan IP boleh disekat (Keselamatan). Kuki HttpOnly/SameSite dan Secure di HTTPS; semua perubahan UI memerlukan CSRF. Sesi sokongan Pentadbir Platform ke dalam sekolah direkodkan dalam log audit sekolah.

## Aliran keputusan

1. Pentadbir/PK menyediakan agihan dalam **Draf**.
2. Klik **Hantar untuk semakan**. Salinan data disimpan dan data kerja dikunci.
3. Guru Besar/Pengetua menyemak semua jawatan dan beban. Klik **Luluskan agihan**, atau kembalikan ke draf dengan sebab.
4. Dari **Lulus**, Guru Besar/Pengetua klik **Muktamadkan & terbitkan surat**.
5. Surat dan paparan tugas guru menggunakan salinan versi muktamad.

Penghantaran memerlukan sekurang-kurangnya satu lantikan; jawatan kosong dan beban berlebihan tidak menghalang keputusan tetapi perlu disemak oleh Guru Besar/Pengetua. Snapshot merangkumi guru, jawatan, penyandang, maklumat sekolah dan syarat. Jika data berubah di luar aplikasi selepas penghantaran, keputusan kelulusan ditolak dan draf perlu dihantar semula. Setiap keputusan merekodkan akaun pelaku dan masa; UI mengelakkan tindakan berdasarkan versi status lama.

Pentadbir/Guru Besar/Pengetua boleh membuka draf pindaan dengan sebab. Surat muktamad terdahulu kekal sama sehingga versi baharu dimuktamadkan. Sejarah versi disimpan; pemilih untuk mencetak semula versi lama belum disediakan. Tarikh surat ialah tarikh pemuktamadan dalam zon Asia/Kuala_Lumpur, bukan tarikh pelayar dibuka.

## Integrasi

Kunci API (ciri Pro) dicipta oleh Pentadbir di API & Integrasi, berasingan daripada akaun pengguna, dan sah untuk satu sekolah sahaja. Kunci baca/tulis hanya boleh meminda ketika status Draf; kunci tidak boleh meluluskan atau memuktamadkan. Gunakan endpoint `workflow/published` atau surat untuk paparan rasmi. Lihat [API.md](API.md).

Pilih jenis sekolah dalam Tetapan Sekolah: SK menggunakan Guru Besar dan SMK menggunakan Pengetua. Gelaran dalam surat mengikut versi muktamad, bukan tetapan terkini. Nombor versi (#1, #2, …) dikira bagi setiap sekolah.
