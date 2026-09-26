<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;

final class EntitySpawnedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Entity $entity,
        public readonly SpawnCause $cause,
    ) {}
}
