<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Scheduler;

use Closure;

/** @internal */
final class ScheduledPluginTask
{
    /** @param Closure(): void $callback */
    public function __construct(
        public readonly int $id,
        public readonly int $sequence,
        public readonly string $owner,
        public readonly Closure $callback,
        public readonly int $periodTicks,
        public readonly OwnedTaskHandle $handle,
        public int $targetTick,
    ) {}
}
