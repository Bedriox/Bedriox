<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Worker;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Server\Worker\Chunk\ChunkGenerationRequest;
use Bedriox\Server\Worker\Chunk\ChunkGenerationRequestCodec;
use Bedriox\Server\Worker\Chunk\ChunkPreparationRequest;
use Bedriox\Server\Worker\Chunk\ChunkPreparationRequestCodec;
use Bedriox\Server\Worker\Chunk\ChunkProjectionIdentity;
use Bedriox\Server\Worker\Chunk\ChunkProjectionTransferCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\ManagedWorkerPool;
use Bedriox\Server\Worker\Network\BatchCompressionRequest;
use Bedriox\Server\Worker\Network\BatchCompressionRequestCodec;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\FlatWorldGenerator;
use PHPUnit\Framework\TestCase;

final class CoreWorkerConsumersTest extends TestCase
{
    public function testProductionBrokerExecutesCompressionAndChunkGenerationTasks(): void
    {
        $pool = ManagedWorkerPool::start('consumer-test', 1);
        try {
            $compression = $pool->submit(
                CoreWorkerTaskCatalog::COMPRESS_BATCH,
                (new BatchCompressionRequestCodec())->encode(new BatchCompressionRequest(
                    str_repeat('batch-data-', 200),
                    CompressionMode::NegotiatedZlib,
                    256,
                    new BatchLimits(),
                )),
            );
            $generation = $pool->submit(
                CoreWorkerTaskCatalog::GENERATE_CHUNK,
                (new ChunkGenerationRequestCodec())->encode(new ChunkGenerationRequest(
                    'flat',
                    1,
                    123,
                    'minecraft:overworld',
                    new ChunkPosition(2, -3),
                )),
            );
            $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
            $generatedChunk = (new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($states)))
                ->generate(new ChunkPosition(2, -3));
            $preparation = $pool->submit(
                CoreWorkerTaskCatalog::PREPARE_CHUNK,
                (new ChunkPreparationRequestCodec())->encode(new ChunkPreparationRequest(
                    ProtocolVersion::CURRENT,
                    ChunkProjectionIdentity::SERIALIZER_VERSION,
                    ChunkProjectionIdentity::COMPRESSION_THRESHOLD,
                    ChunkProjectionIdentity::bundledRegistryHash(),
                    (new ChunkProjectionTransferCodec())->encode($generatedChunk, $states),
                )),
            );
            self::assertNotNull($compression->receipt);
            self::assertNotNull($generation->receipt);
            self::assertNotNull($preparation->receipt);

            $results = $this->await($pool, 3);
            self::assertSame(WorkerResultStatus::SUCCESS, $results[$compression->receipt->taskId]->status);
            self::assertSame(WorkerResultStatus::SUCCESS, $results[$generation->receipt->taskId]->status);
            self::assertSame(WorkerResultStatus::SUCCESS, $results[$preparation->receipt->taskId]->status);
            self::assertSame(0xfe, ord($results[$compression->receipt->taskId]->payload[0]));
            self::assertSame(0xfe, ord($results[$preparation->receipt->taskId]->payload[0]));

            $chunk = (new ChunkTransferCodec())->decode($results[$generation->receipt->taskId]->payload, $states);
            self::assertSame('2:-3', $chunk->position->key());
        } finally {
            $pool->shutdown();
        }
    }

    /** @return array<int, WorkerResult> */
    private function await(ManagedWorkerPool $pool, int $count): array
    {
        $results = [];
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $pool->poll();
            foreach ($pool->takeResults() as $result) {
                $results[$result->receipt->taskId] = $result;
            }
            if (count($results) >= $count) {
                break;
            }
            usleep(1_000);
        } while (hrtime(true) < $deadline);

        self::assertCount($count, $results, $pool->diagnostic());

        return $results;
    }
}
