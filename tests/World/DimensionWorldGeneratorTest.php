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

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\EndWorldGenerator;
use Bedriox\Server\World\Generation\SeededNoise;
use Bedriox\Server\World\NetherWorldGenerator;
use Bedriox\Server\World\WorldGeneratorFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DimensionWorldGeneratorTest extends TestCase
{
    public function testDefaultPresetSelectsDeterministicDimensionGenerator(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $position = new ChunkPosition(0, 0);

        $overworld = WorldGeneratorFactory::create('default', 42, $states, dimension: 'minecraft:overworld')->generate($position);
        $nether = WorldGeneratorFactory::create('default', 42, $states, dimension: 'minecraft:nether')->generate($position);
        $end = WorldGeneratorFactory::create('default', 42, $states, dimension: 'minecraft:the_end')->generate($position);

        self::assertNotSame($this->fingerprint($overworld), $this->fingerprint($nether));
        self::assertNotSame($this->fingerprint($overworld), $this->fingerprint($end));
        self::assertContains($nether->biome()->identifier, [
            'minecraft:hell',
            'minecraft:soulsand_valley',
            'minecraft:basalt_deltas',
            'minecraft:crimson_forest',
            'minecraft:warped_forest',
        ]);
        self::assertSame('minecraft:the_end', $end->biome()->identifier);
    }

    public function testDimensionGenerationIsReproducibleAndSeedSensitive(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $position = new ChunkPosition(83, -47);
        foreach (['minecraft:nether', 'minecraft:the_end'] as $dimension) {
            $first = WorldGeneratorFactory::create('default', 73, $states, dimension: $dimension)->generate($position);
            $same = WorldGeneratorFactory::create('default', 73, $states, dimension: $dimension)->generate($position);
            $other = WorldGeneratorFactory::create('default', 74, $states, dimension: $dimension)->generate($position);

            self::assertSame($this->fingerprint($first), $this->fingerprint($same));
            self::assertNotSame($this->fingerprint($first), $this->fingerprint($other));
        }
    }

    public function testEndCentralLandmarksAndSpawnPlatformAreStable(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = new EndWorldGenerator(42, $states);
        $spawn = $generator->defaultSpawn();
        $spawnChunk = $generator->generate(new ChunkPosition(6, 0));

        self::assertSame([100, 49, 0], [$spawn->x, $spawn->y, $spawn->z]);
        self::assertSame('minecraft:obsidian', $states->state($spawnChunk->blockStateAt(4, 48, 0))->identifier());
        self::assertSame('minecraft:air', $states->state($spawnChunk->blockStateAt(4, 49, 0))->identifier());

        $origin = $generator->generate(new ChunkPosition(0, 0));
        self::assertSame('minecraft:air', $states->state($origin->blockStateAt(0, 69, 0))->identifier());
        self::assertSame('minecraft:bedrock', $states->state($origin->blockStateAt(3, 69, 0))->identifier());

        $pillar = $generator->generate(new ChunkPosition(2, 0));
        self::assertSame('minecraft:obsidian', $states->state($pillar->blockStateAt(10, 70, 0))->identifier());
    }

    public function testNetherStructuresContinueAcrossChunkBorders(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = new NetherWorldGenerator(42, $states);
        $noise = new SeededNoise(42 ^ 0x4e455448);
        $center = null;
        for ($regionX = -2; $regionX <= 2 && $center === null; ++$regionX) {
            for ($regionZ = -2; $regionZ <= 2; ++$regionZ) {
                if ($noise->chance($regionX, 0, $regionZ, 1_701, 3) !== 0) {
                    continue;
                }
                $center = [
                    $regionX * 384 + 96 + $noise->chance($regionX, 1, $regionZ, 1_709, 192),
                    $regionZ * 384 + 96 + $noise->chance($regionX, 2, $regionZ, 1_717, 192),
                ];
                break;
            }
        }
        self::assertNotNull($center);
        [$centerX, $centerZ] = $center;
        $chunkX = self::floorDiv($centerX, 16);
        $chunkZ = self::floorDiv($centerZ, 16);
        $first = $generator->generate(new ChunkPosition($chunkX, $chunkZ));
        $east = $generator->generate(new ChunkPosition($chunkX + 1, $chunkZ));

        self::assertTrue($this->containsAnyIdentifier($first, $states, ['minecraft:nether_brick', 'minecraft:polished_blackstone_bricks']));
        self::assertTrue($this->containsAnyIdentifier($east, $states, ['minecraft:nether_brick', 'minecraft:polished_blackstone_bricks']));
    }

    #[DataProvider('netherSpawnSeeds')]
    public function testNetherDefaultSpawnIsDeterministicAndSafe(int $seed): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $first = new NetherWorldGenerator($seed, $states);
        $spawn = $first->defaultSpawn();
        $same = (new NetherWorldGenerator($seed, $states))->defaultSpawn();
        $chunkX = self::floorDiv($spawn->x, 16);
        $chunkZ = self::floorDiv($spawn->z, 16);
        $localX = (($spawn->x % 16) + 16) % 16;
        $localZ = (($spawn->z % 16) + 16) % 16;
        $chunk = $first->generate(new ChunkPosition($chunkX, $chunkZ));
        $floor = $states->state($chunk->blockStateAt($localX, $spawn->y - 1, $localZ))->identifier();

        self::assertEquals($spawn, $same);
        self::assertGreaterThanOrEqual(34, $spawn->y);
        self::assertLessThanOrEqual(121, $spawn->y);
        self::assertNotContains($floor, [
            'minecraft:air',
            'minecraft:lava',
            'minecraft:magma',
            'minecraft:crimson_roots',
            'minecraft:warped_roots',
            'minecraft:nether_sprouts',
        ]);
        self::assertSame('minecraft:air', $states->state(
            $chunk->blockStateAt($localX, $spawn->y, $localZ),
        )->identifier());
        self::assertSame('minecraft:air', $states->state(
            $chunk->blockStateAt($localX, $spawn->y + 1, $localZ),
        )->identifier());
    }

    /** @return iterable<string, array{int}> */
    public static function netherSpawnSeeds(): iterable
    {
        yield 'zero' => [0];
        yield 'positive' => [42];
        yield 'negative' => [-9_182];
    }

    public function testEndOuterIslandsContainDeterministicChorusAndCityStructures(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $chunk = (new EndWorldGenerator(42, $states))->generate(new ChunkPosition(-112, -80));

        self::assertTrue($this->containsAnyIdentifier($chunk, $states, ['minecraft:end_stone']));
        self::assertTrue($this->containsAnyIdentifier($chunk, $states, ['minecraft:chorus_plant', 'minecraft:chorus_flower']));
        self::assertTrue($this->containsAnyIdentifier($chunk, $states, ['minecraft:purpur_block', 'minecraft:purpur_pillar']));
    }

    /** @param list<string> $identifiers */
    private function containsAnyIdentifier(\Bedriox\Server\World\Chunk $chunk, BlockStateRegistry $states, array $identifiers): bool
    {
        foreach ($chunk->populatedSections() as $section) {
            foreach ($section->palette() as $state) {
                if (in_array($states->state($state)->identifier(), $identifiers, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);
        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }

    private function fingerprint(\Bedriox\Server\World\Chunk $chunk): string
    {
        $values = [$chunk->biome()->identifier];
        foreach ($chunk->populatedSections() as $section) {
            $values[] = $section->sectionY . ':' . implode(',', array_map(
                static fn($state): int => $state->value,
                $section->palette(),
            ));
            $values[] = hash('sha256', $section->blockStorageLayers()[0]->paletteIndices());
        }

        return hash('sha256', implode('|', $values));
    }
}
