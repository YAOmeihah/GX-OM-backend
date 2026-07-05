#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-/www/server/php/82/bin/php}"
WEB_USER="${WEB_USER:-www}"
WEB_GROUP="${WEB_GROUP:-www}"

PACKAGE_PATH="${1:-}"
EXPECTED_SHA256="${2:-}"

if [[ -z "$PACKAGE_PATH" || -z "$EXPECTED_SHA256" ]]; then
  echo "Usage: bash scripts/update-backend.sh /path/to/gx-om-backend-vX.Y.Z.tar.gz <sha256>" >&2
  exit 1
fi

if [[ ! "$EXPECTED_SHA256" =~ ^[A-Fa-f0-9]{64}$ ]]; then
  echo "SHA256 must be 64 hex characters." >&2
  exit 1
fi

if [[ ! -f "$PACKAGE_PATH" ]]; then
  echo "Release package not found: $PACKAGE_PATH" >&2
  exit 1
fi

PACKAGE_PATH="$(realpath "$PACKAGE_PATH")"
EXPECTED_SHA256="$(echo "$EXPECTED_SHA256" | tr 'A-F' 'a-f')"
TIMESTAMP="$(date +'%Y%m%d%H%M%S')"
WORK_DIR="$ROOT_DIR/storage/app/system_updates/manual"
STAGING_DIR="$WORK_DIR/staging/$TIMESTAMP"
BACKUP_DIR="$WORK_DIR/backups/$TIMESTAMP"
LOG_PATH="$ROOT_DIR/storage/logs/manual-update-$TIMESTAMP.log"
MAINTENANCE_DOWN=0

mkdir -p "$ROOT_DIR/storage/logs" "$WORK_DIR/staging" "$WORK_DIR/backups"
exec > >(tee -a "$LOG_PATH") 2>&1

on_error() {
  local exit_code=$?
  echo "Manual update failed with exit code $exit_code."

  if [[ "$MAINTENANCE_DOWN" == "1" ]]; then
    "$PHP_BIN" artisan up || true
  fi

  echo "Log: $LOG_PATH"
  exit "$exit_code"
}

trap on_error ERR

managed_entries=(
  ".env.example"
  "app"
  "artisan"
  "bootstrap"
  "composer.lock"
  "config"
  "database"
  "public"
  "release.json"
  "resources"
  "routes"
  "vendor"
)

# Preserve public/storage, public/.user.ini, and public/app_update.
preserved_public_entries=(
  "storage"
  ".user.ini"
  "app_update"
)

preserved_bootstrap_entries=(
  "cache"
)

is_in_list() {
  local needle="$1"
  shift

  for item in "$@"; do
    if [[ "$item" == "$needle" ]]; then
      return 0
    fi
  done

  return 1
}

copy_directory_contents() {
  local source="$1"
  local target="$2"
  shift 2
  local excluded=("$@")

  [[ -d "$source" ]] || return 0
  mkdir -p "$target"

  shopt -s dotglob nullglob
  for path in "$source"/*; do
    local name
    name="$(basename "$path")"

    if is_in_list "$name" "${excluded[@]}"; then
      continue
    fi

    cp -a "$path" "$target/"
  done
  shopt -u dotglob nullglob
}

replace_directory_contents() {
  local source="$1"
  local target="$2"
  shift 2
  local preserved=("$@")

  mkdir -p "$target"

  shopt -s dotglob nullglob
  for path in "$target"/*; do
    local name
    name="$(basename "$path")"

    if is_in_list "$name" "${preserved[@]}"; then
      continue
    fi

    rm -rf -- "$path"
  done

  for path in "$source"/*; do
    local name
    name="$(basename "$path")"

    if is_in_list "$name" "${preserved[@]}"; then
      continue
    fi

    cp -a "$path" "$target/"
  done
  shopt -u dotglob nullglob
}

backup_managed_entries() {
  mkdir -p "$BACKUP_DIR"

  for entry in "${managed_entries[@]}"; do
    local source="$ROOT_DIR/$entry"
    local target="$BACKUP_DIR/$entry"

    [[ -e "$source" ]] || continue

    if [[ "$entry" == "public" ]]; then
      copy_directory_contents "$source" "$target" "${preserved_public_entries[@]}"
      continue
    fi

    if [[ "$entry" == "bootstrap" ]]; then
      copy_directory_contents "$source" "$target" "${preserved_bootstrap_entries[@]}"
      continue
    fi

    mkdir -p "$(dirname "$target")"
    cp -a "$source" "$target"
  done
}

replace_managed_entries() {
  for entry in "${managed_entries[@]}"; do
    local source="$STAGING_DIR/$entry"
    local target="$ROOT_DIR/$entry"

    [[ -e "$source" ]] || continue

    if [[ "$entry" == "public" ]]; then
      replace_directory_contents "$source" "$target" "${preserved_public_entries[@]}"
      continue
    fi

    if [[ "$entry" == "bootstrap" ]]; then
      replace_directory_contents "$source" "$target" "${preserved_bootstrap_entries[@]}"
      continue
    fi

    rm -rf -- "$target"
    cp -a "$source" "$target"
  done
}

restore_backup() {
  [[ -d "$BACKUP_DIR" ]] || return 0

  for entry in "${managed_entries[@]}"; do
    local source="$BACKUP_DIR/$entry"
    local target="$ROOT_DIR/$entry"

    [[ -e "$source" ]] || continue

    if [[ "$entry" == "public" ]]; then
      replace_directory_contents "$source" "$target" "${preserved_public_entries[@]}"
      continue
    fi

    if [[ "$entry" == "bootstrap" ]]; then
      replace_directory_contents "$source" "$target" "${preserved_bootstrap_entries[@]}"
      continue
    fi

    rm -rf -- "$target"
    cp -a "$source" "$target"
  done
}

ensure_runtime_paths() {
  mkdir -p \
    "$ROOT_DIR/bootstrap/cache" \
    "$ROOT_DIR/public/app_update" \
    "$ROOT_DIR/storage/app/maintenance_exports" \
    "$ROOT_DIR/storage/app/private" \
    "$ROOT_DIR/storage/app/public" \
    "$ROOT_DIR/storage/framework/cache/data" \
    "$ROOT_DIR/storage/framework/sessions" \
    "$ROOT_DIR/storage/framework/views" \
    "$ROOT_DIR/storage/logs"

  chmod -R ug+rwX "$ROOT_DIR/bootstrap/cache" "$ROOT_DIR/storage" "$ROOT_DIR/public/app_update"

  if id "$WEB_USER" >/dev/null 2>&1; then
    chown -R "$WEB_USER:$WEB_GROUP" "$ROOT_DIR/bootstrap/cache" "$ROOT_DIR/storage" "$ROOT_DIR/public/app_update" || true
  fi
}

echo "Manual GX-OM backend update started at $TIMESTAMP"
echo "Root: $ROOT_DIR"
echo "Package: $PACKAGE_PATH"
echo "Log: $LOG_PATH"

ACTUAL_SHA256="$(sha256sum "$PACKAGE_PATH" | awk '{print tolower($1)}')"
if [[ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]]; then
  echo "SHA256 mismatch."
  echo "Expected: $EXPECTED_SHA256"
  echo "Actual:   $ACTUAL_SHA256"
  exit 1
fi

mkdir -p "$STAGING_DIR"
tar -xzf "$PACKAGE_PATH" -C "$STAGING_DIR"

required_entries=(
  ".env.example"
  "app"
  "artisan"
  "bootstrap"
  "composer.lock"
  "config"
  "database"
  "public"
  "release.json"
  "resources"
  "routes"
  "vendor"
)

for entry in "${required_entries[@]}"; do
  if [[ ! -e "$STAGING_DIR/$entry" ]]; then
    echo "Release package missing required entry: $entry"
    exit 1
  fi
done

"$PHP_BIN" artisan down
MAINTENANCE_DOWN=1

backup_managed_entries
replace_managed_entries
ensure_runtime_paths

if ! "$PHP_BIN" artisan migrate --force; then
  echo "Migration failed; restoring backup."
  restore_backup
  ensure_runtime_paths
  exit 1
fi

"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan storage:link --force
ensure_runtime_paths
"$PHP_BIN" artisan up
MAINTENANCE_DOWN=0

rm -rf -- "$STAGING_DIR"

echo "Manual GX-OM backend update completed."
echo "Backup: $BACKUP_DIR"
echo "Log: $LOG_PATH"
