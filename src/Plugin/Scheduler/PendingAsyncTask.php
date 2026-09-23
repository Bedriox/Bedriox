<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Scheduler;

use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Api\Scheduler\AsyncTask;

/** @internal */
final readonly class PendingAsyncTask
{
    public function __construct(
        public AsyncTaskRequest $request,
        public AsyncTask $task,
        public PluginContext $context,
        public OwnedTaskHandle $handle,
    ) {}
}
