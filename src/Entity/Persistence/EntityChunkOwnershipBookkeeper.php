<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;
use RuntimeException;

/** Process-local bookkeeping that keeps every persistent UUID in exactly one owning chunk. */
final class EntityChunkOwnershipBookkeeper
{
    /** @var array<string, EntityChunkOwner> */
    private array $owners = [];

    /** @var array<string, array<string, true>> owner key => UUID set */
    private array $members = [];

    public function register(PersistentEntityRecord $record): void
    {
        $uuid = EntityUuid::validate($record->uuid());
        if ($uuid !== $record->uuid()) {
            throw new InvalidArgumentException('Persistent entity UUID must be canonical.');
        }
        if (isset($this->owners[$uuid])) {
            throw new RuntimeException('Persistent entity UUID is already owned by a chunk.');
        }
        $owner = new EntityChunkOwner($record->worldName(), $record->ownerChunk(), $record->revision());
        $this->owners[$uuid] = $owner;
        $this->members[$owner->key()][$uuid] = true;
    }

    public function owner(string $uuid): EntityChunkOwner
    {
        $uuid = EntityUuid::validate($uuid);

        return $this->owners[$uuid] ?? throw new InvalidArgumentException('Persistent entity UUID has no chunk owner.');
    }

    /**
     * Transfers ownership only if the caller's complete old owner and exact revision still match.
     * Validation finishes before either index is changed.
     */
    public function transfer(
        string $uuid,
        string $expectedWorld,
        ChunkPosition $expectedChunk,
        int $expectedRevision,
        string $newWorld,
        ChunkPosition $newChunk,
        int $newRevision,
    ): EntityChunkOwner {
        $uuid = EntityUuid::validate($uuid);
        $old = $this->owners[$uuid] ?? throw new InvalidArgumentException('Persistent entity UUID has no chunk owner.');
        if ($old->worldName !== $expectedWorld || $old->chunk->x !== $expectedChunk->x
            || $old->chunk->z !== $expectedChunk->z || $old->revision !== $expectedRevision) {
            throw new RuntimeException('Persistent entity ownership transfer is stale.');
        }
        if ($newRevision <= $expectedRevision) {
            throw new InvalidArgumentException('Persistent entity ownership transfer must advance its revision.');
        }
        $new = new EntityChunkOwner($newWorld, $newChunk, $newRevision);

        unset($this->members[$old->key()][$uuid]);
        if (($this->members[$old->key()] ?? []) === []) {
            unset($this->members[$old->key()]);
        }
        $this->members[$new->key()][$uuid] = true;
        $this->owners[$uuid] = $new;

        return $new;
    }

    /** @return list<string> sorted canonical UUIDs */
    public function ownedBy(string $worldName, ChunkPosition $chunk): array
    {
        $owner = new EntityChunkOwner($worldName, $chunk, 0);
        $uuids = array_keys($this->members[$owner->key()] ?? []);
        sort($uuids, SORT_STRING);

        return $uuids;
    }
}
