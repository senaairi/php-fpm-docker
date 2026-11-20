<?php
namespace Lihzorg\PhpFpmSrvApi;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;

class ProcessManager
{
    public const STATUS_RUNNING = 'running'; // 运行中
    public const STATUS_REAPED  = 'reaped';  // 自然退出并已回收
    public const STATUS_STOPPED = 'stopped'; // 被强制停止并已回收

    /** @var string|array<int, string> */
    private string|array $command;
    private ?string $cwd;
    private ?array $env;

    /** @var resource|null */
    private $process = null;

    /** @var resource[] */
    private array $pipes = [];

    private string $status = self::STATUS_STOPPED;
    private bool $stopRequested = false;
    private ?int $exitCode = null;

    private int $outputCapacity;
    private int $statusCapacity;
    private int $readBufferSize;
    private float $readSleep;

    private bool $outputClosed = false;
    private int $droppedOutputBytes = 0;

    /** @var Channel 控制台输出：['stream'=>'stdout|stderr','data'=>string] */
    public Channel $outputChannel;

    /** @var Channel 状态：['status'=>'running|reaped|stopped','exitCode'=>?int] */
    public Channel $statusChannel;

    public function __construct(
        string|array $command,
        ?string $cwd = null,
        ?array $env = null,
        int $outputCapacity = 1024,
        int $statusCapacity = 16,
        int $readBufferSize = 8192,
        float $readSleep = 0.01
    ) {
        $this->command = $command;
        $this->cwd = $cwd;
        $this->env = $env;
        $this->outputCapacity = $outputCapacity;
        $this->statusCapacity = $statusCapacity;
        $this->readBufferSize = $readBufferSize;
        $this->readSleep = $readSleep;

        $this->outputChannel = new Channel($this->outputCapacity);
        $this->statusChannel = new Channel($this->statusCapacity);
    }

    /**
     * 拉起子进程。必须在 Swoole 协程容器中调用。
     */
    public function start(): bool
    {
        if ($this->status === self::STATUS_RUNNING) {
            throw new \RuntimeException('child process is already running');
        }

        if ($this->outputClosed) {
            $this->outputChannel = new Channel($this->outputCapacity);
            $this->outputClosed = false;
        }

        $this->stopRequested = false;
        $this->exitCode = null;
        $this->pipes = [];
        $this->process = null;
        $this->droppedOutputBytes = 0;

        $descriptors = [
            0 => ['pipe', 'r'], // 子进程 stdin，父进程写
            1 => ['pipe', 'w'], // 子进程 stdout，父进程读
            2 => ['pipe', 'w'], // 子进程 stderr，父进程读
        ];

        $process = proc_open(
            $this->command,
            $descriptors,
            $pipes,
            $this->cwd,
            $this->env
        );

        if (!is_resource($process)) {
            $this->setStatus(self::STATUS_STOPPED);
            throw new \RuntimeException('proc_open failed');
        }

        $this->process = $process;
        $this->pipes = $pipes;

        $this->setStatus(self::STATUS_RUNNING);

        $wg = new WaitGroup();
        $wg->add(2);

        Coroutine::create(function () use ($wg, $pipes) {
            try {
                $this->readPipe($pipes[1], 'stdout');
            } finally {
                $wg->done();
            }
        });

        Coroutine::create(function () use ($wg, $pipes) {
            try {
                $this->readPipe($pipes[2], 'stderr');
            } finally {
                $wg->done();
            }
        });

        // 回收协程：等待两个读协程结束，再关闭管道，最后 proc_close
        Coroutine::create(function () use ($wg) {
            $this->reap($wg);
        });

        return true;
    }

    /**
     * 非阻塞/协程化读取管道。读到 EOF 后关闭该管道。
     */
    private function readPipe($pipe, string $stream): void
    {
        if (!is_resource($pipe)) {
            return;
        }

        stream_set_blocking($pipe, false);

        try {
            while (true) {
                if (!is_resource($pipe)) {
                    break;
                }

                $data = @fread($pipe, $this->readBufferSize);

                if ($data === false) {
                    if (feof($pipe)) {
                        break;
                    }
                    Coroutine::sleep($this->readSleep);
                    continue;
                }

                if ($data === '') {
                    if (feof($pipe)) {
                        break;
                    }
                    Coroutine::sleep($this->readSleep);
                    continue;
                }

                $this->pushOutput($stream, $data);

                if (feof($pipe)) {
                    break;
                }
            }
        } finally {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    private function pushOutput(string $stream, string $data): void
    {
        if ($this->outputClosed) {
            $this->droppedOutputBytes += strlen($data);
            return;
        }

        $event = [
            'stream' => $stream,
            'data'   => $data,
        ];

        // 避免消费者过慢时无限阻塞读协程，导致子进程卡在写管道上。
        // 超时未推送成功则丢弃并计数，可通过 getDroppedOutputBytes() 查看。
        if (!$this->outputChannel->push($event, 0.1)) {
            $this->droppedOutputBytes += strlen($data);
        }
    }

    /**
     * 回收顺序：读协程已到 EOF -> 关闭 stdin/out/err -> proc_close -> 推送最终状态。
     */
    private function reap(WaitGroup $wg): void
    {
        $wg->wait();

        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }

        foreach ([1, 2] as $i) {
            if (isset($this->pipes[$i]) && is_resource($this->pipes[$i])) {
                fclose($this->pipes[$i]);
            }
        }

        $code = -1;
        if (is_resource($this->process)) {
            $code = proc_close($this->process);
        }

        $this->exitCode = $code;
        $this->process = null;
        $this->pipes = [];

        $finalStatus = $this->stopRequested
            ? self::STATUS_STOPPED
            : self::STATUS_REAPED;

        $this->setStatus($finalStatus);

        if (!$this->outputClosed) {
            $this->outputChannel->close();
            $this->outputClosed = true;
        }
    }

    private function setStatus(string $status): void
    {
        $this->status = $status;

        $this->statusChannel->push([
            'status'   => $status,
            'exitCode' => $this->exitCode,
        ], 0.2);
    }

    /**
     * 强行停止子进程：发送 SIGKILL，并等待回收完成。
     */
    public function forceStop(float $timeout = 5.0): bool
    {
        if ($this->status !== self::STATUS_RUNNING || !is_resource($this->process)) {
            return true;
        }

        $this->stopRequested = true;

        // 即使 proc_terminate 返回 false，也可能进程刚退出，继续等状态变化
        proc_terminate($this->process, defined('SIGKILL') ? SIGKILL : 9);

        return $this->wait($timeout);
    }

    /**
     * 向子进程发送信号，例如 SIGTERM、SIGINT。
     */
    public function sendSignal(int $sig): bool
    {
        if ($this->status !== self::STATUS_RUNNING || !is_resource($this->process)) {
            return false;
        }

        return proc_terminate($this->process, $sig);
    }

    /**
     * 向子进程 stdin 发送控制台内容。
     */
    public function writeToStdin(string $data): bool
    {
        if (
            $this->status !== self::STATUS_RUNNING
            || !isset($this->pipes[0])
            || !is_resource($this->pipes[0])
        ) {
            return false;
        }

        $len = strlen($data);
        $offset = 0;

        while ($offset < $len) {
            $n = fwrite($this->pipes[0], substr($data, $offset));
            if ($n === false || $n === 0) {
                return false;
            }
            $offset += $n;
        }

        fflush($this->pipes[0]);
        return true;
    }

    /**
     * 主动关闭子进程 stdin，发送 EOF。
     */
    public function closeStdin(): void
    {
        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
            unset($this->pipes[0]);
        }
    }

    /**
     * 等待子进程进入 reaped/stopped 状态。
     */
    public function wait(float $timeout = 5.0): bool
    {
        $deadline = microtime(true) + $timeout;

        while ($this->status === self::STATUS_RUNNING) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            Coroutine::sleep(0.01);
        }

        return true;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPid(): ?int
    {
        if ($this->process === null) {
            return null;
        }
        $status = proc_get_status($this->process);
        return is_array($status) && isset($status['pid']) ? (int)$status['pid'] : null;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function getDroppedOutputBytes(): int
    {
        return $this->droppedOutputBytes;
    }

    public function getOutputChannel(): Channel
    {
        return $this->outputChannel;
    }

    public function getStatusChannel(): Channel
    {
        return $this->statusChannel;
    }
}
