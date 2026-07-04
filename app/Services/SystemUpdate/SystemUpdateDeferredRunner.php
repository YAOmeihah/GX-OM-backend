<?php

namespace App\Services\SystemUpdate;

class SystemUpdateDeferredRunner
{
    public function afterResponse(callable $task): void
    {
        app()->terminating(function () use ($task): void {
            $this->prepareLongRunningTask();
            $task();
        });
    }

    private function prepareLongRunningTask(): void
    {
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }
}
