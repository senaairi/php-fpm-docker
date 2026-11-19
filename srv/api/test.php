<?php
\Swoole\Runtime::enableCoroutine($flags = SWOOLE_HOOK_ALL);
const ROOT_PATH = __DIR__;
require_once ROOT_PATH . '/vendor/autoload.php';


\Swoole\Coroutine\run(function () {
    $p = new \Lihzorg\PhpFpmSrvApi\ProcessManager(
        ['/usr/bin/php8.5', '-r', 'while(true) {echo time(), PHP_EOL; sleep(1);}'],
        '/',
        []
    );
    go(function () use ($p) {
        $p->start();
    });
    while (true) {
        print_r($p->outputChannel->pop());
        sleep(1);
    }
});
