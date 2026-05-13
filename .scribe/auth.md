# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_AUTH_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

通过 <code>POST /api/login</code> 登录获取访问令牌，然后在请求头中添加 <code>Authorization: Bearer {token}</code>
