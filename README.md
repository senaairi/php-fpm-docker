# PHP-FPM Docker

基于 Ubuntu 26.04 和 PHP 8.5 的 PHP-FPM 容器镜像，同时包含 PHP CLI、Swoole 和进程管理面板。容器入口运行管理 API；配置中的 `services` 用来启动和监督后台进程。PHP-FPM 通过 `php-fpm8.5 -F` 在前台运行，由服务管理器负责重启和停止。

## 功能

- PHP-FPM 默认监听 `0.0.0.0:9000`。
- 通过 JSON 配置启动、监督并控制多个后台服务，支持输出查看、stdin 输入和自动重启。
- Swoole 管理 API 和 Web UI 默认监听 `0.0.0.0:26698`。
- 支持周期定时任务、服务管理和容器安全关闭。
- UI 提供定时任务历史、服务控制台及运行状态页面。

## 快速开始

将以下内容保存为 `compose.yaml`。`./wwwroot` 会挂载到容器的 `/wwwroot`；PHP-FPM 作为受管理服务启动。

```yaml
services:
  php8.5-fpm:
    image: "lihzorg/php-fpm-docker:20261119-1742"
    environment:
      PFS_CONFIG: |
        {
          "services": {
            "php-fpm": {
              "command": ["/usr/sbin/php-fpm8.5", "-F"]
            }
          }
        }
    volumes:
      - ./wwwroot:/wwwroot
```

启动并查看服务状态：

```sh
docker compose up -d
docker compose logs -f php8.5-fpm
```

Compose 默认网络中的其他容器可通过 `php8.5-fpm:9000` 连接 PHP-FPM。FastCGI 上游示例：

```nginx
fastcgi_pass php8.5-fpm:9000;
```

容器端口默认不会发布到宿主机。需要从宿主机访问时，在服务下添加端口映射：

```yaml
    ports:
      - "127.0.0.1:9000:9000"
      - "127.0.0.1:26698:26698"
```

映射管理端口后，可在 `http://127.0.0.1:26698/#/login` 打开面板。未设置 `PFS_API_TOKEN` 时，启动程序会生成随机 token 并写入容器日志；也可以通过 `PFS_API_TOKEN` 设置固定密钥。管理端口应限制为可信网络可访问。

## 配置

管理程序需要通过 `PFS_CONFIG` 提供内联 JSON，或通过 `PFS_CONFIG_FILE` 指定 JSON 配置文件。若两者都设置，则优先读取 `PFS_CONFIG_FILE`。JSON 不支持注释。

服务配置支持数组或字符串形式的 `command` 和 `stop_command`，以及工作目录、环境变量、重启间隔和输出日志等选项。定时任务的 `command` 是由 `ProcessManager` 执行的 Shell 命令。完整字段说明见[配置参考](docs/configuration.md)。

## 文档

- [架构与启动流程](docs/architecture.md)
- [配置参考](docs/configuration.md)
- [HTTP API、WebSocket 与管理面板](docs/api-and-ui.md)
- [开发、构建与部署](docs/development.md)

## 本地构建

在仓库根目录执行：

```sh
docker build -t lihzorg/php-fpm-docker:local .
```

Dockerfile 会构建管理 API 和前端资源。更多开发说明见[开发、构建与部署](docs/development.md)。

## 贡献与许可

分支、Pull Request 和发布流程见[贡献指南](CONTRIBUTING.md)。

本项目原创代码采用 [The Unlicense](LICENSE)，可自由使用、修改和分发，包括商业用途。镜像中包含的软件和依赖各自适用其上游许可。
