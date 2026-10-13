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
use Bedriox\Server\World\BlockEntity\BlockEntity;
use Bedriox\Server\World\BlockEntity\BlockEntityCollection;
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

    private readonly InternalBlockStateId $air;

    private readonly int $waterStateValue;

    private readonly int $lavaStateValue;

    /** @var list<InternalBlockStateId> */
    private readonly array $globalStates;

    /** @var array<int, string> Fast-path encoded indexes for the byte-addressable global palette prefix. */
    private readonly array $globalBytesByState;

    /** @var array<int, list<InternalBlockStateId>> Promoted section-local palettes only. */
    private array $localSectionStates = [];

    /** @var array<int, array<int, int>> Promoted section-local state-to-index maps only. */
    private array $localSectionIndicesByState = [];

    /** @var array<string, BlockEntity> */
    private array $blockEntities = [];

    public function __construct(
        public readonly ChunkPosition $position,
        private readonly GenerationBlockPalette $palette,
    ) {
        $this->air = $palette->state('minecraft:air');
        $this->waterStateValue = $palette->state('minecraft:water')->value;
        $this->lavaStateValue = $palette->state('minecraft:lava')->value;
        $this->globalStates = $palette->states();
        $this->globalBytesByState = $palette->fastBytesByState();
        $this->emptySection = $palette->emptySection();
    }

    public function set(int $localX, int $y, int $localZ, InternalBlockStateId $state): void
    {
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16 || $y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
            return;
        }
        $sectionY = $y >> 4;
        $this->sections[$sectionY] ??= $this->emptySection;
        $offset = $localX + $localZ * 16 + (($y & 0x0f) * 256);
        if (!isset($this->localSectionStates[$sectionY])) {
            $globalByte = $this->globalBytesByState[$state->value] ?? null;
            if (is_string($globalByte)) {
                $this->sections[$sectionY][$offset] = $globalByte;
                return;
            }
        }
        $paletteIndex = null;
        if (!isset($this->localSectionStates[$sectionY])) {
            $globalIndex = $this->palette->index($state);
            if ($globalIndex <= 255) {
                $paletteIndex = $globalIndex;
            } else {
                $this->promoteSection($sectionY);
            }
        }
        if ($paletteIndex === null) {
            $paletteIndex = $this->localSectionIndicesByState[$sectionY][$state->value] ?? null;
            if (!is_int($paletteIndex)) {
                $paletteIndex = count($this->localSectionStates[$sectionY]);
                if ($paletteIndex > 255) {
                    throw new \LogicException('Generated subchunk contains more than 256 distinct block states.');
                }
                $this->localSectionStates[$sectionY][] = $state;
                $this->localSectionIndicesByState[$sectionY][$state->value] = $paletteIndex;
            }
        }
        $this->sections[$sectionY][$offset] = chr($paletteIndex);
    }

    /** Fills an inclusive vertical span using the fast global generation palette. */
    public function fillColumn(
        int $localX,
        int $localZ,
        int $minimumY,
        int $maximumY,
        InternalBlockStateId $state,
    ): void {
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16 || $minimumY > $maximumY) {
            return;
        }
        $minimumY = max(Chunk::MIN_Y, $minimumY);
        $maximumY = min(Chunk::MAX_Y, $maximumY);
        $paletteByte = $this->globalBytesByState[$state->value] ?? null;
        if (!is_string($paletteByte)) {
            for ($y = $minimumY; $y <= $maximumY; ++$y) {
                $this->set($localX, $y, $localZ, $state);
            }
            return;
        }
        $columnOffset = $localX + $localZ * 16;
        for ($sectionY = $minimumY >> 4; $sectionY <= $maximumY >> 4; ++$sectionY) {
            if (isset($this->localSectionStates[$sectionY])) {
                $from = max($minimumY, $sectionY * 16);
                $to = min($maximumY, $sectionY * 16 + 15);
                for ($y = $from; $y <= $to; ++$y) {
                    $this->set($localX, $y, $localZ, $state);
                }
                continue;
            }
            $this->sections[$sectionY] ??= $this->emptySection;
            $from = max($minimumY, $sectionY * 16) & 0x0f;
            $to = min($maximumY, $sectionY * 16 + 15) & 0x0f;
            for ($localY = $from; $localY <= $to; ++$localY) {
                $this->sections[$sectionY][$columnOffset + $localY * 256] = $paletteByte;
            }
        }
    }

    /**
     * Initializes the solid terrain and sea fill from one height per X/Z column.
     * Whole uniform layers and sections are emitted in one operation instead of
     * performing tens of thousands of strided string writes.
     *
     * @param array<int, int> $heights X + Z * 16 ordering
     */
    public function initializeTerrainColumns(
        array $heights,
        int $seaLevel,
        InternalBlockStateId $deepslate,
        InternalBlockStateId $stone,
        InternalBlockStateId $water,
    ): void {
        if ($this->sections !== [] || count($heights) !== 256) {
            throw new \LogicException('Terrain columns require an empty builder and exactly 256 heights.');
        }
        $airByte = $this->globalBytesByState[$this->air->value] ?? null;
        $deepslateByte = $this->globalBytesByState[$deepslate->value] ?? null;
        $stoneByte = $this->globalBytesByState[$stone->value] ?? null;
        $waterByte = $this->globalBytesByState[$water->value] ?? null;
        if (!is_string($airByte) || !is_string($deepslateByte)
            || !is_string($stoneByte) || !is_string($waterByte)) {
            throw new \LogicException('Base terrain states must use the fast generation palette.');
        }
        $minimumHeight = min($heights);
        $maximumHeight = max($heights);
        $maximumY = max($maximumHeight, $seaLevel);
        for ($sectionY = Chunk::MIN_SECTION_Y; $sectionY <= $maximumY >> 4; ++$sectionY) {
            $bottomY = $sectionY * 16;
            $topY = $bottomY + 15;
            if ($topY <= 0) {
                $this->sections[$sectionY] = str_repeat($deepslateByte, 4096);
                continue;
            }
            if ($bottomY > 0 && $topY <= $minimumHeight) {
                $this->sections[$sectionY] = str_repeat($stoneByte, 4096);
                continue;
            }
            $indices = '';
            $containsBlocks = false;
            for ($localY = 0; $localY < 16; ++$localY) {
                $y = $bottomY + $localY;
                if ($y <= 0) {
                    $indices .= str_repeat($deepslateByte, 256);
                    $containsBlocks = true;
                    continue;
                }
                if ($y <= $minimumHeight) {
                    $indices .= str_repeat($stoneByte, 256);
                    $containsBlocks = true;
                    continue;
                }
                if ($y > $maximumHeight) {
                    $byte = $y <= $seaLevel ? $waterByte : $airByte;
                    $indices .= str_repeat($byte, 256);
                    $containsBlocks = $containsBlocks || $byte !== $airByte;
                    continue;
                }
                $layer = '';
                for ($column = 0; $column < 256; ++$column) {
                    $height = $heights[$column];
                    if ($y <= $height) {
                        $layer .= $stoneByte;
                        $containsBlocks = true;
                    } elseif ($height < $seaLevel && $y <= $seaLevel) {
                        $layer .= $waterByte;
                        $containsBlocks = true;
                    } else {
                        $layer .= $airByte;
                    }
                }
                $indices .= $layer;
            }
            if ($containsBlocks) {
                $this->sections[$sectionY] = $indices;
            }
        }
    }

    public function setWorld(int $x, int $y, int $z, InternalBlockStateId $state, bool $onlyAir = false): void
    {
        $localX = $x - $this->position->x * 16;
        $localZ = $z - $this->position->z * 16;
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16) {
            return;
        }
        if ($onlyAir && $this->state($localX, $y, $localZ)->value !== $this->air->value) {
            return;
        }
        $this->set($localX, $y, $localZ, $state);
    }

    public function state(int $localX, int $y, int $localZ): InternalBlockStateId
    {
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16 || $y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
            return $this->air;
        }
        $section = $this->sections[$y >> 4] ?? null;
        if ($section === null) {
            return $this->air;
        }

        $sectionY = $y >> 4;
        $paletteIndex = ord($section[$localX + $localZ * 16 + (($y & 0x0f) * 256)]);

        return isset($this->localSectionStates[$sectionY])
            ? $this->localSectionStates[$sectionY][$paletteIndex]
            : $this->globalStates[$paletteIndex];
    }

    public function highestSolid(int $localX, int $localZ, int $maximum = Chunk::MAX_Y): int
    {
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16 || $maximum < Chunk::MIN_Y) {
            return Chunk::MIN_Y;
        }
        $air = $this->air->value;
        $maximum = min(Chunk::MAX_Y, $maximum);
        for ($sectionY = $maximum >> 4; $sectionY >= Chunk::MIN_SECTION_Y; --$sectionY) {
            $section = $this->sections[$sectionY] ?? null;
            if (!is_string($section)) {
                continue;
            }
            $states = $this->localSectionStates[$sectionY] ?? $this->globalStates;
            $top = $sectionY === ($maximum >> 4) ? ($maximum & 0x0f) : 15;
            for ($localY = $top; $localY >= 0; --$localY) {
                $state = $states[ord($section[$localX + $localZ * 16 + $localY * 256])]->value;
                if ($state !== $air && $state !== $this->waterStateValue && $state !== $this->lavaStateValue) {
                    return $sectionY * 16 + $localY;
                }
            }
        }

        return Chunk::MIN_Y;
    }

    public function addBlockEntity(BlockEntity $entity): void
    {
        if ((int) floor($entity->position->x / 16) !== $this->position->x
            || (int) floor($entity->position->z / 16) !== $this->position->z) {
            return;
        }
        $key = $entity->position->x . ':' . $entity->position->y . ':' . $entity->position->z;
        if (isset($this->blockEntities[$key])) {
            throw new \LogicException('Generation attempted to place duplicate block entities.');
        }
        $this->blockEntities[$key] = $entity;
    }

    public function blockEntities(): BlockEntityCollection
    {
        return new BlockEntityCollection($this->position, array_values($this->blockEntities));
    }

    /** @return list<SubChunk> */
    public function sections(): array
    {
        $sections = [];
        ksort($this->sections, SORT_NUMERIC);
        foreach ($this->sections as $sectionY => $indices) {
            $sourceIndices = array_keys(count_chars($indices, 1));
            $sourceStates = $this->localSectionStates[$sectionY] ?? $this->globalStates;
            if (isset($this->localSectionStates[$sectionY])) {
                usort($sourceIndices, fn(int $left, int $right): int => $this->palette->index(
                    $sourceStates[$left],
                ) <=> $this->palette->index($sourceStates[$right]));
            }
            $palette = [];
            $sourceBytes = '';
            $targetBytes = '';
            foreach ($sourceIndices as $target => $source) {
                $palette[] = $sourceStates[$source];
                $sourceBytes .= chr($source);
                $targetBytes .= chr($target);
            }
            $compacted = strtr($indices, $sourceBytes, $targetBytes);
            $sections[] = SubChunk::fromBlockStorageLayers(
                $sectionY,
                [SubChunkBlockStorage::fromTrustedPaletteIndices($palette, $compacted)],
            );
        }

        return $sections;
    }

    private function promoteSection(int $sectionY): void
    {
        $indices = $this->sections[$sectionY];
        $sourceIndices = array_keys(count_chars($indices, 1));
        $states = [];
        $indicesByState = [];
        $sourceBytes = '';
        $targetBytes = '';
        foreach ($sourceIndices as $target => $source) {
            $state = $this->globalStates[$source];
            $states[] = $state;
            $indicesByState[$state->value] = $target;
            $sourceBytes .= chr($source);
            $targetBytes .= chr($target);
        }
        $this->sections[$sectionY] = strtr($indices, $sourceBytes, $targetBytes);
        $this->localSectionStates[$sectionY] = $states;
        $this->localSectionIndicesByState[$sectionY] = $indicesByState;
    }

}
