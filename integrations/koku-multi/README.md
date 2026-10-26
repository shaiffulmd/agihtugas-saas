# Sambungan koku-multi → AgihTugas

Klien PHP ini disediakan untuk backend koku-multi. Ia belum dipasang atau diaktifkan dalam projek koku-multi. Tiada data sekolah sebenar dihantar semasa pembangunan.

## Konfigurasi

1. Jalankan AgihTugas dan cipta key baca/tulis di pelayannya:

   `php bin/api-key.php create 'koku-multi - KOD_SEKOLAH' write`

2. Simpan URL dan key pada backend koku-multi, di luar webroot atau dalam konfigurasi rahsia. Jangan masukkan key dalam repositori, URL atau JavaScript.
3. Muatkan `AgihTugasClient.php` daripada backend. Kod panggilan perlu berada dalam tindakan pentadbir koku-multi yang sudah mengesahkan sesi dan CSRF.

```php
require_admin();
verify_csrf(); // Tindakan POST sahaja, sebelum menghantar data.

require_once '/lokasi/integrations/koku-multi/AgihTugasClient.php';

// Ambil konfigurasi KHUSUS sid() daripada simpanan konfigurasi pelayan.
// Jangan terima base URL, API key atau school ID daripada POST pengguna.
$config = $schoolIntegrationConfig[sid()] ?? null;
if (!$config) throw new RuntimeException('Integrasi sekolah ini belum dikonfigurasi.');

$client = new \AgihTugas\Integration\AgihTugasClient($config['base_url'], $config['api_key']);

// teacher_options() sedia ada menapis guru/admin aktif dan diluluskan bagi sid().
foreach (teacher_options() as $teacher) {
    $synced = $client->syncTeacher(sid(), $teacher);
    // Simpan $synced['id'] sebagai ID AgihTugas jika diperlukan.
}
$summary = $client->summary();
$positions = $client->all('positions');
```

`external_id = koku-multi:{school_id}:{user_id}` menjadikan penghantaran semula guru yang sama selamat tanpa mencipta pendua. ID pengguna koku-multi dan ID guru AgihTugas adalah berbeza. Gunakan ID yang dipulangkan API untuk melantik guru. Kapasiti guru sedia ada dikekalkan ketika sync; guru baharu bermula pada 25 mata. Guru yang sudah ada dalam AgihTugas tanpa external_id perlu dipautkan dahulu melalui PATCH /teachers/{id}; sistem tidak memadankan nama atau e-mel secara andaian.

```php
$teacher = $client->syncTeacher(sid(), $teacherRow);
$assignment = $client->assign($agihTugasPositionId, $teacher['id']);
$tasks = $client->teacherAssignments($teacher['id']);
```

## Sempadan sekolah

AgihTugas sekarang ialah **satu ruang kerja untuk satu sekolah**, bukannya backend berbilang tenant. Semua API key dalam ruang kerja yang sama berkongsi data sekolah yang sama. Key berbeza bukan pengasingan tenant. Sekolah lain dalam koku-multi mesti dipetakan kepada pemasangan/pangkalan data AgihTugas berasingan sebelum digunakan, atau backend AgihTugas perlu ditingkatkan kepada berbilang tenant terlebih dahulu.

Tiada pemadaman guru automatik apabila guru dinyahaktifkan di koku-multi; semak dan lepaskan lantikan dahulu. Sync profil bergerak dari koku-multi ke AgihTugas; agihan kekal diurus dalam AgihTugas atau melalui endpoint assign. Tiada webhook atau sync latar dijalankan secara automatik.

## Agihan automatik dari backend koku-multi

```php
$rules = $client->allocationRules();
$plan = $client->previewAllocation('vacant');
// Paparkan rows, unfilled, warnings dan beban guru untuk disemak pentadbir.
// Simpan plan_id di pelayan; pada tindakan POST berasingan yang disahkan CSRF:
$summary = $client->applyAllocation($plan['plan_id']);
```

Jangan terus apply secara automatik selepas preview dalam aliran pengguna. Jika data/syarat berubah atau draf melebihi 30 minit, jana preview semula. Mod rebalance boleh melepaskan lantikan sedia ada; semak summary.released. `bulkPositions($rows)` menambah jawatan secara terus dan memerlukan semakan pentadbir pada sistem pemanggil.

## Data rasmi dan status kelulusan

`teacherAssignments()` dan `all()` membaca data kerja semasa (boleh berupa draf). Untuk paparan tugas rasmi kepada guru, gunakan `publishedLetter($teacherId)` atau `publishedAllocation()` selepas menyemak pemetaan sekolah/guru dalam koku-multi. `workflowStatus()` memaparkan status agihan. Semua mutasi API menghasilkan 409 apabila draf dikunci untuk semakan. Kelulusan dan pemuktamadan hanya boleh dibuat oleh Guru Besar/Pengetua melalui UI AgihTugas, bukan API key.

## Logo pada dokumen

`publishedLetter()` mengandungi `school.branding` bagi versi muktamad. Gunakan `assetBytes($id)` pada backend untuk mendapatkan PNG bagi logo/jata yang dirujuk. Jangan menggunakan logo semasa untuk menggantikan logo dalam dokumen muktamad lama. Jata Negara turut disertakan sebagai aset bersama aplikasi; logo sekolah kekal data runtime sekolah itu.
