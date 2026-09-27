<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\World\ChunkPosition;

/** Durable chunk ownership boundary for non-player entities. */
interface EntityPersistenceStore
{
    /** @throws CorruptEntityPersistenceException */
    public function loadEntityChunk(ChunkPosition $position): ?EntityChunkSnapshot;

    /** @throws EntityPersistenceConflictException */
    public function saveEntityChunk(EntityChunkSnapshot $snapshot): void;

    /** @throws CorruptEntityPersistenceException|EntityPersistenceConflictException */
    public function transferEntityOwnership(EntityOwnershipTransfer $transfer): EntityOwnershipTransferResult;
}
