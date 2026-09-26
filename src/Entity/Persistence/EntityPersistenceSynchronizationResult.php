<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

final readonly class EntityPersistenceSynchronizationResult
{
    public function __construct(
        public int $observedEntities,
        public int $transferredEntities,
        public int $removedEntities,
        public int $failedEntities,
        public bool $complete,
        public int $deferredOwnershipTransfers = 0,
    ) {}
}
