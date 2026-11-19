<?php

namespace Lihzorg\PhpFpmSrvApi;

use Closure;

class Services
{
    /** @var array<string, ServiceTask> */
    public array $task = [];
    /** @var array<int, Closure(array<string, mixed>): void> */
    private array $listeners = [];
    private int $output_max_lines = 200;
    private int $restart_interval = 5;
    private bool $output_log = false;

    public function boot(): void
    {
        global $_PFS;
        $config = is_array($_PFS->config['services'] ?? null) ? $_PFS->config['services'] : [];
        $defaults = is_array($config['_defaults'] ?? null) ? $config['_defaults'] : [];
        $this->restart_interval = max(
            0,
            (int)($defaults['restart_interval'] ?? $config['restart_interval'] ?? 5),
        );
        $this->output_max_lines = max(
            1,
            (int)($defaults['output_max_lines'] ?? $config['output_max_lines'] ?? 200),
        );
        $this->output_log = (bool)($defaults['output_log'] ?? $config['output_log'] ?? false);

        foreach ($config as $name => $serviceConfig) {
            if (!is_string($name) || str_starts_with($name, '_') || !is_array($serviceConfig)) {
                continue;
            }
            $task = new ServiceTask($name, $serviceConfig, [
                'restart_interval' => $this->restart_interval,
                'output_max_lines' => $this->output_max_lines,
                'output_log' => $this->output_log,
                'stop_command' => $defaults['stop_command'] ?? '',
            ]);
            $task->onEvent(function (array $event): void {
                $this->broadcast($event);
            });
            $this->task[$name] = $task;
            \Swoole\Coroutine::create(function () use ($task): void {
                $task->run();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->task as $task) {
            $task->shutdown();
        }
        foreach ($this->task as $task) {
            $task->waitStopped();
        }
        $this->task = [];
    }

    /** @param Closure(array<string, mixed>): void $listener */
    public function subscribe(Closure $listener): Closure
    {
        $id = count($this->listeners);
        $this->listeners[$id] = $listener;
        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    /** @return array<string, mixed>[] */
    public function list(): array
    {
        return array_map(
            static fn (ServiceTask $task): array => $task->snapshot(),
            array_values($this->task),
        );
    }

    public function get(string $name): ?ServiceTask
    {
        return $this->task[$name] ?? null;
    }

    public function start(string $name): bool
    {
        return ($this->task[$name] ?? null)?->start() ?? false;
    }

    public function stop(string $name): bool
    {
        $task = $this->task[$name] ?? null;
        if ($task === null) {
            return false;
        }
        $task->stop();
        return true;
    }

    public function restart(string $name): bool
    {
        return ($this->task[$name] ?? null)?->restart() ?? false;
    }

    public function write(string $name, string $input): bool
    {
        return ($this->task[$name] ?? null)?->write($input) ?? false;
    }

    /** @param array<string, mixed> $event */
    private function broadcast(array $event): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $listener($event);
            } catch (\Throwable $e) {
                Logs::error('Services', $e->getMessage());
            }
        }
    }
}
