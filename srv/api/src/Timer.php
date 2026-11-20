<?php
namespace Lihzorg\PhpFpmSrvApi;
class Timer
{
    /** @var TimerTask[] */
    public $task = [];


    public function boot() {
        global $_PFS;
        if (array_key_exists('timer', $_PFS->config)) {
            foreach ($_PFS->config['timer'] as $name => $conf) {
                $task = new TimerTask();
                $task->name = $name;
                $task->type = $conf['type'];
                $task->time = $conf['time'];
                $task->configureProcess($conf);
                $task->boot();
                $this->task[$name] = $task;
            }
        }
    }

    public function down()
    {
        foreach ($this->task as $task) {
            $task->clean();
        }
        $this->task = [];
    }
}
