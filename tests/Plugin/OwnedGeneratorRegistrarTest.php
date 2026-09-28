<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\World\Generator\BlockState;
use Bedriox\Api\World\Generator\ChunkPosition as ApiChunkPosition;
use Bedriox\Api\World\Generator\GeneratedChunk;
use Bedriox\Api\World\Generator\GeneratedSection;
use Bedriox\Api\World\Generator\Generator;
use Bedriox\Api\World\Generator\GeneratorContext as ApiGeneratorContext;
use Bedriox\Api\World\Generator\GeneratorDefinition as ApiGeneratorDefinition;
use Bedriox\Api\World\Generator\GeneratorSpawn;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Plugin\OwnedGeneratorRegistrar;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generator\GeneratorContext;
use Bedriox\Server\World\Generator\GeneratorExecution;
use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Generator\GeneratorRegistry;
use PHPUnit\Framework\TestCase;

final class OwnedGeneratorRegistrarTest extends TestCase
{
    public function testRegistrationCreatesBoundedCanonicalGeneratorAndCleanupRemovesIt(): void
    {
        $registry = new GeneratorRegistry();
        $ownership = new PluginOwnershipRegistry();
        $registrar = new OwnedGeneratorRegistrar('TestFeatures', $registry, $ownership);
        $registrar->register(new ApiGeneratorDefinition('testfeatures:test', 1, TestGenerator::class));

        $definition = $registry->require('testfeatures:test');
        self::assertSame('TestFeatures', $definition->owner);
        self::assertSame(GeneratorExecution::WORKER, $definition->execution);
        self::assertSame(TestGenerator::class, $definition->workerSource?->class);

        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = $definition->create(new GeneratorContext(
            42,
            'minecraft:overworld',
            new GeneratorOptions(['surface' => 64]),
            $states,
        ));
        $chunk = $generator->generate(new ChunkPosition(2, -3));
        self::assertSame('testfeatures:test', $generator->name());
        self::assertSame(1, $generator->version());
        self::assertSame(65, $generator->defaultSpawn()->y);
        self::assertSame(
            'minecraft:grass_block',
            $states->state($chunk->blockStateAt(0, 64, 0))->identifier(),
        );

        self::assertSame([], $ownership->releaseAll('TestFeatures'));
        self::assertNull($registry->get('testfeatures:test'));
    }

    public function testAnotherPluginCannotReplaceOwnedDefinition(): void
    {
        $registry = new GeneratorRegistry();
        $ownership = new PluginOwnershipRegistry();
        (new OwnedGeneratorRegistrar('First', $registry, $ownership))->register(
            new ApiGeneratorDefinition('example:test', 1, TestGenerator::class),
        );

        $this->expectException(PluginException::class);
        (new OwnedGeneratorRegistrar('Second', $registry, $ownership))->register(
            new ApiGeneratorDefinition('example:test', 1, TestGenerator::class),
            true,
        );
    }

    public function testStatefulGeneratorDefinitionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiGeneratorDefinition('example:stateful', 1, StatefulTestGenerator::class);
    }
}

final class TestGenerator implements Generator
{
    public function generate(ApiGeneratorContext $context, ApiChunkPosition $position): GeneratedChunk
    {
        $air = new BlockState('minecraft:air');
        $grass = new BlockState('minecraft:grass_block');

        return new GeneratedChunk([
            GeneratedSection::uniform(3, $air),
            GeneratedSection::layered(4, [$grass, $air, $air, $air, $air, $air, $air, $air, $air, $air, $air, $air, $air, $air, $air, $air]),
        ]);
    }

    public function defaultSpawn(ApiGeneratorContext $context): GeneratorSpawn
    {
        return new GeneratorSpawn(0, 65, 0);
    }
}

final class StatefulTestGenerator implements Generator
{
    private int $calls = 0;

    public function generate(ApiGeneratorContext $context, ApiChunkPosition $position): GeneratedChunk
    {
        ++$this->calls;

        return new GeneratedChunk([]);
    }

    public function defaultSpawn(ApiGeneratorContext $context): GeneratorSpawn
    {
        return new GeneratorSpawn(0, 64, 0);
    }
}
