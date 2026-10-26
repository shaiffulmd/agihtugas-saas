#!/bin/sh
# Bina zip keluaran: semua fail dalam release/zip-files.txt, folder 755, fail 644 (untuk cPanel).
# Guna: sh release/build-zip.sh   → urustugas-v<APP_VERSION>-<YYYYMMDD>.zip di akar projek.
# Tambah fail baharu ke release/zip-files.txt (satu laluan setiap baris; folder berakhir dengan "/").
set -e
cd "$(dirname "$0")/.."
V=$(sed -n "s/^const APP_VERSION = '\(.*\)';/\1/p" app/install.php)
Z="urustugas-v$V-$(date +%Y%m%d).zip"; L=release/zip-files.txt
ST=$(mktemp -d); trap 'rm -rf "$ST"' EXIT
missing=$(grep -v '/$' "$L" | while read -r f; do [ -e "$f" ] || echo "$f"; done)
[ -z "$missing" ] || { echo "Fail tiada:"; echo "$missing"; exit 1; }
# Fail masa jalan yang dijejak git tetapi tertinggal daripada senarai = laman rosak di hosting (pernah berlaku). Gagal awal.
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  forgot=$(git ls-files app bin public_html database | grep -vxF -f "$L" || true)
  [ -z "$forgot" ] || { echo "Fail dijejak git tetapi tiada dalam $L:"; echo "$forgot"; exit 1; }
fi
grep -v '/$' "$L" | rsync -a --files-from=- ./ "$ST/"
grep '/$' "$L" | (cd "$ST" && xargs mkdir -p)
find "$ST" -type d -exec chmod 755 {} + ; find "$ST" -type f -exec chmod 644 {} +
rm -f "$Z"; (cd "$ST" && zip -q -X "$OLDPWD/$Z" -@ < "$OLDPWD/$L")
unzip -tq "$Z"; unzip -Z1 "$Z" | grep -q '^\.env$' && { echo "RALAT: .env dalam zip"; exit 1; }
echo "$Z: $(unzip -Z1 "$Z" | grep -vc '/$') fail"
