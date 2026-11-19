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
                $obj = new TimerTask();
                $obj->name = $name;
                $obj->type = $conf['type'];
                $obj->time = $conf['time'];
                $obj->command = $conf['command'];
                $obj->boot();
                $this->task[$name] = $obj;
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