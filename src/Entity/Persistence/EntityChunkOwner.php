<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;

final readonly class EntityChunkOwner
{
    public function __construct(
        public string $worldName,
        public ChunkPosition $chunk,
        public int $revision,
    ) {
        if ($worldName === '' || strlen($worldName) > EntityPersistenceLimits::MAX_WORLD_NAME_BYTES
            || preg_match('//u', $worldName) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $worldName) === 1
            || $revision < 0) {
            throw new InvalidArgumentException('Entity chunk owner is invalid.');
        }
    }

    public function key(): string
    {
        return $this->worldName . "\x00" . $this->chunk->key();
    }
}
