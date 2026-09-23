<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Scheduler;

/**
 * Adapter implemented by the managed plugin-worker runtime.
 *
 * @internal
 */
interface PluginAsyncTaskExecutor
{
    public function submit(AsyncTaskRequest $request): void;

    public function cancel(int $taskId): void;

    /** @return list<AsyncTaskOutcome> */
    public function poll(int $maximum): array;

    public function shutdown(): void;
}
