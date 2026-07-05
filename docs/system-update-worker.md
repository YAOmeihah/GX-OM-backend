# System Update Script

系统更新改为服务器脚本安装模式。管理后台只负责检查 GitHub Release 元数据和展示命令；真正安装在服务器 SSH 终端执行，不再让 FPM 请求下载包或替换代码。

## 推荐流程

1. 管理后台点击“检查更新”，后端只读取 GitHub Release 元数据。
2. 页面展示最新 tag、发布时间、release notes、包名、SHA256 和脚本命令。
3. 管理员 SSH 登录服务器，在项目根目录执行页面给出的 `curl | bash` 命令。
4. 脚本从当前目录 `.env` 读取 GitHub token，通过 GitHub API 下载私有 release 包和 `.sha256`。
5. 脚本校验 SHA256，进入维护模式，备份 managed entries，替换代码，执行迁移和缓存清理。

`POST /api/system-updates/install`、`POST /api/system-updates/runs/{run}/install` 和 `POST /api/system-updates/runs/{run}/queue` 已废弃，返回 410。

## .env 配置

服务器项目根目录 `.env` 至少配置一个 token。推荐使用细粒度 token，只给私有仓库 release/contents 读取权限。

```env
GITHUB_RELEASE_TOKEN=ghp_xxx
GITHUB_RELEASE_OWNER=YAOmeihah
GITHUB_RELEASE_REPO=GX-OM-backend
```

脚本也兼容现有后端配置名：

```env
SYSTEM_UPDATE_GITHUB_TOKEN=ghp_xxx
SYSTEM_UPDATE_GITHUB_OWNER=YAOmeihah
SYSTEM_UPDATE_GITHUB_REPO=GX-OM-backend
```

## 远程脚本安装

把 `<tag>` 换成要安装的版本，例如 `v1.0.14`：

```bash
cd /www/wwwroot/api-gx-om.hrlni.cn
TOKEN="$(
  /www/server/php/82/bin/php -r '$env=parse_ini_file(".env", false, INI_SCANNER_RAW) ?: []; echo $env["GITHUB_RELEASE_TOKEN"] ?? $env["SYSTEM_UPDATE_GITHUB_TOKEN"] ?? $env["GITHUB_TOKEN"] ?? "";'
)"
test -n "$TOKEN" || { echo "Missing GitHub token in .env"; exit 1; }
curl -fsSL \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/vnd.github.raw+json" \
  -H "X-GitHub-Api-Version: 2022-11-28" \
  "https://api.github.com/repos/YAOmeihah/GX-OM-backend/contents/scripts/update-backend.sh?ref=<tag>" \
  | bash -s -- --tag <tag>
```

这条命令做两件事：

- `curl` 通过 GitHub API 拉取私有仓库里对应 tag 的 `scripts/update-backend.sh`。
- `bash --tag <tag>` 执行脚本；脚本会再次读取 `.env` token，并下载 `gx-om-backend-<tag>.tar.gz` 和 `.sha256` release asset。

## 本地包备用模式

如果服务器不能访问 GitHub，可以手动把 release 包上传到服务器后执行：

```bash
cd /www/wwwroot/api-gx-om.hrlni.cn
bash scripts/update-backend.sh /www/wwwroot/packages/gx-om-backend-v1.0.14.tar.gz <sha256>
```

宝塔 PHP 路径或运行用户不同时，可以通过参数覆盖：

```bash
cd /www/wwwroot/api-gx-om.hrlni.cn
bash scripts/update-backend.sh \
  --php-bin /www/server/php/82/bin/php \
  --web-user www \
  --web-group www \
  /www/wwwroot/packages/gx-om-backend-v1.0.14.tar.gz \
  <sha256>
```

## 脚本保护

- 安装前校验 tag、包存在、SHA256 和必需 release entries。
- 安装前执行 `artisan down`，结束时执行 `artisan up`；失败时也会尝试 `artisan up`。
- 安装前备份 managed entries 到 `storage/app/system_updates/manual/backups/{timestamp}`。
- 覆盖代码时保留：
  - `.env`
  - `storage/`
  - `public/storage`
  - `public/.user.ini`
  - `public/app_update`
  - `bootstrap/cache`
- 安装后自动创建运行目录并修复 `storage`、`bootstrap/cache`、`public/app_update` 权限。
- 安装后执行 `artisan migrate --force`、`artisan optimize:clear`、`artisan storage:link --force`。
- 日志写入 `storage/logs/manual-update-{timestamp}.log`。
