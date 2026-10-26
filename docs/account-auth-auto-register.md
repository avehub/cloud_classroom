# 登录即注册 / 微信自动注册 API 接入说明

本次调整后，PC 前台不再提供独立注册入口。验证码登录时账号不存在会自动创建账号；微信首次登录也自动创建并绑定账号。

## 状态字段

以下字段会出现在验证码登录、密码登录响应，以及 `/api/oauth/wechat/callback` 的跳转参数中：

| 字段 | 类型 | 说明 |
|---|---|---|
| `is_new_user` | 0/1 | 本次请求是否新建了账号 |
| `need_set_password` | 0/1 | 是否还没有设置登录密码 |
| `need_bind_phone` | 0/1 | 是否没有手机号/邮箱；为 1 时设置密码前必须先绑定手机号 |

只要 `need_set_password=1`，H5 每次登录后都应提示设置密码；可以选择“稍后设置”。

## 验证码登录

`POST /api/account/verify/login`

```text
account=13900000000&verify_code=123456
```

账号不存在时自动创建，仍返回 `token`：

```json
{
  "token": "xxx",
  "is_new_user": 1,
  "need_set_password": 1,
  "need_bind_phone": 0,
  "code": 0,
  "msg": ""
}
```

再次登录同一账号时 `is_new_user` 为 0，不会重复发放注册奖励。

## 密码登录

`POST /api/account/password/login`

账号尚未设置密码时返回 HTTP 400：

```json
{"code":"account.password_not_set","msg":"该账号尚未设置登录密码，请使用验证码登录"}
```

## 首次设置密码

`POST /api/account/password/setup`

请求头：`X-Token: <登录 token>`

有手机号或邮箱时：

```text
new_password=Abc12345!&confirm_password=Abc12345!
```

`need_bind_phone=1` 时必须同时绑定手机号：

```text
phone=13900000000&verify_code=123456&new_password=Abc12345!&confirm_password=Abc12345!
```

设置成功后密码会真正可用于手机号/邮箱密码登录。接口只允许在密码为空时调用；已设置密码后再次调用返回 `account.password_already_set`。

## H5 微信登录

`GET /api/oauth/wechat/callback` 仍跳回原 `redirect` 地址，并带有：

```text
openid=xxx&token=xxx&is_new_user=1&need_set_password=1&need_bind_phone=1
```

- 未绑定过的微信用户现在也会直接返回有效 `token`，不再返回空 token。
- H5 收到 token 后直接进入已登录状态。
- 如果 `need_set_password=1`，打开密码设置界面；`need_bind_phone=1` 时先让用户输入手机号和短信验证码。
- 已绑定用户返回 `is_new_user=0`。

## 账号信息

`GET /api/uc/account` 新增：

- `has_password`
- `has_login_name`
- `need_set_password`
- `need_bind_phone`

原有字段保留，用于兼容旧版 H5。

## 微信头像同步

- 微信扫码、微信公众号登录时，会把微信头像同步到 `kg_user.avatar`。
- 微信头像 URL 会统一转为 HTTPS，避免 HTTPS 页面出现混合内容。
- 只同步默认头像或已有的微信头像；用户主动上传或后台设置的自定义头像会保留，不会被微信登录覆盖。
- `kg_user.avatar` 已扩展到 255 字符，用于保存较长的微信外链地址。

## 旧版注册接口

`POST /api/account/register` 继续保留原有行为，仅用于兼容旧版 H5。新版 H5 应下线注册按钮和注册页，统一使用验证码登录自动注册。

## H5 前端需要执行

1. 删除注册按钮、注册页入口和“注册并绑定”页面。
2. 登录页默认展示验证码登录，并提示“未注册手机号/邮箱，验证后自动创建账号”。
3. 登录成功后读取 `need_set_password`，为 1 时展示可跳过的密码设置弹窗。
4. 密码设置弹窗在 `need_bind_phone=1` 时增加手机号、短信验证码输入。
5. 移除对 `/api/account/register` 的调用。
6. 微信回调后直接使用返回的 `token`；不要因为 `is_new_user=1` 再跳注册页。
