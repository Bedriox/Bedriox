<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\World\ChunkPosition;

final readonly class EntityChunkActivationResult
{
    public function __construct(
        public ChunkPosition $chunk,
        public bool $alreadyActivated,
        public bool $corrupt,
        public int $activatedEntities,
        public int $dormantRecords,
        public int $inactiveRecords,
        public int $failedRecords,
    ) {}
}
