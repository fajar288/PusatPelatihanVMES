#!/usr/bin/env bash
# Fungsi bersama skrip rilis cPanel (release-backend.sh, release-frontend.sh, rollback.sh).
# Struktur folder di server (bawaan PPTI_BASE=$HOME/ppti):
#   $PPTI_BASE/backend/releases/<versi>/   kode Laravel per rilis
#   $PPTI_BASE/backend/shared/.env         konfigurasi produksi (dibuat manual sekali)
#   $PPTI_BASE/backend/shared/storage/     berkas unggahan, log, cache (bertahan antar rilis)
#   $PPTI_BASE/backend/current -> releases/<versi>   (document root subdomain API = current/public)
#   $PPTI_BASE/frontend/releases/<versi>/  hasil build Next.js standalone
#   $PPTI_BASE/frontend/shared/.env.production
#   $PPTI_BASE/frontend/current -> releases/<versi>  (Application root di Setup Node.js App)
# Tanpa symlink (hosting melarang): PPTI_NO_SYMLINK=1, lihat docs/deploy-cpanel.md.

set -euo pipefail

PPTI_BASE="${PPTI_BASE:-$HOME/ppti}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
PHP_BIN="${PHP_BIN:-php}"
NO_SYMLINK="${PPTI_NO_SYMLINK:-0}"

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
die() { printf 'GAGAL: %s\n' "$*" >&2; exit 1; }

# Versi hanya huruf, angka, titik, garis bawah, tanda hubung (dipakai sebagai nama folder).
valid_version() { [[ "$1" =~ ^[A-Za-z0-9._-]+$ ]] || die "versi tidak valid: '$1'"; }

# Ambil versi dari nama artefak: ppti-backend-<versi>.tar.gz / ppti-frontend-<versi>.zip
version_from_artifact() {
  local name app="$2"
  name="$(basename "$1")"
  name="${name%.tar.gz}"
  name="${name%.tgz}"
  name="${name%.zip}"
  name="${name#ppti-${app}-}"
  printf '%s' "$name"
}

# Ekstrak .tar.gz atau .zip ke folder tujuan (folder dibuat baru).
extract_artifact() {
  local file="$1" dest="$2"
  [[ -f "$file" ]] || die "artefak tidak ditemukan: $file"
  mkdir -p "$dest"
  case "$file" in
    *.tar.gz | *.tgz) tar -xzf "$file" -C "$dest" ;;
    *.zip)
      if command -v unzip >/dev/null 2>&1; then
        unzip -q "$file" -d "$dest"
      elif command -v "$PHP_BIN" >/dev/null 2>&1; then
        "$PHP_BIN" -r '$z=new ZipArchive; if($z->open($argv[1])!==true){fwrite(STDERR,"zip tidak bisa dibuka\n");exit(1);} $z->extractTo($argv[2]) || exit(1); $z->close();' "$file" "$dest"
      else
        die "unzip tidak tersedia; pakai artefak .tar.gz"
      fi
      ;;
    *) die "format artefak tidak dikenal (pakai .tar.gz atau .zip): $file" ;;
  esac
  # artefak dibuat dengan satu folder induk (ppti-backend-<versi>/...): ratakan
  local entries
  entries=("$dest"/*)
  if [[ ${#entries[@]} -eq 1 && -d "${entries[0]}" && ! -e "$dest/artisan" && ! -e "$dest/server.js" ]]; then
    local inner="${entries[0]}"
    shopt -s dotglob
    mv "$inner"/* "$dest"/
    shopt -u dotglob
    rmdir "$inner"
  fi
}

# Periksa di awal rilis (sebelum migrate): current harus symlink, tidak ada, atau folder tanpa berkas.
check_current_replaceable() {
  local app_dir="$1"
  [[ "$NO_SYMLINK" == "1" ]] && return 0
  if [[ -d "$app_dir/current" && ! -L "$app_dir/current" && -n "$(find "$app_dir/current" -type f -print -quit)" ]]; then
    die "$app_dir/current adalah folder berisi berkas (bukan symlink). Pindahkan isinya lalu ulangi, atau pakai PPTI_NO_SYMLINK=1."
  fi
}

# Arahkan current ke releases/<versi> secara atomik (symlink baru lalu rename).
switch_current() {
  local app_dir="$1" version="$2"
  if [[ "$NO_SYMLINK" == "1" ]]; then
    switch_current_copy "$app_dir" "$version"
    return
  fi
  # cPanel membuat folder document root (current/public) saat subdomain dibuat: folder kosong itu diganti symlink.
  # Folder current yang berisi berkas tidak pernah dihapus otomatis.
  if [[ -d "$app_dir/current" && ! -L "$app_dir/current" ]]; then
    if [[ -n "$(find "$app_dir/current" -type f -print -quit)" ]]; then
      die "$app_dir/current adalah folder berisi berkas (bukan symlink). Pindahkan isinya lalu ulangi, atau pakai PPTI_NO_SYMLINK=1."
    fi
    log "ganti folder kosong $app_dir/current (dibuat cPanel) dengan symlink"
    rm -rf "${app_dir:?}/current"
  fi
  ln -sfn "releases/$version" "$app_dir/current.next"
  mv -Tf "$app_dir/current.next" "$app_dir/current"
}

# Alternatif tanpa symlink: current adalah folder biasa; rilis lama dipindah ke current.prev (rename, cepat).
switch_current_copy() {
  local app_dir="$1" version="$2"
  rm -rf "$app_dir/current.next" "$app_dir/current.prev"
  cp -a "$app_dir/releases/$version" "$app_dir/current.next"
  printf '%s\n' "$version" >"$app_dir/current.next/.ppti-release"
  if [[ -d "$app_dir/current" ]]; then
    mv "$app_dir/current" "$app_dir/current.prev"
  fi
  mv "$app_dir/current.next" "$app_dir/current"
}

# Versi yang sedang aktif ('' bila belum ada).
current_version() {
  local app_dir="$1"
  if [[ -L "$app_dir/current" ]]; then
    basename "$(readlink "$app_dir/current")"
  elif [[ -f "$app_dir/current/.ppti-release" ]]; then
    cat "$app_dir/current/.ppti-release"
  else
    printf ''
  fi
}

# Catat riwayat aktivasi (dipakai rollback).
record_release() {
  printf '%s %s\n' "$(date '+%Y-%m-%dT%H:%M:%S')" "$2" >>"$1/releases.log"
}

# Hapus rilis lama, sisakan KEEP_RELEASES terbaru (rilis aktif & sebelumnya tidak pernah dihapus).
prune_releases() {
  local app_dir="$1" active previous
  active="$(current_version "$app_dir")"
  previous="$(previous_version "$app_dir")"
  local -a all=()
  mapfile -t all < <(ls -1t "$app_dir/releases" 2>/dev/null || true)
  local i=0 r
  for r in "${all[@]}"; do
    i=$((i + 1))
    if [[ $i -le $KEEP_RELEASES || "$r" == "$active" || "$r" == "$previous" ]]; then
      continue
    fi
    log "hapus rilis lama: $r"
    rm -rf "${app_dir:?}/releases/$r"
  done
}

# Versi aktif sebelum yang sekarang menurut releases.log (yang foldernya masih ada).
previous_version() {
  local app_dir="$1" active v
  active="$(current_version "$app_dir")"
  [[ -f "$app_dir/releases.log" ]] || return 0
  while read -r _ v; do
    if [[ "$v" != "$active" && -d "$app_dir/releases/$v" ]]; then
      printf '%s' "$v"
      return 0
    fi
  done < <(tac "$app_dir/releases.log")
}

# Kunci agar dua rilis tidak berjalan bersamaan.
acquire_lock() {
  local app_dir="$1"
  mkdir -p "$app_dir"
  exec 9>"$app_dir/.deploy.lock"
  if command -v flock >/dev/null 2>&1; then
    flock -n 9 || die "rilis lain sedang berjalan ($app_dir/.deploy.lock)"
  fi
}
