<?php
namespace Lihzorg\PhpFpmSrvApi;

use Ramsey\Uuid\Uuid;
use Swoole\Coroutine;
use Swoole\Process as SwooleProcess;

/**
 * Owns application initialization, signal handling, startup and shutdown.
 */
class Lifecycle
{
    private string $boot_script = '';
    private string $shutdown_script = '';

    /** @var 'init' | 'running' | 'stopping' | 'stopped' */
    public string $status = 'init';

    public function __construct()
    {
        global $_PFS;
        Logs::info('Lifecycle', 'init');

        $_ENV = getenv();
        $this->ensureLogDirectory();

        $this->loadToken($_PFS);

        $config = $this->loadConfig();
        $_PFS->config = $config;
        $this->loadScripts($config);

        $_PFS->session = new Session();
        $_PFS->http = new Http();
        $_PFS->service = new Services();
        $_PFS->timer = new Timer();
    }

    public function boot(): void
    {
        global $_PFS;

        Logs::info('Lifecycle', 'boot srv');
        $this->runScript($this->boot_script, 'boot');

        $_PFS->service->boot();
        $_PFS->timer->boot();
        $_PFS->http->boot();

        $this->status = 'running';
    }
    public function shutdown(): void
    {
        global $_PFS;
        if ($this->status == 'running') {
            $this->status = 'stopping';
            Logs::info('Lifecycle', 'shutdown srv');
            $_PFS->timer->down();
            $_PFS->service->down();
            $_PFS->http->down();
            $this->runScript($this->shutdown_script, 'shutdown');
            $this->status = 'stopped';
        }
    }
    public function wait()
    {
        while (true) {
            if ($this->status == 'stopped') break;
            \Swoole\Coroutine\System::sleep(0.1);
        }
    }

    private function loadToken(Content $content): void
    {
        $token = Content::env('PFS_API_TOKEN');
        if (is_string($token) && $token !== '') {
            $content->api_token = $token;
            return;
        }

        $content->api_token = Uuid::uuid4()->toString();
        Logs::warning('Lifecycle', '未配置 PFS_API_TOKEN，随机生成 API token: ' . $content->api_token);
    }

    /**
     * @return array<string, mixed>|null
     * @throws ExitRun
     */
    private function loadConfig(): ?array
    {
        $file = Content::env('PFS_CONFIG_FILE');
        $inline = Content::env('PFS_CONFIG');
        $raw = null;

        if (is_string($file) && $file !== '') {
            $raw = @file_get_contents($file);
        } elseif (is_string($inline) && $inline !== '') {
            $raw = $inline;
        }

        if (!is_string($raw) || trim($raw) === '') {
            Logs::warning('Lifecycle', '未配置 PFS_CONFIG_FILE 或 PFS_CONFIG');
            throw new ExitRun();
        }

        $config = json_decode($raw, true);
        if (!is_array($config)) {
            Logs::error('Lifecycle', '配置必须是有效 JSON 对象');
            throw new ExitRun();
        }
        return $config;
    }

    /** @param array<string, mixed> $config */
    private function loadScripts(array $config): void
    {
        $lifecycle = is_array($config['lifecycle'] ?? null) ? $config['lifecycle'] : [];
        $this->boot_script = $this->scriptValue($config, $lifecycle, [
            'boot_script',
            'startup_script',
            'boot',
        ]);
        $this->shutdown_script = $this->scriptValue($config, $lifecycle, [
            'shutdown_script',
            'stop_script',
            'shutdown',
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $lifecycle
     * @param string[] $keys
     */
    private function scriptValue(array $config, array $lifecycle, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($config[$key]) && is_string($config[$key])) {
                return trim($config[$key]);
            }
        }
        foreach ($keys as $key) {
            if (isset($lifecycle[$key]) && is_string($lifecycle[$key])) {
                return trim($lifecycle[$key]);
            }
        }
        return '';
    }

    private function runScript(string $script, string $name): void
    {
        if ($script === '') {
            return;
        }
        $output = shell_exec($script);
        if (is_string($output) && trim($output) !== '') {
            Logs::info('Lifecycle', $name . ': ' . trim($output));
        }
    }

    private function ensureLogDirectory(): void
    {
        $directory = ROOT_PATH . '/data/logs';
        if (!file_exists($directory)) {
            mkdir($directory, 0777, true);
        }
    }
}
