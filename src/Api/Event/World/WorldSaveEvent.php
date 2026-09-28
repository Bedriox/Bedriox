<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\World;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\World;

/** Cancellable intent emitted before a requested world save is submitted. */
final class WorldSaveEvent extends CancellableEvent
{
    public function __construct(public readonly World $world) {}
}
