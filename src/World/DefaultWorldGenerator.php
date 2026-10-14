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

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\BlockAxis;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\Geology\OverworldGeologyGenerator;
use Bedriox\Server\World\Generation\Landform\OverworldLandformField;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\Noise\OverworldBaseDensitySampler;
use Bedriox\Server\World\Generation\Noise\OverworldDensityLattice;
use Bedriox\Server\World\Generation\OverworldBiomeResolver;
use Bedriox\Server\World\Generation\OverworldCaveBiomeResolver;
use Bedriox\Server\World\Generation\OverworldClimate;
use Bedriox\Server\World\Generation\OverworldTerrainSample;
use Bedriox\Server\World\Generation\OverworldTerrainSampler;
use Bedriox\Server\World\Generation\SeededNoise;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureFamily;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureGenerator;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureLocation;
use Bedriox\Server\World\Generation\Surface\OverworldSurfaceContext;
use Bedriox\Server\World\Generation\Surface\OverworldSurfaceField;
use Bedriox\Server\World\Generation\Surface\OverworldSurfaceMaterial;
use Bedriox\Server\World\Generation\Vegetation\OverworldVegetationPlanner;
use Bedriox\Server\World\Generation\Vegetation\TreeArchetype;
use Bedriox\Server\World\Generation\Vegetation\VegetationTreePlan;

/** Deterministic three-dimensional overworld generator for the built-in default world profile. */
final class DefaultWorldGenerator implements VersionedWorldGenerator
{
    public const int VERSION = 5;
    public const int SEA_LEVEL = OverworldTerrainSampler::SEA_LEVEL;
    private const int SPAWN_SEARCH_STEP = 64;
    private const int MAXIMUM_BEACH_WIDTH = 6;
    private const int TERRAIN_SAMPLE_MARGIN = 2;
    private const int CLIMATE_SAMPLE_STEP = 4;
    private const int COASTAL_SAMPLE_STEP = 4;
    private readonly GenerationBlockPalette $blocks;
    private readonly SeededNoise $noise;
    private readonly OverworldTerrainSampler $terrain;
    private readonly OverworldBaseDensitySampler $density;
    private readonly OverworldLandformField $landforms;
    private readonly OverworldSurfaceField $surfaceField;
    private readonly OverworldBiomeResolver $biomes;
    private readonly OverworldCaveBiomeResolver $caveBiomes;
    private readonly OverworldGeologyGenerator $geology;
    private readonly OverworldStructureGenerator $structures;
    private readonly OverworldVegetationPlanner $vegetation;
    /** @var array<int, true> */
    private readonly array $treeSubstrates;
    /** @var array<int, true> */
    private readonly array $aquaticSubstrates;
    /** @var array<int, true> */
    private readonly array $vegetationReplaceable;
    /** @var array<string, OverworldClimate> */
    private array $columnCache = [];
    /** @var list<string> */
    private array $columnCacheOrder = [];
    /** @var array<string, OverworldTerrainSample> */
    private array $regionalSampleCache = [];
    /** @var list<string> */
    private array $regionalSampleCacheOrder = [];
    /** @var array<string, int> */
    private array $planningHeightCache = [];
    /** @var list<string> */
    private array $planningHeightCacheOrder = [];
    /** @var array<string, int> */
    private array $volumeSurfaceHeightCache = [];
    /** @var list<string> */
    private array $volumeSurfaceHeightCacheOrder = [];
    /** @var array<string, int> */
    private array $rawDensitySurfaceCache = [];
    /** @var list<string> */
    private array $rawDensitySurfaceCacheOrder = [];
    /** @var array<string, OverworldDensityLattice> */
    private array $volumeSurfaceLatticeCache = [];
    /** @var list<string> */
    private array $volumeSurfaceLatticeCacheOrder = [];
    /** @var array<string, array{int, int}> */
    private array $surfaceLatticeBoundsCache = [];
    /** @var list<string> */
    private array $surfaceLatticeBoundsCacheOrder = [];
    /** @var array<string, non-empty-list<int>> */
    private array $rawSurfaceChunkCache = [];
    /** @var list<string> */
    private array $rawSurfaceChunkCacheOrder = [];
    /** @var array<string, array{int, int}> */
    private array $coastalSignalCache = [];
    /** @var list<string> */
    private array $coastalSignalCacheOrder = [];
    private ?SpawnPosition $candidateSpawn = null;
    private ?SpawnPosition $spawn = null;

    public function __construct(
        private readonly int $seed,
        BlockStateRegistry $states,
    ) {
        $this->blocks = GenerationBlockPalette::fromRegistry($states);
        $this->noise = new SeededNoise($seed);
        $this->terrain = new OverworldTerrainSampler($this->noise);
        $this->density = new OverworldBaseDensitySampler($seed);
        $this->landforms = new OverworldLandformField($this->noise);
        $this->surfaceField = new OverworldSurfaceField($this->noise);
        $this->biomes = new OverworldBiomeResolver();
        $this->caveBiomes = new OverworldCaveBiomeResolver($this->noise);
        $this->geology = new OverworldGeologyGenerator($seed, $this->blocks);
        $this->structures = new OverworldStructureGenerator($this->blocks, $this->noise);
        $this->vegetation = new OverworldVegetationPlanner($this->noise);
        $this->treeSubstrates = $this->stateSet([
            'minecraft:dirt', 'minecraft:grass_block', 'minecraft:coarse_dirt', 'minecraft:podzol',
            'minecraft:mud', 'minecraft:moss_block', 'minecraft:pale_moss_block', 'minecraft:mycelium',
            'minecraft:dirt_with_roots',
        ]);
        $this->aquaticSubstrates = $this->stateSet([
            'minecraft:sand', 'minecraft:red_sand', 'minecraft:gravel', 'minecraft:clay',
            'minecraft:dirt', 'minecraft:grass_block', 'minecraft:mud',
        ]);
        $this->vegetationReplaceable = $this->stateSet([
            'minecraft:air', 'minecraft:snow_layer', 'minecraft:short_grass', 'minecraft:tall_grass',
            'minecraft:fern', 'minecraft:pale_moss_carpet', 'minecraft:closed_eyeblossom',
            'minecraft:open_eyeblossom',
            'minecraft:dandelion', 'minecraft:poppy', 'minecraft:blue_orchid', 'minecraft:allium',
            'minecraft:azure_bluet', 'minecraft:red_tulip', 'minecraft:white_tulip', 'minecraft:pink_tulip',
            'minecraft:oxeye_daisy', 'minecraft:lily_of_the_valley', 'minecraft:deadbush',
            'minecraft:red_mushroom', 'minecraft:brown_mushroom', 'minecraft:bamboo',
            'minecraft:oak_leaves', 'minecraft:birch_leaves', 'minecraft:spruce_leaves',
            'minecraft:acacia_leaves', 'minecraft:dark_oak_leaves', 'minecraft:jungle_leaves',
            'minecraft:cherry_leaves', 'minecraft:mangrove_leaves', 'minecraft:pale_oak_leaves',
            'minecraft:azalea_leaves', 'minecraft:azalea_leaves_flowered',
        ]);
    }

    public function name(): string
    {
        return 'default';
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function generate(ChunkPosition $position): Chunk
    {
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        $samples = $this->terrainRegion($position, self::TERRAIN_SAMPLE_MARGIN);
        $builder = new MutableChunkBuilder($position, $this->blocks);
        $terrainHeights = array_fill(0, 256, Chunk::MIN_Y);
        $stone = $this->blocks->state('minecraft:stone');
        $deepslate = $this->blocks->state('minecraft:deepslate');
        $water = $this->blocks->state('minecraft:water');
        $lava = $this->blocks->state('minecraft:lava');
        $lattice = $this->volumeSurfaceLatticeForChunk($position->x, $position->z);

        $rawSurfaceHeights = $this->rawSurfaceHeightsForChunk($position->x, $position->z, $lattice);

        for ($localZ = 0; $localZ < 16; ++$localZ) {
            for ($localX = 0; $localX < 16; ++$localX) {
                $worldX = $originX + $localX;
                $worldZ = $originZ + $localZ;
                $column = $localX + $localZ * 16;
                $sample = $samples[$worldZ][$worldX];
                $rawHeight = $rawSurfaceHeights[$column];
                $height = $this->landforms->surfaceHeight(
                    $worldX,
                    $worldZ,
                    $rawHeight,
                    $sample->climate,
                    $sample->riverStrength,
                );
                $terrainHeights[$column] = $height;
                $this->rememberVolumeSurfaceHeight($worldX, $worldZ, $height);
            }
        }
        $terrainHeights = array_values($terrainHeights);
        $builder->initializeTerrainColumns($terrainHeights, self::SEA_LEVEL, $deepslate, $stone, $water);
        for ($localZ = 0; $localZ < 16; ++$localZ) {
            for ($localX = 0; $localX < 16; ++$localX) {
                $height = $terrainHeights[$localX + $localZ * 16];
                for ($y = 1; $y <= min(7, $height); ++$y) {
                    if ($this->noise->chance($originX + $localX, $y, $originZ + $localZ, 3_019, 8) < 8 - $y) {
                        $builder->set($localX, $y, $localZ, $deepslate);
                    }
                }
            }
        }
        $carvedRanges = $this->carveCaves($builder, $position, $terrainHeights, $lava);

        $this->fillOpenWaterColumns($builder);
        $this->applyBedrock($builder, $originX, $originZ);
        $samples = $this->replaceGeneratedTerrainSamples($samples, $position, $terrainHeights);
        [$surfaceHeights, $surfaceBiomes] = $this->applySurfaces(
            $builder,
            $samples,
            $terrainHeights,
            $originX,
            $originZ,
        );
        $this->populateGeologyAndOres($builder, $position);
        $this->decorateCaves($builder, $surfaceHeights, $surfaceBiomes, $carvedRanges, $originX, $originZ);
        $this->populateStructures($builder, $position, $samples, $surfaceHeights);
        $this->populateVegetation($builder, $position, $samples, $surfaceHeights, $surfaceBiomes);

        $sections = $builder->sections();
        $biomeStorage = BiomeStorage::fromColumns($surfaceBiomes);
        $biomeStorages = [];
        for ($sectionY = Chunk::MIN_SECTION_Y; $sectionY <= Chunk::MAX_SECTION_Y; ++$sectionY) {
            $biomeStorages[$sectionY] = $biomeStorage;
        }
        return new Chunk(
            $position,
            $this->blocks->state('minecraft:air'),
            $sections,
            $surfaceBiomes[0],
            finalizationState: ChunkFinalizationState::Done,
            biomeStorages: $biomeStorages,
            blockEntities: $builder->blockEntities(),
        );
    }

    public function defaultSpawn(): SpawnPosition
    {
        return $this->spawn ??= $this->spawnCandidate();
    }

    private function spawnCandidate(): SpawnPosition
    {
        if ($this->candidateSpawn !== null) {
            return $this->candidateSpawn;
        }
        $candidates = [[0, 0]];
        foreach ([true, false] as $corners) {
            for ($radius = self::SPAWN_SEARCH_STEP; $radius <= 768; $radius += self::SPAWN_SEARCH_STEP) {
                array_push($candidates, ...self::spawnRing($radius, $corners));
            }
        }
        foreach ($candidates as [$x, $z]) {
            $sample = $this->regionalSample($x, $z);
            $surfaceHeight = $this->densitySurfaceHeightAt($x, $z);
            $biome = $this->biomes->resolve($sample)->identifier;
            if ($surfaceHeight <= self::SEA_LEVEL + 1 || $sample->slope > 5
                || !in_array($biome, [
                    'minecraft:plains', 'minecraft:sunflower_plains', 'minecraft:forest',
                    'minecraft:birch_forest', 'minecraft:taiga', 'minecraft:savanna',
                    'minecraft:meadow', 'minecraft:cherry_grove',
                ], true)) {
                continue;
            }
            return $this->candidateSpawn = new SpawnPosition(
                $x,
                $surfaceHeight + 1,
                $z,
            );
        }

        return $this->candidateSpawn = new SpawnPosition(
            0,
            $this->densitySurfaceHeightAt(0, 0) + 1,
            0,
        );
    }

    /** @return non-empty-list<array{int, int}> */
    private static function spawnRing(int $radius, bool $corners): array
    {
        if ($corners) {
            return [
                [-$radius, -$radius], [$radius, -$radius],
                [$radius, $radius], [-$radius, $radius],
            ];
        }

        return [
            [0, -$radius], [$radius, 0],
            [0, $radius], [-$radius, 0],
        ];
    }

    public function surfaceHeight(int $x, int $z, ?Biome $biome = null): int
    {
        return $this->densitySurfaceHeightAt($x, $z);
    }

    public function biomeAt(int $x, int $z): Biome
    {
        $sample = $this->regionalSample($x, $z);
        $resolved = $this->biomes->resolve($sample);
        if (!$this->isBeachBiome($resolved)) {
            return $resolved;
        }

        return $this->isNaturalShoreline(
            $x,
            $z,
            $sample,
            fn(int $sampleX, int $sampleZ): int => $this->regionalHeight($sampleX, $sampleZ),
        ) ? $resolved : $this->biomes->resolveDryLand($sample);
    }

    /** Bounded internal lookup using the same eligibility path used during chunk generation. */
    public function locateStructure(
        OverworldStructureFamily $family,
        int $x,
        int $z,
        int $maximumRegionRadius = 64,
    ): ?OverworldStructureLocation {
        return $this->structures->locateNatural(
            $family,
            $x,
            $z,
            fn(int $worldX, int $worldZ): string => $this->biomeAt($worldX, $worldZ)->identifier,
            fn(int $worldX, int $worldZ): int => $this->surfaceHeight($worldX, $worldZ),
            $maximumRegionRadius,
        );
    }

    /** @return array<string, OverworldStructureLocation> */
    public function locateStructureVariants(
        OverworldStructureFamily $family,
        int $x,
        int $z,
        int $maximumRegionRadius = 32,
    ): array {
        return $this->structures->locateNaturalVariants(
            $family,
            $x,
            $z,
            fn(int $worldX, int $worldZ): string => $this->biomeAt($worldX, $worldZ)->identifier,
            fn(int $worldX, int $worldZ): int => $this->surfaceHeight($worldX, $worldZ),
            $maximumRegionRadius,
        );
    }

    /** Fills only sky-connected ocean columns; sealed underground caves remain governed by aquifers. */
    private function fillOpenWaterColumns(MutableChunkBuilder $builder): void
    {
        $air = $this->blocks->state('minecraft:air')->value;
        $water = $this->blocks->state('minecraft:water');
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                if ($builder->state($x, self::SEA_LEVEL, $z)->value !== $water->value) {
                    continue;
                }
                for ($y = self::SEA_LEVEL - 1; $y > Chunk::MIN_Y; --$y) {
                    $state = $builder->state($x, $y, $z)->value;
                    if ($state === $water->value) {
                        continue;
                    }
                    if ($state !== $air) {
                        break;
                    }
                    $builder->set($x, $y, $z, $water);
                }
            }
        }
    }

    private function applyBedrock(MutableChunkBuilder $builder, int $originX, int $originZ): void
    {
        $bedrock = $this->blocks->state('minecraft:bedrock');
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                $builder->set($x, Chunk::MIN_Y, $z, $bedrock);
                $builder->set($x, Chunk::MIN_Y + 1, $z, $bedrock);
                for ($y = Chunk::MIN_Y + 2; $y < Chunk::MIN_Y + 5; ++$y) {
                    if ($this->noise->chance($originX + $x, $y, $originZ + $z, 5_101, 4) >= $y - Chunk::MIN_Y - 1) {
                        $builder->set($x, $y, $z, $bedrock);
                    }
                }
            }
        }
    }

    /**
     * Carves deterministic continuous tunnels from neighboring source chunks.
     * Feature paths keep cave work proportional to actual cave geometry instead
     * of evaluating a three-dimensional noise graph for every terrain block.
     *
     * @param list<int> $surfaceHeights
     * @return array<int, array{int, int}>
     */
    private function carveCaves(
        MutableChunkBuilder $builder,
        ChunkPosition $target,
        array $surfaceHeights,
        InternalBlockStateId $lava,
    ): array {
        $air = $this->blocks->state('minecraft:air');
        $ranges = [];
        $targetX = $target->x * 16;
        $targetZ = $target->z * 16;
        for ($sourceZ = $target->z - 1; $sourceZ <= $target->z + 1; ++$sourceZ) {
            for ($sourceX = $target->x - 1; $sourceX <= $target->x + 1; ++$sourceX) {
                $tunnels = $this->noise->chance($sourceX, 0, $sourceZ, 3_101, 4) === 0
                    ? 1 + $this->noise->chance($sourceX, 0, $sourceZ, 3_103, 2)
                    : 0;
                for ($tunnel = 0; $tunnel < $tunnels; ++$tunnel) {
                    $x = $sourceX * 16 + $this->noise->chance($sourceX, $tunnel, $sourceZ, 3_107, 16);
                    $z = $sourceZ * 16 + $this->noise->chance($sourceX, $tunnel, $sourceZ, 3_109, 16);
                    $y = -42 + $this->noise->chance($sourceX, $tunnel, $sourceZ, 3_113, 98);
                    $heading = $this->noise->chance($sourceX, $tunnel, $sourceZ, 3_127, 6_284) / 1_000.0;
                    $pitch = ($this->noise->chance($sourceX, $tunnel, $sourceZ, 3_131, 801) - 400) / 8_000.0;
                    $length = 18 + $this->noise->chance($sourceX, $tunnel, $sourceZ, 3_137, 25);
                    for ($step = 0; $step < $length; ++$step) {
                        $heading += ($this->noise->chance($sourceX, $tunnel, $sourceZ, 3_139 + $step, 201) - 100) / 2_800.0;
                        $pitch = $pitch * 0.78
                            + ($this->noise->chance($sourceX, $tunnel, $sourceZ, 3_211 + $step, 121) - 60) / 5_000.0;
                        $x += cos($heading) * 1.45;
                        $z += sin($heading) * 1.45;
                        $y += $pitch;
                        $radius = 1.35 + $this->noise->chance($sourceX, $tunnel, $sourceZ, 3_307 + $step, 125) / 100.0;
                        $minimumX = max($targetX, (int) floor($x - $radius));
                        $maximumX = min($targetX + 15, (int) ceil($x + $radius));
                        $minimumZ = max($targetZ, (int) floor($z - $radius));
                        $maximumZ = min($targetZ + 15, (int) ceil($z + $radius));
                        if ($minimumX > $maximumX || $minimumZ > $maximumZ) {
                            continue;
                        }
                        $minimumY = max(Chunk::MIN_Y + 5, (int) floor($y - $radius * 0.72));
                        $maximumY = min(118, (int) ceil($y + $radius * 0.72));
                        for ($blockZ = $minimumZ; $blockZ <= $maximumZ; ++$blockZ) {
                            for ($blockX = $minimumX; $blockX <= $maximumX; ++$blockX) {
                                $localX = $blockX - $targetX;
                                $localZ = $blockZ - $targetZ;
                                $surface = $surfaceHeights[$localX + $localZ * 16] - 5;
                                $horizontal = (($blockX + 0.5 - $x) ** 2 + ($blockZ + 0.5 - $z) ** 2) / ($radius ** 2);
                                if ($horizontal >= 1.0) {
                                    continue;
                                }
                                for ($blockY = $minimumY; $blockY <= min($maximumY, $surface); ++$blockY) {
                                    $vertical = (($blockY + 0.5 - $y) ** 2) / (($radius * 0.72) ** 2);
                                    if ($horizontal + $vertical >= 1.0) {
                                        continue;
                                    }
                                    $builder->set($localX, $blockY, $localZ, $blockY <= -55 ? $lava : $air);
                                    $column = $localX + $localZ * 16;
                                    $range = $ranges[$column] ?? null;
                                    $ranges[$column] = $range === null
                                        ? [$blockY, $blockY]
                                        : [min($range[0], $blockY), max($range[1], $blockY)];
                                }
                            }
                        }
                    }
                }
            }
        }

        return $ranges;
    }

    /**
     * Reconciles climate samples with the final volumetric surface used by the
     * generated chunk. Neighboring margin samples remain available for stable
     * biome and feature decisions at chunk borders.
     *
     * @param array<int, array<int, OverworldTerrainSample>> $samples
     * @param list<int> $surfaceHeights
     * @return array<int, array<int, OverworldTerrainSample>>
     */
    private function replaceGeneratedTerrainSamples(
        array $samples,
        ChunkPosition $position,
        array $surfaceHeights,
    ): array {
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        for ($localZ = 0; $localZ < 16; ++$localZ) {
            for ($localX = 0; $localX < 16; ++$localX) {
                $worldX = $originX + $localX;
                $worldZ = $originZ + $localZ;
                $height = $surfaceHeights[$localX + $localZ * 16];
                $heightAt = function (int $x, int $z) use ($samples, $surfaceHeights, $originX, $originZ): int {
                    $localX = $x - $originX;
                    $localZ = $z - $originZ;
                    if ($localX >= 0 && $localX < 16 && $localZ >= 0 && $localZ < 16) {
                        return $surfaceHeights[$localX + $localZ * 16];
                    }

                    return $samples[$z][$x]->surfaceHeight;
                };
                $sample = $samples[$worldZ][$worldX];
                $east = $heightAt($worldX + 2, $worldZ);
                $west = $heightAt($worldX - 2, $worldZ);
                $south = $heightAt($worldX, $worldZ + 2);
                $north = $heightAt($worldX, $worldZ - 2);
                $samples[$worldZ][$worldX] = new OverworldTerrainSample(
                    $sample->climate,
                    $height,
                    intdiv(max(
                        abs($height - $east),
                        abs($height - $west),
                        abs($height - $south),
                        abs($height - $north),
                    ) + 1, 2),
                    $sample->riverStrength,
                    max(-64, min(64, intdiv($east + $west + $south + $north - 4 * $height, 4))),
                );
            }
        }

        return $samples;
    }

    /**
     * @param array<int, array<int, OverworldTerrainSample>> $samples
     * @param array<int, int> $terrainHeights
     * @return array{list<int>, list<Biome>}
     */
    private function applySurfaces(
        MutableChunkBuilder $builder,
        array $samples,
        array $terrainHeights,
        int $originX,
        int $originZ,
    ): array {
        $heights = [];
        $biomes = [];
        $waterValue = $this->blocks->state('minecraft:water')->value;
        $stoneValue = $this->blocks->state('minecraft:stone')->value;
        $deepslateValue = $this->blocks->state('minecraft:deepslate')->value;
        $airValue = $this->blocks->state('minecraft:air')->value;
        $snowLayer = $this->blocks->state('minecraft:snow_layer');
        $ice = $this->blocks->state('minecraft:ice');
        $blueIce = $this->blocks->state('minecraft:blue_ice');
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                $worldX = $originX + $x;
                $worldZ = $originZ + $z;
                $sample = $samples[$worldZ][$worldX];
                $height = $terrainHeights[$x + $z * 16];
                $resolved = $this->surfaceBiomeFromRegion($samples, $sample, $worldX, $worldZ);
                $submerged = $height < self::SEA_LEVEL
                    && $builder->state($x, $height + 1, $z)->value === $waterValue;
                [$top, $filler, $depth, $snow] = $submerged
                    ? $this->submergedSurfaceRule($sample, $worldX, $worldZ)
                    : $this->surfaceRule($resolved, $sample, $worldX, $worldZ);
                for ($y = max(Chunk::MIN_Y + 5, $height - $depth); $y < $height; ++$y) {
                    $current = $builder->state($x, $y, $z)->value;
                    if ($current === $stoneValue || $current === $deepslateValue) {
                        $builder->set($x, $y, $z, $filler);
                    }
                }
                $builder->set($x, $height, $z, $top);
                if (str_contains($resolved->identifier, 'frozen_ocean')
                    && $builder->state($x, self::SEA_LEVEL, $z)->value === $waterValue) {
                    $builder->set(
                        $x,
                        self::SEA_LEVEL,
                        $z,
                        $this->noise->chance($worldX, 0, $worldZ, 5_417, 19) === 0 ? $blueIce : $ice,
                    );
                }
                if ($snow && $height + 1 <= Chunk::MAX_Y
                    && $builder->state($x, $height + 1, $z)->value === $airValue) {
                    $builder->set($x, $height + 1, $z, $snowLayer);
                }
                $heights[] = $height;
                $biomes[] = $resolved;
            }
        }

        return [$heights, $biomes];
    }

    /**
     * @return array{\Bedriox\Server\World\Block\InternalBlockStateId, \Bedriox\Server\World\Block\InternalBlockStateId, int, bool}
     */
    private function surfaceRule(Biome $biome, OverworldTerrainSample $sample, int $x, int $z): array
    {
        $id = $biome->identifier;
        $depth = 3 + $this->noise->chance($x, 0, $z, 5_211, 3);
        $coherent = $this->surfaceField->sample(new OverworldSurfaceContext(
            $x,
            $z,
            $sample->surfaceHeight,
            self::SEA_LEVEL,
            min(64, $sample->slope),
            $sample->curvature,
            0,
            $this->isBeachBiome($biome) || $id === 'minecraft:river' || $id === 'minecraft:frozen_river'
                ? 2
                : 16,
            $sample->riverStrength,
            $sample->climate->temperature,
            $sample->climate->humidity,
        ));
        if (str_contains($id, 'mesa')) {
            $colors = ['minecraft:orange_terracotta', 'minecraft:red_terracotta', 'minecraft:yellow_terracotta'];
            $state = $this->blocks->state($colors[abs(intdiv($sample->climate->detail, 3_000)) % count($colors)]);

            return [$id === 'minecraft:mesa_bryce' ? $this->blocks->state('minecraft:red_sand') : $state, $state, 7, false];
        }
        if (str_contains($id, 'desert')) {
            return [$this->blocks->state('minecraft:sand'), $this->blocks->state('minecraft:sandstone'), 5, false];
        }
        if (in_array($id, ['minecraft:beach', 'minecraft:cold_beach'], true)) {
            $gravel = $coherent->material === OverworldSurfaceMaterial::GravelShore
                || ($id === 'minecraft:cold_beach' && $coherent->sedimentMask < 2_500);
            $state = $this->blocks->state($gravel ? 'minecraft:gravel' : 'minecraft:sand');

            return [$state, $this->blocks->state('minecraft:sand'), min(4, $depth), false];
        }
        if (str_contains($id, 'ocean')) {
            $choice = $this->noise->chance($x, 0, $z, 5_307, 5);
            $state = $this->blocks->state($choice === 0 ? 'minecraft:clay' : ($choice <= 2 ? 'minecraft:gravel' : 'minecraft:sand'));

            return [$state, $state, $depth, false];
        }
        if ($id === 'minecraft:river' || $id === 'minecraft:frozen_river') {
            return [$this->blocks->state('minecraft:gravel'), $this->blocks->state('minecraft:clay'), $depth, false];
        }
        if ($id === 'minecraft:stone_beach') {
            $state = $this->blocks->state($this->noise->chance($x, 0, $z, 5_263, 4) === 0
                ? 'minecraft:gravel'
                : 'minecraft:stone');

            return [$state, $this->blocks->state('minecraft:stone'), 2, false];
        }
        if (str_contains($id, 'peak') || str_contains($id, 'extreme_hills')) {
            $snow = str_contains($id, 'frozen') || str_contains($id, 'jagged');
            if ($coherent->material === OverworldSurfaceMaterial::Stone
                || $coherent->material === OverworldSurfaceMaterial::GravelScree) {
                return $this->surfaceMaterialRule($coherent->material, 2, $snow);
            }

            return [$this->blocks->state('minecraft:grass_block'), $this->blocks->state('minecraft:dirt'), $depth, $snow];
        }
        if (str_contains($id, 'snow') || str_contains($id, 'ice_') || str_contains($id, 'grove') || str_contains($id, 'cold_taiga')) {
            if ($coherent->material === OverworldSurfaceMaterial::Stone
                || $coherent->material === OverworldSurfaceMaterial::GravelScree) {
                $rock = $this->blocks->state($coherent->material === OverworldSurfaceMaterial::Stone
                    ? 'minecraft:stone'
                    : 'minecraft:gravel');

                return [$rock, $this->blocks->state('minecraft:stone'), 2, true];
            }
            return [$this->blocks->state('minecraft:grass_block'), $this->blocks->state('minecraft:dirt'), $depth, true];
        }
        if (str_contains($id, 'taiga')) {
            $top = match ($coherent->material) {
                OverworldSurfaceMaterial::CoarseDirt => 'minecraft:coarse_dirt',
                OverworldSurfaceMaterial::Podzol => 'minecraft:podzol',
                default => 'minecraft:grass_block',
            };

            return [$this->blocks->state($top), $this->blocks->state('minecraft:dirt'), $depth, false];
        }
        if ($id === 'minecraft:mangrove_swamp') {
            return [$this->blocks->state('minecraft:mud'), $this->blocks->state('minecraft:dirt'), $depth + 1, false];
        }
        if (str_contains($id, 'swamp')) {
            $mud = $sample->surfaceHeight <= self::SEA_LEVEL + 1
                || $coherent->sedimentMask > 8_000;

            return [
                $this->blocks->state($mud ? 'minecraft:mud' : 'minecraft:grass_block'),
                $this->blocks->state('minecraft:dirt'),
                $depth,
                false,
            ];
        }
        if ($id === 'minecraft:pale_garden') {
            return [$this->blocks->state('minecraft:pale_moss_block'), $this->blocks->state('minecraft:dirt'), $depth, false];
        }
        if (str_contains($id, 'mushroom_island')) {
            return [$this->blocks->state('minecraft:mycelium'), $this->blocks->state('minecraft:dirt'), $depth, false];
        }

        return $this->surfaceMaterialRule($coherent->material, $depth, false);
    }

    /**
     * @return array{\Bedriox\Server\World\Block\InternalBlockStateId, \Bedriox\Server\World\Block\InternalBlockStateId, int, false}
     */
    private function submergedSurfaceRule(OverworldTerrainSample $sample, int $x, int $z): array
    {
        $surface = $this->surfaceField->sample(new OverworldSurfaceContext(
            $x,
            $z,
            $sample->surfaceHeight,
            self::SEA_LEVEL,
            min(64, $sample->slope),
            $sample->curvature,
            max(1, self::SEA_LEVEL - $sample->surfaceHeight),
            0,
            $sample->riverStrength,
            $sample->climate->temperature,
            $sample->climate->humidity,
        ));
        $identifier = match ($surface->material) {
            OverworldSurfaceMaterial::GravelShore, OverworldSurfaceMaterial::GravelScree => 'minecraft:gravel',
            OverworldSurfaceMaterial::MudBed => 'minecraft:mud',
            OverworldSurfaceMaterial::ClayBed => 'minecraft:clay',
            default => 'minecraft:sand',
        };
        $state = $this->blocks->state($identifier);

        return [$state, $state, 2 + $this->noise->chance($x, 0, $z, 5_311, 3), false];
    }

    /**
     * @return array{InternalBlockStateId, InternalBlockStateId, int, bool}
     */
    private function surfaceMaterialRule(
        OverworldSurfaceMaterial $material,
        int $depth,
        bool $snow,
    ): array {
        [$top, $filler, $maximumDepth] = match ($material) {
            OverworldSurfaceMaterial::Grass => ['minecraft:grass_block', 'minecraft:dirt', $depth],
            OverworldSurfaceMaterial::CoarseDirt => ['minecraft:coarse_dirt', 'minecraft:dirt', $depth],
            OverworldSurfaceMaterial::Podzol => ['minecraft:podzol', 'minecraft:dirt', $depth],
            OverworldSurfaceMaterial::Stone => ['minecraft:stone', 'minecraft:stone', 2],
            OverworldSurfaceMaterial::GravelScree => ['minecraft:gravel', 'minecraft:stone', 2],
            OverworldSurfaceMaterial::Sand => ['minecraft:sand', 'minecraft:sandstone', min(4, $depth)],
            OverworldSurfaceMaterial::GravelShore => ['minecraft:gravel', 'minecraft:sand', min(3, $depth)],
            OverworldSurfaceMaterial::ClayBed => ['minecraft:clay', 'minecraft:clay', min(3, $depth)],
            OverworldSurfaceMaterial::MudBed => ['minecraft:mud', 'minecraft:dirt', min(4, $depth)],
        };

        return [$this->blocks->state($top), $this->blocks->state($filler), $maximumDepth, $snow];
    }

    /** @param array<int, array<int, OverworldTerrainSample>> $samples */
    private function surfaceBiomeFromRegion(
        array $samples,
        OverworldTerrainSample $sample,
        int $x,
        int $z,
    ): Biome {
        $resolved = $this->biomes->resolve($sample);
        if (!$this->isBeachBiome($resolved)) {
            return $resolved;
        }

        return $this->isNaturalShoreline(
            $x,
            $z,
            $sample,
            fn(int $sampleX, int $sampleZ): int => isset($samples[$sampleZ][$sampleX])
                ? $samples[$sampleZ][$sampleX]->surfaceHeight
                : $this->planningHeightAt($sampleX, $sampleZ),
        ) ? $resolved : $this->biomes->resolveDryLand($sample);
    }

    /** @param callable(int, int): int $heightAt */
    private function isNaturalShoreline(
        int $x,
        int $z,
        OverworldTerrainSample $sample,
        callable $heightAt,
    ): bool {
        if ($sample->surfaceHeight < self::SEA_LEVEL || $sample->riverStrength >= 940) {
            return false;
        }
        $widthSignal = $this->noise->sample2d($x, $z, 72, 5_249) + 32_768;
        $width = 4 + intdiv($widthSignal * (self::MAXIMUM_BEACH_WIDTH - 3), 65_536);
        $heightLimit = self::SEA_LEVEL + 1 + intdiv($widthSignal * 3, 65_536);
        if ($sample->surfaceHeight > $heightLimit) {
            return false;
        }
        for ($distance = 1; $distance <= $width; ++$distance) {
            for ($offset = -$distance; $offset <= $distance; ++$offset) {
                if ($heightAt($x + $offset, $z - $distance) < self::SEA_LEVEL
                    || $heightAt($x + $offset, $z + $distance) < self::SEA_LEVEL) {
                    return true;
                }
            }
            for ($offset = -$distance + 1; $offset < $distance; ++$offset) {
                if ($heightAt($x - $distance, $z + $offset) < self::SEA_LEVEL
                    || $heightAt($x + $distance, $z + $offset) < self::SEA_LEVEL) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isBeachBiome(Biome $biome): bool
    {
        return in_array($biome->identifier, ['minecraft:beach', 'minecraft:cold_beach', 'minecraft:stone_beach'], true);
    }

    private function populateGeologyAndOres(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        $this->geology->populate($builder, $position);
    }

    /**
     * @param list<int> $surfaceHeights
     * @param list<Biome> $surfaceBiomes
     * @param array<int, array{int, int}> $carvedRanges
     */
    private function decorateCaves(
        MutableChunkBuilder $builder,
        array $surfaceHeights,
        array $surfaceBiomes,
        array $carvedRanges,
        int $originX,
        int $originZ,
    ): void {
        $air = $this->blocks->state('minecraft:air')->value;
        foreach ($carvedRanges as $column => [$minimumY, $maximumY]) {
            $x = $column & 15;
            $z = $column >> 4;
            $surface = $surfaceHeights[$column];
            for ($y = max(Chunk::MIN_Y + 6, $minimumY); $y <= min(48, $surface - 10, $maximumY); ++$y) {
                if ($builder->state($x, $y, $z)->value !== $air
                    || $builder->state($x, $y - 1, $z)->value === $air) {
                    continue;
                }
                $chance = $this->noise->chance($originX + $x, $y, $originZ + $z, 7_307, 19);
                if ($chance > 2) {
                    continue;
                }
                $biome = $this->caveBiomes->resolve(
                    $originX + $x,
                    $y,
                    $originZ + $z,
                    $surface,
                    $surfaceBiomes[$x + $z * 16],
                )->identifier;
                if ($biome === 'minecraft:lush_caves') {
                    $builder->set($x, $y - 1, $z, $this->blocks->state('minecraft:moss_block'));
                } elseif ($biome === 'minecraft:dripstone_caves' && $chance === 0) {
                    $builder->set($x, $y - 1, $z, $this->blocks->state('minecraft:dripstone_block'));
                    $builder->set($x, $y, $z, $this->blocks->state('minecraft:pointed_dripstone'));
                } elseif ($biome === 'minecraft:deep_dark' && $chance < 2) {
                    $builder->set($x, $y - 1, $z, $this->blocks->state('minecraft:sculk'));
                } elseif ($biome === 'minecraft:sulfur_caves' && $chance === 0) {
                    $builder->set($x, $y - 1, $z, $this->blocks->state('minecraft:calcite'));
                }
            }
        }
    }

    /**
     * @param array<int, array<int, OverworldTerrainSample>> $samples
     * @param list<int> $surfaceHeights
     * @param list<Biome> $surfaceBiomes
     */
    private function populateVegetation(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $samples,
        array $surfaceHeights,
        array $surfaceBiomes,
    ): void {
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        $groundSignals = [];
        for ($localZ = 0; $localZ < 16; ++$localZ) {
            for ($localX = 0; $localX < 16; ++$localX) {
                $worldX = $originX + $localX;
                $worldZ = $originZ + $localZ;
                $sample = $samples[$worldZ][$worldX];
                $biome = $surfaceBiomes[$localX + $localZ * 16]->identifier;
                if ($this->candidateSpawn !== null
                    && abs($worldX - $this->candidateSpawn->x) <= 4
                    && abs($worldZ - $this->candidateSpawn->z) <= 4) {
                    continue;
                }
                if (str_contains($biome, 'ocean')) {
                    $this->placeAquaticFeature(
                        $builder,
                        $position,
                        $surfaceHeights,
                        $worldX,
                        $worldZ,
                        $biome,
                        $sample,
                    );
                    continue;
                }
                $signalX = self::floorDiv($worldX, 4);
                $signalZ = self::floorDiv($worldZ, 4);
                $signalKey = $signalX . ':' . $signalZ;
                $signals = $groundSignals[$signalKey] ??= [
                    $this->noise->sample2d($signalX * 4, $signalZ * 4, 28, 8_409),
                    $this->noise->sample2d($signalX * 4, $signalZ * 4, 36, 8_419),
                ];
                $this->placeGroundFeature(
                    $builder,
                    $position,
                    $surfaceHeights,
                    $worldX,
                    $worldZ,
                    $biome,
                    $signals[0],
                    $signals[1],
                );
            }
        }

        $planningHeightAt = fn(int $x, int $z): int => $this->planningHeightAt($x, $z);
        $plans = $this->vegetation->plansAffecting(
            $originX,
            $originX + 15,
            $originZ,
            $originZ + 15,
            fn(int $x, int $z): string => $this->planningBiomeAt($x, $z)->identifier,
            $planningHeightAt,
            $planningHeightAt,
        );
        foreach ($plans as $plan) {
            if ($this->candidateSpawn !== null
                && abs($plan->x - $this->candidateSpawn->x) <= OverworldVegetationPlanner::MAXIMUM_REACH + 2
                && abs($plan->z - $this->candidateSpawn->z) <= OverworldVegetationPlanner::MAXIMUM_REACH + 2) {
                continue;
            }
            $this->placeTreePlan($builder, $position, $surfaceHeights, $plan);
        }
    }

    /** @param list<int> $surfaceHeights */
    private function placeTreePlan(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $surfaceHeights,
        VegetationTreePlan $plan,
    ): void {
        $localX = $plan->x - $position->x * 16;
        $localZ = $plan->z - $position->z * 16;
        $ground = $this->densitySurfaceHeightAt($plan->x, $plan->z);
        if (!$this->treePlanOwnsTerrain($plan, $ground)) {
            return;
        }
        $inside = $localX >= 0 && $localX < 16 && $localZ >= 0 && $localZ < 16;
        $waterTolerant = in_array($plan->archetype, [
            TreeArchetype::MangroveArched,
            TreeArchetype::MangroveBroad,
            TreeArchetype::SwampOak,
        ], true) || in_array($plan->profile, ['swamp', 'mangrove'], true);
        if ($ground < ($waterTolerant ? self::SEA_LEVEL - 2 : self::SEA_LEVEL + 1) || $ground > 230) {
            return;
        }
        if ($inside && ($surfaceHeights[$localX + $localZ * 16] !== $ground
            || !$this->isTreeSubstrate($builder->state($localX, $ground, $localZ)))) {
            return;
        }
        if ($inside) {
            for ($y = $ground + 1; $y <= $ground + $plan->height; ++$y) {
                if (!$this->isVegetationReplaceable($builder->state($localX, $y, $localZ))) {
                    return;
                }
            }
        }

        switch ($plan->archetype) {
            case TreeArchetype::OakCompact:
                $this->renderCompactTree($builder, $position, $plan, $ground);
                break;
            case TreeArchetype::OakBroad:
                $this->renderBroadTree($builder, $position, $plan, $ground, 3, 3);
                break;
            case TreeArchetype::OakTall:
                $this->renderBroadTree($builder, $position, $plan, $ground, 2, 2);
                break;
            case TreeArchetype::OakForked:
            case TreeArchetype::BirchForked:
                $this->renderForkedTree($builder, $position, $plan, $ground);
                break;
            case TreeArchetype::OakLeaning:
            case TreeArchetype::SwampOak:
                $this->renderLeaningTree($builder, $position, $plan, $ground);
                break;
            case TreeArchetype::BirchSlender:
                $this->renderColumnTree($builder, $position, $plan, $ground, 2, 2);
                break;
            case TreeArchetype::BirchTall:
                $this->renderColumnTree($builder, $position, $plan, $ground, 2, 3);
                break;
            case TreeArchetype::SpruceConical:
            case TreeArchetype::SpruceTall:
            case TreeArchetype::SpruceWide:
                $this->renderSpruceTree($builder, $position, $plan, $ground);
                break;
            case TreeArchetype::DarkOakBroad:
            case TreeArchetype::DarkOakTall:
                $this->renderDarkOakTree($builder, $position, $plan, $ground);
                break;
            case TreeArchetype::PaleOakGnarled:
            case TreeArchetype::PaleOakTall:
                $this->renderPaleOakTree($builder, $position, $plan, $ground);
                break;
            case TreeArchetype::AcaciaFlat:
            case TreeArchetype::AcaciaSplit:
                $this->renderAcaciaTree($builder, $position, $plan, $ground);
                break;
            case TreeArchetype::JungleTall:
            case TreeArchetype::JungleBranching:
                $this->renderJungleTree($builder, $position, $plan, $ground);
                break;
            case TreeArchetype::MangroveArched:
            case TreeArchetype::MangroveBroad:
                $this->renderMangroveTree($builder, $position, $plan, $ground);
                break;
            case TreeArchetype::CherryWide:
            case TreeArchetype::CherryTall:
                $this->renderCherryTree($builder, $position, $plan, $ground);
                break;
        }
    }

    /** Every chunk touched by a tree independently validates the same root owner. */
    private function treePlanOwnsTerrain(VegetationTreePlan $plan, int $ground): bool
    {
        $waterTolerant = in_array($plan->archetype, [
            TreeArchetype::MangroveArched,
            TreeArchetype::MangroveBroad,
            TreeArchetype::SwampOak,
        ], true) || in_array($plan->profile, ['swamp', 'mangrove'], true);
        if ($ground < ($waterTolerant ? self::SEA_LEVEL - 2 : self::SEA_LEVEL + 1) || $ground > 230) {
            return false;
        }
        if (!$waterTolerant && $ground <= self::SEA_LEVEL + 3
            && $this->isBeachBiome($this->biomeAt($plan->x, $plan->z))) {
            return false;
        }
        $shapeHeight = $this->planningHeightAt($plan->x, $plan->z);
        $slope = max(
            abs($shapeHeight - $this->planningHeightAt($plan->x + 2, $plan->z)),
            abs($shapeHeight - $this->planningHeightAt($plan->x - 2, $plan->z)),
            abs($shapeHeight - $this->planningHeightAt($plan->x, $plan->z + 2)),
            abs($shapeHeight - $this->planningHeightAt($plan->x, $plan->z - 2)),
        );

        $maximumSlope = match ($plan->profile) {
            'old_growth_taiga', 'taiga' => 10,
            'swamp', 'mangrove' => 9,
            default => 7,
        };

        return $slope < $maximumSlope;
    }

    private function renderCompactTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
    ): void {
        $this->placeVerticalTrunk($builder, $position, $plan, $ground, $plan->height);
        $this->placeLeafEllipsoid(
            $builder,
            $position,
            $plan,
            $plan->x,
            $ground + $plan->height,
            $plan->z,
            2,
            2,
            2,
            0,
        );
    }

    private function renderBroadTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
        int $branchCount,
        int $crownRadius,
    ): void {
        $this->placeVerticalTrunk($builder, $position, $plan, $ground, $plan->height);
        $top = $ground + $plan->height;
        $this->placeLeafEllipsoid($builder, $position, $plan, $plan->x, $top, $plan->z, $crownRadius, 2, $crownRadius, 1);
        for ($branch = 0; $branch < $branchCount; ++$branch) {
            [$dx, $dz] = self::direction($plan->rotation + $branch + ($plan->shapeSeed >> ($branch * 2) & 1));
            $length = 2 + (($plan->shapeSeed >> (4 + $branch * 2)) & 1);
            [$endX, $endY, $endZ] = $this->placeBranch(
                $builder,
                $position,
                $plan,
                $plan->x,
                $top - 2 + ($branch & 1),
                $plan->z,
                $dx,
                $dz,
                $length,
                1,
            );
            $this->placeLeafEllipsoid($builder, $position, $plan, $endX, $endY, $endZ, 2, 2, 2, 10 + $branch);
        }
    }

    private function renderForkedTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
    ): void {
        $trunkHeight = max(4, $plan->height - 3);
        $this->placeVerticalTrunk($builder, $position, $plan, $ground, $trunkHeight);
        $startY = $ground + $trunkHeight;
        for ($fork = 0; $fork < 2; ++$fork) {
            [$dx, $dz] = self::direction($plan->rotation + $fork * 2 + (($plan->shapeSeed >> $fork) & 1));
            [$endX, $endY, $endZ] = $this->placeBranch(
                $builder,
                $position,
                $plan,
                $plan->x,
                $startY,
                $plan->z,
                $dx,
                $dz,
                3 + (($plan->shapeSeed >> (4 + $fork)) & 1),
                2,
            );
            $this->placeLeafEllipsoid($builder, $position, $plan, $endX, $endY, $endZ, 2, 2, 2, 20 + $fork);
        }
        $this->placeLeafEllipsoid($builder, $position, $plan, $plan->x, $startY + 2, $plan->z, 2, 2, 2, 24);
    }

    private function renderLeaningTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
    ): void {
        [$dx, $dz] = self::direction($plan->rotation);
        $bendStart = intdiv($plan->height, 2);
        $x = $plan->x;
        $z = $plan->z;
        for ($level = 1; $level <= $plan->height; ++$level) {
            if ($level > $bendStart && ($level - $bendStart) % 2 === 0) {
                $x += $dx;
                $z += $dz;
            }
            $axis = $level > $bendStart && ($level - $bendStart) % 2 === 0
                ? ($dx !== 0 ? BlockAxis::X : BlockAxis::Z)
                : BlockAxis::Y;
            $this->placeTreeBlock($builder, $position, $x, $ground + $level, $z, $plan->archetype->logIdentifier(), $axis);
        }
        $radius = $plan->archetype === TreeArchetype::SwampOak ? 3 : 2;
        $this->placeLeafEllipsoid($builder, $position, $plan, $x, $ground + $plan->height, $z, $radius, 2, $radius, 30);
        [$sideX, $sideZ] = self::direction($plan->rotation + 1);
        $this->placeLeafEllipsoid($builder, $position, $plan, $x + $sideX * 2, $ground + $plan->height - 1, $z + $sideZ * 2, 2, 2, 2, 31);
    }

    private function renderColumnTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
        int $radius,
        int $verticalRadius,
    ): void {
        $this->placeVerticalTrunk($builder, $position, $plan, $ground, $plan->height);
        $this->placeLeafEllipsoid(
            $builder,
            $position,
            $plan,
            $plan->x,
            $ground + $plan->height - 1,
            $plan->z,
            $radius,
            $verticalRadius,
            $radius,
            40,
        );
    }

    private function renderSpruceTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
    ): void {
        $this->placeVerticalTrunk($builder, $position, $plan, $ground, $plan->height);
        $top = $ground + $plan->height;
        $wide = $plan->archetype === TreeArchetype::SpruceWide ? 1 : 0;
        $crownDepth = min($plan->height - 2, 5 + $wide * 2 + ($plan->shapeSeed & 1));
        for ($depth = 0; $depth <= $crownDepth; ++$depth) {
            $y = $top - $depth;
            $wave = ($depth + ($plan->shapeSeed & 1)) % 3;
            $radius = min(3 + $wide, 1 + intdiv($depth + 1, 2));
            if ($wave === 0 && $depth > 1) {
                --$radius;
            }
            $this->placeLeafDisc($builder, $position, $plan, $plan->x, $y, $plan->z, max(1, $radius), 50 + $depth);
        }
        $this->placeTreeBlock($builder, $position, $plan->x, $top + 1, $plan->z, $plan->archetype->leavesIdentifier());
    }

    private function renderDarkOakTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
    ): void {
        $height = $plan->height;
        foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$offsetX, $offsetZ]) {
            $this->placeVerticalTrunk($builder, $position, $plan, $ground, $height, $offsetX, $offsetZ);
        }
        $top = $ground + $height;
        $this->placeLeafEllipsoid($builder, $position, $plan, $plan->x, $top, $plan->z, 4, 2, 4, 60);
        for ($branch = 0; $branch < 4; ++$branch) {
            [$dx, $dz] = self::direction($plan->rotation + $branch);
            [$endX, $endY, $endZ] = $this->placeBranch(
                $builder,
                $position,
                $plan,
                $plan->x,
                $top - 2 + ($branch & 1),
                $plan->z,
                $dx,
                $dz,
                3,
                1,
            );
            $this->placeLeafEllipsoid($builder, $position, $plan, $endX, $endY, $endZ, 2, 2, 2, 61 + $branch);
        }
    }

    private function renderPaleOakTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
    ): void {
        $trunkHeight = max(5, $plan->height - 3);
        $this->placeVerticalTrunk($builder, $position, $plan, $ground, $trunkHeight);
        $startY = $ground + $trunkHeight - 1;
        $branches = $plan->archetype === TreeArchetype::PaleOakGnarled ? 4 : 3;
        for ($branch = 0; $branch < $branches; ++$branch) {
            [$dx, $dz] = self::direction($plan->rotation + $branch);
            $length = 3 + (($plan->shapeSeed >> ($branch * 2)) & 1);
            [$endX, $endY, $endZ] = $this->placeBranch(
                $builder,
                $position,
                $plan,
                $plan->x,
                $startY + ($branch & 1),
                $plan->z,
                $dx,
                $dz,
                $length,
                2,
            );
            $this->placeLeafEllipsoid($builder, $position, $plan, $endX, $endY, $endZ, 3, 2, 3, 70 + $branch);
        }
        $this->placeLeafEllipsoid($builder, $position, $plan, $plan->x, $startY + 3, $plan->z, 3, 2, 3, 76);
    }

    private function renderAcaciaTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
    ): void {
        [$dx, $dz] = self::direction($plan->rotation);
        $straight = max(3, $plan->height - 3);
        $this->placeVerticalTrunk($builder, $position, $plan, $ground, $straight);
        [$endX, $endY, $endZ] = $this->placeBranch(
            $builder,
            $position,
            $plan,
            $plan->x,
            $ground + $straight,
            $plan->z,
            $dx,
            $dz,
            3,
            2,
        );
        $this->placeLeafEllipsoid($builder, $position, $plan, $endX, $endY, $endZ, 4, 1, 3, 80);
        if ($plan->archetype === TreeArchetype::AcaciaSplit) {
            [$sideX, $sideZ] = self::direction($plan->rotation + 2);
            [$sideEndX, $sideEndY, $sideEndZ] = $this->placeBranch(
                $builder,
                $position,
                $plan,
                $plan->x,
                $ground + $straight - 1,
                $plan->z,
                $sideX,
                $sideZ,
                2,
                1,
            );
            $this->placeLeafEllipsoid($builder, $position, $plan, $sideEndX, $sideEndY, $sideEndZ, 3, 1, 2, 81);
        }
    }

    private function renderJungleTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
    ): void {
        $thick = $plan->archetype === TreeArchetype::JungleBranching;
        $this->placeVerticalTrunk($builder, $position, $plan, $ground, $plan->height);
        if ($thick) {
            $this->placeVerticalTrunk($builder, $position, $plan, $ground, $plan->height - 2, 1, 0);
            $this->placeVerticalTrunk($builder, $position, $plan, $ground, $plan->height - 3, 0, 1);
            $this->placeVerticalTrunk($builder, $position, $plan, $ground, $plan->height - 4, 1, 1);
        }
        $top = $ground + $plan->height;
        $this->placeLeafEllipsoid($builder, $position, $plan, $plan->x, $top, $plan->z, $thick ? 4 : 3, 2, $thick ? 4 : 3, 90);
        $branchCount = $thick ? 4 : 2;
        for ($branch = 0; $branch < $branchCount; ++$branch) {
            [$dx, $dz] = self::direction($plan->rotation + $branch);
            [$endX, $endY, $endZ] = $this->placeBranch(
                $builder,
                $position,
                $plan,
                $plan->x,
                $top - 3 - $branch,
                $plan->z,
                $dx,
                $dz,
                3,
                1,
            );
            $this->placeLeafEllipsoid($builder, $position, $plan, $endX, $endY, $endZ, 2, 2, 2, 91 + $branch);
        }
    }

    private function renderMangroveTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
    ): void {
        $this->placeVerticalTrunk($builder, $position, $plan, $ground, $plan->height);
        for ($root = 0; $root < 4; ++$root) {
            [$dx, $dz] = self::direction($plan->rotation + $root);
            $this->placeBranch(
                $builder,
                $position,
                $plan,
                $plan->x,
                $ground + 3,
                $plan->z,
                $dx,
                $dz,
                2 + (($plan->shapeSeed >> $root) & 1),
                -2,
            );
        }
        $top = $ground + $plan->height;
        $radius = $plan->archetype === TreeArchetype::MangroveBroad ? 4 : 3;
        $this->placeLeafEllipsoid($builder, $position, $plan, $plan->x, $top, $plan->z, $radius, 2, $radius, 100);
        for ($branch = 0; $branch < 3; ++$branch) {
            [$dx, $dz] = self::direction($plan->rotation + $branch);
            [$endX, $endY, $endZ] = $this->placeBranch(
                $builder,
                $position,
                $plan,
                $plan->x,
                $top - 2,
                $plan->z,
                $dx,
                $dz,
                3,
                1,
            );
            $this->placeLeafEllipsoid($builder, $position, $plan, $endX, $endY, $endZ, 2, 2, 2, 101 + $branch);
        }
    }

    private function renderCherryTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
    ): void {
        $trunkHeight = max(4, $plan->height - 3);
        $this->placeVerticalTrunk($builder, $position, $plan, $ground, $trunkHeight);
        $startY = $ground + $trunkHeight;
        $branches = $plan->archetype === TreeArchetype::CherryWide ? 4 : 3;
        for ($branch = 0; $branch < $branches; ++$branch) {
            [$dx, $dz] = self::direction($plan->rotation + $branch);
            [$endX, $endY, $endZ] = $this->placeBranch(
                $builder,
                $position,
                $plan,
                $plan->x,
                $startY,
                $plan->z,
                $dx,
                $dz,
                2 + (($plan->shapeSeed >> $branch) & 1),
                2,
            );
            $this->placeLeafEllipsoid($builder, $position, $plan, $endX, $endY, $endZ, 3, 1, 3, 110 + $branch);
        }
        $this->placeLeafEllipsoid($builder, $position, $plan, $plan->x, $startY + 3, $plan->z, 2, 2, 2, 116);
    }

    private function placeVerticalTrunk(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $ground,
        int $height,
        int $offsetX = 0,
        int $offsetZ = 0,
    ): void {
        for ($level = 1; $level <= $height; ++$level) {
            $this->placeTreeBlock(
                $builder,
                $position,
                $plan->x + $offsetX,
                $ground + $level,
                $plan->z + $offsetZ,
                $plan->archetype->logIdentifier(),
                BlockAxis::Y,
            );
        }
    }

    /** @return array{int, int, int} */
    private function placeBranch(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $startX,
        int $startY,
        int $startZ,
        int $directionX,
        int $directionZ,
        int $length,
        int $rise,
    ): array {
        $x = $startX;
        $y = $startY;
        $z = $startZ;
        $axis = $directionX !== 0 ? BlockAxis::X : BlockAxis::Z;
        for ($step = 1; $step <= $length; ++$step) {
            $x += $directionX;
            $z += $directionZ;
            $nextY = $startY + intdiv($step * $rise, max(1, $length));
            if ($nextY !== $y) {
                $this->placeTreeBlock($builder, $position, $x - $directionX, $nextY, $z - $directionZ, $plan->archetype->logIdentifier(), BlockAxis::Y);
                $y = $nextY;
            }
            $this->placeTreeBlock($builder, $position, $x, $y, $z, $plan->archetype->logIdentifier(), $axis);
        }

        return [$x, $y, $z];
    }

    private function placeLeafEllipsoid(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $centerX,
        int $centerY,
        int $centerZ,
        int $radiusX,
        int $radiusY,
        int $radiusZ,
        int $salt,
    ): void {
        $offsetX = (($plan->shapeSeed >> ($salt % 11)) & 1) === 0 ? 0 : 1;
        $offsetZ = (($plan->shapeSeed >> (($salt + 5) % 13)) & 1) === 0 ? 0 : -1;
        $centerX += $offsetX;
        $centerZ += $offsetZ;
        for ($y = $centerY - $radiusY; $y <= $centerY + $radiusY; ++$y) {
            for ($z = $centerZ - $radiusZ; $z <= $centerZ + $radiusZ; ++$z) {
                for ($x = $centerX - $radiusX; $x <= $centerX + $radiusX; ++$x) {
                    $distance = (($x - $centerX) / max(1, $radiusX)) ** 2
                        + (($y - $centerY) / max(1, $radiusY)) ** 2
                        + (($z - $centerZ) / max(1, $radiusZ)) ** 2;
                    if ($distance > 1.12) {
                        continue;
                    }
                    if ($distance > 0.72 && self::shapeRoll($x, $y, $z, $plan->shapeSeed + $salt, 7) < 2) {
                        continue;
                    }
                    $this->placeTreeBlock($builder, $position, $x, $y, $z, $plan->archetype->leavesIdentifier());
                }
            }
        }
    }

    private function placeLeafDisc(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        VegetationTreePlan $plan,
        int $centerX,
        int $y,
        int $centerZ,
        int $radius,
        int $salt,
    ): void {
        for ($z = $centerZ - $radius; $z <= $centerZ + $radius; ++$z) {
            for ($x = $centerX - $radius; $x <= $centerX + $radius; ++$x) {
                $distance = ($x - $centerX) ** 2 + ($z - $centerZ) ** 2;
                if ($distance > $radius * $radius + 1
                    || ($distance > ($radius - 1) ** 2
                        && self::shapeRoll($x, $y, $z, $plan->shapeSeed + $salt, 5) === 0)) {
                    continue;
                }
                $this->placeTreeBlock($builder, $position, $x, $y, $z, $plan->archetype->leavesIdentifier());
            }
        }
    }

    private function placeTreeBlock(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        int $x,
        int $y,
        int $z,
        string $identifier,
        ?BlockAxis $axis = null,
    ): void {
        $localX = $x - $position->x * 16;
        $localZ = $z - $position->z * 16;
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16) {
            return;
        }
        if (!$this->isVegetationReplaceable($builder->state($localX, $y, $localZ))) {
            return;
        }
        $builder->set($localX, $y, $localZ, $this->blocks->state($identifier, $axis));
    }

    /** @return array{int, int} */
    private static function direction(int $rotation): array
    {
        return match ($rotation & 3) {
            0 => [1, 0],
            1 => [0, 1],
            2 => [-1, 0],
            default => [0, -1],
        };
    }

    private static function shapeRoll(int $x, int $y, int $z, int $seed, int $outOf): int
    {
        $mixed = ($x * 73_856_093) ^ ($y * 19_349_663) ^ ($z * 83_492_791) ^ ($seed * 31_337);
        return abs($mixed ^ ($mixed >> 13)) % $outOf;
    }

    /** @param list<int> $surfaceHeights */
    private function placeGroundFeature(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $surfaceHeights,
        int $x,
        int $z,
        string $biome,
        int $patch,
        int $flowerPatch,
    ): void {
        $localX = $x - $position->x * 16;
        $localZ = $z - $position->z * 16;
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16) {
            return;
        }
        $ground = $this->terrainSurfaceHeight($surfaceHeights, $position, $x, $z);
        if ($ground <= self::SEA_LEVEL) {
            return;
        }
        $roll = $this->noise->scatteredChance($x, 0, $z, 8_401, 128);
        $feature = match (true) {
            str_contains($biome, 'desert') && $roll < 3 => 'minecraft:cactus',
            str_contains($biome, 'desert') && $roll < 11 => 'minecraft:deadbush',
            str_contains($biome, 'pale_garden') && $patch > -4_000 && $roll < 38 => 'minecraft:pale_moss_carpet',
            str_contains($biome, 'pale_garden') && $patch < -9_000 && $roll < 5 => 'minecraft:closed_eyeblossom',
            str_contains($biome, 'pale_garden') && $roll < 10 => 'minecraft:brown_mushroom',
            str_contains($biome, 'bamboo') && $patch > -8_000 && $roll < 36 => 'minecraft:bamboo',
            str_contains($biome, 'swamp') && $flowerPatch > 4_000 && $roll < 10 => 'minecraft:blue_orchid',
            str_contains($biome, 'flower') && $flowerPatch > -8_000 && $roll < 38 => [
                'minecraft:allium', 'minecraft:azure_bluet', 'minecraft:oxeye_daisy',
                'minecraft:red_tulip', 'minecraft:pink_tulip',
            ][$this->noise->scatteredChance($x, 0, $z, 8_421, 5)],
            str_contains($biome, 'cherry') && $flowerPatch > -2_000 && $roll < 20 => 'minecraft:pink_tulip',
            (str_contains($biome, 'taiga') || str_contains($biome, 'forest'))
                && $patch > 5_000 && $roll < 28 => 'minecraft:fern',
            (str_contains($biome, 'roofed') || str_contains($biome, 'taiga')) && $roll < 9 => $roll % 2 === 0
                ? 'minecraft:red_mushroom'
                : 'minecraft:brown_mushroom',
            str_contains($biome, 'mushroom') && $roll < 34 => $roll % 2 === 0
                ? 'minecraft:red_mushroom'
                : 'minecraft:brown_mushroom',
            $flowerPatch > 8_000 && $roll < 4 => 'minecraft:dandelion',
            $flowerPatch > 4_000 && $roll < 8 => 'minecraft:poppy',
            $patch > -10_000 && $roll < 44 => 'minecraft:short_grass',
            default => null,
        };
        if ($feature === null) {
            return;
        }
        if (!$this->isGroundFeatureSubstrate($feature, $builder->state($localX, $ground, $localZ))) {
            return;
        }
        $height = $feature === 'minecraft:cactus' || $feature === 'minecraft:bamboo'
            ? 2 + $this->noise->chance($x, 0, $z, 8_503, 3)
            : 1;
        for ($offset = 1; $offset <= $height; ++$offset) {
            if (!$this->isVegetationReplaceable($builder->state($localX, $ground + $offset, $localZ))) {
                return;
            }
        }
        for ($offset = 1; $offset <= $height; ++$offset) {
            $builder->set($localX, $ground + $offset, $localZ, $this->blocks->state($feature));
        }
    }

    /** @param list<int> $surfaceHeights */
    private function placeAquaticFeature(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $surfaceHeights,
        int $x,
        int $z,
        string $biome,
        OverworldTerrainSample $sample,
    ): void {
        $localX = $x - $position->x * 16;
        $localZ = $z - $position->z * 16;
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16) {
            return;
        }
        $floor = $this->terrainSurfaceHeight($surfaceHeights, $position, $x, $z);
        if ($floor >= self::SEA_LEVEL - 1
            || !$this->isAquaticSubstrate($builder->state($localX, $floor, $localZ))
            || $builder->state($localX, $floor + 1, $localZ)->value !== $this->blocks->state('minecraft:water')->value) {
            return;
        }
        if (str_contains($biome, 'frozen')) {
            return;
        }

        // Direct coordinate-hash modulo produces long diagonal bands when viewed
        // across an open ocean. A broad habitat field combined with fine coherent
        // breakup creates irregular beds while retaining deterministic placement.
        $habitat = intdiv($sample->climate->humidity + $sample->climate->detail, 2);
        $density = 10 + intdiv(max(0, $habitat + 12_000) * 28, 44_768);
        $roll = $this->noise->scatteredChance($x, 0, $z, 8_663, 128);
        if ($roll >= $density) {
            return;
        }
        if (str_contains($biome, 'warm_ocean')
            && $this->noise->scatteredChance($x, 0, $z, 8_701, 5) < 2) {
            $corals = [
                'minecraft:tube_coral_block', 'minecraft:brain_coral_block', 'minecraft:bubble_coral_block',
                'minecraft:fire_coral_block', 'minecraft:horn_coral_block',
            ];
            $coralIndex = $this->noise->scatteredChance($x, 0, $z, 8_719, count($corals));
            $builder->set($localX, $floor, $localZ, $this->blocks->state($corals[$coralIndex]));

            return;
        }
        $featureRoll = $this->noise->scatteredChance($x, 0, $z, 8_737, 10);
        $feature = $featureRoll < 3 ? 'minecraft:kelp' : 'minecraft:seagrass';
        $height = $feature === 'minecraft:kelp'
            ? 2 + $this->noise->scatteredChance($x, 0, $z, 8_741, 5)
            : 1;
        for ($offset = 1; $offset <= $height && $floor + $offset < self::SEA_LEVEL; ++$offset) {
            if ($builder->state($localX, $floor + $offset, $localZ)->value !== $this->blocks->state('minecraft:water')->value) {
                break;
            }
            $builder->set($localX, $floor + $offset, $localZ, $this->blocks->state($feature));
        }
    }

    /** @param list<int> $surfaceHeights */
    private function terrainSurfaceHeight(
        array $surfaceHeights,
        ChunkPosition $position,
        int $worldX,
        int $worldZ,
    ): int {
        $localX = $worldX - $position->x * 16;
        $localZ = $worldZ - $position->z * 16;
        if ($localX >= 0 && $localX < 16 && $localZ >= 0 && $localZ < 16) {
            return $surfaceHeights[$localX + $localZ * 16];
        }

        return $this->densitySurfaceHeightAt($worldX, $worldZ);
    }

    private function densitySurfaceHeightAt(int $worldX, int $worldZ): int
    {
        $key = self::coordinateKey($worldX, $worldZ);
        $cached = $this->volumeSurfaceHeightCache[$key] ?? null;
        if (is_int($cached)) {
            return $cached;
        }
        $rawHeight = $this->rawDensitySurfaceAt($worldX, $worldZ);
        $climate = $this->interpolatedClimateAt($worldX, $worldZ);
        $height = $this->landforms->surfaceHeight(
            $worldX,
            $worldZ,
            $rawHeight,
            $climate,
            $this->terrain->riverStrength($climate),
        );
        $this->rememberVolumeSurfaceHeight($worldX, $worldZ, $height);

        return $height;
    }

    private function rawDensitySurfaceAt(int $worldX, int $worldZ): int
    {
        $key = self::coordinateKey($worldX, $worldZ);
        $cached = $this->rawDensitySurfaceCache[$key] ?? null;
        if (is_int($cached)) {
            return $cached;
        }
        $chunkX = self::floorDiv($worldX, 16);
        $chunkZ = self::floorDiv($worldZ, 16);
        [$minimumY, $maximumY] = $this->surfaceLatticeBoundsForChunk($chunkX, $chunkZ);
        $height = $this->density->interpolatedSurfaceHeight(
            $worldX,
            $worldZ,
            $chunkX * 16,
            $chunkZ * 16,
            $minimumY,
            $maximumY,
            16,
            8,
        );
        $this->rememberRawDensitySurface($worldX, $worldZ, $height);

        return $height;
    }

    private function rememberRawDensitySurface(int $worldX, int $worldZ, int $height): void
    {
        $key = self::coordinateKey($worldX, $worldZ);
        if (isset($this->rawDensitySurfaceCache[$key])) {
            return;
        }
        $this->rawDensitySurfaceCache[$key] = $height;
        $this->rawDensitySurfaceCacheOrder[] = $key;
        if (count($this->rawDensitySurfaceCacheOrder) > 8_192) {
            foreach (array_splice($this->rawDensitySurfaceCacheOrder, 0, 1_024) as $expired) {
                unset($this->rawDensitySurfaceCache[$expired]);
            }
        }
    }

    private function volumeSurfaceLatticeForChunk(int $chunkX, int $chunkZ): OverworldDensityLattice
    {
        $key = $chunkX . ':' . $chunkZ;
        $cached = $this->volumeSurfaceLatticeCache[$key] ?? null;
        if ($cached instanceof OverworldDensityLattice) {
            return $cached;
        }
        $originX = $chunkX * 16;
        $originZ = $chunkZ * 16;
        [$minimumY, $maximumY] = $this->surfaceLatticeBoundsForChunk($chunkX, $chunkZ);
        $lattice = new OverworldDensityLattice(
            $this->density,
            null,
            $originX,
            $originZ,
            $minimumY,
            $maximumY,
            16,
            8,
        );
        $this->volumeSurfaceLatticeCache[$key] = $lattice;
        $this->volumeSurfaceLatticeCacheOrder[] = $key;
        if (count($this->volumeSurfaceLatticeCacheOrder) > 32) {
            foreach (array_splice($this->volumeSurfaceLatticeCacheOrder, 0, 4) as $expired) {
                unset($this->volumeSurfaceLatticeCache[$expired]);
            }
        }

        return $lattice;
    }

    /** @return array{int, int} */
    private function surfaceLatticeBoundsForChunk(int $chunkX, int $chunkZ): array
    {
        $key = $chunkX . ':' . $chunkZ;
        $cached = $this->surfaceLatticeBoundsCache[$key] ?? null;
        if (is_array($cached)) {
            return $cached;
        }
        $originX = $chunkX * 16;
        $originZ = $chunkZ * 16;
        $preliminaryMinimum = Chunk::MAX_Y;
        $preliminaryMaximum = Chunk::MIN_Y;
        for ($z = $originZ; $z <= $originZ + 16; $z += 8) {
            for ($x = $originX; $x <= $originX + 16; $x += 8) {
                $surface = $this->planningHeightAt($x, $z);
                $preliminaryMinimum = min($preliminaryMinimum, $surface);
                $preliminaryMaximum = max($preliminaryMaximum, $surface);
            }
        }
        $minimumY = max(Chunk::MIN_Y, self::floorDiv($preliminaryMinimum - 32, 8) * 8);
        $maximumY = min(192, self::floorDiv($preliminaryMaximum + 39, 8) * 8);

        $bounds = [$minimumY, $maximumY];
        $this->surfaceLatticeBoundsCache[$key] = $bounds;
        $this->surfaceLatticeBoundsCacheOrder[] = $key;
        if (count($this->surfaceLatticeBoundsCacheOrder) > 128) {
            foreach (array_splice($this->surfaceLatticeBoundsCacheOrder, 0, 16) as $expired) {
                unset($this->surfaceLatticeBoundsCache[$expired]);
            }
        }

        return $bounds;
    }

    /** @return non-empty-list<int> */
    private function rawSurfaceHeightsForChunk(
        int $chunkX,
        int $chunkZ,
        ?OverworldDensityLattice $lattice = null,
    ): array {
        $key = $chunkX . ':' . $chunkZ;
        $cached = $this->rawSurfaceChunkCache[$key] ?? null;
        if (is_array($cached)) {
            return $cached;
        }
        $lattice ??= $this->volumeSurfaceLatticeForChunk($chunkX, $chunkZ);
        $heights = $lattice->surfaceHeightsForChunk();
        $this->rawSurfaceChunkCache[$key] = $heights;
        $this->rawSurfaceChunkCacheOrder[] = $key;
        if (count($this->rawSurfaceChunkCacheOrder) > 32) {
            foreach (array_splice($this->rawSurfaceChunkCacheOrder, 0, 4) as $expired) {
                unset($this->rawSurfaceChunkCache[$expired]);
            }
        }

        return $heights;
    }

    private function rememberVolumeSurfaceHeight(int $worldX, int $worldZ, int $height): void
    {
        $key = self::coordinateKey($worldX, $worldZ);
        if (isset($this->volumeSurfaceHeightCache[$key])) {
            return;
        }
        $this->volumeSurfaceHeightCache[$key] = $height;
        $this->volumeSurfaceHeightCacheOrder[] = $key;
        if (count($this->volumeSurfaceHeightCacheOrder) > 8_192) {
            foreach (array_splice($this->volumeSurfaceHeightCacheOrder, 0, 1_024) as $expired) {
                unset($this->volumeSurfaceHeightCache[$expired]);
            }
        }

    }

    private function isTreeSubstrate(InternalBlockStateId $state): bool
    {
        return isset($this->treeSubstrates[$state->value]);
    }

    private function isGroundFeatureSubstrate(string $feature, InternalBlockStateId $state): bool
    {
        if ($feature === 'minecraft:cactus') {
            return $this->stateIsAny($state, ['minecraft:sand', 'minecraft:red_sand']);
        }
        if ($feature === 'minecraft:deadbush') {
            return $this->stateIsAny($state, [
                'minecraft:sand', 'minecraft:red_sand', 'minecraft:coarse_dirt',
                'minecraft:white_terracotta', 'minecraft:orange_terracotta', 'minecraft:yellow_terracotta',
                'minecraft:brown_terracotta', 'minecraft:red_terracotta',
            ]);
        }
        if ($feature === 'minecraft:red_mushroom' || $feature === 'minecraft:brown_mushroom') {
            return $this->stateIsAny($state, ['minecraft:mycelium', 'minecraft:podzol', 'minecraft:pale_moss_block']);
        }

        return $this->isTreeSubstrate($state);
    }

    private function isAquaticSubstrate(InternalBlockStateId $state): bool
    {
        return isset($this->aquaticSubstrates[$state->value]);
    }

    private function isVegetationReplaceable(InternalBlockStateId $state): bool
    {
        return isset($this->vegetationReplaceable[$state->value]);
    }

    /** @param list<string> $identifiers */
    private function stateIsAny(InternalBlockStateId $state, array $identifiers): bool
    {
        foreach ($identifiers as $identifier) {
            if ($state->value === $this->blocks->state($identifier)->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $identifiers
     * @return array<int, true>
     */
    private function stateSet(array $identifiers): array
    {
        $result = [];
        foreach ($identifiers as $identifier) {
            $result[$this->blocks->state($identifier)->value] = true;
        }

        return $result;
    }

    /**
     * @param array<int, array<int, OverworldTerrainSample>> $samples
     * @param list<int> $surfaceHeights
     */
    private function populateStructures(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $samples,
        array $surfaceHeights,
    ): void {
        $this->structures->populate(
            $builder,
            $position,
            fn(int $x, int $z): string => $this->biomeAt($x, $z)->identifier,
            fn(int $x, int $z): int => $this->densitySurfaceHeightAt($x, $z),
        );
    }

    /** @return array<int, array<int, OverworldTerrainSample>> */
    private function terrainRegion(ChunkPosition $position, int $margin): array
    {
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        $heights = [];
        $minimumX = $originX - $margin - 2;
        $maximumX = $originX + 15 + $margin + 2;
        $minimumZ = $originZ - $margin - 2;
        $maximumZ = $originZ + 15 + $margin + 2;
        // Quart-resolution climate sampling keeps transitions smooth without
        // imprinting chunk-sized rectangular terraces on biome and surface rules.
        $gridStep = self::CLIMATE_SAMPLE_STEP;
        $gridMinimumX = self::floorDiv($minimumX, $gridStep) * $gridStep;
        $gridMaximumX = self::floorDiv($maximumX, $gridStep) * $gridStep + $gridStep;
        $gridMinimumZ = self::floorDiv($minimumZ, $gridStep) * $gridStep;
        $gridMaximumZ = self::floorDiv($maximumZ, $gridStep) * $gridStep + $gridStep;
        $coarse = [];
        $coarseHeights = [];
        for ($z = $gridMinimumZ; $z <= $gridMaximumZ; $z += $gridStep) {
            for ($x = $gridMinimumX; $x <= $gridMaximumX; $x += $gridStep) {
                $climate = $this->climateAt($x, $z);
                $coarse[$z][$x] = $climate;
                $coarseHeights[$z][$x] = $this->terrain->surfaceHeight($climate);
            }
        }
        $coastalStep = self::COASTAL_SAMPLE_STEP;
        $coastalMinimumX = self::floorDiv($minimumX, $coastalStep) * $coastalStep;
        $coastalMaximumX = self::floorDiv($maximumX, $coastalStep) * $coastalStep + $coastalStep;
        $coastalMinimumZ = self::floorDiv($minimumZ, $coastalStep) * $coastalStep;
        $coastalMaximumZ = self::floorDiv($maximumZ, $coastalStep) * $coastalStep + $coastalStep;
        $coastalSignals = [];
        for ($z = $coastalMinimumZ; $z <= $coastalMaximumZ; $z += $coastalStep) {
            for ($x = $coastalMinimumX; $x <= $coastalMaximumX; $x += $coastalStep) {
                $coastalSignals[$z][$x] = $this->coastalSignalsAt($x, $z);
            }
        }
        for ($z = $minimumZ; $z <= $maximumZ; ++$z) {
            for ($x = $minimumX; $x <= $maximumX; ++$x) {
                $leftX = self::floorDiv($x, $gridStep) * $gridStep;
                $topZ = self::floorDiv($z, $gridStep) * $gridStep;
                $baseHeight = self::bilinear(
                    $coarseHeights[$topZ][$leftX],
                    $coarseHeights[$topZ][$leftX + $gridStep],
                    $coarseHeights[$topZ + $gridStep][$leftX],
                    $coarseHeights[$topZ + $gridStep][$leftX + $gridStep],
                    $x - $leftX,
                    $z - $topZ,
                    $gridStep,
                );
                $coastalLeftX = self::floorDiv($x, $coastalStep) * $coastalStep;
                $coastalTopZ = self::floorDiv($z, $coastalStep) * $coastalStep;
                [$landNorthWest, $floorNorthWest] = $coastalSignals[$coastalTopZ][$coastalLeftX];
                [$landNorthEast, $floorNorthEast] = $coastalSignals[$coastalTopZ][$coastalLeftX + $coastalStep];
                [$landSouthWest, $floorSouthWest] = $coastalSignals[$coastalTopZ + $coastalStep][$coastalLeftX];
                [$landSouthEast, $floorSouthEast] = $coastalSignals[$coastalTopZ + $coastalStep][$coastalLeftX + $coastalStep];
                $landSignal = self::bilinear(
                    $landNorthWest,
                    $landNorthEast,
                    $landSouthWest,
                    $landSouthEast,
                    $x - $coastalLeftX,
                    $z - $coastalTopZ,
                    $coastalStep,
                );
                $floorSignal = self::bilinear(
                    $floorNorthWest,
                    $floorNorthEast,
                    $floorSouthWest,
                    $floorSouthEast,
                    $x - $coastalLeftX,
                    $z - $coastalTopZ,
                    $coastalStep,
                );
                $heights[$z][$x] = $this->terrain->refineCoastalHeight(
                    $baseHeight,
                    $landSignal,
                    $floorSignal,
                    $baseHeight < self::SEA_LEVEL
                        ? $this->terrain->coastalFloorMicroOffset($x, $z)
                        : 0,
                );
            }
        }
        $samples = [];
        for ($z = $originZ - $margin; $z <= $originZ + 15 + $margin; ++$z) {
            for ($x = $originX - $margin; $x <= $originX + 15 + $margin; ++$x) {
                $leftX = self::floorDiv($x, $gridStep) * $gridStep;
                $topZ = self::floorDiv($z, $gridStep) * $gridStep;
                $climate = self::interpolateClimate(
                    $coarse[$topZ][$leftX],
                    $coarse[$topZ][$leftX + $gridStep],
                    $coarse[$topZ + $gridStep][$leftX],
                    $coarse[$topZ + $gridStep][$leftX + $gridStep],
                    $x - $leftX,
                    $z - $topZ,
                    $gridStep,
                );
                $height = $heights[$z][$x];
                $samples[$z][$x] = new OverworldTerrainSample(
                    $climate,
                    $height,
                    intdiv(max(
                        abs($height - $heights[$z][$x + 2]),
                        abs($height - $heights[$z][$x - 2]),
                        abs($height - $heights[$z + 2][$x]),
                        abs($height - $heights[$z - 2][$x]),
                    ) + 1, 2),
                    $this->terrain->riverStrength($climate),
                    max(-64, min(64, intdiv(
                        $heights[$z][$x + 2]
                            + $heights[$z][$x - 2]
                            + $heights[$z + 2][$x]
                            + $heights[$z - 2][$x]
                            - 4 * $height,
                        4,
                    ))),
                );
            }
        }

        return $samples;
    }

    private static function interpolateClimate(
        OverworldClimate $northWest,
        OverworldClimate $northEast,
        OverworldClimate $southWest,
        OverworldClimate $southEast,
        int $offsetX,
        int $offsetZ,
        int $step = 4,
    ): OverworldClimate {
        return new OverworldClimate(
            self::bilinear($northWest->continentalness, $northEast->continentalness, $southWest->continentalness, $southEast->continentalness, $offsetX, $offsetZ, $step),
            self::bilinear($northWest->erosion, $northEast->erosion, $southWest->erosion, $southEast->erosion, $offsetX, $offsetZ, $step),
            self::bilinear($northWest->temperature, $northEast->temperature, $southWest->temperature, $southEast->temperature, $offsetX, $offsetZ, $step),
            self::bilinear($northWest->humidity, $northEast->humidity, $southWest->humidity, $southEast->humidity, $offsetX, $offsetZ, $step),
            self::bilinear($northWest->ridge, $northEast->ridge, $southWest->ridge, $southEast->ridge, $offsetX, $offsetZ, $step),
            self::bilinear($northWest->uplift, $northEast->uplift, $southWest->uplift, $southEast->uplift, $offsetX, $offsetZ, $step),
            self::bilinear($northWest->river, $northEast->river, $southWest->river, $southEast->river, $offsetX, $offsetZ, $step),
            self::bilinear($northWest->detail, $northEast->detail, $southWest->detail, $southEast->detail, $offsetX, $offsetZ, $step),
        );
    }

    private static function bilinear(
        int $northWest,
        int $northEast,
        int $southWest,
        int $southEast,
        int $x,
        int $z,
        int $step = 4,
    ): int {
        $north = $northWest + intdiv(($northEast - $northWest) * $x, $step);
        $south = $southWest + intdiv(($southEast - $southWest) * $x, $step);

        return $north + intdiv(($south - $north) * $z, $step);
    }

    private function climateAt(int $x, int $z): OverworldClimate
    {
        $key = self::coordinateKey($x, $z);
        $cached = $this->columnCache[$key] ?? null;
        if ($cached instanceof OverworldClimate) {
            return $cached;
        }
        $climate = $this->terrain->climateAt($x, $z);
        $this->columnCache[$key] = $climate;
        $this->columnCacheOrder[] = $key;
        if (count($this->columnCacheOrder) > 32_768) {
            foreach (array_splice($this->columnCacheOrder, 0, 4_096) as $expired) {
                unset($this->columnCache[$expired]);
            }
        }

        return $climate;
    }

    private function interpolatedClimateAt(int $x, int $z): OverworldClimate
    {
        $step = self::CLIMATE_SAMPLE_STEP;
        $leftX = self::floorDiv($x, $step) * $step;
        $topZ = self::floorDiv($z, $step) * $step;

        return self::interpolateClimate(
            $this->climateAt($leftX, $topZ),
            $this->climateAt($leftX + $step, $topZ),
            $this->climateAt($leftX, $topZ + $step),
            $this->climateAt($leftX + $step, $topZ + $step),
            $x - $leftX,
            $z - $topZ,
            $step,
        );
    }

    private function regionalSample(int $x, int $z): OverworldTerrainSample
    {
        $key = self::coordinateKey($x, $z);
        $cached = $this->regionalSampleCache[$key] ?? null;
        if ($cached instanceof OverworldTerrainSample) {
            return $cached;
        }
        $climate = $this->interpolatedClimateAt($x, $z);
        $height = $this->regionalHeight($x, $z);

        $east = $this->regionalHeight($x + 2, $z);
        $west = $this->regionalHeight($x - 2, $z);
        $south = $this->regionalHeight($x, $z + 2);
        $north = $this->regionalHeight($x, $z - 2);

        $sample = new OverworldTerrainSample(
            $climate,
            $height,
            intdiv(max(
                abs($height - $east),
                abs($height - $west),
                abs($height - $south),
                abs($height - $north),
            ) + 1, 2),
            $this->terrain->riverStrength($climate),
            max(-64, min(64, intdiv($east + $west + $south + $north - 4 * $height, 4))),
        );
        $this->regionalSampleCache[$key] = $sample;
        $this->regionalSampleCacheOrder[] = $key;
        if (count($this->regionalSampleCacheOrder) > 32_768) {
            foreach (array_splice($this->regionalSampleCacheOrder, 0, 4_096) as $expired) {
                unset($this->regionalSampleCache[$expired]);
            }
        }

        return $sample;
    }

    private function planningBiomeAt(int $x, int $z): Biome
    {
        $climate = $this->interpolatedClimateAt($x, $z);
        $shapeHeight = $this->planningHeightAt($x, $z);
        $east = $this->planningHeightAt($x + 2, $z);
        $west = $this->planningHeightAt($x - 2, $z);
        $south = $this->planningHeightAt($x, $z + 2);
        $north = $this->planningHeightAt($x, $z - 2);

        $sample = new OverworldTerrainSample(
            $climate,
            $shapeHeight,
            intdiv(max(
                abs($shapeHeight - $east),
                abs($shapeHeight - $west),
                abs($shapeHeight - $south),
                abs($shapeHeight - $north),
            ) + 1, 2),
            $this->terrain->riverStrength($climate),
            max(-64, min(64, intdiv($east + $west + $south + $north - 4 * $shapeHeight, 4))),
        );
        $resolved = $this->biomes->resolve($sample);
        if (!$this->isBeachBiome($resolved)) {
            return $resolved;
        }

        return $this->isNaturalShoreline(
            $x,
            $z,
            $sample,
            fn(int $sampleX, int $sampleZ): int => $this->planningHeightAt($sampleX, $sampleZ),
        ) ? $resolved : $this->biomes->resolveDryLand($sample);
    }

    private function planningHeightAt(int $x, int $z): int
    {
        $key = self::coordinateKey($x, $z);
        $cached = $this->planningHeightCache[$key] ?? null;
        if (is_int($cached)) {
            return $cached;
        }
        $climate = $this->climateAt($x, $z);
        $height = $this->landforms->surfaceHeight(
            $x,
            $z,
            $this->terrain->surfaceHeight($climate),
            $climate,
            $this->terrain->riverStrength($climate),
        );
        $this->planningHeightCache[$key] = $height;
        $this->planningHeightCacheOrder[] = $key;
        if (count($this->planningHeightCacheOrder) > 32_768) {
            foreach (array_splice($this->planningHeightCacheOrder, 0, 4_096) as $expired) {
                unset($this->planningHeightCache[$expired]);
            }
        }

        return $height;
    }

    private function regionalHeight(int $x, int $z): int
    {
        return $this->densitySurfaceHeightAt($x, $z);
    }

    /** @return array{int, int} */
    private function coastalSignalsAt(int $x, int $z): array
    {
        $key = self::coordinateKey($x, $z);
        $cached = $this->coastalSignalCache[$key] ?? null;
        if (is_array($cached)) {
            return $cached;
        }
        $signals = [
            $this->terrain->coastalLandSignal($x, $z),
            $this->terrain->coastalFloorSignal($x, $z),
        ];
        $this->coastalSignalCache[$key] = $signals;
        $this->coastalSignalCacheOrder[] = $key;
        if (count($this->coastalSignalCacheOrder) > 8_192) {
            foreach (array_splice($this->coastalSignalCacheOrder, 0, 1_024) as $expired) {
                unset($this->coastalSignalCache[$expired]);
            }
        }

        return $signals;
    }

    private static function coordinateKey(int $x, int $z): string
    {
        return $x . ':' . $z;
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
