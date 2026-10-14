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

namespace Bedriox\Server\Tools;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\DefaultWorldGenerator;

/**
 * Deterministic, black-box qualification of generated chunks.
 *
 * The report deliberately contains measurements rather than release gates. Thresholds are set only after a
 * reviewed baseline and visual qualification, so a generator rewrite cannot make a weak target look successful.
 */
final class WorldGenerationQualification
{
    private const MIN_CAVE_Y = -32;

    /** @return array<string, mixed> */
    public function run(
        int $seed,
        int $radius,
        int $centerChunkX = 0,
        int $centerChunkZ = 0,
    ): array
    {
        if ($radius < 1 || $radius > 4) {
            throw new \InvalidArgumentException('Qualification radius must be between 1 and 4 chunks.');
        }
        if (abs($centerChunkX) > 1_000_000 || abs($centerChunkZ) > 1_000_000) {
            throw new \InvalidArgumentException('Qualification center chunks must be between -1000000 and 1000000.');
        }

        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = new DefaultWorldGenerator($seed, $states);
        $chunks = [];
        $latencies = [];
        for ($relativeChunkZ = -$radius; $relativeChunkZ <= $radius; ++$relativeChunkZ) {
            for ($relativeChunkX = -$radius; $relativeChunkX <= $radius; ++$relativeChunkX) {
                $started = hrtime(true);
                $chunks[$relativeChunkX . ':' . $relativeChunkZ] = $generator->generate(new ChunkPosition(
                    $centerChunkX + $relativeChunkX,
                    $centerChunkZ + $relativeChunkZ,
                ));
                $latencies[] = (hrtime(true) - $started) / 1_000_000;
            }
        }

        $minimum = -$radius * 16;
        $maximum = (($radius + 1) * 16) - 1;
        $width = $maximum - $minimum + 1;
        $heights = [];
        $surfaces = [];
        $biomes = [];
        $waterColumns = [];
        for ($z = $minimum; $z <= $maximum; ++$z) {
            for ($x = $minimum; $x <= $maximum; ++$x) {
                $chunk = $this->chunkAt($chunks, $x, $z);
                $localX = $this->floorMod($x, 16);
                $localZ = $this->floorMod($z, 16);
                [$height, $surface] = $this->surface($chunk, $states, $localX, $localZ);
                $index = $this->index($x, $z, $minimum, $width);
                $heights[$index] = $height;
                $surfaces[$index] = $surface;
                $biomes[$index] = $chunk->biomeAt($localX, max(Chunk::MIN_Y, min(Chunk::MAX_Y, $height)), $localZ)->identifier;
                $waterColumns[$index] = $this->isFluid($states->state($chunk->blockStateAt(
                    $localX,
                    DefaultWorldGenerator::SEA_LEVEL,
                    $localZ,
                ))->identifier());
            }
        }

        $deterministic = true;
        $reverseGenerator = new DefaultWorldGenerator($seed, $states);
        foreach (array_reverse(array_keys($chunks)) as $key) {
            [$relativeChunkX, $relativeChunkZ] = array_map('intval', explode(':', $key));
            $regenerated = $reverseGenerator->generate(new ChunkPosition(
                $centerChunkX + $relativeChunkX,
                $centerChunkZ + $relativeChunkZ,
            ));
            if ($this->signature($regenerated) !== $this->signature($chunks[$key])) {
                $deterministic = false;
                break;
            }
        }

        return [
            'seed' => $seed,
            'generator_version' => $generator->version(),
            'radius_chunks' => $radius,
            'center_chunk_x' => $centerChunkX,
            'center_chunk_z' => $centerChunkZ,
            'sampled_chunks' => count($chunks),
            'sampled_columns' => count($heights),
            'terrain' => $this->terrainMetrics($heights, $minimum, $maximum, $width),
            'surface_geology' => $this->geologyMetrics($surfaces, $heights, $minimum, $maximum, $width),
            'coastline' => $this->coastlineMetrics($waterColumns, $minimum, $maximum, $width),
            'biome_transitions' => $this->biomeMetrics($heights, $biomes, $minimum, $maximum, $width),
            'caves' => $this->caveMetrics($chunks, $states, $heights, $minimum, $maximum, $width),
            'vegetation' => $this->vegetationMetrics(
                $chunks,
                $states,
                $biomes,
                $waterColumns,
                $minimum,
                $maximum,
                $width,
            ),
            'seams' => $this->seamMetrics($heights, $minimum, $maximum, $width),
            'determinism' => ['reverse_order_byte_identical' => $deterministic],
            'latency_ms' => $this->distribution($latencies),
        ];
    }

    /** @param array<int, int> $heights
     *  @return array<string, int|float|array<string, float>>
     */
    private function terrainMetrics(array $heights, int $minimum, int $maximum, int $width): array
    {
        if ($heights === []) {
            throw new \LogicException('Terrain qualification requires at least one sampled column.');
        }
        $maximumRun = 1;
        $longRuns = 0;
        foreach ([[1, 0], [0, 1]] as [$stepX, $stepZ]) {
            for ($z = $minimum; $z <= $maximum; ++$z) {
                for ($x = $minimum; $x <= $maximum; ++$x) {
                    $previousX = $x - $stepX;
                    $previousZ = $z - $stepZ;
                    $height = $heights[$this->index($x, $z, $minimum, $width)];
                    if ($previousX >= $minimum && $previousZ >= $minimum
                        && $heights[$this->index($previousX, $previousZ, $minimum, $width)] === $height) {
                        continue;
                    }
                    $run = 0;
                    for ($runX = $x, $runZ = $z;
                        $runX <= $maximum && $runZ <= $maximum
                        && $heights[$this->index($runX, $runZ, $minimum, $width)] === $height;
                        $runX += $stepX, $runZ += $stepZ) {
                        ++$run;
                    }
                    $maximumRun = max($maximumRun, $run);
                    $longRuns += (int) ($run >= 16);
                }
            }
        }

        $correlations = [];
        foreach ([4, 8, 16] as $lag) {
            $correlations['x_lag_' . $lag] = $this->lagCorrelation($heights, $minimum, $maximum, $width, $lag, 0);
            $correlations['z_lag_' . $lag] = $this->lagCorrelation($heights, $minimum, $maximum, $width, 0, $lag);
        }

        return [
            'minimum_height' => min($heights),
            'maximum_height' => max($heights),
            'mean_height' => array_sum($heights) / count($heights),
            'maximum_cardinal_contour_run' => $maximumRun,
            'cardinal_contour_runs_at_least_16' => $longRuns,
            'height_lag_correlation' => $correlations,
            'axis_bias_at_16' => abs($correlations['x_lag_16'] - $correlations['z_lag_16']),
        ];
    }

    /** @param array<int, string> $surfaces
     *  @param array<int, int>    $heights
     *  @return array<string, mixed>
     */
    private function geologyMetrics(
        array $surfaces,
        array $heights,
        int $minimum,
        int $maximum,
        int $width,
    ): array {
        $stone = [];
        $gentleSlopeCells = 0;
        $gentleSlopeRockCells = 0;
        foreach ($surfaces as $index => $identifier) {
            if ($this->isExposedRock($identifier)) {
                $stone[$index] = true;
            }
        }
        for ($z = $minimum + 1; $z < $maximum; ++$z) {
            for ($x = $minimum + 1; $x < $maximum; ++$x) {
                $index = $this->index($x, $z, $minimum, $width);
                $height = $heights[$index];
                $maximumDelta = 0;
                foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$offsetX, $offsetZ]) {
                    $maximumDelta = max(
                        $maximumDelta,
                        abs($height - $heights[$this->index($x + $offsetX, $z + $offsetZ, $minimum, $width)]),
                    );
                }
                if ($maximumDelta <= 1) {
                    ++$gentleSlopeCells;
                    $gentleSlopeRockCells += (int) isset($stone[$index]);
                }
            }
        }
        $seen = [];
        $components = 0;
        $isolatedCells = 0;
        foreach (array_keys($stone) as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            ++$components;
            $queue = [$start];
            $seen[$start] = true;
            $size = 0;
            for ($cursor = 0; isset($queue[$cursor]); ++$cursor) {
                $cell = $queue[$cursor];
                ++$size;
                $localZ = intdiv($cell, $width);
                $localX = $cell % $width;
                foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$offsetX, $offsetZ]) {
                    $nextX = $localX + $offsetX;
                    $nextZ = $localZ + $offsetZ;
                    if ($nextX < 0 || $nextX >= $width || $nextZ < 0 || $nextZ >= $width) {
                        continue;
                    }
                    $next = $nextX + ($nextZ * $width);
                    if (isset($stone[$next]) && !isset($seen[$next])) {
                        $seen[$next] = true;
                        $queue[] = $next;
                    }
                }
            }
            if ($size <= 2) {
                $isolatedCells += $size;
            }
        }

        return [
            'exposed_rock_cells' => count($stone),
            'connected_rock_components' => $components,
            'isolated_rock_cells_size_at_most_2' => $isolatedCells,
            'isolated_rock_percent' => $stone === [] ? 0.0 : 100.0 * $isolatedCells / count($stone),
            'gentle_slope_cells' => $gentleSlopeCells,
            'gentle_slope_exposed_rock_cells' => $gentleSlopeRockCells,
            'gentle_slope_exposed_rock_percent' => $gentleSlopeCells === 0
                ? 0.0
                : 100.0 * $gentleSlopeRockCells / $gentleSlopeCells,
            'surface_materials' => $this->frequencies($surfaces),
            'material_runs' => $this->categoricalRunMetrics($surfaces, $minimum, $maximum, $width),
        ];
    }

    /** @param array<int, bool> $water
     *  @return array<string, int|float>
     */
    private function coastlineMetrics(array $water, int $minimum, int $maximum, int $width): array
    {
        $coast = [];
        $horizontalBoundaryEdges = 0;
        $verticalBoundaryEdges = 0;
        for ($z = $minimum; $z <= $maximum; ++$z) {
            for ($x = $minimum; $x <= $maximum; ++$x) {
                $index = $this->index($x, $z, $minimum, $width);
                if ($x < $maximum) {
                    $east = $this->index($x + 1, $z, $minimum, $width);
                    if ($water[$index] !== $water[$east]) {
                        ++$verticalBoundaryEdges;
                        $coast[$index] = true;
                        $coast[$east] = true;
                    }
                }
                if ($z < $maximum) {
                    $south = $this->index($x, $z + 1, $minimum, $width);
                    if ($water[$index] !== $water[$south]) {
                        ++$horizontalBoundaryEdges;
                        $coast[$index] = true;
                        $coast[$south] = true;
                    }
                }
            }
        }

        $maximumStraightRun = 0;
        foreach ([[1, 0], [0, 1]] as [$stepX, $stepZ]) {
            for ($z = $minimum; $z <= $maximum; ++$z) {
                for ($x = $minimum; $x <= $maximum; ++$x) {
                    $index = $this->index($x, $z, $minimum, $width);
                    if (!isset($coast[$index])) {
                        continue;
                    }
                    $previousX = $x - $stepX;
                    $previousZ = $z - $stepZ;
                    if ($previousX >= $minimum && $previousZ >= $minimum
                        && isset($coast[$this->index($previousX, $previousZ, $minimum, $width)])) {
                        continue;
                    }
                    $run = 0;
                    for ($runX = $x, $runZ = $z;
                        $runX <= $maximum && $runZ <= $maximum
                        && isset($coast[$this->index($runX, $runZ, $minimum, $width)]);
                        $runX += $stepX, $runZ += $stepZ) {
                        ++$run;
                    }
                    $maximumStraightRun = max($maximumStraightRun, $run);
                }
            }
        }

        $cornerCells = 0;
        foreach (array_keys($coast) as $index) {
            $localZ = intdiv($index, $width);
            $localX = $index % $width;
            $hasHorizontal = ($localX > 0 && isset($coast[$index - 1]))
                || ($localX + 1 < $width && isset($coast[$index + 1]));
            $hasVertical = ($localZ > 0 && isset($coast[$index - $width]))
                || ($localZ + 1 < $width && isset($coast[$index + $width]));
            $cornerCells += (int) ($hasHorizontal && $hasVertical);
        }
        $edges = $horizontalBoundaryEdges + $verticalBoundaryEdges;

        return [
            'boundary_edges' => $edges,
            'coastline_cells' => count($coast),
            'maximum_cardinal_straight_run' => $maximumStraightRun,
            'corner_cell_percent' => $coast === [] ? 0.0 : 100.0 * $cornerCells / count($coast),
            'orientation_bias' => $edges === 0
                ? 0.0
                : abs($horizontalBoundaryEdges - $verticalBoundaryEdges) / $edges,
        ];
    }

    /** @param array<int, string> $values
     *  @return array<string, int|float|array<string, float>>
     */
    private function categoricalRunMetrics(array $values, int $minimum, int $maximum, int $width): array
    {
        $maximumRun = 0;
        $runsAtLeast16 = 0;
        foreach ([[1, 0], [0, 1]] as [$stepX, $stepZ]) {
            for ($z = $minimum; $z <= $maximum; ++$z) {
                for ($x = $minimum; $x <= $maximum; ++$x) {
                    $index = $this->index($x, $z, $minimum, $width);
                    $value = $values[$index];
                    $previousX = $x - $stepX;
                    $previousZ = $z - $stepZ;
                    if ($previousX >= $minimum && $previousZ >= $minimum
                        && $values[$this->index($previousX, $previousZ, $minimum, $width)] === $value) {
                        continue;
                    }
                    $run = 0;
                    for ($runX = $x, $runZ = $z;
                        $runX <= $maximum && $runZ <= $maximum
                        && $values[$this->index($runX, $runZ, $minimum, $width)] === $value;
                        $runX += $stepX, $runZ += $stepZ) {
                        ++$run;
                    }
                    $maximumRun = max($maximumRun, $run);
                    $runsAtLeast16 += (int) ($run >= 16);
                }
            }
        }

        $frequencies = $this->frequencies($values);
        $expectedAgreement = 0.0;
        foreach ($frequencies as $count) {
            $share = $count / count($values);
            $expectedAgreement += $share * $share;
        }
        $lagAgreement = [];
        foreach ([4, 8, 16] as $lag) {
            $lagAgreement['x_lag_' . $lag] = $this->categoricalLagAgreement(
                $values,
                $minimum,
                $maximum,
                $width,
                $lag,
                0,
                $expectedAgreement,
            );
            $lagAgreement['z_lag_' . $lag] = $this->categoricalLagAgreement(
                $values,
                $minimum,
                $maximum,
                $width,
                0,
                $lag,
                $expectedAgreement,
            );
        }

        return [
            'maximum_cardinal_run' => $maximumRun,
            'cardinal_runs_at_least_16' => $runsAtLeast16,
            'normalized_lag_agreement' => $lagAgreement,
            'axis_bias_at_16' => abs($lagAgreement['x_lag_16'] - $lagAgreement['z_lag_16']),
        ];
    }

    /** @param array<int, string> $values */
    private function categoricalLagAgreement(
        array $values,
        int $minimum,
        int $maximum,
        int $width,
        int $lagX,
        int $lagZ,
        float $expectedAgreement,
    ): float {
        $pairs = 0;
        $matches = 0;
        for ($z = $minimum; $z + $lagZ <= $maximum; ++$z) {
            for ($x = $minimum; $x + $lagX <= $maximum; ++$x) {
                ++$pairs;
                $matches += (int) ($values[$this->index($x, $z, $minimum, $width)]
                    === $values[$this->index($x + $lagX, $z + $lagZ, $minimum, $width)]);
            }
        }
        if ($pairs === 0 || $expectedAgreement >= 1.0) {
            return 0.0;
        }

        return (($matches / $pairs) - $expectedAgreement) / (1.0 - $expectedAgreement);
    }

    /** @param array<int, int> $heights
     *  @param array<int, string> $biomes
     *  @return array<string, int|float>
     */
    private function biomeMetrics(array $heights, array $biomes, int $minimum, int $maximum, int $width): array
    {
        $deltas = [];
        for ($z = $minimum; $z <= $maximum; ++$z) {
            for ($x = $minimum; $x <= $maximum; ++$x) {
                $index = $this->index($x, $z, $minimum, $width);
                foreach ([[1, 0], [0, 1]] as [$offsetX, $offsetZ]) {
                    if ($x + $offsetX > $maximum || $z + $offsetZ > $maximum) {
                        continue;
                    }
                    $neighbor = $this->index($x + $offsetX, $z + $offsetZ, $minimum, $width);
                    if ($biomes[$index] !== $biomes[$neighbor]) {
                        $deltas[] = abs($heights[$index] - $heights[$neighbor]);
                    }
                }
            }
        }

        return [
            'adjacent_boundary_edges' => count($deltas),
            'mean_boundary_height_delta' => $deltas === [] ? 0.0 : array_sum($deltas) / count($deltas),
            'maximum_boundary_height_delta' => $deltas === [] ? 0 : max($deltas),
        ];
    }

    /** @param array<string, Chunk> $chunks
     *  @param array<int, int> $heights
     *  @return array<string, int|float>
     */
    private function caveMetrics(
        array $chunks,
        BlockStateRegistry $states,
        array $heights,
        int $minimum,
        int $maximum,
        int $width,
    ): array {
        $caves = [];
        $fluidOverAir = 0;
        for ($z = $minimum; $z <= $maximum; ++$z) {
            for ($x = $minimum; $x <= $maximum; ++$x) {
                $chunk = $this->chunkAt($chunks, $x, $z);
                $localX = $this->floorMod($x, 16);
                $localZ = $this->floorMod($z, 16);
                $surface = $heights[$this->index($x, $z, $minimum, $width)];
                for ($y = self::MIN_CAVE_Y; $y <= $surface - 2; ++$y) {
                    $identifier = $states->state($chunk->blockStateAt($localX, $y, $localZ))->identifier();
                    if ($identifier === 'minecraft:air') {
                        $cell = $this->caveIndex($x, $y, $z, $minimum, $width);
                        $caves[$cell] = true;
                    } elseif ($this->isFluid($identifier)) {
                        $below = $states->state($chunk->blockStateAt($localX, $y - 1, $localZ))->identifier();
                        $fluidOverAir += (int) ($below === 'minecraft:air');
                    }
                }
            }
        }

        $horizontalOpeningCells = 0;
        $horizontalOpeningColumns = [];
        foreach (array_keys($caves) as $cell) {
            [$x, $y, $z] = $this->decodeCaveIndex($cell, $minimum, $width);
            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$offsetX, $offsetZ]) {
                $neighborX = $x + $offsetX;
                $neighborZ = $z + $offsetZ;
                if ($neighborX < $minimum || $neighborX > $maximum
                    || $neighborZ < $minimum || $neighborZ > $maximum) {
                    continue;
                }
                $neighborSurface = $heights[$this->index($neighborX, $neighborZ, $minimum, $width)];
                if ($y > $neighborSurface) {
                    ++$horizontalOpeningCells;
                    $horizontalOpeningColumns[$x . ':' . $z] = true;
                    break;
                }
            }
        }

        $sinkholeCandidates = 0;
        for ($z = $minimum + 1; $z < $maximum; ++$z) {
            for ($x = $minimum + 1; $x < $maximum; ++$x) {
                $surface = $heights[$this->index($x, $z, $minimum, $width)];
                $higherNeighbors = 0;
                foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$offsetX, $offsetZ]) {
                    $neighbor = $heights[$this->index($x + $offsetX, $z + $offsetZ, $minimum, $width)];
                    $higherNeighbors += (int) ($neighbor >= $surface + 3);
                }
                if ($higherNeighbors < 3) {
                    continue;
                }
                $adjoinsCave = false;
                for ($sampleZ = $z - 1; $sampleZ <= $z + 1 && !$adjoinsCave; ++$sampleZ) {
                    for ($sampleX = $x - 1; $sampleX <= $x + 1 && !$adjoinsCave; ++$sampleX) {
                        for ($sampleY = max(self::MIN_CAVE_Y, $surface - 8); $sampleY <= $surface + 2; ++$sampleY) {
                            if (isset($caves[$this->caveIndex($sampleX, $sampleY, $sampleZ, $minimum, $width)])) {
                                $adjoinsCave = true;
                                break;
                            }
                        }
                    }
                }
                $sinkholeCandidates += (int) $adjoinsCave;
            }
        }

        $seen = [];
        $components = 0;
        $largest = 0;
        foreach (array_keys($caves) as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            ++$components;
            $queue = [$start];
            $seen[$start] = true;
            $size = 0;
            for ($cursor = 0; isset($queue[$cursor]); ++$cursor) {
                $cell = $queue[$cursor];
                ++$size;
                [$x, $y, $z] = $this->decodeCaveIndex($cell, $minimum, $width);
                foreach ([[-1, 0, 0], [1, 0, 0], [0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1]] as [$dx, $dy, $dz]) {
                    $nextX = $x + $dx;
                    $nextY = $y + $dy;
                    $nextZ = $z + $dz;
                    if ($nextX < $minimum || $nextX > $maximum || $nextZ < $minimum || $nextZ > $maximum
                        || $nextY < self::MIN_CAVE_Y || $nextY > Chunk::MAX_Y) {
                        continue;
                    }
                    $next = $this->caveIndex($nextX, $nextY, $nextZ, $minimum, $width);
                    if (isset($caves[$next]) && !isset($seen[$next])) {
                        $seen[$next] = true;
                        $queue[] = $next;
                    }
                }
            }
            $largest = max($largest, $size);
        }

        return [
            'air_cells_below_surface' => count($caves),
            'connected_components' => $components,
            'largest_component_cells' => $largest,
            'largest_component_percent' => $caves === [] ? 0.0 : 100.0 * $largest / count($caves),
            'entrance_columns' => count($horizontalOpeningColumns) + $sinkholeCandidates,
            'horizontal_opening_columns' => count($horizontalOpeningColumns),
            'horizontal_opening_cells' => $horizontalOpeningCells,
            'sinkhole_candidate_columns' => $sinkholeCandidates,
            'fluid_cells_directly_over_air' => $fluidOverAir,
        ];
    }

    /** @param array<string, Chunk> $chunks
     *  @param array<int, string>   $biomes
     *  @param array<int, bool>     $waterColumns
     *  @return array<string, mixed>
     */
    private function vegetationMetrics(
        array $chunks,
        BlockStateRegistry $states,
        array $biomes,
        array $waterColumns,
        int $minimum,
        int $maximum,
        int $width,
    ): array {
        $roots = [];
        $rootCells = [];
        $treeCells = [];
        $oceanTreeCells = [];
        for ($z = $minimum; $z <= $maximum; ++$z) {
            for ($x = $minimum; $x <= $maximum; ++$x) {
                $chunk = $this->chunkAt($chunks, $x, $z);
                $localX = $this->floorMod($x, 16);
                $localZ = $this->floorMod($z, 16);
                $columnIndex = $this->index($x, $z, $minimum, $width);
                $oceanColumn = $waterColumns[$columnIndex] || str_contains($biomes[$columnIndex], 'ocean');
                $rootY = null;
                for ($y = Chunk::MIN_Y + 1; $y <= Chunk::MAX_Y; ++$y) {
                    $state = $states->state($chunk->blockStateAt($localX, $y, $localZ));
                    $identifier = $state->identifier();
                    $log = $this->isLog($identifier);
                    if ($log || $this->isLeaf($identifier)) {
                        $treeCell = $this->blockIndex($x, $y, $z, $minimum, $width);
                        $treeCells[$treeCell] = $log ? 2 : 1;
                        if ($oceanColumn) {
                            $oceanTreeCells[$treeCell] = true;
                        }
                    }
                    if ($rootY === null && $log && ($state->properties()['pillar_axis'] ?? 'y') === 'y') {
                        $below = $states->state($chunk->blockStateAt($localX, $y - 1, $localZ))->identifier();
                        if ($this->isRootSubstrate($below)) {
                            $rootY = $y;
                        }
                    }
                }
                if ($rootY === null) {
                    continue;
                }
                $west = $x > $minimum
                    ? $states->state($this->chunkAt($chunks, $x - 1, $z)->blockStateAt(
                        $this->floorMod($x - 1, 16),
                        $rootY,
                        $localZ,
                    ))->identifier()
                    : '';
                $north = $z > $minimum
                    ? $states->state($this->chunkAt($chunks, $x, $z - 1)->blockStateAt(
                        $localX,
                        $rootY,
                        $this->floorMod($z - 1, 16),
                    ))->identifier()
                    : '';
                if (!$this->isLog($west) && !$this->isLog($north)) {
                    $roots[] = [$x, $rootY, $z];
                    $rootCells[$this->blockIndex($x, $rootY, $z, $minimum, $width)] = true;
                }
            }
        }

        $nearest = [];
        $signatures = [];
        foreach ($roots as $index => [$x, $y, $z]) {
            $nearestSquared = PHP_INT_MAX;
            foreach ($roots as $otherIndex => [$otherX, , $otherZ]) {
                if ($index === $otherIndex) {
                    continue;
                }
                $nearestSquared = min($nearestSquared, (($x - $otherX) ** 2) + (($z - $otherZ) ** 2));
            }
            if ($nearestSquared !== PHP_INT_MAX) {
                $nearest[] = sqrt((float) $nearestSquared);
            }
            $signatures[$this->treeSignature($chunks, $states, $x, $y, $z)] = true;
        }

        $seenTreeCells = [];
        $treeComponents = 0;
        $foliageOnlyComponents = 0;
        $unrootedLogComponents = 0;
        $orphanTreeCells = 0;
        $orphanOceanTreeCells = 0;
        $boundaryExcludedComponents = 0;
        foreach (array_keys($treeCells) as $start) {
            if (isset($seenTreeCells[$start])) {
                continue;
            }
            ++$treeComponents;
            $queue = [$start];
            $seenTreeCells[$start] = true;
            $componentCells = [];
            $hasLog = false;
            $hasRoot = false;
            $touchesBoundary = false;
            for ($cursor = 0; isset($queue[$cursor]); ++$cursor) {
                $cell = $queue[$cursor];
                $componentCells[] = $cell;
                [$cellX, $cellY, $cellZ] = $this->decodeBlockIndex($cell, $minimum, $width);
                $hasLog = $hasLog || $treeCells[$cell] === 2;
                $hasRoot = $hasRoot || isset($rootCells[$cell]);
                $touchesBoundary = $touchesBoundary
                    || $cellX === $minimum || $cellX === $maximum
                    || $cellZ === $minimum || $cellZ === $maximum;
                for ($offsetY = -1; $offsetY <= 1; ++$offsetY) {
                    for ($offsetZ = -1; $offsetZ <= 1; ++$offsetZ) {
                        for ($offsetX = -1; $offsetX <= 1; ++$offsetX) {
                            if (($offsetX === 0 && $offsetY === 0 && $offsetZ === 0)
                                || $cellX + $offsetX < $minimum || $cellX + $offsetX > $maximum
                                || $cellZ + $offsetZ < $minimum || $cellZ + $offsetZ > $maximum
                                || $cellY + $offsetY < Chunk::MIN_Y || $cellY + $offsetY > Chunk::MAX_Y) {
                                continue;
                            }
                            $neighbor = $this->blockIndex(
                                $cellX + $offsetX,
                                $cellY + $offsetY,
                                $cellZ + $offsetZ,
                                $minimum,
                                $width,
                            );
                            if (isset($treeCells[$neighbor]) && !isset($seenTreeCells[$neighbor])) {
                                $seenTreeCells[$neighbor] = true;
                                $queue[] = $neighbor;
                            }
                        }
                    }
                }
            }
            if ($touchesBoundary) {
                ++$boundaryExcludedComponents;
                continue;
            }
            if (!$hasLog) {
                ++$foliageOnlyComponents;
            } elseif (!$hasRoot) {
                ++$unrootedLogComponents;
            } else {
                continue;
            }
            $orphanTreeCells += count($componentCells);
            foreach ($componentCells as $cell) {
                $orphanOceanTreeCells += (int) isset($oceanTreeCells[$cell]);
            }
        }

        $biomeColumns = $this->frequencies($biomes);
        $biomeRoots = [];
        foreach ($roots as [$x, , $z]) {
            $biome = $biomes[$this->index($x, $z, $minimum, $width)];
            $biomeRoots[$biome] = ($biomeRoots[$biome] ?? 0) + 1;
        }
        $biomeDensity = [];
        foreach ($biomeColumns as $biome => $columns) {
            $biomeDensity[$biome] = [
                'sampled_columns' => $columns,
                'trunk_roots' => $biomeRoots[$biome] ?? 0,
                'trees_per_chunk_equivalent' => 256.0 * ($biomeRoots[$biome] ?? 0) / $columns,
            ];
        }

        $forestCells = 0;
        $forestClearingCells = 0;
        for ($cellZ = $minimum; $cellZ <= $maximum; $cellZ += 8) {
            for ($cellX = $minimum; $cellX <= $maximum; $cellX += 8) {
                $forestColumns = 0;
                foreach (range($cellZ, min($maximum, $cellZ + 7)) as $z) {
                    foreach (range($cellX, min($maximum, $cellX + 7)) as $x) {
                        $forestColumns += (int) $this->isForestBiome(
                            $biomes[$this->index($x, $z, $minimum, $width)],
                        );
                    }
                }
                if ($forestColumns < 48) {
                    continue;
                }
                ++$forestCells;
                $hasRoot = false;
                foreach ($roots as [$rootX, , $rootZ]) {
                    if ($rootX >= $cellX && $rootX <= $cellX + 7
                        && $rootZ >= $cellZ && $rootZ <= $cellZ + 7) {
                        $hasRoot = true;
                        break;
                    }
                }
                $forestClearingCells += (int) !$hasRoot;
            }
        }

        return [
            'trunk_roots' => count($roots),
            'trees_per_chunk' => count($roots) / count($chunks),
            'nearest_spacing_minimum' => $nearest === [] ? 0.0 : min($nearest),
            'nearest_spacing_mean' => $nearest === [] ? 0.0 : array_sum($nearest) / count($nearest),
            'unique_local_shape_percent' => $roots === [] ? 0.0 : 100.0 * count($signatures) / count($roots),
            'tree_block_components' => $treeComponents,
            'boundary_excluded_tree_components' => $boundaryExcludedComponents,
            'foliage_only_components' => $foliageOnlyComponents,
            'unrooted_log_components' => $unrootedLogComponents,
            'orphan_tree_cells' => $orphanTreeCells,
            'tree_cells_in_ocean_columns' => count($oceanTreeCells),
            'orphan_tree_cells_in_ocean_columns' => $orphanOceanTreeCells,
            'biome_density' => $biomeDensity,
            'forest_sample_cells_8x8' => $forestCells,
            'forest_clearing_cells_8x8' => $forestClearingCells,
            'forest_clearing_percent' => $forestCells === 0
                ? 0.0
                : 100.0 * $forestClearingCells / $forestCells,
        ];
    }

    /** @param array<int, int> $heights
     *  @return array<string, int|float>
     */
    private function seamMetrics(array $heights, int $minimum, int $maximum, int $width): array
    {
        $seamDeltas = [];
        $allDeltas = [];
        for ($z = $minimum; $z <= $maximum; ++$z) {
            for ($x = $minimum; $x <= $maximum; ++$x) {
                $current = $heights[$this->index($x, $z, $minimum, $width)];
                if ($x < $maximum) {
                    $delta = abs($current - $heights[$this->index($x + 1, $z, $minimum, $width)]);
                    $allDeltas[] = $delta;
                    if ($this->floorMod($x, 16) === 15) {
                        $seamDeltas[] = $delta;
                    }
                }
                if ($z < $maximum) {
                    $delta = abs($current - $heights[$this->index($x, $z + 1, $minimum, $width)]);
                    $allDeltas[] = $delta;
                    if ($this->floorMod($z, 16) === 15) {
                        $seamDeltas[] = $delta;
                    }
                }
            }
        }

        return [
            'maximum_chunk_boundary_delta' => $seamDeltas === [] ? 0 : max($seamDeltas),
            'mean_chunk_boundary_delta' => $seamDeltas === [] ? 0.0 : array_sum($seamDeltas) / count($seamDeltas),
            'mean_all_adjacent_delta' => $allDeltas === [] ? 0.0 : array_sum($allDeltas) / count($allDeltas),
        ];
    }

    /** @param array<int, int> $values */
    private function lagCorrelation(
        array $values,
        int $minimum,
        int $maximum,
        int $width,
        int $lagX,
        int $lagZ,
    ): float {
        $left = [];
        $right = [];
        for ($z = $minimum; $z + $lagZ <= $maximum; ++$z) {
            for ($x = $minimum; $x + $lagX <= $maximum; ++$x) {
                $left[] = $values[$this->index($x, $z, $minimum, $width)];
                $right[] = $values[$this->index($x + $lagX, $z + $lagZ, $minimum, $width)];
            }
        }
        $meanLeft = array_sum($left) / count($left);
        $meanRight = array_sum($right) / count($right);
        $numerator = 0.0;
        $leftSquared = 0.0;
        $rightSquared = 0.0;
        foreach ($left as $index => $value) {
            $leftDelta = $value - $meanLeft;
            $rightDelta = $right[$index] - $meanRight;
            $numerator += $leftDelta * $rightDelta;
            $leftSquared += $leftDelta ** 2;
            $rightSquared += $rightDelta ** 2;
        }
        $denominator = sqrt($leftSquared * $rightSquared);

        return $denominator === 0.0 ? 0.0 : $numerator / $denominator;
    }

    /** @param list<float> $values
     *  @return array<string, float>
     */
    private function distribution(array $values): array
    {
        if ($values === []) {
            throw new \LogicException('Latency qualification requires at least one sample.');
        }
        sort($values, SORT_NUMERIC);

        return [
            'minimum' => min($values),
            'mean' => array_sum($values) / count($values),
            'p50' => $values[(int) ceil((count($values) - 1) * 0.50)],
            'p95' => $values[(int) ceil((count($values) - 1) * 0.95)],
            'maximum' => max($values),
        ];
    }

    /** @param array<int, string> $values
     *  @return array<string, int>
     */
    private function frequencies(array $values): array
    {
        $frequencies = [];
        foreach ($values as $value) {
            $frequencies[$value] = ($frequencies[$value] ?? 0) + 1;
        }
        ksort($frequencies);

        return $frequencies;
    }

    /** @param array<string, Chunk> $chunks */
    private function treeSignature(
        array $chunks,
        BlockStateRegistry $states,
        int $rootX,
        int $rootY,
        int $rootZ,
    ): string {
        $shape = '';
        for ($y = $rootY; $y <= min(Chunk::MAX_Y, $rootY + 15); ++$y) {
            for ($z = $rootZ - 4; $z <= $rootZ + 4; ++$z) {
                for ($x = $rootX - 4; $x <= $rootX + 4; ++$x) {
                    $key = $this->floorDiv($x, 16) . ':' . $this->floorDiv($z, 16);
                    if (!isset($chunks[$key])) {
                        $shape .= '0';
                        continue;
                    }
                    $identifier = $states->state($chunks[$key]->blockStateAt(
                        $this->floorMod($x, 16),
                        $y,
                        $this->floorMod($z, 16),
                    ))->identifier();
                    $shape .= $this->isLog($identifier) ? 'L' : ($this->isLeaf($identifier) ? 'F' : '0');
                }
            }
        }

        return hash('xxh3', $shape);
    }

    /** @param array<string, Chunk> $chunks */
    private function chunkAt(array $chunks, int $x, int $z): Chunk
    {
        return $chunks[$this->floorDiv($x, 16) . ':' . $this->floorDiv($z, 16)];
    }

    /** @return array{int, string} */
    private function surface(Chunk $chunk, BlockStateRegistry $states, int $x, int $z): array
    {
        for ($y = Chunk::MAX_Y; $y >= Chunk::MIN_Y; --$y) {
            $identifier = $states->state($chunk->blockStateAt($x, $y, $z))->identifier();
            if (!$this->isSurfaceDecoration($identifier)) {
                return [$y, $identifier];
            }
        }

        return [Chunk::MIN_Y, 'minecraft:air'];
    }

    private function isSurfaceDecoration(string $identifier): bool
    {
        return $identifier === 'minecraft:air'
            || $this->isFluid($identifier)
            || $this->isLog($identifier)
            || $this->isLeaf($identifier)
            || str_contains($identifier, 'flower')
            || str_contains($identifier, 'fern')
            || str_contains($identifier, 'mushroom')
            || str_contains($identifier, 'sapling')
            || str_contains($identifier, 'vine')
            || in_array($identifier, [
                'minecraft:short_grass',
                'minecraft:tall_grass',
                'minecraft:deadbush',
                'minecraft:cactus',
                'minecraft:bamboo',
                'minecraft:snow_layer',
            ], true);
    }

    private function isExposedRock(string $identifier): bool
    {
        return str_contains($identifier, 'stone')
            || str_contains($identifier, 'gravel')
            || in_array($identifier, ['minecraft:andesite', 'minecraft:diorite', 'minecraft:granite', 'minecraft:tuff'], true);
    }

    private function isFluid(string $identifier): bool
    {
        return in_array($identifier, ['minecraft:water', 'minecraft:flowing_water', 'minecraft:lava', 'minecraft:flowing_lava'], true);
    }

    private function isLog(string $identifier): bool
    {
        return str_ends_with($identifier, '_log') || str_ends_with($identifier, '_stem')
            || str_ends_with($identifier, '_wood') || str_ends_with($identifier, '_hyphae');
    }

    private function isLeaf(string $identifier): bool
    {
        return str_ends_with($identifier, '_leaves') || $identifier === 'minecraft:azalea_leaves'
            || $identifier === 'minecraft:azalea_leaves_flowered';
    }

    private function isRootSubstrate(string $identifier): bool
    {
        return str_contains($identifier, 'dirt')
            || str_contains($identifier, 'grass')
            || str_contains($identifier, 'podzol')
            || str_contains($identifier, 'mycelium')
            || str_contains($identifier, 'moss')
            || str_contains($identifier, 'mud')
            || str_contains($identifier, 'sand')
            || str_contains($identifier, 'terracotta');
    }

    private function isForestBiome(string $identifier): bool
    {
        return str_contains($identifier, 'forest')
            || str_contains($identifier, 'taiga')
            || str_contains($identifier, 'jungle')
            || str_contains($identifier, 'grove')
            || str_contains($identifier, 'garden')
            || str_contains($identifier, 'swamp')
            || str_contains($identifier, 'trees');
    }

    private function signature(Chunk $chunk): string
    {
        return hash('sha256', serialize([$chunk->populatedSections(), $chunk->biomeStorages()]));
    }

    private function index(int $x, int $z, int $minimum, int $width): int
    {
        return ($x - $minimum) + (($z - $minimum) * $width);
    }

    private function caveIndex(int $x, int $y, int $z, int $minimum, int $width): int
    {
        return ($x - $minimum) + (($z - $minimum) * $width) + (($y - self::MIN_CAVE_Y) * $width * $width);
    }

    private function blockIndex(int $x, int $y, int $z, int $minimum, int $width): int
    {
        return ($x - $minimum) + (($z - $minimum) * $width) + (($y - Chunk::MIN_Y) * $width * $width);
    }

    /** @return array{int, int, int} */
    private function decodeBlockIndex(int $index, int $minimum, int $width): array
    {
        $layerSize = $width * $width;
        $y = intdiv($index, $layerSize) + Chunk::MIN_Y;
        $withinLayer = $index % $layerSize;
        $z = intdiv($withinLayer, $width) + $minimum;
        $x = ($withinLayer % $width) + $minimum;

        return [$x, $y, $z];
    }

    /** @return array{int, int, int} */
    private function decodeCaveIndex(int $index, int $minimum, int $width): array
    {
        $layerSize = $width * $width;
        $y = intdiv($index, $layerSize) + self::MIN_CAVE_Y;
        $withinLayer = $index % $layerSize;
        $z = intdiv($withinLayer, $width) + $minimum;
        $x = ($withinLayer % $width) + $minimum;

        return [$x, $y, $z];
    }

    private function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }

    private function floorMod(int $value, int $divisor): int
    {
        return (($value % $divisor) + $divisor) % $divisor;
    }
}
