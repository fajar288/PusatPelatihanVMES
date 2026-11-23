#!/usr/bin/env bash
# Rilis backend Laravel di cPanel (jalankan lewat Terminal cPanel / SSH):
#   bash scripts/deploy/release-backend.sh ~/ppti-backend-v1.2.0.tar.gz [versi]
# Langkah: ekstrak ke releases/<versi> -> tautkan .env & storage bersama -> mode pemeliharaan -> migrate --force
# -> cache config/route/view/event -> alihkan current -> queue:restart -> aktif kembali.
# Aman diulang: versi yang sudah aktif dilewati; versi yang gagal di tengah jalan diekstrak ulang.
# Bila langkah apa pun gagal sebelum current dialihkan, rilis lama tetap aktif dan mode pemeliharaan dimatikan.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

[[ $# -ge 1 ]] || die "pemakaian: $0 <artefak ppti-backend-<versi>.tar.gz|.zip> [versi]"
ARTIFACT="$1"
VERSION="${2:-$(version_from_artifact "$ARTIFACT" backend)}"
valid_version "$VERSION"

APP_DIR="$PPTI_BASE/backend"
RELEASE="$APP_DIR/releases/$VERSION"
SHARED="$APP_DIR/shared"
acquire_lock "$APP_DIR"
mkdir -p "$APP_DIR/releases" "$SHARED"

if [[ "$(current_version "$APP_DIR")" == "$VERSION" && -f "$RELEASE/.ppti-complete" ]]; then
  log "versi $VERSION sudah aktif, tidak ada yang dilakukan"
  exit 0
fi

[[ -f "$SHARED/.env" ]] || die "$SHARED/.env belum ada. Salin backend/.env.production.example ke sana, isi nilainya, lalu jalankan: $PHP_BIN artisan key:generate (lihat docs/deploy-cpanel.md)"

# --- 1. ekstrak (folder sementara lalu rename agar tidak pernah ada rilis setengah jadi)
rm -rf "$RELEASE" "$RELEASE.tmp"
log "ekstrak $ARTIFACT -> releases/$VERSION"
extract_artifact "$ARTIFACT" "$RELEASE.tmp"
[[ -f "$RELEASE.tmp/artisan" ]] || die "artefak bukan backend Laravel (artisan tidak ada)"
mv "$RELEASE.tmp" "$RELEASE"

# --- 2. storage & .env bersama
for d in app/public app/private app/backups framework/cache/data framework/sessions framework/views framework/testing logs; do
  mkdir -p "$SHARED/storage/$d"
done
rm -rf "$RELEASE/storage"
if [[ "$NO_SYMLINK" == "1" ]]; then
  log "PPTI_NO_SYMLINK=1: storage & .env memakai path absolut lewat .env (LARAVEL_STORAGE_PATH)"
  cp "$SHARED/.env" "$RELEASE/.env"
  grep -q '^LARAVEL_STORAGE_PATH=' "$RELEASE/.env" || printf '\nLARAVEL_STORAGE_PATH=%s\n' "$SHARED/storage" >>"$RELEASE/.env"
  mkdir -p "$RELEASE/storage"
else
  ln -s "../../shared/storage" "$RELEASE/storage"
  ln -s "../../shared/.env" "$RELEASE/.env"
fi
mkdir -p "$RELEASE/bootstrap/cache"
chmod -R u+rwX "$SHARED/storage" "$RELEASE/bootstrap/cache"

artisan() { (cd "$RELEASE" && "$PHP_BIN" artisan "$@"); }
CURRENT="$APP_DIR/current"
current_artisan() { [[ -e "$CURRENT/artisan" ]] && (cd "$CURRENT" && "$PHP_BIN" artisan "$@"); }

# --- 3. bila gagal sebelum dialihkan: hidupkan lagi rilis lama. Trap EXIT (bukan ERR) karena set -e bisa keluar
# dari dalam fungsi artisan() tempat trap ERR tidak diwarisi.
SWITCHED=0
DONE=0
on_exit() {
  local code=$?
  if [[ $DONE -eq 0 && $SWITCHED -eq 0 ]]; then
    log "rilis $VERSION gagal (kode $code); rilis lama tetap aktif"
    current_artisan up >/dev/null 2>&1 || true
  fi
  exit "$code"
}
trap on_exit EXIT

current_artisan down --retry=60 || true

log "migrate --force"
artisan migrate --force --no-interaction

log "cache konfigurasi, route, view, event"
artisan storage:link --relative --force >/dev/null
artisan config:cache
artisan route:cache
artisan view:cache
artisan event:cache

# --- 4. alihkan current
switch_current "$APP_DIR" "$VERSION"
SWITCHED=1
touch "$RELEASE/.ppti-complete"
record_release "$APP_DIR" "$VERSION"
if [[ "$NO_SYMLINK" == "1" ]]; then
  # current adalah salinan: cache berisi path absolut, jadi dibuat ulang dari folder current
  (cd "$CURRENT" && "$PHP_BIN" artisan config:cache && "$PHP_BIN" artisan route:cache && "$PHP_BIN" artisan view:cache)
fi

artisan queue:restart || true
artisan up
DONE=1

prune_releases "$APP_DIR"
log "backend $VERSION aktif. Cek: curl -fsS https://<domain-api>/api/v1/health/ready"
