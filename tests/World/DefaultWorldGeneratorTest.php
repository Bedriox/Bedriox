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
use Bedriox\Server\World\BiomeRuntimeIdMap;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefaultWorldGeneratorTest extends TestCase
{
    public function testDefaultGeneratorUsesCurrentVersion(): void
    {
        [$generator] = self::generator(0);

        self::assertSame('default', $generator->name());
        self::assertSame(DefaultWorldGenerator::VERSION, $generator->version());
        self::assertSame(5, $generator->version());
    }

    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        yield 'zero' => [0];
        yield 'positive' => [1];
        yield 'negative one remains deterministic' => [-1];
        yield 'minimum' => [-2_147_483_648];
        yield 'maximum' => [2_147_483_647];
    }

    #[DataProvider('seeds')]
    public function testGenerationIsDeterministicBoundedAndSpawnIsDry(int $seed): void
    {
        [$generator, $states] = self::generator($seed);
        $position = new ChunkPosition(-2, 3);
        $first = $generator->generate($position);
        $second = $generator->generate($position);

        self::assertSame(self::signature($first), self::signature($second));
        self::assertLessThanOrEqual(12, count($first->populatedSections()));
        foreach ($first->populatedSections() as $section) {
            self::assertLessThanOrEqual(32, count($section->palette()));
        }

        $spawn = $generator->defaultSpawn();
        $spawnChunk = $generator->generate(new ChunkPosition((int) floor($spawn->x / 16), (int) floor($spawn->z / 16)));
        $localX = (($spawn->x % 16) + 16) % 16;
        $localZ = (($spawn->z % 16) + 16) % 16;
        $air = $states->internalId(\Bedriox\Server\World\Block\VanillaBlockStates::air());
        self::assertNotSame($air->value, $spawnChunk->blockStateAt($localX, $spawn->y - 1, $localZ)->value);
        self::assertSame($air->value, $spawnChunk->blockStateAt($localX, $spawn->y, $localZ)->value);
        self::assertSame($air->value, $spawnChunk->blockStateAt($localX, $spawn->y + 1, $localZ)->value);
    }

    public function testNegativeChunkSeamUsesWorldCoordinatesAndBiomeIdsAreAdmitted(): void
    {
        [$generator] = self::generator(9_123);
        $west = $generator->generate(new ChunkPosition(-1, 0));
        $east = $generator->generate(new ChunkPosition(0, 0));
        for ($z = 0; $z < 16; ++$z) {
            $westHeight = self::highestTerrain($west, 15, $z);
            $eastHeight = self::highestTerrain($east, 0, $z);
            self::assertLessThanOrEqual(
                24,
                abs($westHeight - $eastHeight),
                'Shared density borders must remain continuous while allowing intentional cliffs and ravines.',
            );
            BiomeRuntimeIdMap::bundled()->id($west->biomeAt(15, $westHeight, $z));
            BiomeRuntimeIdMap::bundled()->id($east->biomeAt(0, $eastHeight, $z));
        }
    }

    public function testChunkOutputDoesNotDependOnGenerationOrder(): void
    {
        [$westFirst] = self::generator(77_031);
        [$eastFirst] = self::generator(77_031);
        $westPosition = new ChunkPosition(-1, 2);
        $eastPosition = new ChunkPosition(0, 2);

        $westA = $westFirst->generate($westPosition);
        $eastA = $westFirst->generate($eastPosition);
        $eastB = $eastFirst->generate($eastPosition);
        $westB = $eastFirst->generate($westPosition);

        self::assertSame(self::signature($westA), self::signature($westB));
        self::assertSame(self::signature($eastA), self::signature($eastB));
    }

    public function testOpenTerrainRemainsContinuouslyFilledAcrossOceanChunkSeams(): void
    {
        [$generator, $states] = self::generator(0);
        $chunks = [
            $generator->generate(new ChunkPosition(20, 4)),
            $generator->generate(new ChunkPosition(21, 4)),
        ];
        $air = $states->internalId(\Bedriox\Server\World\Block\VanillaBlockStates::air())->value;
        $fluidIdentifiers = ['minecraft:water', 'minecraft:ice', 'minecraft:frosted_ice'];
        $checked = 0;

        foreach ($chunks as $chunk) {
            for ($z = 0; $z < 16; ++$z) {
                for ($x = 0; $x < 16; ++$x) {
                    if (!in_array(self::identifierAt($chunk, $states, $x, DefaultWorldGenerator::SEA_LEVEL, $z), $fluidIdentifiers, true)) {
                        continue;
                    }
                    ++$checked;
                    for ($y = DefaultWorldGenerator::SEA_LEVEL - 1; $y > Chunk::MIN_Y; --$y) {
                        $identifier = self::identifierAt($chunk, $states, $x, $y, $z);
                        if (in_array($identifier, $fluidIdentifiers, true)) {
                            continue;
                        }
                        self::assertNotSame(
                            $air,
                            $chunk->blockStateAt($x, $y, $z)->value,
                            "Open water at chunk {$chunk->position->x}:{$chunk->position->z} column $x:$z must not contain an air gap at Y=$y before reaching its terrain floor.",
                        );
                        break;
                    }
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'The selected adjacent chunks must exercise an ocean seam.');
    }

    public function testCavifiedDensityProducesAHorizontalSurfaceEntrance(): void
    {
        [$generator, $states] = self::generator(4_242);
        $chunk = $generator->generate(new ChunkPosition(10, 7));

        self::assertSame('minecraft:air', self::identifierAt($chunk, $states, 7, 63, 8));
        self::assertNotSame('minecraft:air', self::identifierAt($chunk, $states, 7, 64, 8));
        self::assertSame('minecraft:air', self::identifierAt($chunk, $states, 8, 63, 8));
        self::assertSame('minecraft:air', self::identifierAt($chunk, $states, 8, 64, 8));
    }

    public function testUndergroundDepositsUseTheHostStoneFamily(): void
    {
        [$generator, $states] = self::generator(0);
        $chunk = $generator->generate(new ChunkPosition(0, 0));
        $deepOres = 0;
        $stoneOres = 0;
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                for ($y = Chunk::MIN_Y; $y <= 192; ++$y) {
                    $identifier = self::identifierAt($chunk, $states, $x, $y, $z);
                    if (str_starts_with($identifier, 'minecraft:deepslate_') && str_ends_with($identifier, '_ore')) {
                        ++$deepOres;
                        self::assertLessThan(8, $y);
                    } elseif (str_ends_with($identifier, '_ore')) {
                        ++$stoneOres;
                        self::assertGreaterThan(0, $y);
                    }
                }
            }
        }

        self::assertGreaterThan(0, $deepOres);
        self::assertGreaterThan(0, $stoneOres);
    }

    public function testVegetationUsesNaturalSubstrateInsteadOfStructuresOrFoliage(): void
    {
        [$generator, $states] = self::generator(0);
        $chunk = $generator->generate(new ChunkPosition(0, 56));
        $plants = [
            'minecraft:short_grass', 'minecraft:tall_grass', 'minecraft:dandelion', 'minecraft:poppy',
            'minecraft:blue_orchid', 'minecraft:allium', 'minecraft:azure_bluet', 'minecraft:red_tulip',
            'minecraft:white_tulip', 'minecraft:pink_tulip', 'minecraft:oxeye_daisy',
            'minecraft:lily_of_the_valley', 'minecraft:deadbush', 'minecraft:red_mushroom',
            'minecraft:brown_mushroom', 'minecraft:bamboo', 'minecraft:cactus',
        ];
        $naturalSubstrates = [
            'minecraft:dirt', 'minecraft:grass_block', 'minecraft:coarse_dirt', 'minecraft:podzol',
            'minecraft:mud', 'minecraft:moss_block', 'minecraft:pale_moss_block', 'minecraft:mycelium',
            'minecraft:dirt_with_roots', 'minecraft:sand', 'minecraft:red_sand',
            'minecraft:white_terracotta', 'minecraft:orange_terracotta', 'minecraft:yellow_terracotta',
            'minecraft:brown_terracotta', 'minecraft:red_terracotta',
        ];
        $checked = 0;
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                for ($y = Chunk::MIN_Y + 1; $y <= 180; ++$y) {
                    $identifier = self::identifierAt($chunk, $states, $x, $y, $z);
                    if (!in_array($identifier, $plants, true)) {
                        continue;
                    }
                    $below = self::identifierAt($chunk, $states, $x, $y - 1, $z);
                    if (($identifier === 'minecraft:bamboo' || $identifier === 'minecraft:cactus')
                        && $below === $identifier) {
                        continue;
                    }
                    ++$checked;
                    self::assertContains(
                        $below,
                        $naturalSubstrates,
                        "Vegetation $identifier must be rooted in an admitted natural substrate.",
                    );
                }
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    public function testSubmergedTerrainNeverUsesGrassAsItsLakebed(): void
    {
        [$generator, $states] = self::generator(0);
        $water = $states->internalId(\Bedriox\Server\World\Block\VanillaBlockStates::water())->value;
        $grass = $states->internalId(\Bedriox\Server\World\Block\VanillaBlockStates::grassBlock())->value;
        $submerged = 0;
        foreach ([new ChunkPosition(20, 4), new ChunkPosition(21, 4), new ChunkPosition(20, 5), new ChunkPosition(21, 5)] as $position) {
            $chunk = $generator->generate($position);
            for ($z = 0; $z < 16; ++$z) {
                for ($x = 0; $x < 16; ++$x) {
                    for ($y = DefaultWorldGenerator::SEA_LEVEL - 1; $y > Chunk::MIN_Y; --$y) {
                        if ($chunk->blockStateAt($x, $y + 1, $z)->value !== $water
                            || $chunk->blockStateAt($x, $y, $z)->value === $water) {
                            continue;
                        }
                        ++$submerged;
                        self::assertNotSame($grass, $chunk->blockStateAt($x, $y, $z)->value);
                        break;
                    }
                }
            }
        }

        self::assertGreaterThan(0, $submerged);
    }

    public function testOceanBiomeNeverOwnsExposedTerrainAtReportedCoast(): void
    {
        [$generator, $states] = self::generator(0);
        $dryColumns = 0;
        foreach ([
            new ChunkPosition(21, -13), new ChunkPosition(22, -13), new ChunkPosition(23, -13),
            new ChunkPosition(21, -12), new ChunkPosition(22, -12), new ChunkPosition(23, -12),
        ] as $position) {
            $chunk = $generator->generate($position);
            for ($z = 0; $z < 16; ++$z) {
                for ($x = 0; $x < 16; ++$x) {
                    for ($y = Chunk::MAX_Y; $y >= DefaultWorldGenerator::SEA_LEVEL; --$y) {
                        $identifier = self::identifierAt($chunk, $states, $x, $y, $z);
                        if (in_array($identifier, ['minecraft:air', 'minecraft:water', 'minecraft:snow_layer'], true)) {
                            continue;
                        }
                        ++$dryColumns;
                        self::assertStringNotContainsString(
                            'ocean',
                            $chunk->biomeAt($x, $y, $z)->identifier,
                            "Dry column {$position->x}:{$position->z} $x:$z must not use an ocean-floor surface rule.",
                        );
                        break;
                    }
                }
            }
        }

        self::assertGreaterThan(0, $dryColumns);
    }

    public function testOceanCoastAvoidsRepeatedThresholdIslands(): void
    {
        [$generator] = self::generator(0);
        $minimumX = -160;
        $maximumX = 160;
        $minimumZ = -160;
        $maximumZ = 160;
        $land = [];
        $heights = [];
        for ($z = $minimumZ; $z <= $maximumZ; ++$z) {
            for ($x = $minimumX; $x <= $maximumX; ++$x) {
                $height = $generator->surfaceHeight($x, $z);
                $heights[$z][$x] = $height;
                $land[$z][$x] = $height >= DefaultWorldGenerator::SEA_LEVEL;
            }
        }

        $maximumStraightRun = 0;
        $currentRun = 0;
        $previousShore = $minimumX;
        $shoreRows = 0;
        for ($z = $minimumZ; $z <= $maximumZ; ++$z) {
            $shore = null;
            $seenWater = false;
            for ($x = $minimumX; $x <= $maximumX; ++$x) {
                $water = !$land[$z][$x];
                $seenWater = $seenWater || $water;
                if ($seenWater && !$water) {
                    $shore = $x;
                    break;
                }
            }
            if ($shore === null) {
                $currentRun = 0;
                continue;
            }
            ++$shoreRows;
            $currentRun = $shore === $previousShore ? $currentRun + 1 : 1;
            $maximumStraightRun = max($maximumStraightRun, $currentRun);
            $previousShore = $shore;
        }

        $seen = [];
        $isolatedComponents = 0;
        $tinyIsolatedComponents = 0;
        for ($z = $minimumZ; $z <= $maximumZ; ++$z) {
            for ($x = $minimumX; $x <= $maximumX; ++$x) {
                if (!$land[$z][$x] || isset($seen[$z][$x])) {
                    continue;
                }

                $queue = [[$x, $z]];
                $seen[$z][$x] = true;
                $size = 0;
                $touchesBoundary = false;
                for ($index = 0; isset($queue[$index]); ++$index) {
                    [$cellX, $cellZ] = $queue[$index];
                    ++$size;
                    $touchesBoundary = $touchesBoundary
                        || $cellX === $minimumX || $cellX === $maximumX
                        || $cellZ === $minimumZ || $cellZ === $maximumZ;
                    foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$offsetX, $offsetZ]) {
                        $nextX = $cellX + $offsetX;
                        $nextZ = $cellZ + $offsetZ;
                        if ($nextX < $minimumX || $nextX > $maximumX
                            || $nextZ < $minimumZ || $nextZ > $maximumZ
                            || !$land[$nextZ][$nextX] || isset($seen[$nextZ][$nextX])) {
                            continue;
                        }
                        $seen[$nextZ][$nextX] = true;
                        $queue[] = [$nextX, $nextZ];
                    }
                }

                if (!$touchesBoundary) {
                    ++$isolatedComponents;
                    $tinyIsolatedComponents += (int) ($size < 64);
                }
            }
        }

        self::assertGreaterThan(100, $shoreRows);
        self::assertLessThanOrEqual(
            56,
            $maximumStraightRun,
            'The shoreline must not collapse into a single long lattice-aligned edge.',
        );
        self::assertLessThanOrEqual(1, $isolatedComponents, 'The coast must not become a repeated island field.');
        self::assertSame(0, $tinyIsolatedComponents, 'Coastal detail must not produce tiny threshold islands.');

        $maximumSubmergedContourRun = 0;
        $longSubmergedContourRuns = 0;
        foreach ([[1, 0], [0, 1], [1, 1], [1, -1]] as [$offsetX, $offsetZ]) {
            for ($z = $minimumZ; $z <= $maximumZ; ++$z) {
                for ($x = $minimumX; $x <= $maximumX; ++$x) {
                    $level = $heights[$z][$x];
                    if ($level >= DefaultWorldGenerator::SEA_LEVEL
                        || (($heights[$z - $offsetZ][$x - $offsetX] ?? null) === $level)) {
                        continue;
                    }
                    $run = 0;
                    $cellX = $x;
                    $cellZ = $z;
                    while (($heights[$cellZ][$cellX] ?? null) === $level) {
                        ++$run;
                        $cellX += $offsetX;
                        $cellZ += $offsetZ;
                    }
                    $maximumSubmergedContourRun = max($maximumSubmergedContourRun, $run);
                    $longSubmergedContourRuns += (int) ($run >= 24);
                }
            }
        }
        self::assertLessThanOrEqual(56, $maximumSubmergedContourRun, 'Ocean floors must not form long planar terraces.');
        self::assertLessThanOrEqual(240, $longSubmergedContourRuns, 'Ocean-floor contours must remain locally irregular.');
    }

    /** @return array{DefaultWorldGenerator, BlockStateRegistry} */
    private static function generator(int $seed): array
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        return [new DefaultWorldGenerator($seed, $registry), $registry];
    }

    private static function signature(Chunk $chunk): string
    {
        $values = [];
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                for ($y = Chunk::MIN_Y; $y <= 120; ++$y) {
                    $values[] = $chunk->blockStateAt($x, $y, $z)->value;
                }
                $values[] = $chunk->biomeAt($x, 64, $z)->identifier;
            }
        }

        return hash('sha256', serialize($values));
    }

    private static function identifierAt(
        Chunk $chunk,
        BlockStateRegistry $states,
        int $x,
        int $y,
        int $z,
    ): string {
        return $states->state($chunk->blockStateAt($x, $y, $z))->identifier();
    }

    private static function highestTerrain(Chunk $chunk, int $x, int $z): int
    {
        for ($y = 120; $y >= Chunk::MIN_Y; --$y) {
            if ($chunk->blockStateAt($x, $y, $z)->value !== $chunk->airState()->value) {
                return $y;
            }
        }

        self::fail('Generated column contained no terrain.');
    }
}
