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

namespace Bedriox\Server\Tests\Entity\Persistence;

use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\Persistence\AsynchronousEntityPersistenceStore;
use Bedriox\Server\Entity\Persistence\CorruptEntityPersistenceException;
use Bedriox\Server\Entity\Persistence\DormantEntityRecord;
use Bedriox\Server\Entity\Persistence\EntityChunkSaveCompletion;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransfer;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferCompletion;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferResult;
use Bedriox\Server\Entity\Persistence\EntityPersistenceConflictException;
use Bedriox\Server\Entity\Persistence\EntityPersistenceManager;
use Bedriox\Server\Entity\Persistence\EntityPersistenceRecord;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\PersistenceWriteRequest;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use PHPUnit\Framework\TestCase;

final class EntityPersistenceManagerTest extends TestCase
{
    private const string COW_UUID = '123e4567-e89b-42d3-a456-426614174000';

    public function testChunkActivationIsIdempotentAndPreservesDormantRecordsAndExactRevisions(): void
    {
        $chunk = new ChunkPosition(0, 0);
        $store = new TestEntityPersistenceStore();
        $store->snapshots[$chunk->key()] = new EntityChunkSnapshot('world', $chunk, 5, [
            self::record(self::COW_UUID, $chunk, new Position(1.5, 64.0, 1.5), revision: 17, ageTicks: 100),
            self::record(
                '323e4567-e89b-42d3-a456-426614174000',
                $chunk,
                new Position(2.5, 64.0, 2.5),
                revision: 4,
                type: 'minecraft:zombie',
            ),
            new DormantEntityRecord(
                'example:dormant',
                '223e4567-e89b-42d3-a456-426614174000',
                'world',
                $chunk,
                9,
                "\x01retained",
            ),
        ]);
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );

        $first = $manager->activateChunk($chunk);
        $second = $manager->activateChunk($chunk);

        self::assertFalse($first->alreadyActivated);
        self::assertFalse($first->corrupt);
        self::assertSame(2, $first->activatedEntities);
        self::assertSame(1, $first->dormantRecords);
        self::assertTrue($second->alreadyActivated);
        self::assertSame(1, $store->loadCalls[$chunk->key()] ?? 0);
        self::assertSame(2, $manager->activeEntityCount());
        self::assertSame(1, $manager->dormantRecordCount());

        $entity = $registry->getByUniqueId(self::COW_UUID);
        self::assertInstanceOf(CowEntity::class, $entity);
        self::assertInstanceOf(
            ZombieEntity::class,
            $registry->getByUniqueId('323e4567-e89b-42d3-a456-426614174000'),
        );
        $entity->damage(1.0);
        $entity->advanceAge();
        $entity->advanceAge();
        self::assertSame(0, $manager->synchronize()->failedEntities);
        $flush = $manager->persistDirty(1);

        self::assertSame(1, $flush->savedChunks);
        $saved = $store->snapshots[$chunk->key()];
        self::assertInstanceOf(EntityChunkSnapshot::class, $saved);
        self::assertSame(6, $saved->chunkRevision);
        self::assertCount(3, $saved->records());
        $cow = self::findRecord($saved, self::COW_UUID);
        self::assertInstanceOf(EntityPersistenceRecord::class, $cow);
        self::assertSame(18, $cow->revision());
        self::assertSame(102, $cow->ageTicks);
        self::assertSame(9.0, $cow->health);
        self::assertInstanceOf(DormantEntityRecord::class, self::findRecord(
            $saved,
            '223e4567-e89b-42d3-a456-426614174000',
        ));
    }

    public function testDurableCrossChunkMoveUsesOneAtomicOwnershipTransfer(): void
    {
        $source = new ChunkPosition(0, 0);
        $destination = new ChunkPosition(1, 0);
        $store = new TestEntityPersistenceStore();
        $store->snapshots[$source->key()] = new EntityChunkSnapshot('world', $source, 4, [
            self::record(self::COW_UUID, $source, new Position(15.5, 64.0, 1.5), revision: 17),
        ]);
        $store->snapshots[$destination->key()] = new EntityChunkSnapshot('world', $destination, 8, []);
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $manager->activateChunk($source);
        $entity = $registry->getByUniqueId(self::COW_UUID);
        self::assertInstanceOf(CowEntity::class, $entity);

        $registry->move($entity->getRuntimeId(), 'world', new Position(16.5, 64.0, 1.5), 0.0, 0.0);
        $sync = $manager->synchronize();

        self::assertSame(1, $sync->transferredEntities);
        self::assertSame(0, $sync->failedEntities);
        self::assertCount(1, $store->transfers);
        $transfer = $store->transfers[0];
        self::assertSame(self::COW_UUID, $transfer->uuid);
        self::assertSame(17, $transfer->expectedEntityRevision);
        self::assertSame([], $transfer->sourceAfter->records());
        self::assertSame(18, $transfer->destinationAfter->revisions()[self::COW_UUID] ?? null);
        self::assertSame(0, $manager->dirtyChunkCount());
    }

    public function testUnloadingAChunkTransfersMultipleEntitiesThatAlreadyCrossedItsBoundary(): void
    {
        $source = new ChunkPosition(0, 0);
        $destination = new ChunkPosition(1, 0);
        $secondUuid = '223e4567-e89b-42d3-a456-426614174000';
        $store = new TestEntityPersistenceStore();
        $store->snapshots[$source->key()] = new EntityChunkSnapshot('world', $source, 4, [
            self::record(self::COW_UUID, $source, new Position(14.5, 64.0, 1.5), revision: 17),
            self::record($secondUuid, $source, new Position(15.5, 64.0, 2.5), revision: 9),
        ]);
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        self::assertSame(2, $manager->activateChunk($source)->activatedEntities);
        $first = $registry->getByUniqueId(self::COW_UUID);
        $second = $registry->getByUniqueId($secondUuid);
        self::assertInstanceOf(CowEntity::class, $first);
        self::assertInstanceOf(CowEntity::class, $second);
        $registry->move($first->getRuntimeId(), 'world', new Position(16.5, 64.0, 1.5), 0.0, 0.0);
        $registry->move($second->getRuntimeId(), 'world', new Position(17.5, 64.0, 2.5), 0.0, 0.0);

        self::assertSame(0, $manager->unloadChunk($source));
        self::assertCount(2, $store->transfers);
        self::assertSame([], $store->snapshots[$source->key()]->records());
        self::assertCount(2, $store->snapshots[$destination->key()]->records());
        self::assertSame(2, $manager->activeEntityCount());
        self::assertSame(2, $manager->unloadChunk($destination));
        self::assertSame(0, $manager->activeEntityCount());
    }

    public function testNewEntitiesUseBoundedAutosaveBatchesAndShutdownFlushesTheRemainder(): void
    {
        $store = new TestEntityPersistenceStore();
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $first = $registry->spawn(static fn(string $uuid, int $runtimeId): CowEntity => new CowEntity(
            $uuid,
            $runtimeId,
            'world',
            new Position(1.5, 64.0, 1.5),
        ));
        $second = $registry->spawn(static fn(string $uuid, int $runtimeId): CowEntity => new CowEntity(
            $uuid,
            $runtimeId,
            'world',
            new Position(17.5, 64.0, 1.5),
        ));
        $manager->registerSpawned($first);
        $manager->registerSpawned($second);

        self::assertSame(2, $manager->dirtyChunkCount());
        self::assertSame(1, $manager->persistDirty(1)->savedChunks);
        self::assertSame(1, $manager->dirtyChunkCount());
        $shutdown = $manager->flushShutdown();

        self::assertSame(1, $shutdown->attemptedChunks);
        self::assertSame(1, $shutdown->savedChunks);
        self::assertSame(0, $shutdown->failedChunksCount());
        self::assertSame(0, $manager->dirtyChunkCount());
        self::assertCount(2, $store->snapshots);
    }

    public function testAutosaveGenerationTerminatesWhenSavedChunksBecomeDirtyAgain(): void
    {
        $store = new TestEntityPersistenceStore();
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $first = $registry->spawn(static fn(string $uuid, int $runtimeId): CowEntity => new CowEntity(
            $uuid,
            $runtimeId,
            'world',
            new Position(1.5, 64.0, 1.5),
        ));
        $second = $registry->spawn(static fn(string $uuid, int $runtimeId): CowEntity => new CowEntity(
            $uuid,
            $runtimeId,
            'world',
            new Position(17.5, 64.0, 1.5),
        ));
        $manager->registerSpawned($first);
        $manager->registerSpawned($second);

        self::assertSame(2, $manager->beginAutosaveGeneration());
        self::assertSame(1, $manager->persistAutosaveGeneration(1)->savedChunks);
        self::assertSame(1, $manager->pendingAutosaveChunkCount());

        $registry->move($first->getRuntimeId(), 'world', new Position(2.5, 64.0, 1.5), 0.0, 0.0);
        $manager->synchronize();
        self::assertSame(2, $manager->dirtyChunkCount());

        self::assertSame(1, $manager->persistAutosaveGeneration(1)->savedChunks);
        self::assertSame(0, $manager->pendingAutosaveChunkCount());
        self::assertSame(1, $manager->dirtyChunkCount());
        self::assertSame(1, $manager->beginAutosaveGeneration());
    }

    public function testAgeIsCheckpointedAtAutosaveAndShutdownInsteadOfDirtyingEveryTick(): void
    {
        $chunk = new ChunkPosition(0, 0);
        $store = new TestEntityPersistenceStore();
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $entity = $registry->spawn(static fn(string $uuid, int $runtimeId): CowEntity => new CowEntity(
            $uuid,
            $runtimeId,
            'world',
            new Position(1.5, 64.0, 1.5),
        ));
        $manager->registerSpawned($entity);
        self::assertSame(1, $manager->persistDirty(1)->savedChunks);

        $entity->advanceAge();
        $entity->advanceAge();
        $manager->synchronize();
        self::assertSame(0, $manager->dirtyChunkCount());

        self::assertSame(1, $manager->beginAutosaveGeneration());
        self::assertSame(1, $manager->persistAutosaveGeneration(1)->savedChunks);
        $record = self::findRecord($store->snapshots[$chunk->key()], $entity->getUniqueId());
        self::assertInstanceOf(EntityPersistenceRecord::class, $record);
        self::assertSame(2, $record->ageTicks);

        $entity->advanceAge();
        self::assertSame(1, $manager->flushShutdown()->savedChunks);
        $record = self::findRecord($store->snapshots[$chunk->key()], $entity->getUniqueId());
        self::assertInstanceOf(EntityPersistenceRecord::class, $record);
        self::assertSame(3, $record->ageTicks);
    }

    public function testOwnershipTransfersAreBoundedAndDeferredFairly(): void
    {
        $firstSource = new ChunkPosition(0, 0);
        $secondSource = new ChunkPosition(2, 0);
        $store = new TestEntityPersistenceStore();
        $secondUuid = '223e4567-e89b-42d3-a456-426614174000';
        $store->snapshots[$firstSource->key()] = new EntityChunkSnapshot('world', $firstSource, 1, [
            self::record(self::COW_UUID, $firstSource, new Position(15.5, 64.0, 1.5), revision: 1),
        ]);
        $store->snapshots[$secondSource->key()] = new EntityChunkSnapshot('world', $secondSource, 1, [
            self::record($secondUuid, $secondSource, new Position(47.5, 64.0, 1.5), revision: 1),
        ]);
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $manager->activateChunk($firstSource);
        $manager->activateChunk($secondSource);
        $first = $registry->getByUniqueId(self::COW_UUID);
        $second = $registry->getByUniqueId($secondUuid);
        self::assertInstanceOf(CowEntity::class, $first);
        self::assertInstanceOf(CowEntity::class, $second);
        $registry->move($first->getRuntimeId(), 'world', new Position(16.5, 64.0, 1.5), 0.0, 0.0);
        $registry->move($second->getRuntimeId(), 'world', new Position(48.5, 64.0, 1.5), 0.0, 0.0);

        $firstPass = $manager->synchronize(2, 1);
        self::assertSame(1, $firstPass->transferredEntities);
        self::assertSame(1, $firstPass->deferredOwnershipTransfers);
        self::assertCount(1, $store->transfers);

        $secondPass = $manager->synchronize(2, 1);
        self::assertSame(1, $secondPass->transferredEntities);
        self::assertSame(0, $secondPass->deferredOwnershipTransfers);
        self::assertCount(2, $store->transfers);
    }

    public function testAsynchronousTransferKeepsUncommittedUnrelatedRuntimeChangesDirty(): void
    {
        $source = new ChunkPosition(0, 0);
        $firstDestination = new ChunkPosition(1, 0);
        $secondDestination = new ChunkPosition(2, 0);
        $secondUuid = '223e4567-e89b-42d3-a456-426614174000';
        $store = new TestAsynchronousEntityPersistenceStore();
        $store->snapshots[$source->key()] = new EntityChunkSnapshot('world', $source, 4, [
            self::record(self::COW_UUID, $source, new Position(15.5, 64.0, 1.5), revision: 17),
            self::record($secondUuid, $source, new Position(2.5, 64.0, 2.5), revision: 5),
        ]);
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $manager->activateChunk($source);
        $first = $registry->getByUniqueId(self::COW_UUID);
        $second = $registry->getByUniqueId($secondUuid);
        self::assertInstanceOf(CowEntity::class, $first);
        self::assertInstanceOf(CowEntity::class, $second);

        $registry->move($second->getRuntimeId(), 'world', new Position(3.5, 64.0, 2.5), 0.0, 0.0);
        $registry->move(
            $first->getRuntimeId(),
            'world',
            new Position($firstDestination->x * 16 + 0.5, 64.0, 1.5),
            0.0,
            0.0,
        );
        self::assertSame(1, $manager->synchronize()->transferredEntities);
        self::assertCount(1, $store->queuedTransfers);

        self::assertSame(0, $manager->synchronize()->failedEntities);
        self::assertSame(5, $store->snapshots[$source->key()]->revisions()[$secondUuid] ?? null);

        $registry->move(
            $second->getRuntimeId(),
            'world',
            new Position($secondDestination->x * 16 + 0.5, 64.0, 2.5),
            0.0,
            0.0,
        );
        self::assertSame(1, $manager->synchronize()->transferredEntities);
        self::assertCount(1, $store->queuedTransfers);
        self::assertSame(
            5,
            $store->queuedTransfers[0]->expectedEntityRevision,
            'The unrelated local change must remain pending on top of its unchanged durable baseline.',
        );
    }

    public function testAsynchronousTransferAdoptsANewerUnrelatedDurableBaseline(): void
    {
        $source = new ChunkPosition(0, 0);
        $firstDestination = new ChunkPosition(1, 0);
        $secondDestination = new ChunkPosition(2, 0);
        $secondUuid = '223e4567-e89b-42d3-a456-426614174000';
        $store = new TestAsynchronousEntityPersistenceStore();
        $store->snapshots[$source->key()] = new EntityChunkSnapshot('world', $source, 4, [
            self::record(self::COW_UUID, $source, new Position(15.5, 64.0, 1.5), revision: 17),
            self::record($secondUuid, $source, new Position(2.5, 64.0, 2.5), revision: 5),
        ]);
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $manager->activateChunk($source);
        $first = $registry->getByUniqueId(self::COW_UUID);
        $second = $registry->getByUniqueId($secondUuid);
        self::assertInstanceOf(CowEntity::class, $first);
        self::assertInstanceOf(CowEntity::class, $second);

        $store->snapshots[$source->key()] = new EntityChunkSnapshot('world', $source, 40, [
            self::record(self::COW_UUID, $source, new Position(15.5, 64.0, 1.5), revision: 17),
            self::record($secondUuid, $source, new Position(2.5, 64.0, 2.5), revision: 20),
        ]);
        $registry->move(
            $first->getRuntimeId(),
            'world',
            new Position($firstDestination->x * 16 + 0.5, 64.0, 1.5),
            0.0,
            0.0,
        );
        self::assertSame(1, $manager->synchronize()->transferredEntities);
        self::assertSame(0, $manager->synchronize()->failedEntities);
        self::assertSame(20, $store->snapshots[$source->key()]->revisions()[$secondUuid] ?? null);

        $registry->move(
            $second->getRuntimeId(),
            'world',
            new Position($secondDestination->x * 16 + 0.5, 64.0, 2.5),
            0.0,
            0.0,
        );
        self::assertSame(1, $manager->synchronize()->transferredEntities);
        self::assertCount(1, $store->queuedTransfers);
        self::assertSame(
            20,
            $store->queuedTransfers[0]->expectedEntityRevision,
            'The next move must compare-and-swap against the exact unrelated durable record returned by storage.',
        );
    }

    public function testAsynchronousAutosaveRebasesRuntimeChangesAndDefersOwnershipTransfer(): void
    {
        $source = new ChunkPosition(0, 0);
        $destination = new ChunkPosition(1, 0);
        $store = new TestAsynchronousEntityPersistenceStore();
        $store->snapshots[$source->key()] = new EntityChunkSnapshot('world', $source, 4, [
            self::record(self::COW_UUID, $source, new Position(1.5, 64.0, 1.5), revision: 17),
        ]);
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $manager->activateChunk($source);
        $cow = $registry->getByUniqueId(self::COW_UUID);
        self::assertInstanceOf(CowEntity::class, $cow);
        $registry->move($cow->getRuntimeId(), 'world', new Position(2.5, 64.0, 1.5), 0.0, 0.0);
        $manager->synchronize();

        self::assertSame(1, $manager->beginAutosaveGeneration());
        $submitted = $manager->persistAutosaveGeneration(1);
        self::assertSame(1, $submitted->attemptedChunks);
        self::assertSame(0, $submitted->savedChunks);
        self::assertSame(1, $manager->pendingAutosaveChunkCount());
        self::assertCount(1, $store->queuedSaves);

        $registry->move(
            $cow->getRuntimeId(),
            'world',
            new Position($destination->x * 16 + 0.5, 64.0, 1.5),
            0.0,
            0.0,
        );
        $blocked = $manager->synchronize();
        self::assertSame(0, $blocked->transferredEntities);
        self::assertSame(1, $blocked->deferredOwnershipTransfers);
        self::assertSame(0, $store->queuedTransferCount());

        $completed = $manager->persistAutosaveGeneration(1);
        self::assertSame(1, $completed->savedChunks);
        self::assertSame(0, $manager->pendingAutosaveChunkCount());
        self::assertSame(18, $store->snapshots[$source->key()]->revisions()[self::COW_UUID] ?? null);

        $transferred = $manager->synchronize();
        self::assertSame(1, $transferred->transferredEntities);
        self::assertCount(1, $store->queuedTransfers);
        $queuedTransfer = $store->queuedTransfers[0] ?? null;
        self::assertInstanceOf(EntityOwnershipTransfer::class, $queuedTransfer);
        self::assertSame(18, $queuedTransfer->expectedEntityRevision);
    }

    public function testAutosaveDefersAChunkUntilUnsettledOwnershipIsTransferred(): void
    {
        $source = new ChunkPosition(0, 0);
        $destination = new ChunkPosition(1, 0);
        $store = new TestEntityPersistenceStore();
        $store->snapshots[$source->key()] = new EntityChunkSnapshot('world', $source, 4, [
            self::record(self::COW_UUID, $source, new Position(1.5, 64.0, 1.5), revision: 17),
        ]);
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $manager->activateChunk($source);
        $cow = $registry->getByUniqueId(self::COW_UUID);
        self::assertInstanceOf(CowEntity::class, $cow);
        $registry->move($cow->getRuntimeId(), 'world', new Position(2.5, 64.0, 1.5), 0.0, 0.0);
        $manager->synchronize();
        $registry->move(
            $cow->getRuntimeId(),
            'world',
            new Position($destination->x * 16 + 0.5, 64.0, 1.5),
            0.0,
            0.0,
        );
        self::assertSame(1, $manager->synchronize(1, 0)->deferredOwnershipTransfers);

        self::assertSame(1, $manager->beginAutosaveGeneration());
        $deferred = $manager->persistAutosaveGeneration(1);
        self::assertSame(0, $deferred->attemptedChunks);
        self::assertSame(0, $deferred->failedChunksCount());
        self::assertSame(1, $manager->pendingAutosaveChunkCount());
        self::assertSame(4, $store->snapshots[$source->key()]->chunkRevision);

        self::assertSame(1, $manager->synchronize(1, 1)->transferredEntities);
        self::assertSame(0, $manager->persistAutosaveGeneration(1)->failedChunksCount());
        self::assertSame(0, $manager->pendingAutosaveChunkCount());
    }

    public function testCorruptChunkIsIsolatedAndDoesNotPreventAnotherChunkFromLoading(): void
    {
        $corrupt = new ChunkPosition(0, 0);
        $healthy = new ChunkPosition(1, 0);
        $store = new TestEntityPersistenceStore();
        $store->corrupt[$corrupt->key()] = true;
        $store->snapshots[$healthy->key()] = new EntityChunkSnapshot('world', $healthy, 1, [
            self::record(
                self::COW_UUID,
                $healthy,
                new Position(17.5, 64.0, 1.5),
                revision: 2,
            ),
        ]);
        $manager = new EntityPersistenceManager(
            'world',
            new EntityRegistry(),
            EntityDefinitionRegistry::baseline(),
            $store,
        );

        self::assertTrue($manager->activateChunk($corrupt)->corrupt);
        self::assertTrue($manager->activateChunk($corrupt)->corrupt);
        self::assertSame(1, $store->loadCalls[$corrupt->key()] ?? 0);
        self::assertSame(1, $manager->activateChunk($healthy)->activatedEntities);
        self::assertTrue($manager->isChunkCorrupt($corrupt));
        self::assertTrue($manager->isChunkActivated($healthy));
        self::assertSame(1, $manager->activeEntityCount());
    }

    public function testFailedAutosaveRemainsDirtyForALaterRetry(): void
    {
        $chunk = new ChunkPosition(0, 0);
        $store = new TestEntityPersistenceStore();
        $store->failedSaves[$chunk->key()] = true;
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $entity = $registry->spawn(static fn(string $uuid, int $runtimeId): CowEntity => new CowEntity(
            $uuid,
            $runtimeId,
            'world',
            new Position(1.5, 64.0, 1.5),
        ));
        $manager->registerSpawned($entity);

        $failed = $manager->persistDirty(1);
        self::assertSame(0, $failed->savedChunks);
        self::assertEquals([$chunk], $failed->failedChunks());
        self::assertSame(1, $manager->dirtyChunkCount());

        unset($store->failedSaves[$chunk->key()]);
        self::assertSame(1, $manager->persistDirty(1)->savedChunks);
        self::assertSame(0, $manager->dirtyChunkCount());
    }

    public function testDeactivationRetainsTheDurableRecordAfterRuntimeRemoval(): void
    {
        $store = new TestEntityPersistenceStore();
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $entity = $registry->spawn(static fn(string $uuid, int $runtimeId): CowEntity => new CowEntity(
            $uuid,
            $runtimeId,
            'world',
            new Position(1.5, 64.0, 1.5),
        ));
        $manager->registerSpawned($entity);

        self::assertTrue($manager->deactivateEntity($entity->getUniqueId()));
        self::assertSame(0, $manager->activeEntityCount());
        self::assertSame($entity, $registry->remove($entity->getRuntimeId()));
        self::assertFalse($manager->deactivateEntity($entity->getUniqueId()));
        self::assertSame(1, $manager->persistDirty(1)->savedChunks);

        $snapshot = $store->snapshots[(new ChunkPosition(0, 0))->key()] ?? null;
        self::assertInstanceOf(EntityChunkSnapshot::class, $snapshot);
        self::assertInstanceOf(
            EntityPersistenceRecord::class,
            self::findRecord($snapshot, $entity->getUniqueId()),
        );
    }

    public function testChunkActivationAndUnloadNotifySymmetricLifecycleCallbacks(): void
    {
        $chunk = new ChunkPosition(0, 0);
        $store = new TestEntityPersistenceStore();
        $store->snapshots[$chunk->key()] = new EntityChunkSnapshot('world', $chunk, 1, [
            self::record(self::COW_UUID, $chunk, new Position(1.5, 64.0, 1.5), revision: 3),
        ]);
        $notifications = [];
        $manager = new EntityPersistenceManager(
            'world',
            new EntityRegistry(),
            EntityDefinitionRegistry::baseline(),
            $store,
            afterActivation: static function (
                EntityPersistenceRecord $record,
                AbstractEntity $entity,
            ) use (&$notifications): bool {
                $notifications[] = ['activated', $record->uuid(), $entity->getUniqueId()];

                return true;
            },
            afterDeactivation: static function (AbstractEntity $entity) use (&$notifications): void {
                $notifications[] = ['deactivated', $entity->getUniqueId(), $entity->getUniqueId()];
            },
        );

        self::assertSame(1, $manager->activateChunk($chunk)->activatedEntities);
        self::assertSame(1, $manager->unloadChunk($chunk));
        self::assertSame([
            ['activated', self::COW_UUID, self::COW_UUID],
            ['deactivated', self::COW_UUID, self::COW_UUID],
        ], $notifications);
        self::assertSame(0, $manager->activeEntityCount());
    }

    public function testEquipmentIsHydratedAndCurrentStateReplacesTheDurableSnapshot(): void
    {
        $chunk = new ChunkPosition(0, 0);
        $store = new TestEntityPersistenceStore();
        $registry = new EntityRegistry();
        $manager = new EntityPersistenceManager(
            'world',
            $registry,
            EntityDefinitionRegistry::baseline(),
            $store,
        );
        $entity = $registry->spawn(static fn(string $uuid, int $runtimeId): ZombieEntity => new ZombieEntity(
            $uuid,
            $runtimeId,
            'world',
            new Position(1.5, 64.0, 1.5),
        ));
        self::assertInstanceOf(ZombieEntity::class, $entity);
        $sword = new ItemStack('minecraft:iron_sword', 1, 17, ItemNbt::empty(), 2);
        $entity->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, $sword);
        $entity->equipmentState()->setDropChance(EquipmentSlot::MAIN_HAND, 0.42);
        $manager->registerSpawned($entity);

        self::assertSame(1, $manager->persistDirty(1)->savedChunks);
        self::assertSame(1, $manager->unloadChunk($chunk));
        self::assertSame(1, $manager->activateChunk($chunk)->activatedEntities);

        $restored = $registry->getByUniqueId($entity->getUniqueId());
        self::assertInstanceOf(ZombieEntity::class, $restored);
        self::assertEquals($sword, $restored->equipmentState()->getItem(EquipmentSlot::MAIN_HAND));
        self::assertSame(0.42, $restored->equipmentState()->getDropChance(EquipmentSlot::MAIN_HAND));

        $restored->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:stone_sword', 1));
        $restored->equipmentState()->setDropChance(EquipmentSlot::MAIN_HAND, 1.0);
        self::assertSame(0, $manager->synchronize()->failedEntities);
        self::assertSame(1, $manager->persistDirty(1)->savedChunks);
        $record = self::findRecord($store->snapshots[$chunk->key()], $entity->getUniqueId());
        self::assertInstanceOf(EntityPersistenceRecord::class, $record);
        self::assertSame('minecraft:stone_sword', $record->equipment()[0]->itemIdentifier);
        self::assertSame(1.0, $record->equipment()[0]->dropChance);
    }

    private static function record(
        string $uuid,
        ChunkPosition $chunk,
        Position $position,
        int $revision,
        int $ageTicks = 0,
        string $type = 'minecraft:cow',
    ): EntityPersistenceRecord {
        return new EntityPersistenceRecord(
            $type,
            $uuid,
            'world',
            $chunk,
            $position,
            0.0,
            0.0,
            new EntityMotion(0.1, 0.0, 0.0),
            10.0,
            $ageTicks,
            true,
            null,
            [],
            0,
            '',
            $revision,
        );
    }

    private static function findRecord(EntityChunkSnapshot $snapshot, string $uuid): ?object
    {
        foreach ($snapshot->records() as $record) {
            if ($record->uuid() === $uuid) {
                return $record;
            }
        }

        return null;
    }
}

final class TestEntityPersistenceStore implements EntityPersistenceStore
{
    /** @var array<string, EntityChunkSnapshot> */
    public array $snapshots = [];

    /** @var array<string, int> */
    public array $loadCalls = [];

    /** @var array<string, true> */
    public array $corrupt = [];

    /** @var list<EntityOwnershipTransfer> */
    public array $transfers = [];

    /** @var array<string, true> */
    public array $failedSaves = [];

    public function loadEntityChunk(ChunkPosition $position, WorldDimension $dimension = WorldDimension::OVERWORLD): ?EntityChunkSnapshot
    {
        $key = $position->key();
        $this->loadCalls[$key] = ($this->loadCalls[$key] ?? 0) + 1;
        if (isset($this->corrupt[$key])) {
            throw new CorruptEntityPersistenceException($position);
        }

        return $this->snapshots[$key] ?? null;
    }

    public function saveEntityChunk(EntityChunkSnapshot $snapshot, WorldDimension $dimension = WorldDimension::OVERWORLD): void
    {
        if (isset($this->failedSaves[$snapshot->chunk->key()])) {
            throw new \RuntimeException('Injected entity persistence write failure.');
        }
        $this->snapshots[$snapshot->chunk->key()] = $snapshot;
    }

    public function transferEntityOwnership(EntityOwnershipTransfer $transfer, WorldDimension $dimension = WorldDimension::OVERWORLD): EntityOwnershipTransferResult
    {
        $this->transfers[] = $transfer;
        $this->snapshots[$transfer->sourceAfter->chunk->key()] = $transfer->sourceAfter;
        $this->snapshots[$transfer->destinationAfter->chunk->key()] = $transfer->destinationAfter;

        return new EntityOwnershipTransferResult($transfer->sourceAfter, $transfer->destinationAfter);
    }
}

final class TestAsynchronousEntityPersistenceStore implements AsynchronousEntityPersistenceStore
{
    /** @var array<string, EntityChunkSnapshot> */
    public array $snapshots = [];

    /** @var list<EntityOwnershipTransfer> */
    public array $queuedTransfers = [];

    /** @var list<EntityChunkSnapshot> */
    public array $queuedSaves = [];

    private int $nextSaveId = 1;

    public function loadEntityChunk(ChunkPosition $position, WorldDimension $dimension = WorldDimension::OVERWORLD): ?EntityChunkSnapshot
    {
        return $this->snapshots[$position->key()] ?? null;
    }

    public function saveEntityChunk(EntityChunkSnapshot $snapshot, WorldDimension $dimension = WorldDimension::OVERWORLD): void
    {
        $this->snapshots[$snapshot->chunk->key()] = $snapshot;
    }

    public function transferEntityOwnership(EntityOwnershipTransfer $transfer, WorldDimension $dimension = WorldDimension::OVERWORLD): EntityOwnershipTransferResult
    {
        $source = $this->snapshots[$transfer->sourceAfter->chunk->key()] ?? null;
        $destination = $this->snapshots[$transfer->destinationAfter->chunk->key()] ?? null;
        if (!$source instanceof EntityChunkSnapshot
            || !$source->containsExactRevision($transfer->uuid, $transfer->expectedEntityRevision)) {
            throw new EntityPersistenceConflictException('Injected asynchronous ownership conflict.');
        }
        $sourceRecords = self::records($source);
        $destinationRecords = $destination instanceof EntityChunkSnapshot ? self::records($destination) : [];
        $destinationRevision = $destination instanceof EntityChunkSnapshot ? $destination->chunkRevision : 0;
        $requestedDestination = self::records($transfer->destinationAfter);
        $moved = $requestedDestination[$transfer->uuid] ?? null;
        if (!$moved instanceof EntityPersistenceRecord || isset($destinationRecords[$transfer->uuid])) {
            throw new EntityPersistenceConflictException('Injected asynchronous ownership conflict.');
        }
        unset($sourceRecords[$transfer->uuid]);
        $destinationRecords[$transfer->uuid] = $moved;
        ksort($sourceRecords, SORT_STRING);
        ksort($destinationRecords, SORT_STRING);
        $sourceAfter = new EntityChunkSnapshot(
            $source->worldName,
            $source->chunk,
            $source->chunkRevision + 1,
            array_values($sourceRecords),
        );
        $destinationAfter = new EntityChunkSnapshot(
            $transfer->destinationAfter->worldName,
            $transfer->destinationAfter->chunk,
            $destinationRevision + 1,
            array_values($destinationRecords),
        );
        $this->snapshots[$sourceAfter->chunk->key()] = $sourceAfter;
        $this->snapshots[$destinationAfter->chunk->key()] = $destinationAfter;

        return new EntityOwnershipTransferResult($sourceAfter, $destinationAfter);
    }

    public function enqueueEntityChunkSave(EntityChunkSnapshot $snapshot, WorldDimension $dimension = WorldDimension::OVERWORLD): PersistenceEnqueueResult
    {
        $request = new PersistenceWriteRequest(
            $this->nextSaveId,
            $this->nextSaveId,
            'entity:' . $snapshot->chunk->key(),
            $snapshot->chunkRevision,
            '',
        );
        ++$this->nextSaveId;
        $this->queuedSaves[] = $snapshot;

        return new PersistenceEnqueueResult(PersistenceSubmission::ACCEPTED, $request);
    }

    public function pollEntityChunkSaves(int $maximumCompletions = 256, ?WorldDimension $dimension = null): array
    {
        return $this->completeSaves($maximumCompletions);
    }

    public function drainEntityChunkSaves(int $timeoutMilliseconds, ?WorldDimension $dimension = null): array
    {
        return $this->completeSaves(count($this->queuedSaves));
    }

    public function enqueueEntityOwnershipTransfer(EntityOwnershipTransfer $transfer, WorldDimension $dimension = WorldDimension::OVERWORLD): bool
    {
        $this->queuedTransfers[] = $transfer;

        return true;
    }

    public function queuedTransferCount(): int
    {
        return count($this->queuedTransfers);
    }

    public function pollEntityOwnershipTransfers(int $maximumCompletions = 256, ?WorldDimension $dimension = null): array
    {
        return $this->complete($maximumCompletions);
    }

    public function drainEntityOwnershipTransfers(int $timeoutMilliseconds, ?WorldDimension $dimension = null): array
    {
        return $this->complete(count($this->queuedTransfers));
    }

    /** @return list<EntityOwnershipTransferCompletion> */
    private function complete(int $maximumCompletions): array
    {
        $queued = array_splice($this->queuedTransfers, 0, $maximumCompletions);
        $completed = [];
        foreach ($queued as $transfer) {
            try {
                $result = $this->transferEntityOwnership($transfer);
                $completed[] = new EntityOwnershipTransferCompletion($transfer, true, result: $result);
            } catch (EntityPersistenceConflictException) {
                $completed[] = new EntityOwnershipTransferCompletion(
                    $transfer,
                    false,
                    'entity_persistence_conflict',
                );
            }
        }

        return $completed;
    }

    /** @return array<string, \Bedriox\Server\Entity\Persistence\PersistentEntityRecord> */
    private static function records(EntityChunkSnapshot $snapshot): array
    {
        $records = [];
        foreach ($snapshot->records() as $record) {
            $records[$record->uuid()] = $record;
        }

        return $records;
    }

    /** @return list<EntityChunkSaveCompletion> */
    private function completeSaves(int $maximumCompletions): array
    {
        $queued = array_splice($this->queuedSaves, 0, $maximumCompletions);
        $completed = [];
        foreach ($queued as $snapshot) {
            $this->saveEntityChunk($snapshot);
            $completed[] = new EntityChunkSaveCompletion(
                $snapshot->chunk,
                $snapshot->chunkRevision,
                true,
            );
        }

        return $completed;
    }
}
