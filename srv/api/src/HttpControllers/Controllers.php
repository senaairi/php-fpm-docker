<?php
namespace Lihzorg\PhpFpmSrvApi\HttpControllers;

use Swoole\Http\Response;

class Controllers {
    public function json_response(Response $resp, array $data, int $statusCode = 200): void
    {
        $resp->header('Content-Type', 'application/json');
        $resp->status($statusCode);
        $resp->end(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
    public function success(Response $resp, mixed $data, string $message = '', int $statusCode = 200): void
    {
        $this->json_response($resp, [
            'status' => 'success',
            'data' => $data,
            'message' => $message,
        ], $statusCode);
    }
    public function error(Response $resp, string $message, mixed $data = '', int $statusCode = 200): void
    {
        $this->json_response($resp, [
            'status' => 'error',
            'data' => $data,
            'message' => $message,
        ], $statusCode);
    }
}
