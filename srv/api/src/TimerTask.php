<?php
namespace Lihzorg\PhpFpmSrvApi;
use Carbon\Carbon;
class TimerTask
{
    public string $name;
    /** @var 'tick' | 'time' 计时器类型<br>tick:循环执行，time:在指定时间执行 */
    public string $type;
    /** @var string  type=tick时，传递单位秒 */
    public string $time;
    /** @var string|array<int, string> */
    public string|array $command = '';
    public string $cwd = '';
    /** @var array<string, string> */
    public array $env = [];
    public string $last_exec;
    public ?string $last_logs;
    public int $timer;
    private ?ProcessManager $process = null;
    /**
     * @var array{
     *     start: string,
     *     end: string,
     *     logs: string
     * }[]
     */
    public array $execute_logs = [];
    /** @var 'wait' | 'exec' */
    public string $status = 'wait';
    public bool $down = false;

    /** @param array<string, mixed> $config */
    public function configureProcess(array $config): void
    {
        $this->command = $this->normalizeCommand($config['command'] ?? '');
        $this->cwd = is_string($config['cwd'] ?? null) && $config['cwd'] !== ''
            ? $config['cwd']
            : ROOT_PATH;
        $this->env = is_array($config['env'] ?? null)
            ? array_map(
                static fn ($environmentValue): string => (string)$environmentValue,
                $config['env'],
            )
            : [];
    }

    public function boot(): void
    {
        if ($this->type == 'tick') {
            $this->timer = \Swoole\Timer::tick($this->time * 1000, [$this, 'execute']);
        } else if ($this->type == 'time') {
            $this->nextTime();
        }
    }
    public function clean(): void
    {
        if (isset($this->timer)) {
            \Swoole\Timer::clear($this->timer);
        }
        $this->down = true;
        while ($this->status === 'exec') {
            if ($this->process?->isRunning()) {
                $this->process->forceStop(1);
            }
            \Swoole\Coroutine\System::sleep(0.01);
        }
    }
    public function nextTime(): void
    {
        $now = Carbon::now();
        $next = new Carbon($now->format('Y-m-d\T').$this->time.$now->format('P'));
        if ($next <= $now) {
            $next->modify('+1 day');
        }
        $time = $now->diffInSeconds($next);
        Logs::debug('Timer', "{$this->name} next after: {$time}s");
        $this->timer = \Swoole\Timer::after($time * 1000, function () {
            call_user_func([$this, 'execute']);
            if (!$this->down) $this->nextTime();
        });
    }
    public function execute(): void
    {
        if ($this->status != 'wait') return;
        $this->status = 'exec';
        Logs::debug('Timer', $this->name.' execute');

        $start = Carbon::now();
        $logs = '';
        try {
            $logs = $this->runCommand();
        } catch (\Throwable $e) {
            $logs = "执行 Shell 命令异常：{$e->getMessage()}\r\n";
            Logs::error('Timer', $this->name.' command failed: '.$e->getMessage());
        }
        $end = Carbon::now();

        $this->last_logs = $logs;
        $this->last_exec = $end->format(DATE_W3C);

        $this->execute_logs[] = [
            'start' => $start->format(DATE_RFC3339_EXTENDED),
            'end' => $end->format(DATE_RFC3339_EXTENDED),
            'logs' => $logs,
        ];

        $max_logs = max(1, (int)(getenv('PFS_TIMER_LOGS_MAX') ?: 100));
        if (count($this->execute_logs) > $max_logs) {
            $this->execute_logs = array_slice($this->execute_logs, -$max_logs);
        }
        $this->status = 'wait';
    }

    private function runCommand(): string
    {
        if ($this->command === '' || $this->command === []) {
            throw new \RuntimeException('定时器命令为空');
        }

        $processManager = new ProcessManager(
            $this->command,
            $this->cwd !== '' ? $this->cwd : ROOT_PATH,
            array_merge($_ENV, $this->env),
        );
        $this->process = $processManager;
        try {
            $processManager->start();
            $processManager->closeStdin();
            return $this->collectOutput($processManager);
        } finally {
            $this->stopCommandProcess($processManager);
            $this->process = null;
        }
    }

    private function collectOutput(ProcessManager $processManager): string
    {
        $logs = '';
        while ($processManager->isRunning()) {
            $event = $processManager->getOutputChannel()->pop(0.05);
            if (is_array($event) && isset($event['data'])) {
                $logs .= (string)$event['data'];
            }
        }

        $processManager->wait(1);
        while (($event = $processManager->getOutputChannel()->pop(0)) !== false) {
            if (is_array($event) && isset($event['data'])) {
                $logs .= (string)$event['data'];
            }
        }
        return $logs;
    }

    private function stopCommandProcess(ProcessManager $processManager): void
    {
        if ($processManager->isRunning()) {
            $processManager->forceStop(1);
        }
    }

    /** @return string|array<int, string> */
    private function normalizeCommand(mixed $command): string|array
    {
        return is_array($command)
            ? array_map(static fn ($commandPart): string => (string)$commandPart, array_values($command))
            : trim((string)$command);
    }
}
