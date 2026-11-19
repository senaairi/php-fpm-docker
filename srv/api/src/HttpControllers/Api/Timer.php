<?php
namespace Lihzorg\PhpFpmSrvApi\HttpControllers\Api;

use Lihzorg\PhpFpmSrvApi\HttpControllers\Controllers;
use Swoole\Http\Request;
use Swoole\Http\Response;

class Timer extends Controllers
{
    public function list(Request $req, Response $resp, array $payload = []): void
    {
        global $_PFS;
        $list = [];
        foreach ($_PFS->timer->task as $task) {
            $list[] = [
                'name' => $task->name,
                'type' => $task->type,
                'time' => $task->time,
                'command' => $task->command,
                'last_exec' => $task->last_exec ?? '',
                'last_logs' => $task->last_logs ?? '',
                'status' => $task->status,
            ];
        }

        $this->success($resp, $list);
    }

    public function history(Request $req, Response $resp, array $payload = []): void
    {
        global $_PFS;
        $name = $payload['name'] ?? null;
        $task = is_string($name) ? ($_PFS->timer->task[$name] ?? null) : null;

        if ($task === null) {
            $this->error($resp, '定时器不存在', [], 404);
            return;
        }

        $this->success($resp, $task->execute_logs);
    }
}
