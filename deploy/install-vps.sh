#!/usr/bin/env bash
set -euo pipefail
# No downloads, package installation, service reload or firewall changes are performed.
if [[ $# -lt 3 || $# -gt 4 ]]; then
  echo "Guna: sudo bash deploy/install-vps.sh DOMAIN PHP_FPM_SOCKET WEB_USER [apache|nginx]" >&2
  echo "Contoh: sudo bash deploy/install-vps.sh tugas.sekolah.edu.my /run/php/php8.3-fpm.sock www-data" >&2
  exit 1
fi
vps_domain=$1
vps_socket=$2
vps_user=$3
vps_server=${4:-apache}
[[ $vps_server == apache || $vps_server == nginx ]] || { echo 'Pilih apache atau nginx.' >&2; exit 1; }
vps_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
vps_webroot="$(dirname -- "$vps_root")/public_html"
[[ -f $vps_webroot/index.php && -d $vps_webroot/assets ]] || { echo 'Folder public_html pakej tidak ditemui di sebelah agihtugas-private.' >&2; exit 1; }
[[ $EUID -eq 0 ]] || { echo 'Jalankan sebagai root/sudo untuk menetapkan pemilik fail.' >&2; exit 1; }
[[ $vps_domain =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$ && $vps_domain == *.* && $vps_domain != *..* ]] || { echo 'Domain tidak sah.' >&2; exit 1; }
[[ $vps_socket =~ ^/[a-zA-Z0-9_./-]+$ && -S $vps_socket ]] || { echo 'Soket PHP-FPM tidak ditemui.' >&2; exit 1; }
[[ $vps_root =~ ^/[a-zA-Z0-9_./-]+$ ]] || { echo 'Laluan pemasangan mengandungi aksara tidak disokong.' >&2; exit 1; }
id "$vps_user" >/dev/null 2>&1 || { echo 'Pengguna web tidak ditemui.' >&2; exit 1; }
[[ $(id -u "$vps_user") -ne 0 && $vps_root != / ]] || { echo 'Gunakan pengguna PHP tanpa kuasa root dan folder aplikasi khusus.' >&2; exit 1; }
command -v runuser >/dev/null || { echo 'Utiliti runuser diperlukan.' >&2; exit 1; }
command -v php >/dev/null || { echo 'Pasang PHP CLI dan modul dahulu.' >&2; exit 1; }
[[ ! -e $vps_root/config/local.php && ! -e $vps_root/storage/agihtugas.sqlite ]] || { echo 'Pemasangan sudah wujud; data tidak ditindih.' >&2; exit 1; }
[[ ! -e $vps_root/deploy/$vps_server.generated.conf ]] || { echo 'Konfigurasi pelayan sudah wujud.' >&2; exit 1; }
cd "$vps_root"
php bin/install.php --check --webroot="$vps_webroot"
# Only runtime directories are writable by PHP; application code stays root-owned.
chown -R root:root "$vps_root"
find "$vps_root" -type d -exec chmod 755 {} +
find "$vps_root" -type f -exec chmod 644 {} +
chown "$vps_user" storage config
chmod 700 storage config
runuser -u "$vps_user" -- php bin/install.php --webroot="$vps_webroot"
sed -e "s|__DOMAIN__|$vps_domain|g" -e "s|__WEBROOT__|$vps_webroot|g" -e "s|__SOCKET__|$vps_socket|g" "deploy/$vps_server.conf" > "deploy/$vps_server.generated.conf"
chmod 640 "deploy/$vps_server.generated.conf"
printf '\nPemasangan aplikasi siap. Konfigurasi %s: %s/deploy/%s.generated.conf\n' "$vps_server" "$vps_root" "$vps_server"
if [[ $vps_server == apache ]]; then
  printf 'Aktifkan modul proxy, proxy_fcgi, rewrite dan vhost. Jalankan apachectl configtest sebelum muat semula Apache.\n'
else
  printf 'Salin ke konfigurasi Nginx dan jalankan nginx -t sebelum muat semula Nginx.\n'
fi
printf 'Aktifkan HTTPS sebelum membuka persediaan akaun. Rujuk PEMASANGAN.md.\n'
