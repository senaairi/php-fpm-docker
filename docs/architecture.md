# 架构与启动流程

## 容器进程

```text
Docker container
└── PID 1: /usr/bin/php8.5 /data/srv/api/main.php
    ├── Lifecycle
    │   ├── boot_script
    │   └── shutdown_script
    ├── Swoole HTTP server
    │   ├── signed HTTP API
    │   ├── service WebSocket console
    │   └── UI files from /data/srv/api/public
    ├── Timer
    └── Services
        └── supervised child processes, such as php-fpm8.5 -F
```

PHP-FPM is installed and configured to listen on `0.0.0.0:9000`. It starts only when it is listed under `services` in the JSON configuration. Run it in the foreground (for example, `/usr/sbin/php-fpm8.5 -F`) so the service manager can observe its exit status, restart it, and stop it during container shutdown.

## Image build and files

The Dockerfile copies `srv/api` to `/data/srv/api` and `srv/ui` to `/data/srv/ui`. During the image build it installs Composer dependencies, builds the Vue application, and copies the frontend output to `/data/srv/api/public`.

The container uses `/data/srv/api` as its working directory and starts with:

```dockerfile
ENTRYPOINT ["/usr/bin/php8.5", "/data/srv/api/main.php"]
```

The source of the API and UI remains in `srv/api` and `srv/ui`; generated frontend assets are included in the image.

## Startup

1. `main.php` enables Swoole coroutine hooks and loads Composer autoloading.
2. Lifecycle initializes logging, reads `PFS_API_TOKEN`, and parses configuration from `PFS_CONFIG_FILE` or `PFS_CONFIG`. If neither setting provides valid JSON, the process exits.
3. Lifecycle runs the optional `boot_script`, starts configured services and timers, then starts the HTTP server.
4. The HTTP server defaults to `0.0.0.0:26698`; override it with `PFS_HTTP_HOST` and `PFS_HTTP_PORT`.
5. The main process handles `SIGTERM` and `SIGINT` by calling the lifecycle shutdown routine.

## Services and process management

Each entry under `services` creates a supervised process. The process manager uses `proc_open()` to capture stdout and stderr, write to stdin, and report process status. Array commands run without an intermediate shell; string commands retain shell behavior.

When a service exits, it is restarted after `restart_interval` seconds (default `5`). The management API and UI can start, stop, or restart configured services and display their output. Service output is kept in memory up to `output_max_lines`; setting `output_log: true` also appends it to `/data/srv/api/data/logs/services/<service-name>.log`.

Services use ordinary stdin/stdout/stderr pipes rather than a PTY, so programs that require a terminal device may not work correctly.

## Shutdown

Shutdown can be triggered by `SIGTERM`, `SIGINT`, or the signed `POST /api/container/shutdown` request. Lifecycle stops timers, asks services to stop and waits for them, stops the HTTP server, then runs the optional `shutdown_script`. The PHP management process then exits.

Lifecycle scripts run through `ProcessManager`; stdout and stderr are sent to `Logs` as they are produced, following the configured console and file log settings. The child process is reaped after exit.

## Timers

- `tick` runs at the configured interval in seconds.
- `time` runs daily at the configured `HH:mm:ss`.

Timer commands are shell commands executed and reaped by `ProcessManager`. Their stdout/stderr is stored in the in-memory execution history, which is cleared when the management process restarts.
