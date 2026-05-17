# User Capabilities Contract

Date: 2026-05-17

## Summary

Clients should use `capabilities` as the only source for feature gating. The array contains permission slugs that the current user can exercise. UI visibility and enabled states should be derived from this array, while backend authorization remains authoritative for every write or protected read.

If `capabilities` is missing from a response, clients must treat the user as having no capabilities for that response.

## Changed Endpoints

- `POST /api/login`
- `GET /api/user`
- `GET /api/permissions/my`

## `POST /api/login`

The login response embeds capabilities under `data.user.capabilities`.

```json
{
  "success": true,
  "data": {
    "user": {
      "id": 2,
      "name": "Store Owner",
      "username": "owner",
      "email": "owner@example.com",
      "roles": ["store_owner"],
      "stores": [
        {
          "id": 1,
          "name": "Store A",
          "code": "STORE-A",
          "is_manager": true
        }
      ],
      "capabilities": [
        "invoices.view",
        "invoices.create",
        "invoices.update",
        "payments.create"
      ]
    },
    "token": "1|example-token"
  },
  "message": "登录成功"
}
```

## `GET /api/user`

The authenticated user response returns `capabilities` alongside existing user, role, and store fields.

```json
{
  "success": true,
  "data": {
    "id": 2,
    "name": "Store Owner",
    "username": "owner",
    "email": "owner@example.com",
    "email_verified_at": "2026-05-17T00:00:00.000000Z",
    "created_at": "2026-05-17T00:00:00.000000Z",
    "updated_at": "2026-05-17T00:00:00.000000Z",
    "roles": ["store_owner"],
    "stores": [
      {
        "id": 1,
        "name": "Store A",
        "code": "STORE-A",
        "is_manager": true
      }
    ],
    "capabilities": [
      "invoices.view",
      "invoices.create",
      "invoices.update",
      "payments.create"
    ]
  }
}
```

## `GET /api/permissions/my`

This endpoint keeps `permissions` for compatibility and adds `capabilities`. Both arrays are identical.

```json
{
  "success": true,
  "data": {
    "permissions": [
      "invoices.view",
      "invoices.create",
      "payments.create"
    ],
    "capabilities": [
      "invoices.view",
      "invoices.create",
      "payments.create"
    ],
    "roles": ["store_staff"]
  }
}
```

## Compatibility Rules

- `data.permissions` equals `data.capabilities` on `GET /api/permissions/my`.
- Admin users receive every permission slug in the system.
- Non-admin users receive the union of permission slugs granted through their roles.
- `stores[].is_manager` is display and compatibility data only. Do not use it for feature gating.
- Missing `capabilities` means the client should assume no capabilities.

## Client Migration Table

| Client action | Capability slug |
| --- | --- |
| Create invoice | `invoices.create` |
| Edit invoice | `invoices.update` |
| Delete invoice | `invoices.delete` |
| Create payment | `payments.create` |
| Allocate payment | `payments.allocate` |
| Revoke payment allocation | `payments.revoke` |
| Delete payment | `payments.delete` |
| View audit logs | `audit-logs.view` |
| Upload attachment | `attachments.upload` |
| Delete attachment | `attachments.delete` |

## Clear-Debt Note

Current clients should gate clear-debt entry points with `payments.create` and rely on backend validation for the final decision until a dedicated clear-debt permission exists.
