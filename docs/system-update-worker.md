# System Update Script

系统更新改为脚本安装模式。管理后台只负责检查 GitHub Release 元数据、校验并保存上传包；真正安装必须在服务器 SSH 终端执行 `scripts/update-backend.sh`。

## 流程

1. 管理后台点击“检查更新”，后端只读取 GitHub Release 元数据。
2. 私有仓库 release 包由管理员手动登录 GitHub 下载，不要求服务器持有 GitHub token。
3. 管理后台上传 `gx-om-backend-vX.Y.Z.tar.gz` 并填写 SHA256。
4. 后端校验 SHA256，保存包到 `storage/app/system_updates/uploads/{tag}/`，返回可复制的脚本命令。
5. 管理员 SSH 登录服务器执行脚本命令。

`POST /api/system-updates/install`、`POST /api/system-updates/runs/{run}/install` 和 `POST /api/system-updates/runs/{run}/queue` 已废弃，返回 410。

## 手动安装命令

```bash
cd /www/wwwroot/api-gx-om.hrlni.cn
bash scripts/update-backend.sh \
  /www/wwwroot/api-gx-om.hrlni.cn/storage/app/system_updates/uploads/v1.0.13/run-6-gx-om-backend-v1.0.13.tar.gz \
  <sha256>
```

也可以直接把包上传到服务器任意目录后执行：

```bash
cd /www/wwwroot/api-gx-om.hrlni.cn
bash scripts/update-backend.sh /www/wwwroot/packages/gx-om-backend-v1.0.13.tar.gz <sha256>
```

宝塔 PHP 路径或运行用户不同时，可以通过环境变量覆盖：

```bash
cd /www/wwwroot/api-gx-om.hrlni.cn
PHP_BIN=/www/server/php/82/bin/php WEB_USER=www WEB_GROUP=www \
  bash scripts/update-backend.sh /www/wwwroot/packages/gx-om-backend-v1.0.13.tar.gz <sha256>
```

## 脚本保护

- 安装前校验包存在、SHA256 和必需 release entries。
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
