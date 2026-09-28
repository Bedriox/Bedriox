<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\World;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\World\World;

/** Published after the requested world save has committed successfully. */
final class WorldSavedEvent extends Event implements PostEvent
{
    public function __construct(public readonly World $world) {}
}
