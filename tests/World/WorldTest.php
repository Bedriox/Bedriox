<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Biome;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockOverrideStore;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Provider\Exception\CorruptChunkException;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use Bedriox\Server\World\WorldTimeRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WorldTest extends TestCase
{
    public function testWorldUsesCalculatedSpawnAndRepositoryIdentity(): void
    {
        $world = $this->world();
        $position = new ChunkPosition(-1, 2);

        self::assertSame($world->chunk($position), $world->chunk(new ChunkPosition(-1, 2)));
        self::assertSame([0, 64, 0], [$world->spawn()->x, $world->spawn()->y, $world->spawn()->z]);
        self::assertSame('flat', $world->generatorName());
        self::assertSame('world', $world->metadata->name);
    }

    public function testExplicitSpawnOverridesCalculatedSpawn(): void
    {
        $override = new SpawnPosition(10, 80, -10);
        self::assertSame($override, $this->world($override)->spawn());
    }

    public function testBlockReplacementIsAuthoritativeAcrossPositiveAndNegativeChunks(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $world = new World(
            new WorldMetadata('world', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );

        foreach ([[0, 0], [16, 0], [-1, -1], [-16, 17]] as [$x, $z]) {
            self::assertSame($palette->grassBlock->value, $world->blockStateAt($x, 63, $z)->value);
            self::assertSame(
                $palette->grassBlock->value,
                $world->setBlockState($x, 63, $z, $palette->air)->value,
            );
            self::assertSame($palette->air->value, $world->blockStateAt($x, 63, $z)->value);
            self::assertSame($palette->air->value, $world->chunk(new ChunkPosition(
                (int) floor($x / 16.0),
                (int) floor($z / 16.0),
            ))->blockStateAt((($x % 16) + 16) % 16, 63, (($z % 16) + 16) % 16)->value);
        }
    }

    public function testBlockReplacementSurvivesChunkEvictionAndRemainsBounded(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $world = new World(
            new WorldMetadata('world', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(1),
            overrides: new BlockOverrideStore(1),
        );
        $world->setBlockState(0, 63, 0, $palette->air);
        $world->chunk(new ChunkPosition(2, 0));

        self::assertSame($palette->air->value, $world->blockStateAt(0, 63, 0)->value);

        $this->expectException(\OverflowException::class);
        $world->setBlockState(1, 63, 0, $palette->air);
    }

    public function testWorldNameIsStrictAndBounded(): void
    {
        foreach (['', '../world', str_repeat('x', 65)] as $name) {
            try {
                new WorldMetadata($name, 0);
                self::fail('Invalid world name was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testWorldOwnsBoundedTickingPausableTime(): void
    {
        $world = $this->world();

        self::assertSame(0, $world->time());
        $world->setTime(13_000);
        self::assertSame(13_000, $world->timeOfDay());
        self::assertSame(0, $world->day());
        $world->advanceTime();
        self::assertSame(13_001, $world->time());

        $world->stopTime();
        self::assertFalse($world->isTimeRunning());
        $world->advanceTime();
        self::assertSame(13_001, $world->time());

        $world->startTime();
        $world->setTime(WorldTimeRules::MAXIMUM);
        $world->advanceTime();
        self::assertSame(11_648, $world->time());

        $this->expectException(InvalidArgumentException::class);
        $world->setTime(-1);
    }

    public function testSpawnYMustBeInsideTheWorld(): void
    {
        foreach ([-65, 320] as $y) {
            try {
                new SpawnPosition(0, $y, 0);
                self::fail('Invalid spawn Y was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testBiomeIdentityIsCanonicalAndNotANetworkId(): void
    {
        self::assertSame('minecraft:plains', Biome::plains()->identifier);

        $this->expectException(InvalidArgumentException::class);
        new Biome('plains');
    }

    public function testProviderChunkLoadsBeforeGeneration(): void
    {
        [$palette, $generator] = $this->flatWorldDependencies();
        $position = new ChunkPosition(3, -2);
        $persisted = $generator->generate($position)->withBlockState(0, 63, 0, $palette->air);
        $persisted = $persisted->withPersistedRevision($persisted->revision);
        $provider = $this->provider();
        $provider->seed($persisted);
        $world = new World(
            new WorldMetadata('world', 0),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
        );

        self::assertSame($palette->air->value, $world->chunk($position)->blockStateAt(0, 63, 0)->value);
        self::assertSame(0, $world->dirtyChunkCount());
    }

    public function testGeneratedAndChangedChunksPersistAcrossEviction(): void
    {
        [$palette, $generator] = $this->flatWorldDependencies();
        $provider = $this->provider();
        $world = new World(
            new WorldMetadata('world', 0),
            $generator,
            new ChunkRepository(1),
            provider: $provider,
        );
        $first = new ChunkPosition(0, 0);

        self::assertSame($palette->grassBlock->value, $world->blockStateAt(0, 63, 0)->value);
        $world->setBlockState(0, 63, 0, $palette->air);
        $world->chunk(new ChunkPosition(1, 0));

        self::assertArrayHasKey($first->key(), $provider->chunks);
        self::assertSame($palette->air->value, $world->blockStateAt(0, 63, 0)->value);
    }

    public function testUpgradedProviderChunkIsScheduledForPersistence(): void
    {
        [, $generator] = $this->flatWorldDependencies();
        $position = new ChunkPosition(2, 3);
        $provider = $this->provider();
        $provider->seed($generator->generate($position));
        $provider->upgradedChunks[$position->key()] = true;
        $world = new World(
            new WorldMetadata('world', 0),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
        );

        $loaded = $world->chunk($position);

        self::assertSame(Chunk::DIRTY_ALL, $loaded->dirtyFlags);
        self::assertSame(1, $loaded->revision);
        self::assertSame(1, $world->dirtyChunkCount());
    }

    public function testProviderCorruptionPropagatesWithoutGenerationFallback(): void
    {
        [, $generator] = $this->flatWorldDependencies();
        $provider = $this->provider();
        $position = new ChunkPosition(-4, 7);
        $provider->corruptChunks[$position->key()] = true;
        $world = new World(
            new WorldMetadata('world', 0),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
        );

        $this->expectException(CorruptChunkException::class);
        $world->chunk($position);
    }

    public function testAutosaveIsBoundedAndFailedSavesRemainDirty(): void
    {
        [, $generator] = $this->flatWorldDependencies();
        $provider = $this->provider();
        $world = new World(
            new WorldMetadata('world', 0),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
        );
        $world->chunk(new ChunkPosition(0, 0));
        $world->chunk(new ChunkPosition(1, 0));

        self::assertSame(1, $world->autosave(1));
        self::assertSame(1, $world->dirtyChunkCount());
        $provider->failSaves = true;
        try {
            $world->autosave(1);
            self::fail('Injected provider failure was ignored.');
        } catch (WorldStorageException) {
            self::assertSame(1, $world->dirtyChunkCount());
        }
    }

    public function testCloseFlushesChunksAndWorldMetadataBeforeClosingProvider(): void
    {
        [, $generator] = $this->flatWorldDependencies();
        $persistedSpawn = new SpawnPosition(12, 80, -7);
        $provider = new InMemoryWorldProvider(new WorldData(
            new WorldMetadata('world', 0),
            'flat',
            $persistedSpawn,
            9001,
        ));
        $world = new World(
            new WorldMetadata('world', 0),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
        );
        $world->chunk(new ChunkPosition(0, 0));
        self::assertSame($persistedSpawn, $world->spawn());
        $world->setTime(13_000);

        $world->close();
        $world->close();

        self::assertTrue($provider->closed);
        self::assertSame(1, $provider->closeCalls);
        self::assertCount(1, $provider->chunks);
        self::assertSame(0, $world->dirtyChunkCount());
        self::assertSame('flat', $provider->data->generatorName);
        self::assertSame(13_000, $provider->data->time);
    }

    public function testCloseReleasesProviderAfterDurabilityFailureAndRemainsIdempotent(): void
    {
        [, $generator] = $this->flatWorldDependencies();
        $provider = $this->provider();
        $world = new World(
            new WorldMetadata('world', 0),
            $generator,
            new ChunkRepository(4),
            provider: $provider,
        );
        $world->chunk(new ChunkPosition(0, 0));
        $provider->failSaves = true;

        try {
            $world->close();
            self::fail('A failed durability flush was ignored.');
        } catch (WorldStorageException) {
            self::assertTrue($provider->closed);
            self::assertSame(1, $provider->closeCalls);
        }

        $world->close();
        self::assertSame(1, $provider->closeCalls);
    }

    private function world(?SpawnPosition $override = null): World
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($registry));

        return new World(new WorldMetadata('world', 0), $generator, new ChunkRepository(4), $override);
    }

    /** @return array{FixedFlatBlockPalette, FlatWorldGenerator} */
    private function flatWorldDependencies(): array
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);

        return [$palette, new FlatWorldGenerator($palette)];
    }

    private function provider(): InMemoryWorldProvider
    {
        return new InMemoryWorldProvider(new WorldData(
            new WorldMetadata('world', 0),
            'flat',
            new SpawnPosition(0, 64, 0),
        ));
    }
}
