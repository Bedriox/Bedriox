<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\LevelDb;

use Bedriox\Server\Entity\Persistence\CorruptEntityPersistenceException;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransfer;
use Bedriox\Server\Entity\Persistence\EntityPersistenceCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceConflictException;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;
use Throwable;

/** Revision-checked entity snapshots stored atomically beside terrain in the world's LevelDB. */
final readonly class LevelDbEntityPersistenceStore implements EntityPersistenceStore
{
    public function __construct(
        private LevelDbDatabase $database,
        private EntityPersistenceCodec $codec,
        private string $worldName,
    ) {
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1) {
            throw new \InvalidArgumentException('Entity persistence world name is invalid.');
        }
    }

    public function loadEntityChunk(ChunkPosition $position): ?EntityChunkSnapshot
    {
        try {
            $document = $this->database->get(BedrioxEntityKey::chunk($position->x, $position->z));
        } catch (LevelDbIoException $error) {
            throw new WorldStorageException('Unable to read the chunk entity snapshot from LevelDB.', previous: $error);
        }
        if ($document === null) {
            return null;
        }

        return $this->decodeOwned($position, $document);
    }

    public function saveEntityChunk(EntityChunkSnapshot $snapshot): void
    {
        $this->assertWorld($snapshot);
        $key = BedrioxEntityKey::chunk($snapshot->chunk->x, $snapshot->chunk->z);
        $encoded = $this->codec->encode($snapshot);
        try {
            $currentBytes = $this->database->get($key);
            if ($currentBytes !== null) {
                $current = $this->decodeOwned($snapshot->chunk, $currentBytes);
                $this->assertAdvancesSnapshot($current, $snapshot, $currentBytes, $encoded);
                if ($current->chunkRevision === $snapshot->chunkRevision) {
                    return;
                }
            }
            $this->database->writeBatch([$key => $encoded], []);
        } catch (CorruptEntityPersistenceException|EntityPersistenceConflictException $error) {
            throw $error;
        } catch (LevelDbIoException|LevelDbStorageException $error) {
            throw new WorldStorageException('Unable to atomically save the chunk entity snapshot.', previous: $error);
        }
    }

    public function transferEntityOwnership(EntityOwnershipTransfer $transfer): void
    {
        $sourceAfter = $transfer->sourceAfter;
        $destinationAfter = $transfer->destinationAfter;
        $this->assertWorld($sourceAfter);
        $this->assertWorld($destinationAfter);
        $sourceKey = BedrioxEntityKey::chunk($sourceAfter->chunk->x, $sourceAfter->chunk->z);
        $destinationKey = BedrioxEntityKey::chunk($destinationAfter->chunk->x, $destinationAfter->chunk->z);

        try {
            $sourceBytes = $this->database->get($sourceKey);
            if ($sourceBytes === null) {
                throw new EntityPersistenceConflictException('Entity ownership source snapshot does not exist.');
            }
            $sourceBefore = $this->decodeOwned($sourceAfter->chunk, $sourceBytes);
            $destinationBytes = $this->database->get($destinationKey);
            $destinationBefore = $destinationBytes === null
                ? null
                : $this->decodeOwned($destinationAfter->chunk, $destinationBytes);

            if (!$sourceBefore->containsExactRevision($transfer->uuid, $transfer->expectedEntityRevision)
                || isset($sourceAfter->revisions()[$transfer->uuid])
                || isset($destinationBefore?->revisions()[$transfer->uuid])) {
                throw new EntityPersistenceConflictException('Entity ownership transfer no longer matches durable ownership.');
            }
            $this->assertSnapshotRevisionAdvances($sourceBefore, $sourceAfter);
            if ($destinationBefore !== null) {
                $this->assertSnapshotRevisionAdvances($destinationBefore, $destinationAfter);
            }
            self::assertRetainsExisting($sourceBefore, $sourceAfter, $transfer->uuid);
            if ($destinationBefore !== null) {
                self::assertRetainsExisting($destinationBefore, $destinationAfter);
            }

            $this->database->writeBatch([
                $sourceKey => $this->codec->encode($sourceAfter),
                $destinationKey => $this->codec->encode($destinationAfter),
            ], []);
        } catch (CorruptEntityPersistenceException|EntityPersistenceConflictException $error) {
            throw $error;
        } catch (LevelDbIoException|LevelDbStorageException $error) {
            throw new WorldStorageException('Unable to atomically transfer entity chunk ownership.', previous: $error);
        }
    }

    private function decodeOwned(ChunkPosition $position, string $document): EntityChunkSnapshot
    {
        try {
            $snapshot = $this->codec->decode($document);
        } catch (Throwable $error) {
            throw new CorruptEntityPersistenceException($position, previous: $error);
        }
        if ($snapshot->worldName !== $this->worldName
            || $snapshot->chunk->x !== $position->x || $snapshot->chunk->z !== $position->z) {
            throw new CorruptEntityPersistenceException($position, 'The chunk entity snapshot has the wrong owner.');
        }

        return $snapshot;
    }

    private function assertWorld(EntityChunkSnapshot $snapshot): void
    {
        if ($snapshot->worldName !== $this->worldName) {
            throw new EntityPersistenceConflictException('Entity snapshot belongs to another world.');
        }
    }

    private function assertAdvancesSnapshot(
        EntityChunkSnapshot $current,
        EntityChunkSnapshot $replacement,
        string $currentBytes,
        string $replacementBytes,
    ): void {
        if ($replacement->chunkRevision < $current->chunkRevision) {
            throw new EntityPersistenceConflictException('Entity snapshot revision is stale.');
        }
        if ($replacement->chunkRevision === $current->chunkRevision && !hash_equals($currentBytes, $replacementBytes)) {
            throw new EntityPersistenceConflictException('Entity snapshot revision conflicts with durable state.');
        }
    }

    private function assertSnapshotRevisionAdvances(
        EntityChunkSnapshot $current,
        EntityChunkSnapshot $replacement,
    ): void {
        if ($replacement->chunkRevision <= $current->chunkRevision) {
            throw new EntityPersistenceConflictException('Entity ownership transfer snapshot revision is stale.');
        }
    }

    private static function assertRetainsExisting(
        EntityChunkSnapshot $before,
        EntityChunkSnapshot $after,
        ?string $removedUuid = null,
    ): void {
        foreach ($before->records() as $record) {
            if ($record->uuid() === $removedUuid) {
                continue;
            }
            $afterRevision = $after->revisions()[$record->uuid()] ?? null;
            if (!is_int($afterRevision) || $afterRevision < $record->revision()) {
                throw new EntityPersistenceConflictException('Entity ownership transfer dropped or rolled back another entity.');
            }
        }
    }
}
