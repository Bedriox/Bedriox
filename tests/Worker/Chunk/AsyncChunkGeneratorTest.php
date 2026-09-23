<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Worker\Chunk;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Worker\Chunk\AsyncChunkGenerator;
use Bedriox\Server\Worker\Chunk\ChunkGenerationRequestCodec;
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
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use Closure;
use PHPUnit\Framework\TestCase;

final class AsyncChunkGeneratorTest extends TestCase
{
    public function testWorldRetriesUntilCanonicalWorkerResultIsInstalledAndRetained(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $blocks = FixedFlatBlockPalette::fromRegistry($states);
        $generator = new FlatWorldGenerator($blocks);
        $dispatcher = new FakeChunkWorkerDispatcher();
        $async = new AsyncChunkGenerator($dispatcher, 2, 'flat', 1, 99, $states);
        $world = new World(new WorldMetadata('world', 99), $generator, new ChunkRepository(16), asyncChunks: $async);
        $position = new ChunkPosition(3, -4);

        self::assertFalse($world->requestRetainChunk($position));
        self::assertFalse($world->requestRetainChunk($position));
        self::assertSame(1, $dispatcher->submissions);
        $request = (new ChunkGenerationRequestCodec())->decode($dispatcher->payload);
        self::assertSame($position->key(), $request->position->key());

        $dispatcher->complete((new ChunkTransferCodec())->encode($generator->generate($position), $states));

        self::assertTrue($world->requestRetainChunk($position));
        self::assertSame($position->key(), $world->chunk($position)->position->key());
        $world->releaseChunk($position);
    }
}

final class FakeChunkWorkerDispatcher implements WorkerDispatcher
{
    public int $submissions = 0;
    public string $payload = '';
    private Closure $completion;
    private WorkerReceipt $receipt;

    public function submit(int $taskTypeId, string $payload, Closure $completion, ?int $deadlineNanoseconds = null): WorkerSubmission
    {
        ++$this->submissions;
        $this->payload = $payload;
        $this->completion = $completion;
        $this->receipt = new WorkerReceipt(str_repeat('x', 16), 1, $taskTypeId, 'world-generation', hrtime(true) + 1_000_000_000);

        return WorkerSubmission::accepted($this->receipt);
    }

    public function complete(string $payload): void
    {
        ($this->completion)(new WorkerResult($this->receipt, WorkerResultStatus::SUCCESS, $payload));
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
