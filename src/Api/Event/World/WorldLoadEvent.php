<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\World;

use Bedriox\Api\Event\CancellableEvent;

/** Cancellable intent emitted before existing world storage is opened. */
final class WorldLoadEvent extends CancellableEvent
{
    public function __construct(public readonly string $worldId) {}
}
