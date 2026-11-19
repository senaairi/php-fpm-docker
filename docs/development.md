# 开发、构建与部署

## 工具

- Docker Engine：构建和运行容器镜像。
- PHP 8.5 CLI、Composer 和 Swoole：本地运行管理 API。
- Node.js 24 和 pnpm：本地开发或构建前端。

依赖声明分别位于 `srv/api/composer.json` 和 `srv/ui/package.json`。Dockerfile 会在镜像构建阶段安装 API 依赖并构建前端。

## 本地运行 API

从 `srv/api/config.json.example` 准备 JSON 配置。配置必须是有效 JSON，不支持注释。

Linux/macOS/WSL 示例：

```sh
export TZ=Asia/Shanghai
export PFS_HTTP_HOST=127.0.0.1
export PFS_HTTP_PORT=17745
export PFS_CONFIG_FILE=/absolute/path/to/config.json
export PFS_API_TOKEN='replace-with-a-random-secret'
php srv/api/main.php
```

也可以切换到 `srv/api` 后执行 `php main.php`。若省略 `PFS_API_TOKEN`，管理 API 会生成随机 UUID；该值会写入启动日志，重启后会变化。

也可运行 `bash srv/api/dev.sh`。脚本默认从 `srv/api/data/config.json` 读取配置，并在本机 `127.0.0.1:14245` 监听。这些默认值可通过环境变量覆盖。

## 前端开发

```sh
cd srv/ui
pnpm install
pnpm dev
```

Vite 默认监听端口 `16658`。访问 `http://localhost:16658`，进入 `/#/login`，填写浏览器可访问的 API 地址和对应 token。

运行类型检查和构建：

```sh
pnpm type-check
pnpm build
```

单独构建前端可运行 `pnpm build-only`，产物位于 `srv/ui/dist`。

## 构建镜像

在仓库根目录执行：

```sh
docker build -t lihzorg/php-fpm-docker:local .
```

Dockerfile 使用 Ubuntu 26.04、PHP 8.5 和 Node.js 24。它将 API 源码复制到 `/data/srv/api`，安装 Composer 生产依赖，构建 Vue UI 并将产物复制到 API 的 `public` 目录。

容器入口为 `/usr/bin/php8.5 /data/srv/api/main.php`。启动时必须提供有效的 `PFS_CONFIG_FILE` 或 `PFS_CONFIG`。

## 容器运行

Compose 示例和 PHP-FPM 服务配置见[项目 README](../README.md#快速开始)。Compose 默认网络中的其他服务可以使用 `php8.5-fpm:9000` 作为 FastCGI 上游。

若要从宿主机访问管理面板，发布管理端口并将其限制在可信网络；本机使用时可绑定到 `127.0.0.1:26698`。PHP-FPM 监听 `9000`，但必须在 `services` 中显式配置 `php-fpm8.5 -F` 才会启动。

## 运行日志

- 管理程序的日志开关由 `PFS_LOGS_*` 环境变量控制，文件日志位于 `/data/srv/api/data/logs`。
- Timer 执行历史只保存在内存中，管理 API 重启后清空。
- Service 输出按 `output_max_lines` 保存在内存中。
- 设置 `output_log: true` 后，Service 输出追加到 `/data/srv/api/data/logs/services/`。

## 常见问题

### 管理 API 进程启动后退出

确认已设置 `PFS_CONFIG_FILE` 或 `PFS_CONFIG`，并确认配置内容是有效 JSON。

### UI 签名失败

确认 UI 中配置的 token 与 `PFS_API_TOKEN` 一致。未配置固定 token 时，服务每次启动都会生成新值。

### PHP-FPM 端口没有响应

确认配置中存在 `services.php-fpm.command`，并使用前台模式启动，例如 `["/usr/sbin/php-fpm8.5", "-F"]`。发布容器的 `9000` 端口本身不会启动 FPM。

### 服务控制台没有输出

确认服务向 stdout 或 stderr 输出内容。管理器使用普通管道，不提供 PTY；有些程序还会缓冲输出，或只写入文件。
