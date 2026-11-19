<?php
\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
ini_set('date.timezone', getenv('TZ') ?: 'UTC');
const ROOT_PATH = __DIR__;
require_once ROOT_PATH . '/vendor/autoload.php';

/** @var \Lihzorg\PhpFpmSrvApi\Content $_PFS */
global $_PFS;

\Swoole\Coroutine\run(static function (): void {
    global $_PFS;
    $_PFS = new \Lihzorg\PhpFpmSrvApi\Content();

    // 垃圾回收
    gc_enable();
    $gc_timer = Swoole\Coroutine\go(function () {
        gc_collect_cycles();
        \Swoole\Coroutine\System::sleep(30);
    });

    try {
        $_PFS->lifecycle = new \Lihzorg\PhpFpmSrvApi\Lifecycle();
        $_PFS->lifecycle->boot();
        \Swoole\Process::signal(defined('SIGTERM') ? SIGTERM : 15, [$_PFS->lifecycle, 'shutdown']);
        \Swoole\Process::signal(defined('SIGINT') ? SIGINT : 2, [$_PFS->lifecycle, 'shutdown']);
        $_PFS->lifecycle->wait();
        \Swoole\Timer::clear($gc_timer);
    } catch (\Lihzorg\PhpFpmSrvApi\ExitRun $e) {

    } catch (\Exception | \Throwable $e) {
        \Lihzorg\PhpFpmSrvApi\Logs::error('main', $e->getMessage().PHP_EOL.$e->getTraceAsString());
    }
});
