# Log masuk tunggal melalui Portal Akaun

Pilihan dan dimatikan secara lalai. Bila diaktifkan, halaman log masuk sekolah memaparkan butang **Log masuk melalui Portal Akaun**, dan jubin UrusTugas dalam portal membawa pengguna terus masuk. Log masuk kata laluan tempatan **kekal berfungsi** sebagai sandaran.

## Aktifkan

1. Dalam Portal Akaun → Panel Pentadbir → **Servis (SSO)**, tambah servis:
   - URL asas sekolah: `https://DOMAIN/{KOD}` (mod laluan) atau `https://{kod}.DOMAIN` (mod subdomain). Ikut `TENANT_PRIMARY`.
   - URL webhook: `https://DOMAIN/sso-hook`
2. Salin tiga nilai yang dipaparkan (sekali sahaja) ke `.env` UrusTugas:
   ```
   SSO_PORTAL_URL=https://akaun.contoh.my
   SSO_CLIENT_ID=urustugas_…
   SSO_CLIENT_SECRET=…
   ```
3. Dalam portal, tambah sekolah, akaun pengguna (**e-mel sama** dengan e-mel dalam UrusTugas) dan langganan.

## Tingkah laku

| Perkara | Bagaimana |
|---|---|
| Padanan pengguna | `users(school_id, email)`. Pengguna mesti sudah wujud dan aktif dalam UrusTugas. Peranan (Pentadbir/PK/Ketua/Guru) kekal dalam UrusTugas. |
| 2FA | Jika portal mengesahkan 2FA (`amr: otp`), 2FA tempatan dilangkau. Jika tidak, aliran 2FA UrusTugas biasa terpakai. |
| Tukar kata laluan wajib | Dilangkau untuk sesi SSO kerana kata laluan tempatan tidak digunakan. |
| Langganan | Portal menghantar status ke `/sso-hook` dan dalam setiap log masuk, lalu `schools.expires_on` dikemas kini. Pro = tarikh tamat; Free/tiada = `NULL`. `school_plan()`, had Free dan `subscription_gate()` terus berfungsi tanpa diubah. |
| Log keluar | Sesi SSO: selepas log keluar, pengguna dihantar ke `PORTAL/logout` supaya sesi portal turut tamat. |
| Had IP / perisai | `user_ip_ok()` disemak. Log masuk SSO yang berjaya mengosongkan kiraan perisai. |
| Audit | `login_sso`, `login_sso_pwd` (portal tanpa 2FA → 2FA tempatan), `login_sso_failed` (dengan sebab). Perubahan langganan: `school_sso_subscription` dalam log platform. |

## Fail

- `app/sso.php`: semua logik (mula, panggil balik, webhook, kuki aliran, log keluar).
- `public_html/index.php`: laluan `?page=sso_mula`, `?page=sso`, `?page=sso_keluar`, `POST /sso-hook`; lantunan `sso_mula` merentas laman; pengecualian 2FA dan tukar kata laluan bagi sesi SSO.
- `app/views.php` (butang), `app/actions.php` (log keluar → portal), `public_html/assets/app.css` (`.sso-*`).

Protokol penuh dan perangkap pelayar (SameSite=Strict, CSP form-action): `portal-akaun/docs/SSO-PROTOKOL.md`.
