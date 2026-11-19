<?php
namespace Lihzorg\PhpFpmSrvApi;

use Lihzorg\PhpFpmSrvApi\HttpControllers\Api\Timer as TimerController;
use Lihzorg\PhpFpmSrvApi\HttpControllers\Api\Service as ServiceController;
use Lihzorg\PhpFpmSrvApi\HttpControllers\Api\Container as ContainerController;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

class Http {
    public Server $srv;
    public string $public_dir;
    /** @var array<string, callable>  */
    public array $route_rules;
    /** @var array<string, int> api 请求id防重放攻击 */
    public array $api_request_id = [];
    public int $api_request_id_timer = 0;

    public function __construct()
    {
        $this->public_dir = realpath(ROOT_PATH.'/public') ? : throw new \Exception('not find public dir');
        $this->srv = new Server(
            host: Content::env('PFS_HTTP_HOST', '0.0.0.0'),
            port: Content::env('PFS_HTTP_PORT', 26698),
            ssl: false,
        );
        // api 路由
        $this->apiRouteRuleInit();
        // api 验证
        $this->srv->handle('/api', [$this, 'apiMiddleware']);
        // 服务控制台 WebSocket
        $this->srv->handle('/ws/services', [$this, 'serviceWebSocket']);
        // 其余请求落到静态资源目录
        $this->srv->handle('/', [$this, 'staticFileHandle']);
    }
    public function boot()
    {
        Logs::info('Http', 'listen: http://'.$this->srv->host.':'.$this->srv->port);
        $this->api_request_id_timer = \Swoole\Timer::tick(60 * 1000, [$this, 'apiRequestIdCleaner']);
        \Swoole\Coroutine\go(function () {
            $this->srv->start();
        });
    }
    public function down()
    {
        if ($this->api_request_id_timer) \Swoole\Timer::clear($this->api_request_id_timer);
        $this->srv->shutdown();
    }
    public function apiRequestIdCleaner()
    {
        $now = time();
        foreach ($this->api_request_id as $k => $v) {
            if ($now > $v) {
                unset($this->api_request_id[$k]);
            }
        }
    }
    public function apiRouteRuleInit()
    {
        $timer = new TimerController();
        $service = new ServiceController();
        $container = new ContainerController();
        $this->route_rules = [
            '/api/timer/list' => [$timer, 'list'],
            '/api/timer/history' => [$timer, 'history'],
            '/api/service/list' => [$service, 'list'],
            '/api/service/action' => [$service, 'action'],
            '/api/container/shutdown' => [$container, 'shutdown'],
        ];
    }
    public function apiMiddleware(Request $request, Response $response)
    {
        global $_PFS;
        $method = $request->server['request_method'] ?? 'GET';
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

        if ($method == 'OPTIONS') {
            $response->status(204);
            $response->end();
        } else {
            $path = parse_url($request->server['request_uri'] ?? '', PHP_URL_PATH);
            if (is_string($path) && array_key_exists($path, $this->route_rules)) {
                $request_id = $request->get['request_id'] ?? '';
                $time = $request->get['time'] ?? '';
                $data = $request->get['data'] ?? '';
                $sign = $request->get['sign'] ?? '';

                if (
                    !is_string($request_id)
                    || $request_id === ''
                    || !is_numeric($time)
                    || (int)$time < time()
                    || !is_string($data)
                    || !is_string($sign)
                    || !hash_equals(hash_hmac('sha256', $request_id.$time.$data, $_PFS->api_token), $sign)
                    || array_key_exists($request_id, $this->api_request_id)
                ) {
                    $response->status(403);
                    $response->end(json_encode([
                        'status' => 'sign_check_failed',
                        'message' => 'sign check failed',
                        'data' => ''
                    ]));
                    return;
                }
                $this->api_request_id[$request_id] = (int)$time;
                $decoded = base64_decode($data, true);
                $depackage = is_string($decoded) ? json_decode($decoded, true) : null;
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($depackage)) {
                    $response->status(403);
                    $response->end(json_encode([
                        'status' => 'error',
                        'message' => 'depackage check failed',
                        'data' => ''
                    ]));
                    return;
                }

                call_user_func($this->route_rules[$path], $request, $response, $depackage);
            } else {
                $response->status(404);
                $response->end();
            }
        }
    }

    public function serviceWebSocket(Request $request, Response $ws): void
    {
        global $_PFS;
        $payload = $this->verifySignature($request);
        if ($payload === null) {
            $ws->status(403);
            $ws->end(json_encode([
                'status' => 'sign_check_failed',
                'message' => 'sign check failed',
                'data' => '',
            ]));
            return;
        }

        $serviceName = $payload['service'] ?? '';
        if (!is_string($serviceName) || $_PFS->service->get($serviceName) === null) {
            $ws->status(404);
            $ws->end(json_encode([
                'status' => 'error',
                'message' => 'service not found',
                'data' => '',
            ]));
            return;
        }

        if (!$ws->upgrade()) {
            return;
        }

        $task = $_PFS->service->get($serviceName);
        $send = static function (Response $socket, array $data): void {
            try {
                $socket->push(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (\Throwable $e) {
                Logs::debug('Services', 'websocket push failed: ' . $e->getMessage());
            }
        };
        $unsubscribe = $_PFS->service->subscribe(function (array $event) use ($ws, $serviceName, $send): void {
            if (($event['service'] ?? null) === $serviceName) {
                $send($ws, $event);
            }
        });
        $send($ws, ['type' => 'snapshot', 'service' => $serviceName, 'data' => $task->snapshot()]);

        try {
            while (true) {
                $frame = $ws->recv();
                if ($frame === '' || $frame === false || $frame === null) {
                    break;
                }
                if (is_object($frame) && (($frame->opcode ?? null) === 0x8)) {
                    break;
                }
                $rawMessage = is_object($frame) ? ($frame->data ?? '') : (string)$frame;
                $message = json_decode((string)$rawMessage, true);
                if (!is_array($message)) {
                    $send($ws, ['type' => 'error', 'message' => '消息格式无效']);
                    continue;
                }
                if (($message['type'] ?? '') === 'input') {
                    $input = (string)($message['data'] ?? '');
                    $success = $_PFS->service->write($serviceName, $input);
                    $send($ws, [
                        'type' => 'input_ack',
                        'service' => $serviceName,
                        'success' => $success,
                    ]);
                } elseif (($message['type'] ?? '') === 'heartbeat') {
                    $send($ws, [
                        'type' => 'heartbeat_ack',
                        'service' => $serviceName,
                        'client_time' => $message['time'] ?? null,
                        'time' => date(DATE_RFC3339_EXTENDED),
                    ]);
                } elseif (($message['type'] ?? '') === 'action') {
                    $action = (string)($message['action'] ?? '');
                    $success = match ($action) {
                        'start' => $_PFS->service->start($serviceName),
                        'stop' => $_PFS->service->stop($serviceName),
                        'restart' => $_PFS->service->restart($serviceName),
                        default => false,
                    };
                    $send($ws, [
                        'type' => 'action_ack',
                        'service' => $serviceName,
                        'action' => $action,
                        'success' => $success,
                    ]);
                }
            }
        } finally {
            $unsubscribe();
            if (method_exists($ws, 'close')) {
                $ws->close();
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function verifySignature(Request $request): ?array
    {
        global $_PFS;
        $requestId = $request->get['request_id'] ?? '';
        $time = $request->get['time'] ?? '';
        $data = $request->get['data'] ?? '';
        $sign = $request->get['sign'] ?? '';
        if (
            $requestId === ''
            || !is_numeric($time)
            || (int)$time < time()
            || !is_string($data)
            || !is_string($sign)
            || !hash_equals(hash_hmac('sha256', $requestId . $time . $data, $_PFS->api_token), $sign)
            || array_key_exists($requestId, $this->api_request_id)
        ) {
            return null;
        }
        $this->api_request_id[$requestId] = (int)$time;
        $decoded = base64_decode($data, true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        return json_last_error() === JSON_ERROR_NONE && is_array($payload) ? $payload : null;
    }

    const mimeMap = [
        'html'  => 'text/html; charset=utf-8',
        'htm'   => 'text/html; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'mjs'   => 'application/javascript; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'map'   => 'application/json; charset=utf-8',
        'txt'   => 'text/plain; charset=utf-8',
        'xml'   => 'application/xml; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject',
        'mp4'   => 'video/mp4',
        'webm'  => 'video/webm',
        'mp3'   => 'audio/mpeg',
        'wav'   => 'audio/wav',
        'pdf'   => 'application/pdf',
        'wasm'  => 'application/wasm',
    ];
    public function staticFileHandle(Request $request, Response $response): void
    {
        // ---------- 1. 方法限制 ----------
        $method = $request->server['request_method'] ?? 'GET';
        if ($method !== 'GET' && $method !== 'HEAD') {
            $response->status(405);
            $response->header('Allow', 'GET, HEAD');
            $response->end('Method Not Allowed');
            return;
        }

        // ---------- 2. 解析路径（只取 path，剥离 query） ----------
        $uri = $request->server['request_uri'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            $response->status(400);
            $response->end('Bad Request');
            return;
        }

        // null 字节会让 realpath 抛 ValueError（PHP 8），提前拦
        if (str_contains($path, "\0")) {
            $response->status(400);
            $response->end('Bad Request');
            return;
        }

        // 根路径映射
        if ($path === '/') {
            $path = '/index.html';
        }

        // ---------- 3. 一次 realpath 定生死 ----------
        $realPath = realpath($this->public_dir . $path);

        // 不存在 → 交给业务逻辑
        if ($realPath === false) {
            // === 你的业务逻辑入口 ===
            $response->header('Content-Type', 'text/plain; charset=utf-8');
            $response->end('Dynamic: ' . $path);
            return;
        }

        // 逃出 public/ → 403（目录遍历、符号链接逃逸都在这拦）
        if (!str_starts_with($realPath, $this->public_dir.'/')) {
            $response->status(403);
            $response->end('Forbidden');
            return;
        }

        // 是目录（/assets/、/assets/subdir/）→ 404
        if (!is_file($realPath)) {
            $response->status(404);
            $response->end('Not Found');
            return;
        }

        // ---------- 4. 缓存头 + ETag ----------
        $mtime = filemtime($realPath);
        $size  = filesize($realPath);
        if ($mtime === false || $size === false) {
            $response->status(500);
            $response->end('Internal Server Error');
            return;
        }
        $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';

        $response->header('Cache-Control', 'public, max-age=86400');
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        $response->header('ETag', $etag);

        // ---------- 5. 条件请求 → 304 ----------
        $ifNoneMatch     = $request->header['if-none-match'] ?? '';
        $ifModifiedSince = $request->header['if-modified-since'] ?? '';
        $notModified     = false;

        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            $notModified = true;
        } elseif ($ifModifiedSince !== '') {
            $ts = strtotime($ifModifiedSince);
            if ($ts !== false && $ts >= $mtime) {
                $notModified = true;
            }
        }

        if ($notModified) {
            $response->status(304);
            $response->end();
            return;
        }

        // ---------- 6. Content-Type ----------
        $ext  = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $mime = self::mimeMap[$ext] ?? null;
        if ($mime === null) {
            $mime = @mime_content_type($realPath) ?: 'application/octet-stream';
        }
        $response->header('Content-Type', $mime);

        // ---------- 7. HEAD：只回 header ----------
        if ($method === 'HEAD') {
            $response->header('Content-Length', (string)$size);
            $response->end();
            return;
        }

        // ---------- 8. GET：sendfile（零拷贝；不要再 end()） ----------
        $response->sendfile($realPath);
    }
}
