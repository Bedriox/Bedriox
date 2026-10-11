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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\World\DimensionType;
use Bedriox\Api\World\World as PublicWorld;
use Bedriox\Server\Persistence\PersistenceQueueSnapshot;
use Bedriox\Server\Persistence\PersistenceQueueStatusProvider;
use Bedriox\Server\Runtime\ManagedWorldRuntime;
use Bedriox\Server\Runtime\OpenedWorld;
use Bedriox\Server\Runtime\WorldRuntimeManager;
use Bedriox\Server\Runtime\WorldStorageRuntime;
use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\SimulationClock;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\Tests\World\InMemoryWorldProvider;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\Provider\WorldProvider;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldGenerator;
use Bedriox\Server\World\WorldMetadata;
use LogicException;
use PHPUnit\Framework\TestCase;

final class WorldRuntimeManagerTest extends TestCase
{
    public function testLoadedRuntimeIsCanonicalAndDuplicateLoadsAreCoalesced(): void
    {
        $manager = new WorldRuntimeManager('world', self::storage(new PublicWorld('world', 1)));
        $loads = 0;
        $mines = $manager->load('mines', static function (PublicWorld $handle) use (&$loads): WorldStorageRuntime {
            ++$loads;

            return self::storage($handle);
        });
        $again = $manager->load('MINES', static function () use (&$loads): never {
            ++$loads;
            throw new \RuntimeException('Duplicate load unexpectedly reopened storage.');
        });

        self::assertSame($mines, $again);
        self::assertSame(1, $loads);
        self::assertSame(2, $manager->count());
        self::assertSame(['world', 'mines'], array_map(
            static fn(ManagedWorldRuntime $runtime): string => $runtime->handle->id(),
            $manager->loaded(),
        ));
    }

    public function testUnloadProtectsDefaultAndRejectsStaleHandlesAfterReload(): void
    {
        $manager = new WorldRuntimeManager('world', self::storage(new PublicWorld('world', 1)));

        $this->expectException(LogicException::class);
        try {
            $manager->unload($manager->default()->handle);
        } finally {
            $first = $manager->load('mines', self::storage(...));
            $manager->unload($first->handle);
            $second = $manager->load('mines', self::storage(...));
            self::assertSame(2, $second->handle->loadGeneration());
            try {
                $manager->save($first->handle);
                self::fail('A stale world handle was accepted.');
            } catch (LogicException $failure) {
                self::assertStringContainsString('earlier load generation', $failure->getMessage());
            }
        }
    }

    public function testWorldPollingUsesOneCadenceAndFairOrdering(): void
    {
        $clock = new RuntimeManagerClock();
        $manager = new WorldRuntimeManager('world', self::storage(new PublicWorld('world', 1), $clock));
        $manager->load('mines', static fn(PublicWorld $handle): WorldStorageRuntime => self::storage($handle, $clock));

        self::assertSame(50_000_000, $manager->nanosecondsUntilNextTick());
        $clock->nanoseconds += 50_000_000;
        $first = $manager->poll();
        $second = $manager->poll();

        self::assertSame(['world', 'mines'], array_keys($first));
        self::assertSame(['mines', 'world'], array_keys($second));
        self::assertCount(1, $first['world']);
        self::assertCount(1, $first['mines']);
        self::assertCount(0, $second['world']);
        self::assertCount(0, $second['mines']);
        self::assertSame(1, $manager->default()->simulation->snapshot()->tick);
        self::assertSame(1, $manager->get('mines')?->simulation->snapshot()->tick);
    }

    public function testNamedWorldStorageExposesAndPollsEveryDimension(): void
    {
        $clock = new RuntimeManagerClock();
        $overworld = self::runtime(new PublicWorld('world', 1), $clock);
        $nether = self::runtime(new PublicWorld('world', 1, dimensionType: DimensionType::NETHER), $clock, DimensionType::NETHER);
        $end = self::runtime(new PublicWorld('world', 1, dimensionType: DimensionType::END), $clock, DimensionType::END);
        $manager = new WorldRuntimeManager('world', new WorldStorageRuntime([$overworld, $nether, $end]));

        self::assertSame(1, $manager->count());
        self::assertSame([$overworld], $manager->loaded());
        self::assertSame([$overworld, $nether, $end], $manager->loadedDimensions());
        self::assertSame($overworld, $manager->default());
        self::assertSame($nether, $manager->get('world', DimensionType::NETHER));
        self::assertSame($end, $manager->default(DimensionType::END));

        $overworld->opened->world->chunk(new ChunkPosition(0, 0));
        $nether->opened->world->chunk(new ChunkPosition(1, 0));
        $end->opened->world->chunk(new ChunkPosition(2, 0));
        self::assertSame(3, $manager->loadedChunkCount());

        $clock->nanoseconds += 50_000_000;
        $ticks = $manager->poll();

        self::assertSame(['world', 'world@nether', 'world@end'], array_keys($ticks));
        self::assertCount(1, $ticks['world']);
        self::assertCount(1, $ticks['world@nether']);
        self::assertCount(1, $ticks['world@end']);
        self::assertSame($nether, $manager->runtimeForPollKey('world@nether'));
        self::assertSame($end, $manager->runtimeForPollKey('world@end'));
    }

    public function testReservedHandlesRemainCanonicalAfterEveryDimensionIsAttached(): void
    {
        $manager = new WorldRuntimeManager('world', self::storage(new PublicWorld('world', 1)));
        $overworldHandle = $manager->reserve('arena');
        $netherHandle = $overworldHandle->getDimension(DimensionType::NETHER);
        $endHandle = $overworldHandle->getDimension(DimensionType::END);

        self::assertNotNull($netherHandle);
        self::assertNotNull($endHandle);
        $storage = new WorldStorageRuntime([
            self::runtime($overworldHandle),
            self::runtime($netherHandle, dimension: DimensionType::NETHER),
            self::runtime($endHandle, dimension: DimensionType::END),
        ]);
        $manager->attach($overworldHandle, $storage);

        self::assertSame($overworldHandle, $manager->get('arena')?->handle);
        self::assertSame($netherHandle, $manager->get('arena', DimensionType::NETHER)?->handle);
        self::assertSame($endHandle, $manager->get('arena', DimensionType::END)?->handle);
        self::assertSame($netherHandle, $overworldHandle->getDimension(DimensionType::NETHER));
        self::assertSame($overworldHandle, $netherHandle->getDimension(DimensionType::OVERWORLD));
    }

    public function testRetainedPublicHandleDoesNotKeepRuntimeManagerAlive(): void
    {
        $manager = new WorldRuntimeManager('world', self::storage(new PublicWorld('world', 1)));
        $handle = $manager->reserve('temporary');
        $manager->abortReservation($handle);
        $managerReference = \WeakReference::create($manager);

        unset($manager);
        gc_collect_cycles();

        self::assertNull($managerReference->get());
        self::assertNull($handle->getDimension(DimensionType::NETHER));
    }

    public function testClosingNamedWorldStorageClosesEveryDimension(): void
    {
        $handle = new PublicWorld('world', 1);
        $metadata = new WorldMetadata($handle->id(), 0);
        $provider = new InMemoryWorldProvider(new WorldData(
            $metadata,
            'test',
            new SpawnPosition(0, 64, 0),
        ));
        $nether = self::runtime(new PublicWorld('world', 1, dimensionType: DimensionType::NETHER), dimension: DimensionType::NETHER, provider: $provider);
        $end = self::runtime(new PublicWorld('world', 1, dimensionType: DimensionType::END), dimension: DimensionType::END, provider: $provider);
        $overworld = self::runtime($handle, provider: $provider);
        $storage = new WorldStorageRuntime([$overworld, $nether, $end]);
        $storage->close(false);

        self::assertTrue($overworld->isClosed());
        self::assertTrue($nether->isClosed());
        self::assertTrue($end->isClosed());
        self::assertSame(1, $provider->closeCalls);
    }

    public function testSharedMetadataSavePreservesEachDimensionsCurrentSpawn(): void
    {
        $handle = new PublicWorld('world', 1);
        $metadata = new WorldMetadata('world', 0);
        $provider = new InMemoryWorldProvider(new WorldData(
            $metadata,
            'test',
            new SpawnPosition(0, 64, 0),
        ));
        $overworld = self::runtime($handle, provider: $provider);
        $nether = self::runtime(
            new PublicWorld('world', 1, dimensionType: DimensionType::NETHER),
            dimension: DimensionType::NETHER,
            provider: $provider,
        );
        $end = self::runtime(
            new PublicWorld('world', 1, dimensionType: DimensionType::END),
            dimension: DimensionType::END,
            provider: $provider,
        );
        $storage = new WorldStorageRuntime([$overworld, $nether, $end]);
        $nether->opened->world->setSpawn(new SpawnPosition(19, 72, -8));
        $end->opened->world->setSpawn(new SpawnPosition(-4, 66, 31));

        $storage->save();

        self::assertEquals(
            new SpawnPosition(19, 72, -8),
            $provider->data->dimension(DimensionType::NETHER)->spawn,
        );
        self::assertEquals(
            new SpawnPosition(-4, 66, 31),
            $provider->data->dimension(DimensionType::END)->spawn,
        );
    }

    public function testSchedulerBoundaryRunsOnceBeforeEveryWorldTick(): void
    {
        $clock = new RuntimeManagerClock();
        $manager = new WorldRuntimeManager('world', self::storage(new PublicWorld('world', 1), $clock));
        $manager->load('mines', static fn(PublicWorld $handle): WorldStorageRuntime => self::storage($handle, $clock));
        $observed = [];
        $clock->nanoseconds += 50_000_000;

        $manager->poll(function () use ($manager, &$observed): void {
            $observed[] = [
                $manager->default()->simulation->snapshot()->tick,
                $manager->get('mines')?->simulation->snapshot()->tick,
            ];
        });

        self::assertSame([[0, 0]], $observed);
        self::assertSame(1, $manager->default()->simulation->snapshot()->tick);
        self::assertSame(1, $manager->get('mines')?->simulation->snapshot()->tick);
    }

    public function testCapacityAndInvalidIdsFailBeforeLoading(): void
    {
        $manager = new WorldRuntimeManager('world', self::storage(new PublicWorld('world', 1)), 1);
        $called = false;

        try {
            $manager->load('other', static function () use (&$called): never {
                $called = true;
                throw new \RuntimeException('Loader should not run.');
            });
            self::fail('Capacity exhaustion was accepted.');
        } catch (LogicException) {
            self::assertFalse($called);
        }

        $this->expectException(\InvalidArgumentException::class);
        $manager->get('../outside');
    }

    public function testChunkAccountingAggregatesEveryLoadedWorld(): void
    {
        $default = self::runtime(new PublicWorld('world', 1));
        $manager = new WorldRuntimeManager('world', new WorldStorageRuntime([$default]));
        $mines = $manager->load('mines', self::storage(...));

        $default->opened->world->chunk(new ChunkPosition(0, 0));
        $mines->opened->world->chunk(new ChunkPosition(2, 3));
        $mines->opened->world->chunk(new ChunkPosition(3, 3));

        self::assertSame(3, $manager->loadedChunkCount());
        self::assertSame(0, $manager->dirtyChunkCount());
        self::assertSame(0, $manager->generatingChunkCount());
        $snapshot = $manager->chunkRepositorySnapshot();
        self::assertSame(32, $snapshot->capacity);
        self::assertSame(3, $snapshot->loaded);
        self::assertSame(3, $snapshot->misses);
    }

    public function testSharedPersistenceQueueIsCountedOncePerNamedWorld(): void
    {
        $handle = new PublicWorld('world', 1);
        $provider = new RuntimeManagerPersistenceProvider(new PersistenceQueueSnapshot(2, 1, 3, 4_096, 4, 5, 6));
        $manager = new WorldRuntimeManager('world', new WorldStorageRuntime([
            self::runtime($handle, provider: $provider),
            self::runtime(
                new PublicWorld('world', 1, dimensionType: DimensionType::NETHER),
                dimension: DimensionType::NETHER,
                provider: $provider,
            ),
            self::runtime(
                new PublicWorld('world', 1, dimensionType: DimensionType::END),
                dimension: DimensionType::END,
                provider: $provider,
            ),
        ]));

        self::assertEquals($provider->snapshot, $manager->persistenceQueueSnapshot());
    }

    public function testChunkUnloadMaintenanceCoversEveryDimension(): void
    {
        $overworld = self::runtime(new PublicWorld('world', 1));
        $nether = self::runtime(
            new PublicWorld('world', 1, dimensionType: DimensionType::NETHER),
            dimension: DimensionType::NETHER,
        );
        $end = self::runtime(
            new PublicWorld('world', 1, dimensionType: DimensionType::END),
            dimension: DimensionType::END,
        );
        $manager = new WorldRuntimeManager('world', new WorldStorageRuntime([$overworld, $nether, $end]));
        foreach ([$overworld, $nether, $end] as $index => $runtime) {
            $position = new ChunkPosition($index, 0);
            $runtime->opened->world->retainChunk($position);
            $runtime->opened->world->releaseChunk($position, true);
        }

        self::assertSame(3, $manager->pendingChunkUnloadCount());
        $result = $manager->processChunkUnloads(3, 5_000);
        self::assertSame(3, $result->examined);
        self::assertSame(3, $result->evicted);
        self::assertSame(0, $result->remainingQueued);
        self::assertSame(0, $manager->pendingChunkUnloadCount());
    }

    private static function runtime(
        PublicWorld $handle,
        ?RuntimeManagerClock $clock = null,
        DimensionType $dimension = DimensionType::OVERWORLD,
        ?WorldProvider $provider = null,
    ): ManagedWorldRuntime {
        $metadata = new WorldMetadata($handle->id(), 0);
        $generator = new RuntimeManagerGenerator();
        $world = new World(
            $metadata,
            $generator,
            new ChunkRepository(16),
            provider: $provider,
            dimension: $dimension,
        );
        $opened = new OpenedWorld($world, new WorldData($metadata, 'test', new SpawnPosition(0, 64, 0)));
        $simulation = new WorldSimulation();

        return new ManagedWorldRuntime(
            $handle,
            $opened,
            $simulation,
            new FixedRateWorldLoop($simulation, $clock ?? new RuntimeManagerClock()),
        );
    }

    private static function storage(
        PublicWorld $handle,
        ?RuntimeManagerClock $clock = null,
    ): WorldStorageRuntime {
        return new WorldStorageRuntime([self::runtime($handle, $clock)]);
    }
}

final class RuntimeManagerClock implements SimulationClock
{
    public int $nanoseconds = 1_000_000;

    public function nowNanoseconds(): int
    {
        return $this->nanoseconds;
    }
}

final readonly class RuntimeManagerGenerator implements WorldGenerator
{
    public function name(): string
    {
        return 'test';
    }

    public function generate(ChunkPosition $position): Chunk
    {
        return new Chunk($position, new \Bedriox\Server\World\Block\InternalBlockStateId(0), []);
    }

    public function defaultSpawn(): SpawnPosition
    {
        return new SpawnPosition(0, 64, 0);
    }
}

final class RuntimeManagerPersistenceProvider implements WorldProvider, PersistenceQueueStatusProvider
{
    private bool $closed = false;

    public function __construct(public readonly PersistenceQueueSnapshot $snapshot) {}

    public function worldData(): WorldData
    {
        if ($this->closed) {
            throw new LogicException('Provider is closed.');
        }

        return new WorldData(new WorldMetadata('world', 0), 'test', new SpawnPosition(0, 64, 0));
    }

    public function loadChunk(
        ChunkPosition $position,
        DimensionType $dimension = DimensionType::OVERWORLD,
    ): ?\Bedriox\Server\World\Provider\LoadedChunkData {
        if ($this->closed) {
            throw new LogicException('Provider is closed.');
        }

        return null;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function persistenceQueueSnapshot(): PersistenceQueueSnapshot
    {
        return $this->snapshot;
    }
}
