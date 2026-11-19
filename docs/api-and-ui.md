# API 与管理面板

## UI 页面

前端使用 Vue 3、Vite 和 Vue Router Hash 模式。`Panel.vue` 统一提供导航、API 地址显示和页脚。

| Hash 路由 | 页面 |
| --- | --- |
| `/#/login` | 配置 API 前置 URL 和 token。 |
| `/#/` | 定时器列表、最近执行结果和历史日志。 |
| `/#/services` | 后台服务状态、控制台输出和进程操作。 |
| `/#/container` | 安全关闭容器。 |

未配置 API 地址或 token 时，功能路由会跳转到登录页。登录信息保存在 `localStorage`：

- `pfs_api_base_url`
- `pfs_api_token`

前端 `src/api.ts` 的 `Api.path` 保存所有后端路径，`Api.Request` 自动构造签名。

## HTTP 签名

HTTP API 请求的签名字段放在 query string 中：

| 参数 | 含义 |
| --- | --- |
| `request_id` | 唯一请求 ID，用于防重放。 |
| `time` | Unix 秒级过期时间，前端默认当前时间后 60 秒。 |
| `data` | 请求载荷 JSON 的 Base64 编码；无载荷请求编码 `{}`。 |
| `sign` | `HMAC-SHA256(request_id + time + data, PFS_API_TOKEN)` 小写十六进制值。 |

成功响应：

```json
{
  "status": "success",
  "data": {},
  "message": ""
}
```

签名失败返回 HTTP `403`、`status: "sign_check_failed"`。UI 清除本地凭据并跳转登录页。API 对前端启用 `Access-Control-Allow-Origin: *`，不使用 Cookie 凭据。

## HTTP 端点

| UI 使用方法 | 路径 | 签名数据 | 功能 |
| --- | --- | --- | --- |
| GET | `/api/timer/list` | `{}` | 获取定时器摘要、状态、最近执行时间和日志。 |
| GET | `/api/timer/history` | `{"name":"<任务名>"}` | 获取指定 TimerTask 的 `execute_logs`。 |
| GET | `/api/service/list` | `{}` | 获取全部后台服务快照。 |
| POST | `/api/service/action` | `{"name":"worker","action":"start"}` | 启动、停止或重启服务；action 为 `start`、`stop`、`restart`。 |
| POST | `/api/container/shutdown` | `{}` | 接收安全关机请求。 |

API 按 URL path 分派接口；签名载荷由前端编码在 `data` query 参数中。POST 业务数据也由该签名载荷承载。

## WebSocket 服务控制台

连接路径：

```text
ws://<host>:<port>/ws/services
wss://<host>:<port>/ws/services
```

握手使用相同签名参数，签名载荷需包含服务名：

```json
{"service":"worker"}
```

连接成功后先发送快照，再持续推送事件。

### Server 消息

| type | 字段 | 说明 |
| --- | --- | --- |
| `snapshot` | `service`, `data` | 当前服务状态与最近缓存的输出。 |
| `output` | `service`, `stream`, `data`, `time` | stdout/stderr 实时输出。 |
| `status` | `service`, `status`, `pid`, `time` | 服务状态变化。 |
| `input_ack` | `service`, `success` | 是否接受输入。 |
| `action_ack` | `service`, `action`, `success` | WebSocket 服务操作结果。 |
| `heartbeat_ack` | `service`, `client_time`, `time` | 心跳回执。 |
| `error` | `message` | 无效消息等错误。 |

### Client 消息

写入进程 stdin：

```json
{"type":"input","data":"status\n"}
```

WebSocket 服务操作：

```json
{"type":"action","action":"restart"}
```

心跳：

```json
{"type":"heartbeat","time":"2026-11-01T00:00:00.000Z"}
```

面板 WebSocket 建立后每 30 秒发送一次心跳。切换页面、切换服务或连接关闭时停止心跳定时器。

## 控制台限制

- 使用普通进程管道而非 PTY。
- 输入原样写入 stdin，UI 提交文本时会在末尾加换行符。
- 需要真实终端设备、TTY 信号或终端尺寸的程序可能无法工作。
- 内存输出按 `output_max_lines` 限制；设置 `output_log: true` 可同时追加到文件。
