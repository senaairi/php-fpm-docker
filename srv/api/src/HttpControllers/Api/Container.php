<?php

namespace Lihzorg\PhpFpmSrvApi\HttpControllers\Api;

use Lihzorg\PhpFpmSrvApi\HttpControllers\Controllers;
use Swoole\Http\Request;
use Swoole\Http\Response;

class Container extends Controllers
{
    public function shutdown(Request $request, Response $response, array $payload = []): void
    {
        global $_PFS;
        $this->success($response, ['status' => 'shutting_down'], '容器正在安全关闭');
        \Swoole\Timer::after(50, static function (): void {
            global $_PFS;
            \Swoole\Coroutine::create(static function () use (&$_PFS): void {
                $_PFS->lifecycle->shutdown();
            });
        });
    }
}
