# Pelan UrusTugas SaaS

## 1. Asas tenant dan pengasingan data

Bina entiti organisasi/sekolah, domain atau slug unik, keahlian pengguna serta peranan mengikut sekolah. Tentukan tenant daripada keahlian yang disahkan; jangan mempercayai school_id yang dihantar klien. Tentukan strategi database sebelum migrasi: database berasingan setiap tenant atau PostgreSQL dengan tenant_id, kekangan komposit dan polisi pengasingan.

Semua guru, jawatan, lantikan, syarat, pelan agihan, sejarah, imej, surat muktamad dan kunci API mesti terikat kepada tenant. Uji akses silang sekolah pada UI, API, muat turun imej/surat, eksport dan kerja latar sebelum menyediakan onboarding awam.

## 2. Portal sekolah

Pendaftaran sekolah SK/SMK, jemputan pengguna, pengesahan e-mel, pemulihan kata laluan, konfigurasi sekolah dan audit. Peranan pentadbir platform mesti berasingan daripada pentadbir sekolah. Akses sokongan kepada tenant mesti direkodkan.

## 3. Pelan dan langganan

Takrif pelan, percubaan, kuota, status langganan serta had penggunaan. Pilih penyedia pembayaran kemudian. Webhook perlu disahkan dan diproses secara idempoten; akses langganan tidak ditentukan oleh redirect pelayar semata-mata.

## 4. Operasi production

Sediakan HTTPS, sandaran/pemulihan mengikut tenant, pemantauan, rate limit, e-mel transaksi, storan imej peribadi, migrasi dan ujian pengasingan automatik. Document root deployment kekal public_html; kod dan rahsia di luar webroot.

## 5. Integrasi koku-multi

Setiap sekolah menggunakan key API dan skop tenant sendiri. Pemetaan external_id mesti unik dalam tenant, bukan seluruh platform. Pembatalan key dan pertukaran keahlian mesti berkuat kuasa serta-merta.

Semua perkara di atas ialah pelan kerja; salinan awal ini belum melaksanakannya.
