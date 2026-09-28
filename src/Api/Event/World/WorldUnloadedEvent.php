<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\World;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldUnloadOptions;

/** Published after storage closes; the included handle is stale by definition. */
final class WorldUnloadedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly World $world,
        public readonly WorldUnloadOptions $options,
    ) {}
}
