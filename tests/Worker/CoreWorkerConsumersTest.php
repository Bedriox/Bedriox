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

namespace Bedriox\Server\Tests\Worker;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Server\Tests\Fixtures\WorkerPluginGenerator;
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
use Bedriox\Server\Worker\World\WorldPreparationCodec;
use Bedriox\Server\Worker\World\WorldPreparationRequest;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generator\BuiltInGeneratorDefinitions;
use Bedriox\Server\World\Generator\WorkerGeneratorSource;
use PHPUnit\Framework\TestCase;

final class CoreWorkerConsumersTest extends TestCase
{
    public function testProductionBrokerLoadsAdmittedPluginGeneratorSource(): void
    {
        $pool = ManagedWorkerPool::start('plugin-generator-consumer-test', 1);
        try {
            $submission = $pool->submit(
                CoreWorkerTaskCatalog::GENERATE_CHUNK,
                (new ChunkGenerationRequestCodec())->encode(new ChunkGenerationRequest(
                    'test:worker',
                    1,
                    73,
                    'minecraft:overworld',
                    new ChunkPosition(4, -2),
                    workerSource: WorkerGeneratorSource::capture(WorkerPluginGenerator::class),
                )),
            );
            self::assertNotNull($submission->receipt);

            $result = $this->await($pool, 1)[$submission->receipt->taskId];
            self::assertSame(WorkerResultStatus::SUCCESS, $result->status, $result->failureCode ?? '');
            $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
            $chunk = (new ChunkTransferCodec())->decode($result->payload, $states);
            self::assertSame('minecraft:grass_block', $states->state($chunk->blockStateAt(0, 64, 0))->identifier());
        } finally {
            $pool->shutdown();
        }
    }

    public function testProductionBrokerPreparesWorldGeneratorMetadata(): void
    {
        $pool = ManagedWorkerPool::start('world-preparation-consumer-test', 1);
        try {
            $codec = new WorldPreparationCodec();
            $submission = $pool->submit(
                CoreWorkerTaskCatalog::PREPARE_WORLD,
                $codec->encodeRequest(new WorldPreparationRequest(
                    'flat',
                    BuiltInGeneratorDefinitions::FLAT,
                    1,
                    73,
                    'minecraft:overworld',
                )),
            );
            self::assertNotNull($submission->receipt);

            $result = $this->await($pool, 1)[$submission->receipt->taskId];
            self::assertSame(WorkerResultStatus::SUCCESS, $result->status, $result->failureCode ?? '');
            $prepared = $codec->decodeResult($result->payload);
            self::assertSame(BuiltInGeneratorDefinitions::FLAT, $prepared->generatorIdentifier);
            self::assertSame([0, 64, 0], [
                $prepared->defaultSpawn->x,
                $prepared->defaultSpawn->y,
                $prepared->defaultSpawn->z,
            ]);
        } finally {
            $pool->shutdown();
        }
    }

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
                    BuiltInGeneratorDefinitions::FLAT,
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
