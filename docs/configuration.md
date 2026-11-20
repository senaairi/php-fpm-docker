# 配置参考

管理程序从 `PFS_CONFIG_FILE` 指定的 JSON 文件读取配置，也可以通过环境变量 `PFS_CONFIG` 传入 JSON 字符串。未设置两者时管理程序不会启动。JSON 必须是标准 JSON，不支持注释。

## 完整示例

```json
{
  "boot_script": "",
  "shutdown_script": "",
  "services": {
    "_defaults": {
      "restart_interval": 5,
      "output_max_lines": 200,
      "output_log": false,
      "stop_command": ""
    },
    "worker": {
      "command": "php /wwwroot/worker.php",
      "cwd": "/wwwroot",
      "env": {
        "APP_ENV": "production"
      },
      "restart_interval": 5,
      "output_max_lines": 200,
      "output_log": false,
      "stop_command": ""
    }
  },
  "timer": {
    "refresh-cache": {
      "type": "tick",
      "time": "60",
      "command": "sh /wwwroot/refresh-cache.sh"
    },
    "daily-report": {
      "type": "time",
      "time": "02:30:00",
      "command": "sh /wwwroot/daily-report.sh"
    }
  }
}
```

生命周期字段也可写在 `lifecycle` 对象中；顶层字段优先：

```json
{
  "lifecycle": {
    "boot_script": "sh ./scripts/boot.sh",
    "shutdown_script": "sh ./scripts/shutdown.sh"
  }
}
```

## 生命周期字段

| 字段 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `boot_script` | string | 空 | 后台服务、定时器和管理 HTTP 服务启动前执行的 Shell 命令。 |
| `shutdown_script` | string | 空 | 定时器、后台服务和 HTTP 停止后执行的 Shell 命令。 |
生命周期脚本由 `ProcessManager` 执行，使用 `/data/srv/api`（容器）或 `srv/api`（源码运行）作为工作目录，并继承管理程序环境。脚本的 stdout/stderr 会实时通过 `Logs` 输出，是否打印到控制台或写入文件由对应的 `PFS_LOGS_*_PRINT` 和 `PFS_LOGS_*_SAVE` 配置控制；进程退出后会回收并记录非零退出码。

建议显式调用 shell 脚本：

```json
{
  "boot_script": "sh ./scripts/boot.sh",
  "shutdown_script": "sh ./scripts/shutdown.sh"
}
```

需要 PHP-FPM 时，将它作为后台服务配置。使用前台模式运行，服务管理器才能监控并在退出后按 `restart_interval` 自动重启：

```json
{
  "services": {
    "php-fpm": {
      "command": ["/usr/sbin/php-fpm8.5", "-F"]
    }
  }
}
```

镜像安装 PHP-FPM 并将其配置为监听 `0.0.0.0:9000`，但只有在 `services` 中显式配置后才会启动。容器收到关闭信号时，服务管理器会停止该进程。

## 后台服务字段

`services._defaults` 仅为 `restart_interval`、`output_max_lines`、`output_log`、`stop_command` 提供共享默认值。`cwd` 和 `env` 应写在具体服务中。

| 字段 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `command` | string / array | 必填 | 启动命令，由 `ProcessManager` 执行。数组形式绕过 shell；字符串形式保留 shell 语义。 |
| `cwd` | string | API 工作目录 | 服务命令和关闭命令的工作目录。 |
| `env` | object | 空 | 合并到管理程序环境中的服务环境变量。 |
| `restart_interval` | integer | `5` | 服务退出后自动拉起前等待秒数；允许 `0`。 |
| `output_max_lines` | integer | `200` | 内存控制台历史行数上限，最小为 1。 |
| `output_log` | boolean | `false` | 是否将 stdout/stderr 追加写入文件。 |
| `stop_command` | string / array | 空 | 可选的服务关闭命令，由 `ProcessManager` 执行；未配置时直接发送 `SIGTERM`。 |

兼容别名：

- `restart` -> `restart_interval`
- `output_lines` -> `output_max_lines`
- `log` -> `output_log`
- `stop_script` 或 `shutdown_command` -> `stop_command`

推荐对不需要 shell 的服务使用数组命令：

```json
{
  "worker": {
    "command": ["php", "/wwwroot/worker.php"],
    "cwd": "/wwwroot"
  }
}
```

需要管道、重定向或多个 shell 操作时使用字符串命令：

```json
{
  "worker": {
    "command": "sh -c 'cd /wwwroot && php worker.php 2>&1'"
  }
}
```

关闭命令额外获得 `PFS_SERVICE_NAME`、`PFS_SERVICE_PID` 和 `PFS_SERVICE_COMMAND` 环境变量。关闭命令自己的 stdout/stderr 也进入服务控制台与可选日志。数组命令绕过 shell，字符串命令保留 shell 语义。

设置 `output_log` 后，日志保存在：

```text
/data/srv/api/data/logs/services/<服务名>.log
```

## 定时器字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `type` | `tick` / `time` | 周期执行或每日指定时间执行。 |
| `time` | string | `tick` 使用秒数；`time` 使用 `HH:mm:ss`。 |
| `command` | string | Shell 命令，由 `ProcessManager` 执行；stdout/stderr 会记录到本次执行日志。 |

`PFS_TIMER_LOGS_MAX` 控制定时器执行历史内存条数，默认 `100`，最小有效值为 `1`。

例如：

```json
{
  "timer": {
    "refresh-cache": {
      "type": "tick",
      "time": "60",
      "command": "sh /wwwroot/refresh-cache.sh"
    }
  }
}
```

## 环境变量

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `PFS_CONFIG_FILE` | 无 | JSON 配置文件路径。 |
| `PFS_CONFIG` | 无 | 内联 JSON 配置；仅在没有 `PFS_CONFIG_FILE` 时读取。 |
| `PFS_API_TOKEN` | 启动时生成 UUID | HTTP API 和 WebSocket 的签名密钥。设置固定值以避免重启后 UI 凭据失效。 |
| `PFS_HTTP_HOST` | `0.0.0.0` | Swoole HTTP 绑定地址。 |
| `PFS_HTTP_PORT` | `26698` | Swoole HTTP 端口。 |
| `TZ` | PHP 代码默认 UTC | 时区。Dockerfile 设为 `Asia/Shanghai`。 |
| `PFS_TIMER_LOGS_MAX` | `100` | 单个 TimerTask 的历史记录上限。 |
| `PFS_LOGS_INFO_PRINT` | `true` | info 日志是否输出到 stdout。 |
| `PFS_LOGS_DEBUG_PRINT` | `true` | debug 日志是否输出到 stdout。 |
| `PFS_LOGS_WARNING_PRINT` | `true` | warning 日志是否输出到 stdout。 |
| `PFS_LOGS_ERROR_PRINT` | `true` | error 日志是否输出到 stdout。 |
| `PFS_LOGS_INFO_SAVE` | `false` | info 日志是否落盘。 |
| `PFS_LOGS_DEBUG_SAVE` | `false` | debug 日志是否落盘。 |
| `PFS_LOGS_WARNING_SAVE` | `true` | warning 日志是否落盘。 |
| `PFS_LOGS_ERROR_SAVE` | `true` | error 日志是否落盘。 |

日志开关只有值为字符串 `true` 时启用。

## 安全

- 使用固定、高熵的 `PFS_API_TOKEN`，不要把实际密钥提交到 Git。
- 面板将 API 地址和 token 存在浏览器 `localStorage`。
- 生命周期脚本、服务启动命令和关闭命令都会在容器内执行，只允许可信管理员维护配置。
- 生产环境建议用 HTTPS/WSS，并限制管理端口的网络可达范围。
