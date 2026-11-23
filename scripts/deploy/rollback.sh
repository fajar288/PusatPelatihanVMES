#!/usr/bin/env bash
# Kembali ke rilis sebelumnya (atau versi tertentu) tanpa build/ekstrak ulang:
#   bash scripts/deploy/rollback.sh backend            # rilis aktif sebelum yang sekarang
#   bash scripts/deploy/rollback.sh frontend v1.1.0    # versi tertentu yang masih ada di releases/
# Catatan backend: skema database TIDAK di-rollback otomatis. Bila rilis baru menambah migrasi yang tidak cocok
# dengan kode lama, pulihkan dari backup database (docs/deploy-cpanel.md bagian Restore) atau jalankan
# `php artisan migrate:rollback --step=N` dari folder rilis baru SEBELUM rollback ini.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

[[ $# -ge 1 ]] || die "pemakaian: $0 backend|frontend [versi]"
APP="$1"
[[ "$APP" == "backend" || "$APP" == "frontend" ]] || die "aplikasi harus backend atau frontend"
APP_DIR="$PPTI_BASE/$APP"
acquire_lock "$APP_DIR"

ACTIVE="$(current_version "$APP_DIR")"
TARGET="${2:-$(previous_version "$APP_DIR")}"
[[ -n "$TARGET" ]] || die "tidak ada rilis sebelumnya untuk $APP"
valid_version "$TARGET"
[[ -f "$APP_DIR/releases/$TARGET/.ppti-complete" ]] || die "rilis $TARGET tidak ada atau belum pernah aktif dengan lengkap"
[[ "$TARGET" != "$ACTIVE" ]] || die "$TARGET sudah aktif"

log "$APP: $ACTIVE -> $TARGET"
switch_current "$APP_DIR" "$TARGET"
record_release "$APP_DIR" "$TARGET"

if [[ "$APP" == "backend" ]]; then
  # config/route cache rilis lama masih ada di foldernya sendiri; cukup beri tahu worker antrean dan aktifkan lagi
  (cd "$APP_DIR/current" && "$PHP_BIN" artisan queue:restart && "$PHP_BIN" artisan up) || die "artisan gagal di rilis $TARGET"
else
  mkdir -p "$APP_DIR/current/tmp"
  touch "$APP_DIR/current/tmp/restart.txt"
fi
log "$APP kembali ke $TARGET"
