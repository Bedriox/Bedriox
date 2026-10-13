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

use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\Geology\OverworldGeologyGenerator;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\OverworldBiomeResolver;
use Bedriox\Server\World\Generation\OverworldCaveBiomeResolver;
use Bedriox\Server\World\Generation\OverworldClimate;
use Bedriox\Server\World\Generation\OverworldTerrainSample;
use Bedriox\Server\World\Generation\OverworldTerrainSampler;
use Bedriox\Server\World\Generation\SeededNoise;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureFamily;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureGenerator;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureLocation;

/** Deterministic three-dimensional overworld generator for the built-in default world profile. */
final class DefaultWorldGenerator implements VersionedWorldGenerator
{
    public const int VERSION = 4;
    public const int SEA_LEVEL = OverworldTerrainSampler::SEA_LEVEL;
    private const int SPAWN_SEARCH_STEP = 64;
    private const int MAXIMUM_BEACH_WIDTH = 6;
    private const int TERRAIN_SAMPLE_MARGIN = 3;
    private const int COASTAL_SAMPLE_STEP = 4;
    private readonly GenerationBlockPalette $blocks;
    private readonly SeededNoise $noise;
    private readonly OverworldTerrainSampler $terrain;
    private readonly OverworldBiomeResolver $biomes;
    private readonly OverworldCaveBiomeResolver $caveBiomes;
    private readonly OverworldGeologyGenerator $geology;
    private readonly OverworldStructureGenerator $structures;
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
    /** @var array<string, int> */
    private array $surfaceHeightCache = [];
    /** @var list<string> */
    private array $surfaceHeightCacheOrder = [];
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
        $this->biomes = new OverworldBiomeResolver();
        $this->caveBiomes = new OverworldCaveBiomeResolver($this->noise);
        $this->geology = new OverworldGeologyGenerator($seed, $this->blocks);
        $this->structures = new OverworldStructureGenerator($this->blocks, $this->noise);
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

        for ($localZ = 0; $localZ < 16; ++$localZ) {
            for ($localX = 0; $localX < 16; ++$localX) {
                $sample = $samples[$originZ + $localZ][$originX + $localX];
                $terrainHeights[$localX + $localZ * 16] = $sample->surfaceHeight;
            }
        }
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
            $biome = $this->biomes->resolve($sample)->identifier;
            if ($sample->surfaceHeight <= self::SEA_LEVEL + 1 || $sample->slope > 5
                || !in_array($biome, [
                    'minecraft:plains', 'minecraft:sunflower_plains', 'minecraft:forest',
                    'minecraft:birch_forest', 'minecraft:taiga', 'minecraft:savanna',
                    'minecraft:meadow', 'minecraft:cherry_grove',
                ], true)) {
                continue;
            }
            return $this->candidateSpawn = new SpawnPosition(
                $x,
                $sample->surfaceHeight + 1,
                $z,
            );
        }

        return $this->candidateSpawn = new SpawnPosition(0, $this->densitySurfaceHeightAt(0, 0) + 1, 0);
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
        return $this->densitySurfaceHeight($x, $z);
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
     * Carves deterministic, continuous cave tunnels from neighboring source chunks.
     * Feature-based carving avoids evaluating multiple 3D noise stacks at every block.
     *
     * @param array<int, int> $surfaceHeights
     * @return array<int, array{int, int}> Local column index to inclusive carved Y range.
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
        if (str_contains($id, 'mesa')) {
            $colors = ['minecraft:orange_terracotta', 'minecraft:red_terracotta', 'minecraft:yellow_terracotta'];
            $state = $this->blocks->state($colors[abs(intdiv($sample->climate->detail, 3_000)) % count($colors)]);

            return [$id === 'minecraft:mesa_bryce' ? $this->blocks->state('minecraft:red_sand') : $state, $state, 7, false];
        }
        if (str_contains($id, 'desert')) {
            return [$this->blocks->state('minecraft:sand'), $this->blocks->state('minecraft:sandstone'), 5, false];
        }
        if (in_array($id, ['minecraft:beach', 'minecraft:cold_beach'], true)) {
            $patch = $sample->climate->detail + intdiv($sample->climate->erosion, 2);
            $gravel = $id === 'minecraft:cold_beach' && $patch < 2_500;
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
            return [$this->blocks->state('minecraft:stone'), $this->blocks->state('minecraft:stone'), 2, str_contains($id, 'frozen') || str_contains($id, 'jagged')];
        }
        if (str_contains($id, 'snow') || str_contains($id, 'ice_') || str_contains($id, 'grove') || str_contains($id, 'cold_taiga')) {
            return [$this->blocks->state('minecraft:grass_block'), $this->blocks->state('minecraft:dirt'), $depth, true];
        }
        if (str_contains($id, 'taiga')) {
            $top = $this->noise->chance($x, 0, $z, 5_401, 4) === 0 ? 'minecraft:coarse_dirt' : 'minecraft:podzol';

            return [$this->blocks->state($top), $this->blocks->state('minecraft:dirt'), $depth, false];
        }
        if ($id === 'minecraft:mangrove_swamp') {
            return [$this->blocks->state('minecraft:mud'), $this->blocks->state('minecraft:dirt'), $depth + 1, false];
        }
        if ($id === 'minecraft:pale_garden') {
            return [$this->blocks->state('minecraft:pale_moss_block'), $this->blocks->state('minecraft:dirt'), $depth, false];
        }
        if (str_contains($id, 'mushroom_island')) {
            return [$this->blocks->state('minecraft:mycelium'), $this->blocks->state('minecraft:dirt'), $depth, false];
        }

        return [$this->blocks->state('minecraft:grass_block'), $this->blocks->state('minecraft:dirt'), $depth, false];
    }

    /**
     * @return array{\Bedriox\Server\World\Block\InternalBlockStateId, \Bedriox\Server\World\Block\InternalBlockStateId, int, false}
     */
    private function submergedSurfaceRule(OverworldTerrainSample $sample, int $x, int $z): array
    {
        $patch = $sample->climate->detail + intdiv($sample->climate->humidity, 3);
        $identifier = match (true) {
            $sample->slope >= 7 => 'minecraft:gravel',
            $sample->climate->humidity > 9_000 && $patch < -5_000 => 'minecraft:clay',
            $patch > 12_000 => 'minecraft:gravel',
            default => 'minecraft:sand',
        };
        $state = $this->blocks->state($identifier);

        return [$state, $state, 2 + $this->noise->chance($x, 0, $z, 5_311, 3), false];
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
                : $this->regionalHeight($sampleX, $sampleZ),
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
        // The widest canopy radius is three blocks, so only sources in this exact halo
        // can affect the target chunk.
        for ($worldZ = $originZ - 3; $worldZ <= $originZ + 18; ++$worldZ) {
            for ($worldX = $originX - 3; $worldX <= $originX + 18; ++$worldX) {
                if ($this->candidateSpawn !== null
                    && abs($worldX - $this->candidateSpawn->x) <= 4
                    && abs($worldZ - $this->candidateSpawn->z) <= 4) {
                    continue;
                }
                $localX = $worldX - $originX;
                $localZ = $worldZ - $originZ;
                $inside = $localX >= 0 && $localX < 16 && $localZ >= 0 && $localZ < 16;
                $sample = $samples[$worldZ][$worldX] ?? $this->terrain->sample($worldX, $worldZ);
                $biome = $inside
                    ? $surfaceBiomes[$localX + $localZ * 16]->identifier
                    : $this->biomes->resolve($sample)->identifier;
                if (str_contains($biome, 'ocean')) {
                    if (!$inside) {
                        continue;
                    }
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
                [$frequency, $log, $leaves] = $this->treeRule($biome);
                if ($frequency > 0 && $this->noise->chance($worldX, 0, $worldZ, 8_101, $frequency) === 0) {
                    $this->placeTree(
                        $builder,
                        $position,
                        $surfaceHeights,
                        $worldX,
                        $worldZ,
                        $biome,
                        $log,
                        $leaves,
                    );
                    continue;
                }
                if (!$inside) {
                    continue;
                }
                $this->placeGroundFeature(
                    $builder,
                    $position,
                    $surfaceHeights,
                    $worldX,
                    $worldZ,
                    $biome,
                );
            }
        }
    }

    /** @return array{int, string, string} */
    private function treeRule(string $biome): array
    {
        return match (true) {
            str_contains($biome, 'bamboo_jungle') => [12, 'minecraft:jungle_log', 'minecraft:jungle_leaves'],
            str_contains($biome, 'jungle') => [17, 'minecraft:jungle_log', 'minecraft:jungle_leaves'],
            str_contains($biome, 'birch') => [20, 'minecraft:birch_log', 'minecraft:birch_leaves'],
            str_contains($biome, 'taiga'), str_contains($biome, 'grove') => [18, 'minecraft:spruce_log', 'minecraft:spruce_leaves'],
            str_contains($biome, 'savanna') => [38, 'minecraft:acacia_log', 'minecraft:acacia_leaves'],
            str_contains($biome, 'roofed') => [14, 'minecraft:dark_oak_log', 'minecraft:dark_oak_leaves'],
            str_contains($biome, 'cherry') => [16, 'minecraft:cherry_log', 'minecraft:cherry_leaves'],
            str_contains($biome, 'mangrove') => [14, 'minecraft:mangrove_log', 'minecraft:mangrove_leaves'],
            str_contains($biome, 'pale_garden') => [13, 'minecraft:pale_oak_log', 'minecraft:pale_oak_leaves'],
            str_contains($biome, 'forest') => [20, 'minecraft:oak_log', 'minecraft:oak_leaves'],
            $biome === 'minecraft:plains' => [150, 'minecraft:oak_log', 'minecraft:oak_leaves'],
            default => [0, 'minecraft:oak_log', 'minecraft:oak_leaves'],
        };
    }

    /** @param list<int> $surfaceHeights */
    private function placeTree(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $surfaceHeights,
        int $x,
        int $z,
        string $biome,
        string $log,
        string $leaves,
    ): void {
        $localX = $x - $position->x * 16;
        $localZ = $z - $position->z * 16;
        $ground = $this->terrainSurfaceHeight($surfaceHeights, $position, $x, $z);
        if ($ground <= self::SEA_LEVEL || $ground > 230) {
            return;
        }
        $height = 5 + $this->noise->chance($x, 0, $z, 8_211, str_contains($biome, 'jungle') ? 8 : 4);
        if (str_contains($biome, 'savanna')) {
            $height += 2;
        }
        if ($localX >= 0 && $localX < 16 && $localZ >= 0 && $localZ < 16
            && !$this->isTreeSubstrate($builder->state($localX, $ground, $localZ))) {
            return;
        }
        for ($y = $ground + 1; $y <= $ground + $height; ++$y) {
            if (!$this->isVegetationReplaceable($this->worldState($builder, $position, $x, $y, $z))) {
                return;
            }
        }
        $logState = $this->blocks->state($log);
        $leafState = $this->blocks->state($leaves);
        if ($localX >= 0 && $localX < 16 && $localZ >= 0 && $localZ < 16) {
            for ($y = $ground + 1; $y <= $ground + $height; ++$y) {
                $builder->set($localX, $y, $localZ, $logState);
            }
        }
        $chunkMinimumX = $position->x * 16;
        $chunkMinimumZ = $position->z * 16;
        $chunkMaximumX = $chunkMinimumX + 15;
        $chunkMaximumZ = $chunkMinimumZ + 15;
        $top = $ground + $height;
        for ($y = $top - 3; $y <= $top + 1; ++$y) {
            $radius = $y >= $top ? 1 : (str_contains($biome, 'taiga') || str_contains($biome, 'grove')
                ? max(1, 3 - intdiv($y - ($top - 3), 2))
                : 2);
            for ($leafZ = max($z - $radius, $chunkMinimumZ); $leafZ <= min($z + $radius, $chunkMaximumZ); ++$leafZ) {
                for ($leafX = max($x - $radius, $chunkMinimumX); $leafX <= min($x + $radius, $chunkMaximumX); ++$leafX) {
                    if (abs($leafX - $x) === $radius && abs($leafZ - $z) === $radius
                        && $this->noise->chance($leafX, $y, $leafZ, 8_307, 3) === 0) {
                        continue;
                    }
                    $builder->setWorld($leafX, $y, $leafZ, $leafState, true);
                }
            }
        }
    }

    /** @param list<int> $surfaceHeights */
    private function placeGroundFeature(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $surfaceHeights,
        int $x,
        int $z,
        string $biome,
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
        $roll = $this->noise->chance($x, 0, $z, 8_401, 96);
        $feature = match (true) {
            str_contains($biome, 'desert') && $roll < 3 => 'minecraft:cactus',
            str_contains($biome, 'desert') && $roll < 10 => 'minecraft:deadbush',
            str_contains($biome, 'bamboo') && $roll < 22 => 'minecraft:bamboo',
            str_contains($biome, 'swamp') && $roll < 6 => 'minecraft:blue_orchid',
            str_contains($biome, 'flower') && $roll < 24 => ['minecraft:allium', 'minecraft:azure_bluet', 'minecraft:oxeye_daisy'][$roll % 3],
            str_contains($biome, 'cherry') && $roll < 12 => 'minecraft:pink_tulip',
            str_contains($biome, 'mushroom') && $roll < 24 => $roll % 2 === 0
                ? 'minecraft:red_mushroom'
                : 'minecraft:brown_mushroom',
            $roll < 4 => 'minecraft:dandelion',
            $roll < 8 => 'minecraft:poppy',
            $roll < 34 => 'minecraft:short_grass',
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
        return $this->generatedSurfaceHeightAt($worldX, $worldZ);
    }

    private function worldState(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        int $worldX,
        int $y,
        int $worldZ,
    ): InternalBlockStateId {
        return $builder->state($worldX - $position->x * 16, $y, $worldZ - $position->z * 16);
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
            fn(int $x, int $z): string => $this->biomes->resolve(
                $samples[$z][$x] ?? $this->regionalSample($x, $z),
            )->identifier,
            fn(int $x, int $z): int => $this->terrainSurfaceHeight($surfaceHeights, $position, $x, $z),
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
        // The narrowest surface climate wavelength is 145 blocks. A 16-block lattice
        // remains comfortably above Nyquist while avoiding redundant per-column noise.
        $gridStep = 16;
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
                    max(
                        abs($height - $heights[$z][$x + 2]),
                        abs($height - $heights[$z][$x - 2]),
                        abs($height - $heights[$z + 2][$x]),
                        abs($height - $heights[$z - 2][$x]),
                    ),
                    $this->terrain->riverStrength($climate),
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

    private function regionalSample(int $x, int $z): OverworldTerrainSample
    {
        $step = 16;
        $leftX = self::floorDiv($x, $step) * $step;
        $topZ = self::floorDiv($z, $step) * $step;
        $climate = self::interpolateClimate(
            $this->climateAt($leftX, $topZ),
            $this->climateAt($leftX + $step, $topZ),
            $this->climateAt($leftX, $topZ + $step),
            $this->climateAt($leftX + $step, $topZ + $step),
            $x - $leftX,
            $z - $topZ,
            $step,
        );
        $height = $this->regionalHeight($x, $z);

        return new OverworldTerrainSample(
            $climate,
            $height,
            max(
                abs($height - $this->regionalHeight($x + 2, $z)),
                abs($height - $this->regionalHeight($x - 2, $z)),
                abs($height - $this->regionalHeight($x, $z + 2)),
                abs($height - $this->regionalHeight($x, $z - 2)),
            ),
            $this->terrain->riverStrength($climate),
        );
    }

    private function regionalHeight(int $x, int $z): int
    {
        return $this->generatedSurfaceHeightAt($x, $z);
    }

    private function densitySurfaceHeight(int $x, int $z): int
    {
        $key = self::coordinateKey($x, $z);
        $cached = $this->surfaceHeightCache[$key] ?? null;
        if (is_int($cached)) {
            return $cached;
        }
        $height = $this->generatedSurfaceHeightAt($x, $z);
        $this->rememberSurfaceHeight($x, $z, $height);

        return $height;
    }

    private function generatedSurfaceHeightAt(int $x, int $z): int
    {
        $key = self::coordinateKey($x, $z);
        $cached = $this->surfaceHeightCache[$key] ?? null;
        if (is_int($cached)) {
            return $cached;
        }
        $step = 16;
        $leftX = self::floorDiv($x, $step) * $step;
        $topZ = self::floorDiv($z, $step) * $step;
        $cornerHeight = function (int $cornerX, int $cornerZ): int {
            return $this->terrain->surfaceHeight($this->climateAt($cornerX, $cornerZ));
        };
        $baseHeight = self::bilinear(
            $cornerHeight($leftX, $topZ),
            $cornerHeight($leftX + $step, $topZ),
            $cornerHeight($leftX, $topZ + $step),
            $cornerHeight($leftX + $step, $topZ + $step),
            $x - $leftX,
            $z - $topZ,
            $step,
        );
        [$landSignal, $floorSignal] = $this->interpolatedCoastalSignals($x, $z);
        $height = $this->terrain->refineCoastalHeight(
            $baseHeight,
            $landSignal,
            $floorSignal,
            $baseHeight < self::SEA_LEVEL
                ? $this->terrain->coastalFloorMicroOffset($x, $z)
                : 0,
        );
        $this->rememberSurfaceHeight($x, $z, $height);

        return $height;
    }

    private function rememberSurfaceHeight(int $x, int $z, int $height): void
    {
        $key = self::coordinateKey($x, $z);
        if (isset($this->surfaceHeightCache[$key])) {
            return;
        }
        $this->surfaceHeightCache[$key] = $height;
        $this->surfaceHeightCacheOrder[] = $key;
        if (count($this->surfaceHeightCacheOrder) > 32_768) {
            foreach (array_splice($this->surfaceHeightCacheOrder, 0, 4_096) as $expired) {
                unset($this->surfaceHeightCache[$expired]);
            }
        }
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

    /** @return array{int, int} */
    private function interpolatedCoastalSignals(int $x, int $z): array
    {
        $step = self::COASTAL_SAMPLE_STEP;
        $leftX = self::floorDiv($x, $step) * $step;
        $topZ = self::floorDiv($z, $step) * $step;
        [$landNorthWest, $floorNorthWest] = $this->coastalSignalsAt($leftX, $topZ);
        [$landNorthEast, $floorNorthEast] = $this->coastalSignalsAt($leftX + $step, $topZ);
        [$landSouthWest, $floorSouthWest] = $this->coastalSignalsAt($leftX, $topZ + $step);
        [$landSouthEast, $floorSouthEast] = $this->coastalSignalsAt($leftX + $step, $topZ + $step);

        return [
            self::bilinear($landNorthWest, $landNorthEast, $landSouthWest, $landSouthEast, $x - $leftX, $z - $topZ, $step),
            self::bilinear($floorNorthWest, $floorNorthEast, $floorSouthWest, $floorSouthEast, $x - $leftX, $z - $topZ, $step),
        ];
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
