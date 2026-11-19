<?php

namespace Lihzorg\PhpFpmSrvApi\HttpControllers\Api;

use Lihzorg\PhpFpmSrvApi\HttpControllers\Controllers;
use Swoole\Http\Request;
use Swoole\Http\Response;

class Service extends Controllers
{
    public function list(Request $request, Response $response, array $payload = []): void
    {
        global $_PFS;
        $this->success($response, $_PFS->service->list());
    }

    public function action(Request $request, Response $response, array $payload = []): void
    {
        global $_PFS;
        $name = $payload['name'] ?? null;
        $action = $payload['action'] ?? null;
        if (!is_string($name) || !is_string($action) || $_PFS->service->get($name) === null) {
            $this->error($response, '服务参数无效', [], 400);
            return;
        }

        $success = match ($action) {
            'start' => $_PFS->service->start($name),
            'stop' => $_PFS->service->stop($name),
            'restart' => $_PFS->service->restart($name),
            default => false,
        };
        if (!$success && !in_array($action, ['stop', 'restart'], true)) {
            $this->error($response, '服务操作失败', [], 409);
            return;
        }
        $this->success($response, $_PFS->service->get($name)?->snapshot() ?? []);
    }
}
