<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Worker\Chunk;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Worker\Chunk\ChunkGenerationRequest;
use Bedriox\Server\Worker\Chunk\ChunkGenerationRequestCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\Worker\Task\GenerateChunkTask;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;
use PHPUnit\Framework\TestCase;

final class ChunkGenerationTaskTest extends TestCase
{
    public function testOneWorkerHandlerGeneratesSeveralChunksForTheSameWorld(): void
    {
        $task = new GenerateChunkTask();
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $first = $this->generate($task, 12345, new ChunkPosition(4, -2), $states);
        $second = $this->generate($task, 12345, new ChunkPosition(5, -2), $states);

        self::assertSame('4:-2', $first->position->key());
        self::assertSame('5:-2', $second->position->key());
        self::assertNotSame($first->position->key(), $second->position->key());
    }

    public function testCachedGeneratorsRemainIsolatedByWorldSeed(): void
    {
        $task = new GenerateChunkTask();
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $position = new ChunkPosition(20, 20);
        $first = $this->generate($task, 12345, $position, $states);
        $second = $this->generate($task, 67890, $position, $states);

        self::assertNotSame(
            (new ChunkTransferCodec())->encode($first, $states),
            (new ChunkTransferCodec())->encode($second, $states),
        );
    }

    private function generate(
        GenerateChunkTask $task,
        int $seed,
        ChunkPosition $position,
        BlockStateRegistry $states,
    ): \Bedriox\Server\World\Chunk {
        $payload = (new ChunkGenerationRequestCodec())->encode(new ChunkGenerationRequest(
            'default',
            DefaultWorldGenerator::VERSION,
            $seed,
            'minecraft:overworld',
            $position,
        ));

        return (new ChunkTransferCodec())->decode($task->execute($payload), $states);
    }
}
