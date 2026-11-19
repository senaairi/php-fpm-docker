<?php

namespace Lihzorg\PhpFpmSrvApi;

use Closure;

class ServiceTask
{
    public string $name;
    /** @var string|array<int, string> */
    public string|array $command;
    public string $status = 'stopped';
    public ?int $pid = null;
    public ?string $started_at = null;
    public ?string $last_exit_at = null;
    public ?int $exit_code = null;
    public ?int $exit_signal = null;
    /** @var array{time: string, stream: string, text: string}[] */
    public array $output_lines = [];

    private string $cwd;
    private string $stop_command;
    /** @var array<string, string> */
    private array $env;
    private int $restart_interval;
    private int $output_max_lines;
    private bool $output_log;
    private string $log_file;
    private ?ProcessManager $process = null;
    private int $process_generation = 0;
    private bool $stop_requested = false;
    private bool $desired_running = true;
    private bool $restart_requested = false;
    private string $input_buffer = '';
    private string $stdout_buffer = '';
    private string $stderr_buffer = '';
    private int $output_generation_completed = 0;
    /** @var Closure(array<string, mixed>): void|null */
    private ?Closure $event_handler = null;

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $defaults
     */
    public function __construct(string $name, array $config, array $defaults = [])
    {
        $this->name = $name;
        $command = $config['command'] ?? '';
        $this->command = is_array($command)
            ? array_map(static fn ($value): string => (string)$value, array_values($command))
            : trim((string)$command);
        $this->stop_command = trim((string)(
            $config['stop_command']
            ?? $config['stop_script']
            ?? $config['shutdown_command']
            ?? $defaults['stop_command']
            ?? ''
        ));
        $this->cwd = (string)($config['cwd'] ?? $defaults['cwd'] ?? ROOT_PATH);
        $this->env = is_array($config['env'] ?? null)
            ? array_map(static fn ($value): string => (string)$value, $config['env'])
            : [];
        $this->restart_interval = max(
            0,
            (int)($config['restart_interval'] ?? $config['restart'] ?? $defaults['restart_interval'] ?? 5),
        );
        $this->output_max_lines = max(
            1,
            (int)($config['output_max_lines'] ?? $config['output_lines'] ?? $defaults['output_max_lines'] ?? 200),
        );
        $this->output_log = (bool)($config['output_log'] ?? $config['log'] ?? $defaults['output_log'] ?? false);
        $this->log_file = ROOT_PATH . '/data/logs/services/' . $this->safeName($name) . '.log';
    }

    /** @param Closure(array<string, mixed>): void $handler */
    public function onEvent(Closure $handler): void
    {
        $this->event_handler = $handler;
    }

    public function boot(): void
    {
        $this->stop_requested = false;
        $this->desired_running = true;
        $this->run();
    }

    public function start(): bool
    {
        $this->desired_running = true;
        if ($this->command === '' || $this->command === []) {
            $this->setStatus('error', ['message' => '服务命令为空']);
            return false;
        }
        if ($this->cwd !== '' && !is_dir($this->cwd)) {
            $this->setStatus('error', ['message' => '工作目录不存在: ' . $this->cwd]);
            return false;
        }
        return true;
    }

    public function stop(): void
    {
        $this->desired_running = false;
    }

    public function shutdown(): void
    {
        $this->stop_requested = true;
        $this->desired_running = false;
    }

    public function waitStopped(float $seconds = 8): void
    {
        $deadline = microtime(true) + max(0, $seconds);
        while ($this->status !== 'stopped' && microtime(true) < $deadline) {
            \Swoole\Coroutine\System::sleep(0.05);
        }
    }

    public function restart(): bool
    {
        $this->desired_running = true;
        $this->restart_requested = true;
        return true;
    }

    public function isRunning(): bool
    {
        if ($this->process === null) {
            return false;
        }
        return $this->process->isRunning();
    }

    public function write(string $input): bool
    {
        if (!$this->desired_running || $this->status !== 'running') {
            return false;
        }
        $this->input_buffer .= $input;
        return true;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $outputLines = $this->output_lines;
        foreach (['stdout', 'stderr'] as $stream) {
            $property = $stream === 'stderr' ? 'stderr_buffer' : 'stdout_buffer';
            if ($this->{$property} !== '') {
                $outputLines[] = [
                    'time' => date(DATE_RFC3339_EXTENDED),
                    'stream' => $stream,
                    'text' => $this->{$property},
                ];
            }
        }

        return [
            'name' => $this->name,
            'command' => $this->command,
            'stop_command' => $this->stop_command,
            'status' => $this->status,
            'pid' => $this->pid,
            'started_at' => $this->started_at,
            'last_exit_at' => $this->last_exit_at,
            'exit_code' => $this->exit_code,
            'exit_signal' => $this->exit_signal,
            'output_lines' => $outputLines,
            'restart_interval' => $this->restart_interval,
            'output_max_lines' => $this->output_max_lines,
            'output_log' => $this->output_log,
        ];
    }

    public function run(): void
    {
        while (!$this->stop_requested) {
            if ($this->restart_requested) {
                $this->restart_requested = false;
                $this->terminateProcess();
            }
            if (!$this->desired_running) {
                if ($this->process !== null) {
                    $this->terminateProcess();
                }
                if ($this->status !== 'stopped') {
                    $this->setStatus('stopped');
                }
                \Swoole\Coroutine\System::sleep(0.05);
                continue;
            }
            if (!$this->isRunning()) {
                if (!$this->startProcess()) {
                    if ($this->stop_requested) {
                        break;
                    }
                    $this->waitForRestart();
                    continue;
                }
            }

            $this->flushInput();
            if ($this->process !== null && !$this->process->isRunning()) {
                $this->process->wait(2.0);
                $generation = $this->process_generation;
                $this->waitOutputDrain($generation);
                $this->exit_code = $this->process->getExitCode();
                $this->last_exit_at = date(DATE_RFC3339_EXTENDED);
                $this->closeProcess();
                $this->input_buffer = '';
                $this->setStatus($this->desired_running ? 'restarting' : 'stopped');
                if ($this->desired_running && !$this->restart_requested) {
                    $this->waitForRestart();
                }
                $this->restart_requested = false;
                continue;
            }
            \Swoole\Coroutine\System::sleep(0.05);
        }
        $this->terminateProcess();
        $this->closeProcess();
        $this->setStatus('stopped');
    }

    private function startProcess(): bool
    {
        if (!$this->desired_running) {
            return false;
        }
        if ($this->command === '' || $this->command === []) {
            $this->setStatus('error', ['message' => '服务命令为空']);
            return false;
        }
        if ($this->cwd !== '' && !is_dir($this->cwd)) {
            $this->setStatus('error', ['message' => '工作目录不存在: ' . $this->cwd]);
            return false;
        }

        $manager = new ProcessManager(
            $this->command,
            $this->cwd !== '' ? $this->cwd : null,
            array_merge($_ENV, $this->env),
        );
        try {
            if (!$manager->start()) {
                throw new \RuntimeException('无法启动服务进程');
            }
        } catch (\Throwable $e) {
            $this->process = null;
            $this->setStatus('error', ['message' => $e->getMessage()]);
            return false;
        }
        $this->process = $manager;
        $generation = ++$this->process_generation;
        $this->stdout_buffer = '';
        $this->stderr_buffer = '';
        $this->pid = $manager->getPid();
        $this->started_at = date(DATE_RFC3339_EXTENDED);
        $this->setStatus('running');
        \Swoole\Coroutine::create(function () use ($manager, $generation): void {
            $this->consumeOutput($manager, $generation);
        });
        \Swoole\Coroutine::create(function () use ($manager, $generation): void {
            $this->consumeStatus($manager, $generation);
        });
        return true;
    }

    private function waitForRestart(): void
    {
        $deadline = microtime(true) + $this->restart_interval;
        while (
            !$this->stop_requested
            && $this->desired_running
            && !$this->restart_requested
            && microtime(true) < $deadline
        ) {
            \Swoole\Coroutine\System::sleep(min(0.1, max(0.01, $deadline - microtime(true))));
        }
    }

    private function flushInput(): void
    {
        if ($this->input_buffer === '' || $this->process === null) {
            return;
        }
        if (!$this->process->writeToStdin($this->input_buffer)) {
            $this->input_buffer = '';
        } else {
            $this->input_buffer = '';
        }
    }

    private function consumeOutput(ProcessManager $manager, int $generation): void
    {
        while (true) {
            $event = $manager->getOutputChannel()->pop();
            if ($event === false) {
                break;
            }
            if (is_array($event) && isset($event['stream'], $event['data'])) {
                $this->appendOutput((string)$event['stream'], (string)$event['data']);
            }
        }
        $this->flushOutputBuffers();
        $this->output_generation_completed = max($this->output_generation_completed, $generation);
    }

    private function waitOutputDrain(int $generation, float $seconds = 2): void
    {
        $deadline = microtime(true) + $seconds;
        while ($this->output_generation_completed < $generation && microtime(true) < $deadline) {
            \Swoole\Coroutine\System::sleep(0.01);
        }
    }

    private function consumeStatus(ProcessManager $manager, int $generation): void
    {
        while (true) {
            $event = $manager->getStatusChannel()->pop();
            if ($event === false) {
                return;
            }
            if (!is_array($event)) {
                continue;
            }
            $status = $event['status'] ?? null;
            if (in_array($status, [ProcessManager::STATUS_REAPED, ProcessManager::STATUS_STOPPED], true)) {
                if ($generation === $this->process_generation && isset($event['exitCode'])) {
                    $this->exit_code = is_numeric($event['exitCode']) ? (int)$event['exitCode'] : null;
                }
                return;
            }
        }
    }

    private function appendOutput(string $stream, string $chunk): void
    {
        $bufferProperty = $stream === 'stderr' ? 'stderr_buffer' : 'stdout_buffer';
        $this->{$bufferProperty} .= str_replace("\r\n", "\n", $chunk);
        $this->{$bufferProperty} = str_replace("\r", "\n", $this->{$bufferProperty});

        while (($position = strpos($this->{$bufferProperty}, "\n")) !== false) {
            $line = substr($this->{$bufferProperty}, 0, $position);
            $this->{$bufferProperty} = substr($this->{$bufferProperty}, $position + 1);
            $this->appendLine($stream, $line);
        }

        if ($this->output_log) {
            $dir = dirname($this->log_file);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            error_log($chunk, 3, $this->log_file);
        }
        $this->emit([
            'type' => 'output',
            'service' => $this->name,
            'stream' => $stream,
            'data' => $chunk,
            'time' => date(DATE_RFC3339_EXTENDED),
        ]);
    }

    private function appendLine(string $stream, string $line): void
    {
        $this->output_lines[] = [
            'time' => date(DATE_RFC3339_EXTENDED),
            'stream' => $stream,
            'text' => $line,
        ];
        if (count($this->output_lines) > $this->output_max_lines) {
            $this->output_lines = array_slice($this->output_lines, -$this->output_max_lines);
        }
    }

    private function flushOutputBuffers(): void
    {
        foreach (['stdout', 'stderr'] as $stream) {
            $property = $stream === 'stderr' ? 'stderr_buffer' : 'stdout_buffer';
            if ($this->{$property} !== '') {
                $this->appendLine($stream, $this->{$property});
                $this->{$property} = '';
            }
        }
    }

    /** @param array<string, mixed> $extra */
    private function setStatus(string $status, array $extra = []): void
    {
        $this->status = $status;
        $this->emit(array_merge([
            'type' => 'status',
            'service' => $this->name,
            'status' => $status,
            'pid' => $this->pid,
            'time' => date(DATE_RFC3339_EXTENDED),
        ], $extra));
    }

    /** @param array<string, mixed> $event */
    private function emit(array $event): void
    {
        if ($this->event_handler !== null) {
            ($this->event_handler)($event);
        }
    }

    private function closeProcess(): void
    {
        $this->process = null;
        $this->pid = null;
    }

    private function terminateProcess(): void
    {
        if ($this->process === null) {
            return;
        }

        if ($this->stop_command !== '' && $this->runStopCommand()) {
            // A stop script gets a chance to perform graceful shutdown first.
            $this->process->wait(2.0);
        }
        if ($this->process !== null) {
            // Use SIGTERM when no stop script is configured or it did not stop the process.
            $this->process->sendSignal(defined('SIGTERM') ? SIGTERM : 15);
            $this->process->wait(2.0);
        }
        if ($this->process !== null) {
            $this->process->forceStop(2.0);
        }
        $this->waitOutputDrain($this->process_generation);
        $this->closeProcess();
        $this->input_buffer = '';
    }

    private function runStopCommand(): bool
    {
        if ($this->process === null) {
            return false;
        }

        $pid = $this->process->pid !== null ? (string)$this->process->pid : '';

        $manager = new ProcessManager(
            $this->stop_command,
            $this->cwd !== '' ? $this->cwd : null,
            array_merge($_ENV, [
                'PFS_SERVICE_NAME' => $this->name,
                'PFS_SERVICE_PID' => $pid,
                'PFS_SERVICE_COMMAND' => is_array($this->command)
                    ? json_encode($this->command, JSON_UNESCAPED_SLASHES)
                    : $this->command,
            ]),
        );
        try {
            if (!$manager->start()) {
                throw new \RuntimeException('无法启动关闭命令');
            }
        } catch (\Throwable $e) {
            $this->emit([
                'type' => 'status',
                'service' => $this->name,
                'status' => 'stop_command_error',
                'message' => $e->getMessage() . '，将发送 SIGTERM',
                'time' => date(DATE_RFC3339_EXTENDED),
            ]);
            return false;
        }

        $deadline = microtime(true) + 2;
        while ($manager->isRunning() && microtime(true) < $deadline) {
            $event = $manager->getOutputChannel()->pop(0.05);
            if (is_array($event) && isset($event['stream'], $event['data'])) {
                $this->appendOutput((string)$event['stream'], (string)$event['data']);
            }
        }
        if ($manager->isRunning()) {
            $manager->forceStop(1);
        } else {
            $manager->wait(1);
        }
        while (($event = $manager->getOutputChannel()->pop(0)) !== false) {
            if (is_array($event) && isset($event['stream'], $event['data'])) {
                $this->appendOutput((string)$event['stream'], (string)$event['data']);
            }
        }
        return true;
    }

    private function safeName(string $name): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $name);
        return $safe !== null && $safe !== '' ? $safe : 'service';
    }
}
