<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;

/** A validated unknown custom record retained byte-for-byte until its owner becomes available. */
final readonly class DormantEntityRecord implements PersistentEntityRecord
{
    public function __construct(
        private string $typeIdentifier,
        private string $uuid,
        private string $worldName,
        private ChunkPosition $ownerChunk,
        private int $revision,
        private string $encodedRecord,
    ) {
        if (str_starts_with($typeIdentifier, 'minecraft:') || $encodedRecord === ''
            || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $typeIdentifier) !== 1
            || strlen($typeIdentifier) > EntityPersistenceLimits::MAX_IDENTIFIER_BYTES
            || strlen($encodedRecord) > EntityPersistenceLimits::MAX_RECORD_BYTES
            || $worldName === '' || strlen($worldName) > EntityPersistenceLimits::MAX_WORLD_NAME_BYTES
            || preg_match('//u', $worldName) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $worldName) === 1
            || $revision < 0) {
            throw new InvalidArgumentException('Dormant entity record must be a bounded custom entity record.');
        }
        if (EntityUuid::validate($uuid) !== $uuid) {
            throw new InvalidArgumentException('Dormant entity UUID must be canonical.');
        }
    }

    public function typeIdentifier(): string
    {
        return $this->typeIdentifier;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function worldName(): string
    {
        return $this->worldName;
    }

    public function ownerChunk(): ChunkPosition
    {
        return $this->ownerChunk;
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function encodedRecord(): string
    {
        return $this->encodedRecord;
    }
}
