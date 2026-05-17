# 本地 Demo 数据填充命令设计

## 背景

当前后端本地运行环境使用 `GX-OM-backend/.env`，数据库连接为 MySQL `qkdemo`。现有 `DatabaseSeeder` 只初始化角色、默认管理员和权限，不生成门店、客户、账单、还款、折扣、附件、分享、审计和维护场景数据。

自动化测试依赖 PHPUnit 的 SQLite 内存库和 factories，因此当前测试套件可以通过；但本地手工联调、Android/admin 端完整流程验证缺少稳定的业务数据。需要新增一个可重复执行的本地 demo 数据命令。

## 目标

新增一个 Artisan 命令：

```bash
php artisan dev:seed-demo
```

命令默认执行“只清理并重建 demo 数据”的安全策略：

- 清理上一批带 `DEMO-` 固定标记的数据。
- 保留本地手动创建的普通数据。
- 重建覆盖完整后端模块的稳定 demo 数据。
- 每次运行后数据形态确定，方便复现接口、页面和移动端流程。

另提供只清理模式：

```bash
php artisan dev:seed-demo --clean
```

该模式只删除 demo 数据，不重建。

## 非目标

- 不做整库 `migrate:fresh`。
- 不删除未带 demo 标记的本地数据。
- 不把 demo 数据并入默认 `DatabaseSeeder`，避免生产或普通部署误灌业务样例。
- 不依赖 SQL dump，避免迁移变化和跨数据库兼容问题。

## 命令行为

`php artisan dev:seed-demo` 的流程：

1. 确认运行环境允许本地 demo 数据填充。
2. 调用现有基础 seeder，确保角色、权限、默认 admin 存在。
3. 按依赖顺序清理旧 demo 数据。
4. 创建 demo 门店、用户、客户、账单、明细、还款、分配、折扣、分享 token、附件、审计日志和维护异常项。
5. 同步 `customer_store_stats`。
6. 输出登录账号、关键样例编号和数据统计。

`php artisan dev:seed-demo --clean` 的流程：

1. 确认运行环境允许清理 demo 数据。
2. 按依赖顺序删除带 `DEMO-` 标记的数据。
3. 输出清理统计。

## Demo 标记策略

所有可清理的数据必须有明确 demo 标记：

- 门店：`stores.code` 使用 `DEMO-A`、`DEMO-B`、`DEMO-C`。
- 用户：`users.email` 使用 `demo.*@example.com`，`username` 使用 `demo_*`。
- 客户：`customers.remarks` 或名称带 `DEMO-` 场景标签。
- 账单：`invoices.invoice_number` 使用 `DEMO-INV-*`。
- 还款：`payments.payment_number` 使用 `DEMO-PAY-*`。
- 附件：`attachments.path` 或 `original_name` 使用 `demo/`、`DEMO-*`。
- 分享 token：关联 demo 客户、demo 门店或 demo 账单。
- 审计日志：关联 demo 用户、demo 门店、demo 模型，或 message/description 使用 `DEMO-`。
- 维护异常项：只创建可由 demo 标记追踪的数据，避免误删真实异常。

清理必须优先删除子表，再删除父表，避免外键约束问题。

## 模块覆盖

### 认证、用户、角色和权限

生成 6 个 demo 用户：

- `demo.admin@example.com`：系统管理员，拥有所有权限。
- `demo.owner.a@example.com`：门店 A 店长。
- `demo.owner.b@example.com`：门店 B 店长。
- `demo.staff.a@example.com`：门店 A 店员。
- `demo.staff.b@example.com`：门店 B 店员。
- `demo.multi@example.com`：跨门店用户，关联门店 A 和 B。

复用现有 `RoleSeeder`、`AdminSeeder`、`PermissionSeeder`，确保 3 个系统角色和权限绑定存在。

### 门店

生成 3 个 demo 门店：

- 门店 A：主流程数据最多，用于账单、还款、折扣、附件、分享、维护。
- 门店 B：用于权限隔离、跨门店客户、跨门店统计。
- 门店 C：少量数据，用于空态和边界场景。

每个门店填充基础信息和支付码字段，覆盖 `stores` 列表、详情、用户列表、支付码接口。

### 客户

生成 12 到 15 个客户：

- 正常客户。
- 欠款客户。
- 无欠款客户。
- 逾期客户。
- 有折扣客户。
- 有核销客户。
- 有多门店同名或同手机号场景的客户。
- 有附件和分享账单的客户。
- 用于筛选的手机号、邮箱、地址、备注明显不同的客户。

客户必须覆盖 `customers` CRUD、客户欠款、每日未付汇总、按账单汇总、清欠和门店访问控制。

### 账单和明细

生成 40 到 50 个账单，80 到 100 条明细：

- 未付账单。
- 部分支付账单。
- 已结清账单。
- 逾期账单。
- 今日账单。
- 本月账单。
- 历史老账单。
- 有多明细账单。
- 明细 `item_name` 为空的账单。
- 可安全删除的账单。
- 因存在付款、分配或折扣而不可破坏性修改/删除的账单。

账单编号使用固定前缀，金额设计成可解释的业务场景，方便人工验证。

### 还款、分配和自动分配

生成 25 到 30 个还款，30 到 40 条分配：

- 未分配还款。
- 部分分配还款。
- 全部分配还款。
- 现金、银行转账、微信、支付宝、其他支付方式。
- 单笔付款分配到单账单。
- 单笔付款分配到多账单。
- 可撤销分配。
- 可触发自动分配建议的未付账单组合。

数据必须覆盖付款列表、详情、创建、分配、批量分配、自动分配、撤销分配和批量撤销。

### 折扣、促销和坏账核销

生成 10 到 12 条折扣记录：

- `write_off`：坏账核销。
- `discount`：折扣。
- `promotion`：促销优惠。
- 管理员可处理的大额核销。
- 店长可处理的门店内优惠。
- 店员权限不足的对照场景。
- 用于折扣统计和客户债务计算的场景。

折扣数据必须能覆盖缺口检测、应用折扣、折扣统计、客户实际欠款、账单实际剩余金额。

### 仪表盘和客户统计

通过账单、还款、折扣组合生成：

- 总客户数。
- 总账单数。
- 未付金额。
- 已收金额。
- 门店维度欠款。
- 今日、本月、历史数据。

填充结束后调用 `CustomerStatsService` 或现有同步命令逻辑，确保 `customer_store_stats` 与业务数据一致。

### 公开账单分享

生成 5 到 6 个分享 token：

- 单账单固定分享。
- 多账单固定分享。
- 动态分享。
- 已过期分享。
- 有访问日志的分享。

覆盖公开账单接口、手机号脱敏、过期判断、访问日志。

### 附件和上传意图

生成 10 到 12 条附件相关记录：

- 账单图片附件。
- 账单 PDF 附件。
- 还款凭证附件。
- 文档附件。
- 附件上传意图。

附件记录使用模拟路径，不要求真实 S3 文件存在；用于列表、关联、删除和审计验证。S3 连通性测试仍由开发者本地配置决定。

### 配置

生成一组本地 demo S3 runtime config，使用明显不可用于生产的占位值。该配置只用于让配置页面有可展示数据，不保证真实连通。

### 审计日志

生成约 40 条审计日志：

- 用户登录或用户管理类日志。
- 门店、客户、账单、还款、附件相关日志。
- 不同操作类型和严重级别。
- 有门店作用域、客户作用域、模型作用域的日志。
- 部分历史日志，用于清理扫描。

覆盖审计日志列表、详情、统计、筛选、历史、用户活动。

### 维护扫描

生成少量可扫描的 demo 异常项：

- 历史已结清账单和付款，用于历史清理。
- 孤立账单明细。
- 孤立还款分配。
- 金额不一致账单。
- 状态不一致账单。
- 过期分享 token。
- 历史审计日志。

异常数据必须带 demo 标记，并且清理命令能删除或重建它们。

## 数据量汇总

- 门店：3 个。
- 用户：6 个。
- 客户：12 到 15 个。
- 账单：40 到 50 个。
- 账单明细：80 到 100 条。
- 还款：25 到 30 个。
- 还款分配：30 到 40 条。
- 折扣：10 到 12 条。
- 分享 token：5 到 6 个。
- 附件和上传意图：10 到 12 条。
- 审计日志：约 40 条。
- 维护异常项：8 到 10 项。

## 架构

新增两个主要类：

- `app/Console/Commands/DevSeedDemoCommand.php`
- `database/seeders/DevDemoSeeder.php`

命令负责参数、环境保护、输出和事务边界。Seeder 负责数据创建与清理。必要时可以在 Seeder 内拆分私有方法，例如：

- `seedUsersAndStores`
- `seedCustomers`
- `seedInvoicesAndItems`
- `seedPaymentsAndAllocations`
- `seedDiscounts`
- `seedShareTokens`
- `seedAttachments`
- `seedAuditLogs`
- `seedMaintenanceScenarios`
- `syncStats`
- `cleanDemoData`

优先使用 Eloquent 和现有模型方法，不直接依赖原始 SQL。只有在模拟孤立数据等维护场景时，才允许在明确禁用/恢复外键或按数据库兼容方式写入。

## 环境保护

命令应避免误在生产环境运行：

- `production` 环境默认拒绝执行。
- 仅允许 `local`、`testing` 或显式 `--force`。
- 输出将要清理和创建的数据范围。

## 错误处理

- 默认填充使用数据库事务；发生异常时回滚本轮创建。
- 清理阶段按依赖顺序执行，尽量避免部分删除。
- 如果 `--clean` 失败，输出失败阶段和异常信息。
- 如果统计同步失败，命令返回失败码，避免用户误以为数据完整。

## 测试策略

采用 TDD 实现，新增 Feature 测试覆盖：

- `dev:seed-demo --clean` 能清理 demo 数据且保留非 demo 数据。
- `dev:seed-demo` 能创建核心模块数据量。
- 命令重复运行后 demo 数据数量稳定，不无限增长。
- demo 用户角色和门店归属正确。
- demo 数据覆盖未付、部分支付、已付、折扣、分享、附件、审计和维护异常场景。
- `customer_store_stats` 在填充后存在并与关键客户/门店组合匹配。

验收命令：

```bash
php artisan test --filter=DevSeedDemoCommandTest --compact
php artisan test --compact
```

手工验收命令：

```bash
php artisan dev:seed-demo
php artisan dev:seed-demo
php artisan dev:seed-demo --clean
```

第二次运行后核心 demo 数据数量应与第一次一致；`--clean` 后 demo 业务数据应被删除，基础角色权限和非 demo 数据应保留。

