# 权限系统升级文档

## ✅ 已完成的优化

本次升级在**完全不影响现有功能**的基础上，为系统增加了完整的权限管理功能。

---

## 📊 新增功能概览

### 1. **新增数据表**
- `permissions` - 权限表（31个权限）
- `permission_role` - 角色权限关联表

### 2. **新增模型方法**

#### **User 模型新增方法**
```php
// 获取用户所有权限
$user->permissions();

// 检查单个权限
$user->hasPermission('invoices.view');  // true/false

// 检查任一权限
$user->hasAnyPermission(['invoices.view', 'payments.view']);

// 检查所有权限
$user->hasAllPermissions(['invoices.view', 'payments.view']);

// 检查任一角色
$user->hasAnyRole(['admin', 'store_owner']);

// 获取权限列表（供前端使用）
$user->getPermissionsList();  // ['invoices.view', 'invoices.create', ...]

// 获取角色列表（供前端使用）
$user->getRolesList();  // ['admin']
```

#### **Role 模型新增方法**
```php
// 获取角色的权限
$role->permissions();

// 分配权限
$role->givePermissionTo('invoices.view');

// 批量分配权限
$role->syncPermissions(['invoices.view', 'payments.view']);

// 移除权限
$role->revokePermissionTo('invoices.delete');

// 检查权限
$role->hasPermission('invoices.view');
```

### 3. **新增 API 接口**

| 方法 | 路径 | 说明 | 权限要求 |
|------|------|------|----------|
| GET | `/api/permissions/my` | 获取当前用户权限 | 登录用户 |
| GET | `/api/permissions` | 获取所有权限（按模块分组） | 仅管理员 |
| GET | `/api/permissions/modules` | 获取所有模块列表 | 仅管理员 |
| GET | `/api/permissions/roles/{role}` | 获取角色的权限 | 仅管理员 |
| PUT | `/api/permissions/roles/{role}` | 更新角色权限 | 仅管理员 |

---

## 🎯 权限列表（共31个）

### 账单模块 (invoices)
- `invoices.view` - 查看账单
- `invoices.create` - 创建账单
- `invoices.update` - 编辑账单
- `invoices.delete` - 删除账单

### 还款模块 (payments)
- `payments.view` - 查看还款
- `payments.create` - 创建还款
- `payments.allocate` - 分配还款
- `payments.revoke` - 撤销分配
- `payments.discount` - 优惠减免
- `payments.delete` - 删除还款

### 客户模块 (customers)
- `customers.view` - 查看客户
- `customers.create` - 创建客户
- `customers.update` - 编辑客户
- `customers.delete` - 删除客户

### 门店模块 (stores)
- `stores.view` - 查看门店
- `stores.create` - 创建门店
- `stores.update` - 编辑门店
- `stores.delete` - 删除门店

### 用户模块 (users)
- `users.view` - 查看用户
- `users.create` - 创建用户
- `users.update` - 编辑用户
- `users.delete` - 删除用户
- `users.assign-roles` - 分配角色
- `users.assign-stores` - 分配门店

### 报表模块 (reports)
- `dashboard.view` - 查看仪表盘
- `reports.view` - 查看报表
- `reports.export` - 导出数据

### 其他模块
- `audit-logs.view` - 查看审计日志
- `config.manage` - 系统配置
- `attachments.upload` - 上传附件
- `attachments.delete` - 删除附件

---

## 👥 角色权限分配

### 管理员 (admin)
- **31个权限**（全部权限）
- 无任何限制

### 店长 (store_owner)
- **21个权限**
- 包含：所有账单、还款、客户管理权限
- 包含：查看门店、报表、审计日志
- 不包含：用户管理、门店创建/编辑/删除

### 店员 (store_staff)
- **9个权限**
- 包含：查看和创建账单
- 包含：查看和创建还款
- 包含：查看、创建、编辑客户
- 包含：查看仪表盘
- 不包含：删除操作、权限管理

---

## 💻 使用示例

### 1. **后端 Controller 中使用**

```php
// 在 Controller 中检查权限
public function index()
{
    $user = auth()->user();

    // 方式1：直接检查
    if (!$user->hasPermission('invoices.view')) {
        return $this->errorResponse('权限不足', 403);
    }

    // 方式2：检查多个权限（任一）
    if (!$user->hasAnyPermission(['invoices.view', 'invoices.create'])) {
        return $this->errorResponse('权限不足', 403);
    }

    // 方式3：检查多个权限（全部）
    if (!$user->hasAllPermissions(['invoices.view', 'invoices.update'])) {
        return $this->errorResponse('权限不足', 403);
    }

    // ...业务逻辑
}
```

### 2. **前端获取用户权限**

```javascript
// 1. 登录后获取用户权限
const response = await axios.get('/api/permissions/my');
const { permissions, roles } = response.data.data;

// 存储到 Vuex/Pinia
store.commit('setPermissions', permissions);
store.commit('setRoles', roles);

// 2. 在组件中使用
export default {
  computed: {
    canCreateInvoice() {
      return this.$store.state.permissions.includes('invoices.create');
    },
    canDeleteInvoice() {
      return this.$store.state.permissions.includes('invoices.delete');
    },
    isAdmin() {
      return this.$store.state.roles.includes('admin');
    }
  }
}

// 3. 模板中使用
<button v-if="canCreateInvoice">创建账单</button>
<button v-if="canDeleteInvoice">删除账单</button>
```

### 3. **管理员动态配置权限**

```javascript
// 获取角色的当前权限
const response = await axios.get('/api/permissions/roles/2');
const { role, permissions } = response.data.data;

// 更新角色权限
await axios.put('/api/permissions/roles/2', {
  permissions: [1, 2, 3, 5, 8] // 权限ID数组
});
```

---

## 🔄 向后兼容性

✅ **所有原有功能完全保留，不受任何影响**

### 原有方法仍可正常使用
```php
// User 模型原有方法
$user->hasRole('admin');           // ✓ 仍可使用
$user->isAdmin();                  // ✓ 仍可使用
$user->isStoreOwner();             // ✓ 仍可使用
$user->isStoreStaff();             // ✓ 仍可使用
$user->belongsToStore($storeId);   // ✓ 仍可使用
$user->isManagerOfStore($storeId); // ✓ 仍可使用

// ApiController 原有方法
$this->isAdmin();                  // ✓ 仍可使用
$this->belongsToStore($storeId);   // ✓ 仍可使用
$this->isManagerOfStore($storeId); // ✓ 仍可使用
```

### 现有 API 路由不变
- 所有现有 API 路由保持不变
- 所有现有业务逻辑保持不变
- 只是**新增**了权限管理 API

---

## 🚀 下一步建议（可选）

以下是可选的进一步优化，不影响现有功能：

### 1. **创建 Policy 类**
使用 Laravel Policy 简化权限检查代码：
```php
// 创建 Policy
php artisan make:policy InvoicePolicy

// 在 Controller 中使用
$this->authorize('view', $invoice);
$this->authorize('delete', $invoice);
```

### 2. **添加权限中间件**
```php
// 创建中间件
php artisan make:middleware CheckPermission

// 在路由中使用
Route::middleware('permission:invoices.delete')->delete('/invoices/{id}');
```

### 3. **前端权限指令**
```javascript
// Vue 自定义指令
Vue.directive('permission', {
  mounted(el, binding) {
    const permission = binding.value;
    if (!store.state.permissions.includes(permission)) {
      el.style.display = 'none';
    }
  }
});

// 使用
<button v-permission="'invoices.delete'">删除</button>
```

---

## 🧪 测试

### 运行测试脚本
```bash
cd backend
php test-permissions.php
```

### 测试 API
```bash
# 1. 获取当前用户权限（需要登录）
curl -H "Authorization: Bearer YOUR_TOKEN" \
  http://localhost:8000/api/permissions/my

# 2. 获取所有权限（仅管理员）
curl -H "Authorization: Bearer ADMIN_TOKEN" \
  http://localhost:8000/api/permissions

# 3. 获取角色权限
curl -H "Authorization: Bearer ADMIN_TOKEN" \
  http://localhost:8000/api/permissions/roles/2
```

---

## 📝 数据库变更

### 新增表
```sql
-- permissions 表
CREATE TABLE permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL COMMENT '权限名称',
  slug VARCHAR(255) UNIQUE NOT NULL COMMENT '权限标识',
  module VARCHAR(255) NOT NULL COMMENT '所属模块',
  description TEXT NULL COMMENT '权限描述',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_module (module)
);

-- permission_role 表
CREATE TABLE permission_role (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  permission_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY unique_permission_role (permission_id, role_id),
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);
```

---

## ⚠️ 注意事项

1. **向后兼容**：所有原有方法和API完全不受影响
2. **性能优化**：权限查询已优化，建议后续添加缓存
3. **管理员特权**：管理员始终拥有所有权限
4. **权限粒度**：当前为操作级权限，可扩展到字段级
5. **前端集成**：需要前端调用 `/api/permissions/my` 获取权限列表

---

## 📞 常见问题

### Q: 原有功能会受影响吗？
**A:** 不会。所有原有方法、API、业务逻辑完全保留。

### Q: 如何给用户分配权限？
**A:** 通过分配角色自动获得权限，或由管理员动态调整角色权限。

### Q: 前端如何判断按钮是否显示？
**A:** 调用 `/api/permissions/my` 获取权限列表，根据权限控制按钮显示。

### Q: 如何添加新权限？
**A:** 在 `PermissionSeeder` 中添加新权限，然后运行 `php artisan db:seed --class=PermissionSeeder`

### Q: 测试账号的权限是什么？
**A:** 运行 `php test-permissions.php` 查看详细权限分配。

---

## ✅ 验证清单

- [x] 数据库迁移成功
- [x] 31个权限已创建
- [x] 3个角色权限已分配
- [x] API 路由已注册
- [x] 用户模型方法正常工作
- [x] 角色模型方法正常工作
- [x] 原有功能完全正常
- [x] 向后兼容性验证通过

---

## 🎉 总结

本次权限系统升级：
- ✅ **完全向后兼容** - 不影响任何现有功能
- ✅ **功能完善** - 31个细粒度权限点
- ✅ **易于使用** - 简洁的API和方法
- ✅ **可扩展** - 支持动态权限配置
- ✅ **已测试** - 通过完整测试验证

现在系统拥有了完整的权限管理能力，可以精确控制每个用户的操作权限！🚀
