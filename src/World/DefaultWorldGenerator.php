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
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockEntity\SuspiciousSandBlockEntity;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\OverworldBiomeResolver;
use Bedriox\Server\World\Generation\OverworldCaveBiomeResolver;
use Bedriox\Server\World\Generation\OverworldClimate;
use Bedriox\Server\World\Generation\OverworldDensityGrid;
use Bedriox\Server\World\Generation\OverworldDensitySampler;
use Bedriox\Server\World\Generation\OverworldTerrainSample;
use Bedriox\Server\World\Generation\OverworldTerrainSampler;
use Bedriox\Server\World\Generation\SeededNoise;

/** Deterministic three-dimensional overworld generator for the built-in default world profile. */
final class DefaultWorldGenerator implements VersionedWorldGenerator
{
    public const int VERSION = 3;
    public const int SEA_LEVEL = OverworldTerrainSampler::SEA_LEVEL;
    private const int HORIZONTAL_SAMPLE_STEP = 4;
    private const int VERTICAL_SAMPLE_STEP = 8;

    private readonly GenerationBlockPalette $blocks;
    private readonly SeededNoise $noise;
    private readonly OverworldTerrainSampler $terrain;
    private readonly OverworldDensitySampler $density;
    private readonly OverworldBiomeResolver $biomes;
    private readonly OverworldCaveBiomeResolver $caveBiomes;
    /** @var array<string, OverworldClimate> */
    private array $columnCache = [];
    /** @var list<string> */
    private array $columnCacheOrder = [];
    /** @var array<string, OverworldDensityGrid> */
    private array $densityGridCache = [];
    /** @var list<string> */
    private array $densityGridCacheOrder = [];
    private ?SpawnPosition $candidateSpawn = null;
    private ?SpawnPosition $spawn = null;

    public function __construct(
        private readonly int $seed,
        BlockStateRegistry $states,
    ) {
        $this->blocks = GenerationBlockPalette::fromRegistry($states);
        $this->noise = new SeededNoise($seed);
        $this->terrain = new OverworldTerrainSampler($this->noise);
        $this->density = new OverworldDensitySampler($this->noise, $this->terrain);
        $this->biomes = new OverworldBiomeResolver();
        $this->caveBiomes = new OverworldCaveBiomeResolver($this->noise);
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
        $samples = $this->terrainRegion($position, 4);
        $builder = new MutableChunkBuilder($position, $this->blocks);
        $density = $this->densityGrid($originX, $originZ);

        for ($cellX = 0; $cellX < 4; ++$cellX) {
            for ($cellZ = 0; $cellZ < 4; ++$cellZ) {
                for ($cellY = 0; $cellY < 48; ++$cellY) {
                    $y0 = Chunk::MIN_Y + $cellY * self::VERTICAL_SAMPLE_STEP;
                    $baseCorners = $density->baseCorners($cellX, $cellY, $cellZ);
                    $carvedCorners = $density->carvedCorners($cellX, $cellY, $cellZ);
                    for ($stepX = 0; $stepX < self::HORIZONTAL_SAMPLE_STEP; ++$stepX) {
                        $localX = $cellX * self::HORIZONTAL_SAMPLE_STEP + $stepX;
                        $worldX = $originX + $localX;
                        for ($stepZ = 0; $stepZ < self::HORIZONTAL_SAMPLE_STEP; ++$stepZ) {
                            $localZ = $cellZ * self::HORIZONTAL_SAMPLE_STEP + $stepZ;
                            $worldZ = $originZ + $localZ;
                            $sample = $samples[self::coordinateKey($worldX, $worldZ)];
                            for ($stepY = 0; $stepY < self::VERTICAL_SAMPLE_STEP; ++$stepY) {
                                $y = $y0 + $stepY;
                                $baseValue = self::trilinear(
                                    $baseCorners,
                                    $stepX / self::HORIZONTAL_SAMPLE_STEP,
                                    $stepY / self::VERTICAL_SAMPLE_STEP,
                                    $stepZ / self::HORIZONTAL_SAMPLE_STEP,
                                );
                                $carvedValue = self::trilinear(
                                    $carvedCorners,
                                    $stepX / self::HORIZONTAL_SAMPLE_STEP,
                                    $stepY / self::VERTICAL_SAMPLE_STEP,
                                    $stepZ / self::HORIZONTAL_SAMPLE_STEP,
                                );
                                if ($carvedValue > 0.0) {
                                    $builder->set(
                                        $localX,
                                        $y,
                                        $localZ,
                                        $y < 0
                                            ? $this->blocks->state('minecraft:deepslate')
                                            : $this->blocks->state('minecraft:stone'),
                                    );
                                    continue;
                                }
                                if ($baseValue <= 0.0 && $y <= self::SEA_LEVEL) {
                                    $builder->set($localX, $y, $localZ, $this->blocks->state('minecraft:water'));
                                    continue;
                                }
                                if ($y < $sample->surfaceHeight - 5) {
                                    $aquifer = $this->density->aquiferLevel($worldX, $y, $worldZ);
                                    if ($aquifer !== null && $y <= $aquifer) {
                                        $builder->set(
                                            $localX,
                                            $y,
                                            $localZ,
                                            $y <= -55
                                                ? $this->blocks->state('minecraft:lava')
                                                : $this->blocks->state('minecraft:water'),
                                        );
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->applyBedrock($builder, $originX, $originZ);
        [$surfaceHeights, $surfaceBiomes] = $this->applySurfaces($builder, $samples, $originX, $originZ);
        $this->populateGeologyAndOres($builder, $position);
        $this->decorateCaves($builder, $surfaceHeights, $surfaceBiomes, $originX, $originZ);
        $this->populateStructures($builder, $position, $samples, $surfaceHeights);
        $this->populateVegetation($builder, $position, $samples, $surfaceHeights);

        $sections = $builder->sections();
        $biomeStorages = $this->biomeStorages($surfaceHeights, $surfaceBiomes, $originX, $originZ);

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
        if ($this->spawn !== null) {
            return $this->spawn;
        }
        $candidate = $this->spawnCandidate();
        $air = $this->blocks->state('minecraft:air')->value;
        $water = $this->blocks->state('minecraft:water')->value;
        $lava = $this->blocks->state('minecraft:lava')->value;
        $baseChunkX = self::floorDiv($candidate->x, 16);
        $baseChunkZ = self::floorDiv($candidate->z, 16);
        for ($radius = 0; $radius <= 2; ++$radius) {
            for ($offsetZ = -$radius; $offsetZ <= $radius; ++$offsetZ) {
                for ($offsetX = -$radius; $offsetX <= $radius; ++$offsetX) {
                    if ($radius !== 0 && abs($offsetX) !== $radius && abs($offsetZ) !== $radius) {
                        continue;
                    }
                    $chunkX = $baseChunkX + $offsetX;
                    $chunkZ = $baseChunkZ + $offsetZ;
                    $chunk = $this->generate(new ChunkPosition($chunkX, $chunkZ));
                    for ($localZ = 0; $localZ < 16; $localZ += 2) {
                        for ($localX = 0; $localX < 16; $localX += 2) {
                            for ($y = min(240, Chunk::MAX_Y - 2); $y >= self::SEA_LEVEL + 1; --$y) {
                                $state = $chunk->blockStateAt($localX, $y, $localZ)->value;
                                if ($state !== $air && $state !== $water && $state !== $lava
                                    && $chunk->blockStateAt($localX, $y + 1, $localZ)->value === $air
                                    && $chunk->blockStateAt($localX, $y + 2, $localZ)->value === $air) {
                                    return $this->spawn = new SpawnPosition(
                                        $chunkX * 16 + $localX,
                                        $y + 1,
                                        $chunkZ * 16 + $localZ,
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }

        return $this->spawn = $candidate;
    }

    private function spawnCandidate(): SpawnPosition
    {
        if ($this->candidateSpawn !== null) {
            return $this->candidateSpawn;
        }
        for ($radius = 0; $radius <= 768; $radius += 8) {
            for ($z = -$radius; $z <= $radius; $z += 8) {
                for ($x = -$radius; $x <= $radius; $x += 8) {
                    if ($radius !== 0 && abs($x) !== $radius && abs($z) !== $radius) {
                        continue;
                    }
                    $sample = $this->terrain->sample($x, $z);
                    $biome = $this->biomes->resolve($sample)->identifier;
                    if ($sample->surfaceHeight <= self::SEA_LEVEL + 1 || $sample->slope > 5
                        || !in_array($biome, [
                            'minecraft:plains', 'minecraft:sunflower_plains', 'minecraft:forest',
                            'minecraft:birch_forest', 'minecraft:taiga', 'minecraft:savanna',
                            'minecraft:meadow', 'minecraft:cherry_grove',
                        ], true)) {
                        continue;
                    }

                    return $this->candidateSpawn = new SpawnPosition($x, $sample->surfaceHeight + 2, $z);
                }
            }
        }

        return $this->candidateSpawn = new SpawnPosition(0, $this->terrain->heightAt(0, 0) + 2, 0);
    }

    public function surfaceHeight(int $x, int $z, ?Biome $biome = null): int
    {
        return $this->terrain->heightAt($x, $z);
    }

    public function biomeAt(int $x, int $z): Biome
    {
        return $this->biomes->resolve($this->terrain->sample($x, $z));
    }

    private function densityGrid(int $originX, int $originZ): OverworldDensityGrid
    {
        $cacheKey = self::coordinateKey($originX, $originZ);
        $cached = $this->densityGridCache[$cacheKey] ?? null;
        if ($cached instanceof OverworldDensityGrid) {
            return $cached;
        }
        $base = [];
        $carved = [];
        for ($gridX = 0; $gridX <= 4; ++$gridX) {
            $worldX = $originX + $gridX * self::HORIZONTAL_SAMPLE_STEP;
            for ($gridZ = 0; $gridZ <= 4; ++$gridZ) {
                $worldZ = $originZ + $gridZ * self::HORIZONTAL_SAMPLE_STEP;
                $climate = $this->climateAt($worldX, $worldZ);
                for ($gridY = 0; $gridY <= 48; ++$gridY) {
                    $y = Chunk::MIN_Y + $gridY * self::VERTICAL_SAMPLE_STEP;
                    $baseDensity = $this->density->baseDensityAt($worldX, $y, $worldZ, $climate);
                    $base[] = $baseDensity;
                    $carved[] = $this->density->carvedDensityAt($worldX, $y, $worldZ, $climate, $baseDensity);
                }
            }
        }
        $grid = new OverworldDensityGrid($base, $carved);

        $this->densityGridCache[$cacheKey] = $grid;
        $this->densityGridCacheOrder[] = $cacheKey;
        if (count($this->densityGridCacheOrder) > 36) {
            foreach (array_splice($this->densityGridCacheOrder, 0, 4) as $expired) {
                unset($this->densityGridCache[$expired]);
            }
        }

        return $grid;
    }

    /** @param array{float, float, float, float, float, float, float, float} $corners */
    private static function trilinear(array $corners, float $x, float $y, float $z): float
    {
        $x00 = $corners[0] + ($corners[1] - $corners[0]) * $x;
        $x01 = $corners[2] + ($corners[3] - $corners[2]) * $x;
        $x10 = $corners[4] + ($corners[5] - $corners[4]) * $x;
        $x11 = $corners[6] + ($corners[7] - $corners[6]) * $x;
        $z0 = $x00 + ($x01 - $x00) * $z;
        $z1 = $x10 + ($x11 - $x10) * $z;

        return $z0 + ($z1 - $z0) * $y;
    }

    private function applyBedrock(MutableChunkBuilder $builder, int $originX, int $originZ): void
    {
        $bedrock = $this->blocks->state('minecraft:bedrock');
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                $builder->set($x, Chunk::MIN_Y, $z, $bedrock);
                for ($y = Chunk::MIN_Y + 1; $y < Chunk::MIN_Y + 5; ++$y) {
                    if ($this->noise->chance($originX + $x, $y, $originZ + $z, 5_101, 5) >= $y - Chunk::MIN_Y) {
                        $builder->set($x, $y, $z, $bedrock);
                    }
                }
            }
        }
    }

    /**
     * @param array<string, OverworldTerrainSample> $samples
     * @return array{list<int>, list<Biome>}
     */
    private function applySurfaces(MutableChunkBuilder $builder, array $samples, int $originX, int $originZ): array
    {
        $heights = [];
        $biomes = [];
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                $worldX = $originX + $x;
                $worldZ = $originZ + $z;
                $sample = $samples[self::coordinateKey($worldX, $worldZ)];
                $height = $builder->highestSolid($x, $z, 240);
                $resolved = $this->biomes->resolve(new OverworldTerrainSample(
                    $sample->climate,
                    $height,
                    $sample->slope,
                    $sample->riverStrength,
                ));
                [$top, $filler, $depth, $snow] = $this->surfaceRule($resolved, $sample, $worldX, $worldZ);
                for ($y = max(Chunk::MIN_Y + 5, $height - $depth); $y < $height; ++$y) {
                    $current = $builder->state($x, $y, $z)->value;
                    if ($current === $this->blocks->state('minecraft:stone')->value
                        || $current === $this->blocks->state('minecraft:deepslate')->value) {
                        $builder->set($x, $y, $z, $filler);
                    }
                }
                $builder->set($x, $height, $z, $top);
                if (str_contains($resolved->identifier, 'frozen_ocean')
                    && $builder->state($x, self::SEA_LEVEL, $z)->value === $this->blocks->state('minecraft:water')->value) {
                    $builder->set(
                        $x,
                        self::SEA_LEVEL,
                        $z,
                        $this->blocks->state($this->noise->chance($worldX, 0, $worldZ, 5_417, 19) === 0
                            ? 'minecraft:blue_ice'
                            : 'minecraft:ice'),
                    );
                }
                if ($snow && $height + 1 <= Chunk::MAX_Y
                    && $builder->state($x, $height + 1, $z)->value === $this->blocks->state('minecraft:air')->value) {
                    $builder->set($x, $height + 1, $z, $this->blocks->state('minecraft:snow_layer'));
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
            return [$this->blocks->state('minecraft:sand'), $this->blocks->state('minecraft:sand'), $depth, false];
        }
        if (str_contains($id, 'ocean')) {
            $choice = $this->noise->chance($x, 0, $z, 5_307, 5);
            $state = $this->blocks->state($choice === 0 ? 'minecraft:clay' : ($choice <= 2 ? 'minecraft:gravel' : 'minecraft:sand'));

            return [$state, $state, $depth, false];
        }
        if ($id === 'minecraft:river' || $id === 'minecraft:frozen_river') {
            return [$this->blocks->state('minecraft:gravel'), $this->blocks->state('minecraft:clay'), $depth, false];
        }
        if ($id === 'minecraft:stone_beach' || str_contains($id, 'peak') || str_contains($id, 'extreme_hills')) {
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

    private function populateGeologyAndOres(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        $deposits = [
            ['minecraft:granite', 8, -48, 96, 20, 6_101],
            ['minecraft:diorite', 8, -32, 112, 18, 6_211],
            ['minecraft:andesite', 8, -16, 128, 18, 6_307],
            ['minecraft:tuff', 7, -60, 20, 20, 6_401],
            ['minecraft:coal_ore', 14, 0, 192, 12, 6_503],
            ['minecraft:iron_ore', 12, -56, 128, 10, 6_601],
            ['minecraft:copper_ore', 9, -16, 112, 10, 6_701],
            ['minecraft:gold_ore', 5, -60, 48, 8, 6_809],
            ['minecraft:redstone_ore', 7, -60, 12, 8, 6_907],
            ['minecraft:lapis_ore', 3, -32, 48, 7, 7_001],
            ['minecraft:diamond_ore', 4, -60, 8, 6, 7_103],
            ['minecraft:emerald_ore', 2, -16, 128, 5, 7_201],
        ];
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        foreach ($deposits as [$identifier, $attempts, $minimumY, $maximumY, $length, $salt]) {
            $replacement = $this->blocks->state($identifier);
            for ($attempt = 0; $attempt < $attempts; ++$attempt) {
                $x = $this->noise->chance($position->x, $attempt, $position->z, $salt, 16);
                $z = $this->noise->chance($position->x, $attempt, $position->z, $salt + 1, 16);
                $y = $minimumY + $this->noise->chance($position->x, $attempt, $position->z, $salt + 2, $maximumY - $minimumY + 1);
                for ($step = 0; $step < $length; ++$step) {
                    $localX = ($x + intdiv($step, 3)) & 15;
                    $localZ = ($z + intdiv($step * 2, 5)) & 15;
                    $localY = $y + intdiv($step, 4) - intdiv($length, 8);
                    $current = $builder->state($localX, $localY, $localZ)->value;
                    if ($current === $this->blocks->state('minecraft:stone')->value
                        || $current === $this->blocks->state('minecraft:deepslate')->value) {
                        $builder->set($localX, $localY, $localZ, $replacement);
                    }
                }
            }
        }
    }

    /**
     * @param list<int> $surfaceHeights
     * @param list<Biome> $surfaceBiomes
     */
    private function decorateCaves(
        MutableChunkBuilder $builder,
        array $surfaceHeights,
        array $surfaceBiomes,
        int $originX,
        int $originZ,
    ): void {
        $air = $this->blocks->state('minecraft:air')->value;
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                $surface = $surfaceHeights[$x + $z * 16];
                for ($y = Chunk::MIN_Y + 6; $y < min(48, $surface - 10); ++$y) {
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
    }

    /**
     * @param array<string, OverworldTerrainSample> $samples
     * @param list<int> $surfaceHeights
     */
    private function populateVegetation(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $samples,
        array $surfaceHeights,
    ): void {
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        for ($worldZ = $originZ - 4; $worldZ <= $originZ + 19; ++$worldZ) {
            for ($worldX = $originX - 4; $worldX <= $originX + 19; ++$worldX) {
                $sample = $samples[self::coordinateKey($worldX, $worldZ)] ?? $this->terrain->sample($worldX, $worldZ);
                $biome = $this->biomes->resolve($sample)->identifier;
                if (str_contains($biome, 'ocean')) {
                    $this->placeAquaticFeature(
                        $builder,
                        $position,
                        $surfaceHeights,
                        $worldX,
                        $worldZ,
                        $biome,
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
        if (!$this->treeVolumeIsReplaceable($builder, $position, $x, $z, $ground, $height, $biome)) {
            return;
        }
        for ($y = $ground + 1; $y <= $ground + $height; ++$y) {
            $builder->setWorld($x, $y, $z, $this->blocks->state($log));
        }
        $top = $ground + $height;
        for ($y = $top - 3; $y <= $top + 1; ++$y) {
            $radius = $y >= $top ? 1 : (str_contains($biome, 'taiga') || str_contains($biome, 'grove')
                ? max(1, 3 - intdiv($y - ($top - 3), 2))
                : 2);
            for ($leafZ = $z - $radius; $leafZ <= $z + $radius; ++$leafZ) {
                for ($leafX = $x - $radius; $leafX <= $x + $radius; ++$leafX) {
                    if (abs($leafX - $x) === $radius && abs($leafZ - $z) === $radius
                        && $this->noise->chance($leafX, $y, $leafZ, 8_307, 3) === 0) {
                        continue;
                    }
                    $builder->setWorld($leafX, $y, $leafZ, $this->blocks->state($leaves), true);
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
        $roll = $this->noise->chance($x, 0, $z, 8_607, 128);
        if (str_contains($biome, 'warm_ocean') && $roll < 13) {
            $corals = [
                'minecraft:tube_coral_block', 'minecraft:brain_coral_block', 'minecraft:bubble_coral_block',
                'minecraft:fire_coral_block', 'minecraft:horn_coral_block',
            ];
            $builder->set($localX, $floor, $localZ, $this->blocks->state($corals[$roll % count($corals)]));

            return;
        }
        if ($roll >= 28 || str_contains($biome, 'frozen')) {
            return;
        }
        $feature = $roll < 8 ? 'minecraft:kelp' : 'minecraft:seagrass';
        $height = $feature === 'minecraft:kelp' ? 2 + ($roll % 5) : 1;
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
        $height = $this->densitySurfaceHeightAt($worldX, $worldZ);
        $localX = $worldX - $position->x * 16;
        $localZ = $worldZ - $position->z * 16;
        if ($localX >= 0 && $localX < 16 && $localZ >= 0 && $localZ < 16
            && $surfaceHeights[$localX + $localZ * 16] !== $height) {
            throw new \LogicException('Regional terrain surface does not match the generated chunk surface.');
        }

        return $height;
    }

    private function densitySurfaceHeightAt(int $worldX, int $worldZ): int
    {
        $chunkX = self::floorDiv($worldX, 16);
        $chunkZ = self::floorDiv($worldZ, 16);
        $originX = $chunkX * 16;
        $originZ = $chunkZ * 16;
        $localX = $worldX - $originX;
        $localZ = $worldZ - $originZ;
        $cellX = intdiv($localX, self::HORIZONTAL_SAMPLE_STEP);
        $cellZ = intdiv($localZ, self::HORIZONTAL_SAMPLE_STEP);
        $stepX = $localX % self::HORIZONTAL_SAMPLE_STEP;
        $stepZ = $localZ % self::HORIZONTAL_SAMPLE_STEP;
        $density = $this->densityGrid($originX, $originZ);
        for ($y = 240; $y >= Chunk::MIN_Y; --$y) {
            $relativeY = $y - Chunk::MIN_Y;
            $cellY = intdiv($relativeY, self::VERTICAL_SAMPLE_STEP);
            $stepY = $relativeY % self::VERTICAL_SAMPLE_STEP;
            if (self::trilinear(
                $density->carvedCorners($cellX, $cellY, $cellZ),
                $stepX / self::HORIZONTAL_SAMPLE_STEP,
                $stepY / self::VERTICAL_SAMPLE_STEP,
                $stepZ / self::HORIZONTAL_SAMPLE_STEP,
            ) > 0.0) {
                return $y;
            }
        }

        return Chunk::MIN_Y;
    }

    private function treeVolumeIsReplaceable(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        int $worldX,
        int $worldZ,
        int $ground,
        int $height,
        string $biome,
    ): bool {
        for ($y = $ground + 1; $y <= $ground + $height; ++$y) {
            if (!$this->isVegetationReplaceable($this->worldState($builder, $position, $worldX, $y, $worldZ))) {
                return false;
            }
        }
        $top = $ground + $height;
        for ($y = $top - 3; $y <= $top + 1; ++$y) {
            $radius = $y >= $top ? 1 : (str_contains($biome, 'taiga') || str_contains($biome, 'grove')
                ? max(1, 3 - intdiv($y - ($top - 3), 2))
                : 2);
            for ($z = $worldZ - $radius; $z <= $worldZ + $radius; ++$z) {
                for ($x = $worldX - $radius; $x <= $worldX + $radius; ++$x) {
                    if (!$this->isVegetationReplaceable($this->worldState($builder, $position, $x, $y, $z))) {
                        return false;
                    }
                }
            }
        }

        return true;
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
        return $this->stateIsAny($state, [
            'minecraft:dirt', 'minecraft:grass_block', 'minecraft:coarse_dirt', 'minecraft:podzol',
            'minecraft:mud', 'minecraft:moss_block', 'minecraft:pale_moss_block', 'minecraft:mycelium',
            'minecraft:dirt_with_roots',
        ]);
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
        return $this->stateIsAny($state, [
            'minecraft:sand', 'minecraft:red_sand', 'minecraft:gravel', 'minecraft:clay',
            'minecraft:dirt', 'minecraft:grass_block', 'minecraft:mud',
        ]);
    }

    private function isVegetationReplaceable(InternalBlockStateId $state): bool
    {
        return $this->stateIsAny($state, [
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
     * @param array<string, OverworldTerrainSample> $samples
     * @param list<int> $surfaceHeights
     */
    private function populateStructures(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $samples,
        array $surfaceHeights,
    ): void {
        $this->populateVillage($builder, $position, $samples, $surfaceHeights);
        $this->populateSurfaceLandmark($builder, $position, $samples, $surfaceHeights);
        $this->populateMineshaft($builder, $position);
        $this->populateDungeon($builder, $position);
        $this->populateGeode($builder, $position);
        $this->populateStronghold($builder, $position);
    }

    /**
     * @param array<string, OverworldTerrainSample> $samples
     * @param list<int> $surfaceHeights
     */
    private function populateVillage(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $samples,
        array $surfaceHeights,
    ): void {
        $regionX = self::floorDiv($position->x, 24);
        $regionZ = self::floorDiv($position->z, 24);
        for ($rz = $regionZ - 1; $rz <= $regionZ + 1; ++$rz) {
            for ($rx = $regionX - 1; $rx <= $regionX + 1; ++$rx) {
                $anchorX = $rx * 384 + 96 + $this->noise->chance($rx, 0, $rz, 9_101, 192);
                $anchorZ = $rz * 384 + 96 + $this->noise->chance($rx, 1, $rz, 9_103, 192);
                if (!self::intersectsChunk($position, $anchorX, $anchorZ, 40)) {
                    continue;
                }
                $sample = $samples[self::coordinateKey($anchorX, $anchorZ)] ?? $this->regionalSample($anchorX, $anchorZ);
                $biome = $this->biomes->resolve($sample)->identifier;
                if (!in_array($biome, [
                    'minecraft:plains', 'minecraft:sunflower_plains', 'minecraft:desert',
                    'minecraft:savanna', 'minecraft:taiga', 'minecraft:cold_taiga', 'minecraft:ice_plains',
                ], true) || $sample->surfaceHeight <= self::SEA_LEVEL + 1 || $sample->slope > 5) {
                    continue;
                }
                $this->placeVillagePieces(
                    $builder,
                    $position,
                    $surfaceHeights,
                    $anchorX,
                    $anchorZ,
                    $sample->surfaceHeight,
                    $biome,
                );
            }
        }
    }

    /** @param list<int> $surfaceHeights */
    private function placeVillagePieces(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $surfaceHeights,
        int $centerX,
        int $centerZ,
        int $ground,
        string $biome,
    ): void {
        $ground = $this->terrainSurfaceHeight($surfaceHeights, $position, $centerX, $centerZ);
        [$planks, $logs] = match (true) {
            str_contains($biome, 'desert') => ['minecraft:sandstone', 'minecraft:sandstone'],
            str_contains($biome, 'savanna') => ['minecraft:acacia_planks', 'minecraft:acacia_log'],
            str_contains($biome, 'taiga') => ['minecraft:spruce_planks', 'minecraft:spruce_log'],
            str_contains($biome, 'ice_') => ['minecraft:spruce_planks', 'minecraft:spruce_log'],
            default => ['minecraft:oak_planks', 'minecraft:oak_log'],
        };
        for ($offset = -28; $offset <= 28; ++$offset) {
            for ($width = -1; $width <= 1; ++$width) {
                $this->setSurfaceWorld($builder, $position, $surfaceHeights, $centerX + $offset, $centerZ + $width, 'minecraft:grass_path');
                $this->setSurfaceWorld($builder, $position, $surfaceHeights, $centerX + $width, $centerZ + $offset, 'minecraft:grass_path');
            }
        }
        for ($x = $centerX - 2; $x <= $centerX + 2; ++$x) {
            for ($z = $centerZ - 2; $z <= $centerZ + 2; ++$z) {
                $builder->setWorld($x, $ground, $z, $this->blocks->state('minecraft:cobblestone'));
                if (abs($x - $centerX) === 2 || abs($z - $centerZ) === 2) {
                    $builder->setWorld($x, $ground + 1, $z, $this->blocks->state('minecraft:cobblestone'));
                }
            }
        }
        foreach ([[-14, -10], [12, -11], [-14, 11], [12, 12], [0, -20], [20, 0]] as $index => [$dx, $dz]) {
            $width = $index % 2 === 0 ? 7 : 9;
            $depth = $index % 3 === 0 ? 8 : 7;
            $this->placeHouse($builder, $position, $surfaceHeights, $centerX + $dx, $centerZ + $dz, $width, $depth, $planks, $logs);
        }
        $this->placeFarm($builder, $position, $surfaceHeights, $centerX - 6, $centerZ + 18);
    }

    /** @param list<int> $surfaceHeights */
    private function placeHouse(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $surfaceHeights,
        int $centerX,
        int $centerZ,
        int $width,
        int $depth,
        string $wall,
        string $log,
    ): void {
        $ground = $this->terrainSurfaceHeight(
            $surfaceHeights,
            $position,
            $centerX,
            $centerZ,
        );
        $halfWidth = intdiv($width, 2);
        $halfDepth = intdiv($depth, 2);
        for ($z = $centerZ - $halfDepth; $z <= $centerZ + $halfDepth; ++$z) {
            for ($x = $centerX - $halfWidth; $x <= $centerX + $halfWidth; ++$x) {
                $builder->setWorld($x, $ground, $z, $this->blocks->state('minecraft:cobblestone'));
                for ($y = $ground + 1; $y <= $ground + 4; ++$y) {
                    $edge = abs($x - $centerX) === $halfWidth || abs($z - $centerZ) === $halfDepth;
                    $corner = abs($x - $centerX) === $halfWidth && abs($z - $centerZ) === $halfDepth;
                    if ($edge) {
                        $builder->setWorld($x, $y, $z, $this->blocks->state($corner ? $log : $wall));
                    } else {
                        $builder->setWorld($x, $y, $z, $this->blocks->state('minecraft:air'));
                    }
                }
            }
        }
        for ($layer = 0; $layer <= 3; ++$layer) {
            for ($z = $centerZ - $halfDepth - 1 + $layer; $z <= $centerZ + $halfDepth + 1 - $layer; ++$z) {
                for ($x = $centerX - $halfWidth - 1 + $layer; $x <= $centerX + $halfWidth + 1 - $layer; ++$x) {
                    $builder->setWorld($x, $ground + 5 + $layer, $z, $this->blocks->state($wall));
                }
            }
        }
        $builder->setWorld($centerX, $ground + 1, $centerZ - $halfDepth, $this->blocks->state('minecraft:air'));
        $builder->setWorld($centerX, $ground + 2, $centerZ - $halfDepth, $this->blocks->state('minecraft:air'));
    }

    /** @param list<int> $surfaceHeights */
    private function placeFarm(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $surfaceHeights,
        int $centerX,
        int $centerZ,
    ): void {
        $ground = $this->terrainSurfaceHeight(
            $surfaceHeights,
            $position,
            $centerX,
            $centerZ,
        );
        for ($z = $centerZ - 4; $z <= $centerZ + 4; ++$z) {
            for ($x = $centerX - 6; $x <= $centerX + 6; ++$x) {
                if (($x - $centerX) % 4 === 0) {
                    $builder->setWorld($x, $ground, $z, $this->blocks->state('minecraft:water'));
                } else {
                    $builder->setWorld($x, $ground, $z, $this->blocks->state('minecraft:farmland'));
                    $builder->setWorld($x, $ground + 1, $z, $this->blocks->state('minecraft:wheat'));
                }
            }
        }
    }

    /** @param list<int> $surfaceHeights */
    private function setSurfaceWorld(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $surfaceHeights,
        int $x,
        int $z,
        string $identifier,
    ): void {
        $height = $this->terrainSurfaceHeight(
            $surfaceHeights,
            $position,
            $x,
            $z,
        );
        $builder->setWorld($x, $height, $z, $this->blocks->state($identifier));
    }

    /**
     * @param array<string, OverworldTerrainSample> $samples
     * @param list<int> $surfaceHeights
     */
    private function populateSurfaceLandmark(
        MutableChunkBuilder $builder,
        ChunkPosition $position,
        array $samples,
        array $surfaceHeights,
    ): void {
        $regionX = self::floorDiv($position->x, 28);
        $regionZ = self::floorDiv($position->z, 28);
        for ($rz = $regionZ - 1; $rz <= $regionZ + 1; ++$rz) {
            for ($rx = $regionX - 1; $rx <= $regionX + 1; ++$rx) {
                $anchorX = $rx * 448 + 96 + $this->noise->chance($rx, 0, $rz, 9_601, 256);
                $anchorZ = $rz * 448 + 96 + $this->noise->chance($rx, 1, $rz, 9_607, 256);
                if (!self::intersectsChunk($position, $anchorX, $anchorZ, 16)) {
                    continue;
                }
                $sample = $samples[self::coordinateKey($anchorX, $anchorZ)] ?? $this->regionalSample($anchorX, $anchorZ);
                $biome = $this->biomes->resolve($sample)->identifier;
                $ground = $this->terrainSurfaceHeight(
                    $surfaceHeights,
                    $position,
                    $anchorX,
                    $anchorZ,
                );
                if (str_contains($biome, 'ocean')) {
                    $this->placeOceanRuin(
                        $builder,
                        $anchorX,
                        $anchorZ,
                        $ground,
                        $biome === 'minecraft:warm_ocean',
                    );
                } elseif (str_contains($biome, 'desert') || str_contains($biome, 'mesa')) {
                    $this->placeDesertTemple($builder, $anchorX, $anchorZ, $ground);
                } elseif (str_contains($biome, 'jungle') || str_contains($biome, 'bamboo')) {
                    $this->placeJungleTemple($builder, $anchorX, $anchorZ, $ground);
                } elseif (str_contains($biome, 'swamp') || str_contains($biome, 'mangrove')) {
                    $this->placeSwampHut($builder, $anchorX, $anchorZ, $ground);
                } elseif ($sample->surfaceHeight > self::SEA_LEVEL + 1
                    && $this->noise->chance($rx, 2, $rz, 9_613, 3) === 0) {
                    $this->placeRuinedPortal($builder, $anchorX, $anchorZ, $ground);
                }
            }
        }
    }

    private function placeDesertTemple(MutableChunkBuilder $builder, int $centerX, int $centerZ, int $ground): void
    {
        for ($layer = 0; $layer < 4; ++$layer) {
            $radius = 9 - $layer * 2;
            for ($z = -$radius; $z <= $radius; ++$z) {
                for ($x = -$radius; $x <= $radius; ++$x) {
                    $builder->setWorld(
                        $centerX + $x,
                        $ground + $layer,
                        $centerZ + $z,
                        $this->blocks->state(($x + $z + $layer) % 7 === 0
                            ? 'minecraft:cut_sandstone'
                            : 'minecraft:sandstone'),
                    );
                }
            }
        }
        for ($z = -4; $z <= 4; ++$z) {
            for ($x = -4; $x <= 4; ++$x) {
                for ($y = $ground + 4; $y <= $ground + 9; ++$y) {
                    $edge = abs($x) === 4 || abs($z) === 4 || $y === $ground + 9;
                    $builder->setWorld(
                        $centerX + $x,
                        $y,
                        $centerZ + $z,
                        $this->blocks->state($edge ? 'minecraft:smooth_sandstone' : 'minecraft:air'),
                    );
                }
            }
        }
        for ($y = $ground + 4; $y <= $ground + 13; ++$y) {
            $builder->setWorld($centerX - 6, $y, $centerZ, $this->blocks->state('minecraft:chiseled_sandstone'));
            $builder->setWorld($centerX + 6, $y, $centerZ, $this->blocks->state('minecraft:chiseled_sandstone'));
        }
    }

    private function placeJungleTemple(MutableChunkBuilder $builder, int $centerX, int $centerZ, int $ground): void
    {
        for ($z = -6; $z <= 6; ++$z) {
            for ($x = -5; $x <= 5; ++$x) {
                for ($y = 0; $y <= 8; ++$y) {
                    $edge = abs($x) === 5 || abs($z) === 6 || $y === 0 || $y === 8;
                    $state = $edge
                        ? ($this->noise->chance($centerX + $x, $ground + $y, $centerZ + $z, 9_701, 4) === 0
                            ? 'minecraft:mossy_cobblestone'
                            : 'minecraft:stone_bricks')
                        : 'minecraft:air';
                    $builder->setWorld($centerX + $x, $ground + $y, $centerZ + $z, $this->blocks->state($state));
                }
            }
        }
        for ($y = 1; $y <= 6; ++$y) {
            $builder->setWorld($centerX, $ground + $y, $centerZ - 6, $this->blocks->state('minecraft:air'));
        }
    }

    private function placeOceanRuin(
        MutableChunkBuilder $builder,
        int $centerX,
        int $centerZ,
        int $floor,
        bool $warm,
    ): void {
        for ($z = -6; $z <= 6; ++$z) {
            for ($x = -6; $x <= 6; ++$x) {
                if (abs($x) + abs($z) > 9) {
                    continue;
                }
                $builder->setWorld($centerX + $x, $floor, $centerZ + $z, $this->blocks->state('minecraft:prismarine'));
                if (abs($x) === 5 || abs($z) === 5) {
                    $height = 2 + $this->noise->chance($centerX + $x, 0, $centerZ + $z, 9_733, 4);
                    for ($y = 1; $y <= $height; ++$y) {
                        $builder->setWorld(
                            $centerX + $x,
                            $floor + $y,
                            $centerZ + $z,
                            $this->blocks->state($y === $height && ($x + $z) % 3 === 0
                                ? 'minecraft:sea_lantern'
                                : 'minecraft:dark_prismarine'),
                        );
                    }
                }
            }
        }
        if (!$warm) {
            return;
        }
        foreach ([[-3, -2], [2, 3], [0, 0]] as $index => [$offsetX, $offsetZ]) {
            $x = $centerX + $offsetX;
            $z = $centerZ + $offsetZ;
            $lootSeed = $this->archaeologySeed($x, $floor + 1, $z, $index);
            $identifier = $this->noise->chance($x, $floor, $z, 9_739 + $index, 10_000) < 670
                ? 'minecraft:sniffer_egg'
                : ['minecraft:brick', 'minecraft:emerald', 'minecraft:wheat'][$index];
            $builder->setWorld($x, $floor + 1, $z, $this->blocks->state('minecraft:suspicious_sand'));
            $builder->addBlockEntity(new SuspiciousSandBlockEntity(
                new BlockPosition($x, $floor + 1, $z),
                new ContainerItemStack($identifier, 1),
                $lootSeed,
                SuspiciousSandBlockEntity::WARM_OCEAN_RUIN_PROVENANCE,
            ));
        }
    }

    private function archaeologySeed(int $x, int $y, int $z, int $index): int
    {
        $high = $this->noise->chance($x, $y, $z, 9_751 + $index, 0x7fffffff);
        $low = $this->noise->chance($z, $y, $x, 9_761 + $index, 0x7fffffff);

        return ($high << 31) ^ $low;
    }

    private function placeSwampHut(MutableChunkBuilder $builder, int $centerX, int $centerZ, int $ground): void
    {
        foreach ([[-4, -3], [-4, 3], [4, -3], [4, 3]] as [$offsetX, $offsetZ]) {
            for ($y = $ground - 4; $y <= $ground + 5; ++$y) {
                $builder->setWorld($centerX + $offsetX, $y, $centerZ + $offsetZ, $this->blocks->state('minecraft:dark_oak_log'));
            }
        }
        for ($z = -4; $z <= 4; ++$z) {
            for ($x = -5; $x <= 5; ++$x) {
                $builder->setWorld($centerX + $x, $ground + 3, $centerZ + $z, $this->blocks->state('minecraft:dark_oak_planks'));
                $wall = abs($x) === 5 || abs($z) === 4;
                for ($y = $ground + 4; $y <= $ground + 6; ++$y) {
                    $builder->setWorld(
                        $centerX + $x,
                        $y,
                        $centerZ + $z,
                        $this->blocks->state($wall ? 'minecraft:dark_oak_planks' : 'minecraft:air'),
                    );
                }
                $builder->setWorld($centerX + $x, $ground + 7, $centerZ + $z, $this->blocks->state('minecraft:spruce_planks'));
            }
        }
    }

    private function placeRuinedPortal(MutableChunkBuilder $builder, int $centerX, int $centerZ, int $ground): void
    {
        for ($z = -4; $z <= 4; ++$z) {
            for ($x = -5; $x <= 5; ++$x) {
                if ($this->noise->chance($centerX + $x, 0, $centerZ + $z, 9_809, 5) === 0) {
                    $builder->setWorld($centerX + $x, $ground, $centerZ + $z, $this->blocks->state('minecraft:netherrack'));
                }
            }
        }
        for ($y = 1; $y <= 6; ++$y) {
            foreach ([-2, 2] as $offset) {
                if ($this->noise->chance($centerX + $offset, $y, $centerZ, 9_811, 8) !== 0) {
                    $builder->setWorld(
                        $centerX + $offset,
                        $ground + $y,
                        $centerZ,
                        $this->blocks->state($y === 3 && $offset === 2 ? 'minecraft:crying_obsidian' : 'minecraft:obsidian'),
                    );
                }
            }
        }
        for ($x = -2; $x <= 2; ++$x) {
            if ($this->noise->chance($centerX + $x, 0, $centerZ, 9_821, 8) !== 0) {
                $builder->setWorld($centerX + $x, $ground + 6, $centerZ, $this->blocks->state('minecraft:obsidian'));
            }
        }
        $builder->setWorld($centerX, $ground, $centerZ + 1, $this->blocks->state('minecraft:magma'));
        $builder->setWorld($centerX + 1, $ground + 1, $centerZ + 1, $this->blocks->state('minecraft:gold_block'));
    }

    private function populateStronghold(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        $regionX = self::floorDiv($position->x, 56);
        $regionZ = self::floorDiv($position->z, 56);
        for ($rz = $regionZ - 1; $rz <= $regionZ + 1; ++$rz) {
            for ($rx = $regionX - 1; $rx <= $regionX + 1; ++$rx) {
                $centerX = $rx * 896 + 224 + $this->noise->chance($rx, 0, $rz, 9_907, 448);
                $centerZ = $rz * 896 + 224 + $this->noise->chance($rx, 1, $rz, 9_911, 448);
                $centerY = -28 + $this->noise->chance($rx, 2, $rz, 9_917, 34);
                if (!self::intersectsChunk($position, $centerX, $centerZ, 22)) {
                    continue;
                }
                for ($z = -14; $z <= 14; ++$z) {
                    for ($x = -14; $x <= 14; ++$x) {
                        $distance = max(abs($x), abs($z));
                        if ($distance < 10) {
                            continue;
                        }
                        for ($y = -1; $y <= 5; ++$y) {
                            $wall = $distance === 14 || $distance === 10 || $y === -1 || $y === 5;
                            $block = $wall
                                ? match ($this->noise->chance($centerX + $x, $centerY + $y, $centerZ + $z, 9_919, 9)) {
                                    0 => 'minecraft:mossy_stone_bricks',
                                    1 => 'minecraft:cracked_stone_bricks',
                                    default => 'minecraft:stone_bricks',
                                }
                            : 'minecraft:air';
                            $builder->setWorld($centerX + $x, $centerY + $y, $centerZ + $z, $this->blocks->state($block));
                        }
                    }
                }
                for ($offset = -20; $offset <= 20; ++$offset) {
                    for ($width = -1; $width <= 1; ++$width) {
                        for ($height = 0; $height <= 3; ++$height) {
                            $builder->setWorld($centerX + $offset, $centerY + $height, $centerZ + $width, $this->blocks->state('minecraft:air'));
                            $builder->setWorld($centerX + $width, $centerY + $height, $centerZ + $offset, $this->blocks->state('minecraft:air'));
                        }
                    }
                }
            }
        }
    }

    private function populateMineshaft(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        $regionX = self::floorDiv($position->x, 12);
        $regionZ = self::floorDiv($position->z, 12);
        for ($rz = $regionZ - 1; $rz <= $regionZ + 1; ++$rz) {
            for ($rx = $regionX - 1; $rx <= $regionX + 1; ++$rx) {
                if ($this->noise->chance($rx, 0, $rz, 9_307, 4) !== 0) {
                    continue;
                }
                $centerX = $rx * 192 + 48 + $this->noise->chance($rx, 1, $rz, 9_311, 96);
                $centerZ = $rz * 192 + 48 + $this->noise->chance($rx, 2, $rz, 9_313, 96);
                $y = -16 + $this->noise->chance($rx, 3, $rz, 9_317, 48);
                if (!self::intersectsChunk($position, $centerX, $centerZ, 44)) {
                    continue;
                }
                for ($offset = -42; $offset <= 42; ++$offset) {
                    for ($dy = 0; $dy <= 3; ++$dy) {
                        for ($width = -1; $width <= 1; ++$width) {
                            $builder->setWorld($centerX + $offset, $y + $dy, $centerZ + $width, $this->blocks->state('minecraft:air'));
                            $builder->setWorld($centerX + $width, $y + $dy, $centerZ + $offset, $this->blocks->state('minecraft:air'));
                        }
                    }
                    if ($offset % 5 === 0) {
                        for ($dy = 0; $dy <= 3; ++$dy) {
                            $builder->setWorld($centerX + $offset, $y + $dy, $centerZ - 2, $this->blocks->state('minecraft:oak_log'));
                            $builder->setWorld($centerX + $offset, $y + $dy, $centerZ + 2, $this->blocks->state('minecraft:oak_log'));
                            $builder->setWorld($centerX - 2, $y + $dy, $centerZ + $offset, $this->blocks->state('minecraft:oak_log'));
                            $builder->setWorld($centerX + 2, $y + $dy, $centerZ + $offset, $this->blocks->state('minecraft:oak_log'));
                        }
                        for ($width = -1; $width <= 1; ++$width) {
                            $builder->setWorld($centerX + $offset, $y, $centerZ + $width, $this->blocks->state('minecraft:rail'));
                        }
                    }
                }
            }
        }
    }

    private function populateDungeon(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        for ($chunkZ = $position->z - 1; $chunkZ <= $position->z + 1; ++$chunkZ) {
            for ($chunkX = $position->x - 1; $chunkX <= $position->x + 1; ++$chunkX) {
                if ($this->noise->chance($chunkX, 0, $chunkZ, 9_401, 28) !== 0) {
                    continue;
                }
                $centerX = $chunkX * 16 + 8;
                $centerZ = $chunkZ * 16 + 8;
                $centerY = -24 + $this->noise->chance($chunkX, 1, $chunkZ, 9_403, 64);
                if (!self::intersectsChunk($position, $centerX, $centerZ, 5)) {
                    continue;
                }
                for ($z = -4; $z <= 4; ++$z) {
                    for ($x = -4; $x <= 4; ++$x) {
                        for ($y = -1; $y <= 4; ++$y) {
                            $wall = abs($x) === 4 || abs($z) === 4 || $y === -1 || $y === 4;
                            $state = $wall
                                ? $this->blocks->state($this->noise->chance($centerX + $x, $centerY + $y, $centerZ + $z, 9_409, 4) === 0
                                    ? 'minecraft:mossy_cobblestone'
                                    : 'minecraft:cobblestone')
                                : $this->blocks->state('minecraft:air');
                            $builder->setWorld($centerX + $x, $centerY + $y, $centerZ + $z, $state);
                        }
                    }
                }
            }
        }
    }

    private function populateGeode(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        for ($chunkZ = $position->z - 1; $chunkZ <= $position->z + 1; ++$chunkZ) {
            for ($chunkX = $position->x - 1; $chunkX <= $position->x + 1; ++$chunkX) {
                if ($this->noise->chance($chunkX, 0, $chunkZ, 9_503, 34) !== 0) {
                    continue;
                }
                $centerX = $chunkX * 16 + $this->noise->chance($chunkX, 1, $chunkZ, 9_509, 16);
                $centerZ = $chunkZ * 16 + $this->noise->chance($chunkX, 2, $chunkZ, 9_511, 16);
                $centerY = -42 + $this->noise->chance($chunkX, 3, $chunkZ, 9_521, 80);
                if (!self::intersectsChunk($position, $centerX, $centerZ, 7)) {
                    continue;
                }
                for ($z = -6; $z <= 6; ++$z) {
                    for ($x = -6; $x <= 6; ++$x) {
                        for ($y = -6; $y <= 6; ++$y) {
                            $distance = $x * $x + $y * $y + $z * $z;
                            $state = match (true) {
                                $distance <= 16 => $this->blocks->state('minecraft:air'),
                                $distance <= 25 => $this->blocks->state('minecraft:budding_amethyst'),
                                $distance <= 36 => $this->blocks->state('minecraft:amethyst_block'),
                                $distance <= 42 => $this->blocks->state('minecraft:calcite'),
                                default => null,
                            };
                            if ($state !== null) {
                                $builder->setWorld($centerX + $x, $centerY + $y, $centerZ + $z, $state);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param list<int> $surfaceHeights
     * @param list<Biome> $surfaceBiomes
     * @return array<int, BiomeStorage>
     */
    private function biomeStorages(array $surfaceHeights, array $surfaceBiomes, int $originX, int $originZ): array
    {
        /** @var array<int, BiomeStorage> $storages */
        $storages = [];
        for ($sectionY = Chunk::MIN_SECTION_Y; $sectionY <= Chunk::MAX_SECTION_Y; ++$sectionY) {
            $palette = [];
            $paletteByIdentifier = [];
            $indices = str_repeat("\x00", BiomeStorage::BIOME_COUNT);
            for ($quartY = 0; $quartY < 16; $quartY += 4) {
                $y = $sectionY * 16 + $quartY + 2;
                for ($quartZ = 0; $quartZ < 16; $quartZ += 4) {
                    for ($quartX = 0; $quartX < 16; $quartX += 4) {
                        $column = $quartX + $quartZ * 16;
                        $surfaceBiome = $surfaceBiomes[$column];
                        $biome = $this->caveBiomes->resolve(
                            $originX + $quartX + 2,
                            $y,
                            $originZ + $quartZ + 2,
                            $surfaceHeights[$column],
                            $surfaceBiome,
                        );
                        $index = $paletteByIdentifier[$biome->identifier] ?? null;
                        if (!is_int($index)) {
                            $index = count($palette);
                            $palette[] = $biome;
                            $paletteByIdentifier[$biome->identifier] = $index;
                        }
                        for ($dy = 0; $dy < 4; ++$dy) {
                            for ($dz = 0; $dz < 4; ++$dz) {
                                for ($dx = 0; $dx < 4; ++$dx) {
                                    $indices[($quartX + $dx) + (($quartZ + $dz) * 16) + (($quartY + $dy) * 256)] = self::encodeBiomeIndex($index);
                                }
                            }
                        }
                    }
                }
            }
            $storages[$sectionY] = BiomeStorage::fromPaletteIndices($palette, $indices);
        }

        return $storages;
    }

    private static function encodeBiomeIndex(int $index): string
    {
        if ($index < 0 || $index > 255) {
            throw new \LogicException('Generated biome palette index is outside its byte bound.');
        }

        return pack('C', $index);
    }

    /** @return array<string, OverworldTerrainSample> */
    private function terrainRegion(ChunkPosition $position, int $margin): array
    {
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        $heights = [];
        $climates = [];
        $minimumX = $originX - $margin - 2;
        $maximumX = $originX + 15 + $margin + 2;
        $minimumZ = $originZ - $margin - 2;
        $maximumZ = $originZ + 15 + $margin + 2;
        $gridMinimumX = self::floorDiv($minimumX, 4) * 4;
        $gridMaximumX = self::floorDiv($maximumX, 4) * 4 + 4;
        $gridMinimumZ = self::floorDiv($minimumZ, 4) * 4;
        $gridMaximumZ = self::floorDiv($maximumZ, 4) * 4 + 4;
        $coarse = [];
        for ($z = $gridMinimumZ; $z <= $gridMaximumZ; $z += 4) {
            for ($x = $gridMinimumX; $x <= $gridMaximumX; $x += 4) {
                $coarse[self::coordinateKey($x, $z)] = $this->climateAt($x, $z);
            }
        }
        for ($z = $minimumZ; $z <= $maximumZ; ++$z) {
            for ($x = $minimumX; $x <= $maximumX; ++$x) {
                $leftX = self::floorDiv($x, 4) * 4;
                $topZ = self::floorDiv($z, 4) * 4;
                $climate = self::interpolateClimate(
                    $coarse[self::coordinateKey($leftX, $topZ)],
                    $coarse[self::coordinateKey($leftX + 4, $topZ)],
                    $coarse[self::coordinateKey($leftX, $topZ + 4)],
                    $coarse[self::coordinateKey($leftX + 4, $topZ + 4)],
                    $x - $leftX,
                    $z - $topZ,
                );
                $key = self::coordinateKey($x, $z);
                $climates[$key] = $climate;
                $heights[$key] = $this->terrain->surfaceHeight($climate);
            }
        }
        $samples = [];
        for ($z = $originZ - $margin; $z <= $originZ + 15 + $margin; ++$z) {
            for ($x = $originX - $margin; $x <= $originX + 15 + $margin; ++$x) {
                $key = self::coordinateKey($x, $z);
                $height = $heights[$key];
                $samples[$key] = new OverworldTerrainSample(
                    $climates[$key],
                    $height,
                    max(
                        abs($height - $heights[self::coordinateKey($x + 2, $z)]),
                        abs($height - $heights[self::coordinateKey($x - 2, $z)]),
                        abs($height - $heights[self::coordinateKey($x, $z + 2)]),
                        abs($height - $heights[self::coordinateKey($x, $z - 2)]),
                    ),
                    $this->terrain->riverStrength($climates[$key]),
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
    ): OverworldClimate {
        return new OverworldClimate(
            self::bilinear($northWest->continentalness, $northEast->continentalness, $southWest->continentalness, $southEast->continentalness, $offsetX, $offsetZ),
            self::bilinear($northWest->erosion, $northEast->erosion, $southWest->erosion, $southEast->erosion, $offsetX, $offsetZ),
            self::bilinear($northWest->temperature, $northEast->temperature, $southWest->temperature, $southEast->temperature, $offsetX, $offsetZ),
            self::bilinear($northWest->humidity, $northEast->humidity, $southWest->humidity, $southEast->humidity, $offsetX, $offsetZ),
            self::bilinear($northWest->ridge, $northEast->ridge, $southWest->ridge, $southEast->ridge, $offsetX, $offsetZ),
            self::bilinear($northWest->uplift, $northEast->uplift, $southWest->uplift, $southEast->uplift, $offsetX, $offsetZ),
            self::bilinear($northWest->river, $northEast->river, $southWest->river, $southEast->river, $offsetX, $offsetZ),
            self::bilinear($northWest->detail, $northEast->detail, $southWest->detail, $southEast->detail, $offsetX, $offsetZ),
        );
    }

    private static function bilinear(
        int $northWest,
        int $northEast,
        int $southWest,
        int $southEast,
        int $x,
        int $z,
    ): int {
        $north = $northWest + intdiv(($northEast - $northWest) * $x, 4);
        $south = $southWest + intdiv(($southEast - $southWest) * $x, 4);

        return $north + intdiv(($south - $north) * $z, 4);
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
        $climate = $this->climateAt($x, $z);

        return new OverworldTerrainSample(
            $climate,
            $this->terrain->surfaceHeight($climate),
            0,
            $this->terrain->riverStrength($climate),
        );
    }

    private static function coordinateKey(int $x, int $z): string
    {
        return $x . ':' . $z;
    }

    private static function intersectsChunk(
        ChunkPosition $position,
        int $centerX,
        int $centerZ,
        int $radius,
    ): bool {
        $minimumX = $position->x * 16;
        $minimumZ = $position->z * 16;

        return $centerX + $radius >= $minimumX
            && $centerX - $radius <= $minimumX + 15
            && $centerZ + $radius >= $minimumZ
            && $centerZ - $radius <= $minimumZ + 15;
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
