#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="$(pwd)"
TAG=""
PHP_BIN="${PHP_BIN:-php}"
WEB_USER="${WEB_USER:-www}"
WEB_GROUP="${WEB_GROUP:-www}"
OWNER_DEFAULT="YAOmeihah"
REPO_DEFAULT="GX-OM-backend"
DOWNLOAD_TIMEOUT="${DOWNLOAD_TIMEOUT:-300}"
CONNECT_TIMEOUT="${CONNECT_TIMEOUT:-10}"

usage() {
  cat >&2 <<'USAGE'
Usage:
  bash update-backend.sh --tag v1.0.16 [--root /www/wwwroot/api-gx-om.hrlni.cn]

What it does:
  1. Reads GitHub token from .env.
  2. Tries to download gx-om-backend-<tag>.tar.gz and its .sha256 from GitHub Release.
  3. If download fails or times out, waits for you to upload the package into the app root.
  4. Verifies SHA256 when a checksum is available.
  5. Deploys the package while preserving .env, storage, public/storage, public/app_update, and .user.ini.

.env token keys:
  SYSTEM_UPDATE_GITHUB_TOKEN, GITHUB_RELEASE_TOKEN, or GITHUB_TOKEN

.env optional repo keys:
  SYSTEM_UPDATE_GITHUB_OWNER / GITHUB_RELEASE_OWNER
  SYSTEM_UPDATE_GITHUB_REPO / GITHUB_RELEASE_REPO
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --tag)
      TAG="${2:-}"
      shift 2
      ;;
    --root)
      APP_DIR="${2:-}"
      shift 2
      ;;
    --php-bin)
      PHP_BIN="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      if [[ -z "$TAG" && "$1" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        TAG="$1"
        shift
        continue
      fi

      echo "Unknown argument: $1" >&2
      usage
      exit 1
      ;;
  esac
done

APP_DIR="$(cd "$APP_DIR" && pwd)"

if [[ ! "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "--tag must look like vX.Y.Z, for example v1.0.16." >&2
  exit 1
fi

if [[ ! -f "$APP_DIR/artisan" || ! -f "$APP_DIR/.env" ]]; then
  echo "App root is invalid: $APP_DIR" >&2
  echo "Run from the Laravel root or pass --root /path/to/app." >&2
  exit 1
fi

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  echo "PHP binary not found: $PHP_BIN" >&2
  echo "Set PHP_BIN=/www/server/php/82/bin/php or pass --php-bin." >&2
  exit 1
fi

TIMESTAMP="$(date +'%Y%m%d%H%M%S')"
WORK_DIR="/tmp/gx-om-backend-update-$TAG"
RELEASE_DIR="$WORK_DIR/release"
PACKAGE_NAME="gx-om-backend-$TAG.tar.gz"
PACKAGE_PATH="$WORK_DIR/$PACKAGE_NAME"
CHECKSUM_PATH="$WORK_DIR/$PACKAGE_NAME.sha256"
LOG_PATH="$APP_DIR/storage/logs/manual-update-$TIMESTAMP.log"
MAINTENANCE_DOWN=0

mkdir -p "$APP_DIR/storage/logs"
exec > >(tee -a "$LOG_PATH") 2>&1

on_error() {
  local code=$?

  echo "Update failed with exit code $code."
  if [[ "$MAINTENANCE_DOWN" == "1" ]]; then
    "$PHP_BIN" "$APP_DIR/artisan" up || true
  fi
  echo "Log: $LOG_PATH"

  exit "$code"
}

trap on_error ERR

read_env_value() {
  local key="$1"
  local value="${!key:-}"

  if [[ -z "$value" ]]; then
    value="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$APP_DIR/.env" | tail -n 1 | sed -E 's/^[^=]+=//' || true)"
  fi

  value="$(printf '%s' "$value" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//; s/\r$//; s/^"//; s/"$//; s/^'\''//; s/'\''$//')"
  printf '%s' "$value"
}

first_env_value() {
  local value=""

  for key in "$@"; do
    value="$(read_env_value "$key")"
    if [[ -n "$value" ]]; then
      printf '%s' "$value"
      return 0
    fi
  done

  return 0
}

wait_for_enter() {
  if [[ -r /dev/tty ]]; then
    read -r _ </dev/tty || true
  else
    echo "No TTY is available; checking every 5 seconds."
    sleep 5
  fi
}

find_asset_id() {
  local json_path="$1"
  local asset_name="$2"

  "$PHP_BIN" -r '
    $json = json_decode(file_get_contents($argv[1]), true);
    if (! is_array($json)) {
        fwrite(STDERR, "Invalid GitHub release JSON.\n");
        exit(2);
    }
    foreach (($json["assets"] ?? []) as $asset) {
        if (($asset["name"] ?? null) === $argv[2]) {
            echo $asset["id"];
            exit(0);
        }
    }
    exit(1);
  ' "$json_path" "$asset_name"
}

github_get() {
  local url="$1"
  local output="$2"
  local accept="${3:-application/vnd.github+json}"

  curl -fL --connect-timeout "$CONNECT_TIMEOUT" --max-time "$DOWNLOAD_TIMEOUT" \
    -H "Authorization: Bearer $GITHUB_TOKEN_VALUE" \
    -H "Accept: $accept" \
    -H "X-GitHub-Api-Version: 2022-11-28" \
    "$url" \
    -o "$output"
}

download_from_github() {
  GITHUB_TOKEN_VALUE="$(first_env_value SYSTEM_UPDATE_GITHUB_TOKEN GITHUB_RELEASE_TOKEN GITHUB_TOKEN)"
  if [[ -z "$GITHUB_TOKEN_VALUE" ]]; then
    echo "No GitHub token found in .env; will use manual upload fallback."
    return 1
  fi

  local owner
  local repo
  local api_url
  local release_json="$WORK_DIR/release.json"
  local package_asset_id
  local checksum_asset_id

  owner="$(first_env_value SYSTEM_UPDATE_GITHUB_OWNER GITHUB_RELEASE_OWNER)"
  repo="$(first_env_value SYSTEM_UPDATE_GITHUB_REPO GITHUB_RELEASE_REPO)"
  api_url="$(first_env_value SYSTEM_UPDATE_GITHUB_API_URL GITHUB_API_URL)"
  owner="${owner:-$OWNER_DEFAULT}"
  repo="${repo:-$REPO_DEFAULT}"
  api_url="${api_url:-https://api.github.com}"

  echo "Trying GitHub download: $owner/$repo $TAG"
  github_get "$api_url/repos/$owner/$repo/releases/tags/$TAG" "$release_json" || return 1

  package_asset_id="$(find_asset_id "$release_json" "$PACKAGE_NAME" 2>/dev/null || true)"
  checksum_asset_id="$(find_asset_id "$release_json" "$PACKAGE_NAME.sha256" 2>/dev/null || true)"

  if [[ -z "$package_asset_id" ]]; then
    echo "Release asset not found: $PACKAGE_NAME"
    return 1
  fi

  echo "Downloading package: $PACKAGE_NAME"
  github_get "$api_url/repos/$owner/$repo/releases/assets/$package_asset_id" "$PACKAGE_PATH" "application/octet-stream" || return 1

  if [[ -n "$checksum_asset_id" ]]; then
    echo "Downloading checksum: $PACKAGE_NAME.sha256"
    github_get "$api_url/repos/$owner/$repo/releases/assets/$checksum_asset_id" "$CHECKSUM_PATH" "application/octet-stream" || true
  fi

  return 0
}

manual_upload_fallback() {
  local root_package="$APP_DIR/$PACKAGE_NAME"
  local root_checksum="$APP_DIR/$PACKAGE_NAME.sha256"

  echo "GitHub download failed or timed out."
  echo "Upload this file to the app root:"
  echo "  $root_package"
  echo "Optional checksum file:"
  echo "  $root_checksum"
  echo "After upload finishes, press Enter here. The script will keep waiting until the package exists."

  while [[ ! -f "$root_package" ]]; do
    wait_for_enter
  done

  cp "$root_package" "$PACKAGE_PATH"
  if [[ -f "$root_checksum" ]]; then
    cp "$root_checksum" "$CHECKSUM_PATH"
  fi
}

verify_package() {
  if [[ ! -f "$PACKAGE_PATH" ]]; then
    echo "Package does not exist: $PACKAGE_PATH" >&2
    exit 1
  fi

  if [[ -f "$CHECKSUM_PATH" ]]; then
    (
      cd "$WORK_DIR"
      sha256sum -c "$PACKAGE_NAME.sha256"
    )
    return
  fi

  echo "No .sha256 file found. Current package SHA256:"
  sha256sum "$PACKAGE_PATH"
  echo "Type YES to continue without checksum verification."
  if [[ -r /dev/tty ]]; then
    read -r confirm </dev/tty
  else
    echo "No TTY available, refusing to deploy without checksum." >&2
    exit 1
  fi

  [[ "$confirm" == "YES" ]] || exit 1
}

ensure_runtime_paths() {
  mkdir -p \
    "$APP_DIR/bootstrap/cache" \
    "$APP_DIR/public/app_update" \
    "$APP_DIR/storage/app/maintenance_exports" \
    "$APP_DIR/storage/app/private" \
    "$APP_DIR/storage/app/public" \
    "$APP_DIR/storage/framework/cache/data" \
    "$APP_DIR/storage/framework/sessions" \
    "$APP_DIR/storage/framework/views" \
    "$APP_DIR/storage/logs"

  chmod -R ug+rwX "$APP_DIR/bootstrap/cache" "$APP_DIR/storage" "$APP_DIR/public/app_update" || true

  if id "$WEB_USER" >/dev/null 2>&1; then
    chown -R "$WEB_USER:$WEB_GROUP" "$APP_DIR/bootstrap/cache" "$APP_DIR/storage" "$APP_DIR/public/app_update" || true
  fi
}

deploy_package() {
  rm -rf "$RELEASE_DIR"
  mkdir -p "$RELEASE_DIR"

  if tar --warning=no-timestamp -tf "$PACKAGE_PATH" >/dev/null 2>&1; then
    tar --warning=no-timestamp -xzf "$PACKAGE_PATH" -C "$RELEASE_DIR"
  else
    tar -xzf "$PACKAGE_PATH" -C "$RELEASE_DIR"
  fi

  for entry in .env.example app artisan bootstrap composer.json composer.lock config database public release.json resources routes vendor; do
    if [[ ! -e "$RELEASE_DIR/$entry" ]]; then
      echo "Release package missing required entry: $entry" >&2
      exit 1
    fi
  done

  cd "$APP_DIR"

  "$PHP_BIN" artisan down || true
  MAINTENANCE_DOWN=1

  rsync -a --delete \
    --exclude=".env" \
    --exclude=".user.ini" \
    --exclude="storage/" \
    --exclude="public/storage" \
    --exclude="public/app_update/" \
    --exclude="public/.user.ini" \
    "$RELEASE_DIR/" "$APP_DIR/"

  ensure_runtime_paths

  "$PHP_BIN" artisan migrate --force
  "$PHP_BIN" artisan storage:link || true
  "$PHP_BIN" artisan optimize:clear
  "$PHP_BIN" artisan config:cache
  "$PHP_BIN" artisan route:cache
  "$PHP_BIN" artisan view:cache
  "$PHP_BIN" artisan up
  MAINTENANCE_DOWN=0
}

echo "GX-OM backend update"
echo "Root: $APP_DIR"
echo "Tag: $TAG"
echo "Log: $LOG_PATH"

rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"

if ! download_from_github; then
  manual_upload_fallback
fi

verify_package
deploy_package

echo "Backend updated to $TAG"
echo "Log: $LOG_PATH"
