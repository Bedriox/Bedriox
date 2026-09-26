<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Event\CancellableEvent;

final class EntitySpawnEvent extends CancellableEvent
{
    public function __construct(
        public readonly Entity $entity,
        public readonly SpawnCause $cause,
    ) {}
}
