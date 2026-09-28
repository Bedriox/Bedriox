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

namespace Bedriox\Server\World\Generation;

use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\SubChunk;
use Bedriox\Server\World\SubChunkBlockStorage;

/** Worker-local mutable generation buffer compacted into immutable chunk sections at completion. */
final class MutableChunkBuilder
{
    /** @var array<int, string> */
    private array $sections = [];

    private readonly string $emptySection;

    public function __construct(
        public readonly ChunkPosition $position,
        private readonly GenerationBlockPalette $palette,
    ) {
        $this->emptySection = str_repeat(self::encodePaletteIndex(
            $palette->index($palette->state('minecraft:air')),
        ), SubChunk::BLOCK_COUNT);
    }

    public function set(int $localX, int $y, int $localZ, InternalBlockStateId $state): void
    {
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16 || $y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
            return;
        }
        $sectionY = $y >> 4;
        $this->sections[$sectionY] ??= $this->emptySection;
        $this->sections[$sectionY][$localX + $localZ * 16 + (($y & 0x0f) * 256)] = self::encodePaletteIndex(
            $this->palette->index($state),
        );
    }

    public function setWorld(int $x, int $y, int $z, InternalBlockStateId $state, bool $onlyAir = false): void
    {
        $localX = $x - $this->position->x * 16;
        $localZ = $z - $this->position->z * 16;
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16) {
            return;
        }
        if ($onlyAir && $this->state($localX, $y, $localZ)->value !== $this->palette->state('minecraft:air')->value) {
            return;
        }
        $this->set($localX, $y, $localZ, $state);
    }

    public function state(int $localX, int $y, int $localZ): InternalBlockStateId
    {
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16 || $y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
            return $this->palette->state('minecraft:air');
        }
        $section = $this->sections[$y >> 4] ?? null;
        if ($section === null) {
            return $this->palette->state('minecraft:air');
        }

        return $this->palette->states()[ord($section[$localX + $localZ * 16 + (($y & 0x0f) * 256)])];
    }

    public function highestSolid(int $localX, int $localZ, int $maximum = Chunk::MAX_Y): int
    {
        $air = $this->palette->state('minecraft:air')->value;
        $water = $this->palette->state('minecraft:water')->value;
        $lava = $this->palette->state('minecraft:lava')->value;
        for ($y = min(Chunk::MAX_Y, $maximum); $y >= Chunk::MIN_Y; --$y) {
            $state = $this->state($localX, $y, $localZ)->value;
            if ($state !== $air && $state !== $water && $state !== $lava) {
                return $y;
            }
        }

        return Chunk::MIN_Y;
    }

    /** @return list<SubChunk> */
    public function sections(): array
    {
        $sections = [];
        ksort($this->sections, SORT_NUMERIC);
        foreach ($this->sections as $sectionY => $indices) {
            $sourceIndices = array_keys(count_chars($indices, 1));
            $palette = [];
            $sourceBytes = '';
            $targetBytes = '';
            foreach ($sourceIndices as $target => $source) {
                $palette[] = $this->palette->states()[$source];
                $sourceBytes .= self::encodePaletteIndex($source);
                $targetBytes .= self::encodePaletteIndex($target);
            }
            $compacted = strtr($indices, $sourceBytes, $targetBytes);
            $sections[] = SubChunk::fromBlockStorageLayers(
                $sectionY,
                [SubChunkBlockStorage::fromPaletteIndices($palette, $compacted)],
            );
        }

        return $sections;
    }

    private static function encodePaletteIndex(int $index): string
    {
        if ($index < 0 || $index > 255) {
            throw new \LogicException('Generation palette index is outside its byte bound.');
        }

        return pack('C', $index);
    }
}
