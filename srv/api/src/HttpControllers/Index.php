<?php
namespace Lihzorg\PhpFpmSrvApi\HttpControllers;
class Index
{
    public function index(\Swoole\Http\Request $req, \Swoole\Http\Response $resp) {
        $resp->end('hello world');
    }

}