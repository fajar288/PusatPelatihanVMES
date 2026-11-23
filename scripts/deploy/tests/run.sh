#!/usr/bin/env bash
# Uji skrip rilis secara lokal di Linux/WSL tanpa server cPanel dan tanpa PHP:
#   bash scripts/deploy/tests/run.sh
# `php` diganti stub yang mencatat perintah artisan (dan bisa dipaksa gagal saat migrate). Yang diuji: struktur
# releases/shared/current, symlink .env & storage, urutan langkah, idempoten, gagal migrate tidak mengalihkan current,
# rollback, rotasi rilis, frontend standalone, dan mode tanpa symlink.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY="$(cd "$HERE/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0
ok() { PASS=$((PASS + 1)); printf '  ok  %s\n' "$*"; }
fail() { printf '  GAGAL %s\n' "$*" >&2; exit 1; }
assert() { if eval "$1"; then ok "$2"; else fail "$2  [$1]"; fi; }

# --- stub php: catat perintah, buat/hapus berkas penanda seperti artisan sungguhan
mkdir -p "$WORK/bin"
cat >"$WORK/bin/php" <<'STUB'
#!/usr/bin/env bash
printf '%s|%s\n' "$(pwd)" "$*" >>"$STUB_LOG"
if [[ "${1:-}" == "artisan" ]]; then
  case "${2:-}" in
    migrate) [[ "${FAKE_MIGRATE_FAIL:-0}" == "1" ]] && { echo "SQLSTATE[HY000] migrasi gagal" >&2; exit 1; } ;;
    down) mkdir -p storage/framework && touch storage/framework/down ;;
    up) rm -f storage/framework/down ;;
    storage:link) mkdir -p public && ln -sfn ../storage/app/public public/storage ;;
  esac
fi
exit 0
STUB
chmod +x "$WORK/bin/php"
export PATH="$WORK/bin:$PATH" STUB_LOG="$WORK/php.log" PPTI_BASE="$WORK/server/ppti" KEEP_RELEASES=2
touch "$STUB_LOG"

backend_artifact() { # versi
  local d="$WORK/build/ppti-backend-$1"
  mkdir -p "$d/public" "$d/storage/app" "$d/bootstrap/cache"
  echo "<?php // $1" >"$d/artisan"
  echo "<?php // index $1" >"$d/public/index.php"
  echo "$1" >"$d/RELEASE"
  tar -czf "$WORK/ppti-backend-$1.tar.gz" -C "$WORK/build" "ppti-backend-$1"
  printf '%s' "$WORK/ppti-backend-$1.tar.gz"
}
frontend_artifact() {
  local d="$WORK/build/ppti-frontend-$1"
  mkdir -p "$d/.next/static/chunks" "$d/public"
  echo "// server $1" >"$d/server.js"
  echo "x" >"$d/.next/static/chunks/a.js"
  tar -czf "$WORK/ppti-frontend-$1.tar.gz" -C "$WORK/build" "ppti-frontend-$1"
  printf '%s' "$WORK/ppti-frontend-$1.tar.gz"
}

B="$PPTI_BASE/backend"
F="$PPTI_BASE/frontend"

echo "Backend"
A1="$(backend_artifact v1.0.0)"
if bash "$DEPLOY/release-backend.sh" "$A1" >/dev/null 2>&1; then fail "tanpa shared/.env harus berhenti"; else ok "berhenti bila shared/.env belum ada"; fi
mkdir -p "$B/shared" && echo "APP_KEY=base64:uji" >"$B/shared/.env"
mkdir -p "$B/current/public"   # folder document root kosong yang dibuat cPanel saat subdomain dibuat

bash "$DEPLOY/release-backend.sh" "$A1" >/dev/null
assert '[[ "$(readlink "$B/current")" == "releases/v1.0.0" ]]' "current -> releases/v1.0.0 (folder kosong buatan cPanel diganti symlink)"
assert '[[ -L "$B/releases/v1.0.0/.env" && "$(readlink "$B/releases/v1.0.0/.env")" == "../../shared/.env" ]]' ".env ditautkan ke shared"
assert '[[ -L "$B/releases/v1.0.0/storage" && -d "$B/shared/storage/framework/views" && -d "$B/shared/storage/app/private" ]]' "storage bersama + struktur folder"
assert '[[ -f "$B/current/artisan" && -f "$B/releases/v1.0.0/.ppti-complete" ]]' "rilis lengkap"
assert '[[ ! -e "$B/shared/storage/framework/down" ]]' "tidak tertinggal dalam mode pemeliharaan"
steps="$(grep "releases/v1.0.0|artisan" "$STUB_LOG" | cut -d'|' -f2 | awk '{print $2}' | paste -sd, -)"
assert '[[ "$steps" == "migrate,storage:link,config:cache,route:cache,view:cache,event:cache,queue:restart,up" ]]' "urutan langkah artisan ($steps)"

: >"$STUB_LOG"
bash "$DEPLOY/release-backend.sh" "$A1" >/dev/null
assert '[[ ! -s "$STUB_LOG" ]]' "idempoten: versi aktif dijalankan ulang tidak melakukan apa-apa"

A2="$(backend_artifact v1.1.0)"
: >"$STUB_LOG"
if FAKE_MIGRATE_FAIL=1 bash "$DEPLOY/release-backend.sh" "$A2" >/dev/null 2>&1; then fail "migrate gagal harus mengembalikan kode gagal"; else ok "migrate gagal -> skrip berhenti dengan kode gagal"; fi
assert '[[ "$(readlink "$B/current")" == "releases/v1.0.0" ]]' "migrate gagal: current tetap v1.0.0"
assert '[[ ! -e "$B/shared/storage/framework/down" ]]' "migrate gagal: rilis lama aktif lagi (artisan up)"
assert '! grep -q "config:cache" "$STUB_LOG"' "migrate gagal: langkah setelahnya tidak dijalankan"

bash "$DEPLOY/release-backend.sh" "$A2" >/dev/null
assert '[[ "$(readlink "$B/current")" == "releases/v1.1.0" ]]' "dijalankan ulang setelah perbaikan: v1.1.0 aktif"

bash "$DEPLOY/rollback.sh" backend >/dev/null
assert '[[ "$(readlink "$B/current")" == "releases/v1.0.0" ]]' "rollback ke v1.0.0"
if bash "$DEPLOY/rollback.sh" backend v9.9.9 >/dev/null 2>&1; then fail "rollback ke versi tak ada harus gagal"; else ok "rollback ke versi yang tidak ada ditolak"; fi
bash "$DEPLOY/rollback.sh" backend v1.1.0 >/dev/null
assert '[[ "$(readlink "$B/current")" == "releases/v1.1.0" ]]' "rollback ke versi tertentu (maju lagi ke v1.1.0)"

sleep 1; bash "$DEPLOY/release-backend.sh" "$(backend_artifact v1.2.0)" >/dev/null
sleep 1; bash "$DEPLOY/release-backend.sh" "$(backend_artifact v1.3.0)" >/dev/null
assert '[[ ! -d "$B/releases/v1.0.0" && -d "$B/releases/v1.2.0" && -d "$B/releases/v1.3.0" ]]' "rotasi KEEP_RELEASES=2 (rilis aktif & sebelumnya disimpan)"
if bash "$DEPLOY/release-backend.sh" "$WORK/tidak-ada.tar.gz" x1 >/dev/null 2>&1; then fail "artefak tidak ada harus gagal"; else ok "artefak tidak ada ditolak"; fi
if bash "$DEPLOY/release-backend.sh" "$A1" "../jahat" >/dev/null 2>&1; then fail "versi dengan ../ harus ditolak"; else ok "versi tidak valid (../) ditolak"; fi

mkdir -p "$WORK/server3/ppti/backend/shared" "$WORK/server3/ppti/backend/current" && echo "APP_KEY=x" >"$WORK/server3/ppti/backend/shared/.env"
echo "situs lama" >"$WORK/server3/ppti/backend/current/index.html"
if PPTI_BASE="$WORK/server3/ppti" bash "$DEPLOY/release-backend.sh" "$A1" >/dev/null 2>&1; then fail "current berisi berkas tidak boleh ditimpa"; else ok "folder current berisi berkas tidak ditimpa"; fi
assert '[[ -f "$WORK/server3/ppti/backend/current/index.html" ]]' "berkas di current tetap utuh"

echo "Frontend"
mkdir -p "$F/shared" && echo "REVALIDATE_SECRET=uji" >"$F/shared/.env.production"
bash "$DEPLOY/release-frontend.sh" "$(frontend_artifact v1.0.0)" >/dev/null
assert '[[ "$(readlink "$F/current")" == "releases/v1.0.0" && -f "$F/current/server.js" && -f "$F/current/.next/static/chunks/a.js" ]]' "standalone + .next/static aktif"
assert '[[ -L "$F/current/.env.production" && -f "$F/current/tmp/restart.txt" ]]' ".env.production ditautkan, Passenger diminta restart"
bad="$WORK/build/ppti-frontend-rusak"; mkdir -p "$bad"; echo x >"$bad/server.js"; tar -czf "$WORK/ppti-frontend-rusak.tar.gz" -C "$WORK/build" ppti-frontend-rusak
if bash "$DEPLOY/release-frontend.sh" "$WORK/ppti-frontend-rusak.tar.gz" >/dev/null 2>&1; then fail "tanpa .next/static harus ditolak"; else ok "artefak tanpa .next/static ditolak, current tetap"; fi
assert '[[ "$(readlink "$F/current")" == "releases/v1.0.0" ]]' "frontend lama tetap aktif"

echo "Tanpa symlink (PPTI_NO_SYMLINK=1)"
export PPTI_BASE="$WORK/server2/ppti" PPTI_NO_SYMLINK=1
B="$PPTI_BASE/backend"
mkdir -p "$B/shared" && echo "APP_KEY=base64:uji" >"$B/shared/.env"
bash "$DEPLOY/release-backend.sh" "$(backend_artifact v2.0.0)" >/dev/null
assert '[[ -d "$B/current" && ! -L "$B/current" && "$(cat "$B/current/.ppti-release")" == "v2.0.0" ]]' "current berupa folder salinan"
assert 'grep -q "^LARAVEL_STORAGE_PATH=$B/shared/storage" "$B/current/.env"' "storage bersama lewat LARAVEL_STORAGE_PATH"
sleep 1; bash "$DEPLOY/release-backend.sh" "$(backend_artifact v2.1.0)" >/dev/null
bash "$DEPLOY/rollback.sh" backend >/dev/null
assert '[[ "$(cat "$B/current/.ppti-release")" == "v2.0.0" ]]' "rollback tanpa symlink"

echo "Semua $PASS pemeriksaan lulus."
