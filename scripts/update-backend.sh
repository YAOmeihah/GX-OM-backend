#!/usr/bin/env bash
set -Eeuo pipefail

ORIGINAL_DIR="$(pwd)"
APP_DIR="$ORIGINAL_DIR"
TAG=""
PHP_BIN="${PHP_BIN:-php}"
WEB_USER="${WEB_USER:-www}"
WEB_GROUP="${WEB_GROUP:-www}"
OWNER_DEFAULT="YAOmeihah"
REPO_DEFAULT="GX-OM-backend"
DOWNLOAD_TIMEOUT="${DOWNLOAD_TIMEOUT:-600}"
CONNECT_TIMEOUT="${CONNECT_TIMEOUT:-10}"
ROOT_ARGUMENT_PROVIDED=0

usage() {
  cat >&2 <<'USAGE'
用法:
  bash update-backend.sh --tag v1.0.16 [--root /www/wwwroot/api-gx-om.hrlni.cn]

脚本流程:
  1. 从 .env 读取 GitHub token。
  2. 尝试从 GitHub Release 下载 gx-om-backend-<tag>.tar.gz 和 .sha256。
  3. 如果下载失败或超时，等待你把更新包上传到项目根目录。
  4. 如果存在校验文件，则校验 SHA256。
  5. 部署更新包，并保留 .env、storage、public/storage、public/app_update、.user.ini。

.env token 变量:
  SYSTEM_UPDATE_GITHUB_TOKEN, GITHUB_RELEASE_TOKEN, or GITHUB_TOKEN

.env 可选仓库变量:
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
      ROOT_ARGUMENT_PROVIDED=1
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

      echo "未知参数: $1" >&2
      usage
      exit 1
      ;;
  esac
done

app_root_markers() {
  cat <<'MARKERS'
.env
artisan
app/
bootstrap/app.php
config/
database/
public/
resources/
routes/
MARKERS
}

missing_app_root_markers() {
  local dir="$1"
  local missing=0

  while IFS= read -r marker; do
    if [[ "$marker" == */ ]]; then
      [[ -d "$dir/${marker%/}" ]] && continue
    else
      [[ -f "$dir/$marker" ]] && continue
    fi

    echo "  - $marker"
    missing=1
  done < <(app_root_markers)

  return "$missing"
}

is_app_root() {
  local dir="$1"

  [[ -d "$dir" ]] || return 1
  [[ -f "$dir/.env" ]] || return 1
  [[ -f "$dir/artisan" ]] || return 1
  [[ -f "$dir/bootstrap/app.php" ]] || return 1
  [[ -d "$dir/app" ]] || return 1
  [[ -d "$dir/config" ]] || return 1
  [[ -d "$dir/database" ]] || return 1
  [[ -d "$dir/public" ]] || return 1
  [[ -d "$dir/resources" ]] || return 1
  [[ -d "$dir/routes" ]] || return 1
}

print_root_suggestions() {
  local candidate
  local candidates=(
    "$ORIGINAL_DIR/.."
    "$ORIGINAL_DIR/../.."
    "/www/wwwroot/api-gx-om.hrlni.cn"
  )

  for candidate in "${candidates[@]}"; do
    if candidate="$(cd "$candidate" 2>/dev/null && pwd)" && is_app_root "$candidate"; then
      echo "检测到可能的正确目录:"
      echo "  $candidate"
      echo "可以这样执行:"
      echo "  cd $candidate"
      echo "  # 然后重新执行更新命令"
      echo "或者传入:"
      echo "  --root $candidate"
      return 0
    fi
  done

  echo "请确认你在 Laravel 后端根目录执行，或传入 --root /www/wwwroot/api-gx-om.hrlni.cn。"
}

if ! APP_DIR="$(cd "$APP_DIR" 2>/dev/null && pwd)"; then
  echo "项目根目录不存在或不可访问: $APP_DIR" >&2
  echo "请先 cd /www/wwwroot/api-gx-om.hrlni.cn，或传入 --root /www/wwwroot/api-gx-om.hrlni.cn。" >&2
  exit 1
fi

if [[ ! "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "--tag 必须是 vX.Y.Z 格式，例如 v1.0.16。" >&2
  exit 1
fi

if ! is_app_root "$APP_DIR"; then
  echo "项目根目录无效: $APP_DIR" >&2
  echo "当前执行目录: $ORIGINAL_DIR" >&2
  if [[ "$ROOT_ARGUMENT_PROVIDED" == "1" ]]; then
    echo "你传入的 --root 目录不是有效的 GX-OM 后端根目录。" >&2
  else
    echo "你当前不在有效的 GX-OM 后端根目录。" >&2
  fi
  echo "缺少以下必要标记:" >&2
  missing_app_root_markers "$APP_DIR" >&2 || true
  print_root_suggestions >&2
  exit 1
fi

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  echo "找不到 PHP 可执行文件: $PHP_BIN" >&2
  echo "请设置 PHP_BIN=/www/server/php/82/bin/php，或传入 --php-bin。" >&2
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
STEP=0
STEP_TOTAL=13
CURRENT_STEP="initializing"

mkdir -p "$APP_DIR/storage/logs"
exec > >(tee -a "$LOG_PATH") 2>&1

timestamp() {
  date '+%Y-%m-%d %H:%M:%S'
}

log() {
  printf '[%s] %s\n' "$(timestamp)" "$*"
}

detail() {
  log "  $*"
}

step() {
  STEP=$((STEP + 1))
  CURRENT_STEP="$1"
  log "[$STEP/$STEP_TOTAL] $CURRENT_STEP"
}

file_size() {
  du -h "$1" 2>/dev/null | awk '{print $1}'
}

release_value() {
  local key="$1"
  local path="$APP_DIR/release.json"

  [[ -f "$path" ]] || return 0

  sed -nE "s/.*\"$key\"[[:space:]]*:[[:space:]]*\"([^\"]*)\".*/\1/p" "$path" | head -n 1
}

run_artisan() {
  detail "$PHP_BIN artisan $*"
  "$PHP_BIN" "$APP_DIR/artisan" "$@" \
    2> >(grep -vE '^PHP Warning:  Module "(mbstring|exif)" is already loaded in Unknown on line 0$' >&2 || true)
}

preflight_environment() {
  local command_name
  local missing_commands=0

  step "预检项目目录和命令"
  detail "脚本启动目录: $ORIGINAL_DIR"
  if [[ "$ROOT_ARGUMENT_PROVIDED" == "1" ]]; then
    detail "使用 --root 指定项目根目录: $APP_DIR"
  else
    detail "使用当前目录作为项目根目录: $APP_DIR"
  fi

  detail "项目根目录标记检查通过。"
  for command_name in curl tar rsync sha256sum grep sed awk du; do
    if command -v "$command_name" >/dev/null 2>&1; then
      detail "命令可用: $command_name ($(command -v "$command_name"))"
      continue
    fi

    echo "缺少必要命令: $command_name" >&2
    missing_commands=1
  done

  if [[ "$missing_commands" == "1" ]]; then
    echo "服务器缺少更新所需命令，请先安装后再重试。" >&2
    exit 1
  fi

  if [[ ! -w "$APP_DIR" ]]; then
    echo "当前用户没有项目根目录写入权限: $APP_DIR" >&2
    echo "请使用有权限的用户执行，例如 root 或站点部署用户。" >&2
    exit 1
  fi

  if [[ ! -f "$APP_DIR/composer.json" ]]; then
    detail "提醒: 当前根目录 composer.json 缺失，新版本包会恢复它。"
  fi

  if [[ ! -f "$APP_DIR/release.json" ]]; then
    detail "提醒: 当前根目录 release.json 缺失，新版本包会恢复它。"
  fi
}

on_error() {
  local code=$?

  log "更新失败，退出码: $code，失败阶段: $CURRENT_STEP"
  if [[ "$MAINTENANCE_DOWN" == "1" ]]; then
    run_artisan up || true
  fi
  log "日志文件: $LOG_PATH"

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
    echo "当前没有可交互终端，每 5 秒自动检查一次。"
    sleep 5
  fi
}

find_asset_id() {
  local json_path="$1"
  local asset_name="$2"

  "$PHP_BIN" -r '
    $json = json_decode(file_get_contents($argv[1]), true);
    if (! is_array($json)) {
        fwrite(STDERR, "GitHub Release JSON 无效。\n");
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
    detail "GitHub token: 未找到，将改为手动上传模式。"
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

  detail "GitHub token: 已找到（已隐藏）"
  detail "Release 接口: $owner/$repo $TAG"
  detail "下载超时: ${DOWNLOAD_TIMEOUT}s，连接超时: ${CONNECT_TIMEOUT}s"

  detail "正在获取 Release 元数据..."
  github_get "$api_url/repos/$owner/$repo/releases/tags/$TAG" "$release_json" || return 1

  package_asset_id="$(find_asset_id "$release_json" "$PACKAGE_NAME" 2>/dev/null || true)"
  checksum_asset_id="$(find_asset_id "$release_json" "$PACKAGE_NAME.sha256" 2>/dev/null || true)"

  if [[ -z "$package_asset_id" ]]; then
    echo "未找到 Release 资产: $PACKAGE_NAME"
    return 1
  fi

  detail "正在下载更新包: $PACKAGE_NAME"
  github_get "$api_url/repos/$owner/$repo/releases/assets/$package_asset_id" "$PACKAGE_PATH" "application/octet-stream" || return 1
  detail "更新包已保存: $PACKAGE_PATH ($(file_size "$PACKAGE_PATH"))"

  if [[ -n "$checksum_asset_id" ]]; then
    detail "正在下载校验文件: $PACKAGE_NAME.sha256"
    github_get "$api_url/repos/$owner/$repo/releases/assets/$checksum_asset_id" "$CHECKSUM_PATH" "application/octet-stream" || true
    if [[ -f "$CHECKSUM_PATH" ]]; then
      detail "校验文件已保存: $CHECKSUM_PATH"
    fi
  fi

  return 0
}

manual_upload_fallback() {
  local root_package="$APP_DIR/$PACKAGE_NAME"
  local root_checksum="$APP_DIR/$PACKAGE_NAME.sha256"

  detail "GitHub 下载失败或超时。"
  detail "请把这个文件上传到项目根目录:"
  detail "$root_package"
  detail "可选校验文件:"
  detail "$root_checksum"
  detail "上传完成后在这里按回车。脚本会一直等待，直到检测到更新包。"

  while [[ ! -f "$root_package" ]]; do
    wait_for_enter
    if [[ ! -f "$root_package" ]]; then
      detail "仍在等待文件: $root_package"
    fi
  done

  cp "$root_package" "$PACKAGE_PATH"
  detail "手动上传的更新包已复制: $PACKAGE_PATH ($(file_size "$PACKAGE_PATH"))"
  if [[ -f "$root_checksum" ]]; then
    cp "$root_checksum" "$CHECKSUM_PATH"
    detail "手动上传的校验文件已复制: $CHECKSUM_PATH"
  fi
}

verify_package() {
  if [[ ! -f "$PACKAGE_PATH" ]]; then
    echo "更新包不存在: $PACKAGE_PATH" >&2
    exit 1
  fi

  if [[ -f "$CHECKSUM_PATH" ]]; then
    detail "正在校验 SHA256: $CHECKSUM_PATH"
    (
      cd "$WORK_DIR"
      sha256sum -c "$PACKAGE_NAME.sha256"
    )
    detail "SHA256 校验通过。"
    return
  fi

  detail "没有找到 .sha256 文件，当前更新包 SHA256:"
  sha256sum "$PACKAGE_PATH"
  detail "如果确认继续部署，请输入 YES。"
  if [[ -r /dev/tty ]]; then
    read -r confirm </dev/tty
  else
    echo "当前没有可交互终端，且缺少校验文件，拒绝部署。" >&2
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

ensure_storage_link() {
  local link_path="$APP_DIR/public/storage"
  local target_path="$APP_DIR/storage/app/public"
  local resolved_link=""
  local resolved_target=""

  mkdir -p "$target_path"

  if [[ -L "$link_path" ]]; then
    resolved_link="$(readlink -f "$link_path" 2>/dev/null || true)"
    resolved_target="$(readlink -f "$target_path" 2>/dev/null || true)"

    if [[ -n "$resolved_link" && "$resolved_link" == "$resolved_target" ]]; then
      detail "public/storage 链接已存在且指向正确，跳过创建。"
      return 0
    fi

    detail "public/storage 链接已存在但指向不正确，正在重建。"
    detail "当前指向: ${resolved_link:-无法解析}"
    detail "期望指向: ${resolved_target:-$target_path}"
    rm -f "$link_path"
    run_artisan storage:link
    return 0
  fi

  if [[ -e "$link_path" ]]; then
    detail "public/storage 已存在但不是软链接，为避免误删线上文件，保留并跳过创建。"
    detail "如附件访问异常，请人工检查: $link_path"
    return 0
  fi

  run_artisan storage:link
}

deploy_package() {
  step "解压更新包"
  rm -rf "$RELEASE_DIR"
  mkdir -p "$RELEASE_DIR"
  detail "解压目录: $RELEASE_DIR"

  if tar --warning=no-timestamp -tf "$PACKAGE_PATH" >/dev/null 2>&1; then
    tar --warning=no-timestamp -xzf "$PACKAGE_PATH" -C "$RELEASE_DIR"
  else
    tar -xzf "$PACKAGE_PATH" -C "$RELEASE_DIR"
  fi

  step "检查更新包边界"
  for entry in .env.example app artisan bootstrap composer.json composer.lock config database public release.json resources routes vendor; do
    if [[ ! -e "$RELEASE_DIR/$entry" ]]; then
      echo "更新包缺少必要文件或目录: $entry" >&2
      exit 1
    fi
  done
  detail "必要文件和目录检查通过。"
  detail "发布元数据: $(tr -d '\n' <"$RELEASE_DIR/release.json")"

  cd "$APP_DIR"

  step "进入维护模式"
  run_artisan down || true
  MAINTENANCE_DOWN=1

  step "同步新版本文件"
  detail "使用 rsync --checksum --delete，按文件内容同步，并保护以下路径:"
  detail ".env, .user.ini, storage/, public/storage, public/app_update/, public/.user.ini"
  rsync -a --checksum --delete \
    --exclude=".env" \
    --exclude=".user.ini" \
    --exclude="storage/" \
    --exclude="public/storage" \
    --exclude="public/app_update/" \
    --exclude="public/.user.ini" \
    "$RELEASE_DIR/" "$APP_DIR/"
  cp -f "$RELEASE_DIR/release.json" "$APP_DIR/release.json"
  detail "版本元数据已强制刷新: $APP_DIR/release.json"

  step "检查运行时目录权限"
  ensure_runtime_paths
  detail "运行时目录已准备: bootstrap/cache, storage, public/app_update"

  step "执行数据库迁移"
  run_artisan migrate --force

  step "重建 Laravel 链接和缓存"
  ensure_storage_link
  run_artisan optimize:clear
  run_artisan config:cache
  run_artisan route:cache
  run_artisan view:cache

  step "校验安装版本"
  local installed_tag
  local installed_version
  installed_tag="$(release_value tag || true)"
  installed_version="$(release_value version || true)"
  detail "当前根目录 release.json: $installed_tag ($installed_version)"
  if [[ "$installed_tag" != "$TAG" ]]; then
    echo "安装版本校验失败: 期望 $TAG，实际 ${installed_tag:-未读取到 tag}" >&2
    exit 1
  fi

  step "恢复线上服务"
  run_artisan up
  MAINTENANCE_DOWN=0
}

log "GX-OM 后端更新开始"
detail "项目根目录: $APP_DIR"
detail "目标版本: $TAG"
detail "当前版本: $(release_value tag || true) ($(release_value version || true))"
detail "PHP 可执行文件: $PHP_BIN"
detail "临时工作目录: $WORK_DIR"
detail "日志文件: $LOG_PATH"

preflight_environment

step "准备临时工作目录"
rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"
detail "临时工作目录已准备: $WORK_DIR"

step "下载更新包或等待手动上传"
if ! download_from_github; then
  manual_upload_fallback
fi

step "校验更新包"
verify_package
deploy_package

detail "已安装版本: $(release_value tag || true) ($(release_value version || true))"
log "后端更新完成: $TAG"
log "日志文件: $LOG_PATH"
