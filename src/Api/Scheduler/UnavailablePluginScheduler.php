<?php

declare(strict_types=1);

namespace Bedriox\Api\Scheduler;

final class UnavailablePluginScheduler implements PluginScheduler
{
    public function nextTick(callable $callback): TaskHandle
    {
        throw new \LogicException('The plugin scheduler is not available in this context.');
    }

    public function delayed(int $delayTicks, callable $callback): TaskHandle
    {
        throw new \LogicException('The plugin scheduler is not available in this context.');
    }

    public function repeating(int $periodTicks, callable $callback): TaskHandle
    {
        throw new \LogicException('The plugin scheduler is not available in this context.');
    }

    public function delayedRepeating(int $delayTicks, int $periodTicks, callable $callback): TaskHandle
    {
        throw new \LogicException('The plugin scheduler is not available in this context.');
    }

    public function async(AsyncTask $task, AsyncTaskValue $input): TaskHandle
    {
        throw new \LogicException('The plugin scheduler is not available in this context.');
    }
}
