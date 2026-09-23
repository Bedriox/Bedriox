<?php

declare(strict_types=1);

namespace Bedriox\Api\Scheduler;

interface PluginScheduler
{
    /** @param callable(): void $callback */
    public function nextTick(callable $callback): TaskHandle;

    /** @param callable(): void $callback */
    public function delayed(int $delayTicks, callable $callback): TaskHandle;

    /** @param callable(): void $callback */
    public function repeating(int $periodTicks, callable $callback): TaskHandle;

    /** @param callable(): void $callback */
    public function delayedRepeating(int $delayTicks, int $periodTicks, callable $callback): TaskHandle;

    public function async(AsyncTask $task, AsyncTaskValue $input): TaskHandle;
}
