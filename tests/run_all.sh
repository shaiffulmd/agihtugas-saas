#!/bin/sh
# Jalankan semua suite ujian UrusTugas mengikut persekitaran. Setiap kumpulan memerlukan pemasangan UJIAN tersendiri
# (ujian mengubah data & tetapan platform). Jalankan dari akar salinan projek yang .env-nya menunjuk ke pemasangan itu.
#
#   sh tests/run_all.sh statik                       — tanpa pelayan (lint, audit SQL, simbol)
#   URL=http://127.0.0.1:8091 sh tests/run_all.sh demo
#        .env: DEMO_MODE=1 (pemasang web → "Pasang demo"), TENANT_PRIMARY=path
#   URL=http://localhost:8092 SESSION_DIR=… EMPTY_DB=nama_db_kosong sh tests/run_all.sh tempatan
#        .env: APP_ENV=local, DEMO_MODE=0, REQUIRE_2FA=1, TENANT_PRIMARY=subdomain, PLATFORM_DOMAIN=localhost:8092,
#              PAYMENT_TEST_BASE=http://127.0.0.1:8095 → php tests/setup_test_install.php
#   URL=http://127.0.0.1:8091 DB_PORT=… DB_NAME=db_kosong DB_USER=… DB_PASS=… sh tests/run_all.sh baharu
#        tiada .env & pangkalan data kosong (pemasang web diuji); pulihkan public_html/install.php selepas itu.
set -e
cd "$(dirname "$0")/.."
G=${1:-statik}; TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
run(){ printf '\n▶ %s\n' "$*"; "$@" > "$TMP/out" 2>&1 || { tail -15 "$TMP/out"; echo "GAGAL: $*"; exit 1; }; tail -1 "$TMP/out"; }
case "$G" in
statik)
  for f in app/*.php bin/*.php public_html/*.php tests/*.php integrations/koku-multi/*.php; do php -l "$f" >/dev/null; done; echo "php -l: semua fail lulus"
  run python3 tests/tenant_sql_audit.py
  run php tests/static_symbols.php ;;
demo)
  : "${URL:?tetapkan URL}"
  php bin/school.php demo-reset --all >/dev/null
  run env TEST_URL="$URL" python3 tests/public_demo.py
  php bin/school.php demo-reset --all >/dev/null
  run env TEST_URL="$URL" python3 tests/multi_tenant_integration.py
  php tests/setup_engine_test.php > "$TMP/ujian.json"
  run env TEST_URL="$URL" SETUP_JSON="$TMP/ujian.json" python3 tests/engine_integration.py
  run env TEST_URL="$URL" SETUP_JSON="$TMP/ujian.json" python3 tests/plan_integration.py
  run php tests/allocation_engine.php
  run php tests/load_index.php
  run php tests/letter_format.php
  run php tests/subjects.php
  run php tests/workflow_flags.php
  run php tests/roles.php
  run php tests/bulk_import.php
  run php tests/trial_letters.php
  run php tests/smtp_auth.php
  run php tests/schema_engine.php
  run php tests/import_agihtugas.php
  run php tests/integration_client.php DEM0001 "$URL/DEM0001" DEM0002
  php bin/school.php demo-reset --all >/dev/null ;;
tempatan)
  : "${URL:?tetapkan URL}" "${SESSION_DIR:?tetapkan SESSION_DIR}"
  H=$(printf '%s' "$URL" | sed 's#^\(https*://\)#\1dem0001.#')
  run php tests/security.php
  for t in errors_integration security_integration integrity_integration platform_integration payment_integration accounts_integration domain_integration appearance_integration; do
    run env TEST_URL="$H" SESSION_DIR="$SESSION_DIR" python3 tests/$t.py
  done
  run php tests/directory.php
  run env UPD_TEST_ENV=.env UPD_TEST_LOCK=storage/installed.lock python3 tests/updater_local.py
  [ -n "${EMPTY_DB:-}" ] && run php tests/backup_roundtrip.php "$EMPTY_DB"
  php tests/setup_engine_test.php > "$TMP/ujian.json"
  run env TEST_URL="$(printf '%s' "$URL" | sed 's#localhost#127.0.0.1#')" python3 tests/api_security.py
  O=$(php -r "require 'app/bootstrap.php'; echo school_find('UJT0001')?'UJT0001':'';")
  run env TEST_URL="$URL" MODE=subdomain SCHOOLS=DEM0001,$O python3 tests/multi_tenant_integration.py ;;
baharu)
  : "${URL:?tetapkan URL}"
  [ ! -f .env ] || { echo "Buang/pindah .env dahulu (ujian pemasang memerlukan pemasangan kosong)."; exit 1; }
  run env TEST_URL="$URL" python3 tests/smoke_platform.py ;;
*) echo "Kumpulan tidak dikenali: $G (statik | demo | tempatan | baharu)"; exit 1 ;;
esac
printf '\nKumpulan %s selesai.\n' "$G"
