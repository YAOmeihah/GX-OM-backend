# System Update Worker

系统更新现在采用手动上传 + CLI worker 安装模式。

## 流程

1. 管理后台点击“检查更新”，后端只读取 GitHub Release 元数据。
2. 管理员手动打开 GitHub Release 页面下载 `gx-om-backend-vX.Y.Z.tar.gz`。
3. 管理后台上传更新包并填写 SHA256。
4. 后端校验 SHA256，保存包到 `storage/app/system_updates/uploads/{tag}/`，创建 `uploaded` 任务。
5. 管理后台点击“开始安装”，HTTP 只把任务置为 `queued`。
6. CLI worker 执行安装并写入进度、日志、失败原因。

`POST /api/system-updates/install` 和 `POST /api/system-updates/runs/{run}/install` 已废弃，返回 410。

## Worker 命令

```bash
cd /www/wwwroot/api-gx-om.hrlni.cn
/www/server/php/82/bin/php artisan system-update:worker --once
```

也可以手动执行指定任务：

```bash
cd /www/wwwroot/api-gx-om.hrlni.cn
/www/server/php/82/bin/php artisan system-update:run 123
```

## Cron 示例

```cron
* * * * * cd /www/wwwroot/api-gx-om.hrlni.cn && /www/server/php/82/bin/php -d memory_limit=512M artisan system-update:worker --once >> storage/logs/system-update-worker.log 2>&1
```

worker 使用 `system-update:worker` 缓存锁避免并发安装。若页面长时间停在 `queued`、`verifying` 或 `running`，请检查 cron 是否运行，以及 `storage/logs/system-update-worker.log`。

## 安装保护

- 安装前校验 tag、SHA256、tar.gz archive entry。
- archive 校验使用 gzip/tar 流式读取，避免大包在 verifying 阶段占满内存。
- 拒绝绝对路径、`../`、symlink、hardlink 和受保护文件。
- 安装会先解压到 staging，再备份 managed entries，最后替换。
- `.env`、`storage/`、`public/storage`、上传目录和备份目录会保留。
- 安装失败会尝试 rollback，并把任务写为 `failed`，记录 `error_message`。
