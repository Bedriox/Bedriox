<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Api\Entity\CustomEntityState;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\RegisteredEntityDefinition;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;
use Closure;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use Throwable;

/**
 * Coordinates loaded entity snapshots with the authoritative runtime registry.
 *
 * A manager belongs to one world and one persistence store. Call activateChunk()
 * after terrain load, synchronize() after entity simulation, persistDirty() from
 * bounded autosave work, and flushShutdown() before closing the world provider.
 */
final class EntityPersistenceManager
{
    public const int MAX_AUTOSAVE_CHUNKS = 1_024;
    public const int MAX_SYNCHRONIZED_ENTITIES = 4_096;
    public const int MAX_ACTIVATED_CHUNKS = 65_536;

    /** @var array<string, ManagedEntityChunkState> */
    private array $chunks = [];

    /** @var array<string, ManagedEntityRuntimeState> */
    private array $runtimeStates = [];

    /** @var array<string, string> UUID => chunk key */
    private array $owners = [];

    /** @var array<string, PendingEntityOwnershipTransfer> UUID => in-flight transfer */
    private array $pendingOwnershipTransfers = [];

    /** @var array<string, int> Chunk key => number of in-flight transfers using the chunk */
    private array $pendingOwnershipTransferChunks = [];

    /** @var array<string, PendingEntityChunkSave> Chunk key => in-flight immutable autosave */
    private array $pendingChunkSaves = [];

    /** @var array<string, true> */
    private array $corruptChunks = [];

    private int $synchronizationCursor = 0;

    /** @var array<string, true> Chunk keys captured for the current finite autosave generation. */
    private array $autosavePending = [];

    private int $autosaveGeneration = 0;

    /** @var list<array{uuid: string, source: ChunkPosition, destination: ChunkPosition, code: string, detail: string}> */
    private array $ownershipTransferFailures = [];

    /** @var Closure(EntityPersistenceRecord, RegisteredEntityDefinition, EntityRegistry): (?AbstractEntity) */
    private readonly Closure $activator;

    /** @var null|Closure(EntityPersistenceRecord, AbstractEntity): bool */
    private readonly ?Closure $afterActivation;

    /** @var null|Closure(AbstractEntity): (?CustomEntityState) */
    private readonly ?Closure $customStateEncoder;

    /** @var null|Closure(AbstractEntity): void */
    private readonly ?Closure $afterDeactivation;

    /**
     * @param null|Closure(EntityPersistenceRecord, RegisteredEntityDefinition, EntityRegistry): (?AbstractEntity) $activator
     * @param null|Closure(EntityPersistenceRecord, AbstractEntity): bool $afterActivation
     * @param null|Closure(AbstractEntity): (?CustomEntityState) $customStateEncoder
     * @param null|Closure(AbstractEntity): void $afterDeactivation
     */
    public function __construct(
        private readonly string $worldName,
        private readonly EntityRegistry $registry,
        private readonly EntityDefinitionRegistry $definitions,
        private readonly EntityPersistenceStore $store,
        ?Closure $activator = null,
        ?Closure $afterActivation = null,
        ?Closure $customStateEncoder = null,
        ?Closure $afterDeactivation = null,
        private readonly WorldDimension $dimension = WorldDimension::OVERWORLD,
    ) {
        if ($worldName === '' || strlen($worldName) > EntityPersistenceLimits::MAX_WORLD_NAME_BYTES
            || preg_match('//u', $worldName) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $worldName) === 1) {
            throw new InvalidArgumentException('Entity persistence manager world name is invalid.');
        }
        $this->activator = $activator ?? $this->activateFromDefinition(...);
        $this->afterActivation = $afterActivation;
        $this->customStateEncoder = $customStateEncoder;
        $this->afterDeactivation = $afterDeactivation;
    }

    public function activateChunk(ChunkPosition $chunk): EntityChunkActivationResult
    {
        $key = $chunk->key();
        if (isset($this->chunks[$key])) {
            return new EntityChunkActivationResult($chunk, true, false, 0, 0, 0, 0);
        }
        if (isset($this->corruptChunks[$key])) {
            return new EntityChunkActivationResult($chunk, true, true, 0, 0, 0, 0);
        }
        if (count($this->chunks) + count($this->corruptChunks) >= self::MAX_ACTIVATED_CHUNKS) {
            throw new OverflowException('Entity persistence activated-chunk capacity is exhausted.');
        }

        try {
            $snapshot = $this->store->loadEntityChunk($chunk, $this->dimension);
        } catch (CorruptEntityPersistenceException) {
            $this->corruptChunks[$key] = true;

            return new EntityChunkActivationResult($chunk, false, true, 0, 0, 0, 0);
        }

        if ($snapshot !== null && ($snapshot->worldName !== $this->worldName
            || $snapshot->chunk->x !== $chunk->x || $snapshot->chunk->z !== $chunk->z)) {
            $this->corruptChunks[$key] = true;

            return new EntityChunkActivationResult($chunk, false, true, 0, 0, 0, 0);
        }

        $records = [];
        $chunkRevision = 0;
        $loadedRecords = [];
        if ($snapshot !== null) {
            $chunkRevision = $snapshot->chunkRevision;
            $loadedRecords = $snapshot->records();
        }
        foreach ($loadedRecords as $record) {
            if (isset($this->owners[$record->uuid()])) {
                $this->corruptChunks[$key] = true;

                return new EntityChunkActivationResult($chunk, false, true, 0, 0, 0, 0);
            }
            $records[$record->uuid()] = $record;
        }
        $state = new ManagedEntityChunkState($chunk, $chunkRevision, $records);
        $this->chunks[$key] = $state;
        foreach ($records as $uuid => $_record) {
            $this->owners[$uuid] = $key;
        }

        $activated = 0;
        $dormant = 0;
        $inactive = 0;
        $failed = 0;
        foreach ($records as $uuid => $record) {
            if ($record instanceof DormantEntityRecord) {
                if ($this->definitions->get($record->typeIdentifier()) === null) {
                    ++$dormant;
                    continue;
                }
                try {
                    $record = (new EntityPersistenceCodec(array_map(
                        static fn(RegisteredEntityDefinition $definition): string => $definition->definition->type->identifier(),
                        $this->definitions->all(),
                    )))->materializeDormant($record);
                    $state->records[$uuid] = $record;
                } catch (Throwable) {
                    ++$failed;
                    continue;
                }
            }
            if (!$record instanceof EntityPersistenceRecord) {
                ++$failed;
                continue;
            }
            $registration = $this->definitions->get($record->typeIdentifier());
            if ($registration === null) {
                ++$inactive;
                continue;
            }
            if ($this->registry->getByUniqueId($record->uuid()) !== null) {
                ++$failed;
                continue;
            }
            $entity = null;
            try {
                $entity = ($this->activator)($record, $registration, $this->registry);
                if ($entity === null) {
                    $registered = $this->registry->getByUniqueId($record->uuid());
                    if ($registered !== null) {
                        $this->registry->remove($registered->getRuntimeId());
                    }
                    ++$inactive;
                    continue;
                }
                $this->assertActivatedEntity($record, $entity);
                if ($this->afterActivation !== null && !($this->afterActivation)($record, $entity)) {
                    $this->registry->remove($entity->getRuntimeId());
                    ++$inactive;
                    continue;
                }
                $this->runtimeStates[$record->uuid()] = new ManagedEntityRuntimeState(
                    $record,
                    $entity->getRuntimeId(),
                    $entity->revision(),
                    $entity->ageTicks(),
                    $record->ageTicks,
                    true,
                );
                ++$activated;
            } catch (Throwable) {
                if ($entity !== null
                    && $this->registry->getByRuntimeId($entity->getRuntimeId()) === $entity) {
                    $this->registry->remove($entity->getRuntimeId());
                }
                $registered = $this->registry->getByUniqueId($record->uuid());
                if ($registered !== null) {
                    $this->registry->remove($registered->getRuntimeId());
                }
                ++$failed;
            }
        }

        return new EntityChunkActivationResult($chunk, false, false, $activated, $dormant, $inactive, $failed);
    }

    /**
     * Registers a newly spawned persistent entity in its current owner chunk.
     * The entity must already belong to the authoritative EntityRegistry.
     *
     * @param array<int, EntityEquipmentEntry> $equipment
     */
    public function registerSpawned(
        AbstractEntity $entity,
        int|string|null $variant = null,
        array $equipment = [],
        int $customSchemaVersion = 0,
        string $customData = '',
    ): void {
        if (!$entity->isPersistent() || $entity->isRemoved()
            || $entity->getWorldName() !== $this->worldName
            || $this->registry->getByUniqueId($entity->getUniqueId()) !== $entity) {
            throw new InvalidArgumentException('Only a live, registered persistent entity in this world can be tracked.');
        }
        if (isset($this->owners[$entity->getUniqueId()])) {
            throw new LogicException('Persistent entity UUID is already tracked.');
        }
        if ($equipment !== []) {
            if (!$entity instanceof AbstractLivingEntity) {
                throw new InvalidArgumentException('Only living entities can own equipment.');
            }
            foreach ($equipment as $entry) {
                $entity->equipmentState()->restoreItem(
                    $entry->equipmentSlot(),
                    $entry->itemStack(),
                    $entry->dropChance,
                );
            }
        }

        $chunk = self::chunkAt($entity);
        $activation = $this->activateChunk($chunk);
        if ($activation->corrupt) {
            throw new CorruptEntityPersistenceException($chunk);
        }
        $state = $this->chunks[$chunk->key()];
        if (count($state->records) >= EntityPersistenceLimits::MAX_RECORDS) {
            throw new OverflowException('Entity persistence chunk capacity is exhausted.');
        }
        $record = $this->recordFromEntity(
            $entity,
            $chunk,
            $entity->revision(),
            $entity->ageTicks(),
            $variant,
            $customSchemaVersion,
            $customData,
        );
        $uuid = $entity->getUniqueId();
        $state->records[$uuid] = $record;
        $state->dirty = true;
        ++$state->mutationRevision;
        $this->owners[$uuid] = $chunk->key();
        $this->runtimeStates[$uuid] = new ManagedEntityRuntimeState(
            $record,
            $entity->getRuntimeId(),
            $entity->revision(),
            $entity->ageTicks(),
            $entity->ageTicks(),
            false,
        );
    }

    /**
     * Observes up to the supplied number of managed runtime entities.
     * Cross-chunk moves of durable entities are committed through one atomic store transfer.
     */
    public function synchronize(
        int $maximumEntities = self::MAX_SYNCHRONIZED_ENTITIES,
        int $maximumOwnershipTransfers = 4,
    ): EntityPersistenceSynchronizationResult {
        if ($maximumEntities < 1 || $maximumEntities > self::MAX_SYNCHRONIZED_ENTITIES) {
            throw new InvalidArgumentException('Entity persistence synchronization limit is outside its supported range.');
        }
        if ($maximumOwnershipTransfers < 0 || $maximumOwnershipTransfers > 256) {
            throw new InvalidArgumentException('Entity persistence ownership-transfer limit is outside its supported range.');
        }

        $this->collectOwnershipTransferCompletions();
        $uuids = array_keys($this->runtimeStates);
        sort($uuids, SORT_STRING);
        $count = count($uuids);
        $selectedCount = min($maximumEntities, $count);
        $selected = [];
        if ($count > 0) {
            $start = $this->synchronizationCursor % $count;
            for ($offset = 0; $offset < $selectedCount; ++$offset) {
                $selected[] = $uuids[($start + $offset) % $count];
            }
            $this->synchronizationCursor = ($start + $selectedCount) % $count;
        }
        $transferred = 0;
        $deferredTransfers = 0;
        $removed = 0;
        $failed = 0;
        foreach ($selected as $uuid) {
            try {
                if ($this->requiresOwnershipTransfer($uuid) && $transferred >= $maximumOwnershipTransfers) {
                    ++$deferredTransfers;
                    continue;
                }
                $result = $this->synchronizeEntity($uuid);
                $transferred += $result === 1 ? 1 : 0;
                $removed += $result === 2 ? 1 : 0;
                $deferredTransfers += $result === 3 ? 1 : 0;
            } catch (Throwable) {
                $this->markOwnerDirty($uuid);
                ++$failed;
            }
        }

        return new EntityPersistenceSynchronizationResult(
            count($selected),
            $transferred,
            $removed,
            $failed,
            $selectedCount === $count,
            $deferredTransfers,
        );
    }

    /**
     * Captures a finite autosave generation. Runtime age is checkpointed here,
     * rather than turning every simulation tick into new persistence work.
     */
    public function beginAutosaveGeneration(): int
    {
        if ($this->autosavePending !== []) {
            return count($this->autosavePending);
        }
        $this->checkpointRuntimeAges();
        $keys = array_keys(array_filter(
            $this->chunks,
            fn(ManagedEntityChunkState $state): bool => $state->dirty
                && !isset($this->pendingOwnershipTransferChunks[$state->chunk->key()]),
        ));
        sort($keys, SORT_STRING);
        $this->autosavePending = array_fill_keys($keys, true);
        if ($this->autosaveGeneration < PHP_INT_MAX) {
            ++$this->autosaveGeneration;
        }

        return count($this->autosavePending);
    }

    /** Saves only chunks captured by beginAutosaveGeneration(). */
    public function persistAutosaveGeneration(int $maximumChunks): EntityPersistenceFlushResult
    {
        if ($maximumChunks < 1 || $maximumChunks > self::MAX_AUTOSAVE_CHUNKS) {
            throw new InvalidArgumentException('Entity persistence autosave batch is outside its supported range.');
        }
        $completionResult = $this->collectEntityChunkSaveCompletions();
        $keys = array_slice(array_keys($this->autosavePending), 0, $maximumChunks);
        $keys = array_values(array_filter(
            $keys,
            fn(string $key): bool => !isset($this->pendingOwnershipTransferChunks[$key])
                && !isset($this->pendingChunkSaves[$key]),
        ));
        if ($keys === []) {
            return $completionResult;
        }

        $attempted = $completionResult->attemptedChunks;
        $saved = $completionResult->savedChunks;
        $failed = $completionResult->failedChunks();
        $failureDetails = $completionResult->failureDetails();
        foreach ($keys as $key) {
            $state = $this->chunks[$key] ?? null;
            if ($state === null || !$state->dirty) {
                unset($this->autosavePending[$key]);
                continue;
            }
            if ($this->chunkHasUnsettledOwnership($state)) {
                unset($this->autosavePending[$key]);
                $this->autosavePending[$key] = true;
                continue;
            }
            try {
                $snapshot = $this->snapshot($state);
                if ($this->store instanceof AsynchronousEntityPersistenceStore) {
                    $submission = $this->store->enqueueEntityChunkSave($snapshot, $this->dimension);
                    if ($submission->status === PersistenceSubmission::SATURATED) {
                        unset($this->autosavePending[$key]);
                        $this->autosavePending[$key] = true;
                        break;
                    }
                    if ($submission->status === PersistenceSubmission::STALE) {
                        throw new LogicException('Entity autosave snapshot was stale without matching manager state.');
                    }
                    $this->pendingChunkSaves[$key] = new PendingEntityChunkSave(
                        $snapshot,
                        $state->mutationRevision,
                        $this->captureSnapshotRuntimeBaselines($snapshot),
                    );
                    ++$attempted;
                    continue;
                }
                $this->store->saveEntityChunk($snapshot, $this->dimension);
                $this->acknowledge($state, $snapshot);
                unset($this->autosavePending[$key]);
                ++$attempted;
                ++$saved;
            } catch (Throwable $error) {
                ++$attempted;
                unset($this->autosavePending[$key]);
                $this->autosavePending[$key] = true;
                $failed[] = $state->chunk;
                $failureDetails[] = self::failureDetail($state->chunk, 'autosave', $error);
            }
        }

        return new EntityPersistenceFlushResult($attempted, $saved, $failed, $failureDetails);
    }

    public function pendingAutosaveChunkCount(): int
    {
        return count($this->autosavePending);
    }

    public function autosaveGeneration(): int
    {
        return $this->autosaveGeneration;
    }

    /** @return list<array{uuid: string, source: ChunkPosition, destination: ChunkPosition, code: string, detail: string}> */
    public function drainOwnershipTransferFailures(): array
    {
        $failures = $this->ownershipTransferFailures;
        $this->ownershipTransferFailures = [];

        return $failures;
    }

    /** Saves at most $maximumChunks dirty chunks and retains failed work for a later retry. */
    public function persistDirty(int $maximumChunks): EntityPersistenceFlushResult
    {
        if ($maximumChunks < 1 || $maximumChunks > self::MAX_AUTOSAVE_CHUNKS) {
            throw new InvalidArgumentException('Entity persistence autosave batch is outside its supported range.');
        }
        $keys = array_keys(array_filter(
            $this->chunks,
            fn(ManagedEntityChunkState $state): bool => $state->dirty
                && !isset($this->pendingOwnershipTransferChunks[$state->chunk->key()]),
        ));
        sort($keys, SORT_STRING);
        $keys = array_slice($keys, 0, $maximumChunks);
        return $this->persistKeys($keys);
    }

    /** Synchronizes every active record and attempts every dirty chunk exactly once. */
    public function flushShutdown(): EntityPersistenceFlushResult
    {
        $pendingSaves = $this->drainEntityChunkSaves();
        $this->drainOwnershipTransfers();
        $uuids = array_keys($this->runtimeStates);
        sort($uuids, SORT_STRING);
        foreach ($uuids as $uuid) {
            try {
                $this->synchronizeEntity($uuid, false);
            } catch (Throwable) {
                $this->markOwnerDirty($uuid);
            }
        }
        $this->checkpointRuntimeAges();
        $this->autosavePending = [];

        $keys = array_keys(array_filter(
            $this->chunks,
            static fn(ManagedEntityChunkState $state): bool => $state->dirty,
        ));
        if ($keys === []) {
            return $pendingSaves;
        }
        sort($keys, SORT_STRING);

        $remaining = $this->persistKeys($keys);

        return new EntityPersistenceFlushResult(
            $pendingSaves->attemptedChunks + $remaining->attemptedChunks,
            $pendingSaves->savedChunks + $remaining->savedChunks,
            [...$pendingSaves->failedChunks(), ...$remaining->failedChunks()],
            [...$pendingSaves->failureDetails(), ...$remaining->failureDetails()],
        );
    }

    /** Saves and releases one chunk. Returns the number of runtime entities removed. */
    public function unloadChunk(ChunkPosition $chunk): int
    {
        $key = $chunk->key();
        if (isset($this->pendingChunkSaves[$key])) {
            $this->drainEntityChunkSaves();
        }
        if (isset($this->pendingOwnershipTransferChunks[$key])) {
            $this->drainOwnershipTransfers();
        }
        if (isset($this->corruptChunks[$key])) {
            unset($this->corruptChunks[$key]);

            return 0;
        }
        $state = $this->chunks[$key] ?? null;
        if ($state === null) {
            return 0;
        }
        foreach (array_keys($state->records) as $uuid) {
            if (isset($this->runtimeStates[$uuid])) {
                $this->synchronizeEntity($uuid, false);
            }
        }
        if ($state->dirty) {
            $snapshot = $this->snapshot($state);
            $this->store->saveEntityChunk($snapshot, $this->dimension);
            $this->acknowledge($state, $snapshot);
        }
        unset($this->autosavePending[$key]);

        $removed = 0;
        foreach (array_keys($state->records) as $uuid) {
            $runtime = $this->runtimeStates[$uuid] ?? null;
            if ($runtime !== null) {
                $entity = $this->registry->remove($runtime->runtimeId);
                if ($entity !== null) {
                    ++$removed;
                    try {
                        ($this->afterDeactivation)?->__invoke($entity);
                    } catch (Throwable) {
                        // Persistence unload must complete after isolated plugin notification failures.
                    }
                }
                unset($this->runtimeStates[$uuid]);
            }
            unset($this->owners[$uuid]);
        }
        unset($this->chunks[$key]);

        return $removed;
    }

    /** Removes a despawned entity from its next persisted owner snapshot. */
    public function forgetEntity(string $uuid): bool
    {
        $uuid = EntityUuid::validate($uuid);
        $key = $this->owners[$uuid] ?? null;
        $runtime = $this->runtimeStates[$uuid] ?? null;
        if ($key === null || $runtime === null) {
            return false;
        }
        $state = $this->chunks[$key] ?? throw new LogicException('Entity persistence owner chunk is unavailable.');
        unset($state->records[$uuid], $this->runtimeStates[$uuid], $this->owners[$uuid]);
        $state->dirty = true;
        ++$state->mutationRevision;

        return true;
    }

    /**
     * Stops runtime tracking without deleting the durable record.
     *
     * This is used when an owning plugin becomes unavailable. The last valid
     * record remains chunk-owned and can be activated again when a compatible
     * definition is registered.
     */
    public function deactivateEntity(string $uuid): bool
    {
        EntityUuid::validate($uuid);
        $runtime = $this->runtimeStates[$uuid] ?? null;
        $ownerKey = $this->owners[$uuid] ?? null;
        if ($runtime === null || $ownerKey === null) {
            return false;
        }
        $entity = $this->registry->getByRuntimeId($runtime->runtimeId);
        if ($entity !== null && !$entity->isRemoved()) {
            try {
                $this->synchronizeEntity($uuid, false);
                $runtime = $this->runtimeStates[$uuid];
                $ownerKey = $this->owners[$uuid];
                $owner = $this->chunks[$ownerKey] ?? null;
                if ($owner !== null) {
                    $owner->records[$uuid] = $this->currentRecord($runtime, $entity, $owner->chunk);
                    $owner->dirty = true;
                    ++$owner->mutationRevision;
                }
            } catch (Throwable) {
                // Preserve the last known-good record if plugin state can no longer be encoded.
            }
        }
        unset($this->runtimeStates[$uuid]);

        return true;
    }

    public function isChunkActivated(ChunkPosition $chunk): bool
    {
        return isset($this->chunks[$chunk->key()]);
    }

    public function isChunkCorrupt(ChunkPosition $chunk): bool
    {
        return isset($this->corruptChunks[$chunk->key()]);
    }

    public function dirtyChunkCount(): int
    {
        return count(array_filter(
            $this->chunks,
            static fn(ManagedEntityChunkState $state): bool => $state->dirty,
        ));
    }

    public function activeEntityCount(): int
    {
        return count($this->runtimeStates);
    }

    public function dormantRecordCount(): int
    {
        $count = 0;
        foreach ($this->chunks as $state) {
            foreach ($state->records as $record) {
                $count += $record instanceof DormantEntityRecord ? 1 : 0;
            }
        }

        return $count;
    }

    private function synchronizeEntity(string $uuid, bool $allowAsynchronousTransfer = true): int
    {
        if (isset($this->pendingOwnershipTransfers[$uuid])) {
            return 0;
        }
        $runtime = $this->runtimeStates[$uuid] ?? null;
        if ($runtime === null) {
            return 0;
        }
        $entity = $this->registry->getByRuntimeId($runtime->runtimeId);
        if ($entity === null || $entity->isRemoved()) {
            $this->forgetEntity($uuid);

            return 2;
        }
        if ($entity->getUniqueId() !== $uuid || $entity->getWorldName() !== $this->worldName) {
            throw new LogicException('Managed entity identity or world changed outside persistence ownership.');
        }

        $sourceKey = $this->owners[$uuid] ?? throw new LogicException('Managed entity has no persistence owner.');
        $source = $this->chunks[$sourceKey] ?? throw new LogicException('Managed entity owner chunk is unavailable.');
        $destinationChunk = self::chunkAt($entity);
        if ($source->chunk->x === $destinationChunk->x && $source->chunk->z === $destinationChunk->z) {
            if ($entity->revision() !== $runtime->runtimeRevisionBaseline) {
                $source->dirty = true;
                ++$source->mutationRevision;
            }

            return 0;
        }

        $activation = $this->activateChunk($destinationChunk);
        if ($activation->corrupt) {
            throw new CorruptEntityPersistenceException($destinationChunk);
        }
        $destinationKey = $destinationChunk->key();
        $destination = $this->chunks[$destinationKey];
        if (count($destination->records) >= EntityPersistenceLimits::MAX_RECORDS) {
            throw new OverflowException('Destination entity persistence chunk capacity is exhausted.');
        }
        $moved = $this->currentRecord($runtime, $entity, $destinationChunk);

        if ($runtime->durablyStored) {
            $sourceAfter = $this->snapshot($source, $uuid);
            $destinationAfter = $this->snapshot($destination, null, $moved);
            $transfer = new EntityOwnershipTransfer(
                $uuid,
                $runtime->record->revision(),
                $sourceAfter,
                $destinationAfter,
            );
            $runtimeBaselines = $this->captureRuntimeBaselines($transfer);
            if ($allowAsynchronousTransfer && $this->store instanceof AsynchronousEntityPersistenceStore) {
                $sourceKey = $source->chunk->key();
                if (isset($this->pendingOwnershipTransferChunks[$sourceKey])
                    || isset($this->pendingOwnershipTransferChunks[$destinationKey])
                    || isset($this->pendingChunkSaves[$sourceKey])
                    || isset($this->pendingChunkSaves[$destinationKey])
                    || !$this->store->enqueueEntityOwnershipTransfer($transfer, $this->dimension)) {
                    return 3;
                }
                $this->pendingOwnershipTransfers[$uuid] = new PendingEntityOwnershipTransfer(
                    $transfer,
                    $sourceKey,
                    $destinationKey,
                    $source->mutationRevision,
                    $destination->mutationRevision,
                    $entity->revision(),
                    $entity->ageTicks(),
                    $runtimeBaselines,
                );
                $this->pendingOwnershipTransferChunks[$sourceKey] = 1;
                $this->pendingOwnershipTransferChunks[$destinationKey] = 1;

                return 1;
            }
            $committed = $this->store->transferEntityOwnership($transfer, $this->dimension);
            unset($source->records[$uuid]);
            $source->dirty = $this->reconcileOwnershipTransferRecords(
                $source,
                $sourceAfter,
                $committed->sourceAfter,
                $runtimeBaselines,
                $uuid,
            );
            $committedMoved = $this->recordByUuid($committed->destinationAfter, $uuid);
            if (!$committedMoved instanceof EntityPersistenceRecord) {
                throw new LogicException('Committed entity ownership transfer has no materialized destination record.');
            }
            $destination->records[$uuid] = $committedMoved;
            $destination->dirty = $this->reconcileOwnershipTransferRecords(
                $destination,
                $destinationAfter,
                $committed->destinationAfter,
                $runtimeBaselines,
                $uuid,
            );
            $this->owners[$uuid] = $destinationKey;
            $runtime->record = $committedMoved;
            $runtime->runtimeRevisionBaseline = $entity->revision();
            $runtime->runtimeAgeBaseline = $entity->ageTicks();
            $runtime->persistedAgeBaseline = $runtime->record->ageTicks;
            $runtime->durablyStored = true;
        } else {
            unset($source->records[$uuid]);
            $destination->records[$uuid] = $moved;
            $source->dirty = true;
            $destination->dirty = true;
            ++$source->mutationRevision;
            ++$destination->mutationRevision;
            $this->owners[$uuid] = $destinationKey;
            $runtime->record = $moved;
            $runtime->runtimeRevisionBaseline = $entity->revision();
            $runtime->runtimeAgeBaseline = $entity->ageTicks();
            $runtime->persistedAgeBaseline = $moved->ageTicks;
        }

        return 1;
    }

    private function requiresOwnershipTransfer(string $uuid): bool
    {
        if (isset($this->pendingOwnershipTransfers[$uuid])) {
            return false;
        }
        $runtime = $this->runtimeStates[$uuid] ?? null;
        if ($runtime === null) {
            return false;
        }
        $entity = $this->registry->getByRuntimeId($runtime->runtimeId);
        $sourceKey = $this->owners[$uuid] ?? null;

        return $entity !== null
            && !$entity->isRemoved()
            && $sourceKey !== null
            && self::chunkAt($entity)->key() !== $sourceKey;
    }

    private function chunkHasUnsettledOwnership(ManagedEntityChunkState $state): bool
    {
        $ownerKey = $state->chunk->key();
        foreach (array_keys($state->records) as $uuid) {
            $runtime = $this->runtimeStates[$uuid] ?? null;
            if ($runtime === null) {
                continue;
            }
            $entity = $this->registry->getByRuntimeId($runtime->runtimeId);
            if ($entity !== null && !$entity->isRemoved() && self::chunkAt($entity)->key() !== $ownerKey) {
                return true;
            }
        }

        return false;
    }

    private function checkpointRuntimeAges(): void
    {
        foreach ($this->runtimeStates as $uuid => $runtime) {
            $entity = $this->registry->getByRuntimeId($runtime->runtimeId);
            if ($entity !== null && !$entity->isRemoved()
                && $entity->ageTicks() !== $runtime->runtimeAgeBaseline) {
                $this->markOwnerDirty($uuid);
            }
        }
    }

    private function collectOwnershipTransferCompletions(int $maximumCompletions = 256): void
    {
        if (!$this->store instanceof AsynchronousEntityPersistenceStore) {
            return;
        }
        $this->acceptOwnershipTransferCompletions(
            $this->store->pollEntityOwnershipTransfers($maximumCompletions, $this->dimension),
        );
    }

    private function collectEntityChunkSaveCompletions(int $maximumCompletions = 256): EntityPersistenceFlushResult
    {
        if (!$this->store instanceof AsynchronousEntityPersistenceStore) {
            return new EntityPersistenceFlushResult(0, 0, []);
        }

        return $this->acceptEntityChunkSaveCompletions(
            $this->store->pollEntityChunkSaves($maximumCompletions, $this->dimension),
        );
    }

    private function drainEntityChunkSaves(): EntityPersistenceFlushResult
    {
        if (!$this->store instanceof AsynchronousEntityPersistenceStore || $this->pendingChunkSaves === []) {
            return new EntityPersistenceFlushResult(0, 0, []);
        }
        $expectedCompletions = count($this->pendingChunkSaves);
        $completions = $this->store->drainEntityChunkSaves(30_000, $this->dimension);
        $result = $this->acceptEntityChunkSaveCompletions($completions);
        if (count($completions) !== $expectedCompletions) {
            throw new LogicException('Entity chunk save drain did not resolve every pending snapshot.');
        }

        return $result;
    }

    /** @param list<EntityChunkSaveCompletion> $completions */
    private function acceptEntityChunkSaveCompletions(array $completions): EntityPersistenceFlushResult
    {
        $saved = 0;
        $failed = [];
        $failureDetails = [];
        foreach ($completions as $completion) {
            $key = $completion->chunk->key();
            $pending = $this->pendingChunkSaves[$key] ?? null;
            if (!$pending instanceof PendingEntityChunkSave
                || $pending->snapshot->chunkRevision !== $completion->revision) {
                throw new LogicException('Entity chunk save completion did not match pending state.');
            }
            unset($this->pendingChunkSaves[$key]);
            $state = $this->chunks[$key] ?? null;
            if (!$completion->successful) {
                if ($state !== null) {
                    $state->dirty = true;
                    unset($this->autosavePending[$key]);
                    $this->autosavePending[$key] = true;
                }
                $failed[] = $completion->chunk;
                $failureDetails[] = [
                    'chunk' => $completion->chunk,
                    'operation' => 'autosave',
                    'exception' => $completion->failureCode === 'entity_persistence_conflict'
                        ? EntityPersistenceConflictException::class
                        : WorldStorageException::class,
                    'detail' => $completion->failureDetail ?? $completion->failureCode ?? 'storage_failure',
                ];
                continue;
            }
            if ($state !== null) {
                $changed = $state->mutationRevision !== $pending->mutationRevision;
                $state->revision = $pending->snapshot->chunkRevision;
                $this->rebaseTransferRecords($state, $pending->snapshot, $pending->runtimeBaselines);
                $state->dirty = $changed;
            }
            unset($this->autosavePending[$key]);
            ++$saved;
        }

        return new EntityPersistenceFlushResult(count($completions), $saved, $failed, $failureDetails);
    }

    private function drainOwnershipTransfers(): void
    {
        if (!$this->store instanceof AsynchronousEntityPersistenceStore
            || $this->pendingOwnershipTransfers === []) {
            return;
        }
        $this->acceptOwnershipTransferCompletions(
            $this->store->drainEntityOwnershipTransfers(30_000, $this->dimension),
        );
        if ($this->pendingOwnershipTransfers !== []) {
            throw new LogicException('Entity ownership transfer drain did not resolve every pending transfer.');
        }
    }

    /** @param list<EntityOwnershipTransferCompletion> $completions */
    private function acceptOwnershipTransferCompletions(array $completions): void
    {
        foreach ($completions as $completion) {
            $uuid = $completion->transfer->uuid;
            $pending = $this->pendingOwnershipTransfers[$uuid] ?? null;
            if (!$pending instanceof PendingEntityOwnershipTransfer
                || $pending->transfer->sourceAfter->chunkRevision !== $completion->transfer->sourceAfter->chunkRevision
                || $pending->transfer->destinationAfter->chunkRevision !== $completion->transfer->destinationAfter->chunkRevision) {
                throw new LogicException('Entity ownership transfer completion did not match pending state.');
            }
            unset($this->pendingOwnershipTransfers[$uuid]);
            $this->releaseOwnershipTransferChunk($pending->sourceKey);
            $this->releaseOwnershipTransferChunk($pending->destinationKey);

            $source = $this->chunks[$pending->sourceKey] ?? null;
            $destination = $this->chunks[$pending->destinationKey] ?? null;
            if (!$completion->successful || $source === null || $destination === null) {
                if (!$completion->successful) {
                    $this->ownershipTransferFailures[] = [
                        'uuid' => $uuid,
                        'source' => $completion->transfer->sourceAfter->chunk,
                        'destination' => $completion->transfer->destinationAfter->chunk,
                        'code' => $completion->failureCode ?? 'storage_failure',
                        'detail' => $completion->failureDetail ?? '',
                    ];
                    if (count($this->ownershipTransferFailures) > 256) {
                        array_shift($this->ownershipTransferFailures);
                    }
                }
                if ($source !== null) {
                    $source->dirty = true;
                    ++$source->mutationRevision;
                }
                if ($destination !== null) {
                    $destination->dirty = true;
                    ++$destination->mutationRevision;
                }
                continue;
            }

            $committed = $completion->result;
            if (!$committed instanceof EntityOwnershipTransferResult) {
                throw new LogicException('Successful entity ownership transfer has no committed snapshots.');
            }
            $moved = $this->recordByUuid($committed->destinationAfter, $uuid);
            if (!$moved instanceof EntityPersistenceRecord) {
                throw new LogicException('Completed entity ownership transfer has no materialized destination record.');
            }

            $sourceChanged = $source->mutationRevision !== $pending->sourceMutationRevision;
            $destinationChanged = $destination->mutationRevision !== $pending->destinationMutationRevision;
            unset($source->records[$uuid]);
            $source->dirty = $this->reconcileOwnershipTransferRecords(
                $source,
                $pending->transfer->sourceAfter,
                $committed->sourceAfter,
                $pending->runtimeBaselines,
                $uuid,
                $sourceChanged,
            );
            ++$source->mutationRevision;

            $runtime = $this->runtimeStates[$uuid] ?? null;
            if ($runtime === null) {
                unset($destination->records[$uuid], $this->owners[$uuid]);
                $destination->dirty = true;
                ++$destination->mutationRevision;
                $this->reconcileOwnershipTransferRecords(
                    $destination,
                    $pending->transfer->destinationAfter,
                    $committed->destinationAfter,
                    $pending->runtimeBaselines,
                    $uuid,
                    true,
                );
                continue;
            }

            $destination->records[$uuid] = $moved;
            $destination->dirty = $this->reconcileOwnershipTransferRecords(
                $destination,
                $pending->transfer->destinationAfter,
                $committed->destinationAfter,
                $pending->runtimeBaselines,
                $uuid,
                $destinationChanged,
            );
            ++$destination->mutationRevision;
            $this->owners[$uuid] = $pending->destinationKey;
            $this->rebaseRuntimeRecord(
                $runtime,
                $moved,
                $pending->runtimeRevisionBaseline,
                $pending->runtimeAgeBaseline,
            );

            if (!$source->dirty) {
                unset($this->autosavePending[$pending->sourceKey]);
            }
            if (!$destination->dirty) {
                unset($this->autosavePending[$pending->destinationKey]);
            }
        }
    }

    private function releaseOwnershipTransferChunk(string $key): void
    {
        $remaining = ($this->pendingOwnershipTransferChunks[$key] ?? 0) - 1;
        if ($remaining <= 0) {
            unset($this->pendingOwnershipTransferChunks[$key]);
        } else {
            $this->pendingOwnershipTransferChunks[$key] = $remaining;
        }
    }

    /**
     * Captures the runtime revisions used to construct every record in an
     * asynchronous transfer. The complete source and destination snapshots are
     * durable on success, so every included runtime record must advance to the
     * same persistence baseline as the storage owner.
     *
     * @return array<string, array{revision: int, age: int}>
     */
    private function captureRuntimeBaselines(EntityOwnershipTransfer $transfer): array
    {
        $baselines = [];
        foreach ([$transfer->sourceAfter, $transfer->destinationAfter] as $snapshot) {
            foreach ($snapshot->records() as $record) {
                $runtime = $this->runtimeStates[$record->uuid()] ?? null;
                if ($runtime === null) {
                    continue;
                }
                $entity = $this->registry->getByRuntimeId($runtime->runtimeId);
                if ($entity === null || $entity->isRemoved()
                    || self::chunkAt($entity)->key() !== $snapshot->chunk->key()) {
                    continue;
                }
                $baselines[$record->uuid()] = [
                    'revision' => $entity->revision(),
                    'age' => $entity->ageTicks(),
                ];
            }
        }

        return $baselines;
    }

    /** @return array<string, array{revision: int, age: int}> */
    private function captureSnapshotRuntimeBaselines(EntityChunkSnapshot $snapshot): array
    {
        $baselines = [];
        foreach ($snapshot->records() as $record) {
            $runtime = $this->runtimeStates[$record->uuid()] ?? null;
            if ($runtime === null) {
                continue;
            }
            $entity = $this->registry->getByRuntimeId($runtime->runtimeId);
            if ($entity === null || $entity->isRemoved()
                || self::chunkAt($entity)->key() !== $snapshot->chunk->key()) {
                continue;
            }
            $baselines[$record->uuid()] = [
                'revision' => $entity->revision(),
                'age' => $entity->ageTicks(),
            ];
        }

        return $baselines;
    }

    /**
     * Reconciles the unrelated records returned by an atomic one-entity ownership delta.
     *
     * The submitted snapshots may contain local changes which the ownership delta does
     * not commit. Those changes stay dirty, while a newer durable baseline returned by
     * the storage owner is adopted before the next snapshot is constructed.
     *
     * @param array<string, array{revision: int, age: int}> $runtimeBaselines
     */
    private function reconcileOwnershipTransferRecords(
        ManagedEntityChunkState $state,
        EntityChunkSnapshot $submitted,
        EntityChunkSnapshot $committed,
        array $runtimeBaselines,
        string $excludedUuid,
        bool $changedDuringWrite = false,
    ): bool {
        $dirty = $changedDuringWrite;
        $submittedRecords = self::recordsByUuid($submitted);
        $committedRecords = self::recordsByUuid($committed);
        $ownerKey = $state->chunk->key();

        foreach ($committedRecords as $uuid => $record) {
            if ($uuid === $excludedUuid) {
                continue;
            }
            $runtime = $this->runtimeStates[$uuid] ?? null;
            $baseline = $runtimeBaselines[$uuid] ?? null;
            $entity = $runtime === null ? null : $this->registry->getByRuntimeId($runtime->runtimeId);
            if ($runtime === null || !is_array($baseline) || $entity === null || $entity->isRemoved()
                || self::chunkAt($entity)->key() !== $ownerKey) {
                $state->records[$uuid] = $record;
                $this->owners[$uuid] = $ownerKey;
                continue;
            }
            if (!$record instanceof EntityPersistenceRecord) {
                throw new LogicException('An active entity resolved to a dormant committed persistence record.');
            }

            $submittedRecord = $submittedRecords[$uuid] ?? null;
            $hadPendingChange = $submittedRecord instanceof PersistentEntityRecord
                && $submittedRecord != $runtime->record;
            if (!$hadPendingChange || $submittedRecord == $record) {
                $this->rebaseRuntimeRecord(
                    $runtime,
                    $record,
                    $baseline['revision'],
                    $baseline['age'],
                );
            } else {
                // Preserve the old runtime baseline so currentRecord() reapplies the
                // local delta on top of the exact durable record returned by storage.
                $runtime->record = $record;
                $runtime->persistedAgeBaseline = $record->ageTicks;
                $runtime->durablyStored = true;
                $dirty = true;
            }

            if ($entity->revision() !== $runtime->runtimeRevisionBaseline
                || $entity->ageTicks() !== $runtime->runtimeAgeBaseline) {
                $state->records[$uuid] = $this->currentRecord($runtime, $entity, $state->chunk);
                $dirty = true;
            } else {
                $state->records[$uuid] = $record;
            }
            $this->owners[$uuid] = $ownerKey;
        }

        foreach ($submittedRecords as $uuid => $_record) {
            if ($uuid !== $excludedUuid && !isset($committedRecords[$uuid], $state->records[$uuid])) {
                $dirty = true;
            }
        }
        $state->revision = $committed->chunkRevision;

        return $dirty;
    }

    /**
     * Rebases records which still belong to this in-memory chunk onto the exact
     * snapshot committed by the storage owner. Records added or removed while
     * the transfer was in flight remain authoritative and keep the chunk dirty.
     *
     * @param array<string, array{revision: int, age: int}> $runtimeBaselines
     */
    private function rebaseTransferRecords(
        ManagedEntityChunkState $state,
        EntityChunkSnapshot $snapshot,
        array $runtimeBaselines,
        ?string $excludedUuid = null,
    ): void {
        foreach ($snapshot->records() as $record) {
            $uuid = $record->uuid();
            if ($uuid === $excludedUuid || !isset($state->records[$uuid])) {
                continue;
            }
            $runtime = $this->runtimeStates[$uuid] ?? null;
            $baseline = $runtimeBaselines[$uuid] ?? null;
            if ($runtime !== null && is_array($baseline)) {
                if (!$record instanceof EntityPersistenceRecord) {
                    throw new LogicException('An active entity resolved to a dormant saved persistence record.');
                }
                $state->records[$uuid] = $record;
                $this->rebaseRuntimeRecord(
                    $runtime,
                    $record,
                    $baseline['revision'],
                    $baseline['age'],
                );
            }
        }
    }

    private function rebaseRuntimeRecord(
        ManagedEntityRuntimeState $runtime,
        EntityPersistenceRecord $record,
        int $runtimeRevisionBaseline,
        int $runtimeAgeBaseline,
    ): void {
        $runtime->record = $record;
        $runtime->runtimeRevisionBaseline = $runtimeRevisionBaseline;
        $runtime->runtimeAgeBaseline = $runtimeAgeBaseline;
        $runtime->persistedAgeBaseline = $record->ageTicks;
        $runtime->durablyStored = true;
    }

    private function recordByUuid(EntityChunkSnapshot $snapshot, string $uuid): ?PersistentEntityRecord
    {
        foreach ($snapshot->records() as $record) {
            if ($record->uuid() === $uuid) {
                return $record;
            }
        }

        return null;
    }

    /** @return array<string, PersistentEntityRecord> */
    private static function recordsByUuid(EntityChunkSnapshot $snapshot): array
    {
        $records = [];
        foreach ($snapshot->records() as $record) {
            $records[$record->uuid()] = $record;
        }

        return $records;
    }

    private function markOwnerDirty(string $uuid): void
    {
        $key = $this->owners[$uuid] ?? null;
        if ($key !== null && isset($this->chunks[$key])) {
            $this->chunks[$key]->dirty = true;
            ++$this->chunks[$key]->mutationRevision;
        }
    }

    /** @param list<string> $keys */
    private function persistKeys(array $keys): EntityPersistenceFlushResult
    {
        $saved = 0;
        $failed = [];
        $failureDetails = [];
        foreach ($keys as $key) {
            $state = $this->chunks[$key];
            try {
                $snapshot = $this->snapshot($state);
                $this->store->saveEntityChunk($snapshot, $this->dimension);
                $this->acknowledge($state, $snapshot);
                ++$saved;
            } catch (Throwable $error) {
                $failed[] = $state->chunk;
                $failureDetails[] = self::failureDetail($state->chunk, 'flush', $error);
            }
        }

        return new EntityPersistenceFlushResult(count($keys), $saved, $failed, $failureDetails);
    }

    /** @return array{chunk: ChunkPosition, operation: string, exception: string, detail: string} */
    private static function failureDetail(ChunkPosition $chunk, string $operation, Throwable $error): array
    {
        return [
            'chunk' => $chunk,
            'operation' => $operation,
            'exception' => $error::class,
            'detail' => $error->getMessage(),
        ];
    }

    private function snapshot(
        ManagedEntityChunkState $state,
        ?string $excludedUuid = null,
        ?EntityPersistenceRecord $added = null,
    ): EntityChunkSnapshot {
        if ($state->revision >= PHP_INT_MAX) {
            throw new OverflowException('Entity persistence chunk revision is exhausted.');
        }
        $records = [];
        foreach ($state->records as $uuid => $record) {
            if ($uuid === $excludedUuid) {
                continue;
            }
            $runtime = $this->runtimeStates[$uuid] ?? null;
            if ($runtime !== null) {
                $entity = $this->registry->getByRuntimeId($runtime->runtimeId);
                if ($entity !== null && !$entity->isRemoved()
                    && self::chunkAt($entity)->key() === $state->chunk->key()) {
                    $record = $this->currentRecord($runtime, $entity, $state->chunk);
                }
            }
            $records[$uuid] = $record;
        }
        if ($added !== null) {
            if (isset($records[$added->uuid()])) {
                throw new LogicException('Destination entity persistence snapshot already contains the moved UUID.');
            }
            $records[$added->uuid()] = $added;
        }
        ksort($records, SORT_STRING);

        return new EntityChunkSnapshot(
            $this->worldName,
            $state->chunk,
            $state->revision + 1,
            array_values($records),
        );
    }

    private function acknowledge(ManagedEntityChunkState $state, EntityChunkSnapshot $snapshot): void
    {
        $previousUuids = array_keys($state->records);
        $records = [];
        foreach ($snapshot->records() as $record) {
            $records[$record->uuid()] = $record;
            $this->owners[$record->uuid()] = $state->chunk->key();
            $runtime = $this->runtimeStates[$record->uuid()] ?? null;
            if ($runtime !== null && $record instanceof EntityPersistenceRecord) {
                $entity = $this->registry->getByRuntimeId($runtime->runtimeId);
                if ($entity !== null && self::chunkAt($entity)->key() === $state->chunk->key()) {
                    $runtime->record = $record;
                    $runtime->runtimeRevisionBaseline = $entity->revision();
                    $runtime->runtimeAgeBaseline = $entity->ageTicks();
                    $runtime->persistedAgeBaseline = $record->ageTicks;
                    $runtime->durablyStored = true;
                }
            }
        }
        foreach ($previousUuids as $uuid) {
            if (!isset($records[$uuid]) && ($this->owners[$uuid] ?? null) === $state->chunk->key()) {
                unset($this->owners[$uuid]);
            }
        }
        $state->records = $records;
        $state->revision = $snapshot->chunkRevision;
        $state->dirty = false;
    }

    private function currentRecord(
        ManagedEntityRuntimeState $runtime,
        AbstractEntity $entity,
        ChunkPosition $owner,
    ): EntityPersistenceRecord {
        $revisionDelta = $entity->revision() - $runtime->runtimeRevisionBaseline;
        $ageDelta = $entity->ageTicks() - $runtime->runtimeAgeBaseline;
        if ($revisionDelta < 0 || $ageDelta < 0
            || $runtime->record->revision() > PHP_INT_MAX - $revisionDelta
            || $runtime->persistedAgeBaseline > 0x7fffffff - $ageDelta) {
            throw new OverflowException('Managed entity persistence revision or age is invalid.');
        }

        return $this->recordFromEntity(
            $entity,
            $owner,
            $runtime->record->revision() + $revisionDelta,
            $runtime->persistedAgeBaseline + $ageDelta,
            $runtime->record->variant,
            $runtime->record->customSchemaVersion,
            $runtime->record->customData,
        );
    }

    private function recordFromEntity(
        AbstractEntity $entity,
        ChunkPosition $owner,
        int $revision,
        int $ageTicks,
        int|string|null $variant,
        int $customSchemaVersion,
        string $customData,
    ): EntityPersistenceRecord {
        if ($entity instanceof IntrinsicEntityPersistence) {
            $variant = $entity->persistenceVariant();
            $customSchemaVersion = $entity->persistenceSchemaVersion();
            $customData = $entity->persistenceData();
        }
        if ($this->customStateEncoder !== null) {
            $customState = ($this->customStateEncoder)($entity);
            if ($customState !== null) {
                $customSchemaVersion = $customState->schemaVersion;
                $customData = $customState->bytes();
            }
        }

        return new EntityPersistenceRecord(
            $entity->getType()->identifier(),
            $entity->getUniqueId(),
            $this->worldName,
            $owner,
            $entity->internalPosition(),
            $entity->getYaw(),
            $entity->getPitch(),
            $entity->getMotion(),
            $entity instanceof AbstractLivingEntity ? $entity->getHealth() : null,
            $ageTicks,
            $entity->isPersistent(),
            $variant,
            self::equipmentEntries($entity),
            $customSchemaVersion,
            $customData,
            $revision,
            $entity->spawnOrigin(),
            $entity->despawnPolicy(),
            $entity instanceof AbstractLivingEntity ? $entity->getFireTicks() : 0,
        );
    }

    private function activateFromDefinition(
        EntityPersistenceRecord $record,
        RegisteredEntityDefinition $registration,
        EntityRegistry $registry,
    ): AbstractEntity {
        $entity = $registry->spawn(
            static fn(string $uuid, int $runtimeId): AbstractEntity => $registration->persistenceFactory !== null
                ? ($registration->persistenceFactory)($uuid, $runtimeId, $record)
                : ($registration->factory)(
                    $uuid,
                    $runtimeId,
                    $record->worldName(),
                    $record->position,
                    $record->yaw,
                    $record->pitch,
                ),
            $record->uuid(),
        );
        if (!$record->persistent || !$entity->isPersistent()) {
            throw new LogicException('Loaded entity persistence flag disagrees with its definition.');
        }
        if ($entity instanceof AbstractLivingEntity) {
            if ($record->health === null || $record->health > $entity->getMaximumHealth()) {
                throw new LogicException('Loaded living entity health disagrees with its definition.');
            }
            $entity->damage($entity->getMaximumHealth() - $record->health);
            $entity->restoreFireTicks($record->fireTicks);
            foreach ($record->equipment() as $entry) {
                $entity->equipmentState()->restoreItem(
                    $entry->equipmentSlot(),
                    $entry->itemStack(),
                    $entry->dropChance,
                );
            }
        } elseif ($record->health !== null) {
            throw new LogicException('Loaded non-living entity unexpectedly contains health.');
        }
        $entity->restoreSpawnOwnership($record->spawnOrigin, $record->despawnPolicy);
        $entity->setMotion($record->motion);
        $entity->restoreAgeTicks($record->ageTicks);
        if ($entity instanceof IntrinsicEntityPersistence) {
            $entity->restorePersistenceState(
                $record->variant,
                $record->customSchemaVersion,
                $record->customData,
            );
        }

        return $entity;
    }

    /** @return list<EntityEquipmentEntry> */
    private static function equipmentEntries(AbstractEntity $entity): array
    {
        if (!$entity instanceof AbstractLivingEntity) {
            return [];
        }
        $entries = [];
        foreach (\Bedriox\Api\Inventory\EquipmentSlot::cases() as $slot) {
            $item = $entity->equipmentState()->getItem($slot);
            if ($item === null) {
                continue;
            }
            $entries[] = new EntityEquipmentEntry(
                $slot->value,
                $item->identifier,
                $item->count,
                $item->damage,
                $item->auxValue,
                $item->nbt?->toBinary() ?? '',
                $entity->equipmentState()->getDropChance($slot),
            );
        }

        return $entries;
    }

    private function assertActivatedEntity(EntityPersistenceRecord $record, AbstractEntity $entity): void
    {
        if ($this->registry->getByUniqueId($record->uuid()) !== $entity
            || $entity->getUniqueId() !== $record->uuid()
            || $entity->getType()->identifier() !== $record->typeIdentifier()
            || $entity->getWorldName() !== $this->worldName
            || self::chunkAt($entity)->key() !== $record->ownerChunk()->key()) {
            throw new LogicException('Entity persistence activator returned an incompatible runtime entity.');
        }
    }

    private static function chunkAt(AbstractEntity $entity): ChunkPosition
    {
        $position = $entity->internalPosition();

        return new ChunkPosition((int) floor($position->x / 16.0), (int) floor($position->z / 16.0));
    }
}
