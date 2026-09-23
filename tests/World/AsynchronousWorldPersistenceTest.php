<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use Bedriox\Server\Persistence\PersistenceWriteRequest;
use Bedriox\Server\Persistence\World\ChunkLoadCompletion;
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

    public function loadChunk(ChunkPosition $position): ?LoadedChunkData
    {
        ++$this->synchronousLoadCalls;

        return null;
    }

    public function saveWorldData(WorldData $worldData): void
    {
        $this->lifecycle[] = 'world-data';
        $this->data = $worldData;
    }

    public function saveChunk(ChunkSaveData $chunkData): void
    {
        throw new LogicException('The asynchronous world path performed a synchronous chunk save.');
    }

    public function requestChunkLoad(ChunkPosition $position): bool
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

    public function pollChunkLoads(int $maximumCompletions = 256): array
    {
        $completions = array_splice($this->loadCompletions, 0, $maximumCompletions);
        foreach ($completions as $completion) {
            unset($this->pendingLoads[$completion->position->key()]);
        }

        return $completions;
    }

    public function enqueueChunkSave(ChunkSaveData $chunkData): PersistenceEnqueueResult
    {
        $key = 'chunk:' . $chunkData->chunk->position->key();
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

        return new PersistenceEnqueueResult(PersistenceSubmission::ACCEPTED, $request);
    }

    public function completeSave(ChunkPosition $position, int $revision): void
    {
        unset($this->pendingSaves['chunk:' . $position->key()]);
        $this->saveCompletions[] = new PersistenceWriteCompletion(
            $this->nextRequestId++,
            'chunk:' . $position->key(),
            $revision,
            true,
        );
    }

    public function pollChunkSaves(int $maximumCompletions = 256): array
    {
        return array_splice($this->saveCompletions, 0, $maximumCompletions);
    }

    public function drainChunkSaves(int $timeoutMilliseconds): array
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
    private Closure $completion;
    private WorkerReceipt $receipt;

    public function submit(int $taskTypeId, string $payload, Closure $completion, ?int $deadlineNanoseconds = null): WorkerSubmission
    {
        ++$this->submissions;
        $this->completion = $completion;
        $this->receipt = new WorkerReceipt(str_repeat('g', 16), $this->submissions, $taskTypeId, 'world-generation', hrtime(true) + 1_000_000_000);

        return WorkerSubmission::accepted($this->receipt);
    }

    public function complete(Chunk $chunk, BlockStateRegistry $states): void
    {
        ($this->completion)(new WorkerResult(
            $this->receipt,
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
