<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\LevelDb;

use Bedriox\Server\Entity\Persistence\CorruptEntityPersistenceException;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransfer;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferResult;
use Bedriox\Server\Entity\Persistence\EntityPersistenceCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceConflictException;
use Bedriox\Server\Entity\Persistence\EntityPersistenceLimits;
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

    public function transferEntityOwnership(EntityOwnershipTransfer $transfer): EntityOwnershipTransferResult
    {
        $requestedSource = $transfer->sourceAfter;
        $requestedDestination = $transfer->destinationAfter;
        $this->assertWorld($requestedSource);
        $this->assertWorld($requestedDestination);
        $sourceKey = BedrioxEntityKey::chunk($requestedSource->chunk->x, $requestedSource->chunk->z);
        $destinationKey = BedrioxEntityKey::chunk($requestedDestination->chunk->x, $requestedDestination->chunk->z);

        try {
            $sourceBytes = $this->database->get($sourceKey);
            if ($sourceBytes === null) {
                throw new EntityPersistenceConflictException('Entity ownership source snapshot does not exist.');
            }
            $sourceBefore = $this->decodeOwned($requestedSource->chunk, $sourceBytes);
            $destinationBytes = $this->database->get($destinationKey);
            $destinationBefore = $destinationBytes === null
                ? null
                : $this->decodeOwned($requestedDestination->chunk, $destinationBytes);
            $destinationChunkRevision = $destinationBefore === null ? 0 : $destinationBefore->chunkRevision;
            $destinationContainsTarget = $destinationBefore !== null
                && isset($destinationBefore->revisions()[$transfer->uuid]);

            if (!$sourceBefore->containsExactRevision($transfer->uuid, $transfer->expectedEntityRevision)
                || isset($requestedSource->revisions()[$transfer->uuid])
                || $destinationContainsTarget) {
                throw new EntityPersistenceConflictException(sprintf(
                    'Entity ownership transfer for %s expected record revision %d in source chunk %s at chunk revision %d.',
                    $transfer->uuid,
                    $transfer->expectedEntityRevision,
                    $requestedSource->chunk->key(),
                    $sourceBefore->chunkRevision,
                ));
            }
            $moved = self::recordByUuid($requestedDestination, $transfer->uuid);
            if ($moved === null || $moved->revision() <= $transfer->expectedEntityRevision) {
                throw new EntityPersistenceConflictException('Entity ownership transfer is missing its advanced destination record.');
            }
            if ($sourceBefore->chunkRevision >= PHP_INT_MAX
                || $destinationChunkRevision >= PHP_INT_MAX) {
                throw new EntityPersistenceConflictException('Entity ownership transfer chunk revision is exhausted.');
            }
            $sourceRecords = self::recordsByUuid($sourceBefore);
            unset($sourceRecords[$transfer->uuid]);
            $destinationRecords = $destinationBefore === null ? [] : self::recordsByUuid($destinationBefore);
            if (count($destinationRecords) >= EntityPersistenceLimits::MAX_RECORDS) {
                throw new EntityPersistenceConflictException('Entity ownership destination chunk capacity is exhausted.');
            }
            $destinationRecords[$transfer->uuid] = $moved;
            ksort($sourceRecords, SORT_STRING);
            ksort($destinationRecords, SORT_STRING);
            $sourceAfter = new EntityChunkSnapshot(
                $this->worldName,
                $sourceBefore->chunk,
                $sourceBefore->chunkRevision + 1,
                array_values($sourceRecords),
            );
            $destinationAfter = new EntityChunkSnapshot(
                $this->worldName,
                $requestedDestination->chunk,
                $destinationChunkRevision + 1,
                array_values($destinationRecords),
            );

            $this->database->writeBatch([
                $sourceKey => $this->codec->encode($sourceAfter),
                $destinationKey => $this->codec->encode($destinationAfter),
            ], []);
            return new EntityOwnershipTransferResult($sourceAfter, $destinationAfter);
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
            throw new EntityPersistenceConflictException(sprintf(
                'Entity snapshot revision %d is older than durable revision %d for chunk %s.',
                $replacement->chunkRevision,
                $current->chunkRevision,
                $replacement->chunk->key(),
            ));
        }
        if ($replacement->chunkRevision === $current->chunkRevision && !hash_equals($currentBytes, $replacementBytes)) {
            throw new EntityPersistenceConflictException(sprintf(
                'Entity snapshot revision %d differs from durable state for chunk %s.',
                $replacement->chunkRevision,
                $replacement->chunk->key(),
            ));
        }
    }

    /** @return array<string, \Bedriox\Server\Entity\Persistence\PersistentEntityRecord> */
    private static function recordsByUuid(EntityChunkSnapshot $snapshot): array
    {
        $records = [];
        foreach ($snapshot->records() as $record) {
            $records[$record->uuid()] = $record;
        }

        return $records;
    }

    private static function recordByUuid(
        EntityChunkSnapshot $snapshot,
        string $uuid,
    ): ?\Bedriox\Server\Entity\Persistence\PersistentEntityRecord {
        foreach ($snapshot->records() as $record) {
            if ($record->uuid() === $uuid) {
                return $record;
            }
        }

        return null;
    }
}
