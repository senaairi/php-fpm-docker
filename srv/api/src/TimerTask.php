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
    public string $command;
    public string $last_exec;
    public ?string $last_logs;
    public int $timer;
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

    public function boot() {
        if ($this->type == 'tick') {
            $this->timer = \Swoole\Timer::tick($this->time * 1000, [$this, 'execute']);
        } else if ($this->type == 'time') {
            $this->nextTime();
        }
    }
    public function clean(): void
    {
        \Swoole\Timer::clear($this->timer);
        $this->down = true;
        while (true) {
            if ($this->status == 'exec') \Swoole\Coroutine\System::sleep(1);
            break;
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
        try {
            $logs = eval($this->command);
        } catch (\Exception | \Throwable $e) {
            $logs = "执行command代码异常：{$e->getMessage()}\r\n{$e->getTraceAsString()}";
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
}
