<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Event\CancellableEvent;

final class EntityDespawnEvent extends CancellableEvent
{
    public function __construct(
        public readonly Entity $entity,
        public readonly string $reason,
    ) {}
}
