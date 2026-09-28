<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\World;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\WorldCreationOptions;

/** Cancellable intent emitted before any storage or runtime is created. */
final class WorldCreateEvent extends CancellableEvent
{
    public function __construct(
        public readonly string $worldId,
        public readonly WorldCreationOptions $options,
    ) {}
}
