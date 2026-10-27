# UrusTugas API v1

Setiap sekolah mempunyai API sendiri: `https://DOMAIN/KOD/api/v1` (mod laluan) atau `https://kod.DOMAIN/api/v1` (mod subdomain), cth. `http://127.0.0.1:8091/CEA1234/api/v1` setempat. Data sama seperti UI sekolah itu. Kontrak mesin: `public_html/assets/api/openapi.json` (dihidangkan di `/assets/api/openapi.json`).

## Kunci API

Pentadbir sekolah mencipta kunci di **API & Integrasi** (menu Sistem): nama sistem + skop `read` (baca sahaja) atau `write` (baca & ubah). Kunci penuh (`ut_` + 64 heks) dipaparkan **sekali**; hanya HMAC-SHA256 disimpan (subkunci platform — hash unik global). Kunci hanya sah untuk sekolah yang menciptanya: kunci sekolah A pada URL sekolah B → `401 unauthorized`. Batalkan kunci di halaman yang sama; untuk putaran akses cipta kunci baharu, kemas kini sistem pemanggil, kemudian batalkan kunci lama.

Semua endpoint memerlukan `Authorization: Bearer <KUNCI>`. Kuki sesi atau kunci dalam query URL tidak diterima. Jangan simpan kunci dalam JavaScript/pelayar. Cubaan kunci gagal dihadkan (120 / 15 minit / IP). Sekolah dengan langganan tamat → `403 subscription_expired`; mod kecemasan platform → `503 maintenance` (tulisan) atau semua (mod tutup).

## Endpoint

| Kaedah | Laluan selepas /api/v1 | Fungsi |
| --- | --- | --- |
| GET, POST | /teachers | Senarai atau tambah guru |
| GET, PATCH, DELETE | /teachers/{id} | Baca, sunting sebahagian atau padam guru |
| POST | /teachers/sync | Cipta/kemas kini guru mengikut external_id unik |
| GET | /teachers/{id}/assignments | Tugas seorang guru |
| GET, POST | /positions | Senarai atau tambah jawatan |
| GET, PATCH, DELETE | /positions/{id} | Baca, sunting sebahagian atau padam jawatan |
| PUT | /positions/{id}/assignment | Tetapkan/pindahkan penyandang secara idempoten |
| GET, POST | /assignments | Senarai atau tambah lantikan |
| GET, PATCH, DELETE | /assignments/{id} | Baca, tukar teacher_id atau lepaskan lantikan |
| GET | /summary | Jumlah guru, jawatan, mata dan beban mengikut bidang |
| GET | /school | Profil sekolah dan label sesi |
| GET | /letters/{teacher_id} | Data JSON untuk membina surat dalam sistem lain |

DELETE guru atau jawatan yang masih mempunyai lantikan menghasilkan 409. POST assignments untuk jawatan yang sudah diisi menghasilkan 409; gunakan PUT positions/{id}/assignment untuk pemindahan. PUT boleh diulang tanpa menggandakan lantikan. Melebihi kapasiti dibenarkan seperti UI, dengan `over_capacity` pada objek guru; sistem pemanggil perlu memaparkan amaran.

## Permintaan dan respons

Badan POST/PATCH/PUT mesti objek JSON dengan Content-Type: application/json (maksimum 64 KB). Medan tidak dikenali ditolak. Integer mesti nombor JSON, bukan rentetan. PATCH mengekalkan medan yang tidak dihantar. Medan nama maksimum 160 aksara; email maksimum 190. `external_id` unik dalam sekolah (sekolah lain boleh menggunakan nilai sama).

Guru: `name` dan `capacity` (1–100) diperlukan ketika POST; `email` dan `specialty` pilihan. `external_id` pilihan dan unik, contoh `koku-multi:12:89`. POST /teachers/sync memerlukan external_id; guru baharu juga memerlukan name, dengan capacity lalai 25. Sync guru sedia ada mengekalkan medan yang tidak dihantar. Untuk memautkan rekod AgihTugas sedia ada, PATCH external_id dahulu supaya sync tidak mencipta rekod kedua. Hanya guru boleh dipautkan melalui external_id pada versi ini.

Jawatan: `name`, `category` (Pentadbiran/Kurikulum/HEM/Kokurikulum), `weight` (1–10). Satu rekod jawatan ialah satu tempat lantikan. Pilihan: `level` (`utama`/`sokongan`/`sampingan`, lalai sokongan) dan `term` (`tahunan`/`bermusim`/`program`, lalai tahunan). Respons menyertakan `score` = weight × tahap (1.5/1.0/0.5) × tempoh (1.0/0.6/0.3) yang digunakan dalam indeks beban; `weight` kekal sebagai mata kapasiti.

Indeks beban (1.2): guru menerima medan pilihan `teaching_periods` (0–60 masa seminggu), `exam_class` (boolean), `status` (`aktif`/`cuti`/`bertukar`) dan `index_exempt` (boolean, pentadbir). PATCH dan sync mengekalkan medan yang tidak dihantar. Setiap objek guru menyertakan `load_index`: `index` (null jika tidak dikira), `label` (`terbeban`/`seimbang`/`kurang`/`belum`/`dikecualikan`/`tidak_aktif`), `score`, `teaching` (masa terlaras faktor peperiksaan), `teaching_ratio`, `task_ratio` dan `warnings` (`terbeban`, `kurang`, `banyak`, `tidak_aktif`, `tiada_masa`). Indeks = bahagian mengajar × teaching_ratio + baki × task_ratio, dengan nisbah dibandingkan dengan median guru aktif yang tidak dikecualikan (purata jika median 0). `/summary` menyertakan `load_index` (overloaded, balanced, underloaded, not_counted, median_teaching, median_score, teaching_share, high, low). Tetapan indeks diurus di UI (Analisis Beban), bukan API.

Agihan: `teacher_id`, `position_id`. PATCH hanya boleh menukar teacher_id; position_id tidak boleh ditukar kepada jawatan lain.

Senarai menyokong `page=1&per_page=50` (per_page maksimum 100), dengan `meta.total` dan `meta.pages`. teachers dan positions menyokong `q`; teachers menyokong `external_id`; positions menyokong `category`; assignments menyokong `teacher_id` dan `position_id`. Urutan senarai mengikut ID menaik.

```json
{"data":{"id":13,"name":"Guru Contoh","capacity":25,"load":8,"task_count":1,"teaching_periods":28,"exam_class":false,"status":"aktif","index_exempt":false,"over_capacity":false,"load_index":{"index":0.94,"label":"seimbang","score":8,"teaching":28,"teaching_ratio":1,"task_ratio":0.85,"warnings":[]}},"request_id":"..."}
```

Respons guru sebenar juga mengandungi email, specialty dan external_id. Respons berjaya: 200 atau 201 untuk ciptaan baharu. Penciptaan melalui POST CRUD menyertakan header Location. Semua respons menyertakan X-Request-ID. Ralat:

```json
{"error":{"code":"validation_error","message":"..."},"request_id":"..."}
```

Kod HTTP: 400 JSON rosak; 401 key tiada/tidak sah; 403 skop tidak mencukupi; 404 rekod/endpoint tiada; 405 kaedah salah; 409 konflik; 413 badan terlalu besar; 415 Content-Type salah; 422 input tidak sah; 500 ralat pelayan. Perubahan API direkod dalam log aktiviti dengan ID/nama key, tanpa token.

## Contoh cURL

Tetapkan URUSTUGAS_API_KEY dalam persekitaran shell. Jangan commit nilainya.

```sh
curl -H "Authorization: Bearer $URUSTUGAS_API_KEY" \
  http://127.0.0.1:8091/CEA1234/api/v1/summary

curl -X POST -H "Authorization: Bearer $URUSTUGAS_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"external_id":"koku-multi:12:89","name":"Guru Contoh","email":"guru@example.com"}' \
  http://127.0.0.1:8091/CEA1234/api/v1/teachers/sync

curl -X PUT -H "Authorization: Bearer $URUSTUGAS_API_KEY" \
  -H 'Content-Type: application/json' -d '{"teacher_id":13}' \
  http://127.0.0.1:8091/CEA1234/api/v1/positions/21/assignment
```

Gantikan ID dalam contoh dengan ID sebenar respons API.

## Akses rangkaian

UI dilindungi log masuk dan peranan; API kekal menggunakan Bearer key berasingan. Semua mutasi data API memerlukan status Draf. API key tidak boleh meluluskan atau memuktamadkan agihan. Untuk pemasangan pelayan, gunakan HTTPS dan document root `public/`, jauhkan storage serta bin daripada webroot. Apache memerlukan mod_rewrite dan .htaccess yang disertakan; pelayan lain perlu menghantar /api/* kepada index.php sambil mengekalkan Authorization.

Secara lalai tiada CORS untuk laman lain. Jika diperlukan untuk aplikasi pelayar yang dipercayai, tetapkan API_CORS_ORIGINS dalam .env dengan senarai origin tepat dipisah koma. Tiada wildcard atau Access-Control-Allow-Credentials. CORS bukan pengesahan. Kunci rahsia untuk sistem lain (cth. Sekolah Koku) hendaklah kekal di backend.

Satu ruang kerja AgihTugas mewakili satu sekolah. API ini belum mempunyai tenant, webhook, auto-sync atau arkib sesi. /letters mengembalikan salinan versi muktamad terkini dalam JSON (`409 not_finalized` jika belum ada versi muktamad; `404 not_found` jika guru tiada dalam versi muktamad terkini, cth. guru ditambah selepas pemuktamadan); PDF masih melalui cetakan UI. Auto-agihan tersedia melalui aliran preview dan apply seperti di bawah.

## Auto-agihan, syarat dan jawatan pukal

| Kaedah | Endpoint | Fungsi |
| --- | --- | --- |
| GET | /allocation/rules | Baca syarat umum dan syarat khusus jawatan |
| PUT | /allocation/rules | Kemas kini medan syarat umum yang dihantar |
| GET, PUT | /positions/{id}/rules | Baca / ganti syarat khusus satu jawatan |
| POST | /allocation/preview | Jana pratonton dengan mode: vacant atau rebalance |
| POST | /allocation/apply | Simpan pratonton dengan plan_id |
| POST | /positions/bulk | Tambah jawatan pukal daripada objek rows |

Semua PUT/POST memerlukan key write. Preview menyimpan draf tetapi tidak mengubah assignments. Pratonton sah 30 minit dan sekali guna; perubahan guru, jawatan, agihan, tetapan sekolah atau syarat selepas preview menyebabkan apply ditolak dengan 409. Pelan disimpan di pelayan, bukan diterima sebagai mapping daripada pemanggil. Had syarat terpakai untuk auto-agihan; endpoint lantikan manual masih membenarkan pentadbir mengatasi had.

Contoh syarat umum (PUT membenarkan subset medan; category_limits mesti lengkap apabila dihantar):

```json
{"respect_capacity":true,"max_tasks":13,"max_heavy":2,"heavy_threshold":8,"category_limits":{"Pentadbiran":3,"Kurikulum":3,"HEM":3,"Kokurikulum":4},"excluded_teachers":[]}
```

Syarat jawatan (PUT menggantikan syarat; nilai lalai bagi medan yang tidak dihantar):

```json
{"allowed_teachers":[1,2],"fixed_teacher_id":null,"required_specialty":"Matematik"}
```

Senarai allowed_teachers kosong bermaksud semua guru; required_specialty kosong bermaksud tiada syarat kepakaran. Had kapasiti/bilangan/berat/bidang dan pengecualian guru tetap terpakai pada penyandang tetap. Guru yang dirujuk dalam syarat tidak boleh dipadam sehingga rujukan dibuang.

```json
{"mode":"vacant"}
```

Preview memulangkan plan_id, expires_at, rows (before_teacher_id, teacher_id, reason, changed), unfilled, warnings, teachers (beban selepas cadangan), summary (assigned, unfilled, changed, released) dan rules. Apply menerima `{"plan_id":"nilai daripada preview"}` dan memulangkan summary. Mod rebalance boleh mengosongkan lantikan sedia ada yang tidak lagi layak; semak released sebelum apply. Enjin heuristik tidak menjamin optimum global.

Bulk menerima `{"rows":[{"name":"AJK Disiplin","category":"HEM","weight":5,"count":3}]}`. count pilihan, lalai 1, maksimum 50. Maksimum 500 baris / 1,000 tempat setiap permintaan, dengan had badan API 64 KB. Semua input disahkan dahulu dan disimpan atomik; pendua nama/bidang menyebabkan 422. Respons 201 mempunyai data.created dan data.ids. Endpoint bulk menyimpan terus; aplikasi pemanggil perlu menyediakan semakan pengguna sendiri. UI AgihTugas menyediakan pratonton sebelum simpan.

## Semakan dan penerbitan (v1.2)

GET `/workflow` memulangkan status (`draft`, `submitted`, `approved`, `finalized`), revision_id dan version. GET `/workflow/published` memulangkan revision_id, finalized_at serta snapshot versi muktamad terkini; 409 jika belum ada. Kedua-duanya memerlukan key read atau write.

API tidak menyediakan tindakan submit/approve/finalize/return; keputusan tersebut memerlukan akaun pengguna dan peranan pada UI. Mutasi melalui API (termasuk sync guru, rules, preview, apply dan bulk) menghasilkan **409 workflow_locked** apabila bukan Draf. Bacaan teachers/positions/assignments/summary masih menunjukkan data kerja semasa; gunakan published atau letters untuk paparan rasmi kepada guru. Endpoint `/letters/{id}` kini menggunakan snapshot muktamad, bukannya draf semasa, dan menyertakan revision_id serta finalized_at.

Key integrasi masih mempunyai akses seluruh ruang kerja sekolah; jangan berikan key ini kepada guru atau aplikasi pelayar. Backend koku-multi mesti mengesahkan sid() dan pemetaan profil guru sebelum memaparkan dokumen kepada pengguna berkenaan.

Profil sekolah API menyertakan `school_type` (SK/SMK) dan `principal_title` (Guru Besar/Pengetua). Surat dan snapshot muktamad menggunakan jenis sekolah pada masa versi itu dihantar, supaya surat lama tidak berubah apabila pentadbir menukar jenis sekolah.

## Imej sekolah dan jata

`school.branding` mengandungi layout (`dual`, `school`, `national`), school_logo (metadata atau null), national_crest dan shared_crest_url. Metadata imej mempunyai id, mime, width, height dan url. GET `/assets/{id}` mengembalikan **image/png binari**, bukan sampul JSON, dengan pengesahan Bearer read/write yang sama. Respons imej menggunakan cache private dan nosniff.

Muat naik dan gaya kepala surat diurus oleh Pentadbir melalui UI, hanya semasa Draf. Surat muktamad merujuk versi imej yang disimpan dalam snapshot. Metadata tidak mengandungi laluan filesystem atau data logo dalam base64. Jata bersama tersedia secara statik di `/assets/shared/jata-negara.png` untuk muat turun tanpa API key. Klien PHP mempunyai `assetBytes($id)` untuk mengambil imej versi snapshot.
