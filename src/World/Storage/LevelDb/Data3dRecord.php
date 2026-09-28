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
