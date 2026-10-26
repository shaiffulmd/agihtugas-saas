# Sambungan sistem lain (cth. Sekolah Koku / koku-multi) → UrusTugas

`UrusTugasClient.php` ialah klien PHP pelayan untuk API v1 UrusTugas. **Pilihan sahaja**: UrusTugas dan koku-multi berdiri sendiri, tiada kod, pangkalan data, kuki atau kunci dikongsi. Sambungan hanya melalui HTTPS + kunci API. Klien ini fail tunggal tanpa kebergantungan — salin ke sistem pemanggil.

## Konfigurasi (setiap sekolah)

1. Pentadbir sekolah di UrusTugas → **API & Integrasi** → cipta kunci (`read` untuk baca sahaja, `write` untuk segerak guru / lantik). Kunci `ut_…` dipaparkan sekali.
2. Simpan **URL utama sekolah** dan kunci pada pelayan sistem pemanggil (konfigurasi rahsia, bukan repositori/JavaScript), dipetakan kepada sekolah sistem itu:
   - mod laluan: `https://urustugas.my/CEA1234`
   - mod subdomain: `https://cea1234.urustugas.my`
   Gunakan bentuk utama yang dipaparkan di halaman API & Integrasi; klien tidak mengikut lencongan (kunci tidak dihantar ke lokasi lain) dan akan melaporkan URL yang betul.
3. Pakej sekolah mesti membenarkan API (lalai: Pro). Sekolah Free menerima `403 pro_required`.

```php
require_admin(); verify_csrf();                       // tindakan pentadbir sistem pemanggil sahaja
require_once '/lokasi/integrations/koku-multi/UrusTugasClient.php';

$cfg = $urusTugasConfig[sid()] ?? null;              // daripada konfigurasi pelayan, BUKAN input pengguna
if (!$cfg) throw new RuntimeException('Integrasi UrusTugas sekolah ini belum dikonfigurasi.');
$ut = new \UrusTugas\Integration\UrusTugasClient($cfg['school_url'], $cfg['api_key']);

foreach (teacher_options() as $row) {                // koku-multi: guru/admin aktif bagi sid()
    $t = $ut->syncKokuTeacher(sid(), $row);           // external_id = koku-multi:{sid}:{user_id}
}
$summary = $ut->summary();
```

`external_id` unik **dalam sekolah** UrusTugas; menghantar semula guru yang sama mengemas kini profil tanpa pendua. ID guru UrusTugas berbeza daripada ID pengguna sistem pemanggil — gunakan ID yang dipulangkan untuk `assign()`. Guru baharu tanpa kapasiti bermula 25 mata; kapasiti guru sedia ada tidak diubah oleh sync. Guru sedia ada tanpa `external_id` perlu dipautkan dahulu (PATCH /teachers/{id}); tiada padanan nama/e-mel secara andaian.

## Ralat

`UrusTugasError` membawa `apiCode` yang stabil: `unauthorized` (kunci salah / sekolah lain / dibatalkan), `insufficient_scope`, `pro_required`, `workflow_locked` (data dikunci semasa semakan), `duplicate_external_id`, `record_in_use`, `position_assigned`, `stale_or_used_plan`, `not_finalized`, `validation_error`, `rate_limited`, `maintenance`, `subscription_expired`. Tiada ulangan automatik — paparkan kepada pentadbir.

## Agihan automatik & data rasmi

```php
$plan = $ut->previewAllocation('vacant');            // paparkan rows/unfilled/warnings untuk semakan
$sum  = $ut->applyAllocation($plan['plan_id']);       // tindakan POST berasingan; sah 30 minit, sekali guna
```

`teacherAssignments()` / `all()` membaca data kerja (mungkin draf). Untuk tugas rasmi guna `publishedLetter($teacherId)` atau `publishedAllocation()`. Kelulusan & pemuktamadan hanya oleh Guru Besar / Pengetua melalui UI UrusTugas. Logo pada surat muktamad: `assetBytes($id)` untuk id dalam `school.branding` (jangan ganti dengan logo semasa).

Ujian klien terhadap pelayan sebenar: `php tests/integration_client.php URL_SEKOLAH` (lihat fail).
