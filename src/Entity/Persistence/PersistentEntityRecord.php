<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\World\ChunkPosition;

interface PersistentEntityRecord
{
    public function typeIdentifier(): string;

    public function uuid(): string;

    public function worldName(): string;

    public function ownerChunk(): ChunkPosition;

    public function revision(): int;
}
