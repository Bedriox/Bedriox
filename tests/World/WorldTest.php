<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Biome;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockOverrideStore;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
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

    private function world(?SpawnPosition $override = null): World
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($registry));

        return new World(new WorldMetadata('world', 0), $generator, new ChunkRepository(4), $override);
    }
}
