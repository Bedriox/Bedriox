<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\LevelDb;

use InvalidArgumentException;

final readonly class Data3dRecord
{
    public const int HEIGHTMAP_BYTES = 512;
    public const int BIOME_STORAGE_COUNT = 24;

    /** @var list<PersistentBiomeStorage> */
    private array $biomes;

    /** @param list<PersistentBiomeStorage> $biomes */
    public function __construct(private string $heightmap, array $biomes)
    {
        if (strlen($heightmap) !== self::HEIGHTMAP_BYTES) {
            throw new InvalidArgumentException('Data3D heightmap must contain exactly 512 bytes.');
        }
        if (count($biomes) !== self::BIOME_STORAGE_COUNT) {
            throw new InvalidArgumentException('Data3D must contain exactly 24 biome palettes.');
        }
        $this->biomes = $biomes;
    }

    public function heightmap(): string
    {
        return $this->heightmap;
    }

    /** @return list<PersistentBiomeStorage> */
    public function biomes(): array
    {
        return $this->biomes;
    }
}
