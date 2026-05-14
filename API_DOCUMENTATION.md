# 债务管理系统 API 文档

## 系统概述

这是一个基于Laravel的多门店债务管理系统，提供完整的RESTful API接口，用于管理门店、客户、账单和还款记录。

### 技术栈

- **后端框架**: Laravel 11.x
- **认证方式**: Laravel Sanctum
- **数据库**: MySQL/MariaDB
- **API风格**: RESTful

### 基础信息

- **API基础URL**: `http://your-domain.com/api`
- **API版本**: v1
- **内容类型**: `application/json`
- **字符编码**: UTF-8

## 认证机制

系统使用Laravel Sanctum进行API认证，所有API接口（除登录外）都需要在请求头中包含认证令牌。

### 认证流程

1. 使用用户名密码调用登录接口获取访问令牌
2. 在后续请求的Header中携带令牌
3. 令牌格式：`Authorization: Bearer {token}`

### 权限级别

系统采用基于角色的访问控制（RBAC），支持以下角色：

- **系统管理员(admin)**: 拥有系统所有权限，可管理所有门店和用户
- **店长(store_owner)**: 在其所属门店中拥有完全管理权限
- **店员(store_staff)**: 在其所属门店中拥有基础操作权限

### 权限检查逻辑

- **系统管理员**: 可以访问和管理所有资源
- **店长**: 可以管理其所属门店的所有业务（账单、还款、门店信息等）
- **店员**: 可以在其所属门店中进行基础操作（创建账单、记录还款等）

### 门店权限分配

用户通过角色获得权限级别，通过门店关联获得访问范围：
- 用户可以同时属于多个门店
- 店长角色的用户在所有其归属的门店中都拥有管理权限
- 店员角色的用户在所有其归属的门店中都拥有基础操作权限

### 角色权限系统状态

✅ **系统状态**: 权限架构已重新设计并优化
- 采用纯角色权限系统，简化权限管理
- 移除了门店级别的权限字段，统一权限检查逻辑
- 所有权限检查基于用户角色和门店归属关系
- 权限检查机制运行正常且性能优化

> **重要更新**: 系统已从双重权限机制简化为纯角色权限系统，提升了可维护性和用户体验。

## 通用响应格式

### 成功响应

```json
{
    "success": true,
    "data": {
        // 响应数据
    },
    "message": "操作成功信息"
}
```

### 错误响应

```json
{
    "success": false,
    "message": "错误信息描述"
}
```

### 分页响应

```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            // 数据列表
        ],
        "first_page_url": "http://example.com/api/resource?page=1",
        "from": 1,
        "last_page": 5,
        "last_page_url": "http://example.com/api/resource?page=5",
        "next_page_url": "http://example.com/api/resource?page=2",
        "path": "http://example.com/api/resource",
        "per_page": 15,
        "prev_page_url": null,
        "to": 15,
        "total": 75
    }
}
```

## HTTP状态码

| 状态码 | 说明 |
|--------|------|
| 200 | 请求成功 |
| 201 | 创建成功 |
| 400 | 请求参数错误 |
| 401 | 未认证 |
| 403 | 权限不足 |
| 404 | 资源不存在 |
| 422 | 验证失败 |
| 500 | 服务器内部错误 |

## 通用查询参数

### 分页参数

- `page`: 页码，默认为1
- `per_page`: 每页数量，默认为15

### 搜索参数

- `search`: 搜索关键词（适用于支持搜索的接口）

### 日期筛选参数

- `start_date`: 开始日期 (YYYY-MM-DD格式)
- `end_date`: 结束日期 (YYYY-MM-DD格式)

---

# API接口详情

## 1. 认证相关

### 1.1 用户登录

**接口说明**: 用户登录获取访问令牌

- **请求方式**: `POST`
- **请求URL**: `/api/login`
- **是否需要认证**: 否

**请求参数**:

```json
{
    "login": "admin@example.com",
    "password": "password"
}
```

或者使用用户名登录：
```json
{
    "login": "admin",
    "password": "password"
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| login | string | 是 | 用户邮箱或用户名（支持中文用户名） |
| password | string | 是 | 用户密码 |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "name": "管理员",
            "username": "admin",
            "email": "admin@example.com",
            "roles": ["admin"]
        },
        "token": "1|abcdefghijklmnopqrstuvwxyz"
    },
    "message": "登录成功"
}
```

### 1.2 用户注册

**接口说明**: 用户注册，新用户默认获得店员权限

- **请求方式**: `POST`
- **请求URL**: `/api/register`
- **是否需要认证**: 否

**请求参数**:

```json
{
    "name": "张三",
    "username": "zhangsan",
    "email": "zhangsan@example.com",
    "password": "password123",
    "password_confirmation": "password123"
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| name | string | 是 | 用户姓名，最大255字符 |
| username | string | 是 | 用户名，最大255字符，必须唯一，支持中文 |
| email | string | 是 | 用户邮箱，必须唯一 |
| password | string | 是 | 密码，最少6字符 |
| password_confirmation | string | 是 | 确认密码，必须与password一致 |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "user": {
            "id": 2,
            "name": "张三",
            "username": "zhangsan",
            "email": "zhangsan@example.com",
            "roles": ["store_staff"],
            "stores": []
        },
        "token": "2|abcdefghijklmnopqrstuvwxyz"
    },
    "message": "注册成功"
}
```

### 1.3 用户登出

**接口说明**: 用户登出，使当前令牌失效

- **请求方式**: `POST`
- **请求URL**: `/api/logout`
- **是否需要认证**: 是

**请求头**:

```
Authorization: Bearer {token}
```

**成功响应示例**:

```json
{
    "success": true,
    "message": "登出成功"
}
```

### 1.4 获取当前用户信息

**接口说明**: 获取当前登录用户的详细信息

- **请求方式**: `GET`
- **请求URL**: `/api/user`
- **是否需要认证**: 是

**请求头**:

```
Authorization: Bearer {token}
```

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "管理员",
        "email": "admin@example.com",
        "email_verified_at": "2024-01-01T00:00:00.000000Z",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z",
        "roles": ["admin"],
        "stores": [
            {
                "id": 1,
                "name": "总店",
                "code": "MAIN"
            }
        ]
    }
}
```

### 1.5 修改密码

**接口说明**: 已登录用户修改自己的密码

- **请求方式**: `PUT`
- **请求URL**: `/api/user/password`
- **是否需要认证**: 是

**请求头**:

```
Authorization: Bearer {token}
```

**请求参数**:

```json
{
    "current_password": "old_password",
    "new_password": "new_password",
    "new_password_confirmation": "new_password"
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| current_password | string | 是 | 当前密码 |
| new_password | string | 是 | 新密码（最少6位） |
| new_password_confirmation | string | 是 | 确认新密码（必须与new_password一致） |

**成功响应示例**:

```json
{
    "success": true,
    "data": null,
    "message": "密码修改成功"
}
```

**错误响应示例**:

1. **当前密码错误（422）**:
```json
{
    "success": false,
    "message": "验证失败",
    "errors": {
        "current_password": ["当前密码错误"]
    }
}
```

2. **新密码与当前密码相同（422）**:
```json
{
    "success": false,
    "message": "验证失败",
    "errors": {
        "new_password": ["新密码不能与当前密码相同"]
    }
}
```

3. **新密码格式不符合要求（422）**:
```json
{
    "success": false,
    "message": "验证失败",
    "errors": {
        "new_password": ["新密码长度不能少于6位"]
    }
}
```

4. **确认密码不一致（422）**:
```json
{
    "success": false,
    "message": "验证失败",
    "errors": {
        "new_password": ["新密码与确认密码不一致"]
    }
}
```

**安全特性**:

- ✅ **密码验证**: 必须提供正确的当前密码才能修改
- ✅ **密码哈希**: 新密码使用安全哈希算法存储
- ✅ **重复检查**: 防止设置与当前密码相同的新密码
- ✅ **长度要求**: 新密码最少6位字符
- ✅ **确认验证**: 必须提供确认密码防止输入错误

**使用示例**:

```bash
curl -X PUT http://your-domain.com/api/user/password \
  -H "Authorization: Bearer your-token-here" \
  -H "Content-Type: application/json" \
  -d '{
    "current_password": "old_password",
    "new_password": "new_secure_password",
    "new_password_confirmation": "new_secure_password"
  }'
```

---

## 2. 门店管理

### 2.1 获取门店列表

**接口说明**: 获取门店列表，管理员可查看所有门店，普通用户只能查看所属门店

- **请求方式**: `GET`
- **请求URL**: `/api/stores`
- **是否需要认证**: 是

**请求头**:

```
Authorization: Bearer {token}
```

**成功响应示例**:

```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "总店",
            "code": "MAIN",
            "address": "北京市朝阳区xxx街道",
            "phone": "010-12345678",
            "description": "总店描述",
            "is_active": true,
            "created_at": "2024-01-01T00:00:00.000000Z",
            "updated_at": "2024-01-01T00:00:00.000000Z"
        }
    ]
}
```

### 2.2 创建门店

**接口说明**: 创建新门店（仅管理员可操作）

- **请求方式**: `POST`
- **请求URL**: `/api/stores`
- **是否需要认证**: 是
- **权限要求**: 系统管理员

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**请求参数**:

```json
{
    "name": "分店A",
    "code": "BRANCH_A",
    "address": "上海市浦东新区xxx路",
    "phone": "021-87654321",
    "description": "分店A的描述信息",
    "is_active": true
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| name | string | 是 | 门店名称，最大255字符 |
| code | string | 是 | 门店编码，最大50字符，必须唯一 |
| address | string | 否 | 门店地址，最大255字符 |
| phone | string | 否 | 联系电话，最大20字符 |
| description | string | 否 | 门店描述 |
| is_active | boolean | 否 | 是否启用，默认true |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 2,
        "name": "分店A",
        "code": "BRANCH_A",
        "address": "上海市浦东新区xxx路",
        "phone": "021-87654321",
        "description": "分店A的描述信息",
        "is_active": true,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z"
    },
    "message": "门店创建成功"
}
```

### 2.3 获取门店详情

**接口说明**: 获取指定门店的详细信息

- **请求方式**: `GET`
- **请求URL**: `/api/stores/{id}`
- **是否需要认证**: 是
- **权限要求**: 管理员或该门店的员工

**请求头**:

```
Authorization: Bearer {token}
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 门店ID |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "总店",
        "code": "MAIN",
        "address": "北京市朝阳区xxx街道",
        "phone": "010-12345678",
        "description": "总店描述",
        "is_active": true,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z"
    }
}
```

### 2.4 更新门店信息

**接口说明**: 更新指定门店的信息

- **请求方式**: `PUT`
- **请求URL**: `/api/stores/{id}`
- **是否需要认证**: 是
- **权限要求**: 管理员或该门店的经理

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 门店ID |

**请求参数**:

```json
{
    "name": "总店（更新）",
    "address": "北京市朝阳区新地址",
    "phone": "010-87654321",
    "description": "更新后的描述",
    "is_active": false
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| name | string | 否 | 门店名称，最大255字符 |
| code | string | 否 | 门店编码，最大50字符，必须唯一 |
| address | string | 否 | 门店地址，最大255字符 |
| phone | string | 否 | 联系电话，最大20字符 |
| description | string | 否 | 门店描述 |
| is_active | boolean | 否 | 是否启用 |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "总店（更新）",
        "code": "MAIN",
        "address": "北京市朝阳区新地址",
        "phone": "010-87654321",
        "description": "更新后的描述",
        "is_active": false,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T12:00:00.000000Z"
    },
    "message": "门店更新成功"
}
```

### 2.5 删除门店

**接口说明**: 删除指定门店（仅管理员可操作）

- **请求方式**: `DELETE`
- **请求URL**: `/api/stores/{id}`
- **是否需要认证**: 是
- **权限要求**: 系统管理员

**请求头**:

```
Authorization: Bearer {token}
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 门店ID |

**成功响应示例**:

```json
{
    "success": true,
    "message": "门店删除成功"
}
```

---

## 3. 客户管理

### 3.1 获取客户列表

**接口说明**: 获取客户列表，支持搜索和分页

- **请求方式**: `GET`
- **请求URL**: `/api/customers`
- **是否需要认证**: 是

**请求头**:

```
Authorization: Bearer {token}
```

**查询参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| search | string | 否 | 搜索关键词（姓名、电话、身份证号） |
| page | integer | 否 | 页码，默认1 |
| per_page | integer | 否 | 每页数量，默认15 |

**请求示例**:

```
GET /api/customers?search=张三&page=1&per_page=10
```

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "name": "张三",
                "phone": "13800138000",
                "email": "zhangsan@example.com",
                "address": "北京市海淀区xxx小区",
                "id_card": "110101199001011234",
                "remarks": "VIP客户",
                "created_at": "2024-01-01T00:00:00.000000Z",
                "updated_at": "2024-01-01T00:00:00.000000Z"
            }
        ],
        "first_page_url": "http://example.com/api/customers?page=1",
        "from": 1,
        "last_page": 1,
        "last_page_url": "http://example.com/api/customers?page=1",
        "next_page_url": null,
        "path": "http://example.com/api/customers",
        "per_page": 15,
        "prev_page_url": null,
        "to": 1,
        "total": 1
    }
}
```

### 3.2 创建客户

**接口说明**: 创建新客户

- **请求方式**: `POST`
- **请求URL**: `/api/customers`
- **是否需要认证**: 是

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**请求参数**:

```json
{
    "name": "李四",
    "phone": "13900139000",
    "email": "lisi@example.com",
    "address": "上海市浦东新区xxx路",
    "id_card": "310101199002021234",
    "remarks": "新客户"
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| name | string | 是 | 客户姓名，最大255字符 |
| phone | string | 否 | 手机号码，最大20字符 |
| email | string | 否 | 邮箱地址，最大255字符 |
| address | string | 否 | 联系地址，最大255字符 |
| id_card | string | 否 | 身份证号，最大18字符 |
| remarks | string | 否 | 备注信息 |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 2,
        "name": "李四",
        "phone": "13900139000",
        "email": "lisi@example.com",
        "address": "上海市浦东新区xxx路",
        "id_card": "310101199002021234",
        "remarks": "新客户",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z"
    },
    "message": "客户创建成功"
}
```

### 3.3 获取客户详情

**接口说明**: 获取指定客户的详细信息，包括关联的账单和还款记录

- **请求方式**: `GET`
- **请求URL**: `/api/customers/{id}`
- **是否需要认证**: 是

**请求头**:

```
Authorization: Bearer {token}
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 客户ID |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "张三",
        "phone": "13800138000",
        "email": "zhangsan@example.com",
        "address": "北京市海淀区xxx小区",
        "id_card": "110101199001011234",
        "remarks": "VIP客户",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z",
        "invoices": [
            {
                "id": 1,
                "invoice_number": "MAIN-20240101-ABC12",
                "amount": "1000.00",
                "paid_amount": "500.00",
                "status": "partially_paid",
                "due_date": "2024-01-31"
            }
        ],
        "payments": [
            {
                "id": 1,
                "payment_number": "PAY-MAIN-20240101-XYZ12",
                "amount": "500.00",
                "payment_method": "cash"
            }
        ]
    }
}
```

### 3.4 更新客户信息

**接口说明**: 更新指定客户的信息

- **请求方式**: `PUT`
- **请求URL**: `/api/customers/{id}`
- **是否需要认证**: 是

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 客户ID |

**请求参数**:

```json
{
    "name": "张三（更新）",
    "phone": "13800138001",
    "email": "zhangsan_new@example.com",
    "address": "北京市朝阳区新地址",
    "remarks": "VIP客户（已更新）"
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| name | string | 否 | 客户姓名，最大255字符 |
| phone | string | 否 | 手机号码，最大20字符 |
| email | string | 否 | 邮箱地址，最大255字符 |
| address | string | 否 | 联系地址，最大255字符 |
| id_card | string | 否 | 身份证号，最大18字符 |
| remarks | string | 否 | 备注信息 |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "张三（更新）",
        "phone": "13800138001",
        "email": "zhangsan_new@example.com",
        "address": "北京市朝阳区新地址",
        "id_card": "110101199001011234",
        "remarks": "VIP客户（已更新）",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T12:00:00.000000Z"
    },
    "message": "客户更新成功"
}
```

### 3.5 删除客户

**接口说明**: 删除指定客户（仅管理员可操作，且客户不能有关联的账单或还款记录）

- **请求方式**: `DELETE`
- **请求URL**: `/api/customers/{id}`
- **是否需要认证**: 是
- **权限要求**: 系统管理员

**请求头**:

```
Authorization: Bearer {token}
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 客户ID |

**成功响应示例**:

```json
{
    "success": true,
    "message": "客户删除成功"
}
```

**错误响应示例**:

```json
{
    "success": false,
    "message": "该客户有关联的账单或还款记录，无法删除"
}
```

---

## 4. 账单管理

### 4.1 获取账单列表

**接口说明**: 获取账单列表，支持多种筛选条件和分页

- **请求方式**: `GET`
- **请求URL**: `/api/invoices`
- **是否需要认证**: 是
- **权限要求**: 所有已认证用户

**请求头**:

```
Authorization: Bearer {token}
```

**权限说明**:
- **系统管理员**: 可查看所有门店的账单数据
- **门店经理/店员**: 只能查看所属门店的账单数据
- 如果指定了store_id参数，系统会验证用户是否有权限查看该门店的数据

**查询参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| store_id | integer | 否 | 门店ID筛选 |
| customer_id | integer | 否 | 客户ID筛选 |
| status | string | 否 | 状态筛选：unpaid, partially_paid, paid, overdue |
| start_date | string | 否 | 开始日期 (YYYY-MM-DD) |
| end_date | string | 否 | 结束日期 (YYYY-MM-DD) |
| page | integer | 否 | 页码，默认1 |
| per_page | integer | 否 | 每页数量，默认15 |

**请求示例**:

```
GET /api/invoices?store_id=1&status=unpaid&start_date=2024-01-01&end_date=2024-01-31&page=1&per_page=10
```

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "invoice_number": "MAIN-20240101-ABC12",
                "store_id": 1,
                "customer_id": 1,
                "created_by": 1,
                "amount": "1000.00",
                "paid_amount": "500.00",
                "due_date": "2024-01-31T00:00:00.000000Z",
                "status": "partially_paid",
                "description": "商品销售",
                "created_at": "2024-01-01T00:00:00.000000Z",
                "updated_at": "2024-01-01T00:00:00.000000Z",
                "store": {
                    "id": 1,
                    "name": "总店"
                },
                "customer": {
                    "id": 1,
                    "name": "张三",
                    "phone": "13800138000"
                },
                "created_by": {
                    "id": 1,
                    "name": "管理员"
                }
            }
        ],
        "first_page_url": "http://example.com/api/invoices?page=1",
        "from": 1,
        "last_page": 1,
        "last_page_url": "http://example.com/api/invoices?page=1",
        "links": [
            {
                "url": null,
                "label": "&laquo; Previous",
                "active": false
            },
            {
                "url": "http://example.com/api/invoices?page=1",
                "label": "1",
                "active": true
            },
            {
                "url": null,
                "label": "Next &raquo;",
                "active": false
            }
        ],
        "next_page_url": null,
        "path": "http://example.com/api/invoices",
        "per_page": 15,
        "prev_page_url": null,
        "to": 1,
        "total": 1
    }
}
```

**响应字段说明**:

| 字段名 | 类型 | 说明 |
|--------|------|------|
| id | integer | 账单ID |
| invoice_number | string | 账单编号 |
| store_id | integer | 门店ID |
| customer_id | integer | 客户ID |
| created_by | integer | 创建者用户ID |
| amount | string | 账单总金额（保留2位小数） |
| paid_amount | string | 已付金额（保留2位小数） |
| due_date | string | 到期日期（ISO 8601格式） |
| status | string | 账单状态：unpaid, partially_paid, paid, overdue |
| description | string\|null | 账单描述 |
| created_at | string | 创建时间（ISO 8601格式） |
| updated_at | string | 更新时间（ISO 8601格式） |
| store | object | 关联门店信息 |
| store.id | integer | 门店ID |
| store.name | string | 门店名称 |
| customer | object | 关联客户信息 |
| customer.id | integer | 客户ID |
| customer.name | string | 客户姓名 |
| customer.phone | string\|null | 客户电话 |
| created_by | object | 创建者信息 |
| created_by.id | integer | 创建者ID |
| created_by.name | string | 创建者姓名 |

**分页字段说明**:

| 字段名 | 类型 | 说明 |
|--------|------|------|
| current_page | integer | 当前页码 |
| data | array | 账单数据数组 |
| first_page_url | string | 第一页URL |
| from | integer | 当前页起始记录号 |
| last_page | integer | 最后一页页码 |
| last_page_url | string | 最后一页URL |
| links | array | 分页链接数组 |
| next_page_url | string\|null | 下一页URL |
| path | string | 基础路径 |
| per_page | integer | 每页记录数 |
| prev_page_url | string\|null | 上一页URL |
| to | integer | 当前页结束记录号 |
| total | integer | 总记录数 |

### 4.2 创建账单

**接口说明**: 创建新账单，支持传统总金额模式和明细项目模式

- **请求方式**: `POST`
- **请求URL**: `/api/invoices`
- **是否需要认证**: 是
- **权限要求**: 用户必须属于指定门店

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**请求参数**:

**方式1: 传统总金额模式**
```json
{
    "store_id": 1,
    "customer_id": 1,
    "amount": 1500.00,
    "due_date": "2024-01-31",
    "description": "商品销售账单"
}
```

**方式2: 明细项目模式**
```json
{
    "store_id": 1,
    "customer_id": 1,
    "due_date": "2024-01-31",
    "description": "商品销售账单",
    "items": [
        {
            "item_name": "商品A",
            "item_description": "规格：大号",
            "quantity": 2,
            "unit_price": 100.00,
            "sort_order": 0
        },
        {
            "item_name": "商品B",
            "item_description": "规格：中号",
            "quantity": 1,
            "unit_price": 200.00,
            "sort_order": 1
        }
    ]
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| store_id | integer | 是 | 门店ID，必须存在 |
| customer_id | integer | 是 | 客户ID，必须存在 |
| amount | decimal | 条件必填 | 账单金额，最小0.01（传统模式必填，明细模式可选） |
| due_date | string | 否 | 到期日期 (YYYY-MM-DD)，必须大于等于账单日期 |
| description | string | 否 | 账单描述 |
| items | array | 条件必填 | 明细项目数组（明细模式必填） |
| items.*.item_name | string | 否 | 商品/服务名称，最大255字符，可为空 |
| items.*.item_description | string | 否 | 商品描述/规格 |
| items.*.quantity | decimal | 是 | 数量，最小0.001 |
| items.*.unit_price | decimal | 是 | 单价，最小0.01 |
| items.*.sort_order | integer | 否 | 排序，默认按数组顺序 |

**注意事项**:
- 必须提供 `amount` 或 `items` 其中之一
- 传统模式：提供 `amount`，系统自动创建默认明细项
- 明细模式：提供 `items` 数组，系统自动计算总金额
- 小计金额由系统自动计算：`quantity × unit_price`

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 2,
        "invoice_number": "MAIN-20240101-XYZ78",
        "store_id": 1,
        "customer_id": 1,
        "created_by": 1,
        "amount": "300.00",
        "paid_amount": "0.00",
        "status": "unpaid",
        "due_date": "2024-01-31",
        "description": "商品销售账单",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z",
        "items": [
            {
                "id": 1,
                "invoice_id": 2,
                "item_name": "商品A",
                "item_description": "规格：大号",
                "quantity": "2.000",
                "unit_price": "100.00",
                "subtotal": "200.00",
                "sort_order": 0,
                "created_at": "2024-01-01T00:00:00.000000Z",
                "updated_at": "2024-01-01T00:00:00.000000Z"
            },
            {
                "id": 2,
                "invoice_id": 2,
                "item_name": "商品B",
                "item_description": "规格：中号",
                "quantity": "1.000",
                "unit_price": "100.00",
                "subtotal": "100.00",
                "sort_order": 1,
                "created_at": "2024-01-01T00:00:00.000000Z",
                "updated_at": "2024-01-01T00:00:00.000000Z"
            }
        ]
    },
    "message": "账单创建成功"
}
```

### 4.3 获取账单详情

**接口说明**: 获取指定账单的详细信息，包括明细项目和还款分配记录

- **请求方式**: `GET`
- **请求URL**: `/api/invoices/{id}`
- **是否需要认证**: 是
- **权限要求**: 用户必须属于该账单所在门店

**请求头**:

```
Authorization: Bearer {token}
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 账单ID |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 1,
        "invoice_number": "MAIN-20240101-ABC12",
        "amount": "1000.00",
        "paid_amount": "500.00",
        "status": "partially_paid",
        "due_date": "2024-01-31",
        "description": "商品销售",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z",
        "store": {
            "id": 1,
            "name": "总店"
        },
        "customer": {
            "id": 1,
            "name": "张三",
            "phone": "13800138000"
        },
        "created_by": {
            "id": 1,
            "name": "管理员"
        },
        "payment_allocations": [
            {
                "id": 1,
                "amount": "500.00",
                "allocated_at": "2024-01-15T00:00:00.000000Z",
                "payment": {
                    "id": 1,
                    "payment_number": "PAY-MAIN-20240115-ABC12",
                    "amount": "500.00",
                    "payment_method": "cash"
                },
                "allocated_by": {
                    "id": 1,
                    "name": "管理员"
                }
            }
        ]
    }
}
```

### 4.4 更新账单

**接口说明**: 更新指定账单的信息

- **请求方式**: `PUT`
- **请求URL**: `/api/invoices/{id}`
- **是否需要认证**: 是
- **权限要求**: 管理员或该门店的经理

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 账单ID |

**请求参数**:

```json
{
    "amount": 1200.00,
    "due_date": "2024-02-01",
    "description": "更新后的账单描述"
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| amount | decimal | 否 | 账单金额，最小0.01（如果已有付款则不能修改） |
| due_date | string | 否 | 到期日期 (YYYY-MM-DD) |
| description | string | 否 | 账单描述 |

**注意事项**:

- 如果账单已经有付款记录，则只能更新描述字段
- 其他字段的修改需要在没有付款记录的情况下进行

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 1,
        "invoice_number": "MAIN-20240101-ABC12",
        "amount": "1200.00",
        "paid_amount": "0.00",
        "status": "unpaid",
        "due_date": "2024-02-01",
        "description": "更新后的账单描述",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T12:00:00.000000Z"
    },
    "message": "账单更新成功"
}
```

### 4.5 删除账单

**接口说明**: 删除指定账单（仅管理员或门店经理可操作）

- **请求方式**: `DELETE`
- **请求URL**: `/api/invoices/{id}`
- **是否需要认证**: 是
- **权限要求**: 管理员或该门店的经理

**请求头**:

```
Authorization: Bearer {token}
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 账单ID |

**成功响应示例**:

```json
{
    "success": true,
    "message": "账单删除成功"
}
```

### 4.6 获取账单明细列表

**接口说明**: 获取指定账单的明细项目列表

- **请求方式**: `GET`
- **请求URL**: `/api/invoices/{invoice_id}/items`
- **是否需要认证**: 是
- **权限要求**: 用户必须属于账单所在门店

**请求头**:

```
Authorization: Bearer {token}
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| invoice_id | integer | 是 | 账单ID |

**成功响应示例**:

```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "invoice_id": 2,
            "item_name": "商品A",
            "item_description": "规格：大号",
            "quantity": "2.000",
            "unit_price": "100.00",
            "subtotal": "200.00",
            "sort_order": 0,
            "created_at": "2024-01-01T00:00:00.000000Z",
            "updated_at": "2024-01-01T00:00:00.000000Z"
        },
        {
            "id": 2,
            "invoice_id": 2,
            "item_name": "商品B",
            "item_description": "规格：中号",
            "quantity": "1.000",
            "unit_price": "100.00",
            "subtotal": "100.00",
            "sort_order": 1,
            "created_at": "2024-01-01T00:00:00.000000Z",
            "updated_at": "2024-01-01T00:00:00.000000Z"
        }
    ]
}
```

### 4.7 添加账单明细项

**接口说明**: 为指定账单添加新的明细项目

- **请求方式**: `POST`
- **请求URL**: `/api/invoices/{invoice_id}/items`
- **是否需要认证**: 是
- **权限要求**: 需要系统管理员权限或店长权限

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| invoice_id | integer | 是 | 账单ID |

**请求参数**:

```json
{
    "item_name": "新商品",
    "item_description": "商品描述",
    "quantity": 1,
    "unit_price": 150.00,
    "sort_order": 2
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| item_name | string | 否 | 商品/服务名称，最大255字符，可为空 |
| item_description | string | 否 | 商品描述/规格 |
| quantity | decimal | 是 | 数量，最小0.001 |
| unit_price | decimal | 是 | 单价，最小0.01 |
| sort_order | integer | 否 | 排序，默认为最大排序值+1 |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 3,
        "invoice_id": 2,
        "item_name": "新商品",
        "item_description": "商品描述",
        "quantity": "1.000",
        "unit_price": "150.00",
        "subtotal": "150.00",
        "sort_order": 2,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z"
    },
    "message": "明细项添加成功"
}
```

### 4.8 更新账单明细项

**接口说明**: 更新指定的账单明细项目

- **请求方式**: `PUT`
- **请求URL**: `/api/invoice-items/{item_id}`
- **是否需要认证**: 是
- **权限要求**: 需要系统管理员权限或店长权限

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| item_id | integer | 是 | 明细项ID |

**请求参数**:

```json
{
    "item_name": "更新后的商品名",
    "item_description": "更新后的描述",
    "quantity": 2,
    "unit_price": 120.00,
    "sort_order": 1
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| item_name | string | 否 | 商品/服务名称，最大255字符，可为空 |
| item_description | string | 否 | 商品描述/规格 |
| quantity | decimal | 否 | 数量，最小0.001 |
| unit_price | decimal | 否 | 单价，最小0.01 |
| sort_order | integer | 否 | 排序 |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 3,
        "invoice_id": 2,
        "item_name": "更新后的商品名",
        "item_description": "更新后的描述",
        "quantity": "2.000",
        "unit_price": "120.00",
        "subtotal": "240.00",
        "sort_order": 1,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T01:00:00.000000Z"
    },
    "message": "明细项更新成功"
}
```

### 4.9 删除账单明细项

**接口说明**: 删除指定的账单明细项目

- **请求方式**: `DELETE`
- **请求URL**: `/api/invoice-items/{item_id}`
- **是否需要认证**: 是
- **权限要求**: 需要系统管理员权限或店长权限

**请求头**:

```
Authorization: Bearer {token}
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| item_id | integer | 是 | 明细项ID |

**成功响应示例**:

```json
{
    "success": true,
    "message": "明细项删除成功"
}
```

**注意事项**:
- 账单至少需要保留一个明细项，无法删除最后一个明细项
- 如果账单已有付款记录，无法删除明细项
- 删除明细项后会自动重新计算账单总金额

---

## 5. 附件管理

### 5.0 缤纷云S4配置说明

**重要更新**：本系统已基于缤纷云官方SDK文档进行优化配置，确保最佳兼容性和性能。

#### **核心配置参数**

以下配置参数已根据缤纷云官方文档进行优化：

```php
// config/filesystems.php - bitiful磁盘配置
'bitiful' => [
    'driver' => 's3',
    'key' => env('BITIFUL_ACCESS_KEY'),
    'secret' => env('BITIFUL_SECRET_KEY'),
    'region' => env('BITIFUL_REGION', 'cn-east-1'),
    'bucket' => env('BITIFUL_BUCKET'),
    'endpoint' => env('BITIFUL_ENDPOINT'),
    'use_path_style_endpoint' => false,        // 官方推荐：使用虚拟主机风格
    'signature_version' => 'v4',              // 官方推荐：使用v4签名
    'use_aws_shared_config_files' => false,   // 官方推荐：禁用共享配置
    'http' => ['verify' => false],            // 开发环境：禁用SSL验证
],
```

#### **环境变量配置**

```env
# 缤纷云S4配置
BITIFUL_ACCESS_KEY=your_access_key
BITIFUL_SECRET_KEY=your_secret_key
BITIFUL_REGION=cn-east-1
BITIFUL_BUCKET=your_bucket_name
BITIFUL_ENDPOINT=s3.bitiful.net              # 注意：不包含https://前缀
BITIFUL_URL=                                 # 保持为空，系统自动处理
BITIFUL_SSL_VERIFY=false                     # 开发环境建议设为false
```

#### **关键配置说明**

| 配置项 | 值 | 说明 |
|--------|----|----- |
| `use_path_style_endpoint` | `false` | 使用虚拟主机风格URL（`bucket.s3.bitiful.net`）而非路径风格（`s3.bitiful.net/bucket`） |
| `signature_version` | `'v4'` | 使用AWS签名版本4，与缤纷云S4完全兼容 |
| `use_aws_shared_config_files` | `false` | 禁用AWS共享配置文件，避免配置冲突 |
| `BITIFUL_ENDPOINT` | `s3.bitiful.net` | 不包含协议前缀，系统会自动添加https:// |

#### **权限配置要求**

确保缤纷云子账户具有以下权限：

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:DeleteObject"
            ],
            "Resource": "arn:aws:s3:::your_bucket_name/*"
        },
        {
            "Effect": "Allow",
            "Action": [
                "s3:ListBucket"
            ],
            "Resource": "arn:aws:s3:::your_bucket_name"
        }
    ]
}
```

### 5.1 快速开始

如果您只想快速集成文件上传功能，可以直接复制以下代码：

```html
<!-- HTML -->
<input type="file" id="fileInput" accept="image/*,application/pdf">
<button id="uploadBtn" onclick="handleUpload()">上传文件</button>
<div id="status"></div>

<script>
// 复制粘贴即用的上传函数
async function handleUpload() {
    const fileInput = document.getElementById('fileInput');
    const statusDiv = document.getElementById('status');
    const file = fileInput.files[0];

    if (!file) {
        statusDiv.innerHTML = '请选择文件';
        return;
    }

    const token = 'your_auth_token_here'; // 替换为实际token

    try {
        statusDiv.innerHTML = '正在上传...';

        // 1. 获取预签名URL
        const presignedResponse = await fetch('/api/attachments/presigned-url', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                attachable_type: 'invoice',
                attachable_id: 4,
                filename: file.name,
                file_size: file.size,
                mime_type: file.type
            })
        });

        const presignedData = await presignedResponse.json();
        const { upload_url, file_path } = presignedData.data;

        // 2. 上传到缤纷云S4
        await fetch(upload_url, {
            method: 'PUT',
            body: file
        });

        // 3. 确认上传
        const confirmResponse = await fetch('/api/attachments', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                attachable_type: 'invoice',
                attachable_id: 4,
                file_path: file_path,
                original_filename: file.name,
                file_size: file.size,
                mime_type: file.type
            })
        });

        const result = await confirmResponse.json();
        statusDiv.innerHTML = `✅ 上传成功！附件ID: ${result.data.id}`;

    } catch (error) {
        statusDiv.innerHTML = `❌ 上传失败: ${error.message}`;
        console.error(error);
    }
}
</script>
```

### 5.2 生成预签名上传URL

**接口说明**: 生成用于直接上传文件到对象存储的预签名URL

- **请求方式**: `POST`
- **请求URL**: `/api/attachments/presigned-url`
- **是否需要认证**: 是
- **权限要求**: 用户必须属于关联实体所在门店

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**请求参数**:

```json
{
    "attachable_type": "invoice",
    "attachable_id": 1,
    "filename": "invoice_scan.pdf",
    "file_size": 2048576,
    "mime_type": "application/pdf"
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| attachable_type | string | 是 | 关联类型：invoice（账单）或 payment（还款） |
| attachable_id | integer | 是 | 关联实体ID |
| filename | string | 是 | 原始文件名，最大255字符 |
| file_size | integer | 是 | 文件大小（字节），最大10MB |
| mime_type | string | 是 | 文件MIME类型，见允许类型列表 |

**允许的文件类型**:
- 图片：`image/jpeg`, `image/png`, `image/gif`, `image/webp`
- 文档：`application/pdf`, `application/msword`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document`
- 表格：`application/vnd.ms-excel`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
- 文本：`text/plain`

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "upload_url": "https://gxhpimg.s3.bitiful.net/attachments/invoices/2025/07/26/1753453004_ed39fe78_invoice_scan.pdf?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=...&X-Amz-Date=20250726T141644Z&X-Amz-Expires=1200&X-Amz-SignedHeaders=host&X-Amz-Signature=...",
        "file_path": "attachments/invoices/2025/07/26/1753453004_ed39fe78_invoice_scan.pdf",
        "original_mime_type": "application/pdf",
        "expires_in": 1200,
        "upload_instructions": {
            "method": "PUT",
            "content_type": null,
            "note": "重要：不要设置Content-Type头，让浏览器自动处理"
        }
    },
    "message": "预签名URL生成成功"
}
```

**重要变更说明**：
- **URL格式变更**：现在使用虚拟主机风格 `bucket.s3.bitiful.net` 而非路径风格 `s3.bitiful.net/bucket`
- **签名版本**：使用AWS4-HMAC-SHA256签名算法，完全兼容缤纷云S4
- **Content-Type处理**：前端上传时不要设置Content-Type头，让浏览器自动处理

### 5.3 确认文件上传完成

**接口说明**: 确认文件已成功上传到对象存储，保存附件记录

- **请求方式**: `POST`
- **请求URL**: `/api/attachments`
- **是否需要认证**: 是
- **权限要求**: 用户必须属于关联实体所在门店

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**请求参数**:

```json
{
    "attachable_type": "invoice",
    "attachable_id": 1,
    "file_path": "attachments/invoices/2024/01/1/1642780800_a1b2c3d4_invoice_scan.pdf",
    "original_filename": "invoice_scan.pdf",
    "file_size": 2048576,
    "mime_type": "application/pdf"
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| attachable_type | string | 是 | 关联类型：invoice 或 payment |
| attachable_id | integer | 是 | 关联实体ID |
| file_path | string | 是 | 文件在对象存储中的路径 |
| original_filename | string | 是 | 原始文件名 |
| file_size | integer | 是 | 文件大小（字节） |
| mime_type | string | 是 | 文件MIME类型 |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 1,
        "attachable_type": "App\\Models\\Invoice",
        "attachable_id": 1,
        "original_filename": "invoice_scan.pdf",
        "stored_filename": "1642780800_a1b2c3d4_invoice_scan.pdf",
        "file_path": "attachments/invoices/2024/01/1/1642780800_a1b2c3d4_invoice_scan.pdf",
        "file_size": 2048576,
        "mime_type": "application/pdf",
        "uploaded_by": 1,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z",
        "uploaded_by_user": {
            "id": 1,
            "name": "管理员"
        }
    },
    "message": "附件上传成功"
}
```

### 5.3 获取附件列表

**接口说明**: 获取指定实体的附件列表

- **请求方式**: `GET`
- **请求URL**: `/api/attachments?attachable_type={type}&attachable_id={id}`
- **是否需要认证**: 是
- **权限要求**: 用户必须属于关联实体所在门店

**请求头**:

```
Authorization: Bearer {token}
```

**查询参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| attachable_type | string | 是 | 关联类型：invoice 或 payment |
| attachable_id | integer | 是 | 关联实体ID |

**成功响应示例**:

```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "attachable_type": "App\\Models\\Invoice",
            "attachable_id": 1,
            "original_filename": "invoice_scan.pdf",
            "stored_filename": "1642780800_a1b2c3d4_invoice_scan.pdf",
            "file_path": "attachments/invoices/2024/01/1/1642780800_a1b2c3d4_invoice_scan.pdf",
            "file_size": 2048576,
            "mime_type": "application/pdf",
            "uploaded_by": 1,
            "created_at": "2024-01-01T00:00:00.000000Z",
            "updated_at": "2024-01-01T00:00:00.000000Z",
            "uploaded_by_user": {
                "id": 1,
                "name": "管理员"
            }
        }
    ]
}
```

### 5.5 删除附件

**接口说明**: 删除指定的附件文件和记录

- **请求方式**: `DELETE`
- **请求URL**: `/api/attachments/{attachment_id}`
- **是否需要认证**: 是
- **权限要求**: 用户必须属于关联实体所在门店

**请求头**:

```
Authorization: Bearer {token}
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| attachment_id | integer | 是 | 附件ID |

**成功响应示例**:

```json
{
    "success": true,
    "message": "附件删除成功"
}
```

### 5.4 前端集成指南

#### **完整的三步上传流程**

以下是完整的前端JavaScript实现，包含错误处理和最佳实践：

```javascript
/**
 * 完整的文件上传函数
 * @param {File} file - 要上传的文件对象
 * @param {string} attachableType - 关联类型 ('invoice' 或 'payment')
 * @param {number} attachableId - 关联实体ID
 * @param {string} token - 认证token
 * @returns {Promise<Object>} 上传结果
 */
async function uploadFileToS4(file, attachableType, attachableId, token) {
    try {
        // 步骤1: 获取预签名URL
        console.log('步骤1: 获取预签名URL...');
        const presignedResponse = await fetch('/api/attachments/presigned-url', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                attachable_type: attachableType,
                attachable_id: attachableId,
                filename: file.name,
                file_size: file.size,
                mime_type: file.type
            })
        });

        if (!presignedResponse.ok) {
            const errorData = await presignedResponse.json();
            throw new Error(`获取预签名URL失败: ${errorData.message || presignedResponse.statusText}`);
        }

        const presignedData = await presignedResponse.json();
        const { upload_url, file_path } = presignedData.data;

        console.log('✅ 预签名URL获取成功');

        // 步骤2: 直接上传到缤纷云S4
        console.log('步骤2: 上传文件到缤纷云S4...');
        const uploadResponse = await fetch(upload_url, {
            method: 'PUT',
            body: file
            // 重要：不设置Content-Type头，让浏览器自动处理
        });

        if (!uploadResponse.ok) {
            throw new Error(`文件上传失败: HTTP ${uploadResponse.status} ${uploadResponse.statusText}`);
        }

        console.log('✅ 文件上传成功');

        // 步骤3: 确认上传完成
        console.log('步骤3: 确认上传完成...');
        const confirmResponse = await fetch('/api/attachments', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                attachable_type: attachableType,
                attachable_id: attachableId,
                file_path: file_path,
                original_filename: file.name,
                file_size: file.size,
                mime_type: file.type
            })
        });

        if (!confirmResponse.ok) {
            const errorData = await confirmResponse.json();
            throw new Error(`确认上传失败: ${errorData.message || confirmResponse.statusText}`);
        }

        const result = await confirmResponse.json();

        if (result.success) {
            console.log('✅ 上传流程完成');
            return result.data;
        } else {
            throw new Error(result.message || '确认上传失败');
        }

    } catch (error) {
        console.error('❌ 文件上传失败:', error);
        throw error;
    }
}
```

#### **使用示例**

```javascript
// HTML文件选择器
const fileInput = document.getElementById('file-input');
const uploadButton = document.getElementById('upload-button');
const progressDiv = document.getElementById('progress');

fileInput.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file) {
        uploadButton.disabled = false;
        uploadButton.onclick = () => handleUpload(file);
    }
});

async function handleUpload(file) {
    const token = 'your_auth_token_here';
    const attachableType = 'invoice';
    const attachableId = 4;

    try {
        // 显示上传进度
        progressDiv.innerHTML = '正在上传...';
        uploadButton.disabled = true;

        // 执行上传
        const attachment = await uploadFileToS4(file, attachableType, attachableId, token);

        // 上传成功
        progressDiv.innerHTML = `✅ 上传成功！附件ID: ${attachment.id}`;
        console.log('上传的附件信息:', attachment);

    } catch (error) {
        // 上传失败
        progressDiv.innerHTML = `❌ 上传失败: ${error.message}`;
        console.error('上传错误:', error);
    } finally {
        uploadButton.disabled = false;
    }
}
```

#### **错误处理和故障排除**

**常见错误及解决方案**：

| 错误类型 | 错误信息 | 解决方案 |
|----------|----------|----------|
| 权限错误 | `AccessDenied` | 检查缤纷云子账户权限配置，确保有 `s3:PutObject` 权限 |
| 签名错误 | `SignatureDoesNotMatch` | 检查配置参数，确保使用官方推荐的配置 |
| 文件过大 | `文件大小超过限制` | 文件大小不能超过10MB |
| 类型错误 | `不支持的文件类型` | 检查文件MIME类型是否在允许列表中 |
| 网络错误 | `NetworkError` | 检查网络连接和防火墙设置 |
| 超时错误 | `TimeoutError` | 预签名URL已过期，重新获取 |

**调试建议**：

```javascript
// 开启详细日志
async function uploadWithDebug(file, attachableType, attachableId, token) {
    try {
        console.log('开始上传调试:', {
            fileName: file.name,
            fileSize: file.size,
            fileType: file.type,
            attachableType,
            attachableId
        });

        const result = await uploadFileToS4(file, attachableType, attachableId, token);
        console.log('上传成功:', result);
        return result;

    } catch (error) {
        console.error('上传失败详情:', {
            error: error.message,
            stack: error.stack,
            timestamp: new Date().toISOString()
        });

        // 根据错误类型提供具体建议
        if (error.message.includes('AccessDenied')) {
            console.error('💡 建议: 检查缤纷云存储桶权限配置');
        } else if (error.message.includes('SignatureDoesNotMatch')) {
            console.error('💡 建议: 检查配置参数和时间同步');
        } else if (error.message.includes('NetworkError')) {
            console.error('💡 建议: 检查网络连接');
        }

        throw error;
    }
}
```

#### **兼容性说明**

**URL格式变更**：
- **旧格式**（路径风格）：`https://s3.bitiful.net/bucket/path/file.ext`
- **新格式**（虚拟主机风格）：`https://bucket.s3.bitiful.net/path/file.ext`

**迁移指南**：
- 现有前端代码无需修改，API接口保持兼容
- 预签名URL格式自动更新为新格式
- 如果有硬编码的URL解析逻辑，需要适配新格式

**安全注意事项**：
- 预签名URL有效期为20分钟
- 文件大小限制为10MB
- 仅支持指定的文件类型
- 用户只能访问其有权限门店的附件
- 文件路径自动生成，防止路径遍历攻击

---

## 6. 还款管理

### 5.1 获取还款列表

**接口说明**: 获取还款列表，支持多种筛选条件和分页

- **请求方式**: `GET`
- **请求URL**: `/api/payments`
- **是否需要认证**: 是

**请求头**:

```
Authorization: Bearer {token}
```

**查询参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| store_id | integer | 否 | 门店ID筛选 |
| customer_id | integer | 否 | 客户ID筛选 |
| payment_method | string | 否 | 支付方式筛选：cash, bank_transfer, wechat, alipay, other |
| start_date | string | 否 | 开始日期 (YYYY-MM-DD) |
| end_date | string | 否 | 结束日期 (YYYY-MM-DD) |
| page | integer | 否 | 页码，默认1 |
| per_page | integer | 否 | 每页数量，默认15 |

**请求示例**:

```
GET /api/payments?store_id=1&payment_method=cash&start_date=2024-01-01&end_date=2024-01-31&page=1&per_page=10
```

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "payment_number": "PAY-MAIN-20240115-ABC12",
                "amount": "500.00",
                "allocated_amount": "500.00",
                "payment_method": "cash",
                "reference_number": null,
                "remarks": "现金还款",
                "created_at": "2024-01-15T00:00:00.000000Z",
                "updated_at": "2024-01-15T00:00:00.000000Z",
                "store": {
                    "id": 1,
                    "name": "总店"
                },
                "customer": {
                    "id": 1,
                    "name": "张三",
                    "phone": "13800138000"
                },
                "received_by": {
                    "id": 1,
                    "name": "管理员"
                }
            }
        ],
        "first_page_url": "http://example.com/api/payments?page=1",
        "from": 1,
        "last_page": 1,
        "last_page_url": "http://example.com/api/payments?page=1",
        "next_page_url": null,
        "path": "http://example.com/api/payments",
        "per_page": 15,
        "prev_page_url": null,
        "to": 1,
        "total": 1
    }
}
```

### 5.2 创建还款记录

**接口说明**: 创建新的还款记录，可以同时进行还款分配

- **请求方式**: `POST`
- **请求URL**: `/api/payments`
- **是否需要认证**: 是
- **权限要求**: 用户必须属于指定门店

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**请求参数**:

```json
{
    "store_id": 1,
    "customer_id": 1,
    "amount": 800.00,
    "payment_method": "cash",
    "reference_number": "REF123456",
    "remarks": "现金还款",
    "allocations": [
        {
            "invoice_id": 1,
            "amount": 500.00
        },
        {
            "invoice_id": 2,
            "amount": 300.00
        }
    ]
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| store_id | integer | 是 | 门店ID，必须存在 |
| customer_id | integer | 是 | 客户ID，必须存在 |
| amount | decimal | 是 | 还款金额，最小0.01 |
| payment_method | string | 是 | 支付方式：cash, bank_transfer, wechat, alipay, other |
| reference_number | string | 否 | 参考号码，最大255字符 |
| remarks | string | 否 | 备注信息 |
| allocations | array | 否 | 还款分配数组 |
| allocations.*.invoice_id | integer | 是 | 账单ID |
| allocations.*.amount | decimal | 是 | 分配金额 |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 2,
        "payment_number": "PAY-MAIN-20240115-XYZ78",
        "store_id": 1,
        "customer_id": 1,
        "received_by": 1,
        "amount": "800.00",
        "allocated_amount": "800.00",
        "payment_method": "cash",
        "reference_number": "REF123456",
        "remarks": "现金还款",
        "created_at": "2024-01-15T00:00:00.000000Z",
        "updated_at": "2024-01-15T00:00:00.000000Z",
        "allocations": [
            {
                "id": 1,
                "payment_id": 2,
                "invoice_id": 1,
                "amount": "500.00",
                "allocated_by": 1,
                "allocated_at": "2024-01-15T00:00:00.000000Z",
                "invoice": {
                    "id": 1,
                    "invoice_number": "MAIN-20240101-ABC12",
                    "amount": "1000.00",
                    "paid_amount": "500.00"
                }
            },
            {
                "id": 2,
                "payment_id": 2,
                "invoice_id": 2,
                "amount": "300.00",
                "allocated_by": 1,
                "allocated_at": "2024-01-15T00:00:00.000000Z",
                "invoice": {
                    "id": 2,
                    "invoice_number": "MAIN-20240102-DEF34",
                    "amount": "1000.00",
                    "paid_amount": "300.00"
                }
            }
        ],
        "customer": {
            "id": 1,
            "name": "张三",
            "phone": "13800138000"
        },
        "store": {
            "id": 1,
            "name": "总店"
        }
    },
    "message": "还款记录创建成功"
}
```

### 5.3 获取还款详情

**接口说明**: 获取指定还款的详细信息，包括分配记录

- **请求方式**: `GET`
- **请求URL**: `/api/payments/{id}`
- **是否需要认证**: 是
- **权限要求**: 用户必须属于该还款所在门店

**请求头**:

```
Authorization: Bearer {token}
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 还款ID |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 1,
        "payment_number": "PAY-MAIN-20240115-ABC12",
        "amount": "500.00",
        "allocated_amount": "500.00",
        "payment_method": "cash",
        "reference_number": null,
        "remarks": "现金还款",
        "created_at": "2024-01-15T00:00:00.000000Z",
        "updated_at": "2024-01-15T00:00:00.000000Z",
        "store": {
            "id": 1,
            "name": "总店"
        },
        "customer": {
            "id": 1,
            "name": "张三",
            "phone": "13800138000"
        },
        "received_by": {
            "id": 1,
            "name": "管理员"
        },
        "allocations": [
            {
                "id": 1,
                "amount": "500.00",
                "allocated_at": "2024-01-15T00:00:00.000000Z",
                "invoice": {
                    "id": 1,
                    "invoice_number": "MAIN-20240101-ABC12",
                    "amount": "1000.00",
                    "paid_amount": "500.00",
                    "status": "partially_paid"
                },
                "allocated_by": {
                    "id": 1,
                    "name": "管理员"
                }
            }
        ]
    }
}
```

### 5.4 分配还款到账单

**接口说明**: 将已存在的还款分配到指定账单

- **请求方式**: `POST`
- **请求URL**: `/api/payments/{id}/allocate`
- **是否需要认证**: 是
- **权限要求**: 用户必须属于该还款所在门店

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 还款ID |

**请求参数**:

```json
{
    "invoice_id": 2,
    "amount": 300.00
}
```

**参数说明**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| invoice_id | integer | 是 | 账单ID，必须存在 |
| amount | decimal | 是 | 分配金额，最小0.01 |

**业务规则**:

- 账单必须与还款属于同一客户和门店
- 分配金额不能超过账单剩余未付金额
- 分配金额不能超过还款剩余未分配金额

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "id": 2,
        "payment_id": 1,
        "invoice_id": 2,
        "amount": "300.00",
        "allocated_by": 1,
        "allocated_at": "2024-01-15T12:00:00.000000Z",
        "created_at": "2024-01-15T12:00:00.000000Z",
        "updated_at": "2024-01-15T12:00:00.000000Z"
    },
    "message": "还款分配成功"
}
```

### 5.5 删除还款记录

**接口说明**: 删除指定还款记录（仅管理员或门店经理可操作，且还款未分配）

- **请求方式**: `DELETE`
- **请求URL**: `/api/payments/{id}`
- **是否需要认证**: 是
- **权限要求**: 管理员或该门店的经理

**请求头**:

```
Authorization: Bearer {token}
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 还款ID |

**业务规则**:

- 只有管理员或门店经理可以删除还款记录
- 如果还款已经分配到账单，则不能删除

**成功响应示例**:

```json
{
    "success": true,
    "message": "还款记录删除成功"
}
```

**错误响应示例**:

```json
{
    "success": false,
    "message": "该还款记录已有分配，无法删除"
}
```

### 5.6 获取自动分配建议

**接口说明**: 获取还款的自动分配建议，支持多种分配策略

- **请求方式**: `GET`
- **请求URL**: `/api/payments/{id}/allocation-suggestion`
- **是否需要认证**: 是
- **权限要求**: 管理员或门店经理权限

**请求头**:

```
Authorization: Bearer {token}
```

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| strategy | string | 否 | 分配策略：oldest_first, due_date_first, smallest_first, largest_first, overdue_first |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "payment": {
            "id": 5,
            "amount": "200.00",
            "allocated_amount": "100.00",
            "unallocated_amount": 100.00
        },
        "suggestion": {
            "suggestions": [
                {
                    "invoice_id": 1,
                    "invoice_number": "INV-001",
                    "suggested_amount": 100.00,
                    "will_be_fully_paid": true
                }
            ],
            "total_debt": 100.00,
            "excess_amount": 0,
            "can_fully_allocate": true,
            "strategy": "oldest_first",
            "strategy_description": "按账单日期优先（最早的优先）"
        },
        "excess_info": {
            "is_excess": false,
            "excess_amount": 0
        },
        "available_strategies": [
            {
                "value": "oldest_first",
                "description": "按账单日期优先（最早的优先）"
            }
        ]
    },
    "message": "分配建议获取成功"
}
```

### 5.7 执行自动分配

**接口说明**: 根据指定策略自动分配还款到账单

- **请求方式**: `POST`
- **请求URL**: `/api/payments/{id}/auto-allocate`
- **是否需要认证**: 是
- **权限要求**: 管理员或门店经理权限

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| strategy | string | 否 | 分配策略，默认为oldest_first |
| confirm_excess | boolean | 否 | 确认超额还款，默认为false |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "payment": {
            "id": 5,
            "amount": "200.00",
            "allocated_amount": "200.00"
        },
        "allocations": [
            {
                "allocation": {
                    "id": 10,
                    "amount": "100.00"
                },
                "invoice": {
                    "id": 1,
                    "invoice_number": "INV-001"
                },
                "amount": 100.00
            }
        ],
        "strategy_used": "oldest_first"
    },
    "message": "自动分配完成"
}
```

### 5.8 批量自动分配

**接口说明**: 批量执行多笔还款的自动分配

- **请求方式**: `POST`
- **请求URL**: `/api/payments/batch-auto-allocate`
- **是否需要认证**: 是
- **权限要求**: 管理员或门店经理权限

**请求头**:

```
Authorization: Bearer {token}
Content-Type: application/json
```

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| payment_ids | array | 是 | 还款ID数组 |
| payment_ids.* | integer | 是 | 还款ID |
| strategy | string | 否 | 分配策略，默认为oldest_first |
| store_id | integer | 否 | 门店ID（非管理员必填） |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "results": [
            {
                "payment_id": 5,
                "success": true,
                "allocations_count": 1,
                "allocated_amount": 100.00
            }
        ],
        "summary": {
            "total_payments": 1,
            "successful_allocations": 1,
            "failed_allocations": 0,
            "strategy_used": "oldest_first"
        }
    },
    "message": "批量自动分配完成，成功处理 1/1 笔还款"
}
```

---

## 7. 用户管理（仅管理员）

### 6.1 获取用户列表

**接口说明**: 获取系统中所有用户的列表，支持搜索和筛选

- **请求方式**: `GET`
- **请求URL**: `/api/users`
- **是否需要认证**: 是
- **权限要求**: 系统管理员

**请求头**:
```
Authorization: Bearer {token}
```

**查询参数**:
| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| search | string | 否 | 搜索关键词（姓名、用户名、邮箱） |
| role | string | 否 | 角色筛选（admin, store_owner, store_staff） |
| page | integer | 否 | 页码，默认1 |
| per_page | integer | 否 | 每页数量，默认15 |

**请求示例**:
```
GET /api/users?search=张三&role=store_staff&page=1&per_page=10
```

**成功响应示例**:
```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 2,
                "name": "张三",
                "username": "zhangsan",
                "email": "zhangsan@example.com",
                "created_at": "2024-01-01T00:00:00.000000Z",
                "roles": [
                    {
                        "id": 3,
                        "name": "店员",
                        "slug": "store_staff"
                    }
                ],
                "permissions": {
                    "is_admin": false,
                    "is_store_owner": false,
                    "is_store_staff": true
                },
                "stores": [
                    {
                        "id": 1,
                        "name": "总店",
                        "code": "MAIN"
                    }
                ]
            }
        ],
        "first_page_url": "http://example.com/api/users?page=1",
        "from": 1,
        "last_page": 1,
        "last_page_url": "http://example.com/api/users?page=1",
        "next_page_url": null,
        "path": "http://example.com/api/users",
        "per_page": 15,
        "prev_page_url": null,
        "to": 1,
        "total": 1
    }
}
```

### 6.2 获取用户详情

**接口说明**: 获取指定用户的详细信息

- **请求方式**: `GET`
- **请求URL**: `/api/users/{id}`
- **是否需要认证**: 是
- **权限要求**: 系统管理员

**请求头**:
```
Authorization: Bearer {token}
```

**路径参数**:
| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 用户ID |

**成功响应示例**:
```json
{
    "success": true,
    "data": {
        "id": 2,
        "name": "张三",
        "username": "zhangsan",
        "email": "zhangsan@example.com",
        "email_verified_at": null,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z",
        "roles": [
            {
                "id": 3,
                "name": "店员",
                "slug": "store_staff"
            }
        ],
        "permissions": {
            "is_admin": false,
            "is_store_owner": false,
            "is_store_staff": true
        },
        "stores": [
            {
                "id": 1,
                "name": "总店",
                "code": "MAIN"
            }
        ]
    }
}
```

### 6.3 更新用户角色

**接口说明**: 更新指定用户的角色权限

- **请求方式**: `PUT`
- **请求URL**: `/api/users/{id}/roles`
- **是否需要认证**: 是
- **权限要求**: 系统管理员

**请求头**:
```
Authorization: Bearer {token}
Content-Type: application/json
```

**路径参数**:
| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 用户ID |

**请求参数**:
```json
{
    "role_ids": [2]
}
```

**参数说明**:
| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| role_ids | array | 是 | 角色ID数组 |
| role_ids.* | integer | 是 | 角色ID，必须存在 |

**成功响应示例**:
```json
{
    "success": true,
    "data": {
        "id": 2,
        "name": "张三",
        "username": "zhangsan",
        "email": "zhangsan@example.com",
        "roles": [
            {
                "id": 2,
                "name": "店长",
                "slug": "store_owner"
            }
        ],
        "permissions": {
            "is_admin": false,
            "is_store_owner": true,
            "is_store_staff": false
        },
        "stores": [
            {
                "id": 1,
                "name": "总店",
                "code": "MAIN"
            }
        ]
    },
    "message": "用户角色更新成功"
}
```

### 6.4 更新用户门店权限

**接口说明**: 更新指定用户的门店归属关系，权限级别由用户角色决定

- **请求方式**: `PUT`
- **请求URL**: `/api/users/{id}/stores`
- **是否需要认证**: 是
- **权限要求**: 系统管理员

**请求头**:
```
Authorization: Bearer {token}
Content-Type: application/json
```

**路径参数**:
| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 用户ID |

**请求参数**:
```json
{
    "stores": [1, 2, 3]
}
```

**参数说明**:
| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| stores | array | 是 | 门店ID数组 |
| stores.* | integer | 是 | 门店ID，必须存在 |

**权限说明**:
- 移除了is_manager字段，权限级别完全由用户角色决定
- 如果用户拥有store_owner角色，则在所有分配的门店中都具有管理权限
- 如果用户拥有store_staff角色，则在所有分配的门店中都具有基础操作权限

**成功响应示例**:
```json
{
    "success": true,
    "data": {
        "id": 2,
        "name": "张三",
        "username": "zhangsan",
        "email": "zhangsan@example.com",
        "roles": [
            {
                "id": 2,
                "name": "店长",
                "slug": "store_owner"
            }
        ],
        "stores": [
            {
                "id": 1,
                "name": "总店",
                "code": "MAIN"
            },
            {
                "id": 2,
                "name": "分店A",
                "code": "BRANCH_A"
            }
        ]
    },
    "message": "用户门店权限更新成功"
}
```

### 6.5 获取角色列表

**接口说明**: 获取系统中所有可用的角色列表

- **请求方式**: `GET`
- **请求URL**: `/api/roles`
- **是否需要认证**: 是
- **权限要求**: 系统管理员

**请求头**:
```
Authorization: Bearer {token}
```

**成功响应示例**:
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "系统管理员",
            "slug": "admin",
            "description": "系统管理员，拥有所有权限",
            "is_system": true
        },
        {
            "id": 2,
            "name": "店长",
            "slug": "store_owner",
            "description": "在其所属门店中拥有完全管理权限",
            "is_system": false
        },
        {
            "id": 3,
            "name": "店员",
            "slug": "store_staff",
            "description": "店员，可以处理日常业务",
            "is_system": false
        }
    ]
}
```

---

## 8. 仪表盘数据

### 7.1 获取仪表盘概览

**接口说明**: 获取仪表盘概览数据，包括系统基础统计信息和财务数据汇总

- **请求方式**: `GET`
- **请求URL**: `/api/dashboard/overview`
- **是否需要认证**: 是
- **权限要求**: 所有已认证用户

**请求头**:

```
Authorization: Bearer {token}
```

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "summary": {
            "total_customers": 150,
            "total_invoices": 1250,
            "total_payments": 980,
            "total_stores": 5
        },
        "financial": {
            "total_invoice_amount": "125000.00",
            "total_paid_amount": "98000.00",
            "total_outstanding_amount": "27000.00",
            "total_payment_amount": "98000.00"
        },
        "invoice_status_distribution": {
            "unpaid": 45,
            "partially_paid": 32,
            "paid": 173,
            "overdue": 12
        }
    }
}
```

### 7.2 获取详细统计数据

**接口说明**: 获取详细的统计数据，支持时间范围筛选，管理员可查看门店对比数据

- **请求方式**: `GET`
- **请求URL**: `/api/dashboard/statistics`
- **是否需要认证**: 是
- **权限要求**: 所有已认证用户

**请求头**:

```
Authorization: Bearer {token}
```

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| start_date | string | 否 | 开始日期，格式：YYYY-MM-DD |
| end_date | string | 否 | 结束日期，格式：YYYY-MM-DD |

**请求示例**:
```
GET /api/dashboard/statistics?start_date=2024-01-01&end_date=2024-12-31
```

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "period": {
            "start_date": "2024-01-01",
            "end_date": "2024-12-31"
        },
        "customers": {
            "total_customers": 150,
            "customers_with_debt": 45
        },
        "invoices": {
            "count": 1250,
            "total_amount": "125000.00",
            "paid_amount": "98000.00",
            "average_amount": "100.00"
        },
        "payments": {
            "count": 980,
            "total_amount": "98000.00",
            "average_amount": "100.00"
        },
        "stores": [
            {
                "store_id": 1,
                "store_name": "总店",
                "invoice_count": 500,
                "total_amount": "50000.00",
                "paid_amount": "40000.00"
            }
        ]
    }
}
```

**权限说明**:
- **系统管理员**: 可查看所有门店的统计数据，包含stores字段
- **门店经理/店员**: 只能查看所属门店的统计数据，stores字段为空数组

---

## 10. 错误处理

### 7.1 验证错误 (422)

当请求参数验证失败时，系统会返回详细的错误信息：

```json
{
    "success": false,
    "message": "The given data was invalid.",
    "errors": {
        "name": [
            "姓名字段是必填的。"
        ],
        "email": [
            "邮箱格式不正确。"
        ]
    }
}
```

### 7.2 权限错误 (403)

当用户没有足够权限访问资源时：

```json
{
    "success": false,
    "message": "权限不足"
}
```

### 7.3 资源不存在 (404)

当请求的资源不存在时：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

### 7.4 认证错误 (401)

当用户未认证或令牌无效时：

```json
{
    "success": false,
    "message": "Unauthenticated."
}
```

---

## 11. 业务状态说明

### 9.1 账单状态

| 状态 | 说明 |
|------|------|
| unpaid | 未付款 |
| partially_paid | 部分付款 |
| paid | 已付清 |
| overdue | 已逾期 |

### 9.2 支付方式

| 方式 | 说明 |
|------|------|
| cash | 现金 |
| bank_transfer | 银行转账 |
| wechat | 微信支付 |
| alipay | 支付宝 |
| other | 其他方式 |

---

## 12. 使用示例

### 12.1 完整的业务流程示例

以下是一个完整的债务管理业务流程示例：

#### 步骤1: 用户登录

```bash
curl -X POST http://your-domain.com/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "password"
  }'
```

#### 步骤2: 创建客户

```bash
curl -X POST http://your-domain.com/api/customers \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "张三",
    "phone": "13800138000",
    "email": "zhangsan@example.com"
  }'
```

#### 步骤3: 创建账单

```bash
curl -X POST http://your-domain.com/api/invoices \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "customer_id": 1,
    "amount": 1000.00,
    "due_date": "2024-01-31",
    "description": "商品销售"
  }'
```

#### 步骤4: 记录还款并分配

```bash
curl -X POST http://your-domain.com/api/payments \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "store_id": 1,
    "customer_id": 1,
    "amount": 500.00,
    "payment_method": "cash",
    "allocations": [
      {
        "invoice_id": 1,
        "amount": 500.00
      }
    ]
  }'
```

#### 步骤5: 查看客户欠款汇总

```bash
curl -X GET http://your-domain.com/api/customers/1/debt \
  -H "Authorization: Bearer {token}"
```

---

## 13. 开发注意事项

### 11.1 时区处理

- 所有日期时间字段均使用UTC时间存储
- 前端应根据用户时区进行转换显示

### 11.2 数据精度

- 金额字段使用decimal类型，保留2位小数
- 计算时注意浮点数精度问题

### 11.3 并发处理

- 还款分配操作使用数据库事务确保数据一致性
- 建议在高并发场景下使用乐观锁机制

### 11.4 性能优化

- 列表接口支持分页，建议合理设置每页数量
- 使用Eager Loading减少N+1查询问题
- 对于大量数据的统计查询，建议使用缓存

---

## 14. 更新日志

### v1.2.1 (2025-07-22) - 账单API优化
- 🔧 **修复**: 确保账单列表API正确返回due_date字段
- ✅ 为缺失due_date的账单设置默认值（账单日期+30天）
- 📝 更新了账单列表API文档，完善了响应字段说明
- ✅ 添加了详细的权限控制说明
- ✅ 补充了完整的分页字段说明
- 📋 完成了账单列表API的全面功能测试，测试覆盖率99%

### v1.2.0 (2025-07-21) - 仪表盘功能
- 🆕 **新增功能**: 添加了完整的仪表盘数据接口
- ✅ 实现了仪表盘概览接口 (GET /api/dashboard/overview)
- ✅ 实现了详细统计数据接口 (GET /api/dashboard/statistics)
- ✅ 支持基于角色的数据权限控制
- ✅ 提供系统概览、财务汇总、账单状态分布等统计数据
- ✅ 支持时间范围筛选和门店对比功能
- 📝 完善了API文档，添加了仪表盘接口章节

### v1.1.1 (2025-07-21) - 紧急修复
- 🔧 **紧急修复**: 解决了"Table 'role_user' doesn't exist"数据库错误
- ✅ 重新创建了缺失的角色权限系统表（roles, role_user, store_user）
- ✅ 恢复了基础角色数据（系统管理员、门店经理、店员）
- ✅ 修复了用户-角色关联功能
- ✅ 验证了权限检查机制正常运行
- 📝 更新了API文档，添加了系统状态说明

### v1.1.0 (2024-07-05)
- 新增用户注册功能，新用户默认获得店员权限
- 支持用户名登录，包括中文用户名
- 新增用户管理功能，管理员可以修改用户角色和门店权限
- 增强登录接口，支持邮箱或用户名登录
- 完善权限管理体系

### v1.0.0 (2024-01-01)

- 初始版本发布
- 实现基础的门店、客户、账单、还款管理功能
- 支持基于角色的权限控制
- 提供完整的RESTful API接口

---

*本文档最后更新时间：2025-07-22*

### 3.6 获取客户欠款汇总

**接口说明**: 获取指定客户的欠款汇总信息，包括传统欠款、实际欠款（含优惠减免）和未付清账单列表。

**⚠️ 权限控制**:
- **门店权限隔离**: 用户只能查看其有权限访问的门店的客户数据
- **店员/店长**: 只能看到所属门店的数据
- **系统管理员**: 可以看到所有门店的数据
- **权限验证**: 如果指定store_id参数，系统会验证用户是否有权限访问该门店

- **请求方式**: `GET`
- **请求URL**: `/api/customers/{id}/debt`
- **是否需要认证**: 是

**请求头**:

```
Authorization: Bearer {token}
```

**路径参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| id | integer | 是 | 客户ID |

**查询参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| store_id | integer | 否 | 门店ID，指定查询特定门店的客户欠款。如不指定，返回用户有权限访问的所有门店的汇总数据 |

**成功响应示例**:

```json
{
    "success": true,
    "data": {
        "customer": {
            "id": 1,
            "name": "张三",
            "phone": "13800138000"
        },
        "traditional_debt": 2500.00,
        "actual_debt": 2465.00,
        "discount_summary": {
            "total_count": 2,
            "total_amount": 35.00,
            "by_type": {
                "discount": {
                    "count": 1,
                    "amount": 20.00
                },
                "promotion": {
                    "count": 1,
                    "amount": 15.00
                }
            }
        },
        "store_debt_info": {
            "total_invoices": 3,
            "unpaid_invoices": 2,
            "total_amount": 2500.00,
            "paid_amount": 0.00,
            "discount_amount": 35.00,
            "traditional_debt": 2500.00,
            "actual_debt": 2465.00,
            "discount_rate": 1.40,
            "store_count": 2
        },
        "accessible_stores": [1, 2],
        "unpaid_invoices": [
            {
                "id": 1,
                "invoice_number": "INV-20240101-001",
                "store_id": 1,
                "amount": "1500.00",
                "paid_amount": "0.00",
                "discount_amount": "20.00",
                "actual_remaining": "1480.00",
                "status": "unpaid",
                "due_date": "2024-01-31",
                "has_discounts": true
            },
            {
                "id": 2,
                "invoice_number": "INV-20240102-001",
                "store_id": 2,
                "amount": "1000.00",
                "paid_amount": "0.00",
                "discount_amount": "15.00",
                "actual_remaining": "985.00",
                "status": "unpaid",
                "due_date": "2024-02-15",
                "has_discounts": true
            }
        ]
    }
}
```

**响应字段说明**:

| 字段名 | 类型 | 说明 |
|--------|------|------|
| customer | object | 客户基本信息 |
| traditional_debt | decimal | 传统欠款金额（不含优惠减免） |
| actual_debt | decimal | 实际欠款金额（含优惠减免） |
| discount_summary | object | 优惠减免统计信息 |
| discount_summary.total_count | integer | 优惠减免记录总数 |
| discount_summary.total_amount | decimal | 优惠减免总金额 |
| discount_summary.by_type | object | 按类型分组的优惠减免统计 |
| store_debt_info | object | 门店欠款汇总信息 |
| store_debt_info.total_invoices | integer | 总账单数 |
| store_debt_info.unpaid_invoices | integer | 未付清账单数 |
| store_debt_info.total_amount | decimal | 账单总金额 |
| store_debt_info.paid_amount | decimal | 已付金额 |
| store_debt_info.discount_amount | decimal | 优惠减免金额 |
| store_debt_info.traditional_debt | decimal | 传统欠款 |
| store_debt_info.actual_debt | decimal | 实际欠款 |
| store_debt_info.discount_rate | decimal | 优惠减免率（百分比） |
| store_debt_info.store_count | integer | 涉及门店数量 |
| accessible_stores | array | 用户可访问的门店ID列表 |
| unpaid_invoices | array | 未付清账单列表 |
| unpaid_invoices[].store_id | integer | 账单所属门店ID |
| unpaid_invoices[].discount_amount | decimal | 该账单的优惠减免金额 |
| unpaid_invoices[].actual_remaining | decimal | 实际剩余金额（含优惠减免） |
| unpaid_invoices[].has_discounts | boolean | 是否有优惠减免记录 |

**错误响应示例**:

1. **权限不足（403）**:
```json
{
    "success": false,
    "message": "您没有权限访问该门店的数据"
}
```

2. **无门店权限（403）**:
```json
{
    "success": false,
    "message": "您没有权限访问任何门店的数据"
}
```

3. **客户不存在（404）**:
```json
{
    "success": false,
    "message": "客户不存在"
}
```

**使用示例**:

1. **查询用户有权限的所有门店的客户欠款**:
```bash
GET /api/customers/1/debt
```

2. **查询特定门店的客户欠款**:
```bash
GET /api/customers/1/debt?store_id=1
```

---

## 9. 优惠减免管理

优惠减免功能允许授权用户对客户的未付账单进行折扣、优惠或坏账核销处理。系统支持三种类型的优惠减免：

- **折扣(discount)**: 一般性的价格折扣
- **促销优惠(promotion)**: 促销活动产生的优惠
- **坏账核销(write_off)**: 无法收回的债务核销

### 权限要求

- **系统管理员**: 可以进行任何类型和金额的优惠减免
- **店长**: 可以在其管理的门店进行优惠减免，受配置限制
- **店员**: 只能进行小额折扣，受严格的金额和类型限制

### 9.1 检测还款差额

检测还款金额与客户总欠款的差额，并获取优惠减免建议。

**接口地址**: `GET /api/payments/{payment}/detect-gap`

**权限要求**: 需要对应门店的访问权限

**请求参数**: 无

**响应示例**:

```json
{
    "success": true,
    "message": "差额检测完成",
    "data": {
        "payment": {
            "id": 1,
            "payment_number": "PAY-20240101-001",
            "amount": "2300.00",
            "customer": {
                "id": 1,
                "name": "张三"
            },
            "store": {
                "id": 1,
                "name": "总店"
            }
        },
        "gap_info": {
            "has_gap": true,
            "gap_amount": 35.00,
            "total_debt": 2335.00,
            "payment_amount": 2300.00,
            "can_apply_discount": true,
            "suggested_discount_type": "discount",
            "unpaid_invoices": [
                {
                    "id": 1,
                    "invoice_number": "INV-001",
                    "amount": "1500.00",
                    "actual_remaining_amount": 1500.00
                },
                {
                    "id": 2,
                    "invoice_number": "INV-002",
                    "amount": "835.00",
                    "actual_remaining_amount": 835.00
                }
            ]
        },
        "can_approve_discount": true
    }
}
```

### 9.2 处理优惠减免

对指定的还款记录应用优惠减免。

**接口地址**: `POST /api/payments/{payment}/apply-discount`

**权限要求**: 需要优惠减免权限

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| discount_data | array | 是 | 优惠减免数据数组 |
| discount_data.*.invoice_id | integer | 是 | 账单ID |
| discount_data.*.amount | decimal | 是 | 优惠减免金额 |
| discount_data.*.type | string | 否 | 减免类型：discount/promotion/write_off |
| discount_data.*.reason | string | 否 | 减免原因说明 |

**请求示例**:

```json
{
    "discount_data": [
        {
            "invoice_id": 2,
            "amount": 35.00,
            "type": "discount",
            "reason": "客户优惠抹零"
        }
    ]
}
```

**响应示例**:

```json
{
    "success": true,
    "message": "优惠减免处理成功",
    "data": {
        "payment": {
            "id": 1,
            "payment_number": "PAY-20240101-001",
            "amount": "2300.00",
            "discounts": [
                {
                    "id": 1,
                    "discount_amount": "35.00",
                    "discount_type": "discount",
                    "reason": "客户优惠抹零",
                    "invoice": {
                        "id": 2,
                        "invoice_number": "INV-002"
                    },
                    "approved_by": {
                        "id": 1,
                        "name": "店长"
                    },
                    "created_at": "2024-01-01T12:00:00.000000Z"
                }
            ]
        },
        "result": {
            "success": true,
            "allocations": [...],
            "discounts": [...],
            "message": "优惠抹零处理完成"
        }
    }
}
```

### 9.3 创建还款时处理优惠减免

在创建还款记录的同时处理优惠减免。

**接口地址**: `POST /api/payments`

**扩展参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| apply_discount | boolean | 否 | 是否应用优惠减免 |
| discount_data | array | 否 | 优惠减免数据（当apply_discount为true时必填） |

**请求示例**:

```json
{
    "store_id": 1,
    "customer_id": 1,
    "amount": 2300.00,
    "payment_method": "cash",
    "remarks": "上门收款",
    "apply_discount": true,
    "discount_data": [
        {
            "invoice_id": 2,
            "amount": 35.00,
            "type": "discount",
            "reason": "优惠抹零"
        }
    ]
}
```

**响应示例**:

```json
{
    "success": true,
    "message": "还款记录创建成功，已处理优惠抹零",
    "data": {
        "payment": {
            "id": 1,
            "payment_number": "PAY-20240101-001",
            "amount": "2300.00",
            "discounts": [...],
            "allocations": [...]
        },
        "discount_result": {
            "success": true,
            "allocations": [...],
            "discounts": [...],
            "gap_info": {...}
        }
    }
}
```

### 9.4 获取优惠减免统计

获取指定门店的优惠减免统计信息。

**接口地址**: `GET /api/discount-statistics`

**权限要求**: 需要对应门店的访问权限

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| store_id | integer | 否 | 门店ID（管理员可选，其他角色自动使用所属门店） |
| start_date | date | 否 | 开始日期 |
| end_date | date | 否 | 结束日期 |

**响应示例**:

```json
{
    "success": true,
    "message": "优惠减免统计获取成功",
    "data": {
        "total_count": 15,
        "total_amount": 1250.50,
        "average_amount": 83.37,
        "by_type": {
            "discount": {
                "count": 10,
                "amount": 450.00
            },
            "promotion": {
                "count": 3,
                "amount": 300.50
            },
            "write_off": {
                "count": 2,
                "amount": 500.00
            }
        }
    }
}
```

### 9.5 自动分配支持优惠减免

自动分配接口现在支持优惠减免处理。

**接口地址**: `POST /api/payments/{payment}/auto-allocate`

**扩展参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| include_discount | boolean | 否 | 是否包含优惠减免处理（默认true） |

**响应示例**（包含优惠减免）:

```json
{
    "success": true,
    "message": "自动分配完成，已处理优惠减免",
    "data": {
        "payment": {
            "id": 1,
            "amount": "2300.00",
            "allocations": [...],
            "discounts": [...]
        },
        "allocations": [...],
        "discounts": [
            {
                "discount": {
                    "id": 1,
                    "discount_amount": "35.00",
                    "discount_type": "discount"
                },
                "invoice": {...},
                "amount": 35.00
            }
        ],
        "strategy_used": "oldest_first",
        "message": "自动分配完成，已处理优惠减免"
    }
}
```

### 9.6 客户欠款信息扩展

客户欠款接口（详见第3.6节）现在包含优惠减免信息和门店权限控制。

**📋 完整接口定义**: 请参考第3.6节"获取客户欠款汇总"获取完整的接口文档。

**⚠️ 重要更新 - 权限控制修复**:
- **门店权限隔离**: 用户只能查看其有权限访问的门店的客户数据
- **数据安全**: 修复了权限控制漏洞，确保门店数据严格隔离
- **权限验证**: 所有查询都会验证用户的门店访问权限

**🆕 新增功能**:
- **查询参数**: `store_id` (可选) - 指定查询特定门店的客户欠款
- **权限字段**: `accessible_stores` - 返回用户可访问的门店列表
- **优惠减免**: 完整的优惠减免统计和明细信息

**扩展响应字段**:

```json
{
    "success": true,
    "data": {
        "customer": {...},
        "traditional_debt": 2335.00,
        "actual_debt": 2300.00,
        "discount_summary": {
            "total_count": 1,
            "total_amount": 35.00,
            "by_type": {
                "discount": {
                    "count": 1,
                    "amount": 35.00
                }
            }
        },
        "store_debt_info": {
            "total_invoices": 2,
            "unpaid_invoices": 0,
            "total_amount": 2335.00,
            "paid_amount": 2300.00,
            "discount_amount": 35.00,
            "traditional_debt": 35.00,
            "actual_debt": 0.00,
            "discount_rate": 1.50,
            "store_count": 1
        },
        "accessible_stores": [1],
        "unpaid_invoices": [
            {
                "id": 1,
                "invoice_number": "INV-001",
                "store_id": 1,
                "amount": "1500.00",
                "paid_amount": "1500.00",
                "discount_amount": "0.00",
                "actual_remaining": "0.00",
                "status": "paid",
                "has_discounts": false
            },
            {
                "id": 2,
                "invoice_number": "INV-002",
                "store_id": 1,
                "amount": "835.00",
                "paid_amount": "800.00",
                "discount_amount": "35.00",
                "actual_remaining": "0.00",
                "status": "paid",
                "has_discounts": true
            }
        ]
    }
}
```

**新增字段说明**:
- `traditional_debt`: 传统欠款金额（不含优惠减免）
- `actual_debt`: 实际欠款金额（含优惠减免）
- `discount_summary`: 优惠减免统计信息
- `store_debt_info`: 门店欠款汇总信息（新增store_count字段）
- `accessible_stores`: 用户可访问的门店ID列表（权限控制）
- `unpaid_invoices[].store_id`: 账单所属门店ID（权限控制）
- `unpaid_invoices[].discount_amount`: 该账单的优惠减免金额
- `unpaid_invoices[].actual_remaining`: 实际剩余金额（含优惠减免）
- `unpaid_invoices[].has_discounts`: 是否有优惠减免记录

**权限控制行为**:
- 店员/店长只能看到所属门店的数据
- 管理员可以看到所有门店的数据
- 指定store_id参数时会验证用户权限
- 无权限访问时返回403错误

## 优惠减免配置

系统通过配置文件 `config/payment.php` 管理优惠减免相关的参数：

### 配置项说明

```php
// 单笔优惠减免最大金额
'max_discount_amount' => 1000,

// 每日优惠减免总额限制
'daily_discount_limit' => 5000,

// 优惠减免类型配置
'discount_types' => [
    'write_off' => [
        'name' => '坏账核销',
        'max_amount' => 2000,
        'requires_approval' => true,
        'approval_roles' => ['admin', 'store_owner']
    ],
    'discount' => [
        'name' => '折扣',
        'max_amount' => 500,
        'requires_approval' => false,
        'approval_roles' => ['admin', 'store_owner', 'store_staff']
    ],
    'promotion' => [
        'name' => '促销优惠',
        'max_amount' => 1000,
        'requires_approval' => false,
        'approval_roles' => ['admin', 'store_owner']
    ]
],

// 自动优惠减免配置
'auto_discount' => [
    'enabled' => true,
    'max_amount' => 100,
    'threshold' => 10, // 小于此金额自动建议优惠减免
],

// 审计配置
'audit' => [
    'log_all_discounts' => true,
    'require_reason' => true,
    'min_reason_length' => 5,
]
```

## 错误处理

优惠减免相关的错误代码：

| 错误代码 | HTTP状态码 | 说明 |
|----------|------------|------|
| DISCOUNT_PERMISSION_DENIED | 403 | 没有优惠减免权限 |
| DISCOUNT_AMOUNT_EXCEEDED | 422 | 优惠减免金额超过限制 |
| DISCOUNT_INVALID_TYPE | 422 | 无效的优惠减免类型 |
| DISCOUNT_INVOICE_MISMATCH | 422 | 账单与还款不匹配 |
| DISCOUNT_ALREADY_PROCESSED | 422 | 账单已处理过优惠减免 |
| DISCOUNT_VALIDATION_FAILED | 422 | 优惠减免数据验证失败 |

## 使用场景示例

### 场景1：客户上门付款优惠抹零

客户张三在总店有两个未付账单，总计2335元，实际付款2300元，需要35元优惠抹零：

1. **创建还款并处理优惠减免**:
```bash
POST /api/payments
{
    "store_id": 1,
    "customer_id": 1,
    "amount": 2300.00,
    "payment_method": "cash",
    "apply_discount": true,
    "discount_data": [
        {
            "invoice_id": 2,
            "amount": 35.00,
            "type": "discount",
            "reason": "客户优惠抹零"
        }
    ]
}
```

2. **验证处理结果**:
```bash
GET /api/customers/1/debt?store_id=1
```

### 场景2：分步处理优惠减免

1. **先创建还款记录**:
```bash
POST /api/payments
{
    "store_id": 1,
    "customer_id": 1,
    "amount": 2300.00,
    "payment_method": "cash"
}
```

2. **检测差额**:
```bash
GET /api/payments/1/detect-gap
```

3. **应用优惠减免**:
```bash
POST /api/payments/1/apply-discount
{
    "discount_data": [
        {
            "invoice_id": 2,
            "amount": 35.00,
            "type": "discount",
            "reason": "优惠抹零"
        }
    ]
}
```

### 场景3：查看优惠减免统计

```bash
GET /api/discount-statistics?store_id=1&start_date=2024-01-01&end_date=2024-01-31
```
```
