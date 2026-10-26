#!/usr/bin/env bash
# Naik taraf MariaDB dengan selamat (CVE MariaDB Oktober 2026) — untuk pelayan Linux (Debian/Ubuntu: apt, RHEL/Alma/Rocky: dnf/yum).
#
# Aliran:
#   1. Semakan awal   : root, pengurus pakej, versi semasa & versi dibaiki, ruang cakera, cPanel/Plesk (urus melalui panel).
#   2. Bekukan tulisan: SET GLOBAL read_only=ON (pengguna aplikasi tiada SUPER → tulisan disekat seketika; bacaan diteruskan).
#   3. Backup         : mariadb-dump semua pangkalan data (+ routines/events/triggers, pengguna & grant) → .sql.gz + SHA-256 + bilangan baris.
#   4. Uji backup     : pulih ke instance MariaDB SEMENTARA yang terasing (tanpa rangkaian, datadir sendiri), bandingkan jadual & bilangan baris.
#                       Backup yang gagal diuji → naik taraf DIBATALKAN dan tulisan dibuka semula.
#   5. Naik taraf     : pakej MariaDB sahaja (versi calon mesti ≥ versi dibaiki), restart, mariadb-upgrade.
#   6. Semakan selepas: versi baharu, SELECT 1, semakan jadual (mariadb-check), read_only dimatikan, log ralat.
#
# Guna:
#   sudo bash bin/mariadb-kemaskini.sh                 # semak + backup + uji + naik taraf (meminta pengesahan)
#   sudo bash bin/mariadb-kemaskini.sh --backup-sahaja # backup + uji sahaja, tiada naik taraf (boleh dijadualkan)
#   sudo bash bin/mariadb-kemaskini.sh --semak         # laporan sahaja, tiada perubahan
#   Pilihan: --dir /var/backups/mariadb   --simpan 5 (bil. backup disimpan)   --ya (tanpa soalan)
#            --tanpa-beku (jangan read_only; bilangan baris mungkin berbeza jika ada tulisan)   --terima-backport (lihat di bawah)
#            --versi X.Y.Z  pilih versi tertentu daripada repo (lalai: menu pilihan; dengan --ya: versi terbaharu siri semasa)
#            --siri X.Y     naik ke siri LTS lebih baharu (cth. 11.8): repo rasmi MariaDB ditukar melalui mariadb_repo_setup.
#            --repo-rasmi   jika repo distro belum ada versi dibaiki (cth. Ubuntu 24.04 masih 10.11.14), tambah repo rasmi MariaDB
#                           bagi siri SAMA tanpa bertanya (tanpa pilihan ini skrip menawarkannya secara interaktif).
#                           Naik taraf siri utama — uji aplikasi selepasnya; turun siri TIDAK disokong (pulih daripada backup).
#   Kelayakan (dicuba mengikut turutan): MARIADB_DEFAULTS=/laluan/.my.cnf, soket root (unix_socket), /root/.my.cnf,
#   /etc/mysql/debian.cnf, kemudian kata laluan diminta (tidak dipaparkan & tidak disimpan; fail sementara 600 dipadam bila tamat).
#   Pengguna lain selain root: MARIADB_USER=nama (perlu keistimewaan penuh: dump semua DB, SET GLOBAL, SHUTDOWN tidak diperlukan).
#
# Pakej distro (Debian/Ubuntu/RHEL) kadangkala membaiki CVE tanpa menaikkan nombor versi upstream (backport). Jika calon distro lebih
# baharu tetapi nombornya di bawah senarai, semak changelog pakej, kemudian jalankan semula dengan --terima-backport.
#
# Pemulihan jika naik taraf gagal: pasang semula versi lama pakej, kemudian
#   gunzip -c <backup>.sql.gz | mariadb      (sebagai root)    — lihat fail PULIH.txt dalam folder backup.

set -Eeuo pipefail
umask 077

# Versi dibaiki (selaras dengan DB_CVE_FIXED dalam app/integrity.php).
FIXED="10.6:10.6.28 10.11:10.11.19 11.4:11.4.13 11.8:11.8.9 12.3:12.3.3 13.0:13.0.2"

MODE=penuh; DIR=/var/backups/mariadb; KEEP=5; YES=0; FREEZE=1; BACKPORT=0; WANT=""; NEWSERIES=""; OFFICIAL=0
while [ $# -gt 0 ]; do
  case "$1" in
    --backup-sahaja) MODE=backup ;;
    --semak) MODE=semak ;;
    --dir) DIR="${2:?--dir perlukan laluan}"; shift ;;
    --simpan) KEEP="${2:?--simpan perlukan nombor}"; shift ;;
    --ya) YES=1 ;;
    --tanpa-beku) FREEZE=0 ;;
    --terima-backport) BACKPORT=1 ;;
    --versi) WANT="${2:?--versi perlukan X.Y.Z}"; shift ;;
    --siri) NEWSERIES="${2:?--siri perlukan X.Y}"; shift ;;
    --repo-rasmi) OFFICIAL=1 ;;
    -h|--help) sed -n '2,38p' "$0"; exit 0 ;;
    *) echo "Pilihan tidak dikenali: $1" >&2; exit 2 ;;
  esac; shift
done

C_R=$'\033[31m'; C_G=$'\033[32m'; C_Y=$'\033[33m'; C_0=$'\033[0m'; [ -t 1 ] || { C_R=; C_G=; C_Y=; C_0=; }
log(){ printf '%s %s\n' "$(date '+%H:%M:%S')" "$*"; }
ok(){ log "${C_G}✔${C_0} $*"; }
warn(){ log "${C_Y}!${C_0} $*"; }
die(){ log "${C_R}✘ $*${C_0}" >&2; exit 1; }
# die dengan 20 baris akhir log (untuk diagnosis di pelayan).
die_log(){ [ -s "$2" ] && { log "--- $(basename "$2") (20 baris akhir):" >&2; tail -n 20 "$2" | sed 's/^/    /' >&2; }; die "$1"; }
confirm(){ [ "$YES" = 1 ] && return 0; printf '%s [taip YA]: ' "$1"; read -r a; [ "$a" = "YA" ]; }

DEF=(); [ -n "${MARIADB_DEFAULTS:-}" ] && DEF=(--defaults-extra-file="$MARIADB_DEFAULTS")
CLI=$(command -v mariadb || command -v mysql || true); DUMP=$(command -v mariadb-dump || command -v mysqldump || true)
[ -n "$CLI" ] && [ -n "$DUMP" ] || die "Klien mariadb / mariadb-dump tidak dijumpai."
sql(){ "$CLI" ${DEF[@]+"${DEF[@]}"} -N -B -e "$1"; }
vercmp_lt(){ [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" = "$1" ]; } # $1 < $2
fixed_for(){ local s="$1" p; for p in $FIXED; do [ "${p%%:*}" = "$s" ] && { echo "${p#*:}"; return; }; done; echo ""; }

FROZEN=0; TMPI=""; TMPPID=""; SOCKD=""; OUT=""; CRED=""
cleanup(){
  local rc=$?
  if [ -n "$TMPPID" ] && kill -0 "$TMPPID" 2>/dev/null; then kill "$TMPPID" 2>/dev/null || true; wait "$TMPPID" 2>/dev/null || true; fi
  if [ -n "$TMPI" ]; then [ $rc -ne 0 ] && [ -n "$OUT" ] && cp "$TMPI"/*.log "$OUT/" 2>/dev/null; rm -rf "$TMPI"; fi
  [ -n "$SOCKD" ] && rm -rf "$SOCKD"
  [ -n "$CRED" ] && rm -f "$CRED"
  if [ "$FROZEN" = 1 ]; then sql "SET GLOBAL read_only=OFF" 2>/dev/null && warn "read_only dimatikan semula (tulisan dibuka)." || warn "GAGAL mematikan read_only — jalankan: mariadb -e 'SET GLOBAL read_only=OFF'"; fi
  [ $rc -ne 0 ] && log "${C_R}Skrip berhenti (kod $rc). Tiada pakej dinaik taraf melainkan dinyatakan di atas.${C_0}"
  exit $rc
}
trap cleanup EXIT

# ---------------- 1. Semakan awal ----------------
log "== Semakan awal =="
if [ "$MODE" != semak ] && [ "$(id -u)" != 0 ] && [ "$(uname)" = Linux ]; then die "Jalankan sebagai root (sudo)."; fi
# Kelayakan: cuba tanpa soalan dahulu, kemudian minta kata laluan (root dengan kata laluan, bukan unix_socket).
creds_ok(){ sql "SELECT 1" >/dev/null 2>&1; }
MUSER=${MARIADB_USER:-root}
if [ -n "${MARIADB_DEFAULTS:-}" ]; then creds_ok || die "Tidak dapat log masuk dengan $MARIADB_DEFAULTS."
elif creds_ok; then ok "Kelayakan: soket ($(id -un))."
else
  for f in /root/.my.cnf /etc/mysql/debian.cnf; do
    [ -r "$f" ] || continue; DEF=(--defaults-extra-file="$f"); creds_ok && { ok "Kelayakan: $f"; break; }; DEF=()
  done
  if ! creds_ok; then
    [ -t 0 ] || die "Tidak dapat log masuk ke MariaDB. Sediakan /root/.my.cnf ([client] user=… password=…) atau MARIADB_DEFAULTS=/laluan/fail.cnf (chmod 600)."
    for cuba in 1 2 3; do
      printf 'Kata laluan MariaDB untuk %s@localhost (tidak dipaparkan, tidak disimpan): ' "$MUSER"; IFS= read -rs PW; echo
      CRED=$(mktemp); chmod 600 "$CRED"; PE=${PW//\\/\\\\}; PE=${PE//\"/\\\"}
      printf '[client]\nuser=%s\npassword="%s"\n' "$MUSER" "$PE" > "$CRED"; unset PW PE
      DEF=(--defaults-extra-file="$CRED"); creds_ok && { ok "Kelayakan: kata laluan $MUSER (sesi ini sahaja)."; break; }
      rm -f "$CRED"; CRED=""; DEF=(); warn "Log masuk gagal ($cuba/3)."
    done
    creds_ok || die "Tidak dapat log masuk ke MariaDB sebagai $MUSER."
  fi
fi
RAW=$(sql "SELECT VERSION()"); echo "$RAW" | grep -qi mariadb || die "Pelayan ini bukan MariaDB ($RAW). CVE MariaDB Okt 2026 tidak terpakai; skrip ini untuk MariaDB sahaja."
CUR=$(echo "$RAW" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+-MariaDB' | head -1); CUR=${CUR%-MariaDB}
SERIES=$(echo "$CUR" | cut -d. -f1,2); NEED=$(fixed_for "$SERIES")
log "Versi semasa: $RAW (siri $SERIES)"
if [ -z "$NEED" ]; then
  if vercmp_lt "13.0" "$SERIES"; then NEED="$CUR"; ok "Siri $SERIES lebih baharu daripada senarai pembaikan."
  else warn "Siri $SERIES tidak lagi menerima pembaikan. Naik taraf ke siri LTS (10.11 / 11.4 / 11.8) — perlu repo MariaDB siri baharu (mariadb_repo_setup), bukan skrip ini."; [ "$MODE" = penuh ] && MODE=backup; fi
elif vercmp_lt "$CUR" "$NEED"; then warn "TERDEDAH: perlu $NEED atau lebih baharu."
else ok "Sudah dibaiki ($CUR ≥ $NEED)."; [ "$MODE" = penuh ] && { log "Tiada naik taraf diperlukan; teruskan dengan backup + uji sahaja."; MODE=backup; }; fi

PANEL=""; [ -d /usr/local/cpanel ] && PANEL=cPanel; [ -d /usr/local/psa ] && PANEL=Plesk
if [ -n "$PANEL" ] && [ "$MODE" = penuh ]; then warn "$PANEL dikesan: naik taraf MariaDB melalui panel ($PANEL › MySQL/MariaDB Upgrade). Skrip ini hanya akan membuat backup + uji."; MODE=backup; fi

PM=""; command -v apt-get >/dev/null && PM=apt; [ -z "$PM" ] && command -v dnf >/dev/null && PM=dnf; [ -z "$PM" ] && command -v yum >/dev/null && PM=yum
SVC=""; if command -v systemctl >/dev/null; then for s in mariadb mysql mysqld; do systemctl list-unit-files "$s.service" 2>/dev/null | grep -q "^$s.service" && { SVC=$s; break; }; done; fi
CAND=""; CANDFULL=""
# Senarai versi mariadb-server dalam repo: "x.y.z<TAB>rentetan-versi-pakej", terbaharu dahulu.
list_versions(){
  if [ "$PM" = apt ]; then apt-cache madison mariadb-server 2>/dev/null | awk -F'|' '{gsub(/ /,"",$2); print $2}' | while read -r full; do v=$(echo "$full" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1); [ -n "$v" ] && printf '%s\t%s\n' "$v" "$full"; done
  else $PM -q list --showduplicates --available 'MariaDB-server' 'mariadb-server' 2>/dev/null | awk 'NF>=2 && $1 ~ /server/ {print $2}' | while read -r full; do v=$(echo "$full" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1); [ -n "$v" ] && printf '%s\t%s\n' "$v" "$full"; done; fi | sort -t$'\t' -k1,1Vr -u
}
if [ "$MODE" = penuh ]; then
  [ -n "$PM" ] || die "Pengurus pakej apt/dnf/yum tidak dijumpai."
  [ -n "$SVC" ] || die "Perkhidmatan systemd mariadb/mysql tidak dijumpai."
  # Repo rasmi MariaDB (r.mariadb.com) bagi satu siri; konfigurasi repo sahaja — tiada pakej dipasang di sini.
  add_repo(){
    local RS; RS=$(mktemp); curl -fsSL https://r.mariadb.com/downloads/mariadb_repo_setup -o "$RS" || die "Gagal memuat turun mariadb_repo_setup."
    bash "$RS" --mariadb-server-version="mariadb-$1" || die "mariadb_repo_setup gagal."; rm -f "$RS"; ok "Repo rasmi MariaDB $1 ditambah."
  }
  refresh(){ log "Mengemas kini senarai pakej ($PM)…"; if [ "$PM" = apt ]; then apt-get update -qq; else $PM -q makecache >/dev/null 2>&1 || true; fi
    ALL=$(list_versions); [ -n "$ALL" ] || die "Tiada versi mariadb-server ditemui dalam repo."; }
  # Pilihan sah: lebih baharu daripada versi semasa, ≥ versi dibaiki (atau backport jika --terima-backport), siri sasaran sahaja.
  compute_opts(){ OPTS=$(echo "$ALL" | while IFS=$'\t' read -r v full; do
    [ "$(echo "$v" | cut -d. -f1,2)" = "$SERIES_T" ] || continue
    vercmp_lt "$CUR" "$v" || continue
    if vercmp_lt "$v" "$NEED"; then [ "$BACKPORT" = 1 ] && printf '%s\t%s\tbackport?\n' "$v" "$full"; else printf '%s\t%s\tdibaiki\n' "$v" "$full"; fi
  done); }
  if [ -n "$NEWSERIES" ]; then
    [ -n "$(fixed_for "$NEWSERIES")" ] || die "Siri $NEWSERIES tidak disokong skrip ini. Pilih salah satu: $(for p in $FIXED; do printf '%s ' "${p%%:*}"; done)"
    vercmp_lt "$SERIES" "$NEWSERIES" || die "Siri $NEWSERIES tidak lebih baharu daripada $SERIES. Turun siri tidak disokong."
    warn "Naik taraf SIRI UTAMA $SERIES → $NEWSERIES: repo rasmi MariaDB akan ditukar (mariadb_repo_setup daripada r.mariadb.com)."
    confirm "Tukar repo MariaDB ke siri $NEWSERIES?" || die "Dibatalkan."
    add_repo "$NEWSERIES"; NEED=$(fixed_for "$NEWSERIES"); SERIES_T=$NEWSERIES
  else SERIES_T=$SERIES; fi
  refresh; compute_opts
  if [ -z "$OPTS" ] && [ "$BACKPORT" = 0 ] && [ -z "$NEWSERIES" ]; then
    log "Versi dalam repo: $(echo "$ALL" | cut -f1 | head -8 | tr '\n' ' ')"
    warn "Repo semasa (distro) belum ada $SERIES_T yang dibaiki (≥ $NEED)."
    log "   1) Tambah repo rasmi MariaDB siri $SERIES_T (r.mariadb.com) dan naik ke versi dibaiki  ← disyorkan"
    log "      Pakej distro digantikan dengan pakej MariaDB.org siri yang sama; data & /etc/mysql dikekalkan; backup diuji dahulu."
    log "   2) Batal (tunggu kemas kini keselamatan distro, kemudian jalankan semula)"
    if [ "$OFFICIAL" = 1 ]; then pick=1
    elif [ "$YES" = 1 ] || ! [ -t 0 ]; then die "Gunakan --repo-rasmi untuk menambah repo rasmi MariaDB tanpa soalan."
    else printf 'Pilih [1/2]: '; read -r pick; fi
    [ "${pick:-}" = 1 ] || die "Dibatalkan. Tiada perubahan dibuat."
    add_repo "$SERIES_T"; refresh; compute_opts
  fi
  if [ -z "$OPTS" ]; then
    log "Versi dalam repo: $(echo "$ALL" | cut -f1 | head -8 | tr '\n' ' ')"
    if [ "$BACKPORT" = 1 ] && [ "$PM" = apt ] && apt list --upgradable 2>/dev/null | grep -q '^mariadb-server/'; then
      CANDFULL=$(apt-cache policy mariadb-server | awk '/Candidate:/{print $2}'); CAND=$CUR; warn "Kemas kini distro (backport) $CANDFULL diterima atas --terima-backport."
    else die "Tiada versi $SERIES_T yang dibaiki (≥ $NEED) walaupun dalam repo rasmi. Semak https://mariadb.org/download atau guna --siri untuk siri lebih baharu."; fi
  else
    log "Versi tersedia untuk dinaik taraf:"
    n=0; echo "$OPTS" | while IFS=$'\t' read -r v full tag; do n=$((n+1)); printf '   %d) %-10s %-34s %s%s\n' "$n" "$v" "$full" "$tag" "$([ $n = 1 ] && echo '  ← disyorkan')"; done
    # Siri LTS lain yang lebih baharu (memerlukan --siri).
    MORE=""; for p in $FIXED; do sr=${p%%:*}; vercmp_lt "$SERIES_T" "$sr" && MORE="$MORE $sr"; done
    [ -n "$MORE" ] && log "Siri lebih baharu juga boleh dipilih dengan --siri:$MORE (naik taraf siri utama)."
    if [ -n "$WANT" ]; then PICK=$(echo "$OPTS" | awk -F'\t' -v w="$WANT" '$1==w' | head -1); [ -n "$PICK" ] || die "Versi $WANT tiada dalam pilihan di atas."
    elif [ "$YES" = 1 ]; then PICK=$(echo "$OPTS" | head -1)
    else printf 'Pilih nombor versi [1]: '; read -r k; k=${k:-1}; echo "$k" | grep -qE '^[0-9]+$' || die "Pilihan tidak sah."; PICK=$(echo "$OPTS" | sed -n "${k}p"); [ -n "$PICK" ] || die "Pilihan tidak sah."; fi
    CAND=$(echo "$PICK" | cut -f1); CANDFULL=$(echo "$PICK" | cut -f2)
  fi
  ok "Versi sasaran: $CAND ($CANDFULL)"
fi

DATADIR=$(sql "SELECT @@datadir"); USED_KB=$(du -sk "$DATADIR" 2>/dev/null | awk '{print $1}' || echo 0)
mkdir -p "$DIR"; chmod 700 "$DIR"; FREE_KB=$(df -Pk "$DIR" | awk 'NR==2{print $4}')
log "Saiz datadir ~$((USED_KB/1024)) MB; ruang kosong di $DIR: $((FREE_KB/1024)) MB"
[ "${USED_KB:-0}" -gt 0 ] && [ "$FREE_KB" -lt $((USED_KB*2)) ] && die "Ruang cakera tidak mencukupi untuk backup + uji pulih (perlu ~2× saiz datadir)."
[ "$MODE" = semak ] && { ok "Mod semak sahaja — tiada perubahan dibuat."; exit 0; }

case "$MODE" in penuh) confirm "Teruskan: bekukan tulisan, backup, uji, kemudian naik taraf MariaDB $CUR → $CAND?" || die "Dibatalkan.";;
                backup) confirm "Teruskan dengan backup + uji backup (tiada naik taraf)?" || die "Dibatalkan.";; esac

# ---------------- 2. Bekukan tulisan ----------------
STAMP=$(date +%Y%m%d-%H%M%S); OUT="$DIR/mariadb-$STAMP"; mkdir -p "$OUT"
DBS=$(sql "SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','performance_schema','sys','mysql') ORDER BY 1")
if [ "$FREEZE" = 1 ]; then
  sql "SET GLOBAL read_only=ON"; FROZEN=1; sql "FLUSH TABLES" >/dev/null 2>&1 || true
  ok "Tulisan dibekukan (read_only=ON). Laman masih boleh dibaca; simpan data akan gagal seketika."
else warn "--tanpa-beku: bilangan baris boleh berbeza jika ada tulisan semasa backup."; fi

# ---------------- 3. Backup ----------------
log "== Backup =="
: > "$OUT/bilangan-sumber.txt"
for db in $DBS; do
  for t in $(sql "SELECT table_name FROM information_schema.tables WHERE table_schema='$db' AND table_type='BASE TABLE' ORDER BY 1"); do
    printf '%s.%s\t%s\n' "$db" "$t" "$(sql "SELECT COUNT(*) FROM \`$db\`.\`$t\`")" >> "$OUT/bilangan-sumber.txt"
  done
done
DUMPOPTS=(--single-transaction --quick --routines --events --triggers --hex-blob --default-character-set=utf8mb4 --add-drop-database)
# --system=users (MariaDB 10.2+) menyimpan pengguna & grant sebagai CREATE USER/GRANT (mudah dipulih merentas versi).
"$DUMP" ${DEF[@]+"${DEF[@]}"} --system=users >"$OUT/pengguna-grant.sql" 2>"$OUT/dump-pengguna.err" || warn "Dump pengguna & grant gagal (lihat dump-pengguna.err); pangkalan data mysql tetap dalam dump penuh."
# shellcheck disable=SC2086
"$DUMP" ${DEF[@]+"${DEF[@]}"} "${DUMPOPTS[@]}" --databases $DBS mysql 2>"$OUT/dump.err" | gzip -6 > "$OUT/semua.sql.gz"
[ "${PIPESTATUS[0]}" = 0 ] || die "mariadb-dump gagal — lihat $OUT/dump.err"
gzip -t "$OUT/semua.sql.gz" || die "Fail backup rosak (gzip -t gagal)."
( cd "$OUT" && sha256sum semua.sql.gz pengguna-grant.sql > SHA256SUMS 2>/dev/null || shasum -a 256 semua.sql.gz pengguna-grant.sql > SHA256SUMS )
for f in /etc/mysql /etc/my.cnf /etc/my.cnf.d; do [ -e "$f" ] && cp -a "$f" "$OUT/" 2>/dev/null || true; done
cat > "$OUT/PULIH.txt" <<EOF
Backup MariaDB $RAW — $STAMP
Pulih penuh (sebagai root, pelayan MariaDB berjalan):
  sha256sum -c SHA256SUMS
  gunzip -c semua.sql.gz | mariadb
  mariadb < pengguna-grant.sql        # jika pengguna/grant perlu dipulih berasingan
Turun versi pakej (jika naik taraf gagal): pasang versi $CUR daripada repo/cache pakej, kemudian pulih seperti di atas.
Pangkalan data: $DBS
EOF
ok "Backup: $OUT/semua.sql.gz ($(du -h "$OUT/semua.sql.gz" | cut -f1)), $(wc -l < "$OUT/bilangan-sumber.txt" | tr -d ' ') jadual."

# ---------------- 4. Uji backup (instance sementara terasing) ----------------
log "== Uji backup: pulih ke instance sementara =="
MDB=$(command -v mariadbd || command -v mysqld || ls /usr/sbin/mariadbd /usr/sbin/mysqld /usr/libexec/mysqld 2>/dev/null | head -1 || true)
INST=$(command -v mariadb-install-db || command -v mysql_install_db || true)
[ -n "$MDB" ] && [ -n "$INST" ] || die "mariadbd / mariadb-install-db tidak dijumpai untuk uji pulih."
# Instance uji di luar $DIR: folder backup dikunci 700 (root), sedangkan mariadbd uji berjalan sebagai pengguna mysql.
TROOT=${MARIADB_UJI_DIR:-/var/tmp}; [ -d "$TROOT" ] || TROOT=/tmp
TF=$(df -Pk "$TROOT" | awk 'NR==2{print $4}'); [ "${USED_KB:-0}" -gt 0 ] && [ "$TF" -lt "$USED_KB" ] && die "Ruang di $TROOT tidak mencukupi untuk uji pulih (perlu ~$((USED_KB/1024)) MB). Tetapkan MARIADB_UJI_DIR=/folder/lain."
TMPI=$(mktemp -d "$TROOT/mariadb-uji.XXXXXX"); SOCKD=$(mktemp -d /tmp/mdbu.XXXXXX); SOCK="$SOCKD/s.sock" # laluan soket pendek (had ~104 aksara)
RUNAS=(); if [ "$(id -u)" = 0 ]; then id mysql >/dev/null 2>&1 && { chown -R mysql: "$TMPI" "$SOCKD"; chmod 750 "$TMPI" "$SOCKD"; RUNAS=(--user=mysql); }; fi
"$INST" --no-defaults --datadir="$TMPI/data" ${RUNAS[@]+"${RUNAS[@]}"} --auth-root-authentication-method=normal --skip-test-db >"$TMPI/uji-install.log" 2>&1 || die_log "Gagal menyediakan instance uji (log disalin ke $OUT/uji-install.log)." "$TMPI/uji-install.log"
"$MDB" --no-defaults --datadir="$TMPI/data" --socket="$SOCK" --skip-networking --pid-file="$SOCKD/p.pid" --log-error="$TMPI/uji-mariadbd.log" ${RUNAS[@]+"${RUNAS[@]}"} --innodb-buffer-pool-size=256M &
TMPPID=$!
for _ in $(seq 1 60); do "$CLI" --no-defaults -uroot -S "$SOCK" -e "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done
"$CLI" --no-defaults -uroot -S "$SOCK" -e "SELECT 1" >/dev/null 2>&1 || die_log "Instance uji tidak bermula (log disalin ke $OUT/uji-mariadbd.log)." "$TMPI/uji-mariadbd.log"
# Pulih dalam instance tanpa rangkaian: SQL tertanam (CVE-2026-86047) tidak menyentuh pelayan sebenar.
gunzip -c "$OUT/semua.sql.gz" | "$CLI" --no-defaults -uroot -S "$SOCK" --binary-mode 2>"$OUT/uji-pulih.err" || die_log "Pulih ke instance uji GAGAL — backup tidak boleh dipercayai (lihat $OUT/uji-pulih.err). Naik taraf dibatalkan." "$OUT/uji-pulih.err"
BAD=0; N=0
while IFS=$'\t' read -r tbl want; do
  N=$((N+1)); db=${tbl%%.*}; t=${tbl#*.}
  got=$("$CLI" --no-defaults -uroot -S "$SOCK" -N -B -e "SELECT COUNT(*) FROM \`$db\`.\`$t\`" 2>/dev/null || echo TIADA)
  if [ "$got" != "$want" ]; then
    if [ "$FREEZE" = 0 ] && [ "$got" != TIADA ]; then warn "Berbeza (tulisan semasa backup?): $tbl sumber=$want pulih=$got"; else log "${C_R}Tidak sepadan: $tbl sumber=$want pulih=$got${C_0}"; BAD=$((BAD+1)); fi
  fi
done < "$OUT/bilangan-sumber.txt"
for db in $DBS; do
  r=$("$CLI" --no-defaults -uroot -S "$SOCK" -N -B -e "SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema='$db'")
  s=$(sql "SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema='$db'"); [ "$r" = "$s" ] || { log "${C_R}Routine $db: sumber=$s pulih=$r${C_0}"; BAD=$((BAD+1)); }
done
kill "$TMPPID" 2>/dev/null || true; wait "$TMPPID" 2>/dev/null || true; TMPPID=""; rm -rf "$TMPI" "$SOCKD"; TMPI=""; SOCKD=""
[ "$BAD" = 0 ] || die "Uji backup GAGAL ($BAD perbezaan daripada $N jadual). Naik taraf dibatalkan; backup disimpan di $OUT untuk siasatan."
ok "Uji backup LULUS: $N jadual dipulih, bilangan baris & routine sepadan."
echo "LULUS $(date '+%F %T') $N jadual" > "$OUT/UJI-LULUS"

# Simpan $KEEP backup terbaharu sahaja.
ls -1dt "$DIR"/mariadb-* 2>/dev/null | tail -n +$((KEEP+1)) | while read -r old; do rm -rf "$old"; log "Backup lama dibuang: $old"; done

if [ "$MODE" = backup ]; then
  [ "$FROZEN" = 1 ] && { sql "SET GLOBAL read_only=OFF"; FROZEN=0; ok "Tulisan dibuka semula."; }
  ok "Selesai (backup + uji). Tiada naik taraf dibuat."; exit 0
fi

# ---------------- 5. Naik taraf ----------------
log "== Naik taraf MariaDB $CUR → $CAND =="
if [ "$PM" = apt ]; then
  PKGS=$(dpkg-query -W -f='${db:Status-Abbrev} ${binary:Package}\n' 2>/dev/null | awk '$1=="ii" && $2 ~ /^(mariadb|libmariadb|galera)/ {print $2}')
  # Pin setiap pakej MariaDB kepada versi pilihan jika repo menyediakannya (pakej MariaDB bergantung pada versi yang sama tepat).
  PIN=""; for pk in $PKGS; do if apt-cache madison "$pk" 2>/dev/null | awk -F'|' '{gsub(/ /,"",$2); print $2}' | grep -qxF "$CANDFULL"; then PIN="$PIN $pk=$CANDFULL"; else PIN="$PIN $pk"; fi; done
  # shellcheck disable=SC2086
  DEBIAN_FRONTEND=noninteractive apt-get install -y -o Dpkg::Options::=--force-confold $PIN
else
  PKGS=$(rpm -qa --qf '%{NAME}\n' | grep -iE '^(mariadb|galera)' | sort -u)
  PIN=""; for pk in $PKGS; do case "$pk" in [Mm]ari[aA][Dd][Bb]-*|mariadb-*) PIN="$PIN $pk-$CANDFULL";; *) PIN="$PIN $pk";; esac; done
  # shellcheck disable=SC2086
  $PM install -y $PIN || $PM upgrade -y $PKGS
fi
ok "Pakej dinaik taraf: $(echo $PIN)"

# ---------------- 6. Semakan selepas ----------------
log "== Semakan selepas =="
systemctl restart "$SVC"; sleep 3; systemctl is-active --quiet "$SVC" || die "Perkhidmatan $SVC tidak aktif selepas restart! Semak: journalctl -u $SVC -n 80. Pemulihan: $OUT/PULIH.txt"
FROZEN=0 # restart menetapkan semula read_only kepada nilai konfigurasi
UPG=$(command -v mariadb-upgrade || command -v mysql_upgrade || true)
[ -n "$UPG" ] && "$UPG" ${DEF[@]+"${DEF[@]}"} --force >"$OUT/mariadb-upgrade.log" 2>&1 && ok "mariadb-upgrade selesai." || warn "mariadb-upgrade melaporkan amaran (lihat $OUT/mariadb-upgrade.log)."
NEW=$(sql "SELECT VERSION()"); log "Versi baharu: $NEW"
[ "$(sql "SELECT @@read_only")" = 0 ] || { warn "read_only masih ON dalam konfigurasi — dimatikan."; sql "SET GLOBAL read_only=OFF"; }
CHK=$(command -v mariadb-check || command -v mysqlcheck || true)
# shellcheck disable=SC2086
[ -n "$CHK" ] && { "$CHK" ${DEF[@]+"${DEF[@]}"} --check --databases $DBS >"$OUT/semak-jadual.log" 2>&1 && ! grep -qiE 'error|corrupt' "$OUT/semak-jadual.log" && ok "Semakan jadual: tiada ralat." || warn "Semakan jadual ada amaran — lihat $OUT/semak-jadual.log"; }
NV=$(echo "$NEW" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
if vercmp_lt "$NV" "$NEED" && [ "$BACKPORT" = 0 ]; then warn "Versi $NV masih di bawah $NEED."; else ok "MariaDB kini $NV."; fi
ok "Selesai. Backup diuji: $OUT. Buka Panel Platform › Maklumat Sistem untuk mengesahkan status keselamatan pangkalan data."
