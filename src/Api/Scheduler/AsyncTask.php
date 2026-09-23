<?php

declare(strict_types=1);

namespace Bedriox\Api\Scheduler;

use Bedriox\Api\Plugin\PluginContext;

/**
 * Process-isolated work with main-thread lifecycle callbacks.
 *
 * The worker constructs a fresh instance and invokes only onRun(); instance
 * mutations made there are not transferred back to the main process.
 */
abstract class AsyncTask
{
    abstract public function onRun(AsyncTaskValue $input): AsyncTaskValue;

    public function onCompletion(AsyncTaskValue $result, PluginContext $context): void {}

    public function onFailure(AsyncTaskFailure $failure, PluginContext $context): void {}
}
