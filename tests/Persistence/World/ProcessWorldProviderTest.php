<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Persistence\World;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\CorruptEntityPersistenceException;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransfer;
use Bedriox\Server\Entity\Persistence\EntityPersistenceCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceRecord;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\World\ProcessWorldProvider;
use Bedriox\Server\Persistence\World\ProcessWorldProviderFactory;
use Bedriox\Server\Persistence\World\WorldDataIpcCodec;
use Bedriox\Server\Persistence\World\WorldStorageStartup;
use Bedriox\Server\Persistence\World\WorldStorageStartupCodec;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\ManagedWorkerDispatcher;
use Bedriox\Server\Worker\ManagedWorkerPool;
use Bedriox\Server\Worker\Task\SpawnWorldStorageOwnerRequest;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerPoolSnapshot;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerRejectionReason;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\Worker\WorkerSubmission;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\VanillaBlockStates;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\Exception\CorruptChunkException;
use Bedriox\Server\World\Provider\Exception\WorldProviderClosedException;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\WorldGeneratorFactory;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class ProcessWorldProviderTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../Fixtures/bedriox-world-storage-fixture';

    public function testWorldDataAndStartupCodecsRoundTripExactValues(): void
    {
        $data = self::worldData();
        $worldCodec = new WorldDataIpcCodec();
        self::assertEquals($data, $worldCodec->decode($worldCodec->encode($data)));

        $startup = new WorldStorageStartup('create', 'C:\\worlds\\test', $data, 1234);
        $startupCodec = new WorldStorageStartupCodec();
        self::assertEquals($startup, $startupCodec->decode($startupCodec->encode($startup)));
    }

    public function testDedicatedOwnerSavesAndLoadsCanonicalChunkAndClosesCleanly(): void
    {
        $states = self::states();
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-equivalence', self::worldData(), 1234),
            $states,
            entryPoint: self::FIXTURE,
        );
        $chunk = WorldGeneratorFactory::create('flat', 42, $states)->generate(new ChunkPosition(-2, 3));
        $provider->saveChunk(new ChunkSaveData($chunk));
        $loaded = $provider->loadChunk($chunk->position);

        self::assertNotNull($loaded);
        self::assertEquals($chunk->position, $loaded->chunk->position);
        self::assertSame($chunk->revision, $loaded->chunk->revision);
        self::assertSame($chunk->revision, $loaded->chunk->persistedRevision);
        self::assertFalse($loaded->upgraded);
        self::assertNull($provider->loadChunk(new ChunkPosition(100, 100)));

        $updated = new WorldData($provider->worldData()->metadata, 'flat', new SpawnPosition(8, 70, 9), 99, 3);
        $provider->saveWorldData($updated);
        self::assertEquals($updated, $provider->worldData());
        $provider->close();
        self::assertTrue($provider->ownerConfirmedClosed());

        $this->expectException(WorldProviderClosedException::class);
        $provider->worldData();
    }

    public function testDedicatedOwnerStartupCanBePolledWithoutBlockingTheCaller(): void
    {
        $startedAt = hrtime(true);
        $provider = ProcessWorldProvider::beginStart(
            'test-version',
            new WorldStorageStartup('create', 'fixture-slow-start', self::worldData(), 1234),
            self::states(),
            entryPoint: self::FIXTURE,
        );
        try {
            self::assertLessThan(1_000_000_000, hrtime(true) - $startedAt);
            self::assertFalse($provider->pollStartup());

            $ready = false;
            $maximumPollNanoseconds = 0;
            $deadline = hrtime(true) + 8_000_000_000;
            while (hrtime(true) < $deadline) {
                $pollStartedAt = hrtime(true);
                $ready = $provider->pollStartup();
                $maximumPollNanoseconds = max($maximumPollNanoseconds, hrtime(true) - $pollStartedAt);
                if ($ready) {
                    break;
                }
                usleep(1_000);
            }

            self::assertTrue($ready);
            self::assertLessThan(1_000_000_000, $maximumPollNanoseconds);
            self::assertSame('Process Test', $provider->worldData()->metadata->name);
        } finally {
            $provider->close();
        }
    }

    public function testDedicatedOwnerCanDelegateColdProcessLaunchWithoutBlockingTheCaller(): void
    {
        $launcher = new class implements WorkerDispatcher {
            public ?string $submittedPayload = null;
            public ?int $submittedTaskType = null;

            public function submit(int $taskTypeId, string $payload, \Closure $completion, ?int $deadlineNanoseconds = null): WorkerSubmission
            {
                unset($completion);
                $this->submittedPayload = $payload;
                $this->submittedTaskType = $taskTypeId;

                return WorkerSubmission::accepted(new WorkerReceipt('test', 1, $taskTypeId, 'test', $deadlineNanoseconds ?? PHP_INT_MAX));
            }

            public function cancel(WorkerReceipt $receipt): bool
            {
                return false;
            }

            public function poll(int $maximumCompletions = 256): void {}

            public function snapshot(): WorkerPoolSnapshot
            {
                return new WorkerPoolSnapshot('test', 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, true);
            }

            public function shutdown(): void {}
        };
        $worldData = self::worldData();
        $states = self::states();
        $startedAt = hrtime(true);
        $provider = ProcessWorldProvider::beginStart(
            'test-version',
            new WorldStorageStartup('create', 'delegated-launch', $worldData, 1234),
            $states,
            processLauncher: $launcher,
            processLauncherTaskType: 7,
        );
        try {
            self::assertLessThan(50_000_000, hrtime(true) - $startedAt);
            self::assertSame(7, $launcher->submittedTaskType);
            self::assertIsString($launcher->submittedPayload);
            $request = SpawnWorldStorageOwnerRequest::decode($launcher->submittedPayload);
            self::assertSame('test-version', $request->applicationVersion);
            self::assertStringStartsWith('tcp://127.0.0.1:', $request->endpoint);
            self::assertFalse($provider->pollStartup());
        } finally {
            $provider->close();
        }
    }

    public function testFactorySharesItsImmutableEntityCodecAcrossDynamicProviders(): void
    {
        $launcher = new class implements WorkerDispatcher {
            private int $nextTaskId = 1;

            public function submit(int $taskTypeId, string $payload, \Closure $completion, ?int $deadlineNanoseconds = null): WorkerSubmission
            {
                unset($payload, $completion);

                return WorkerSubmission::accepted(new WorkerReceipt(
                    'test',
                    $this->nextTaskId++,
                    $taskTypeId,
                    'test',
                    $deadlineNanoseconds ?? PHP_INT_MAX,
                ));
            }

            public function cancel(WorkerReceipt $receipt): bool
            {
                return false;
            }

            public function poll(int $maximumCompletions = 256): void {}

            public function snapshot(): WorkerPoolSnapshot
            {
                return new WorkerPoolSnapshot('test', 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, true);
            }

            public function shutdown(): void {}
        };
        $codec = new EntityPersistenceCodec(['minecraft:cow']);
        $factory = new ProcessWorldProviderFactory(
            'test-version',
            entryPoint: self::FIXTURE,
            entityPersistenceCodec: $codec,
        );
        $factory->useProcessLauncher($launcher, 7);
        $states = self::states();
        $first = $factory->beginCreate('first', self::worldData(), $states);
        $second = $factory->beginCreate('second', self::worldData(), $states);

        try {
            $property = new \ReflectionProperty(ProcessWorldProvider::class, 'entityPersistenceCodec');
            self::assertSame($codec, $property->getValue($first));
            self::assertSame($codec, $property->getValue($second));
        } finally {
            $first->close();
            $second->close();
        }
    }

    public function testDelegatedOwnerRemainsOpenWithoutAParentProcessHandle(): void
    {
        $fixture = self::FIXTURE;
        $launcher = new class ($fixture) implements WorkerDispatcher {
            /** @var resource|null */
            private mixed $process = null;

            public function __construct(private readonly string $fixture) {}

            public function submit(int $taskTypeId, string $payload, \Closure $completion, ?int $deadlineNanoseconds = null): WorkerSubmission
            {
                unset($completion);
                $request = SpawnWorldStorageOwnerRequest::decode($payload);
                $pipes = [];
                $process = proc_open(
                    [
                        PHP_BINARY,
                        $this->fixture,
                        'world',
                        $request->epochHex,
                        $request->applicationVersion,
                        $request->endpoint,
                        $request->tokenHex,
                    ],
                    [
                        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                        1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
                        2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
                    ],
                    $pipes,
                    dirname($this->fixture, 2),
                    options: ['bypass_shell' => true, 'blocking_pipes' => false],
                );
                if (!is_resource($process)) {
                    return WorkerSubmission::rejected(WorkerRejectionReason::UNAVAILABLE_BROKER);
                }
                $this->process = $process;

                return WorkerSubmission::accepted(new WorkerReceipt('test', 1, $taskTypeId, 'test', $deadlineNanoseconds ?? PHP_INT_MAX));
            }

            public function cancel(WorkerReceipt $receipt): bool
            {
                return false;
            }

            public function poll(int $maximumCompletions = 256): void {}

            public function snapshot(): WorkerPoolSnapshot
            {
                return new WorkerPoolSnapshot('test', 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, true);
            }

            public function shutdown(): void
            {
                if (!is_resource($this->process)) {
                    return;
                }
                $deadline = hrtime(true) + 1_000_000_000;
                do {
                    $status = proc_get_status($this->process);
                    if (!$status['running']) {
                        proc_close($this->process);
                        $this->process = null;

                        return;
                    }
                    usleep(1_000);
                } while (hrtime(true) < $deadline);
                proc_terminate($this->process);
                proc_close($this->process);
                $this->process = null;
            }
        };
        $provider = ProcessWorldProvider::beginStart(
            'test-version',
            new WorldStorageStartup('create', 'delegated-ready', self::worldData(), 1234),
            self::states(),
            processLauncher: $launcher,
            processLauncherTaskType: 7,
        );
        try {
            $deadline = hrtime(true) + 8_000_000_000;
            while (!$provider->pollStartup() && hrtime(true) < $deadline) {
                usleep(1_000);
            }

            self::assertSame('Process Test', $provider->worldData()->metadata->name);
            $provider->close();
            self::assertTrue($provider->ownerConfirmedClosed());
            $provider->close();
        } finally {
            $provider->close();
            $launcher->shutdown();
        }
    }

    public function testFailedDelegatedStartupCanBeClosedRepeatedly(): void
    {
        $launcher = new class implements WorkerDispatcher {
            public function submit(int $taskTypeId, string $payload, \Closure $completion, ?int $deadlineNanoseconds = null): WorkerSubmission
            {
                unset($payload);
                $receipt = new WorkerReceipt('test', 1, $taskTypeId, 'test', $deadlineNanoseconds ?? PHP_INT_MAX);
                $completion(new WorkerResult($receipt, WorkerResultStatus::FAILED, failureCode: 'launch_failed'));

                return WorkerSubmission::accepted($receipt);
            }

            public function cancel(WorkerReceipt $receipt): bool
            {
                return false;
            }

            public function poll(int $maximumCompletions = 256): void {}

            public function snapshot(): WorkerPoolSnapshot
            {
                return new WorkerPoolSnapshot('test', 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, true);
            }

            public function shutdown(): void {}
        };
        $provider = ProcessWorldProvider::beginStart(
            'test-version',
            new WorldStorageStartup('create', 'delegated-failure', self::worldData(), 1234),
            self::states(),
            processLauncher: $launcher,
            processLauncherTaskType: 7,
        );

        try {
            $provider->pollStartup();
            self::fail('Failed delegated startup was accepted.');
        } catch (WorldStorageException $failure) {
            self::assertStringContainsString('launch failed', $failure->getMessage());
        }
        $provider->close();
        $provider->close();
    }

    public function testProductionWorkerLaunchesDedicatedOwnerThroughCompleteStartupHandshake(): void
    {
        if (!extension_loaded('leveldb')) {
            self::markTestSkipped('The production world-storage handshake requires the qualified LevelDB runtime.');
        }
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-worker-world-' . bin2hex(random_bytes(8));
        $pool = ManagedWorkerPool::start('test-version', 1);
        $dispatcher = new ManagedWorkerDispatcher($pool);
        $provider = ProcessWorldProvider::beginStart(
            'test-version',
            new WorldStorageStartup('create', $directory, self::worldData(), 1234),
            self::states(),
            processLauncher: $dispatcher,
            processLauncherTaskType: CoreWorkerTaskCatalog::SPAWN_WORLD_STORAGE_OWNER,
        );
        try {
            $ready = false;
            $deadline = hrtime(true) + 10_000_000_000;
            do {
                $dispatcher->poll();
                if ($provider->pollStartup()) {
                    $ready = true;
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);

            self::assertTrue($ready, $pool->diagnostic());
            self::assertSame('Process Test', $provider->worldData()->metadata->name);
        } finally {
            $provider->close();
            $dispatcher->shutdown();
            self::removeDirectory($directory);
        }
    }

    public function testCorruptChunkCategoryCrossesProcessBoundary(): void
    {
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-corrupt', self::worldData(), 1234),
            self::states(),
            entryPoint: self::FIXTURE,
        );
        try {
            $this->expectException(CorruptChunkException::class);
            $provider->loadChunk(new ChunkPosition(0, 0));
        } finally {
            $provider->close();
        }
    }

    public function testEntitySnapshotsAndAtomicOwnershipTransferCrossProcessBoundary(): void
    {
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-entities', self::worldData(), 1234),
            self::states(),
            entryPoint: self::FIXTURE,
        );
        $source = new ChunkPosition(0, 0);
        $destination = new ChunkPosition(1, 0);
        $uuid = '123e4567-e89b-42d3-a456-426614174000';
        try {
            self::assertNull($provider->loadEntityChunk($source));
            $provider->saveEntityChunk(new EntityChunkSnapshot('Process Test', $source, 1, [
                self::entityRecord($uuid, $source, 3),
            ]));
            $provider->saveEntityChunk(new EntityChunkSnapshot('Process Test', $destination, 1, []));
            self::assertSame([$uuid => 3], $provider->loadEntityChunk($source)?->revisions());

            $result = $provider->transferEntityOwnership(new EntityOwnershipTransfer(
                $uuid,
                3,
                new EntityChunkSnapshot('Process Test', $source, 2, []),
                new EntityChunkSnapshot('Process Test', $destination, 2, [
                    self::entityRecord($uuid, $destination, 4),
                ]),
            ));

            self::assertSame(2, $result->sourceAfter->chunkRevision);
            self::assertSame(2, $result->destinationAfter->chunkRevision);
            $savedSource = $provider->loadEntityChunk($source);
            $savedDestination = $provider->loadEntityChunk($destination);
            self::assertInstanceOf(EntityChunkSnapshot::class, $savedSource);
            self::assertInstanceOf(EntityChunkSnapshot::class, $savedDestination);
            self::assertSame([], $savedSource->records());
            self::assertSame([$uuid => 4], $savedDestination->revisions());
        } finally {
            $provider->close();
        }
    }

    public function testTerrainLoadPreloadsItsEntityOwnerSnapshot(): void
    {
        $states = self::states();
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-entity-bundle', self::worldData(), 1234),
            $states,
            entryPoint: self::FIXTURE,
        );
        $position = new ChunkPosition(2, -3);
        $uuid = '123e4567-e89b-42d3-a456-426614174001';
        try {
            $provider->saveChunk(new ChunkSaveData(WorldGeneratorFactory::create('flat', 42, $states)->generate($position)));
            $provider->saveEntityChunk(new EntityChunkSnapshot('Process Test', $position, 1, [
                self::entityRecord($uuid, $position, 7),
            ]));

            self::assertNotNull($provider->loadChunk($position));
            self::assertSame([$uuid => 7], $provider->loadEntityChunk($position)?->revisions());
        } finally {
            $provider->close();
        }
    }

    public function testEntityChunkSaveCompletesThroughTheNonblockingPersistenceQueue(): void
    {
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-async-entity-save', self::worldData(), 1234),
            self::states(),
            entryPoint: self::FIXTURE,
        );
        $position = new ChunkPosition(3, -2);
        $uuid = '123e4567-e89b-42d3-a456-426614174002';
        $snapshot = new EntityChunkSnapshot('Process Test', $position, 1, [
            self::entityRecord($uuid, $position, 9),
        ]);
        try {
            $started = hrtime(true);
            self::assertSame(PersistenceSubmission::ACCEPTED, $provider->enqueueEntityChunkSave($snapshot)->status);
            self::assertLessThan(50_000_000, hrtime(true) - $started);

            $completions = [];
            $deadline = hrtime(true) + 2_000_000_000;
            do {
                $completions = $provider->pollEntityChunkSaves();
                if ($completions !== []) {
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);

            self::assertCount(1, $completions);
            self::assertTrue($completions[0]->successful);
            self::assertEquals($position, $completions[0]->chunk);
            self::assertSame(1, $completions[0]->revision);
            self::assertSame([$uuid => 9], $provider->loadEntityChunk($position)?->revisions());
        } finally {
            $provider->close();
        }
    }

    public function testEntityPersistenceDoesNotDrainQueuedTerrainLoads(): void
    {
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-timeout-entity-barrier', self::worldData(), 1234),
            self::states(),
            2_000,
            self::FIXTURE,
        );
        $queued = new ChunkPosition(40, 40);
        $entityChunk = new ChunkPosition(0, 0);
        try {
            self::assertTrue($provider->requestChunkLoad($queued));

            $started = hrtime(true);
            $provider->saveEntityChunk(new EntityChunkSnapshot('Process Test', $entityChunk, 1, []));
            self::assertLessThan(
                250_000_000,
                hrtime(true) - $started,
                'Entity persistence drained terrain work which had not started.',
            );

            self::assertSame([], $provider->pollChunkLoads());
            $loads = [];
            $deadline = hrtime(true) + 2_000_000_000;
            do {
                $loads = $provider->pollChunkLoads();
                if ($loads !== []) {
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);
            self::assertCount(1, $loads);
            self::assertEquals($queued, $loads[0]->position);
        } finally {
            $provider->close();
        }
    }

    public function testWorldMetadataPersistenceDoesNotDrainQueuedTerrainLoads(): void
    {
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-timeout-metadata-barrier', self::worldData(), 1234),
            self::states(),
            2_000,
            self::FIXTURE,
        );
        $queued = new ChunkPosition(40, 40);
        try {
            self::assertTrue($provider->requestChunkLoad($queued));
            $updated = new WorldData(
                $provider->worldData()->metadata,
                'flat',
                new SpawnPosition(8, 70, 9),
                99,
                3,
            );

            $started = hrtime(true);
            $provider->saveWorldData($updated);
            self::assertLessThan(
                250_000_000,
                hrtime(true) - $started,
                'World metadata persistence drained terrain work which had not started.',
            );
            self::assertEquals($updated, $provider->worldData());
            self::assertSame([], $provider->pollChunkLoads());

            $loads = [];
            $deadline = hrtime(true) + 2_000_000_000;
            do {
                $loads = $provider->pollChunkLoads();
                if ($loads !== []) {
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);
            self::assertCount(1, $loads);
            self::assertEquals($queued, $loads[0]->position);
        } finally {
            $provider->close();
        }
    }

    public function testCorruptEntitySnapshotDoesNotTerminateStorageOwner(): void
    {
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-entity-corrupt', self::worldData(), 1234),
            self::states(),
            entryPoint: self::FIXTURE,
        );
        try {
            try {
                $provider->loadEntityChunk(new ChunkPosition(0, 0));
                self::fail('Corrupt entity persistence was accepted.');
            } catch (CorruptEntityPersistenceException $error) {
                self::assertEquals(new ChunkPosition(0, 0), $error->chunk);
            }
            self::assertNull($provider->loadEntityChunk(new ChunkPosition(1, 0)));
            self::assertNull($provider->loadChunk(new ChunkPosition(1, 0)));
        } finally {
            $provider->close();
        }
    }

    public function testNonblockingSaveCoalescesRevisionsAndAsyncLoadDeduplicatesCoordinates(): void
    {
        $states = self::states();
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-async-slow-save', self::worldData(), 1234),
            $states,
            entryPoint: self::FIXTURE,
        );
        try {
            $chunk = WorldGeneratorFactory::create('flat', 42, $states)->generate(new ChunkPosition(4, -5));
            $revisionOne = $chunk->withBlockState(0, 64, 0, $states->internalId(VanillaBlockStates::air()));
            $revisionTwo = $revisionOne->withBlockState(1, 64, 0, $states->internalId(VanillaBlockStates::stone()));

            self::assertSame(PersistenceSubmission::ACCEPTED, $provider->enqueueChunkSave(new ChunkSaveData($revisionOne))->status);
            self::assertSame(PersistenceSubmission::COALESCED, $provider->enqueueChunkSave(new ChunkSaveData($revisionTwo))->status);
            $pollStarted = hrtime(true);
            self::assertSame([], $provider->pollChunkSaves());
            self::assertLessThan(250_000_000, hrtime(true) - $pollStarted, 'Chunk save polling blocked on child disk work.');
            $completions = $provider->drainChunkSaves(5_000);
            self::assertCount(1, $completions);
            self::assertTrue($completions[0]->successful);
            self::assertSame($revisionTwo->revision, $completions[0]->revision);
            self::assertEquals($chunk->position, ProcessWorldProvider::chunkPositionFor($completions[0]));

            self::assertTrue($provider->requestChunkLoad($chunk->position));
            self::assertFalse($provider->requestChunkLoad($chunk->position));
            $loads = [];
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $loads = $provider->pollChunkLoads();
                if ($loads !== []) {
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);
            self::assertCount(1, $loads);
            self::assertNotNull($loads[0]->loaded);
            self::assertSame($revisionTwo->revision, $loads[0]->loaded->chunk->revision);
        } finally {
            $provider->close();
        }
    }

    public function testTimedOutRequestFailsOwnerWithoutSubstitutingMissingChunk(): void
    {
        $provider = ProcessWorldProvider::start(
            'test-version',
            new WorldStorageStartup('create', 'fixture-timeout', self::worldData(), 1234),
            self::states(),
            100,
            self::FIXTURE,
        );

        $this->expectException(WorldStorageException::class);
        $provider->loadChunk(new ChunkPosition(0, 0));
    }

    private static function worldData(): WorldData
    {
        return new WorldData(
            new WorldMetadata('Process Test', 42),
            'flat',
            new SpawnPosition(0, 64, 0),
            12,
            2,
            1,
        );
    }

    private static function states(): BlockStateRegistry
    {
        return new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
    }

    private static function entityRecord(
        string $uuid,
        ChunkPosition $owner,
        int $revision,
    ): EntityPersistenceRecord {
        return new EntityPersistenceRecord(
            'minecraft:cow',
            $uuid,
            'Process Test',
            $owner,
            new Position($owner->x * 16 + 0.5, 64.0, $owner->z * 16 + 0.5),
            0.0,
            0.0,
            new EntityMotion(),
            10.0,
            0,
            true,
            null,
            [],
            0,
            '',
            $revision,
        );
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $entryPath = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($entryPath)) {
                self::removeDirectory($entryPath);
            } else {
                @unlink($entryPath);
            }
        }
        @rmdir($path);
    }
}
