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

/** Immutable persistent biome palette with Bedriox's in-memory YZX index order. */
final readonly class PersistentBiomeStorage
{
    /** @var list<int> */
    private array $palette;

    /** @var list<int> */
    private array $indices;

    /**
     * @param list<int> $palette
     * @param list<int> $indices
     */
    public function __construct(array $palette, array $indices)
    {
        if ($palette === [] || count($palette) > PersistentBlockStorage::ENTRY_COUNT) {
            throw new InvalidArgumentException('Persistent biome palette must contain between 1 and 4096 entries.');
        }
        if (count($indices) !== PersistentBlockStorage::ENTRY_COUNT) {
            throw new InvalidArgumentException('Persistent biome storage must contain exactly 4096 indices.');
        }
        foreach ($palette as $biomeId) {
            if ($biomeId < 0 || $biomeId > 0xffff_ffff) {
                throw new InvalidArgumentException('Persistent biome ID must fit an unsigned 32-bit integer.');
            }
        }
        foreach ($indices as $index) {
            if ($index < 0 || $index >= count($palette)) {
                throw new InvalidArgumentException('Persistent biome storage contains an out-of-range palette index.');
            }
        }
        $this->palette = $palette;
        $this->indices = $indices;
    }

    public static function uniform(int $biomeId): self
    {
        return new self([$biomeId], array_fill(0, PersistentBlockStorage::ENTRY_COUNT, 0));
    }

    /** @return list<int> */
    public function palette(): array
    {
        return $this->palette;
    }

    public function indexAt(int $x, int $y, int $z): int
    {
        if ($x < 0 || $x > 15 || $y < 0 || $y > 15 || $z < 0 || $z > 15) {
            throw new InvalidArgumentException('Persistent storage coordinates must be between 0 and 15.');
        }
        return $this->indices[$x + ($z << 4) + ($y << 8)];
    }

    public function biomeIdAt(int $x, int $y, int $z): int
    {
        return $this->palette[$this->indexAt($x, $y, $z)];
    }

    /** @return list<int> */
    public function indices(): array
    {
        return $this->indices;
    }
}
