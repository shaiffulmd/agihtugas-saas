# SEO & perlindungan SEO poisoning — UrusTugas

Disesuaikan daripada koku-multi commit `ea94ff1` (`app/seo.php`) dan panduan `kit-web/PANDUAN.md` bahagian 7. Kod tidak disalin buta: UrusTugas tiada laman awam sekolah, jadi hanya halaman awam **platform** diindeks.

## 1. Dapatan kajian struktur

| Perkara | UrusTugas | Kesan pada SEO |
|---|---|---|
| Enjin templat | PHP biasa, `p_head()` (platform) & `views.php` (sekolah) | `p_head()` menerima tag SEO; tanpa tag → `noindex` seperti dahulu |
| Routing | `public_html/index.php` → `tenant_boot()` → `platform_main()` atau halaman sekolah | robots.txt/sitemap.xml dikendalikan sebelum semakan laluan 404 |
| Multi-penyewa | Ya — `/KOD/` atau `kod.DOMAIN` (bentuk bukan utama → 302) | Kanonik daripada `platform_domain()`, bukan pengepala Host |
| Halaman awam platform | `/`, `/direktori`, `/faq`, `/terma`, `/polisi`, `/daftar`, `/daftar/sah`, `/lapor-keselamatan` | 6 pertama boleh diindeks; 2 terakhir noindex |
| Laman awam sekolah | **Tiada** (`$siteOn=false` dalam `errors.php`) — sekolah hanya log masuk & sistem dalaman | Semua halaman sekolah `noindex, nofollow`; tiada sitemap sekolah |
| CSP | `script-src 'self'` (tiada skrip sebaris) | JSON-LD ialah blok data (`application/ld+json`), tidak dilaksana — dibenarkan |
| Kandungan pengguna | Hebahan (teks kaya, dalam sistem sahaja), tetapan sekolah (teks log masuk), nama sekolah (direktori awam) | `ugc` pada pautan, had pautan, nama sekolah tanpa URL |
| Pemeriksa integriti | Ada (`app/integrity.php`) | Ditambah pengesanan fail doorway HTML/.htaccess |
| Lantunan kuki SameSite rentas-laman | **Tiada** dalam `bootstrap.php` | Tiada pengecualian perangkak diperlukan (lihat §5) |
| Zip keluaran senarai putih | Ya (`release/zip-files.txt`) | `app/seo.php` ditambah |

## 2. Apa yang dilaksanakan

### Modul baharu `app/seo.php`
- `seo_meta($o)` — `description` (≤160 aksara, teks biasa), `robots`, `canonical`, Open Graph (`og:type/locale/site_name/title/description/url/image`) dan `twitter:card`. Halaman `noindex` tidak diberi kanonik/`og:url` dan menghantar `X-Robots-Tag: noindex, follow`.
- `seo_json_ld($data)` — JSON-LD dengan `JSON_HEX_TAG|JSON_HEX_AMP` (`</script>` dan `&` menjadi `<…`/`&`; disahkan dengan ujian).
- `seo_platform_abs($path)` — URL mutlak **hanya** daripada domain induk (Panel Platform → Subdomain Sekolah / `PLATFORM_DOMAIN`). Tanpa domain induk: tiada kanonik dan sitemap 404.
- `seo_too_many_links()` (had `SEO_MAX_LINKS=10`) dan `seo_has_link()` (URL/domain dalam teks yang dipaparkan di halaman terindeks).
- `seo_platform_robots()`, `seo_platform_sitemap()`, `seo_school_file()` — tanpa sesi, `Cache-Control: public, max-age=3600`, tiada `Set-Cookie`.

### Halaman awam platform (`lp_head($title,$directory,$seo)`)
| Halaman | Indeks | Kanonik | JSON-LD |
|---|---|---|---|
| `/` | ya; `?q=…` → noindex (teks carian dipaparkan semula) | `/` | Organization, WebSite, SoftwareApplication (tawaran Free/Pro dalam MYR) |
| `/direktori` | ya; `?q=`, negeri tidak wujud, tiada hasil → noindex | `/direktori[?negeri=…&halaman=n]` (tanpa `paparan`/`q`) | — |
| `/faq` | ya jika ada soalan; kosong → noindex | `/faq` | FAQPage |
| `/terma`, `/polisi` | ya | sendiri | — |
| `/daftar` | ya (GET); POST → noindex | `/daftar` | — |
| `/daftar/sah`, `/lapor-keselamatan` | noindex (tiada `path`) | — | — |

`platform_main()` kini hanya menghantar `X-Robots-Tag: noindex` untuk laluan selain halaman awam di atas (panel `/platform/`, titik akhir lain).

### robots.txt & sitemap.xml
- `/robots.txt` (domain induk): `Disallow` hanya titik akhir mesin — `/install.php`, `/kemaskini-hook`, `/bayar-callback`, `/hos-semak`, `/sihat.php`, `/*/api/` — dan baris `Sitemap:`. Halaman yang tidak mahu diindeks guna `noindex` (bukan Disallow) supaya enjin carian dapat membacanya.
- `/sitemap.xml`: `/`, `/direktori`, `/terma`, `/polisi`, serta `/faq` (jika ada soalan) dan `/daftar` (jika pendaftaran dibuka). **Halaman sekolah tidak disenaraikan** kerana semuanya halaman log masuk noindex (berbeza daripada koku-multi yang ada laman awam sekolah).
- `kod.DOMAIN/robots.txt` (mod subdomain): `Disallow: /api/`, tanpa sitemap. `/KOD/robots.txt` & `/KOD/sitemap.xml` → 404 (robots.txt hanya sah di akar hos).
- `public_html/index.php` mentakrif `NO_SESSION` untuk kedua-dua fail ini.

### Sistem sekolah & ralat
- `views.php`: `<meta name="robots" content="noindex, nofollow">`; `index.php` menghantar `X-Robots-Tag: noindex, nofollow` sebelum paparan sekolah (log masuk, 2FA, papan pemuka, modul).
- `errors.php`: setiap ralat ≥400 menghantar `X-Robots-Tag: noindex` (selain meta sedia ada) — tiada kanonik.

### Kandungan pengguna (perlindungan SEO poisoning)
- `richtext.php`: pautan teks kaya (hebahan) kini `rel="noopener nofollow ugc"`.
- `hebahan.php`: hebahan ditolak jika >10 pautan (`<a>` atau URL dalam teks).
- `settings.php`: nama sekolah tidak boleh mengandungi URL; medan teks lain (tajuk/teks log masuk, banner, slogan) ditolak jika >10 pautan.
- `registration.php`: nama sekolah pendaftaran awam tidak boleh mengandungi URL/domain — nama ini dipaparkan dalam direktori awam yang diindeks.
- Pautan footer `lp_links()` diurus oleh pentadbir platform (dipercayai) — kekal tanpa `nofollow`.

### Integriti fail
- `integrity.php`: `INTEGRITY_DOORWAY` — fail `.html/.htm/.shtml/.xhtml` atau `.htaccess` dalam `public_html/assets` dan folder sementara awam dilaporkan sebagai mencurigakan (halaman doorway / spam SEO yang ditanam). Fail baharu di tempat lain dalam `public_html/` sudah dikesan sebagai "baharu" oleh semakan asas.

## 3. Semakan keselamatan lain
- **Open redirect** — tiada ditemui. `redirect()` hanya menerima laluan dalaman tetap; `tenant_location()` menolak `//`; lencongan bentuk URL utama dibina semula dengan `school_url()` (garis condong awal dibuang); `p_next` dibina daripada `p` yang ditapis `^[a-z]+$`; URL gerbang bayaran datang daripada API gerbang, bukan input pengguna. `/KOD//evil.example` → 404.
- **404 lembut** — laluan tidak wujud → 404 sebenar (platform & sekolah). `?page=` tidak dikenali tanpa log masuk memaparkan halaman log masuk (200) tetapi noindex, jadi tidak diindeks; parameter asing pada halaman awam platform tidak dipaparkan semula dan kanonik menunjuk ke URL bersih.
- **Pengepala Host palsu** — diuji: `Host: evil.example` masih menghasilkan kanonik, `og:url` dan `Sitemap:` daripada domain induk.

## 4. Fail diubah

| Fail | Perubahan |
|---|---|
| `app/seo.php` (baharu) | Modul SEO |
| `public_html/index.php` | `NO_SESSION` untuk robots/sitemap, muat `seo`, `seo_school_file()`, `X-Robots-Tag` sistem sekolah |
| `app/platform.php` | `p_head(..., $seo)`, `platform_main()` robots/sitemap & noindex terpilih |
| `app/landing.php` | `lp_head(..., $seo)`, JSON-LD halaman utama, noindex `?q=` |
| `app/directory.php` | Kanonik bersih, noindex carian/negeri tidak sah/kosong |
| `app/faq.php` | FAQPage JSON-LD, noindex jika kosong |
| `app/legal.php`, `app/registration.php` | Kanonik & description; nama sekolah tanpa URL |
| `app/views.php`, `app/errors.php` | `noindex, nofollow` / `X-Robots-Tag` ralat |
| `app/richtext.php` | `nofollow ugc` |
| `app/hebahan.php`, `app/settings.php` | Had pautan / nama sekolah tanpa URL |
| `app/integrity.php` | Pengesanan fail doorway |
| `release/zip-files.txt` | `app/seo.php` |

## 5. Ujian (29 Okt 2026)
- `sh tests/run_all.sh statik` — `php -l` semua fail, audit SQL penyewa, simbol tidak ditakrif: **lulus**.
- Pelayan `php -S 127.0.0.1:8091` + MariaDB ujian sementara (`DEMO_MODE=1`, mod laluan):
  - `curl /robots.txt`, `/sitemap.xml` — 200, `text/plain` / `application/xml`, tiada `Set-Cookie`, URL mutlak domain induk.
  - Meta setiap halaman awam: description, robots, kanonik, OG, Twitter, JSON-LD (jadual §2) — sepadan.
  - `/?q=…`, `/direktori?q=…`, negeri tidak wujud, `/faq` kosong, `/daftar/sah`, `/lapor-keselamatan`, `/platform/`, 404 — `noindex`, tiada kanonik.
  - `/CEA1234/`, `/CEA1234/?page=spam` — `noindex, nofollow` (meta + pengepala); `/CEA1234/robots.txt` 404; mod subdomain `cea1234.…/robots.txt` 200.
  - JSON-LD dengan `</script>` & `&` dalam FAQ/teks halaman utama — di-escape.
  - Fail `public_html/assets/*.html` dikesan oleh `integrity_suspicious()`; `seo_has_link()` / `seo_too_many_links()` diuji.
  - `tests/public_demo.py` 33 lulus; `tests/multi_tenant_integration.py` 57 lulus. (Nota: `public_demo.py` memerlukan ≥10 FAQ dalam pangkalan data ujian — ia bergantung pada data, bukan kod.)

## 6. Belum dibuat / cadangan susulan
- Halaman awam platform masih memulakan sesi (kuki `urustugas_platform`) — sama seperti koku-multi; tidak menjejaskan pengindeksan.
- Kuki sesi `SameSite=Strict`: navigasi rentas-laman (cth. pautan e-mel) ke sekolah boleh menulis ganti kuki sesi sedia ada. koku-multi membaikinya dengan lantunan meta refresh (commit `433fd79`) yang mengecualikan perangkak; jika dibawa ke UrusTugas, kecualikan User-Agent perangkak seperti di koku-multi.
- Lencongan bentuk URL bukan utama kekal 302 (bentuk utama boleh berubah ikut langganan Pro); halaman sekolah noindex, jadi tiada kesan SEO.
