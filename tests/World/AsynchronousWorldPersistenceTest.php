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

namespace Bedriox\Server\Tests\World;

use Bedriox\Api\World\WorldDimension;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use Bedriox\Server\Persistence\PersistenceWriteRequest;
use Bedriox\Server\Persistence\World\ChunkLoadCompletion;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\Event\PlayerRespawned;
use Bedriox\Server\Simulation\Event\RespawnAcknowledged;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\Worker\Chunk\AsyncChunkGenerator;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerPoolSnapshot;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\Worker\WorkerSubmission;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\ChunkUnloadManager;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Provider\AsynchronousWorldProvider;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\LoadedChunkData;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;

final class AsynchronousWorldPersistenceTest extends TestCase
{
    public function testSpawnRetentionWaitsForAsynchronousStorageAndIsIdempotent(): void
    {
        [, , $generator] = self::worldDependencies();
        $provider = new FakeAsynchronousWorldProvider(self::worldData());
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
        );
        $spawnChunk = new ChunkPosition(0, 0);

        self::assertFalse($world->requestRetainSpawnChunk());
        self::assertSame([$spawnChunk->key()], $provider->loadRequests);
        self::assertSame(0, $provider->synchronousLoadCalls);

        $provider->completeMissing($spawnChunk);
        self::assertTrue($world->requestRetainSpawnChunk());
        self::assertTrue($world->requestRetainSpawnChunk());
        self::assertSame(1, $world->chunkRepositorySnapshot()->retainedChunks);
        self::assertSame(1, $world->chunkRepositorySnapshot()->retentionReferences);
        self::assertSame(0, $provider->synchronousLoadCalls);
    }

    public function testRespawnWaitsForAnUnloadedDestinationWithoutUsingSynchronousStorage(): void
    {
        [$states, $palette, $generator] = self::worldDependencies();
        $provider = new FakeAsynchronousWorldProvider(self::worldData());
        $workers = new FakeWorldGenerationDispatcher();
        $joinPosition = new ChunkPosition(0, 0);
        $respawnPosition = new ChunkPosition(2, 0);
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(16),
            provider: $provider,
            asyncChunks: new AsyncChunkGenerator($workers, 2, 'flat', 1, 7, $states),
            chunkUnloads: new ChunkUnloadManager(0),
        );

        self::assertFalse($world->requestRetainChunk($joinPosition));
        $provider->completeMissing($joinPosition);
        self::assertFalse($world->requestRetainChunk($joinPosition));
        $workers->complete($generator->generate($joinPosition), $states);
        self::assertTrue($world->requestRetainChunk($joinPosition));

        $simulation = new WorldSimulation(
            spawn: new Position(32.0, 64.0, 0.0),
            blockWorld: $world,
            blockPalette: $palette,
        );
        $commands = new SimulationCommandFactory();
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('identity', 'Player'),
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState([], 0),
            0,
            0,
        );
        self::assertTrue($simulation->enqueue($commands->join(
            'session',
            'identity',
            'Player',
            bootstrap: $bootstrap,
            loginApproved: true,
        )));
        $simulation->tick();
        $synchronousLoadsBeforeRespawn = $provider->synchronousLoadCalls;

        self::assertTrue($simulation->enqueue($commands->damage('session', 20.0)));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->acknowledgeRespawn('session')));
        self::assertSame([], $simulation->tick()->events);
        self::assertFalse($simulation->snapshot()->players[0]->alive);
        self::assertSame($synchronousLoadsBeforeRespawn, $provider->synchronousLoadCalls);

        $respawnChunks = [
            new ChunkPosition(1, -1),
            new ChunkPosition(2, -1),
            new ChunkPosition(1, 0),
            $respawnPosition,
        ];
        foreach ($respawnChunks as $chunk) {
            self::assertContains($chunk->key(), $provider->loadRequests);
            $provider->completeMissing($chunk);
        }
        self::assertSame([], $simulation->tick()->events);
        foreach ($respawnChunks as $chunk) {
            $workers->complete($generator->generate($chunk), $states);
        }
        $events = $simulation->tick()->events;

        self::assertSame(
            [RespawnAcknowledged::class, PlayerRespawned::class],
            array_map(static fn(object $event): string => $event::class, $events),
        );
        self::assertTrue($simulation->snapshot()->players[0]->alive);
        self::assertSame($synchronousLoadsBeforeRespawn, $provider->synchronousLoadCalls);
    }

    public function testLoadMustConfirmMissingBeforeWorkerGenerationBegins(): void
    {
        [$states, $palette, $generator] = self::worldDependencies();
        $provider = new FakeAsynchronousWorldProvider(self::worldData());
        $workers = new FakeWorldGenerationDispatcher();
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
            asyncChunks: new AsyncChunkGenerator($workers, 2, 'flat', 1, 7, $states),
        );
        $position = new ChunkPosition(3, -2);

        self::assertFalse($world->requestRetainChunk($position));
        self::assertSame([$position->key()], $provider->loadRequests);
        self::assertSame(0, $provider->synchronousLoadCalls);
        self::assertSame(0, $workers->submissions);

        self::assertFalse($world->requestRetainChunk($position));
        self::assertSame([$position->key()], $provider->loadRequests);
        self::assertSame(0, $workers->submissions);

        $provider->completeMissing($position);
        self::assertFalse($world->requestRetainChunk($position));
        self::assertSame(1, $workers->submissions);

        $workers->complete($generator->generate($position), $states);
        self::assertTrue($world->requestRetainChunk($position));
        self::assertSame($palette->grassBlock->value, $world->chunk($position)->blockStateAt(0, 63, 0)->value);
        self::assertSame(0, $provider->synchronousLoadCalls);
        $world->releaseChunk($position);
    }

    public function testZeroWorkerFallbackStillWaitsForNonblockingStorageMiss(): void
    {
        [, $palette, $generator] = self::worldDependencies();
        $provider = new FakeAsynchronousWorldProvider(self::worldData());
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
        );
        $position = new ChunkPosition(-4, 6);

        self::assertFalse($world->requestRetainChunk($position));
        self::assertSame(0, $provider->synchronousLoadCalls);

        $provider->completeMissing($position);
        self::assertTrue($world->requestRetainChunk($position));
        self::assertSame($palette->grassBlock->value, $world->chunk($position)->blockStateAt(0, 63, 0)->value);
        self::assertSame(0, $provider->synchronousLoadCalls);
        $world->releaseChunk($position);
    }

    public function testOnlyTheAcknowledgedChunkRevisionBecomesPersistedDuringARace(): void
    {
        [$states, $palette, $generator] = self::worldDependencies();
        $provider = new FakeAsynchronousWorldProvider(self::worldData());
        $workers = new FakeWorldGenerationDispatcher();
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
            asyncChunks: new AsyncChunkGenerator($workers, 2, 'flat', 1, 7, $states),
        );
        $position = new ChunkPosition(0, 0);
        $provider->completeMissingAfterRequest = true;

        self::assertFalse($world->requestRetainChunk($position));
        self::assertFalse($world->requestRetainChunk($position));
        $workers->complete($generator->generate($position), $states);
        self::assertTrue($world->requestRetainChunk($position));
        $world->releaseChunk($position);

        self::assertSame(1, $world->autosave(1));
        self::assertSame([1], $provider->submittedRevisions);
        $world->setBlockState(0, 63, 0, $palette->air);

        $provider->completeSave($position, 1);
        self::assertSame(1, $world->autosave(1));
        self::assertSame([1, 2], $provider->submittedRevisions);
        self::assertSame(1, $world->dirtyChunkCount());
        self::assertSame(1, $world->chunk($position)->persistedRevision);

        $provider->completeSave($position, 2);
        self::assertSame(0, $world->autosave(1));
        self::assertSame(0, $world->dirtyChunkCount());
        self::assertSame(2, $world->chunk($position)->persistedRevision);
    }

    public function testCloseDrainsChunkWritesBeforeMetadataAndProviderClosure(): void
    {
        [, , $generator] = self::worldDependencies();
        $provider = new FakeAsynchronousWorldProvider(self::worldData());
        $provider->completeDrainedSaves = true;
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
        );
        $world->chunk(new ChunkPosition(4, 5));

        $world->close();
        $world->close();

        self::assertSame(0, $world->dirtyChunkCount());
        self::assertSame(['drain', 'world-data', 'close'], $provider->lifecycle);
        self::assertSame(1, $provider->closeCalls);
    }

    public function testDimensionQualifiedSaveCompletionAcknowledgesTheCorrectChunk(): void
    {
        [$states, , $generator] = self::worldDependencies();
        $provider = new FakeAsynchronousWorldProvider(self::worldData());
        $workers = new FakeWorldGenerationDispatcher();
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
            asyncChunks: new AsyncChunkGenerator($workers, 2, 'flat', 1, 7, $states),
            dimension: WorldDimension::NETHER,
        );
        $position = new ChunkPosition(2, -3);
        $provider->completeMissingAfterRequest = true;

        self::assertFalse($world->requestRetainChunk($position));
        self::assertFalse($world->requestRetainChunk($position));
        $workers->complete($generator->generate($position), $states);
        self::assertTrue($world->requestRetainChunk($position));
        $world->releaseChunk($position);

        self::assertSame(1, $world->autosave(1));
        self::assertSame(['chunk:NETHER:2:-3'], $provider->submittedKeys);
        $provider->completeSave($position, 1, WorldDimension::NETHER);
        $world->pollAsynchronousCompletions();
        self::assertSame(0, $world->dirtyChunkCount());
    }

    public function testPendingOldestSaveDoesNotStarveLaterDirtyChunks(): void
    {
        [, , $generator] = self::worldDependencies();
        $provider = new FakeAsynchronousWorldProvider(self::worldData());
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
        );
        $positions = [new ChunkPosition(0, 0), new ChunkPosition(1, 0), new ChunkPosition(2, 0)];
        foreach ($positions as $position) {
            $world->chunk($position);
        }

        self::assertSame(1, $world->autosave(1));
        self::assertSame(1, $world->autosave(1));
        self::assertSame(1, $world->autosave(1));
        self::assertSame(['chunk:0:0', 'chunk:1:0', 'chunk:2:0'], $provider->submittedKeys);
        self::assertSame(3, $world->dirtyChunkCount());
    }

    public function testDirtyChunkEvictsOnlyAfterExactAsynchronousAcknowledgement(): void
    {
        [, , $generator] = self::worldDependencies();
        $provider = new FakeAsynchronousWorldProvider(self::worldData());
        $position = new ChunkPosition(0, 0);
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
            chunkUnloads: new ChunkUnloadManager(0),
        );
        $world->retainChunk($position);
        $world->releaseChunk($position);

        $submitted = $world->processChunkUnloads();
        self::assertSame(1, $submitted->saveSubmissions);
        self::assertSame(0, $submitted->evicted);
        self::assertSame(1, $world->loadedChunkCount());

        $provider->completeSave($position, 1);
        $completed = $world->processChunkUnloads();
        self::assertSame(1, $completed->evicted);
        self::assertSame(0, $world->loadedChunkCount());
        self::assertSame(0, $world->pendingChunkUnloadCount());
    }

    public function testChunkChangedDuringUnloadSaveRemainsDirtyUntilNewRevisionIsAcknowledged(): void
    {
        [, $palette, $generator] = self::worldDependencies();
        $provider = new FakeAsynchronousWorldProvider(self::worldData());
        $position = new ChunkPosition(0, 0);
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
            chunkUnloads: new ChunkUnloadManager(0),
        );
        $world->retainChunk($position);
        $world->releaseChunk($position);
        $world->processChunkUnloads();
        $world->setBlockState(0, 63, 0, $palette->air);

        $provider->completeSave($position, 1);
        $resubmitted = $world->processChunkUnloads();
        self::assertSame(1, $resubmitted->saveSubmissions);
        self::assertSame(0, $resubmitted->evicted);
        self::assertSame([1, 2], $provider->submittedRevisions);
        self::assertSame(1, $world->dirtyChunkCount());

        $provider->completeSave($position, 2);
        self::assertSame(1, $world->processChunkUnloads()->evicted);
        self::assertSame(0, $world->loadedChunkCount());
    }

    public function testFailedUnloadSavePreservesChunkAndAllowsExactRevisionRetry(): void
    {
        [, , $generator] = self::worldDependencies();
        $provider = new FakeAsynchronousWorldProvider(self::worldData());
        $position = new ChunkPosition(0, 0);
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
            chunkUnloads: new ChunkUnloadManager(0),
        );
        $world->retainChunk($position);
        $world->releaseChunk($position);
        $world->processChunkUnloads();

        $provider->completeSaveFailure($position, 1);
        $retry = $world->processChunkUnloads();
        self::assertSame(1, $retry->saveSubmissions);
        self::assertSame(0, $retry->evicted);
        self::assertSame([1, 1], $provider->submittedRevisions);
        self::assertSame(1, $world->dirtyChunkCount());
        self::assertSame(1, $world->loadedChunkCount());
    }

    public function testPendingUnloadSaveDoesNotStarveLaterCandidates(): void
    {
        [, , $generator] = self::worldDependencies();
        $provider = new FakeAsynchronousWorldProvider(self::worldData());
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
            chunkUnloads: new ChunkUnloadManager(0),
        );
        $positions = [new ChunkPosition(0, 0), new ChunkPosition(1, 0), new ChunkPosition(2, 0)];
        foreach ($positions as $position) {
            $world->retainChunk($position);
            $world->releaseChunk($position);
        }

        self::assertSame(1, $world->processChunkUnloads(1)->saveSubmissions);
        self::assertSame(1, $world->processChunkUnloads(1)->saveSubmissions);
        self::assertSame(1, $world->processChunkUnloads(1)->saveSubmissions);
        self::assertSame(['chunk:0:0', 'chunk:1:0', 'chunk:2:0'], $provider->submittedKeys);
    }

    public function testRetainingAgainCancelsQueuedUnload(): void
    {
        [, , $generator] = self::worldDependencies();
        $position = new ChunkPosition(0, 0);
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            chunkUnloads: new ChunkUnloadManager(0),
        );
        $world->retainChunk($position);
        $world->releaseChunk($position);
        self::assertSame(1, $world->pendingChunkUnloadCount());

        $world->retainChunk($position);
        self::assertSame(0, $world->pendingChunkUnloadCount());
        self::assertSame(0, $world->processChunkUnloads()->evicted);
        self::assertSame(1, $world->loadedChunkCount());
    }

    public function testUnretainedChunkAccessIsQueuedForBoundedUnload(): void
    {
        [, , $generator] = self::worldDependencies();
        $world = new World(
            new WorldMetadata('world', 7),
            $generator,
            new ChunkRepository(4),
            chunkUnloads: new ChunkUnloadManager(0),
        );
        $position = new ChunkPosition(0, 0);

        $world->chunk($position);
        self::assertSame(1, $world->pendingChunkUnloadCount());
        self::assertSame(1, $world->processChunkUnloads()->evicted);
        self::assertSame(0, $world->loadedChunkCount());
    }

    /** @return array{BlockStateRegistry, FixedFlatBlockPalette, FlatWorldGenerator} */
    private static function worldDependencies(): array
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);

        return [$states, $palette, new FlatWorldGenerator($palette)];
    }

    private static function worldData(): WorldData
    {
        return new WorldData(
            new WorldMetadata('world', 7),
            'flat',
            new SpawnPosition(0, 64, 0),
        );
    }
}

final class FakeAsynchronousWorldProvider implements AsynchronousWorldProvider
{
    /** @var list<string> */
    public array $loadRequests = [];
    /** @var list<int> */
    public array $submittedRevisions = [];
    /** @var list<string> */
    public array $submittedKeys = [];
    /** @var list<string> */
    public array $lifecycle = [];
    public int $synchronousLoadCalls = 0;
    public int $closeCalls = 0;
    public bool $completeMissingAfterRequest = false;
    public bool $completeDrainedSaves = false;
    /** @var array<string, true> */
    private array $pendingLoads = [];
    /** @var list<ChunkLoadCompletion> */
    private array $loadCompletions = [];
    /** @var list<PersistenceWriteCompletion> */
    private array $saveCompletions = [];
    /** @var array<string, ChunkSaveData> */
    private array $pendingSaves = [];
    private int $nextRequestId = 1;

    public function __construct(private WorldData $data) {}

    public function worldData(): WorldData
    {
        return $this->data;
    }

    public function loadChunk(ChunkPosition $position, WorldDimension $dimension = WorldDimension::OVERWORLD): ?LoadedChunkData
    {
        ++$this->synchronousLoadCalls;

        return null;
    }

    public function saveWorldData(WorldData $worldData): void
    {
        $this->lifecycle[] = 'world-data';
        $this->data = $worldData;
    }

    public function saveChunk(ChunkSaveData $chunkData, WorldDimension $dimension = WorldDimension::OVERWORLD): void
    {
        throw new LogicException('The asynchronous world path performed a synchronous chunk save.');
    }

    public function requestChunkLoad(ChunkPosition $position, WorldDimension $dimension = WorldDimension::OVERWORLD): bool
    {
        $key = $position->key();
        if (isset($this->pendingLoads[$key])) {
            return false;
        }
        $this->pendingLoads[$key] = true;
        $this->loadRequests[] = $key;
        if ($this->completeMissingAfterRequest) {
            $this->completeMissing($position);
        }

        return true;
    }

    public function completeMissing(ChunkPosition $position): void
    {
        $this->loadCompletions[] = new ChunkLoadCompletion($position, null, true);
    }

    public function pollChunkLoads(int $maximumCompletions = 256, ?WorldDimension $dimension = null): array
    {
        $completions = array_splice($this->loadCompletions, 0, $maximumCompletions);
        foreach ($completions as $completion) {
            unset($this->pendingLoads[$completion->position->key()]);
        }

        return $completions;
    }

    public function enqueueChunkSave(ChunkSaveData $chunkData, WorldDimension $dimension = WorldDimension::OVERWORLD): PersistenceEnqueueResult
    {
        $key = 'chunk:' . ($dimension === WorldDimension::OVERWORLD ? '' : $dimension->name . ':')
            . $chunkData->chunk->position->key();
        $existing = $this->pendingSaves[$key] ?? null;
        if ($existing instanceof ChunkSaveData && $existing->revision >= $chunkData->revision) {
            return new PersistenceEnqueueResult(PersistenceSubmission::STALE, null);
        }
        $request = new PersistenceWriteRequest(
            $this->nextRequestId++,
            count($this->submittedRevisions) + 1,
            $key,
            $chunkData->revision,
            'chunk',
        );
        $this->pendingSaves[$key] = $chunkData;
        $this->submittedRevisions[] = $chunkData->revision;
        $this->submittedKeys[] = $key;

        return new PersistenceEnqueueResult(PersistenceSubmission::ACCEPTED, $request);
    }

    public function completeSave(
        ChunkPosition $position,
        int $revision,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): void {
        $key = 'chunk:' . ($dimension === WorldDimension::OVERWORLD ? '' : $dimension->name . ':') . $position->key();
        unset($this->pendingSaves[$key]);
        $this->saveCompletions[] = new PersistenceWriteCompletion(
            $this->nextRequestId++,
            $key,
            $revision,
            true,
        );
    }

    public function completeSaveFailure(ChunkPosition $position, int $revision): void
    {
        unset($this->pendingSaves['chunk:' . $position->key()]);
        $this->saveCompletions[] = new PersistenceWriteCompletion(
            $this->nextRequestId++,
            'chunk:' . $position->key(),
            $revision,
            false,
            'disk_full',
        );
    }

    public function pollChunkSaves(int $maximumCompletions = 256, ?WorldDimension $dimension = null): array
    {
        return array_splice($this->saveCompletions, 0, $maximumCompletions);
    }

    public function drainChunkSaves(int $timeoutMilliseconds, ?WorldDimension $dimension = null): array
    {
        $this->lifecycle[] = 'drain';
        if ($this->completeDrainedSaves) {
            foreach ($this->pendingSaves as $key => $save) {
                $this->saveCompletions[] = new PersistenceWriteCompletion(
                    $this->nextRequestId++,
                    $key,
                    $save->revision,
                    true,
                );
            }
            $this->pendingSaves = [];
        }

        return $this->pollChunkSaves();
    }

    public function close(): void
    {
        ++$this->closeCalls;
        $this->lifecycle[] = 'close';
    }
}

final class FakeWorldGenerationDispatcher implements WorkerDispatcher
{
    public int $submissions = 0;

    /** @var list<array{Closure(WorkerResult): void, WorkerReceipt}> */
    private array $pending = [];

    public function submit(int $taskTypeId, string $payload, Closure $completion, ?int $deadlineNanoseconds = null): WorkerSubmission
    {
        ++$this->submissions;
        $receipt = new WorkerReceipt(str_repeat('g', 16), $this->submissions, $taskTypeId, 'world-generation', hrtime(true) + 1_000_000_000);
        $this->pending[] = [$completion, $receipt];

        return WorkerSubmission::accepted($receipt);
    }

    public function complete(Chunk $chunk, BlockStateRegistry $states): void
    {
        [$completion, $receipt] = array_shift($this->pending)
            ?? throw new LogicException('No world-generation task is pending.');
        $completion(new WorkerResult(
            $receipt,
            WorkerResultStatus::SUCCESS,
            (new ChunkTransferCodec())->encode($chunk, $states),
        ));
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        return true;
    }

    public function poll(int $maximumCompletions = 256): void {}

    public function snapshot(): WorkerPoolSnapshot
    {
        return new WorkerPoolSnapshot('', 1, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, true);
    }

    public function shutdown(): void {}
}
