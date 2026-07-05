#!/usr/bin/env bash
set -Eeuo pipefail

if [[ -n "${ROOT_DIR:-}" ]]; then
  ROOT_DIR="$(cd "$ROOT_DIR" && pwd)"
elif [[ -n "${BASH_SOURCE[0]:-}" && -f "${BASH_SOURCE[0]}" ]]; then
  ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
else
  ROOT_DIR="$(pwd)"
fi

PHP_BIN="${PHP_BIN:-php}"
WEB_USER="${WEB_USER:-www}"
WEB_GROUP="${WEB_GROUP:-www}"
PACKAGE_PATH=""
EXPECTED_SHA256=""
RELEASE_TAG=""

usage() {
  cat >&2 <<'USAGE'
Usage:
  bash scripts/update-backend.sh /path/to/gx-om-backend-vX.Y.Z.tar.gz <sha256>
  bash scripts/update-backend.sh --tag vX.Y.Z

Options:
  --tag <tag>          Download gx-om-backend-<tag>.tar.gz from a private GitHub Release.
  --sha256 <sha256>   Override the release .sha256 asset when using --tag.
  --root <path>       Deployment root. Defaults to the script parent, or current directory when piped to bash.
  --php-bin <path>    PHP binary. Defaults to php from PATH.
  --web-user <user>   FPM user for writable runtime paths. Defaults to www.
  --web-group <group> FPM group for writable runtime paths. Defaults to www.

.env values used by --tag:
  GITHUB_RELEASE_TOKEN, SYSTEM_UPDATE_GITHUB_TOKEN, or GITHUB_TOKEN
  GITHUB_RELEASE_OWNER or SYSTEM_UPDATE_GITHUB_OWNER (default: YAOmeihah)
  GITHUB_RELEASE_REPO or SYSTEM_UPDATE_GITHUB_REPO (default: GX-OM-backend)
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --tag)
      RELEASE_TAG="${2:-}"
      shift 2
      ;;
    --sha256)
      EXPECTED_SHA256="${2:-}"
      shift 2
      ;;
    --root)
      ROOT_DIR="$(cd "${2:-}" && pwd)"
      shift 2
      ;;
    --php-bin)
      PHP_BIN="${2:-}"
      shift 2
      ;;
    --web-user)
      WEB_USER="${2:-}"
      shift 2
      ;;
    --web-group)
      WEB_GROUP="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    -*)
      echo "Unknown option: $1" >&2
      usage
      exit 1
      ;;
    *)
      if [[ -z "$PACKAGE_PATH" ]]; then
        PACKAGE_PATH="$1"
      elif [[ -z "$EXPECTED_SHA256" ]]; then
        EXPECTED_SHA256="$1"
      else
        echo "Unexpected argument: $1" >&2
        usage
        exit 1
      fi
      shift
      ;;
  esac
done

TIMESTAMP="$(date +'%Y%m%d%H%M%S')"
WORK_DIR="$ROOT_DIR/storage/app/system_updates/manual"
DOWNLOAD_DIR="$WORK_DIR/downloads"
STAGING_DIR="$WORK_DIR/staging/$TIMESTAMP"
BACKUP_DIR="$WORK_DIR/backups/$TIMESTAMP"
LOG_PATH="$ROOT_DIR/storage/logs/manual-update-$TIMESTAMP.log"
MAINTENANCE_DOWN=0

mkdir -p "$ROOT_DIR/storage/logs" "$DOWNLOAD_DIR" "$WORK_DIR/staging" "$WORK_DIR/backups"
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

ensure_php_bin() {
  if [[ "$PHP_BIN" == */* ]]; then
    if [[ ! -x "$PHP_BIN" ]]; then
      echo "PHP binary is not executable: $PHP_BIN" >&2
      exit 1
    fi

    return 0
  fi

  if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "PHP binary not found: $PHP_BIN. Set PHP_BIN or pass --php-bin." >&2
    exit 1
  fi
}

ensure_php_bin

read_env_value() {
  local key="$1"
  local value="${!key:-}"

  if [[ -z "$value" && -f "$ROOT_DIR/.env" ]]; then
    value="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$ROOT_DIR/.env" | tail -n 1 | sed -E 's/^[^=]+=//' || true)"
  fi

  value="$(printf '%s' "$value" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//; s/\r$//; s/^"//; s/"$//; s/^'\''//; s/'\''$//')"
  printf '%s' "$value"
}

github_api_get() {
  local url="$1"
  local output="$2"
  local accept="${3:-application/vnd.github+json}"

  curl -fL --retry 3 --connect-timeout 20 \
    -H "Authorization: Bearer $GITHUB_AUTH_TOKEN" \
    -H "Accept: $accept" \
    -H "X-GitHub-Api-Version: 2022-11-28" \
    "$url" \
    -o "$output"
}

json_asset_id_by_name() {
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

download_release_package_by_tag() {
  if [[ ! "$RELEASE_TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "--tag must look like vX.Y.Z." >&2
    exit 1
  fi

  GITHUB_AUTH_TOKEN="$(read_env_value GITHUB_RELEASE_TOKEN)"
  if [[ -z "$GITHUB_AUTH_TOKEN" ]]; then
    GITHUB_AUTH_TOKEN="$(read_env_value SYSTEM_UPDATE_GITHUB_TOKEN)"
  fi
  if [[ -z "$GITHUB_AUTH_TOKEN" ]]; then
    GITHUB_AUTH_TOKEN="$(read_env_value GITHUB_TOKEN)"
  fi

  if [[ -z "$GITHUB_AUTH_TOKEN" ]]; then
    echo "GITHUB_RELEASE_TOKEN, SYSTEM_UPDATE_GITHUB_TOKEN, or GITHUB_TOKEN is required in $ROOT_DIR/.env for --tag mode." >&2
    exit 1
  fi

  local owner
  local repo
  local api_url
  owner="$(read_env_value GITHUB_RELEASE_OWNER)"
  if [[ -z "$owner" ]]; then
    owner="$(read_env_value SYSTEM_UPDATE_GITHUB_OWNER)"
  fi
  repo="$(read_env_value GITHUB_RELEASE_REPO)"
  if [[ -z "$repo" ]]; then
    repo="$(read_env_value SYSTEM_UPDATE_GITHUB_REPO)"
  fi
  api_url="$(read_env_value GITHUB_API_URL)"
  if [[ -z "$api_url" ]]; then
    api_url="$(read_env_value SYSTEM_UPDATE_GITHUB_API_URL)"
  fi
  owner="${owner:-YAOmeihah}"
  repo="${repo:-GX-OM-backend}"
  api_url="${api_url:-https://api.github.com}"

  local release_dir="$DOWNLOAD_DIR/$RELEASE_TAG"
  local release_json="$release_dir/release.json"
  local package_name="gx-om-backend-${RELEASE_TAG}.tar.gz"
  local sha_name="${package_name}.sha256"
  local package_asset_id
  local sha_asset_id
  local sha_file="$release_dir/$sha_name"

  mkdir -p "$release_dir"

  echo "Downloading GitHub release metadata: $owner/$repo $RELEASE_TAG"
  github_api_get "$api_url/repos/$owner/$repo/releases/tags/$RELEASE_TAG" "$release_json"

  package_asset_id="$(json_asset_id_by_name "$release_json" "$package_name")"
  sha_asset_id="$(json_asset_id_by_name "$release_json" "$sha_name")"

  PACKAGE_PATH="$release_dir/$package_name"

  echo "Downloading release package asset: $package_name"
  github_api_get "$api_url/repos/$owner/$repo/releases/assets/$package_asset_id" "$PACKAGE_PATH" "application/octet-stream"

  if [[ -z "$EXPECTED_SHA256" ]]; then
    echo "Downloading release checksum asset: $sha_name"
    github_api_get "$api_url/repos/$owner/$repo/releases/assets/$sha_asset_id" "$sha_file" "application/octet-stream"
    EXPECTED_SHA256="$(awk '{print tolower($1)}' "$sha_file")"
  fi
}

if [[ -n "$RELEASE_TAG" ]]; then
  download_release_package_by_tag
fi

if [[ -z "$PACKAGE_PATH" || -z "$EXPECTED_SHA256" ]]; then
  usage
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
