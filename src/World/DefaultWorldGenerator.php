<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\DefaultBlockPalette;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Generation\SeededNoise;

/** Deterministic, bounded overworld generator with terrain, water, caves, ores, and forests. */
final class DefaultWorldGenerator implements WorldGenerator
{
    public const int SEA_LEVEL = 62;
    private const int MIN_SURFACE = 42;
    private const int MAX_SURFACE = 108;

    private readonly SeededNoise $noise;
    /** @var list<InternalBlockStateId> */
    private readonly array $generationPalette;
    /** @var array<int, int> */
    private readonly array $paletteIndexByState;
    private ?SpawnPosition $spawn = null;

    public function __construct(
        private readonly int $seed,
        private readonly DefaultBlockPalette $blocks,
    ) {
        $this->noise = new SeededNoise($seed);
        $this->generationPalette = [
            $blocks->air, $blocks->bedrock, $blocks->stone, $blocks->dirt, $blocks->grassBlock,
            $blocks->sand, $blocks->sandstone, $blocks->gravel, $blocks->water, $blocks->coalOre,
            $blocks->ironOre, $blocks->oakLog, $blocks->oakLeaves,
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

    public function generate(ChunkPosition $position): Chunk
    {
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        /** @var array<int, string> $cells */
        $cells = [];
        $biomeColumns = [];

        for ($localZ = 0; $localZ < 16; ++$localZ) {
            for ($localX = 0; $localX < 16; ++$localX) {
                $worldX = $originX + $localX;
                $worldZ = $originZ + $localZ;
                $biome = $this->biomeAt($worldX, $worldZ);
                $biomeColumns[] = $biome;
                $surface = $this->surfaceHeight($worldX, $worldZ, $biome);
                $this->generateColumn($cells, $localX, $localZ, $worldX, $worldZ, $surface, $biome);
            }
        }
        $this->populateOres($cells, $position);
        $this->populateTrees($cells, $position);

        $sections = [];
        ksort($cells, SORT_NUMERIC);
        foreach ($cells as $sectionY => $states) {
            $sections[] = SubChunk::fromBlockStorageLayers(
                $sectionY,
                [SubChunkBlockStorage::fromPaletteIndices($this->generationPalette, $states)],
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
        for ($radius = 0; $radius <= 64; ++$radius) {
            for ($z = -$radius; $z <= $radius; ++$z) {
                for ($x = -$radius; $x <= $radius; ++$x) {
                    if ($radius !== 0 && abs($x) !== $radius && abs($z) !== $radius) {
                        continue;
                    }
                    $biome = $this->biomeAt($x, $z);
                    $surface = $this->surfaceHeight($x, $z, $biome);
                    if ($surface >= self::SEA_LEVEL && $biome->identifier !== Biome::ocean()->identifier) {
                        return $this->spawn = new SpawnPosition($x, $surface + 1, $z);
                    }
                }
            }
        }

        return $this->spawn = new SpawnPosition(0, self::SEA_LEVEL + 1, 0);
    }

    public function surfaceHeight(int $x, int $z, ?Biome $biome = null): int
    {
        $biome ??= $this->biomeAt($x, $z);
        $continental = intdiv($this->noise->sample2d($x, $z, 128, 11) * 18, 32_768);
        $regional = intdiv($this->noise->sample2d($x, $z, 48, 23) * 10, 32_768);
        $detail = intdiv($this->noise->sample2d($x, $z, 18, 37) * 4, 32_768);
        $offset = match ($biome->identifier) {
            'minecraft:ocean' => -14,
            'minecraft:extreme_hills' => 16 + abs(intdiv($regional, 2)),
            default => 0,
        };

        return max(self::MIN_SURFACE, min(self::MAX_SURFACE, 64 + $continental + $regional + $detail + $offset));
    }

    public function biomeAt(int $x, int $z): Biome
    {
        $continental = $this->noise->sample2d($x, $z, 160, 101);
        if ($continental < -15_000) {
            return Biome::ocean();
        }
        $temperature = $this->noise->sample2d($x, $z, 112, 211);
        $moisture = $this->noise->sample2d($x, $z, 96, 307);
        $relief = $this->noise->sample2d($x, $z, 144, 401);
        if ($temperature > 11_000 && $moisture < -2_000) {
            return Biome::desert();
        }
        if ($relief > 16_000) {
            return Biome::hills();
        }
        if ($moisture > 5_000) {
            return Biome::forest();
        }

        return Biome::plains();
    }

    /** @param array<int, string> $cells */
    private function generateColumn(
        array &$cells,
        int $localX,
        int $localZ,
        int $worldX,
        int $worldZ,
        int $surface,
        Biome $biome,
    ): void {
        $this->put($cells, $localX, Chunk::MIN_Y, $localZ, $this->blocks->bedrock);
        for ($y = Chunk::MIN_Y + 1; $y < Chunk::MIN_Y + 4; ++$y) {
            if ($this->noise->chance($worldX, $y, $worldZ, 503, 4) >= $y - Chunk::MIN_Y) {
                $this->put($cells, $localX, $y, $localZ, $this->blocks->bedrock);
            }
        }
        $coverDepth = $biome->identifier === 'minecraft:desert' ? 7 : 3;
        $stoneTop = $surface - $coverDepth - 1;
        for ($y = Chunk::MIN_Y + 4; $y <= $stoneTop; ++$y) {
            $this->put($cells, $localX, $y, $localZ, $this->blocks->stone);
        }
        if ($biome->identifier === 'minecraft:desert') {
            for ($y = $stoneTop + 1; $y <= $surface - 4; ++$y) {
                $this->put($cells, $localX, $y, $localZ, $this->blocks->sandstone);
            }
            for ($y = $surface - 3; $y <= $surface; ++$y) {
                $this->put($cells, $localX, $y, $localZ, $this->blocks->sand);
            }
        } else {
            for ($y = $surface - 3; $y < $surface; ++$y) {
                $this->put($cells, $localX, $y, $localZ, $this->blocks->dirt);
            }
            $surfaceState = $biome->identifier === 'minecraft:ocean' && $surface < self::SEA_LEVEL - 5
                ? $this->blocks->gravel
                : $this->blocks->grassBlock;
            $this->put($cells, $localX, $surface, $localZ, $surfaceState);
        }
        for ($y = $surface + 1; $y <= self::SEA_LEVEL; ++$y) {
            $this->put($cells, $localX, $y, $localZ, $this->blocks->water);
        }
        $center = $this->caveCenter($worldX, $worldZ, $surface);
        if ($center !== null) {
            for ($y = $center - 2; $y <= $center + 2; ++$y) {
                $this->put(
                    $cells,
                    $localX,
                    $y,
                    $localZ,
                    $y <= 10 ? $this->blocks->water : $this->blocks->air,
                );
            }
        }
    }

    private function caveCenter(int $x, int $z, int $surface): ?int
    {
        $center = 18 + intdiv($this->noise->sample2d($x, $z, 44, 701) * 22, 32_768);
        $mask = $this->noise->sample2d($x, $z, 20, 709);

        return $mask > 8_000 && $center >= Chunk::MIN_Y + 5 && $center <= min(72, $surface - 5)
            ? $center
            : null;
    }

    /** @param array<int, string> $cells */
    private function populateOres(array &$cells, ChunkPosition $position): void
    {
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        foreach ([[$this->blocks->coalOre, 28, 96, 901], [$this->blocks->ironOre, 18, 64, 907]] as [$ore, $attempts, $maxY, $salt]) {
            for ($attempt = 0; $attempt < $attempts; ++$attempt) {
                $localX = $this->noise->chance($position->x, $attempt, $position->z, $salt, 16);
                $localZ = $this->noise->chance($position->x, $attempt, $position->z, $salt + 1, 16);
                $y = Chunk::MIN_Y + 5 + $this->noise->chance(
                    $originX + $localX,
                    $attempt,
                    $originZ + $localZ,
                    $salt + 2,
                    $maxY - Chunk::MIN_Y - 4,
                );
                $sectionY = $y >> 4;
                $offset = $localX + $localZ * 16 + (($y & 0x0f) * 256);
                if (isset($cells[$sectionY])
                    && ord($cells[$sectionY][$offset]) === $this->paletteIndexByState[$this->blocks->stone->value]) {
                    $this->put($cells, $localX, $y, $localZ, $ore);
                }
            }
        }
    }

    /** @param array<int, string> $cells */
    private function populateTrees(array &$cells, ChunkPosition $position): void
    {
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        for ($anchorZ = $originZ - 2; $anchorZ <= $originZ + 17; ++$anchorZ) {
            for ($anchorX = $originX - 2; $anchorX <= $originX + 17; ++$anchorX) {
                if ($this->biomeAt($anchorX, $anchorZ)->identifier !== 'minecraft:forest'
                    || $this->noise->chance($anchorX, 0, $anchorZ, 809, 48) !== 0) {
                    continue;
                }
                $ground = $this->surfaceHeight($anchorX, $anchorZ, Biome::forest());
                if ($ground < self::SEA_LEVEL) {
                    continue;
                }
                $height = 4 + $this->noise->chance($anchorX, 0, $anchorZ, 811, 3);
                for ($y = $ground + 1; $y <= $ground + $height; ++$y) {
                    $this->putWorld($cells, $position, $anchorX, $y, $anchorZ, $this->blocks->oakLog, false);
                }
                for ($y = $ground + $height - 2; $y <= $ground + $height + 1; ++$y) {
                    $radius = $y === $ground + $height + 1 ? 1 : 2;
                    for ($z = $anchorZ - $radius; $z <= $anchorZ + $radius; ++$z) {
                        for ($x = $anchorX - $radius; $x <= $anchorX + $radius; ++$x) {
                            if (abs($x - $anchorX) === $radius && abs($z - $anchorZ) === $radius
                                && $this->noise->chance($x, $y, $z, 821, 2) === 0) {
                                continue;
                            }
                            $this->putWorld($cells, $position, $x, $y, $z, $this->blocks->oakLeaves, true);
                        }
                    }
                }
            }
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
        $sectionY = $y >> 4;
        $offset = $localX + $localZ * 16 + (($y & 0x0f) * 256);
        if ($onlyAir && isset($cells[$sectionY])
            && ord($cells[$sectionY][$offset]) !== $this->paletteIndexByState[$this->blocks->air->value]) {
            return;
        }
        $this->put($cells, $localX, $y, $localZ, $state);
    }

    /** @param array<int, string> $cells */
    private function put(array &$cells, int $x, int $y, int $z, InternalBlockStateId $state): void
    {
        $sectionY = $y >> 4;
        if (!isset($cells[$sectionY])) {
            $cells[$sectionY] = str_repeat(
                chr($this->paletteIndexByState[$this->blocks->air->value]),
                SubChunkBlockStorage::BLOCK_COUNT,
            );
        }
        $cells[$sectionY][$x + $z * 16 + (($y & 0x0f) * 256)] = chr($this->paletteIndexByState[$state->value]);
    }
}
