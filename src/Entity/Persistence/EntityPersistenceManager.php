<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Api\Entity\CustomEntityState;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\RegisteredEntityDefinition;
use Bedriox\Server\World\ChunkPosition;
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

    /** @var array<string, true> */
    private array $corruptChunks = [];

    private int $synchronizationCursor = 0;

    /** @var array<string, true> Chunk keys captured for the current finite autosave generation. */
    private array $autosavePending = [];

    private int $autosaveGeneration = 0;

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
            $snapshot = $this->store->loadEntityChunk($chunk);
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
            static fn(ManagedEntityChunkState $state): bool => $state->dirty,
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
        $keys = array_slice(array_keys($this->autosavePending), 0, $maximumChunks);
        if ($keys === []) {
            return new EntityPersistenceFlushResult(0, 0, []);
        }

        $saved = 0;
        $failed = [];
        foreach ($keys as $key) {
            $state = $this->chunks[$key] ?? null;
            if ($state === null || !$state->dirty) {
                unset($this->autosavePending[$key]);
                continue;
            }
            try {
                $snapshot = $this->snapshot($state);
                $this->store->saveEntityChunk($snapshot);
                $this->acknowledge($state, $snapshot);
                unset($this->autosavePending[$key]);
                ++$saved;
            } catch (Throwable) {
                unset($this->autosavePending[$key]);
                $this->autosavePending[$key] = true;
                $failed[] = $state->chunk;
            }
        }

        return new EntityPersistenceFlushResult(count($keys), $saved, $failed);
    }

    public function pendingAutosaveChunkCount(): int
    {
        return count($this->autosavePending);
    }

    public function autosaveGeneration(): int
    {
        return $this->autosaveGeneration;
    }

    /** Saves at most $maximumChunks dirty chunks and retains failed work for a later retry. */
    public function persistDirty(int $maximumChunks): EntityPersistenceFlushResult
    {
        if ($maximumChunks < 1 || $maximumChunks > self::MAX_AUTOSAVE_CHUNKS) {
            throw new InvalidArgumentException('Entity persistence autosave batch is outside its supported range.');
        }
        $keys = array_keys(array_filter(
            $this->chunks,
            static fn(ManagedEntityChunkState $state): bool => $state->dirty,
        ));
        sort($keys, SORT_STRING);
        $keys = array_slice($keys, 0, $maximumChunks);
        return $this->persistKeys($keys);
    }

    /** Synchronizes every active record and attempts every dirty chunk exactly once. */
    public function flushShutdown(): EntityPersistenceFlushResult
    {
        $uuids = array_keys($this->runtimeStates);
        sort($uuids, SORT_STRING);
        foreach ($uuids as $uuid) {
            try {
                $this->synchronizeEntity($uuid);
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
            return new EntityPersistenceFlushResult(0, 0, []);
        }
        sort($keys, SORT_STRING);

        return $this->persistKeys($keys);
    }

    /** Saves and releases one chunk. Returns the number of runtime entities removed. */
    public function unloadChunk(ChunkPosition $chunk): int
    {
        $key = $chunk->key();
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
                $this->synchronizeEntity($uuid);
            }
        }
        if ($state->dirty) {
            $snapshot = $this->snapshot($state);
            $this->store->saveEntityChunk($snapshot);
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
                $this->synchronizeEntity($uuid);
                $runtime = $this->runtimeStates[$uuid];
                $ownerKey = $this->owners[$uuid];
                $owner = $this->chunks[$ownerKey] ?? null;
                if ($owner !== null) {
                    $owner->records[$uuid] = $this->currentRecord($runtime, $entity, $owner->chunk);
                    $owner->dirty = true;
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

    private function synchronizeEntity(string $uuid): int
    {
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
            $this->store->transferEntityOwnership(new EntityOwnershipTransfer(
                $uuid,
                $runtime->record->revision(),
                $sourceAfter,
                $destinationAfter,
            ));
            $this->owners[$uuid] = $destinationKey;
            $this->acknowledge($source, $sourceAfter);
            $this->acknowledge($destination, $destinationAfter);
        } else {
            unset($source->records[$uuid]);
            $destination->records[$uuid] = $moved;
            $source->dirty = true;
            $destination->dirty = true;
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

    private function markOwnerDirty(string $uuid): void
    {
        $key = $this->owners[$uuid] ?? null;
        if ($key !== null && isset($this->chunks[$key])) {
            $this->chunks[$key]->dirty = true;
        }
    }

    /** @param list<string> $keys */
    private function persistKeys(array $keys): EntityPersistenceFlushResult
    {
        $saved = 0;
        $failed = [];
        foreach ($keys as $key) {
            $state = $this->chunks[$key];
            try {
                $snapshot = $this->snapshot($state);
                $this->store->saveEntityChunk($snapshot);
                $this->acknowledge($state, $snapshot);
                ++$saved;
            } catch (Throwable) {
                $failed[] = $state->chunk;
            }
        }

        return new EntityPersistenceFlushResult(count($keys), $saved, $failed);
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
                if ($entity !== null && !$entity->isRemoved()) {
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
                if ($entity !== null) {
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
            static fn(string $uuid, int $runtimeId): AbstractEntity => ($registration->factory)(
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
