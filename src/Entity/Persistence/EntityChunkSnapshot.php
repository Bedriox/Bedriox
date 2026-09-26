<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;

final readonly class EntityChunkSnapshot
{
    /** @var list<PersistentEntityRecord> */
    private array $records;

    /** @var array<string, int> */
    private array $revisions;

    /**
     * @param array<int, PersistentEntityRecord> $records
     */
    public function __construct(
        public string $worldName,
        public ChunkPosition $chunk,
        public int $chunkRevision,
        array $records,
    ) {
        if ($worldName === '' || strlen($worldName) > EntityPersistenceLimits::MAX_WORLD_NAME_BYTES
            || preg_match('//u', $worldName) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $worldName) === 1
            || $chunkRevision < 0 || !array_is_list($records)
            || count($records) > EntityPersistenceLimits::MAX_RECORDS) {
            throw new InvalidArgumentException('Entity chunk snapshot metadata is invalid.');
        }
        $revisions = [];
        foreach ($records as $record) {
            if (EntityUuid::validate($record->uuid()) !== $record->uuid()
                || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $record->typeIdentifier()) !== 1
                || strlen($record->typeIdentifier()) > EntityPersistenceLimits::MAX_IDENTIFIER_BYTES
                || $record->revision() < 0
                || $record->worldName() !== $worldName
                || $record->ownerChunk()->x !== $chunk->x
                || $record->ownerChunk()->z !== $chunk->z
                || isset($revisions[$record->uuid()])) {
                throw new InvalidArgumentException('Entity chunk snapshot contains an invalid owner or duplicate UUID.');
            }
            $revisions[$record->uuid()] = $record->revision();
        }
        $this->records = $records;
        $this->revisions = $revisions;
    }

    /** @return list<PersistentEntityRecord> */
    public function records(): array
    {
        return $this->records;
    }

    /** @return array<string, int> exact entity revisions captured by this snapshot */
    public function revisions(): array
    {
        return $this->revisions;
    }

    public function containsExactRevision(string $uuid, int $revision): bool
    {
        return ($this->revisions[$uuid] ?? null) === $revision;
    }
}
