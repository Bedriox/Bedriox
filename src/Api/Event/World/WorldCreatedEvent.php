<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\World;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldCreationOptions;

/** Published after a newly created world is loaded and authoritative. */
final class WorldCreatedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly World $world,
        public readonly WorldCreationOptions $options,
    ) {}
}
