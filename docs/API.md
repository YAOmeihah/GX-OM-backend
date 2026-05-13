# DVideo API 文档

> 自动生成时间：2026-01-25 22:03:51

# Introduction

多门店债务管理系统 RESTful API，支持门店、客户、账单、还款、附件和审计日志管理。

<aside>
    <strong>Base URL</strong>: <code>http://localhost</code>
</aside>

欢迎使用债务管理系统 API 文档！本文档提供了与 API 交互所需的全部信息。

## 主要功能
- **用户认证**: 登录、登出、密码管理
- **门店管理**: 创建和管理多个门店
- **客户管理**: 客户信息和欠款查询
- **账单管理**: 账单创建、明细、附件
- **还款管理**: 还款记录、自动分配、优惠减免
- **审计日志**: 操作追踪和审计

## 权限级别
- **系统管理员(admin)**: 拥有系统所有权限
- **店长(store_owner)**: 管理所属门店
- **店员(store_staff)**: 基础操作权限

<aside>右侧深色区域显示不同编程语言的代码示例，可通过顶部标签切换。</aside>



## 认证方式

# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_AUTH_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

通过 <code>POST /api/login</code> 登录获取访问令牌，然后在请求头中添加 <code>Authorization: Bearer {token}</code>


## 目录

- [认证管理](#认证管理)
  - [POST api/login](#post_api_login)
  - [POST api/register](#post_api_register)
  - [POST api/logout](#post_api_logout)
  - [GET api/user](#get_api_user)
  - [PUT api/user/password](#put_api_user_password)
- [仪表盘](#仪表盘)
  - [GET api/dashboard/overview](#get_api_dashboard_overview)
  - [GET api/dashboard/statistics](#get_api_dashboard_statistics)
- [用户管理](#用户管理)
  - [POST api/users](#post_api_users)
  - [GET api/users](#get_api_users)
  - [GET api/users/{user_id}](#get_api_users__user_id_)
  - [PUT api/users/{user_id}](#put_api_users__user_id_)
  - [PUT api/users/{user_id}/roles](#put_api_users__user_id__roles)
  - [PUT api/users/{user_id}/stores](#put_api_users__user_id__stores)
  - [GET api/roles](#get_api_roles)
- [门店管理](#门店管理)
  - [GET api/stores/{store}/users](#get_api_stores__store__users)
  - [GET api/stores/{store}/payment-codes](#get_api_stores__store__payment_codes)
  - [GET api/stores](#get_api_stores)
  - [POST api/stores](#post_api_stores)
  - [GET api/stores/{id}](#get_api_stores__id_)
  - [PUT api/stores/{id}](#put_api_stores__id_)
  - [DELETE api/stores/{id}](#delete_api_stores__id_)
- [客户管理](#客户管理)
  - [GET api/customers](#get_api_customers)
  - [POST api/customers](#post_api_customers)
  - [GET api/customers/{id}](#get_api_customers__id_)
  - [PUT api/customers/{id}](#put_api_customers__id_)
  - [DELETE api/customers/{id}](#delete_api_customers__id_)
  - [GET api/customers/{customer}/debt](#get_api_customers__customer__debt)
  - [POST api/customers/{customer}/clear-debt](#post_api_customers__customer__clear_debt)
- [账单管理](#账单管理)
  - [GET api/invoices](#get_api_invoices)
  - [POST api/invoices](#post_api_invoices)
  - [GET api/invoices/{id}](#get_api_invoices__id_)
  - [PUT api/invoices/{id}](#put_api_invoices__id_)
  - [DELETE api/invoices/{id}](#delete_api_invoices__id_)
- [账单明细](#账单明细)
  - [GET api/invoices/{invoice}/items](#get_api_invoices__invoice__items)
  - [POST api/invoices/{invoice}/items](#post_api_invoices__invoice__items)
  - [PUT api/invoice-items/{item}](#put_api_invoice_items__item_)
  - [DELETE api/invoice-items/{item}](#delete_api_invoice_items__item_)
- [附件管理](#附件管理)
  - [POST api/attachments/presigned-url](#post_api_attachments_presigned_url)
  - [POST api/attachments](#post_api_attachments)
  - [GET api/attachments](#get_api_attachments)
  - [DELETE api/attachments/{attachment}](#delete_api_attachments__attachment_)
  - [POST api/permissions/attachments/presigned-url](#post_api_permissions_attachments_presigned_url)
  - [POST api/permissions/attachments](#post_api_permissions_attachments)
  - [GET api/permissions/attachments](#get_api_permissions_attachments)
  - [DELETE api/permissions/attachments/{id}](#delete_api_permissions_attachments__id_)
- [配置管理](#配置管理)
  - [GET api/config/attachment](#get_api_config_attachment)
  - [PUT api/config/s3](#put_api_config_s3)
  - [POST api/config/s3/test](#post_api_config_s3_test)
- [还款管理](#还款管理)
  - [GET api/payments](#get_api_payments)
  - [POST api/payments](#post_api_payments)
  - [GET api/payments/{id}](#get_api_payments__id_)
  - [DELETE api/payments/{id}](#delete_api_payments__id_)
  - [POST api/payments/{payment}/allocate](#post_api_payments__payment__allocate)
  - [POST api/payments/{payment}/batch-allocate](#post_api_payments__payment__batch_allocate)
  - [GET api/payments/{payment}/allocation-suggestion](#get_api_payments__payment__allocation_suggestion)
  - [POST api/payments/{payment}/auto-allocate](#post_api_payments__payment__auto_allocate)
  - [POST api/payments/batch-auto-allocate](#post_api_payments_batch_auto_allocate)
  - [DELETE api/payments/{payment}/allocations/{allocation}](#delete_api_payments__payment__allocations__allocation_)
  - [DELETE api/payments/{payment}/allocations](#delete_api_payments__payment__allocations)
  - [GET api/payments/{payment}/detect-gap](#get_api_payments__payment__detect_gap)
  - [POST api/payments/{payment}/apply-discount](#post_api_payments__payment__apply_discount)
  - [GET api/discount-statistics](#get_api_discount_statistics)
- [审计日志](#审计日志)
  - [GET api/audit-logs](#get_api_audit_logs)
  - [GET api/audit-logs/statistics](#get_api_audit_logs_statistics)
  - [GET api/audit-logs/filters](#get_api_audit_logs_filters)
  - [GET api/audit-logs/history](#get_api_audit_logs_history)
  - [GET api/audit-logs/user-activity/{userId?}](#get_api_audit_logs_user_activity__userid?_)
  - [GET api/audit-logs/{id}](#get_api_audit_logs__id_)
- [权限管理](#权限管理)
  - [GET api/permissions/my](#get_api_permissions_my)
  - [GET api/permissions](#get_api_permissions)
  - [GET api/permissions/modules](#get_api_permissions_modules)
  - [GET api/permissions/roles/{role_id}](#get_api_permissions_roles__role_id_)
  - [PUT api/permissions/roles/{role_id}](#put_api_permissions_roles__role_id_)
- [其他接口](#其他接口)
  - [GET api/permissions/check-token](#get_api_permissions_check_token)
- [数据维护](#数据维护)
  - [GET api/maintenance/types](#get_api_maintenance_types)
  - [POST api/maintenance/scan](#post_api_maintenance_scan)
  - [POST api/maintenance/execute](#post_api_maintenance_execute)
  - [GET api/maintenance/history](#get_api_maintenance_history)
  - [POST api/maintenance/export](#post_api_maintenance_export)
  - [GET api/maintenance/export/{filename}](#get_api_maintenance_export__filename_)

---

## 认证管理 {#认证管理}

### 用户登录 {#post_api_login}

**请求方式：** `POST`

**请求路径：** `api/login`

**需要认证：** ❌ 否

**接口描述：**

使用用户名/邮箱和密码登录系统，获取访问令牌。
支持使用邮箱或用户名登录，系统会自动识别。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `login` | string | ✅ | 用户名或邮箱地址 | `admin` |
| `password` | string | ✅ | 用户密码 | `password123` |

**响应示例：**

**登录成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "name": "管理员",
            "username": "admin",
            "email": "admin@example.com",
            "roles": [
                "admin"
            ],
            "stores": [
                {
                    "id": 1,
                    "name": "总店",
                    "code": "MAIN",
                    "is_manager": true
                }
            ]
        },
        "token": "1|abcdefghijklmnopqrstuvwxyz123456"
    },
    "message": "登录成功"
}
```

**用户名或密码错误** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "login": [
            "用户名\/邮箱或密码错误"
        ]
    }
}
```

**参数验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "login": [
            "login 字段是必需的"
        ],
        "password": [
            "password 字段是必需的"
        ]
    }
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"login":"admin","password":"password123"}' \
  "http://localhost:8000/api/login"
```

```javascript
fetch('http://localhost:8000/api/login', {
  method: 'POST',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"login":"admin","password":"password123"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 用户注册 {#post_api_register}

**请求方式：** `POST`

**请求路径：** `api/register`

**需要认证：** ✅ 是

**接口描述：**

注册新用户账号，新用户默认获得店员(store_staff)角色。
需要管理员权限才能注册新用户。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `name` | string | ✅ | 用户真实姓名，最大255字符 | `张三` |
| `username` | string | ✅ | 登录用户名，最大255字符，必须唯一 | `zhangsan` |
| `email` | string | ✅ | 邮箱地址，必须唯一 | `zhangsan@example.com` |
| `password` | string | ✅ | 密码，最少6位 | `password123` |
| `password_confirmation` | string | ✅ | 确认密码，必须与password一致 | `password123` |

**响应示例：**

**注册成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "user": {
            "id": 2,
            "name": "张三",
            "username": "zhangsan",
            "email": "zhangsan@example.com",
            "roles": [
                "store_staff"
            ],
            "stores": []
        },
        "token": "2|abcdefghijklmnopqrstuvwxyz123456"
    },
    "message": "注册成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "username": [
            "用户名已被使用"
        ],
        "email": [
            "邮箱已被注册"
        ],
        "password": [
            "密码长度不能少于6位"
        ]
    }
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"张三","username":"zhangsan","email":"zhangsan@example.com","password":"password123","password_confirmation":"password123"}' \
  "http://localhost:8000/api/register"
```

```javascript
fetch('http://localhost:8000/api/register', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"name":"张三","username":"zhangsan","email":"zhangsan@example.com","password":"password123","password_confirmation":"password123"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 用户登出 {#post_api_logout}

**请求方式：** `POST`

**请求路径：** `api/logout`

**需要认证：** ✅ 是

**接口描述：**

销毁当前用户的访问令牌，使其失效。

**响应示例：**

**登出成功** (HTTP 200)：

```json
{
    "success": true,
    "data": null,
    "message": "登出成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/logout"
```

```javascript
fetch('http://localhost:8000/api/logout', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取当前用户信息 {#get_api_user}

**请求方式：** `GET`

**请求路径：** `api/user`

**需要认证：** ✅ 是

**接口描述：**

获取当前登录用户的详细信息，包括角色和门店权限。

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "管理员",
        "username": "admin",
        "email": "admin@example.com",
        "email_verified_at": "2024-01-01T00:00:00.000000Z",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z",
        "roles": [
            "admin"
        ],
        "stores": [
            {
                "id": 1,
                "name": "总店",
                "code": "MAIN",
                "is_manager": true
            }
        ]
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/user"
```

```javascript
fetch('http://localhost:8000/api/user', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 修改密码 {#put_api_user_password}

**请求方式：** `PUT`

**请求路径：** `api/user/password`

**需要认证：** ✅ 是

**接口描述：**

修改当前登录用户的密码。需要验证当前密码，新密码不能与当前密码相同。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `current_password` | string | ✅ | 当前密码 | `oldpassword123` |
| `new_password` | string | ✅ | 新密码，最少6位 | `newpassword456` |
| `new_password_confirmation` | string | ✅ | 确认新密码 | `newpassword456` |

**响应示例：**

**修改成功** (HTTP 200)：

```json
{
    "success": true,
    "data": null,
    "message": "密码修改成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**当前密码错误** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "current_password": [
            "当前密码错误"
        ]
    }
}
```

**新密码与当前密码相同** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "new_password": [
            "新密码不能与当前密码相同"
        ]
    }
}
```

**新密码确认不一致** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "new_password": [
            "新密码与确认密码不一致"
        ]
    }
}
```

**请求示例：**

```bash
curl -X PUT \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"current_password":"oldpassword123","new_password":"newpassword456","new_password_confirmation":"newpassword456"}' \
  "http://localhost:8000/api/user/password"
```

```javascript
fetch('http://localhost:8000/api/user/password', {
  method: 'PUT',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"current_password":"oldpassword123","new_password":"newpassword456","new_password_confirmation":"newpassword456"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 仪表盘 {#仪表盘}

### 获取仪表盘概览 {#get_api_dashboard_overview}

**请求方式：** `GET`

**请求路径：** `api/dashboard/overview`

**需要认证：** ✅ 是

**接口描述：**

获取系统概览数据，包括今日、昨日和总体统计。
非管理员用户只能看到自己所属门店的数据统计。
支持通过 store_id 参数筛选特定门店的数据。

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `store_id` | integer | ❌ | 可选，按门店ID筛选统计数据（用户需有该门店权限） | `1` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "summary": {
            "total_customers": 100,
            "total_invoices": 500,
            "total_payments": 300,
            "total_stores": 5
        },
        "today": {
            "invoice_amount": "10000.00",
            "invoice_count": 5,
            "payment_amount": "8000.00",
            "payment_count": 3,
            "discount_amount": "500.00",
            "new_customers": 2
        },
        "yesterday": {
            "invoice_amount": "9000.00",
            "invoice_count": 4,
            "payment_amount": "6000.00",
            "payment_count": 2,
            "discount_amount": "300.00",
            "new_customers": 1
        },
        "overall": {
            "invoice_amount": "500000.00",
            "paid_amount": "350000.00",
            "outstanding_amount": "145000.00",
            "discount_amount": "5000.00",
            "collection_rate": 70
        },
        "invoice_status_distribution": {
            "unpaid": 50,
            "partially_paid": 100,
            "paid": 300,
            "overdue": 50
        }
    }
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/dashboard/overview"?store_id=1
```

```javascript
fetch('http://localhost:8000/api/dashboard/overview?store_id=1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取详细统计数据 {#get_api_dashboard_statistics}

**请求方式：** `GET`

**请求路径：** `api/dashboard/statistics`

**需要认证：** ✅ 是

**接口描述：**

获取更详细的统计数据，支持按时间范围筛选。
管理员可以看到各门店的分别统计。

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `start_date` | string | ❌ | 开始日期(YYYY-MM-DD格式) | `2024-01-01` |
| `end_date` | string | ❌ | 结束日期(YYYY-MM-DD格式) | `2024-12-31` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "period": {
            "start_date": "2024-01-01",
            "end_date": "2024-12-31"
        },
        "customers": {
            "total_customers": 100,
            "customers_with_debt": 30
        },
        "invoices": {
            "count": 500,
            "total_amount": "500000.00",
            "paid_amount": "350000.00",
            "average_amount": "1000.00"
        },
        "payments": {
            "count": 300,
            "total_amount": "350000.00",
            "average_amount": "1166.67"
        },
        "stores": [
            {
                "store_id": 1,
                "store_name": "总店",
                "invoice_count": 200,
                "total_amount": 200000,
                "paid_amount": 150000
            },
            {
                "store_id": 2,
                "store_name": "分店A",
                "invoice_count": 150,
                "total_amount": 150000,
                "paid_amount": 100000
            }
        ]
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/dashboard/statistics"?start_date=2024-01-01&end_date=2024-12-31
```

```javascript
fetch('http://localhost:8000/api/dashboard/statistics?start_date=2024-01-01&end_date=2024-12-31', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 用户管理 {#用户管理}

### 创建新用户 {#post_api_users}

**请求方式：** `POST`

**请求路径：** `api/users`

**需要认证：** ✅ 是

**接口描述：**

创建一个新的系统用户，并分配初始角色和门店权限。
仅系统管理员可以访问此接口。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `name` | string | ✅ | 姓名 | `张三` |
| `username` | string | ✅ | 用户名 | `zhangsan` |
| `email` | string | ✅ | 邮箱 | `zhangsan@example.com` |
| `password` | string | ✅ | 密码（至少6位） | `password123` |
| `role_ids` | string[] | ✅ | 角色ID列表 | `[2]` |
| `store_ids` | string[] | ❌ | optional 门店ID列表 | `[1]` |
| `password_confirmation` | string | ✅ | 确认密码 | `password123` |

**响应示例：**

**创建成功** (HTTP 201)：

```json
{
  "success": true,
  "data": {
    "id": 5,
    "name": "张三",
    "username": "zhangsan",
    "email": "zhangsan@example.com",
    "created_at": "2024-01-01 10:00:00",
    "roles": [...],
    "stores": [...]
  },
  "message": "用户创建成功"
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"张三","username":"zhangsan","email":"zhangsan@example.com","password":"password123","role_ids":[2],"store_ids":[1],"password_confirmation":"password123"}' \
  "http://localhost:8000/api/users"
```

```javascript
fetch('http://localhost:8000/api/users', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"name":"张三","username":"zhangsan","email":"zhangsan@example.com","password":"password123","role_ids":[2],"store_ids":[1],"password_confirmation":"password123"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取用户列表 {#get_api_users}

**请求方式：** `GET`

**请求路径：** `api/users`

**需要认证：** ✅ 是

**接口描述：**

获取系统所有用户的分页列表，支持按关键词搜索和角色筛选。
仅系统管理员可以访问此接口。

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `search` | string | ❌ | 搜索关键词，可搜索姓名、用户名、邮箱 | `admin` |
| `role` | string | ❌ | 按角色筛选，可选值：admin、store_owner、store_staff | `store_owner` |
| `per_page` | integer | ❌ | 每页显示数量，默认15 | `15` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "name": "管理员",
                "username": "admin",
                "email": "admin@example.com",
                "created_at": "2024-01-01T00:00:00.000000Z",
                "roles": [
                    {
                        "id": 1,
                        "name": "系统管理员",
                        "slug": "admin"
                    }
                ],
                "permissions": {
                    "is_admin": true,
                    "is_store_owner": false,
                    "is_store_staff": false
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
        "first_page_url": "http:\/\/localhost\/api\/users?page=1",
        "from": 1,
        "last_page": 1,
        "last_page_url": "http:\/\/localhost\/api\/users?page=1",
        "next_page_url": null,
        "path": "http:\/\/localhost\/api\/users",
        "per_page": 15,
        "prev_page_url": null,
        "to": 1,
        "total": 1
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/users"?search=admin&role=store_owner&per_page=15
```

```javascript
fetch('http://localhost:8000/api/users?search=admin&role=store_owner&per_page=15', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取用户详情 {#get_api_users__user_id_}

**请求方式：** `GET`

**请求路径：** `api/users/{user_id}`

**需要认证：** ✅ 是

**接口描述：**

获取指定用户的详细信息，包括角色和门店权限。
仅系统管理员可以访问此接口。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `user_id` | integer | ✅ | 用户ID | `1` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "管理员",
        "username": "admin",
        "email": "admin@example.com",
        "email_verified_at": "2024-01-01T00:00:00.000000Z",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z",
        "roles": [
            {
                "id": 1,
                "name": "系统管理员",
                "slug": "admin"
            }
        ],
        "permissions": {
            "is_admin": true,
            "is_store_owner": false,
            "is_store_staff": false
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

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**用户不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/users/1"
```

```javascript
fetch('http://localhost:8000/api/users/1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 更新用户详情 {#put_api_users__user_id_}

**请求方式：** `PUT`

**请求路径：** `api/users/{user_id}`

**需要认证：** ✅ 是

**接口描述：**

更新用户的基本信息、密码和角色。仅系统管理员可调用。
若不修改密码，请留空密码字段。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `user_id` | integer | ✅ | The ID of the user. | `8` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `name` | string | ✅ | validation.max. | `vctvlmmeobmjbyrw` |
| `username` | string | ❌ |  | `` |
| `email` | string | ❌ |  | `` |
| `password` | string | ❌ | validation.min. | `&}nBC+#5}}p`S)'}lY` |
| `role_ids` | string[] | ❌ | The <code>id</code> of an existing record in the roles table. | `` |
| `store_ids` | string[] | ❌ | The <code>id</code> of an existing record in the stores table. | `` |

**响应示例：**

**更新成功** (HTTP 200)：

```json
{
    "success": true,
    "message": "用户更新成功"
}
```

**请求示例：**

```bash
curl -X PUT \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"vctvlmmeobmjbyrw","username":"","email":"","password":"&}nBC+#5}}p`S)'}lY","role_ids":"","store_ids":""}' \
  "http://localhost:8000/api/users/8"
```

```javascript
fetch('http://localhost:8000/api/users/8', {
  method: 'PUT',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"name":"vctvlmmeobmjbyrw","username":"","email":"","password":"&}nBC+#5}}p`S)'}lY","role_ids":"","store_ids":""})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 更新用户角色 {#put_api_users__user_id__roles}

**请求方式：** `PUT`

**请求路径：** `api/users/{user_id}/roles`

**需要认证：** ✅ 是

**接口描述：**

更新指定用户的角色。仅系统管理员可以执行此操作。
不能删除自己的管理员角色。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `user_id` | integer | ✅ | 用户ID | `2` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `role_ids` | string[] | ✅ | 角色ID列表 | `[2,3]` |
| `role_ids.*` | integer | ✅ | 角色ID，必须是已存在的角色 | `2` |

**响应示例：**

**更新成功** (HTTP 200)：

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

**删除自己的管理员角色** (HTTP 400)：

```json
{
    "success": false,
    "message": "不能删除自己的管理员角色"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**用户不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "role_ids": [
            "角色ID列表不能为空"
        ],
        "role_ids.0": [
            "角色不存在"
        ]
    }
}
```

**请求示例：**

```bash
curl -X PUT \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"role_ids":[2,3],"role_ids.*":2}' \
  "http://localhost:8000/api/users/2/roles"
```

```javascript
fetch('http://localhost:8000/api/users/2/roles', {
  method: 'PUT',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"role_ids":[2,3],"role_ids.*":2})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 更新用户门店权限 {#put_api_users__user_id__stores}

**请求方式：** `PUT`

**请求路径：** `api/users/{user_id}/stores`

**需要认证：** ✅ 是

**接口描述：**

更新指定用户所属的门店列表。仅系统管理员可以执行此操作。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `user_id` | integer | ✅ | 用户ID | `2` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `stores` | string[] | ✅ | 门店ID列表 | `[1,2]` |
| `stores.*` | integer | ✅ | 门店ID，必须是已存在的门店 | `1` |

**响应示例：**

**更新成功** (HTTP 200)：

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
                "code": "BRANCHA"
            }
        ]
    },
    "message": "用户门店权限更新成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**用户不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "stores": [
            "门店列表不能为空"
        ],
        "stores.0": [
            "门店不存在"
        ]
    }
}
```

**请求示例：**

```bash
curl -X PUT \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"stores":[1,2],"stores.*":1}' \
  "http://localhost:8000/api/users/2/stores"
```

```javascript
fetch('http://localhost:8000/api/users/2/stores', {
  method: 'PUT',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"stores":[1,2],"stores.*":1})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取角色列表 {#get_api_roles}

**请求方式：** `GET`

**请求路径：** `api/roles`

**需要认证：** ✅ 是

**接口描述：**

获取系统所有可用的角色列表。仅系统管理员可以访问此接口。

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "系统管理员",
            "slug": "admin",
            "description": "拥有系统所有权限",
            "is_system": true
        },
        {
            "id": 2,
            "name": "店长",
            "slug": "store_owner",
            "description": "管理所属门店",
            "is_system": true
        },
        {
            "id": 3,
            "name": "店员",
            "slug": "store_staff",
            "description": "基础操作权限",
            "is_system": true
        }
    ]
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/roles"
```

```javascript
fetch('http://localhost:8000/api/roles', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 门店管理 {#门店管理}

### 获取门店用户列表 {#get_api_stores__store__users}

**请求方式：** `GET`

**请求路径：** `api/stores/{store}/users`

**需要认证：** ✅ 是

**接口描述：**

获取指定门店的所有关联用户（员工）。
允许该门店的员工或管理员访问。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `store` | string | ✅ | The store. | `cumque` |
| `id` | integer | ✅ | 门店ID | `1` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "张三",
            "username": "zhangsan"
        }
    ]
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/stores/cumque/users"
```

```javascript
fetch('http://localhost:8000/api/stores/cumque/users', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取门店支付二维码数据 {#get_api_stores__store__payment_codes}

**请求方式：** `GET`

**请求路径：** `api/stores/{store}/payment-codes`

**需要认证：** ✅ 是

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `store` | string | ✅ | The store. | `quas` |
| `id` | integer | ✅ | 门店ID | `1` |

**响应示例：**

**HTTP 200**：

```json
{
    "success": true,
    "data": {
        "wechat_pay_code_data": "wxp:\/\/...",
        "alipay_code_data": "https:\/\/qr.alipay.com\/..."
    }
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/stores/quas/payment-codes"
```

```javascript
fetch('http://localhost:8000/api/stores/quas/payment-codes', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取门店列表 {#get_api_stores}

**请求方式：** `GET`

**请求路径：** `api/stores`

**需要认证：** ✅ 是

**接口描述：**

获取当前用户有权限访问的所有门店。管理员可以查看所有门店，
其他用户只能查看自己所属的门店。

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "总店",
            "code": "MAIN",
            "address": "北京市朝阳区xxx路1号",
            "phone": "010-12345678",
            "description": "公司总部门店",
            "is_active": true,
            "created_at": "2024-01-01T00:00:00.000000Z",
            "updated_at": "2024-01-01T00:00:00.000000Z"
        },
        {
            "id": 2,
            "name": "分店A",
            "code": "BRANCHA",
            "address": "上海市浦东新区xxx路100号",
            "phone": "021-87654321",
            "description": "上海分店",
            "is_active": true,
            "created_at": "2024-01-01T00:00:00.000000Z",
            "updated_at": "2024-01-01T00:00:00.000000Z"
        }
    ]
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/stores"
```

```javascript
fetch('http://localhost:8000/api/stores', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 创建门店 {#post_api_stores}

**请求方式：** `POST`

**请求路径：** `api/stores`

**需要认证：** ✅ 是

**接口描述：**

创建新的门店记录。仅系统管理员可执行此操作。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `name` | string | ✅ | 门店名称，最大255字符 | `新分店` |
| `code` | string | ✅ | 门店编码，最大50字符，必须唯一，用于生成账单号等 | `NEWBRANCH` |
| `address` | string | ❌ | 门店地址，最大255字符 | `广州市天河区xxx路200号` |
| `phone` | string | ❌ | 门店电话，最大20字符 | `020-11112222` |
| `description` | string | ❌ | 门店描述 | `广州新开分店` |
| `is_active` | boolean | ❌ | 是否启用，默认true | `1` |
| `wechat_pay_code_data` | string | ❌ |  | `qui` |
| `alipay_code_data` | string | ❌ |  | `consequuntur` |

**响应示例：**

**创建成功** (HTTP 201)：

```json
{
    "success": true,
    "data": {
        "id": 3,
        "name": "新分店",
        "code": "NEWBRANCH",
        "address": "广州市天河区xxx路200号",
        "phone": "020-11112222",
        "description": "广州新开分店",
        "is_active": true,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z"
    },
    "message": "门店创建成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "code": [
            "门店编码已被使用"
        ]
    }
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"新分店","code":"NEWBRANCH","address":"广州市天河区xxx路200号","phone":"020-11112222","description":"广州新开分店","is_active":true,"wechat_pay_code_data":"qui","alipay_code_data":"consequuntur"}' \
  "http://localhost:8000/api/stores"
```

```javascript
fetch('http://localhost:8000/api/stores', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"name":"新分店","code":"NEWBRANCH","address":"广州市天河区xxx路200号","phone":"020-11112222","description":"广州新开分店","is_active":true,"wechat_pay_code_data":"qui","alipay_code_data":"consequuntur"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取门店详情 {#get_api_stores__id_}

**请求方式：** `GET`

**请求路径：** `api/stores/{id}`

**需要认证：** ✅ 是

**接口描述：**

获取指定门店的详细信息。管理员可查看任意门店，
其他用户只能查看自己所属的门店。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | integer | ✅ | 门店ID | `1` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "总店",
        "code": "MAIN",
        "address": "北京市朝阳区xxx路1号",
        "phone": "010-12345678",
        "description": "公司总部门店",
        "is_active": true,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z"
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**门店不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/stores/1"
```

```javascript
fetch('http://localhost:8000/api/stores/1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 更新门店信息 {#put_api_stores__id_}

**请求方式：** `PUT`

**请求路径：** `api/stores/{id}`

**需要认证：** ✅ 是

**接口描述：**

更新指定门店的信息。需要系统管理员或该门店的店长权限。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | integer | ✅ | 门店ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `name` | string | ❌ | 门店名称，最大255字符 | `总店（已更名）` |
| `code` | string | ❌ | 门店编码，最大50字符，必须唯一 | `MAIN` |
| `address` | string | ❌ | 门店地址，最大255字符 | `北京市朝阳区新地址路1号` |
| `phone` | string | ❌ | 门店电话，最大20字符 | `010-88888888` |
| `description` | string | ❌ | 门店描述 | `公司总部门店（已搬迁）` |
| `is_active` | boolean | ❌ | 是否启用 | `1` |
| `wechat_pay_code_data` | string | ❌ |  | `placeat` |
| `alipay_code_data` | string | ❌ |  | `quia` |

**响应示例：**

**更新成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "总店（已更名）",
        "code": "MAIN",
        "address": "北京市朝阳区新地址路1号",
        "phone": "010-88888888",
        "description": "公司总部门店（已搬迁）",
        "is_active": true,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-02T00:00:00.000000Z"
    },
    "message": "门店更新成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限或店长权限"
}
```

**门店不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "code": [
            "门店编码已被使用"
        ]
    }
}
```

**请求示例：**

```bash
curl -X PUT \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"总店（已更名）","code":"MAIN","address":"北京市朝阳区新地址路1号","phone":"010-88888888","description":"公司总部门店（已搬迁）","is_active":true,"wechat_pay_code_data":"placeat","alipay_code_data":"quia"}' \
  "http://localhost:8000/api/stores/1"
```

```javascript
fetch('http://localhost:8000/api/stores/1', {
  method: 'PUT',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"name":"总店（已更名）","code":"MAIN","address":"北京市朝阳区新地址路1号","phone":"010-88888888","description":"公司总部门店（已搬迁）","is_active":true,"wechat_pay_code_data":"placeat","alipay_code_data":"quia"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 删除门店 {#delete_api_stores__id_}

**请求方式：** `DELETE`

**请求路径：** `api/stores/{id}`

**需要认证：** ✅ 是

**接口描述：**

删除指定门店。仅系统管理员可执行此操作。
注意：删除门店前应确保没有关联的账单、还款等数据。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | integer | ✅ | 门店ID | `1` |

**响应示例：**

**删除成功** (HTTP 200)：

```json
{
    "success": true,
    "data": null,
    "message": "门店删除成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**门店不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**请求示例：**

```bash
curl -X DELETE \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/stores/1"
```

```javascript
fetch('http://localhost:8000/api/stores/1', {
  method: 'DELETE',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 客户管理 {#客户管理}

### 获取客户列表 {#get_api_customers}

**请求方式：** `GET`

**请求路径：** `api/customers`

**需要认证：** ✅ 是

**接口描述：**

获取系统中所有客户的分页列表，支持按姓名、电话、身份证号搜索。

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `search` | string | ❌ | 搜索关键词，可搜索姓名、电话、身份证号 | `张三` |
| `per_page` | integer | ❌ | 每页显示数量，默认15 | `15` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "name": "张三",
                "phone": "13800138001",
                "email": "zhangsan@example.com",
                "address": "北京市朝阳区xxx路123号",
                "id_card": "110101199001011234",
                "remarks": "VIP客户",
                "created_at": "2024-01-01T00:00:00.000000Z",
                "updated_at": "2024-01-01T00:00:00.000000Z"
            }
        ],
        "first_page_url": "http:\/\/localhost\/api\/customers?page=1",
        "from": 1,
        "last_page": 5,
        "last_page_url": "http:\/\/localhost\/api\/customers?page=5",
        "next_page_url": "http:\/\/localhost\/api\/customers?page=2",
        "path": "http:\/\/localhost\/api\/customers",
        "per_page": 15,
        "prev_page_url": null,
        "to": 15,
        "total": 75
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/customers"?search=%E5%BC%A0%E4%B8%89&per_page=15
```

```javascript
fetch('http://localhost:8000/api/customers?search=%E5%BC%A0%E4%B8%89&per_page=15', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 创建客户 {#post_api_customers}

**请求方式：** `POST`

**请求路径：** `api/customers`

**需要认证：** ✅ 是

**接口描述：**

创建新的客户记录。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `name` | string | ✅ | 客户姓名，最大255字符 | `张三` |
| `phone` | string | ❌ | 客户手机号，最大20字符 | `13800138001` |
| `email` | string | ❌ | 客户邮箱 | `zhangsan@example.com` |
| `address` | string | ❌ | 客户地址，最大255字符 | `北京市朝阳区xxx路123号` |
| `id_card` | string | ❌ | 身份证号，最大18字符 | `110101199001011234` |
| `remarks` | string | ❌ | 备注信息 | `VIP客户` |

**响应示例：**

**创建成功** (HTTP 201)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "张三",
        "phone": "13800138001",
        "email": "zhangsan@example.com",
        "address": "北京市朝阳区xxx路123号",
        "id_card": "110101199001011234",
        "remarks": "VIP客户",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z"
    },
    "message": "客户创建成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "name": [
            "姓名不能为空"
        ]
    }
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"张三","phone":"13800138001","email":"zhangsan@example.com","address":"北京市朝阳区xxx路123号","id_card":"110101199001011234","remarks":"VIP客户"}' \
  "http://localhost:8000/api/customers"
```

```javascript
fetch('http://localhost:8000/api/customers', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"name":"张三","phone":"13800138001","email":"zhangsan@example.com","address":"北京市朝阳区xxx路123号","id_card":"110101199001011234","remarks":"VIP客户"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取客户详情 {#get_api_customers__id_}

**请求方式：** `GET`

**请求路径：** `api/customers/{id}`

**需要认证：** ✅ 是

**接口描述：**

获取指定客户的详细信息，包括该客户在当前用户有权限访问的门店中的账单和还款记录。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | integer | ✅ | 客户ID | `1` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "张三",
        "phone": "13800138001",
        "email": "zhangsan@example.com",
        "address": "北京市朝阳区xxx路123号",
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
                "invoice_date": "2024-01-01"
            }
        ],
        "payments": [
            {
                "id": 1,
                "payment_number": "PAY-MAIN-20240101-XYZ99",
                "amount": "500.00",
                "payment_date": "2024-01-05",
                "payment_method": "cash"
            }
        ]
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**无门店权限** (HTTP 403)：

```json
{
    "success": false,
    "message": "您没有权限访问任何门店的数据"
}
```

**客户不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/customers/1"
```

```javascript
fetch('http://localhost:8000/api/customers/1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 更新客户信息 {#put_api_customers__id_}

**请求方式：** `PUT`

**请求路径：** `api/customers/{id}`

**需要认证：** ✅ 是

**接口描述：**

更新指定客户的信息。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | integer | ✅ | 客户ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `name` | string | ❌ | 客户姓名，最大255字符 | `李四` |
| `phone` | string | ❌ | 客户手机号，最大20字符 | `13800138002` |
| `email` | string | ❌ | 客户邮箱 | `lisi@example.com` |
| `address` | string | ❌ | 客户地址，最大255字符 | `上海市浦东新区xxx路456号` |
| `id_card` | string | ❌ | 身份证号，最大18字符 | `310101199002021234` |
| `remarks` | string | ❌ | 备注信息 | `普通客户` |

**响应示例：**

**更新成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "李四",
        "phone": "13800138002",
        "email": "lisi@example.com",
        "address": "上海市浦东新区xxx路456号",
        "id_card": "310101199002021234",
        "remarks": "普通客户",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-02T00:00:00.000000Z"
    },
    "message": "客户更新成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**客户不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "email": [
            "邮箱格式不正确"
        ]
    }
}
```

**请求示例：**

```bash
curl -X PUT \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"李四","phone":"13800138002","email":"lisi@example.com","address":"上海市浦东新区xxx路456号","id_card":"310101199002021234","remarks":"普通客户"}' \
  "http://localhost:8000/api/customers/1"
```

```javascript
fetch('http://localhost:8000/api/customers/1', {
  method: 'PUT',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"name":"李四","phone":"13800138002","email":"lisi@example.com","address":"上海市浦东新区xxx路456号","id_card":"310101199002021234","remarks":"普通客户"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 删除客户 {#delete_api_customers__id_}

**请求方式：** `DELETE`

**请求路径：** `api/customers/{id}`

**需要认证：** ✅ 是

**接口描述：**

删除指定客户。只有管理员可以执行此操作，且客户不能有关联的账单或还款记录。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | integer | ✅ | 客户ID | `1` |

**响应示例：**

**删除成功** (HTTP 200)：

```json
{
    "success": true,
    "data": null,
    "message": "客户删除成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "只有管理员可以删除客户"
}
```

**客户不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**有关联记录** (HTTP 422)：

```json
{
    "success": false,
    "message": "该客户有关联的账单或还款记录，无法删除"
}
```

**请求示例：**

```bash
curl -X DELETE \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/customers/1"
```

```javascript
fetch('http://localhost:8000/api/customers/1', {
  method: 'DELETE',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取客户欠款汇总 {#get_api_customers__customer__debt}

**请求方式：** `GET`

**请求路径：** `api/customers/{customer}/debt`

**需要认证：** ✅ 是

**接口描述：**

获取指定客户的欠款详情，包括传统欠款、实际欠款（扣除优惠减免后）、
优惠减免统计以及未付账单列表。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `customer` | integer | ✅ | 客户ID | `1` |

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `store_id` | integer | ❌ | 指定门店ID，不传则返回所有有权限门店的汇总 | `1` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "customer": {
            "id": 1,
            "name": "张三",
            "phone": "13800138001"
        },
        "traditional_debt": 5000,
        "actual_debt": 4800,
        "discount_summary": {
            "total_count": 2,
            "total_amount": 200,
            "by_type": {
                "write_off": {
                    "count": 1,
                    "amount": 100
                },
                "discount": {
                    "count": 1,
                    "amount": 100
                }
            }
        },
        "store_debt_info": {
            "total_invoices": 10,
            "unpaid_invoices": 3,
            "total_amount": 10000,
            "paid_amount": 5000,
            "discount_amount": 200,
            "traditional_debt": 5000,
            "actual_debt": 4800,
            "discount_rate": 2,
            "store_count": 2
        },
        "accessible_stores": [
            1,
            2
        ],
        "unpaid_invoices": [
            {
                "id": 1,
                "invoice_number": "MAIN-20240101-ABC12",
                "store_id": 1,
                "amount": "2000.00",
                "paid_amount": "500.00",
                "discount_amount": "100.00",
                "actual_remaining": "1400.00",
                "status": "partially_paid",
                "due_date": "2024-02-01",
                "has_discounts": true
            }
        ]
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**无门店权限** (HTTP 403)：

```json
{
    "success": false,
    "message": "您没有权限访问任何门店的数据"
}
```

**无指定门店权限** (HTTP 403)：

```json
{
    "success": false,
    "message": "您没有权限访问该门店的数据"
}
```

**客户不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/customers/1/debt"?store_id=1
```

```javascript
fetch('http://localhost:8000/api/customers/1/debt?store_id=1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 一键清账 {#post_api_customers__customer__clear_debt}

**请求方式：** `POST`

**请求路径：** `api/customers/{customer}/clear-debt`

**需要认证：** ✅ 是

**接口描述：**

为客户进行一键清账操作，自动分配还款到未付账单，差额自动创建减免记录。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `customer` | string | ✅ | The customer. | `similique` |
| `id` | integer | ✅ | 客户ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `payment_amount` | decimal | ✅ | 实际收款金额 | `9500.00` |
| `store_id` | integer | ✅ | 门店ID | `1` |
| `payment_method` | string | ❌ | 支付方式（cash/bank_transfer/alipay/wechat/other），默认cash | `bank_transfer` |
| `remarks` | string | ❌ | 备注 | `一次性清账` |
| `apply_discount` | boolean | ❌ | 差额是否作为减免，默认true | `1` |
| `expected_debt` | number | ❌ |  | `1.82` |

**响应示例：**

**清账成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "payment": {
            "id": 123,
            "amount": "9500.00",
            "payment_number": "PAY202601030001"
        },
        "allocations": [
            {
                "invoice_id": 1,
                "amount": "3000.00"
            },
            {
                "invoice_id": 2,
                "amount": "3000.00"
            },
            {
                "invoice_id": 3,
                "amount": "3500.00"
            }
        ],
        "discounts": [
            {
                "invoice_id": 3,
                "amount": "500.00"
            }
        ],
        "summary": {
            "original_debt": "10000.00",
            "payment_received": "9500.00",
            "discount_applied": "500.00",
            "invoices_cleared": 3
        }
    },
    "message": "清账成功"
}
```

**无欠款** (HTTP 422)：

```json
{
    "success": false,
    "message": "该客户在指定门店无待清账单"
}
```

**金额超出** (HTTP 422)：

```json
{
    "success": false,
    "message": "收款金额超过总欠款"
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"payment_amount":"9500.00","store_id":1,"payment_method":"bank_transfer","remarks":"一次性清账","apply_discount":true,"expected_debt":1.82}' \
  "http://localhost:8000/api/customers/similique/clear-debt"
```

```javascript
fetch('http://localhost:8000/api/customers/similique/clear-debt', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"payment_amount":"9500.00","store_id":1,"payment_method":"bank_transfer","remarks":"一次性清账","apply_discount":true,"expected_debt":1.82})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 账单管理 {#账单管理}

### 获取账单列表 {#get_api_invoices}

**请求方式：** `GET`

**请求路径：** `api/invoices`

**需要认证：** ✅ 是

**接口描述：**

获取账单分页列表，非管理员只能查看自己所属门店的账单。
支持按门店、客户、状态和日期范围筛选。

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `store_id` | integer | ❌ | 按门店ID筛选 | `1` |
| `customer_id` | integer | ❌ | 按客户ID筛选 | `1` |
| `created_by` | integer | ❌ | 按创建人/经手人ID筛选 | `1` |
| `status` | string | ❌ | 按状态筛选，可选值：unpaid(未付)、partially_paid(部分付款)、paid(已付清)、overdue(逾期) | `unpaid` |
| `start_date` | string | ❌ | 开始日期(YYYY-MM-DD格式) | `2024-01-01` |
| `end_date` | string | ❌ | 结束日期(YYYY-MM-DD格式) | `2024-12-31` |
| `per_page` | integer | ❌ | 每页显示数量，默认15 | `15` |

**响应示例：**

**获取成功** (HTTP 200)：

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
                "created_by": {
                    "id": 1,
                    "name": "管理员"
                },
                "amount": "1000.00",
                "paid_amount": "500.00",
                "status": "partially_paid",
                "invoice_date": "2024-01-01",
                "due_date": "2024-02-01",
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
                    "phone": "13800138001"
                }
            }
        ],
        "first_page_url": "http:\/\/localhost\/api\/invoices?page=1",
        "from": 1,
        "last_page": 5,
        "last_page_url": "http:\/\/localhost\/api\/invoices?page=5",
        "next_page_url": "http:\/\/localhost\/api\/invoices?page=2",
        "path": "http:\/\/localhost\/api\/invoices",
        "per_page": 15,
        "prev_page_url": null,
        "to": 15,
        "total": 75
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**无权限查看门店** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/invoices"?store_id=1&customer_id=1&created_by=1&status=unpaid&start_date=2024-01-01&end_date=2024-12-31&per_page=15
```

```javascript
fetch('http://localhost:8000/api/invoices?store_id=1&customer_id=1&created_by=1&status=unpaid&start_date=2024-01-01&end_date=2024-12-31&per_page=15', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 创建账单 {#post_api_invoices}

**请求方式：** `POST`

**请求路径：** `api/invoices`

**需要认证：** ✅ 是

**接口描述：**

创建新的账单记录。可以直接指定总金额，或提供明细项目列表。
如果提供了明细项目，系统会自动计算总金额。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `store_id` | integer | ✅ | 门店ID | `1` |
| `customer_id` | integer | ✅ | 客户ID | `1` |
| `amount` | number | ❌ | 账单总金额（如果不提供明细项目则必填），最小0.01 | `1000` |
| `due_date` | string | ❌ | 到期日期(YYYY-MM-DD格式) | `2024-02-01` |
| `description` | string | ❌ | 账单描述/备注 | `商品销售` |
| `items` | string[] | ❌ | 账单明细项目列表 | `["est"]` |
| `items[].item_name` | string | ❌ | 项目名称，最大255字符 | `商品A` |
| `items[].item_description` | string | ❌ | 项目描述 | `优质商品` |
| `items[].quantity` | number | ✅ | 数量，最小0.001 | `2` |
| `items[].unit_price` | number | ✅ | 单价，最小0.01 | `500` |
| `items[].sort_order` | integer | ❌ | 排序号，最小0 | `0` |

**响应示例：**

**创建成功** (HTTP 201)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "invoice_number": "MAIN-20240101-ABC12",
        "store_id": 1,
        "customer_id": 1,
        "created_by": 1,
        "amount": "1000.00",
        "paid_amount": "0.00",
        "status": "unpaid",
        "due_date": "2024-02-01",
        "description": "商品销售",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z",
        "items": [
            {
                "id": 1,
                "invoice_id": 1,
                "item_name": "商品A",
                "item_description": "优质商品",
                "quantity": "2.000",
                "unit_price": "500.00",
                "subtotal": "1000.00",
                "sort_order": 0
            }
        ]
    },
    "message": "账单创建成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**无权限** (HTTP 403)：

```json
{
    "success": false,
    "message": "您没有权限在此门店创建账单"
}
```

**缺少金额或明细** (HTTP 422)：

```json
{
    "success": false,
    "message": "必须提供账单金额或明细项目"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "store_id": [
            "门店不存在"
        ],
        "customer_id": [
            "客户不存在"
        ]
    }
}
```

**创建失败** (HTTP 500)：

```json
{
    "success": false,
    "message": "账单创建失败：..."
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"store_id":1,"customer_id":1,"amount":1000,"due_date":"2024-02-01","description":"商品销售","items":["est"],"items[].item_name":"商品A","items[].item_description":"优质商品","items[].quantity":2,"items[].unit_price":500,"items[].sort_order":0}' \
  "http://localhost:8000/api/invoices"
```

```javascript
fetch('http://localhost:8000/api/invoices', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"store_id":1,"customer_id":1,"amount":1000,"due_date":"2024-02-01","description":"商品销售","items":["est"],"items[].item_name":"商品A","items[].item_description":"优质商品","items[].quantity":2,"items[].unit_price":500,"items[].sort_order":0})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取账单详情 {#get_api_invoices__id_}

**请求方式：** `GET`

**请求路径：** `api/invoices/{id}`

**需要认证：** ✅ 是

**接口描述：**

获取指定账单的详细信息，包括门店、客户、创建者、明细项目、付款分配记录和附件。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | integer | ✅ | 账单ID | `1` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "invoice_number": "MAIN-20240101-ABC12",
        "store_id": 1,
        "customer_id": 1,
        "created_by": {
            "id": 1,
            "name": "管理员"
        },
        "amount": "1000.00",
        "paid_amount": "500.00",
        "status": "partially_paid",
        "invoice_date": "2024-01-01",
        "due_date": "2024-02-01",
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
            "phone": "13800138001"
        },
        "items": [
            {
                "id": 1,
                "invoice_id": 1,
                "item_name": "商品A",
                "item_description": "优质商品",
                "quantity": "2.000",
                "unit_price": "500.00",
                "subtotal": "1000.00",
                "sort_order": 0
            }
        ],
        "payment_allocations": [
            {
                "id": 1,
                "payment_id": 1,
                "invoice_id": 1,
                "amount": "500.00",
                "allocated_by": {
                    "id": 1,
                    "name": "管理员"
                },
                "created_at": "2024-01-05T00:00:00.000000Z",
                "payment": {
                    "id": 1,
                    "payment_number": "PAY-MAIN-20240105-XYZ99",
                    "amount": "500.00"
                }
            }
        ],
        "attachments": [
            {
                "id": 1,
                "filename": "abc123.jpg",
                "original_filename": "收据照片.jpg",
                "file_path": "invoices\/abc123.jpg",
                "file_size": 102400,
                "mime_type": "image\/jpeg",
                "url": "https:\/\/storage.example.com\/invoices\/abc123.jpg"
            }
        ]
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**账单不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/invoices/1"
```

```javascript
fetch('http://localhost:8000/api/invoices/1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 更新账单 {#put_api_invoices__id_}

**请求方式：** `PUT`

**请求路径：** `api/invoices/{id}`

**需要认证：** ✅ 是

**接口描述：**

更新指定账单的信息。如果账单已有付款记录，则只能更新描述字段。
需要系统管理员或该门店店长权限。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | integer | ✅ | 账单ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `customer_id` | string | ❌ | The <code>id</code> of an existing record in the customers table. | `` |
| `amount` | number | ❌ | 账单总金额（仅无付款时可修改），最小0.01 | `1500` |
| `invoice_date` | string | ❌ | 账单日期(YYYY-MM-DD格式)（仅无付款时可修改） | `2024-01-02` |
| `due_date` | string | ❌ | 到期日期(YYYY-MM-DD格式)（仅无付款时可修改） | `2024-02-15` |
| `description` | string | ❌ | 账单描述/备注 | `商品销售（已更新）` |
| `items` | string[] | ❌ | 账单明细项目列表（仅无付款时可修改） | `["explicabo"]` |
| `items[].id` | string | ❌ | The <code>id</code> of an existing record in the invoice_items table. | `` |
| `items[].item_name` | string | ❌ | 项目名称，最大255字符 | `商品B` |
| `items[].item_description` | string | ❌ | 项目描述 | `高端商品` |
| `items[].quantity` | number | ✅ | 数量，最小0.001 | `3` |
| `items[].unit_price` | number | ✅ | 单价，最小0.01 | `500` |
| `items[].sort_order` | integer | ❌ | 排序号，最小0 | `0` |

**响应示例：**

**更新成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "invoice_number": "MAIN-20240101-ABC12",
        "store_id": 1,
        "customer_id": 1,
        "amount": "1500.00",
        "paid_amount": "0.00",
        "status": "unpaid",
        "invoice_date": "2024-01-02",
        "due_date": "2024-02-15",
        "description": "商品销售（已更新）",
        "items": [
            {
                "id": 2,
                "item_name": "商品B",
                "quantity": "3.000",
                "unit_price": "500.00",
                "subtotal": "1500.00"
            }
        ]
    },
    "message": "账单更新成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限或店长权限"
}
```

**账单不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**更新失败** (HTTP 500)：

```json
{
    "success": false,
    "message": "账单更新失败：..."
}
```

**请求示例：**

```bash
curl -X PUT \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"customer_id":"","amount":1500,"invoice_date":"2024-01-02","due_date":"2024-02-15","description":"商品销售（已更新）","items":["explicabo"],"items[].id":"","items[].item_name":"商品B","items[].item_description":"高端商品","items[].quantity":3,"items[].unit_price":500,"items[].sort_order":0}' \
  "http://localhost:8000/api/invoices/1"
```

```javascript
fetch('http://localhost:8000/api/invoices/1', {
  method: 'PUT',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"customer_id":"","amount":1500,"invoice_date":"2024-01-02","due_date":"2024-02-15","description":"商品销售（已更新）","items":["explicabo"],"items[].id":"","items[].item_name":"商品B","items[].item_description":"高端商品","items[].quantity":3,"items[].unit_price":500,"items[].sort_order":0})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 删除账单 {#delete_api_invoices__id_}

**请求方式：** `DELETE`

**请求路径：** `api/invoices/{id}`

**需要认证：** ✅ 是

**接口描述：**

删除指定账单。需要系统管理员或该门店店长权限。
如果账单已有付款记录则无法删除。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | integer | ✅ | 账单ID | `1` |

**响应示例：**

**删除成功** (HTTP 200)：

```json
{
    "success": true,
    "data": null,
    "message": "账单删除成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限或店长权限"
}
```

**账单不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**已有付款记录** (HTTP 422)：

```json
{
    "success": false,
    "message": "该账单已有付款记录，无法删除"
}
```

**请求示例：**

```bash
curl -X DELETE \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/invoices/1"
```

```javascript
fetch('http://localhost:8000/api/invoices/1', {
  method: 'DELETE',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 账单明细 {#账单明细}

### 获取账单明细列表 {#get_api_invoices__invoice__items}

**请求方式：** `GET`

**请求路径：** `api/invoices/{invoice}/items`

**需要认证：** ✅ 是

**接口描述：**

获取指定账单的所有明细项目，按排序号排列。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `invoice` | integer | ✅ | 账单ID | `1` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "invoice_id": 1,
            "item_name": "商品A",
            "item_description": "优质商品",
            "quantity": "2.000",
            "unit_price": "500.00",
            "subtotal": "1000.00",
            "sort_order": 0,
            "created_at": "2024-01-01T00:00:00.000000Z",
            "updated_at": "2024-01-01T00:00:00.000000Z"
        },
        {
            "id": 2,
            "invoice_id": 1,
            "item_name": "商品B",
            "item_description": "普通商品",
            "quantity": "5.000",
            "unit_price": "100.00",
            "subtotal": "500.00",
            "sort_order": 1,
            "created_at": "2024-01-01T00:00:00.000000Z",
            "updated_at": "2024-01-01T00:00:00.000000Z"
        }
    ]
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**账单不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/invoices/1/items"
```

```javascript
fetch('http://localhost:8000/api/invoices/1/items', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 添加账单明细项 {#post_api_invoices__invoice__items}

**请求方式：** `POST`

**请求路径：** `api/invoices/{invoice}/items`

**需要认证：** ✅ 是

**接口描述：**

为指定账单添加新的明细项目。需要系统管理员或该门店店长权限。
如果账单已有付款记录则无法添加明细。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `invoice` | integer | ✅ | 账单ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `item_name` | string | ❌ | 项目名称，最大255字符 | `商品C` |
| `item_description` | string | ❌ | 项目描述 | `新增商品` |
| `quantity` | number | ✅ | 数量，最小0.001 | `3` |
| `unit_price` | number | ✅ | 单价，最小0.01 | `200` |
| `sort_order` | integer | ❌ | 排序号，最小0，不填则自动排到最后 | `2` |

**响应示例：**

**添加成功** (HTTP 201)：

```json
{
    "success": true,
    "data": {
        "id": 3,
        "invoice_id": 1,
        "item_name": "商品C",
        "item_description": "新增商品",
        "quantity": "3.000",
        "unit_price": "200.00",
        "subtotal": "600.00",
        "sort_order": 2,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z"
    },
    "message": "明细项添加成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限或店长权限"
}
```

**账单不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**账单已有付款** (HTTP 422)：

```json
{
    "success": false,
    "message": "该账单已有付款记录，无法修改明细"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "quantity": [
            "数量不能为空"
        ],
        "unit_price": [
            "单价不能为空"
        ]
    }
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"item_name":"商品C","item_description":"新增商品","quantity":3,"unit_price":200,"sort_order":2}' \
  "http://localhost:8000/api/invoices/1/items"
```

```javascript
fetch('http://localhost:8000/api/invoices/1/items', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"item_name":"商品C","item_description":"新增商品","quantity":3,"unit_price":200,"sort_order":2})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 更新账单明细项 {#put_api_invoice_items__item_}

**请求方式：** `PUT`

**请求路径：** `api/invoice-items/{item}`

**需要认证：** ✅ 是

**接口描述：**

更新指定的明细项目。需要系统管理员或该门店店长权限。
如果账单已有付款记录则无法修改明细。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `item` | integer | ✅ | 明细项ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `item_name` | string | ❌ | 项目名称，最大255字符 | `商品A（已更新）` |
| `item_description` | string | ❌ | 项目描述 | `优质商品（更新描述）` |
| `quantity` | number | ❌ | 数量，最小0.001 | `5` |
| `unit_price` | number | ❌ | 单价，最小0.01 | `450` |
| `sort_order` | integer | ❌ | 排序号，最小0 | `0` |

**响应示例：**

**更新成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "invoice_id": 1,
        "item_name": "商品A（已更新）",
        "item_description": "优质商品（更新描述）",
        "quantity": "5.000",
        "unit_price": "450.00",
        "subtotal": "2250.00",
        "sort_order": 0,
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-02T00:00:00.000000Z"
    },
    "message": "明细项更新成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限或店长权限"
}
```

**明细项不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**账单已有付款** (HTTP 422)：

```json
{
    "success": false,
    "message": "该账单已有付款记录，无法修改明细"
}
```

**请求示例：**

```bash
curl -X PUT \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"item_name":"商品A（已更新）","item_description":"优质商品（更新描述）","quantity":5,"unit_price":450,"sort_order":0}' \
  "http://localhost:8000/api/invoice-items/1"
```

```javascript
fetch('http://localhost:8000/api/invoice-items/1', {
  method: 'PUT',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"item_name":"商品A（已更新）","item_description":"优质商品（更新描述）","quantity":5,"unit_price":450,"sort_order":0})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 删除账单明细项 {#delete_api_invoice_items__item_}

**请求方式：** `DELETE`

**请求路径：** `api/invoice-items/{item}`

**需要认证：** ✅ 是

**接口描述：**

删除指定的明细项目。需要系统管理员或该门店店长权限。
如果账单已有付款记录则无法删除明细。账单至少需要保留一个明细项。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `item` | integer | ✅ | 明细项ID | `2` |

**响应示例：**

**删除成功** (HTTP 200)：

```json
{
    "success": true,
    "data": null,
    "message": "明细项删除成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限或店长权限"
}
```

**明细项不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**账单已有付款** (HTTP 422)：

```json
{
    "success": false,
    "message": "该账单已有付款记录，无法删除明细"
}
```

**最后一个明细项** (HTTP 422)：

```json
{
    "success": false,
    "message": "账单至少需要保留一个明细项"
}
```

**请求示例：**

```bash
curl -X DELETE \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/invoice-items/2"
```

```javascript
fetch('http://localhost:8000/api/invoice-items/2', {
  method: 'DELETE',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 附件管理 {#附件管理}

### 生成预签名上传URL {#post_api_attachments_presigned_url}

**请求方式：** `POST`

**请求路径：** `api/attachments/presigned-url`

**需要认证：** ✅ 是

**接口描述：**

生成S3兼容存储的预签名上传URL，前端可直接使用此URL上传文件。
上传完成后需调用确认接口创建附件记录。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `attachable_type` | string | ✅ | 关联类型，可选值：invoice、payment | `invoice` |
| `attachable_id` | integer | ✅ | 关联实体ID | `1` |
| `filename` | string | ✅ | 原始文件名，最大255字符 | `发票照片.jpg` |
| `file_size` | integer | ✅ | 文件大小(字节)，最大10485760(10MB) | `102400` |
| `mime_type` | string | ✅ | 文件MIME类型，支持：image/jpeg、image/png、image/gif、image/webp、application/pdf、application/msword、application/vnd.openxmlformats-officedocument.wordprocessingml.document、application/vnd.ms-excel、application/vnd.openxmlformats-officedocument.spreadsheetml.sheet、text/plain | `image/jpeg` |

**响应示例：**

**生成成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "upload_url": "https:\/\/s3.example.com\/bucket\/path\/file.jpg?X-Amz-Algorithm=...",
        "file_path": "attachments\/invoices\/2024\/01\/1\/1704067200_abc12345_发票照片.jpg",
        "original_mime_type": "image\/jpeg",
        "expires_in": 1200,
        "upload_instructions": {
            "method": "PUT",
            "content_type": null,
            "note": "重要：请设置Content-Type为null，让浏览器自动处理"
        }
    },
    "message": "预签名URL生成成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**关联实体不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "关联实体不存在"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "mime_type": [
            "不支持的文件类型"
        ]
    }
}
```

**生成失败** (HTTP 500)：

```json
{
    "success": false,
    "message": "预签名URL生成失败：..."
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"attachable_type":"invoice","attachable_id":1,"filename":"发票照片.jpg","file_size":102400,"mime_type":"image\/jpeg"}' \
  "http://localhost:8000/api/attachments/presigned-url"
```

```javascript
fetch('http://localhost:8000/api/attachments/presigned-url', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"attachable_type":"invoice","attachable_id":1,"filename":"发票照片.jpg","file_size":102400,"mime_type":"image\/jpeg"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 确认文件上传完成 {#post_api_attachments}

**请求方式：** `POST`

**请求路径：** `api/attachments`

**需要认证：** ✅ 是

**接口描述：**

确认文件已上传到对象存储，创建附件记录。
需在调用预签名URL上传文件成功后调用此接口。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `attachable_type` | string | ✅ | 关联类型，可选值：invoice、payment | `invoice` |
| `attachable_id` | integer | ✅ | 关联实体ID | `1` |
| `file_path` | string | ✅ | 文件路径（从generatePresignedUrl返回） | `attachments/invoices/2024/01/1/1704067200_abc12345_发票照片.jpg` |
| `original_filename` | string | ✅ | 原始文件名，最大255字符 | `发票照片.jpg` |
| `file_size` | integer | ✅ | 文件大小(字节)，最大10485760(10MB) | `102400` |
| `mime_type` | string | ✅ | 文件MIME类型 | `image/jpeg` |

**响应示例：**

**创建成功** (HTTP 201)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "attachable_type": "App\\Models\\Invoice",
        "attachable_id": 1,
        "original_filename": "发票照片.jpg",
        "stored_filename": "1704067200_abc12345_发票照片.jpg",
        "file_path": "attachments\/invoices\/2024\/01\/1\/1704067200_abc12345_发票照片.jpg",
        "file_size": 102400,
        "mime_type": "image\/jpeg",
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

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**关联实体不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "关联实体不存在"
}
```

**文件验证失败** (HTTP 422)：

```json
{
    "success": false,
    "message": "文件上传验证失败：文件不存在于对象存储中"
}
```

**保存失败** (HTTP 500)：

```json
{
    "success": false,
    "message": "附件记录保存失败：..."
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"attachable_type":"invoice","attachable_id":1,"file_path":"attachments\/invoices\/2024\/01\/1\/1704067200_abc12345_发票照片.jpg","original_filename":"发票照片.jpg","file_size":102400,"mime_type":"image\/jpeg"}' \
  "http://localhost:8000/api/attachments"
```

```javascript
fetch('http://localhost:8000/api/attachments', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"attachable_type":"invoice","attachable_id":1,"file_path":"attachments\/invoices\/2024\/01\/1\/1704067200_abc12345_发票照片.jpg","original_filename":"发票照片.jpg","file_size":102400,"mime_type":"image\/jpeg"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取附件列表 {#get_api_attachments}

**请求方式：** `GET`

**请求路径：** `api/attachments`

**需要认证：** ✅ 是

**接口描述：**

获取指定实体（账单或还款）的附件列表。

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `attachable_type` | string | ✅ | 关联类型，可选值：invoice、payment | `invoice` |
| `attachable_id` | integer | ✅ | 关联实体ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `attachable_type` | string | ✅ |  | `payment` |
| `attachable_id` | integer | ✅ |  | `16` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "attachable_type": "App\\Models\\Invoice",
            "attachable_id": 1,
            "original_filename": "发票照片.jpg",
            "stored_filename": "1704067200_abc12345_发票照片.jpg",
            "file_path": "attachments\/invoices\/2024\/01\/1\/1704067200_abc12345_发票照片.jpg",
            "file_size": 102400,
            "mime_type": "image\/jpeg",
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

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**关联实体不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "关联实体不存在"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"attachable_type":"payment","attachable_id":16}' \
  "http://localhost:8000/api/attachments"?attachable_type=invoice&attachable_id=1
```

```javascript
fetch('http://localhost:8000/api/attachments?attachable_type=invoice&attachable_id=1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"attachable_type":"payment","attachable_id":16})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 删除附件 {#delete_api_attachments__attachment_}

**请求方式：** `DELETE`

**请求路径：** `api/attachments/{attachment}`

**需要认证：** ✅ 是

**接口描述：**

删除指定附件，同时从对象存储中删除文件。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `attachment` | integer | ✅ | 附件ID | `1` |

**响应示例：**

**删除成功** (HTTP 200)：

```json
{
    "success": true,
    "data": null,
    "message": "附件删除成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**附件不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**删除失败** (HTTP 500)：

```json
{
    "success": false,
    "message": "附件删除失败：..."
}
```

**请求示例：**

```bash
curl -X DELETE \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/attachments/1"
```

```javascript
fetch('http://localhost:8000/api/attachments/1', {
  method: 'DELETE',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 生成预签名上传URL {#post_api_permissions_attachments_presigned_url}

**请求方式：** `POST`

**请求路径：** `api/permissions/attachments/presigned-url`

**需要认证：** ✅ 是

**接口描述：**

生成S3兼容存储的预签名上传URL，前端可直接使用此URL上传文件。
上传完成后需调用确认接口创建附件记录。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `attachable_type` | string | ✅ | 关联类型，可选值：invoice、payment | `invoice` |
| `attachable_id` | integer | ✅ | 关联实体ID | `1` |
| `filename` | string | ✅ | 原始文件名，最大255字符 | `发票照片.jpg` |
| `file_size` | integer | ✅ | 文件大小(字节)，最大10485760(10MB) | `102400` |
| `mime_type` | string | ✅ | 文件MIME类型，支持：image/jpeg、image/png、image/gif、image/webp、application/pdf、application/msword、application/vnd.openxmlformats-officedocument.wordprocessingml.document、application/vnd.ms-excel、application/vnd.openxmlformats-officedocument.spreadsheetml.sheet、text/plain | `image/jpeg` |

**响应示例：**

**生成成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "upload_url": "https:\/\/s3.example.com\/bucket\/path\/file.jpg?X-Amz-Algorithm=...",
        "file_path": "attachments\/invoices\/2024\/01\/1\/1704067200_abc12345_发票照片.jpg",
        "original_mime_type": "image\/jpeg",
        "expires_in": 1200,
        "upload_instructions": {
            "method": "PUT",
            "content_type": null,
            "note": "重要：请设置Content-Type为null，让浏览器自动处理"
        }
    },
    "message": "预签名URL生成成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**关联实体不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "关联实体不存在"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "mime_type": [
            "不支持的文件类型"
        ]
    }
}
```

**生成失败** (HTTP 500)：

```json
{
    "success": false,
    "message": "预签名URL生成失败：..."
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"attachable_type":"invoice","attachable_id":1,"filename":"发票照片.jpg","file_size":102400,"mime_type":"image\/jpeg"}' \
  "http://localhost:8000/api/permissions/attachments/presigned-url"
```

```javascript
fetch('http://localhost:8000/api/permissions/attachments/presigned-url', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"attachable_type":"invoice","attachable_id":1,"filename":"发票照片.jpg","file_size":102400,"mime_type":"image\/jpeg"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 确认文件上传完成 {#post_api_permissions_attachments}

**请求方式：** `POST`

**请求路径：** `api/permissions/attachments`

**需要认证：** ✅ 是

**接口描述：**

确认文件已上传到对象存储，创建附件记录。
需在调用预签名URL上传文件成功后调用此接口。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `attachable_type` | string | ✅ | 关联类型，可选值：invoice、payment | `invoice` |
| `attachable_id` | integer | ✅ | 关联实体ID | `1` |
| `file_path` | string | ✅ | 文件路径（从generatePresignedUrl返回） | `attachments/invoices/2024/01/1/1704067200_abc12345_发票照片.jpg` |
| `original_filename` | string | ✅ | 原始文件名，最大255字符 | `发票照片.jpg` |
| `file_size` | integer | ✅ | 文件大小(字节)，最大10485760(10MB) | `102400` |
| `mime_type` | string | ✅ | 文件MIME类型 | `image/jpeg` |

**响应示例：**

**创建成功** (HTTP 201)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "attachable_type": "App\\Models\\Invoice",
        "attachable_id": 1,
        "original_filename": "发票照片.jpg",
        "stored_filename": "1704067200_abc12345_发票照片.jpg",
        "file_path": "attachments\/invoices\/2024\/01\/1\/1704067200_abc12345_发票照片.jpg",
        "file_size": 102400,
        "mime_type": "image\/jpeg",
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

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**关联实体不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "关联实体不存在"
}
```

**文件验证失败** (HTTP 422)：

```json
{
    "success": false,
    "message": "文件上传验证失败：文件不存在于对象存储中"
}
```

**保存失败** (HTTP 500)：

```json
{
    "success": false,
    "message": "附件记录保存失败：..."
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"attachable_type":"invoice","attachable_id":1,"file_path":"attachments\/invoices\/2024\/01\/1\/1704067200_abc12345_发票照片.jpg","original_filename":"发票照片.jpg","file_size":102400,"mime_type":"image\/jpeg"}' \
  "http://localhost:8000/api/permissions/attachments"
```

```javascript
fetch('http://localhost:8000/api/permissions/attachments', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"attachable_type":"invoice","attachable_id":1,"file_path":"attachments\/invoices\/2024\/01\/1\/1704067200_abc12345_发票照片.jpg","original_filename":"发票照片.jpg","file_size":102400,"mime_type":"image\/jpeg"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取附件列表 {#get_api_permissions_attachments}

**请求方式：** `GET`

**请求路径：** `api/permissions/attachments`

**需要认证：** ✅ 是

**接口描述：**

获取指定实体（账单或还款）的附件列表。

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `attachable_type` | string | ✅ | 关联类型，可选值：invoice、payment | `invoice` |
| `attachable_id` | integer | ✅ | 关联实体ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `attachable_type` | string | ✅ |  | `invoice` |
| `attachable_id` | integer | ✅ |  | `16` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "attachable_type": "App\\Models\\Invoice",
            "attachable_id": 1,
            "original_filename": "发票照片.jpg",
            "stored_filename": "1704067200_abc12345_发票照片.jpg",
            "file_path": "attachments\/invoices\/2024\/01\/1\/1704067200_abc12345_发票照片.jpg",
            "file_size": 102400,
            "mime_type": "image\/jpeg",
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

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**关联实体不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "关联实体不存在"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"attachable_type":"invoice","attachable_id":16}' \
  "http://localhost:8000/api/permissions/attachments"?attachable_type=invoice&attachable_id=1
```

```javascript
fetch('http://localhost:8000/api/permissions/attachments?attachable_type=invoice&attachable_id=1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"attachable_type":"invoice","attachable_id":16})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 删除附件 {#delete_api_permissions_attachments__id_}

**请求方式：** `DELETE`

**请求路径：** `api/permissions/attachments/{id}`

**需要认证：** ✅ 是

**接口描述：**

删除指定附件，同时从对象存储中删除文件。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | string | ✅ | The ID of the attachment. | `corporis` |
| `attachment` | integer | ✅ | 附件ID | `1` |

**响应示例：**

**删除成功** (HTTP 200)：

```json
{
    "success": true,
    "data": null,
    "message": "附件删除成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**附件不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**删除失败** (HTTP 500)：

```json
{
    "success": false,
    "message": "附件删除失败：..."
}
```

**请求示例：**

```bash
curl -X DELETE \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/permissions/attachments/corporis"
```

```javascript
fetch('http://localhost:8000/api/permissions/attachments/corporis', {
  method: 'DELETE',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 配置管理 {#配置管理}

### 获取附件配置 {#get_api_config_attachment}

**请求方式：** `GET`

**请求路径：** `api/config/attachment`

**需要认证：** ✅ 是

**接口描述：**

获取S3存储和附件上传相关配置信息。仅系统管理员可访问。

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "disk": "s3-compat",
        "max_file_size": 10485760,
        "presigned_url_expires": 20,
        "allowed_mime_types": [
            "image\/jpeg",
            "image\/png",
            "image\/gif",
            "image\/webp",
            "application\/pdf",
            "application\/msword",
            "application\/vnd.openxmlformats-officedocument.wordprocessingml.document",
            "application\/vnd.ms-excel",
            "application\/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            "text\/plain"
        ],
        "s3_config": {
            "region": "auto",
            "bucket": "my-bucket",
            "endpoint": "https:\/\/s3.example.com",
            "access_key": "已配置",
            "secret_key": "已配置"
        }
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/config/attachment"
```

```javascript
fetch('http://localhost:8000/api/config/attachment', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 更新S3存储配置 {#put_api_config_s3}

**请求方式：** `PUT`

**请求路径：** `api/config/s3`

**需要认证：** ✅ 是

**接口描述：**

更新S3兼容存储的配置信息。仅系统管理员可执行。
更新后会自动测试连接，测试失败则不会保存配置。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `access_key` | string | ✅ | S3访问密钥 | `AKIAIOSFODNN7EXAMPLE` |
| `secret_key` | string | ✅ | S3秘密密钥 | `wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY` |
| `region` | string | ✅ | 存储区域 | `auto` |
| `bucket` | string | ✅ | 存储桶名称 | `my-bucket` |
| `endpoint` | string | ✅ | 存储服务端点URL | `https://s3.example.com` |

**响应示例：**

**更新成功** (HTTP 200)：

```json
{
    "success": true,
    "data": null,
    "message": "S3存储配置更新成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限"
}
```

**连接测试失败** (HTTP 422)：

```json
{
    "success": false,
    "message": "配置测试失败：Connection refused"
}
```

**更新失败** (HTTP 500)：

```json
{
    "success": false,
    "message": "配置更新失败：..."
}
```

**请求示例：**

```bash
curl -X PUT \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"access_key":"AKIAIOSFODNN7EXAMPLE","secret_key":"wJalrXUtnFEMI\/K7MDENG\/bPxRfiCYEXAMPLEKEY","region":"auto","bucket":"my-bucket","endpoint":"https:\/\/s3.example.com"}' \
  "http://localhost:8000/api/config/s3"
```

```javascript
fetch('http://localhost:8000/api/config/s3', {
  method: 'PUT',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"access_key":"AKIAIOSFODNN7EXAMPLE","secret_key":"wJalrXUtnFEMI\/K7MDENG\/bPxRfiCYEXAMPLEKEY","region":"auto","bucket":"my-bucket","endpoint":"https:\/\/s3.example.com"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 测试S3存储连接 {#post_api_config_s3_test}

**请求方式：** `POST`

**请求路径：** `api/config/s3/test`

**需要认证：** ✅ 是

**接口描述：**

测试当前S3兼容存储配置是否可以正常连接。仅系统管理员可执行。

**响应示例：**

**连接成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "status": "connected"
    },
    "message": "S3存储连接测试成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限"
}
```

**连接失败** (HTTP 422)：

```json
{
    "success": false,
    "message": "连接测试失败：Connection refused"
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/config/s3/test"
```

```javascript
fetch('http://localhost:8000/api/config/s3/test', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 还款管理 {#还款管理}

### 获取还款列表 {#get_api_payments}

**请求方式：** `GET`

**请求路径：** `api/payments`

**需要认证：** ✅ 是

**接口描述：**

获取还款记录的分页列表，非管理员只能查看自己所属门店的还款。
支持按门店、客户、支付方式和日期范围筛选。

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `store_id` | integer | ❌ | 按门店ID筛选 | `1` |
| `customer_id` | integer | ❌ | 按客户ID筛选 | `1` |
| `payment_method` | string | ❌ | 按支付方式筛选，可选值：cash、bank_transfer、wechat、alipay、other | `cash` |
| `start_date` | string | ❌ | 开始日期(YYYY-MM-DD格式) | `2024-01-01` |
| `end_date` | string | ❌ | 结束日期(YYYY-MM-DD格式) | `2024-12-31` |
| `per_page` | integer | ❌ | 每页显示数量，默认15 | `15` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "payment_number": "PAY-MAIN-20240101-ABC12",
                "store_id": 1,
                "customer_id": 1,
                "received_by": {
                    "id": 1,
                    "name": "管理员"
                },
                "amount": "500.00",
                "allocated_amount": "500.00",
                "payment_date": "2024-01-05",
                "payment_method": "cash",
                "reference_number": null,
                "remarks": "现金还款",
                "created_at": "2024-01-05T00:00:00.000000Z",
                "updated_at": "2024-01-05T00:00:00.000000Z",
                "store": {
                    "id": 1,
                    "name": "总店"
                },
                "customer": {
                    "id": 1,
                    "name": "张三",
                    "phone": "13800138001"
                },
                "discounts": []
            }
        ],
        "first_page_url": "http:\/\/localhost\/api\/payments?page=1",
        "from": 1,
        "last_page": 5,
        "per_page": 15,
        "total": 75
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**无权限** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/payments"?store_id=1&customer_id=1&payment_method=cash&start_date=2024-01-01&end_date=2024-12-31&per_page=15
```

```javascript
fetch('http://localhost:8000/api/payments?store_id=1&customer_id=1&payment_method=cash&start_date=2024-01-01&end_date=2024-12-31&per_page=15', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 创建还款记录 {#post_api_payments}

**请求方式：** `POST`

**请求路径：** `api/payments`

**需要认证：** ✅ 是

**接口描述：**

创建新的还款记录。可以同时指定分配到哪些账单，也可以直接处理优惠减免。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `store_id` | integer | ✅ | 门店ID | `1` |
| `customer_id` | integer | ✅ | 客户ID | `1` |
| `amount` | number | ✅ | 还款金额，最小0.01 | `500` |
| `payment_method` | string | ✅ | 支付方式，可选值：cash、bank_transfer、wechat、alipay、other | `cash` |
| `reference_number` | string | ❌ | 交易参考号/流水号，最大255字符 | `TXN20240105001` |
| `remarks` | string | ❌ | 备注 | `现金还款` |
| `allocations` | string[] | ❌ | 还款分配列表（手动分配时使用） | `["id"]` |
| `apply_discount` | boolean | ❌ | 是否应用优惠减免 | `` |
| `discount_data` | string[] | ❌ | 优惠减免数据（当apply_discount为true时使用） | `["vel"]` |
| `allocations[].invoice_id` | integer | ✅ | 账单ID | `1` |
| `allocations[].amount` | number | ✅ | 分配金额，最小0.01 | `500` |
| `discount_data[].invoice_id` | integer | ✅ | 账单ID | `1` |
| `discount_data[].amount` | number | ✅ | 减免金额 | `50` |
| `discount_data[].type` | string | ❌ | 减免类型：write_off(抹零)、discount(折扣)、promotion(促销) | `write_off` |
| `discount_data[].reason` | string | ❌ | 减免原因，最大500字符 | `尾数抹零` |

**响应示例：**

**创建成功** (HTTP 201)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "payment_number": "PAY-MAIN-20240105-ABC12",
        "store_id": 1,
        "customer_id": 1,
        "received_by": 1,
        "amount": "500.00",
        "allocated_amount": "500.00",
        "payment_method": "cash",
        "reference_number": "TXN20240105001",
        "remarks": "现金还款",
        "created_at": "2024-01-05T00:00:00.000000Z",
        "updated_at": "2024-01-05T00:00:00.000000Z",
        "allocations": [
            {
                "id": 1,
                "payment_id": 1,
                "invoice_id": 1,
                "amount": "500.00",
                "invoice": {
                    "id": 1,
                    "invoice_number": "MAIN-20240101-ABC12"
                }
            }
        ],
        "customer": {
            "id": 1,
            "name": "张三"
        },
        "store": {
            "id": 1,
            "name": "总店"
        }
    },
    "message": "还款记录创建成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**无权限** (HTTP 403)：

```json
{
    "success": false,
    "message": "您没有权限在此门店创建还款记录"
}
```

**客户无欠款** (HTTP 422)：

```json
{
    "success": false,
    "message": "该客户没有未付清的账单"
}
```

**分配金额超限** (HTTP 422)：

```json
{
    "success": false,
    "message": "分配总金额超过了还款金额"
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"store_id":1,"customer_id":1,"amount":500,"payment_method":"cash","reference_number":"TXN20240105001","remarks":"现金还款","allocations":["id"],"apply_discount":false,"discount_data":["vel"],"allocations[].invoice_id":1,"allocations[].amount":500,"discount_data[].invoice_id":1,"discount_data[].amount":50,"discount_data[].type":"write_off","discount_data[].reason":"尾数抹零"}' \
  "http://localhost:8000/api/payments"
```

```javascript
fetch('http://localhost:8000/api/payments', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"store_id":1,"customer_id":1,"amount":500,"payment_method":"cash","reference_number":"TXN20240105001","remarks":"现金还款","allocations":["id"],"apply_discount":false,"discount_data":["vel"],"allocations[].invoice_id":1,"allocations[].amount":500,"discount_data[].invoice_id":1,"discount_data[].amount":50,"discount_data[].type":"write_off","discount_data[].reason":"尾数抹零"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取还款详情 {#get_api_payments__id_}

**请求方式：** `GET`

**请求路径：** `api/payments/{id}`

**需要认证：** ✅ 是

**接口描述：**

获取指定还款的详细信息，包括门店、客户、收款人、分配记录和优惠减免记录。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | integer | ✅ | 还款ID | `1` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "payment_number": "PAY-MAIN-20240105-ABC12",
        "store_id": 1,
        "customer_id": 1,
        "received_by": {
            "id": 1,
            "name": "管理员"
        },
        "amount": "500.00",
        "allocated_amount": "500.00",
        "payment_date": "2024-01-05",
        "payment_method": "cash",
        "reference_number": "TXN20240105001",
        "remarks": "现金还款",
        "store": {
            "id": 1,
            "name": "总店"
        },
        "customer": {
            "id": 1,
            "name": "张三",
            "phone": "13800138001"
        },
        "allocations": [
            {
                "id": 1,
                "payment_id": 1,
                "invoice_id": 1,
                "amount": "500.00",
                "invoice": {
                    "id": 1,
                    "invoice_number": "MAIN-20240101-ABC12",
                    "amount": "1000.00"
                },
                "allocated_by": {
                    "id": 1,
                    "name": "管理员"
                }
            }
        ],
        "discounts": []
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**还款不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/payments/1"
```

```javascript
fetch('http://localhost:8000/api/payments/1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 删除还款记录 {#delete_api_payments__id_}

**请求方式：** `DELETE`

**请求路径：** `api/payments/{id}`

**需要认证：** ✅ 是

**接口描述：**

删除指定还款记录。需要管理员或店长权限。删除时会自动撤销该还款的所有分配记录。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | integer | ✅ | 还款ID | `1` |

**响应示例：**

**删除成功** (HTTP 200)：

```json
{
    "success": true,
    "data": null,
    "message": "还款记录删除成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限或店长权限"
}
```

**还款不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**请求示例：**

```bash
curl -X DELETE \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/payments/1"
```

```javascript
fetch('http://localhost:8000/api/payments/1', {
  method: 'DELETE',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 分配还款到账单 {#post_api_payments__payment__allocate}

**请求方式：** `POST`

**请求路径：** `api/payments/{payment}/allocate`

**需要认证：** ✅ 是

**接口描述：**

将还款金额手动分配到指定账单。账单必须与还款属于同一客户和门店。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `payment` | integer | ✅ | 还款ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `invoice_id` | integer | ✅ | 账单ID | `1` |
| `amount` | number | ✅ | 分配金额，最小0.01 | `500` |

**响应示例：**

**分配成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "id": 1,
        "payment_id": 1,
        "invoice_id": 1,
        "amount": "500.00",
        "allocated_by": 1,
        "created_at": "2024-01-05T00:00:00.000000Z"
    },
    "message": "还款分配成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**还款不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**客户/门店不匹配** (HTTP 422)：

```json
{
    "success": false,
    "message": "账单与还款的客户或门店不匹配"
}
```

**超过账单剩余金额** (HTTP 422)：

```json
{
    "success": false,
    "message": "分配金额超过了账单剩余未付金额"
}
```

**超过还款剩余金额** (HTTP 422)：

```json
{
    "success": false,
    "message": "分配金额超过了还款剩余未分配金额"
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"invoice_id":1,"amount":500}' \
  "http://localhost:8000/api/payments/1/allocate"
```

```javascript
fetch('http://localhost:8000/api/payments/1/allocate', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"invoice_id":1,"amount":500})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 批量分配还款到多个账单 {#post_api_payments__payment__batch_allocate}

**请求方式：** `POST`

**请求路径：** `api/payments/{payment}/batch-allocate`

**需要认证：** ✅ 是

**接口描述：**

将还款金额一次性分配到多个账单。所有账单必须与还款属于同一客户和门店。
操作在事务中执行，确保原子性（全部成功或全部失败）。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `payment` | integer | ✅ | 还款ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `allocations` | string[] | ✅ | 分配列表 | `["quaerat"]` |
| `allocations[].invoice_id` | integer | ✅ | 账单ID | `1` |
| `allocations[].amount` | number | ✅ | 分配金额，最小0.01 | `500` |

**响应示例：**

**分配成功** (HTTP 200)：

```json
{
  "success": true,
  "data": {...payment object...},
  "message": "成功分配 3 笔账单"
}
```

**分配总额超过剩余金额** (HTTP 422)：

```json
{
    "success": false,
    "message": "分配总金额 (1500.00) 超过了还款剩余未分配金额 (1000.00)"
}
```

**账单不匹配** (HTTP 422)：

```json
{
    "success": false,
    "message": "账单 INV-001 与还款的客户或门店不匹配"
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"allocations":["quaerat"],"allocations[].invoice_id":1,"allocations[].amount":500}' \
  "http://localhost:8000/api/payments/1/batch-allocate"
```

```javascript
fetch('http://localhost:8000/api/payments/1/batch-allocate', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"allocations":["quaerat"],"allocations[].invoice_id":1,"allocations[].amount":500})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取自动分配建议 {#get_api_payments__payment__allocation_suggestion}

**请求方式：** `GET`

**请求路径：** `api/payments/{payment}/allocation-suggestion`

**需要认证：** ✅ 是

**接口描述：**

根据指定策略获取还款的自动分配建议，可包含优惠减免建议。
需要管理员或店长权限。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `payment` | integer | ✅ | 还款ID | `1` |

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `strategy` | string | ❌ | 分配策略，可选值：oldest_first(最早优先)、due_date_first(到期日优先)、smallest_first(最小金额优先)、largest_first(最大金额优先)、overdue_first(逾期优先)，默认oldest_first | `oldest_first` |
| `include_discount` | boolean | ❌ | 是否包含优惠减免建议，默认true | `1` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "payment": {
            "id": 1,
            "payment_number": "PAY-MAIN-20240105-ABC12",
            "amount": "500.00",
            "customer": {
                "id": 1,
                "name": "张三"
            },
            "store": {
                "id": 1,
                "name": "总店"
            }
        },
        "suggestion": {
            "allocations": [
                {
                    "invoice_id": 1,
                    "invoice_number": "MAIN-20240101-ABC12",
                    "suggested_amount": 500,
                    "remaining_after_allocation": 500
                }
            ],
            "total_allocation": 500,
            "remaining_unallocated": 0
        },
        "excess_info": {
            "is_excess": false,
            "excess_amount": 0
        },
        "available_strategies": [
            {
                "value": "oldest_first",
                "description": "按账单日期从早到晚分配"
            },
            {
                "value": "due_date_first",
                "description": "按到期日期从早到晚分配"
            },
            {
                "value": "smallest_first",
                "description": "从最小金额账单开始分配"
            },
            {
                "value": "largest_first",
                "description": "从最大金额账单开始分配"
            },
            {
                "value": "overdue_first",
                "description": "优先分配逾期账单"
            }
        ]
    },
    "message": "分配建议获取成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限或店长权限"
}
```

**还款不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/payments/1/allocation-suggestion"?strategy=oldest_first&include_discount=1
```

```javascript
fetch('http://localhost:8000/api/payments/1/allocation-suggestion?strategy=oldest_first&include_discount=1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 执行自动分配 {#post_api_payments__payment__auto_allocate}

**请求方式：** `POST`

**请求路径：** `api/payments/{payment}/auto-allocate`

**需要认证：** ✅ 是

**接口描述：**

根据指定策略自动将还款分配到客户的未付账单。
需要管理员或店长权限。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `payment` | integer | ✅ | 还款ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `strategy` | string | ❌ | 分配策略，可选值：oldest_first、due_date_first、smallest_first、largest_first、overdue_first，默认oldest_first | `oldest_first` |
| `confirm_excess` | boolean | ❌ | 确认超额还款（当检测到超额时需要设为true才能继续） | `` |
| `include_discount` | boolean | ❌ | 是否包含优惠减免处理，默认true | `1` |

**响应示例：**

**分配成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "payment": {
            "id": 1,
            "payment_number": "PAY-MAIN-20240105-ABC12",
            "amount": "500.00",
            "allocated_amount": "500.00",
            "allocations": [],
            "discounts": []
        },
        "allocations": [
            {
                "invoice_id": 1,
                "amount": 500
            }
        ],
        "discounts": [],
        "strategy_used": "oldest_first",
        "excess_info": {
            "is_excess": false
        },
        "message": "自动分配完成"
    },
    "message": "自动分配完成"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限或店长权限"
}
```

**还款不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**超额未确认** (HTTP 422)：

```json
{
    "success": false,
    "message": "检测到超额还款，请确认是否继续",
    "data": {
        "excess_info": {
            "is_excess": true,
            "excess_amount": 100
        },
        "requires_confirmation": true
    }
}
```

**无可分配账单** (HTTP 422)：

```json
{
    "success": false,
    "message": "没有找到可分配的账单"
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"strategy":"oldest_first","confirm_excess":false,"include_discount":true}' \
  "http://localhost:8000/api/payments/1/auto-allocate"
```

```javascript
fetch('http://localhost:8000/api/payments/1/auto-allocate', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"strategy":"oldest_first","confirm_excess":false,"include_discount":true})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 批量自动分配 {#post_api_payments_batch_auto_allocate}

**请求方式：** `POST`

**请求路径：** `api/payments/batch-auto-allocate`

**需要认证：** ✅ 是

**接口描述：**

批量对多笔还款执行自动分配。需要管理员或店长权限。

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `payment_ids` | string[] | ✅ | 还款ID列表 | `[1,2,3]` |
| `strategy` | string | ❌ | 分配策略，默认oldest_first | `oldest_first` |
| `store_id` | integer | ❌ | 限定门店ID（可选） | `1` |
| `payment_ids.*` | integer | ✅ | 还款ID | `1` |

**响应示例：**

**批量分配成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "results": [
            {
                "payment_id": 1,
                "success": true,
                "allocations": [
                    {
                        "invoice_id": 1,
                        "amount": 500
                    }
                ],
                "message": "分配成功"
            },
            {
                "payment_id": 2,
                "success": false,
                "message": "没有可分配的账单"
            }
        ],
        "summary": {
            "total_payments": 2,
            "successful_allocations": 1,
            "failed_allocations": 1,
            "strategy_used": "oldest_first"
        }
    },
    "message": "批量自动分配完成，成功处理 1\/2 笔还款"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限或对应店长权限"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "payment_ids": [
            "还款ID列表不能为空"
        ]
    }
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"payment_ids":[1,2,3],"strategy":"oldest_first","store_id":1,"payment_ids.*":1}' \
  "http://localhost:8000/api/payments/batch-auto-allocate"
```

```javascript
fetch('http://localhost:8000/api/payments/batch-auto-allocate', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"payment_ids":[1,2,3],"strategy":"oldest_first","store_id":1,"payment_ids.*":1})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 撤销单个分配记录 {#delete_api_payments__payment__allocations__allocation_}

**请求方式：** `DELETE`

**请求路径：** `api/payments/{payment}/allocations/{allocation}`

**需要认证：** ✅ 是

**接口描述：**

撤销指定的还款分配记录，将分配金额退还给还款和账单。
需要管理员或店长权限。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `payment` | integer | ✅ | 还款ID | `1` |
| `allocation` | integer | ✅ | 分配记录ID | `1` |

**响应示例：**

**撤销成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "payment": {
            "id": 1,
            "payment_number": "PAY-MAIN-20240105-ABC12",
            "amount": "500.00",
            "allocated_amount": "0.00",
            "allocations": []
        },
        "message": "分配撤销成功"
    },
    "message": "分配撤销成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限或店长权限"
}
```

**还款或分配记录不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**撤销失败** (HTTP 422)：

```json
{
    "success": false,
    "message": "分配撤销失败：..."
}
```

**请求示例：**

```bash
curl -X DELETE \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/payments/1/allocations/1"
```

```javascript
fetch('http://localhost:8000/api/payments/1/allocations/1', {
  method: 'DELETE',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 撤销所有分配记录 {#delete_api_payments__payment__allocations}

**请求方式：** `DELETE`

**请求路径：** `api/payments/{payment}/allocations`

**需要认证：** ✅ 是

**接口描述：**

撤销指定还款的所有分配记录。需要管理员或店长权限。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `payment` | integer | ✅ | 还款ID | `1` |

**响应示例：**

**撤销成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "payment": {
            "id": 1,
            "payment_number": "PAY-MAIN-20240105-ABC12",
            "amount": "500.00",
            "allocated_amount": "0.00"
        },
        "revoked_count": 3,
        "message": "成功撤销 3 条分配记录"
    },
    "message": "成功撤销 3 条分配记录"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "需要系统管理员权限或店长权限"
}
```

**还款不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**无分配记录** (HTTP 422)：

```json
{
    "success": false,
    "message": "该还款没有分配记录"
}
```

**撤销失败** (HTTP 422)：

```json
{
    "success": false,
    "message": "分配撤销失败：..."
}
```

**请求示例：**

```bash
curl -X DELETE \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/payments/1/allocations"
```

```javascript
fetch('http://localhost:8000/api/payments/1/allocations', {
  method: 'DELETE',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 检测还款差额 {#get_api_payments__payment__detect_gap}

**请求方式：** `GET`

**请求路径：** `api/payments/{payment}/detect-gap`

**需要认证：** ✅ 是

**接口描述：**

检测还款金额与客户欠款之间的差额，用于判断是否需要优惠减免。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `payment` | integer | ✅ | 还款ID | `1` |

**响应示例：**

**检测成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "payment": {
            "id": 1,
            "payment_number": "PAY-MAIN-20240105-ABC12",
            "amount": "980.00",
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
            "total_debt": 1000,
            "payment_amount": 980,
            "gap_amount": 20,
            "gap_type": "underpayment",
            "suggested_action": "apply_discount"
        },
        "can_approve_discount": true
    },
    "message": "差额检测完成"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**还款不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/payments/1/detect-gap"
```

```javascript
fetch('http://localhost:8000/api/payments/1/detect-gap', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 应用优惠减免 {#post_api_payments__payment__apply_discount}

**请求方式：** `POST`

**请求路径：** `api/payments/{payment}/apply-discount`

**需要认证：** ✅ 是

**接口描述：**

为还款应用优惠减免，可以抹零、折扣或促销。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `payment` | integer | ✅ | 还款ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `discount_data` | string[] | ✅ | 优惠减免数据列表 | `["saepe"]` |
| `discount_data[].invoice_id` | integer | ✅ | 账单ID | `1` |
| `discount_data[].amount` | number | ✅ | 减免金额 | `20` |
| `discount_data[].type` | string | ❌ | 减免类型：write_off(抹零)、discount(折扣)、promotion(促销) | `write_off` |
| `discount_data[].reason` | string | ❌ | 减免原因 | `尾数抹零，客户整数付款` |

**响应示例：**

**处理成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "payment": {
            "id": 1,
            "payment_number": "PAY-MAIN-20240105-ABC12",
            "amount": "980.00",
            "allocated_amount": "980.00",
            "allocations": [],
            "discounts": [
                {
                    "id": 1,
                    "payment_id": 1,
                    "invoice_id": 1,
                    "discount_amount": "20.00",
                    "discount_type": "write_off",
                    "reason": "尾数抹零",
                    "invoice": {
                        "id": 1,
                        "invoice_number": "MAIN-20240101-ABC12"
                    }
                }
            ]
        },
        "result": {
            "total_discount": 20,
            "invoices_affected": 1
        }
    },
    "message": "优惠减免处理成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**无减免权限** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限验证失败：您没有权限进行优惠减免操作"
}
```

**还款不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "资源不存在"
}
```

**处理失败** (HTTP 422)：

```json
{
    "success": false,
    "message": "优惠减免处理失败：..."
}
```

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"discount_data":["saepe"],"discount_data[].invoice_id":1,"discount_data[].amount":20,"discount_data[].type":"write_off","discount_data[].reason":"尾数抹零，客户整数付款"}' \
  "http://localhost:8000/api/payments/1/apply-discount"
```

```javascript
fetch('http://localhost:8000/api/payments/1/apply-discount', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"discount_data":["saepe"],"discount_data[].invoice_id":1,"discount_data[].amount":20,"discount_data[].type":"write_off","discount_data[].reason":"尾数抹零，客户整数付款"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取优惠减免统计 {#get_api_discount_statistics}

**请求方式：** `GET`

**请求路径：** `api/discount-statistics`

**需要认证：** ✅ 是

**接口描述：**

获取优惠减免的统计数据，包括按类型分组的减免金额和次数。

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `store_id` | integer | ❌ | 门店ID，不传则返回用户所属门店的统计 | `1` |
| `start_date` | string | ❌ | 开始日期(YYYY-MM-DD格式) | `2024-01-01` |
| `end_date` | string | ❌ | 结束日期(YYYY-MM-DD格式) | `2024-12-31` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `store_id` | string | ❌ | The <code>id</code> of an existing record in the stores table. | `` |
| `start_date` | string | ❌ | validation.date. | `2026-01-25T22:03:50` |
| `end_date` | string | ❌ | validation.date validation.after_or_equal. | `2051-04-17` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "total_count": 50,
        "total_amount": 5000,
        "by_type": {
            "write_off": {
                "count": 30,
                "amount": 3000
            },
            "discount": {
                "count": 15,
                "amount": 1500
            },
            "promotion": {
                "count": 5,
                "amount": 500
            }
        },
        "by_month": [
            {
                "month": "2024-01",
                "count": 10,
                "amount": 1000
            }
        ]
    },
    "message": "优惠减免统计获取成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**无门店关联** (HTTP 403)：

```json
{
    "success": false,
    "message": "您没有关联任何门店"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"store_id":"","start_date":"2026-01-25T22:03:50","end_date":"2051-04-17"}' \
  "http://localhost:8000/api/discount-statistics"?store_id=1&start_date=2024-01-01&end_date=2024-12-31
```

```javascript
fetch('http://localhost:8000/api/discount-statistics?store_id=1&start_date=2024-01-01&end_date=2024-12-31', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"store_id":"","start_date":"2026-01-25T22:03:50","end_date":"2051-04-17"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 审计日志 {#审计日志}

### 获取审计日志列表 {#get_api_audit_logs}

**请求方式：** `GET`

**请求路径：** `api/audit-logs`

**需要认证：** ✅ 是

**接口描述：**

获取审计日志的分页列表，支持多种筛选条件。
管理员可查看所有日志，其他用户只能查看所属门店的日志。

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `user_id` | integer | ❌ | 按用户ID筛选 | `1` |
| `store_id` | integer | ❌ | 按门店ID筛选 | `1` |
| `action` | string | ❌ | 按操作类型筛选，可选值：login、logout、create、update、delete、allocate、revoke、discount | `create` |
| `auditable_type` | string | ❌ | 按模型类型筛选，可选值：invoice、payment、customer、store、user、attachment等 | `invoice` |
| `auditable_id` | integer | ❌ | 按模型ID筛选 | `1` |
| `start_date` | string | ❌ | 开始日期(YYYY-MM-DD格式) | `2024-01-01` |
| `end_date` | string | ❌ | 结束日期(YYYY-MM-DD格式) | `2024-12-31` |
| `is_success` | boolean | ❌ | 按成功/失败筛选 | `1` |
| `search` | string | ❌ | 搜索关键词，可搜索用户名、描述、IP地址等 | `张三` |
| `sort_by` | string | ❌ | 排序字段，默认created_at | `created_at` |
| `sort_order` | string | ❌ | 排序方向，可选值：asc、desc，默认desc | `desc` |
| `per_page` | integer | ❌ | 每页显示数量，最大100，默认15 | `15` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "user_id": 1,
                "store_id": 1,
                "action": "create",
                "auditable_type": "App\\Models\\Invoice",
                "auditable_id": 1,
                "auditable_label": "账单 MAIN-20240101-ABC12",
                "user_name": "管理员",
                "description": "创建账单",
                "old_values": null,
                "new_values": {
                    "amount": "1000.00",
                    "status": "unpaid"
                },
                "ip_address": "192.168.1.1",
                "user_agent": "Mozilla\/5.0...",
                "is_success": true,
                "created_at": "2024-01-01T00:00:00.000000Z",
                "user": {
                    "id": 1,
                    "name": "管理员"
                },
                "store": {
                    "id": 1,
                    "name": "总店"
                }
            }
        ],
        "first_page_url": "http:\/\/localhost\/api\/audit-logs?page=1",
        "from": 1,
        "last_page": 10,
        "per_page": 15,
        "total": 150
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**无门店权限** (HTTP 403)：

```json
{
    "success": false,
    "message": "您没有权限访问审计日志"
}
```

**无指定门店权限** (HTTP 403)：

```json
{
    "success": false,
    "message": "无权访问该门店的日志"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/audit-logs"?user_id=1&store_id=1&action=create&auditable_type=invoice&auditable_id=1&start_date=2024-01-01&end_date=2024-12-31&is_success=1&search=%E5%BC%A0%E4%B8%89&sort_by=created_at&sort_order=desc&per_page=15
```

```javascript
fetch('http://localhost:8000/api/audit-logs?user_id=1&store_id=1&action=create&auditable_type=invoice&auditable_id=1&start_date=2024-01-01&end_date=2024-12-31&is_success=1&search=%E5%BC%A0%E4%B8%89&sort_by=created_at&sort_order=desc&per_page=15', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取审计统计数据 {#get_api_audit_logs_statistics}

**请求方式：** `GET`

**请求路径：** `api/audit-logs/statistics`

**需要认证：** ✅ 是

**接口描述：**

获取审计日志的统计数据，包括按操作类型、模型类型分组的统计。

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `store_id` | integer | ❌ | 门店ID，不传则返回用户所属门店的统计 | `1` |
| `start_date` | string | ❌ | 开始日期(YYYY-MM-DD格式) | `2024-01-01` |
| `end_date` | string | ❌ | 结束日期(YYYY-MM-DD格式) | `2024-12-31` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "total_logs": 1000,
        "success_count": 980,
        "failure_count": 20,
        "by_action": {
            "create": 300,
            "update": 400,
            "delete": 50,
            "login": 200,
            "logout": 50
        },
        "by_model": {
            "App\\Models\\Invoice": 400,
            "App\\Models\\Payment": 300,
            "App\\Models\\Customer": 200,
            "App\\Models\\User": 100
        },
        "action_labels": {
            "login": "登录",
            "logout": "登出",
            "create": "创建",
            "update": "更新",
            "delete": "删除"
        },
        "model_labels": {
            "App\\Models\\Invoice": "账单",
            "App\\Models\\Payment": "还款",
            "App\\Models\\Customer": "客户"
        }
    },
    "message": "审计统计获取成功"
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "您没有权限访问审计统计"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/audit-logs/statistics"?store_id=1&start_date=2024-01-01&end_date=2024-12-31
```

```javascript
fetch('http://localhost:8000/api/audit-logs/statistics?store_id=1&start_date=2024-01-01&end_date=2024-12-31', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取筛选选项 {#get_api_audit_logs_filters}

**请求方式：** `GET`

**请求路径：** `api/audit-logs/filters`

**需要认证：** ✅ 是

**接口描述：**

获取审计日志筛选时可用的操作类型和模型类型选项。

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "actions": [
            {
                "value": "login",
                "label": "登录"
            },
            {
                "value": "logout",
                "label": "登出"
            },
            {
                "value": "create",
                "label": "创建"
            },
            {
                "value": "update",
                "label": "更新"
            },
            {
                "value": "delete",
                "label": "删除"
            },
            {
                "value": "allocate",
                "label": "分配"
            },
            {
                "value": "revoke",
                "label": "撤销"
            },
            {
                "value": "discount",
                "label": "优惠减免"
            }
        ],
        "models": [
            {
                "value": "invoice",
                "label": "账单",
                "full_class": "App\\Models\\Invoice"
            },
            {
                "value": "payment",
                "label": "还款",
                "full_class": "App\\Models\\Payment"
            },
            {
                "value": "customer",
                "label": "客户",
                "full_class": "App\\Models\\Customer"
            },
            {
                "value": "store",
                "label": "门店",
                "full_class": "App\\Models\\Store"
            },
            {
                "value": "user",
                "label": "用户",
                "full_class": "App\\Models\\User"
            }
        ]
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/audit-logs/filters"
```

```javascript
fetch('http://localhost:8000/api/audit-logs/filters', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取模型的审计历史 {#get_api_audit_logs_history}

**请求方式：** `GET`

**请求路径：** `api/audit-logs/history`

**需要认证：** ✅ 是

**接口描述：**

获取指定模型实例的完整审计历史记录。

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `auditable_type` | string | ✅ | 模型类型，可选值：invoice、payment、customer、store、user等 | `invoice` |
| `auditable_id` | integer | ✅ | 模型ID | `1` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `auditable_type` | string | ✅ |  | `aut` |
| `auditable_id` | integer | ✅ |  | `14` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "auditable_type": "invoice",
        "auditable_id": 1,
        "model_label": "账单",
        "history": [
            {
                "id": 10,
                "user_id": 1,
                "action": "update",
                "description": "更新账单金额",
                "old_values": {
                    "amount": "1000.00"
                },
                "new_values": {
                    "amount": "1500.00"
                },
                "created_at": "2024-01-02T00:00:00.000000Z",
                "user": {
                    "id": 1,
                    "name": "管理员"
                }
            },
            {
                "id": 1,
                "user_id": 1,
                "action": "create",
                "description": "创建账单",
                "old_values": null,
                "new_values": {
                    "amount": "1000.00",
                    "status": "unpaid"
                },
                "created_at": "2024-01-01T00:00:00.000000Z",
                "user": {
                    "id": 1,
                    "name": "管理员"
                }
            }
        ]
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "无权查看此记录的审计历史"
}
```

**验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "auditable_type": [
            "模型类型不能为空"
        ],
        "auditable_id": [
            "模型ID不能为空"
        ]
    }
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"auditable_type":"aut","auditable_id":14}' \
  "http://localhost:8000/api/audit-logs/history"?auditable_type=invoice&auditable_id=1
```

```javascript
fetch('http://localhost:8000/api/audit-logs/history?auditable_type=invoice&auditable_id=1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"auditable_type":"aut","auditable_id":14})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取用户操作日志 {#get_api_audit_logs_user_activity__userid?_}

**请求方式：** `GET`

**请求路径：** `api/audit-logs/user-activity/{userId?}`

**需要认证：** ✅ 是

**接口描述：**

获取指定用户的操作日志。非管理员只能查看自己的日志。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `userId` | integer | ❌ | 用户ID（管理员可指定，非管理员忽略此参数） | `1` |

**Query 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `user_id` | integer | ❌ | 用户ID（管理员用，URL参数优先） | `1` |
| `start_date` | string | ❌ | 开始日期(YYYY-MM-DD格式) | `2024-01-01` |
| `end_date` | string | ❌ | 结束日期(YYYY-MM-DD格式) | `2024-12-31` |
| `limit` | integer | ❌ | 返回记录数限制，默认50 | `50` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "user_id": 1,
        "activities": [
            {
                "id": 1,
                "action": "login",
                "description": "用户登录",
                "ip_address": "192.168.1.1",
                "is_success": true,
                "created_at": "2024-01-01T08:00:00.000000Z",
                "store": {
                    "id": 1,
                    "name": "总店"
                }
            }
        ]
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/audit-logs/user-activity/{userId?}"?user_id=1&start_date=2024-01-01&end_date=2024-12-31&limit=50
```

```javascript
fetch('http://localhost:8000/api/audit-logs/user-activity/{userId?}?user_id=1&start_date=2024-01-01&end_date=2024-12-31&limit=50', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取审计日志详情 {#get_api_audit_logs__id_}

**请求方式：** `GET`

**请求路径：** `api/audit-logs/{id}`

**需要认证：** ✅ 是

**接口描述：**

获取指定审计日志的详细信息，包括变更前后的数据对比。

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `id` | integer | ✅ | 审计日志ID | `1` |

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "log": {
            "id": 1,
            "user_id": 1,
            "store_id": 1,
            "action": "update",
            "auditable_type": "App\\Models\\Invoice",
            "auditable_id": 1,
            "auditable_label": "账单 MAIN-20240101-ABC12",
            "user_name": "管理员",
            "description": "更新账单",
            "old_values": {
                "amount": "1000.00"
            },
            "new_values": {
                "amount": "1500.00"
            },
            "ip_address": "192.168.1.1",
            "user_agent": "Mozilla\/5.0...",
            "is_success": true,
            "created_at": "2024-01-01T00:00:00.000000Z",
            "user": {
                "id": 1,
                "name": "管理员",
                "email": "admin@example.com"
            },
            "store": {
                "id": 1,
                "name": "总店",
                "code": "MAIN"
            }
        },
        "formatted_changes": [
            {
                "field": "amount",
                "old_value": "1000.00",
                "new_value": "1500.00"
            }
        ]
    }
}
```

**未认证** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "无权查看此日志"
}
```

**日志不存在** (HTTP 404)：

```json
{
    "success": false,
    "message": "审计日志不存在"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/audit-logs/1"
```

```javascript
fetch('http://localhost:8000/api/audit-logs/1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 权限管理 {#权限管理}

### 获取当前用户权限列表 {#get_api_permissions_my}

**请求方式：** `GET`

**请求路径：** `api/permissions/my`

**需要认证：** ✅ 是

**接口描述：**

获取当前登录用户的所有权限标识和角色标识。
前端使用此接口获取权限列表，用于控制页面按钮、菜单等UI元素的显示。
管理员(admin)自动拥有所有权限。

**使用场景**:
- 用户登录后自动调用，获取权限列表
- 页面刷新时重新获取权限，确保权限控制正常
- 权限更新后可手动调用刷新权限

**注意事项**:
- 前端权限检查仅用于UI控制，真正的权限验证在后端进行
- 管理员角色返回所有31个权限点
- 其他角色返回其被分配的权限点

**响应示例：**

**店员用户** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "permissions": [
            "invoices.view",
            "invoices.create",
            "payments.view",
            "payments.create",
            "customers.view"
        ],
        "roles": [
            "store_staff"
        ]
    }
}
```

**店长用户** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "permissions": [
            "invoices.view",
            "invoices.create",
            "invoices.update",
            "invoices.delete",
            "payments.view",
            "payments.create",
            "payments.allocate",
            "payments.revoke",
            "payments.discount",
            "payments.delete",
            "customers.view",
            "customers.create",
            "customers.update",
            "customers.delete",
            "dashboard.view",
            "reports.view",
            "reports.export"
        ],
        "roles": [
            "store_owner"
        ]
    }
}
```

**管理员用户** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "permissions": [
            "invoices.view",
            "invoices.create",
            "invoices.update",
            "invoices.delete",
            "payments.view",
            "payments.create",
            "payments.allocate",
            "payments.revoke",
            "payments.discount",
            "payments.delete",
            "customers.view",
            "customers.create",
            "customers.update",
            "customers.delete",
            "stores.view",
            "stores.create",
            "stores.update",
            "stores.delete",
            "users.view",
            "users.create",
            "users.update",
            "users.delete",
            "users.assign-roles",
            "users.assign-stores",
            "dashboard.view",
            "reports.view",
            "reports.export",
            "audit-logs.view",
            "roles.view",
            "roles.update",
            "settings.manage"
        ],
        "roles": [
            "admin"
        ]
    }
}
```

**未登录** (HTTP 401)：

```json
{
    "success": false,
    "message": "未认证用户",
    "error_code": "UNAUTHENTICATED"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/permissions/my"
```

```javascript
fetch('http://localhost:8000/api/permissions/my', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取所有权限（按模块分组） {#get_api_permissions}

**请求方式：** `GET`

**请求路径：** `api/permissions`

**需要认证：** ✅ 是

**接口描述：**

获取系统中所有31个权限点，按模块分组返回。
用于权限管理页面展示和角色权限分配。

**权限模块**:
- invoices: 账单管理 (4个权限)
- payments: 还款管理 (6个权限)
- customers: 客户管理 (4个权限)
- stores: 门店管理 (4个权限)
- users: 用户管理 (6个权限)
- dashboard: 仪表盘 (1个权限)
- reports: 报表管理 (2个权限)
- audit-logs: 审计日志 (1个权限)
- roles: 角色管理 (2个权限)
- settings: 系统设置 (1个权限)

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "invoices": [
            {
                "id": 1,
                "name": "查看账单",
                "slug": "invoices.view",
                "module": "invoices",
                "description": "查看账单列表和详情"
            },
            {
                "id": 2,
                "name": "创建账单",
                "slug": "invoices.create",
                "module": "invoices",
                "description": "创建新账单"
            },
            {
                "id": 3,
                "name": "编辑账单",
                "slug": "invoices.update",
                "module": "invoices",
                "description": "编辑账单信息"
            },
            {
                "id": 4,
                "name": "删除账单",
                "slug": "invoices.delete",
                "module": "invoices",
                "description": "删除账单"
            }
        ],
        "payments": [
            {
                "id": 5,
                "name": "查看还款",
                "slug": "payments.view",
                "module": "payments",
                "description": "查看还款记录"
            }
        ]
    }
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/permissions"
```

```javascript
fetch('http://localhost:8000/api/permissions', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取所有模块列表 {#get_api_permissions_modules}

**请求方式：** `GET`

**请求路径：** `api/permissions/modules`

**需要认证：** ✅ 是

**接口描述：**

获取系统中所有权限模块的唯一标识列表。
用于权限管理界面的模块分类展示。

**返回的模块**:
- invoices: 账单管理
- payments: 还款管理
- customers: 客户管理
- stores: 门店管理
- users: 用户管理
- dashboard: 仪表盘
- reports: 报表管理
- audit-logs: 审计日志
- roles: 角色管理
- settings: 系统设置

**响应示例：**

**获取成功** (HTTP 200)：

```json
{
    "success": true,
    "data": [
        "invoices",
        "payments",
        "customers",
        "stores",
        "users",
        "dashboard",
        "reports",
        "audit-logs",
        "roles",
        "settings"
    ]
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/permissions/modules"
```

```javascript
fetch('http://localhost:8000/api/permissions/modules', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取角色的权限 {#get_api_permissions_roles__role_id_}

**请求方式：** `GET`

**请求路径：** `api/permissions/roles/{role_id}`

**需要认证：** ✅ 是

**接口描述：**

获取指定角色被分配的所有权限。
用于角色权限管理页面，显示某个角色当前拥有的权限。

**系统角色**:
- admin (ID: 1): 管理员，拥有所有权限
- store_owner (ID: 2): 店长，拥有门店管理权限
- store_staff (ID: 3): 店员，拥有基础操作权限

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `role_id` | integer | ✅ | The ID of the role. | `1` |
| `role` | integer | ✅ | 角色ID | `2` |

**响应示例：**

**获取店长权限** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "role": {
            "id": 2,
            "name": "店长",
            "slug": "store_owner",
            "description": "门店负责人，管理本门店业务"
        },
        "permissions": [
            {
                "id": 1,
                "name": "查看账单",
                "slug": "invoices.view",
                "module": "invoices",
                "description": "查看账单列表和详情"
            },
            {
                "id": 2,
                "name": "创建账单",
                "slug": "invoices.create",
                "module": "invoices",
                "description": "创建新账单"
            }
        ]
    }
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**角色不存在** (HTTP 404)：

```json
{
    "message": "No query results for model [App\\Models\\Role]."
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/permissions/roles/1"
```

```javascript
fetch('http://localhost:8000/api/permissions/roles/1', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 更新角色权限 {#put_api_permissions_roles__role_id_}

**请求方式：** `PUT`

**请求路径：** `api/permissions/roles/{role_id}`

**需要认证：** ✅ 是

**接口描述：**

批量更新指定角色的权限分配。
会完全替换角色的现有权限，未在列表中的权限将被移除。

**操作说明**:
- 使用权限ID数组进行批量分配
- 支持同时分配多个权限
- 自动验证权限ID的有效性
- 更新成功后返回新的权限列表

**注意事项**:
- 管理员角色(admin)默认拥有所有权限，无需手动分配
- 分配的权限必须存在于系统中
- 此操作会完全替换原有权限，请确保包含所有需要的权限

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `role_id` | integer | ✅ | The ID of the role. | `1` |
| `role` | integer | ✅ | 角色ID | `2` |

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `permissions` | string[] | ✅ | 权限ID数组 | `[1,2,3,5,7]` |
| `permissions.*` | integer | ✅ | 权限ID，必须是系统中存在的权限 | `1` |

**响应示例：**

**更新成功** (HTTP 200)：

```json
{
    "success": true,
    "data": {
        "id": 2,
        "name": "店长",
        "slug": "store_owner",
        "description": "门店负责人，管理本门店业务",
        "permissions": [
            {
                "id": 1,
                "name": "查看账单",
                "slug": "invoices.view",
                "module": "invoices"
            },
            {
                "id": 2,
                "name": "创建账单",
                "slug": "invoices.create",
                "module": "invoices"
            },
            {
                "id": 3,
                "name": "编辑账单",
                "slug": "invoices.update",
                "module": "invoices"
            }
        ]
    },
    "message": "权限更新成功"
}
```

**权限不足** (HTTP 403)：

```json
{
    "success": false,
    "message": "权限不足"
}
```

**角色不存在** (HTTP 404)：

```json
{
    "message": "No query results for model [App\\Models\\Role]."
}
```

**参数验证失败** (HTTP 422)：

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "permissions": [
            "permissions 字段是必需的"
        ],
        "permissions.0": [
            "所选的 permissions.0 无效"
        ]
    }
}
```

**请求示例：**

```bash
curl -X PUT \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"permissions":[1,2,3,5,7],"permissions.*":1}' \
  "http://localhost:8000/api/permissions/roles/1"
```

```javascript
fetch('http://localhost:8000/api/permissions/roles/1', {
  method: 'PUT',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"permissions":[1,2,3,5,7],"permissions.*":1})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 其他接口 {#其他接口}

###  {#get_api_permissions_check_token}

**请求方式：** `GET`

**请求路径：** `api/permissions/check-token`

**需要认证：** ✅ 是

**响应示例：**

**HTTP 401**：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/permissions/check-token"
```

```javascript
fetch('http://localhost:8000/api/permissions/check-token', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 数据维护 {#数据维护}

### 获取可用的维护类型 {#get_api_maintenance_types}

**请求方式：** `GET`

**请求路径：** `api/maintenance/types`

**需要认证：** ✅ 是

**响应示例：**

**HTTP 401**：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/maintenance/types"
```

```javascript
fetch('http://localhost:8000/api/maintenance/types', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 扫描待处理数据 {#post_api_maintenance_scan}

**请求方式：** `POST`

**请求路径：** `api/maintenance/scan`

**需要认证：** ✅ 是

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `type` | string | ✅ |  | `qui` |
| `options` | object | ❌ |  | `` |
| `options.months` | integer | ❌ | validation.min validation.max. | `8` |
| `options.targets` | string[] | ❌ |  | `` |
| `options.exclude_stores` | integer[] | ❌ |  | `[5]` |
| `options.types` | object | ❌ |  | `` |
| `options.normal_days` | integer | ❌ | validation.min validation.max. | `12` |
| `options.critical_days` | integer | ❌ | validation.min validation.max. | `21` |
| `options.page` | integer | ❌ | validation.min. | `33` |
| `options.per_page` | integer | ❌ | validation.min validation.max. | `13` |

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"type":"qui","options":"","options.months":8,"options.targets":"","options.exclude_stores":[5],"options.types":"","options.normal_days":12,"options.critical_days":21,"options.page":33,"options.per_page":13}' \
  "http://localhost:8000/api/maintenance/scan"
```

```javascript
fetch('http://localhost:8000/api/maintenance/scan', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"type":"qui","options":"","options.months":8,"options.targets":"","options.exclude_stores":[5],"options.types":"","options.normal_days":12,"options.critical_days":21,"options.page":33,"options.per_page":13})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 执行清理操作 {#post_api_maintenance_execute}

**请求方式：** `POST`

**请求路径：** `api/maintenance/execute`

**需要认证：** ✅ 是

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `scan_id` | string | ✅ | validation.uuid. | `cd3ca3d6-79f3-3bb4-bbc1-0f0b1a985e3e` |
| `selected_ids` | integer[] | ❌ |  | `[12]` |
| `export_before_delete` | boolean | ❌ |  | `` |

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"scan_id":"cd3ca3d6-79f3-3bb4-bbc1-0f0b1a985e3e","selected_ids":[12],"export_before_delete":false}' \
  "http://localhost:8000/api/maintenance/execute"
```

```javascript
fetch('http://localhost:8000/api/maintenance/execute', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"scan_id":"cd3ca3d6-79f3-3bb4-bbc1-0f0b1a985e3e","selected_ids":[12],"export_before_delete":false})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 获取维护执行历史 {#get_api_maintenance_history}

**请求方式：** `GET`

**请求路径：** `api/maintenance/history`

**需要认证：** ✅ 是

**响应示例：**

**HTTP 401**：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/maintenance/history"
```

```javascript
fetch('http://localhost:8000/api/maintenance/history', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 导出扫描结果 {#post_api_maintenance_export}

**请求方式：** `POST`

**请求路径：** `api/maintenance/export`

**需要认证：** ✅ 是

**Body 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `scan_id` | string | ✅ | validation.uuid. | `27ed4372-bd9a-3d46-b597-09da5af6844d` |

**请求示例：**

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"scan_id":"27ed4372-bd9a-3d46-b597-09da5af6844d"}' \
  "http://localhost:8000/api/maintenance/export"
```

```javascript
fetch('http://localhost:8000/api/maintenance/export', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({"scan_id":"27ed4372-bd9a-3d46-b597-09da5af6844d"})
})
.then(response => response.json())
.then(data => console.log(data));
```

---

### 下载导出文件 {#get_api_maintenance_export__filename_}

**请求方式：** `GET`

**请求路径：** `api/maintenance/export/{filename}`

**需要认证：** ✅ 是

**URL 参数：**

| 参数名 | 类型 | 必填 | 描述 | 示例 |
|--------|------|------|------|------|
| `filename` | string | ✅ |  | `sed` |

**响应示例：**

**HTTP 401**：

```json
{
    "success": false,
    "message": "未认证用户，请先登录",
    "error_code": "UNAUTHENTICATED",
    "login_url": "http:\/\/localhost\/api\/login"
}
```

**请求示例：**

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "http://localhost:8000/api/maintenance/export/sed"
```

```javascript
fetch('http://localhost:8000/api/maintenance/export/sed', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

