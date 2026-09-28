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

namespace Bedriox\Server\Tests\Worker\Chunk;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Tests\Fixtures\WorkerPluginGenerator;
use Bedriox\Server\Worker\Chunk\ChunkGenerationRequest;
use Bedriox\Server\Worker\Chunk\ChunkGenerationRequestCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\Worker\Task\GenerateChunkTask;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;
use Bedriox\Server\World\Generator\BuiltInGeneratorDefinitions;
use Bedriox\Server\World\Generator\GeneratorContext;
use Bedriox\Server\World\Generator\GeneratorDefinition;
use Bedriox\Server\World\Generator\GeneratorIdentifier;
use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Generator\GeneratorRegistry;
use Bedriox\Server\World\Generator\WorkerGeneratorSource;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\VersionedWorldGenerator;
use PHPUnit\Framework\TestCase;

final class ChunkGenerationTaskTest extends TestCase
{
    public function testPluginGeneratorSourceRunsThroughWorkerTaskBoundary(): void
    {
        $task = new GenerateChunkTask();
        $source = WorkerGeneratorSource::capture(WorkerPluginGenerator::class);
        $payload = (new ChunkGenerationRequestCodec())->encode(new ChunkGenerationRequest(
            'test:worker',
            1,
            73,
            'minecraft:overworld',
            new ChunkPosition(4, -2),
            new GeneratorOptions(),
            $source,
        ));

        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $chunk = (new \Bedriox\Server\Worker\Chunk\ChunkTransferCodec())->decode($task->execute($payload), $states);

        self::assertSame(4, $chunk->position->x);
        self::assertSame(-2, $chunk->position->z);
        self::assertSame('minecraft:grass_block', $states->state($chunk->blockStateAt(0, 64, 0))->identifier());
    }

    public function testPluginGeneratorSourceRejectsChangedDigest(): void
    {
        $source = WorkerGeneratorSource::capture(WorkerPluginGenerator::class);
        $request = new ChunkGenerationRequest(
            'test:worker',
            1,
            73,
            'minecraft:overworld',
            new ChunkPosition(0, 0),
            workerSource: new WorkerGeneratorSource($source->class, $source->file, str_repeat('0', 64)),
        );

        $this->expectException(\RuntimeException::class);
        (new GenerateChunkTask())->execute((new ChunkGenerationRequestCodec())->encode($request));
    }

    public function testPluginGeneratorSourceRejectsClassOutsideGeneratorContract(): void
    {
        $source = WorkerGeneratorSource::capture(WorkerPluginGenerator::class);
        $request = new ChunkGenerationRequest(
            'test:worker',
            1,
            73,
            'minecraft:overworld',
            new ChunkPosition(0, 0),
            workerSource: new WorkerGeneratorSource(self::class, $source->file, $source->sha256),
        );

        $this->expectException(\RuntimeException::class);
        (new GenerateChunkTask())->execute((new ChunkGenerationRequestCodec())->encode($request));
    }

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

    public function testCodecRoundTripsCanonicalOptionsDeterministically(): void
    {
        $codec = new ChunkGenerationRequestCodec();
        $first = new ChunkGenerationRequest(
            BuiltInGeneratorDefinitions::FLAT,
            1,
            5,
            'minecraft:overworld',
            new ChunkPosition(-7, 11),
            new GeneratorOptions(['terrain' => ['scale' => 2.0], 'features' => ['caves', 'lakes']]),
        );
        $second = new ChunkGenerationRequest(
            BuiltInGeneratorDefinitions::FLAT,
            1,
            5,
            'minecraft:overworld',
            new ChunkPosition(-7, 11),
            new GeneratorOptions(['features' => ['caves', 'lakes'], 'terrain' => ['scale' => 2.0]]),
        );

        self::assertSame($codec->encode($first), $codec->encode($second));
        self::assertSame($first->options->canonicalJson(), $codec->decode($codec->encode($first))->options->canonicalJson());
    }

    public function testWorkerUsesRegisteredPluginGeneratorAndTransferredOptions(): void
    {
        $registry = new GeneratorRegistry();
        $registry->register(new GeneratorDefinition(
            new GeneratorIdentifier('example:marker'),
            1,
            'ExamplePlugin',
            static fn(GeneratorContext $context): VersionedWorldGenerator => new MarkerWorldGenerator($context),
        ));
        $task = new GenerateChunkTask($registry);
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $position = new ChunkPosition(0, 0);
        $payload = (new ChunkGenerationRequestCodec())->encode(new ChunkGenerationRequest(
            'example:marker',
            1,
            77,
            'minecraft:overworld',
            $position,
            new GeneratorOptions(['markerY' => 31]),
        ));

        $chunk = (new ChunkTransferCodec())->decode($task->execute($payload), $states);

        self::assertEquals(
            $states->internalId(\Bedriox\Data\CanonicalBlockState::from('minecraft:stone')),
            $chunk->blockStateAt(0, 31, 0),
        );
    }

    private function generate(
        GenerateChunkTask $task,
        int $seed,
        ChunkPosition $position,
        BlockStateRegistry $states,
    ): \Bedriox\Server\World\Chunk {
        $payload = (new ChunkGenerationRequestCodec())->encode(new ChunkGenerationRequest(
            BuiltInGeneratorDefinitions::DEFAULT,
            DefaultWorldGenerator::VERSION,
            $seed,
            'minecraft:overworld',
            $position,
        ));

        return (new ChunkTransferCodec())->decode($task->execute($payload), $states);
    }
}

final readonly class MarkerWorldGenerator implements VersionedWorldGenerator
{
    private int $markerY;

    public function __construct(private GeneratorContext $context)
    {
        $markerY = $context->options->values()['markerY'] ?? null;
        if (!is_int($markerY)) {
            throw new \InvalidArgumentException('Marker generator requires an integer markerY option.');
        }
        $this->markerY = $markerY;
    }

    public function name(): string
    {
        return 'example:marker';
    }

    public function version(): int
    {
        return 1;
    }

    public function generate(ChunkPosition $position): \Bedriox\Server\World\Chunk
    {
        $air = $this->context->blockStates->internalId(\Bedriox\Data\CanonicalBlockState::from('minecraft:air'));
        $stone = $this->context->blockStates->internalId(\Bedriox\Data\CanonicalBlockState::from('minecraft:stone'));

        return (new \Bedriox\Server\World\Chunk($position, $air, []))->withBlockState(0, $this->markerY, 0, $stone);
    }

    public function defaultSpawn(): SpawnPosition
    {
        return new SpawnPosition(0, 64, 0);
    }
}
