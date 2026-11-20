<?php
namespace Lihzorg\PhpFpmSrvApi;

use Ramsey\Uuid\Uuid;

/**
 * Owns application initialization, signal handling, startup and shutdown.
 */
class Lifecycle
{
    /** @var array{command: string|array<int, string>, cwd: string, env: array<string, string>} */
    private array $boot_script = [];
    /** @var array{command: string|array<int, string>, cwd: string, env: array<string, string>} */
    private array $shutdown_script = [];

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
        $this->boot_script = $this->resolveScriptConfig($config, $lifecycle, [
            'boot_script',
            'startup_script',
            'boot',
        ]);
        $this->shutdown_script = $this->resolveScriptConfig($config, $lifecycle, [
            'shutdown_script',
            'stop_script',
            'shutdown',
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $lifecycle
     * @param string[] $keys
     * @return array{command: string|array<int, string>, cwd: string, env: array<string, string>}
     */
    private function resolveScriptConfig(array $config, array $lifecycle, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($config[$key])) {
                $script = $this->normalizeScript($config[$key]);
                if ($script !== null) {
                    return $script;
                }
            }
        }
        foreach ($keys as $key) {
            if (isset($lifecycle[$key])) {
                $script = $this->normalizeScript($lifecycle[$key]);
                if ($script !== null) {
                    return $script;
                }
            }
        }
        return ['command' => '', 'cwd' => ROOT_PATH, 'env' => []];
    }

    /** @return array{command: string|array<int, string>, cwd: string, env: array<string, string>}|null */
    private function normalizeScript(mixed $script): ?array
    {
        if (!is_string($script) && !is_array($script)) {
            return null;
        }

        $config = is_array($script) && array_key_exists('command', $script)
            ? $script
            : ['command' => $script];

        return [
            'command' => $this->normalizeCommand($config['command'] ?? ''),
            'cwd' => $this->normalizeWorkingDirectory($config['cwd'] ?? null),
            'env' => $this->normalizeEnvironment($config['env'] ?? null),
        ];
    }

    /** @return string|array<int, string> */
    private function normalizeCommand(mixed $command): string|array
    {
        return is_array($command)
            ? array_map(static fn ($commandPart): string => (string)$commandPart, array_values($command))
            : trim((string)$command);
    }

    private function normalizeWorkingDirectory(mixed $cwd): string
    {
        return is_string($cwd) && $cwd !== '' ? $cwd : ROOT_PATH;
    }

    /** @return array<string, string> */
    private function normalizeEnvironment(mixed $environment): array
    {
        return is_array($environment)
            ? array_map(static fn ($environmentValue): string => (string)$environmentValue, $environment)
            : [];
    }

    /** @param array{command: string|array<int, string>, cwd: string, env: array<string, string>} $script */
    private function runScript(array $script, string $name): void
    {
        if ($script['command'] === '' || $script['command'] === []) {
            return;
        }

        $processManager = new ProcessManager($script['command'], $script['cwd'], array_merge($_ENV, $script['env']));
        try {
            $processManager->start();
            $processManager->closeStdin();
            $this->streamScriptOutput($processManager);
            $processManager->wait(1);
            $this->logScriptExit($name, $processManager->getExitCode());
        } catch (\RuntimeException $e) {
            Logs::error('Lifecycle', $name . ' script failed: ' . $e->getMessage());
        } finally {
            $processManager->forceStop(1);
        }
    }

    private function streamScriptOutput(ProcessManager $processManager): void
    {
        while ($processManager->isRunning()) {
            $this->writeScriptOutput($processManager->getOutputChannel()->pop(0.05));
        }

        while (($event = $processManager->getOutputChannel()->pop(0)) !== false) {
            $this->writeScriptOutput($event);
        }
    }

    private function writeScriptOutput(mixed $event): void
    {
        if (!is_array($event) || !isset($event['data'])) {
            return;
        }

        $stream = ($event['stream'] ?? 'stdout') === 'stderr' ? 'stderr' : 'stdout';
        $output = rtrim((string)$event['data'], "\r\n");
        if ($output === '') {
            return;
        }

        $message = 'Lifecycle script ' . $stream . ': ' . $output;
        if ($stream === 'stderr') {
            Logs::error('Lifecycle', $message);
            return;
        }
        Logs::info('Lifecycle', $message);
    }

    private function logScriptExit(string $name, ?int $exitCode): void
    {
        if ($exitCode !== 0) {
            Logs::error('Lifecycle', $name . ' script exited with code ' . ($exitCode ?? 'unknown'));
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
