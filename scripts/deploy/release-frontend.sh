#!/usr/bin/env bash
# Rilis frontend Next.js (build standalone dari GitHub Actions) di cPanel "Setup Node.js App":
#   bash scripts/deploy/release-frontend.sh ~/ppti-frontend-v1.2.0.tar.gz [versi]
# Application root di cPanel = ppti/frontend/current, Application startup file = server.js.
# Langkah: ekstrak ke releases/<versi> -> tautkan .env.production bersama -> alihkan current -> restart aplikasi
# (Passenger: tmp/restart.txt). Aman diulang; server tidak perlu menjalankan npm install atau next build.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

[[ $# -ge 1 ]] || die "pemakaian: $0 <artefak ppti-frontend-<versi>.tar.gz|.zip> [versi]"
ARTIFACT="$1"
VERSION="${2:-$(version_from_artifact "$ARTIFACT" frontend)}"
valid_version "$VERSION"

APP_DIR="$PPTI_BASE/frontend"
RELEASE="$APP_DIR/releases/$VERSION"
SHARED="$APP_DIR/shared"
acquire_lock "$APP_DIR"
mkdir -p "$APP_DIR/releases" "$SHARED"

if [[ "$(current_version "$APP_DIR")" == "$VERSION" && -f "$RELEASE/.ppti-complete" ]]; then
  log "versi $VERSION sudah aktif, tidak ada yang dilakukan"
  exit 0
fi

check_current_replaceable "$APP_DIR"
rm -rf "$RELEASE" "$RELEASE.tmp"
log "ekstrak $ARTIFACT -> releases/$VERSION"
extract_artifact "$ARTIFACT" "$RELEASE.tmp"
[[ -f "$RELEASE.tmp/server.js" ]] || die "artefak bukan build Next.js standalone (server.js tidak ada)"
[[ -d "$RELEASE.tmp/.next/static" ]] || die "artefak tidak memuat .next/static (aset CSS/JS)"
mv "$RELEASE.tmp" "$RELEASE"

# Variabel sisi server (API_URL_INTERNAL, REVALIDATE_SECRET, API_PROXY_SECRET) dibaca saat runtime dari
# .env.production; NEXT_PUBLIC_* sudah tertanam saat build di GitHub Actions.
if [[ -f "$SHARED/.env.production" ]]; then
  if [[ "$NO_SYMLINK" == "1" ]]; then
    cp "$SHARED/.env.production" "$RELEASE/.env.production"
  else
    ln -sfn "../../shared/.env.production" "$RELEASE/.env.production"
  fi
else
  log "PERINGATAN: $SHARED/.env.production belum ada; isi variabel di menu Setup Node.js App atau buat berkas itu"
fi
mkdir -p "$RELEASE/tmp"

switch_current "$APP_DIR" "$VERSION"
touch "$RELEASE/.ppti-complete"
record_release "$APP_DIR" "$VERSION"

# Passenger (Setup Node.js App) memuat ulang aplikasi saat tmp/restart.txt disentuh
mkdir -p "$APP_DIR/current/tmp"
touch "$APP_DIR/current/tmp/restart.txt"

prune_releases "$APP_DIR"
log "frontend $VERSION aktif. Bila halaman belum berubah, klik Restart di Setup Node.js App."
