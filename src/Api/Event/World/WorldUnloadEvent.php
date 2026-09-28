<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\World;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldUnloadOptions;

/** Cancellable intent emitted before players move or world shutdown begins. */
final class WorldUnloadEvent extends CancellableEvent
{
    public function __construct(
        public readonly World $world,
        public readonly WorldUnloadOptions $options,
    ) {}
}
