<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\DefaultBlockPalette;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Generation\OverworldBiomeResolver;
use Bedriox\Server\World\Generation\OverworldClimate;
use Bedriox\Server\World\Generation\OverworldTerrainSample;
use Bedriox\Server\World\Generation\OverworldTerrainSampler;
use Bedriox\Server\World\Generation\SeededNoise;

/** Staged deterministic overworld generator with continental terrain and climate-driven biomes. */
final class DefaultWorldGenerator implements VersionedWorldGenerator
{
    public const int VERSION = 1;
    public const int SEA_LEVEL = OverworldTerrainSampler::SEA_LEVEL;

    private readonly SeededNoise $noise;
    private readonly OverworldTerrainSampler $terrain;
    private readonly OverworldBiomeResolver $biomes;
    /** @var list<InternalBlockStateId> */
    private readonly array $generationPalette;
    /** @var array<int, int> */
    private readonly array $paletteIndexByState;
    /** @var array<string, array{OverworldClimate, int}> */
    private array $columnCache = [];
    /** @var list<string> */
    private array $columnCacheOrder = [];
    private ?SpawnPosition $spawn = null;

    public function __construct(
        private readonly int $seed,
        private readonly DefaultBlockPalette $blocks,
    ) {
        $this->noise = new SeededNoise($seed);
        $this->terrain = new OverworldTerrainSampler($this->noise);
        $this->biomes = new OverworldBiomeResolver();
        $this->generationPalette = [
            $blocks->air, $blocks->bedrock, $blocks->stone, $blocks->dirt, $blocks->grassBlock,
            $blocks->sand, $blocks->sandstone, $blocks->gravel, $blocks->water, $blocks->coalOre,
            $blocks->ironOre, $blocks->oakLog, $blocks->oakLeaves, $blocks->clay, $blocks->ice,
            $blocks->snow, $blocks->coarseDirt, $blocks->podzol, $blocks->deepslate, $blocks->lava,
            $blocks->copperOre, $blocks->goldOre, $blocks->redstoneOre, $blocks->diamondOre,
            $blocks->birchLog, $blocks->birchLeaves, $blocks->spruceLog, $blocks->spruceLeaves,
        ];
        $indices = [];
        foreach ($this->generationPalette as $index => $state) {
            $indices[$state->value] = $index;
        }
        $this->paletteIndexByState = $indices;
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
        $terrainSamples = $this->terrainRegion($position, 3);
        /** @var array<int, string> $cells */
        $cells = [];
        $biomeColumns = [];

        for ($localZ = 0; $localZ < 16; ++$localZ) {
            for ($localX = 0; $localX < 16; ++$localX) {
                $worldX = $originX + $localX;
                $worldZ = $originZ + $localZ;
                $sample = $terrainSamples[self::coordinateKey($worldX, $worldZ)];
                $biome = $this->biomes->resolve($sample);
                $biomeColumns[] = $biome;
                $this->generateColumn($cells, $localX, $localZ, $worldX, $worldZ, $sample, $biome);
            }
        }
        $this->populateOres($cells, $position);
        $this->populateBoulders($cells, $position, $terrainSamples);
        $this->populateTrees($cells, $position, $terrainSamples);

        $sections = [];
        ksort($cells, SORT_NUMERIC);
        foreach ($cells as $sectionY => $states) {
            $sections[] = SubChunk::fromBlockStorageLayers(
                $sectionY,
                [$this->compactStorage($states)],
            );
        }
        $biomeStorage = BiomeStorage::fromColumns($biomeColumns);
        $biomeStorages = array_fill(Chunk::MIN_SECTION_Y, Chunk::SECTION_COUNT, $biomeStorage);

        return new Chunk(
            $position,
            $this->blocks->air,
            $sections,
            $biomeColumns[0],
            finalizationState: ChunkFinalizationState::Done,
            biomeStorages: $biomeStorages,
        );
    }

    public function defaultSpawn(): SpawnPosition
    {
        if ($this->spawn !== null) {
            return $this->spawn;
        }
        for ($radius = 0; $radius <= 512; $radius += 8) {
            for ($z = -$radius; $z <= $radius; $z += 8) {
                for ($x = -$radius; $x <= $radius; $x += 8) {
                    if ($radius !== 0 && abs($x) !== $radius && abs($z) !== $radius) {
                        continue;
                    }
                    $sample = $this->terrain->sample($x, $z);
                    $biome = $this->biomes->resolve($sample)->identifier;
                    if ($sample->surfaceHeight <= self::SEA_LEVEL + 1 || $sample->slope > 3
                        || !in_array($biome, [
                            'minecraft:plains', 'minecraft:forest', 'minecraft:birch_forest',
                            'minecraft:taiga', 'minecraft:savanna',
                        ], true)) {
                        continue;
                    }

                    return $this->spawn = new SpawnPosition($x, $sample->surfaceHeight + 1, $z);
                }
            }
        }

        return $this->spawn = new SpawnPosition(0, $this->terrain->heightAt(0, 0) + 1, 0);
    }

    public function surfaceHeight(int $x, int $z, ?Biome $biome = null): int
    {
        return $this->terrain->heightAt($x, $z);
    }

    public function biomeAt(int $x, int $z): Biome
    {
        return $this->biomes->resolve($this->terrain->sample($x, $z));
    }

    /** @param array<int, string> $cells */
    private function generateColumn(
        array &$cells,
        int $localX,
        int $localZ,
        int $worldX,
        int $worldZ,
        OverworldTerrainSample $sample,
        Biome $biome,
    ): void {
        $surface = $sample->surfaceHeight;
        $this->put($cells, $localX, Chunk::MIN_Y, $localZ, $this->blocks->bedrock);
        for ($y = Chunk::MIN_Y + 1; $y < Chunk::MIN_Y + 5; ++$y) {
            if ($this->noise->chance($worldX, $y, $worldZ, 1_009, 5) >= $y - Chunk::MIN_Y) {
                $this->put($cells, $localX, $y, $localZ, $this->blocks->bedrock);
            }
        }
        for ($y = Chunk::MIN_Y + 4; $y <= $surface; ++$y) {
            $this->put($cells, $localX, $y, $localZ, $y < 0 ? $this->blocks->deepslate : $this->blocks->stone);
        }
        $this->carveCaves($cells, $localX, $localZ, $worldX, $worldZ, $surface);
        $this->applySurface($cells, $localX, $localZ, $worldX, $worldZ, $surface, $sample, $biome);

        for ($y = $surface + 1; $y <= self::SEA_LEVEL; ++$y) {
            $state = $y === self::SEA_LEVEL && $sample->climate->temperature < -10_000
                ? $this->blocks->ice
                : $this->blocks->water;
            $this->put($cells, $localX, $y, $localZ, $state);
        }
    }

    /** @param array<int, string> $cells */
    private function applySurface(
        array &$cells,
        int $localX,
        int $localZ,
        int $worldX,
        int $worldZ,
        int $surface,
        OverworldTerrainSample $sample,
        Biome $biome,
    ): void {
        $identifier = $biome->identifier;
        $top = $this->blocks->grassBlock;
        $filler = $this->blocks->dirt;
        $depth = 3 + $this->noise->chance($worldX, 0, $worldZ, 1_103, 3);
        if (in_array($identifier, ['minecraft:desert', 'minecraft:beach'], true)) {
            $top = $this->blocks->sand;
            $filler = $this->blocks->sand;
            for ($y = $surface - $depth - 3; $y < $surface - $depth; ++$y) {
                $this->put($cells, $localX, $y, $localZ, $this->blocks->sandstone);
            }
        } elseif (in_array($identifier, ['minecraft:stone_beach', 'minecraft:jagged_peaks'], true)
            || ($identifier === 'minecraft:extreme_hills' && $sample->slope >= 6)) {
            $top = $this->blocks->stone;
            $filler = $this->blocks->stone;
            $depth = 2;
        } elseif ($identifier === 'minecraft:river') {
            $top = $this->blocks->gravel;
            $filler = $this->noise->chance($worldX, 0, $worldZ, 1_109, 3) === 0
                ? $this->blocks->clay
                : $this->blocks->sand;
        } elseif (in_array($identifier, ['minecraft:ocean', 'minecraft:deep_ocean'], true)) {
            $choice = $this->noise->chance($worldX, 0, $worldZ, 1_123, 5);
            $top = $choice === 0 ? $this->blocks->clay : ($choice <= 2 ? $this->blocks->gravel : $this->blocks->sand);
            $filler = $choice === 0 ? $this->blocks->clay : $this->blocks->sand;
        } elseif ($identifier === 'minecraft:taiga') {
            $top = $this->noise->chance($worldX, 0, $worldZ, 1_127, 4) === 0
                ? $this->blocks->coarseDirt
                : $this->blocks->podzol;
        } elseif (in_array($identifier, ['minecraft:ice_plains', 'minecraft:snowy_slopes'], true)) {
            $top = $this->blocks->snow;
        }

        for ($y = max(Chunk::MIN_Y + 5, $surface - $depth); $y < $surface; ++$y) {
            $this->put($cells, $localX, $y, $localZ, $filler);
        }
        $this->put($cells, $localX, $surface, $localZ, $top);
    }

    /** @param array<int, string> $cells */
    private function carveCaves(array &$cells, int $localX, int $localZ, int $worldX, int $worldZ, int $surface): void
    {
        $tunnels = [
            [$this->noise->fractal2d($worldX, $worldZ, 36, 2, 52, 1_301), 18 + intdiv($this->noise->fractal2d($worldX, $worldZ, 115, 2, 55, 1_303) * 30, 32_768), 2],
            [$this->noise->fractal2d($worldX, $worldZ, 29, 2, 50, 1_307), -28 + intdiv($this->noise->fractal2d($worldX, $worldZ, 92, 2, 54, 1_309) * 20, 32_768), 2],
        ];
        foreach ($tunnels as [$mask, $center, $baseRadius]) {
            if (abs($mask) >= 2_300 || $center > $surface - 6) {
                continue;
            }
            $radius = $baseRadius + max(0, intdiv(2_300 - abs($mask), 900));
            for ($y = max(Chunk::MIN_Y + 6, $center - $radius); $y <= min($surface - 5, $center + $radius); ++$y) {
                $fluid = $y <= -49
                    ? $this->blocks->lava
                    : ($y < 18 && $this->noise->sample2d($worldX, $worldZ, 70, 1_319) > 18_000
                        ? $this->blocks->water
                        : $this->blocks->air);
                $this->put($cells, $localX, $y, $localZ, $fluid);
            }
        }
    }

    /** @param array<int, string> $cells */
    private function populateOres(array &$cells, ChunkPosition $position): void
    {
        $ores = [
            [$this->blocks->coalOre, 10, -8, 128, 10, 1_501],
            [$this->blocks->ironOre, 9, -48, 80, 9, 1_601],
            [$this->blocks->copperOre, 7, -16, 96, 8, 1_701],
            [$this->blocks->goldOre, 4, -56, 32, 7, 1_801],
            [$this->blocks->redstoneOre, 5, -60, 8, 7, 1_901],
            [$this->blocks->diamondOre, 3, -60, -8, 5, 2_003],
        ];
        $regionX = self::floorDiv($position->x * 16, 32);
        $regionZ = self::floorDiv($position->z * 16, 32);
        foreach ($ores as [$ore, $attempts, $minY, $maxY, $length, $salt]) {
            for ($rz = $regionZ - 1; $rz <= $regionZ + 1; ++$rz) {
                for ($rx = $regionX - 1; $rx <= $regionX + 1; ++$rx) {
                    for ($attempt = 0; $attempt < $attempts; ++$attempt) {
                        $x = $rx * 32 + $this->noise->chance($rx, $attempt, $rz, $salt, 32);
                        $z = $rz * 32 + $this->noise->chance($rx, $attempt, $rz, $salt + 1, 32);
                        $y = $minY + $this->noise->chance($rx, $attempt, $rz, $salt + 2, $maxY - $minY + 1);
                        $dx = $this->noise->chance($rx, $attempt, $rz, $salt + 3, 3) - 1;
                        $dy = $this->noise->chance($rx, $attempt, $rz, $salt + 4, 3) - 1;
                        $dz = $this->noise->chance($rx, $attempt, $rz, $salt + 5, 3) - 1;
                        if ($dx === 0 && $dy === 0 && $dz === 0) {
                            $dx = 1;
                        }
                        for ($step = 0; $step < $length; ++$step) {
                            $this->replaceNaturalStone($cells, $position, $x, $y, $z, $ore);
                            if ($step % 3 === 1) {
                                $this->replaceNaturalStone($cells, $position, $x + 1, $y, $z, $ore);
                                $this->replaceNaturalStone($cells, $position, $x, $y, $z + 1, $ore);
                            }
                            $x += $dx;
                            $z += $dz;
                            if ($step % 2 === 1) {
                                $y += $dy;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<int, string>                      $cells
     * @param array<string, OverworldTerrainSample> $terrainSamples
     */
    private function populateTrees(array &$cells, ChunkPosition $position, array $terrainSamples): void
    {
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        $spawn = $this->defaultSpawn();
        for ($anchorZ = $originZ - 3; $anchorZ <= $originZ + 18; ++$anchorZ) {
            for ($anchorX = $originX - 3; $anchorX <= $originX + 18; ++$anchorX) {
                if (abs($anchorX - $spawn->x) <= 3 && abs($anchorZ - $spawn->z) <= 3) {
                    continue;
                }
                $sample = $terrainSamples[self::coordinateKey($anchorX, $anchorZ)];
                $biome = $this->biomes->resolve($sample)->identifier;
                [$outOf, $log, $leaves] = match ($biome) {
                    'minecraft:forest' => [24, $this->blocks->oakLog, $this->blocks->oakLeaves],
                    'minecraft:birch_forest' => [22, $this->blocks->birchLog, $this->blocks->birchLeaves],
                    'minecraft:taiga' => [20, $this->blocks->spruceLog, $this->blocks->spruceLeaves],
                    'minecraft:plains' => [180, $this->blocks->oakLog, $this->blocks->oakLeaves],
                    'minecraft:savanna' => [90, $this->blocks->oakLog, $this->blocks->oakLeaves],
                    default => [0, $this->blocks->oakLog, $this->blocks->oakLeaves],
                };
                if ($outOf === 0 || $this->noise->chance($anchorX, 0, $anchorZ, 2_101, $outOf) !== 0) {
                    continue;
                }
                $ground = $sample->surfaceHeight;
                if ($ground <= self::SEA_LEVEL + 1) {
                    continue;
                }
                $height = 5 + $this->noise->chance($anchorX, 0, $anchorZ, 2_111, $biome === 'minecraft:taiga' ? 5 : 3);
                for ($y = $ground + 1; $y <= $ground + $height; ++$y) {
                    $this->putWorld($cells, $position, $anchorX, $y, $anchorZ, $log, false);
                }
                $top = $ground + $height;
                for ($y = $top - 3; $y <= $top + 1; ++$y) {
                    $radius = $biome === 'minecraft:taiga' ? max(1, 3 - intdiv($y - ($top - 3), 2)) : ($y >= $top ? 1 : 2);
                    for ($z = $anchorZ - $radius; $z <= $anchorZ + $radius; ++$z) {
                        for ($x = $anchorX - $radius; $x <= $anchorX + $radius; ++$x) {
                            if (abs($x - $anchorX) === $radius && abs($z - $anchorZ) === $radius
                                && $this->noise->chance($x, $y, $z, 2_117, 3) === 0) {
                                continue;
                            }
                            $this->putWorld($cells, $position, $x, $y, $z, $leaves, true);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<int, string>                      $cells
     * @param array<string, OverworldTerrainSample> $terrainSamples
     */
    private function populateBoulders(array &$cells, ChunkPosition $position, array $terrainSamples): void
    {
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        $spawn = $this->defaultSpawn();
        for ($anchorZ = $originZ - 2; $anchorZ <= $originZ + 17; ++$anchorZ) {
            for ($anchorX = $originX - 2; $anchorX <= $originX + 17; ++$anchorX) {
                if (abs($anchorX - $spawn->x) <= 3 && abs($anchorZ - $spawn->z) <= 3) {
                    continue;
                }
                $sample = $terrainSamples[self::coordinateKey($anchorX, $anchorZ)];
                $biome = $this->biomes->resolve($sample)->identifier;
                if (!in_array($biome, ['minecraft:extreme_hills', 'minecraft:stone_beach'], true)
                    || $this->noise->chance($anchorX, 0, $anchorZ, 2_201, 150) !== 0) {
                    continue;
                }
                $ground = $sample->surfaceHeight;
                for ($y = $ground; $y <= $ground + 2; ++$y) {
                    for ($z = $anchorZ - 1; $z <= $anchorZ + 1; ++$z) {
                        for ($x = $anchorX - 1; $x <= $anchorX + 1; ++$x) {
                            if (abs($x - $anchorX) + abs($z - $anchorZ) + ($y - $ground) <= 2) {
                                $this->putWorld($cells, $position, $x, $y, $z, $this->blocks->stone, false);
                            }
                        }
                    }
                }
            }
        }
    }

    /** @param array<int, string> $cells */
    private function replaceNaturalStone(array &$cells, ChunkPosition $chunk, int $x, int $y, int $z, InternalBlockStateId $replacement): void
    {
        $localX = $x - $chunk->x * 16;
        $localZ = $z - $chunk->z * 16;
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16 || $y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
            return;
        }
        $current = $this->stateIndex($cells, $localX, $y, $localZ);
        if ($current === $this->paletteIndexByState[$this->blocks->stone->value]
            || $current === $this->paletteIndexByState[$this->blocks->deepslate->value]) {
            $this->put($cells, $localX, $y, $localZ, $replacement);
        }
    }

    /** @param array<int, string> $cells */
    private function putWorld(array &$cells, ChunkPosition $chunk, int $x, int $y, int $z, InternalBlockStateId $state, bool $onlyAir): void
    {
        $localX = $x - $chunk->x * 16;
        $localZ = $z - $chunk->z * 16;
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16 || $y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
            return;
        }
        if ($onlyAir && $this->stateIndex($cells, $localX, $y, $localZ)
            !== $this->paletteIndexByState[$this->blocks->air->value]) {
            return;
        }
        $this->put($cells, $localX, $y, $localZ, $state);
    }

    /** @param array<int, string> $cells */
    private function stateIndex(array $cells, int $x, int $y, int $z): int
    {
        $sectionY = $y >> 4;
        if (!isset($cells[$sectionY])) {
            return $this->paletteIndexByState[$this->blocks->air->value];
        }

        return ord($cells[$sectionY][$x + $z * 16 + (($y & 0x0f) * 256)]);
    }

    /** @param array<int, string> $cells */
    private function put(array &$cells, int $x, int $y, int $z, InternalBlockStateId $state): void
    {
        if ($y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
            return;
        }
        $sectionY = $y >> 4;
        if (!isset($cells[$sectionY])) {
            $cells[$sectionY] = str_repeat(chr($this->paletteIndexByState[$this->blocks->air->value]), SubChunkBlockStorage::BLOCK_COUNT);
        }
        $cells[$sectionY][$x + $z * 16 + (($y & 0x0f) * 256)] = chr($this->paletteIndexByState[$state->value]);
    }

    private function compactStorage(string $states): SubChunkBlockStorage
    {
        $used = [];
        for ($offset = 0; $offset < SubChunkBlockStorage::BLOCK_COUNT; ++$offset) {
            $used[ord($states[$offset])] = true;
        }
        $sourceIndices = array_keys($used);
        sort($sourceIndices, SORT_NUMERIC);
        $palette = [];
        $translation = [];
        foreach ($sourceIndices as $targetIndex => $sourceIndex) {
            $palette[] = $this->generationPalette[$sourceIndex];
            $translation[$sourceIndex] = $targetIndex;
        }
        $compacted = '';
        for ($offset = 0; $offset < SubChunkBlockStorage::BLOCK_COUNT; ++$offset) {
            $compacted .= chr($translation[ord($states[$offset])]);
        }

        return SubChunkBlockStorage::fromPaletteIndices($palette, $compacted);
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
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
                $key = self::coordinateKey($x, $z);
                [$climate] = $this->baseColumn($x, $z);
                $coarse[$key] = $climate;
            }
        }
        for ($z = $minimumZ; $z <= $maximumZ; ++$z) {
            for ($x = $minimumX; $x <= $maximumX; ++$x) {
                $key = self::coordinateKey($x, $z);
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
                $height = $this->terrain->surfaceHeight($climate);
                $climates[$key] = $climate;
                $heights[$key] = $height;
            }
        }
        $samples = [];
        for ($z = $originZ - $margin; $z <= $originZ + 15 + $margin; ++$z) {
            for ($x = $originX - $margin; $x <= $originX + 15 + $margin; ++$x) {
                $key = self::coordinateKey($x, $z);
                $height = $heights[$key];
                $slope = max(
                    abs($height - $heights[self::coordinateKey($x + 2, $z)]),
                    abs($height - $heights[self::coordinateKey($x - 2, $z)]),
                    abs($height - $heights[self::coordinateKey($x, $z + 2)]),
                    abs($height - $heights[self::coordinateKey($x, $z - 2)]),
                );
                $samples[$key] = new OverworldTerrainSample(
                    $climates[$key],
                    $height,
                    $slope,
                    $this->terrain->riverStrength($climates[$key]),
                );
            }
        }

        return $samples;
    }

    private static function coordinateKey(int $x, int $z): string
    {
        return $x . ':' . $z;
    }

    /** @return array{OverworldClimate, int} */
    private function baseColumn(int $x, int $z): array
    {
        $key = self::coordinateKey($x, $z);
        $cached = $this->columnCache[$key] ?? null;
        if ($cached !== null) {
            return $cached;
        }
        $climate = $this->terrain->climateAt($x, $z);
        $value = [$climate, $this->terrain->surfaceHeight($climate)];
        $this->columnCache[$key] = $value;
        $this->columnCacheOrder[] = $key;
        if (count($this->columnCacheOrder) > 32_768) {
            foreach (array_splice($this->columnCacheOrder, 0, 4_096) as $expired) {
                unset($this->columnCache[$expired]);
            }
        }

        return $value;
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

    private static function bilinear(int $northWest, int $northEast, int $southWest, int $southEast, int $x, int $z): int
    {
        $north = $northWest + intdiv(($northEast - $northWest) * $x, 4);
        $south = $southWest + intdiv(($southEast - $southWest) * $x, 4);

        return $north + intdiv(($south - $north) * $z, 4);
    }
}
