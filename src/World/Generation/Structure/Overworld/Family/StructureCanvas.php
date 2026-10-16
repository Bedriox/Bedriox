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

namespace Bedriox\Server\World\Generation\Structure\Overworld\Family;

use Bedriox\Api\World\BedColor;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\BedDirection;
use Bedriox\Server\World\BlockEntity\BedBlockEntity;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\SeededNoise;

/** Chunk-clipped drawing primitives shared by original structure-family renderers. */
final readonly class StructureCanvas
{
    public function __construct(
        private GenerationBlockPalette $blocks,
        private SeededNoise $noise,
    ) {}

    public function block(MutableChunkBuilder $builder, int $x, int $y, int $z, string $identifier): void
    {
        if (!$this->inside($builder, $x, $z)) {
            return;
        }
        $builder->setWorld($x, $y, $z, $this->blocks->state($identifier));
    }

    /** @param array<string, bool|int|string> $properties */
    public function canonicalBlock(
        MutableChunkBuilder $builder,
        int $x,
        int $y,
        int $z,
        string $identifier,
        array $properties,
    ): void {
        if (!$this->inside($builder, $x, $z)) {
            return;
        }
        $builder->setWorld($x, $y, $z, $this->blocks->canonical(CanonicalBlockState::from($identifier, $properties)));
    }

    public function bed(
        MutableChunkBuilder $builder,
        int $footX,
        int $y,
        int $footZ,
        BedDirection $direction,
        BedColor $color = BedColor::RED,
    ): void {
        [$offsetX, $offsetZ] = $direction->offset();
        foreach ([[0, 0, 0], [$offsetX, $offsetZ, 1]] as [$xOffset, $zOffset, $head]) {
            $x = $footX + $xOffset;
            $z = $footZ + $zOffset;
            if (!$this->inside($builder, $x, $z)) {
                continue;
            }
            $this->canonicalBlock($builder, $x, $y, $z, 'minecraft:bed', [
                'direction' => $direction->value,
                'head_piece_bit' => $head,
                'occupied_bit' => 0,
            ]);
            $builder->addBlockEntity(new BedBlockEntity(new BlockPosition($x, $y, $z), $color));
        }
    }

    public function box(MutableChunkBuilder $builder, int $x1, int $y1, int $z1, int $x2, int $y2, int $z2, string $identifier): void
    {
        [$x1, $x2, $z1, $z2] = $this->clip($builder, $x1, $x2, $z1, $z2);
        if ($x1 > $x2 || $z1 > $z2) {
            return;
        }
        for ($y = $y1; $y <= $y2; ++$y) {
            for ($z = $z1; $z <= $z2; ++$z) {
                for ($x = $x1; $x <= $x2; ++$x) {
                    $this->block($builder, $x, $y, $z, $identifier);
                }
            }
        }
    }

    public function hollow(MutableChunkBuilder $builder, int $x1, int $y1, int $z1, int $x2, int $y2, int $z2, string $wall): void
    {
        [$minimumX, $maximumX, $minimumZ, $maximumZ] = $this->clip($builder, $x1, $x2, $z1, $z2);
        if ($minimumX > $maximumX || $minimumZ > $maximumZ) {
            return;
        }
        for ($y = $y1; $y <= $y2; ++$y) {
            for ($z = $minimumZ; $z <= $maximumZ; ++$z) {
                for ($x = $minimumX; $x <= $maximumX; ++$x) {
                    $this->block(
                        $builder,
                        $x,
                        $y,
                        $z,
                        $x === $x1 || $x === $x2 || $y === $y1 || $y === $y2 || $z === $z1 || $z === $z2
                            ? $wall
                            : 'minecraft:air',
                    );
                }
            }
        }
    }

    public function floor(MutableChunkBuilder $builder, int $x1, int $x2, int $y, int $z1, int $z2, string $identifier): void
    {
        $this->box($builder, $x1, $y, $z1, $x2, $y, $z2, $identifier);
    }

    public function column(MutableChunkBuilder $builder, int $x, int $y1, int $y2, int $z, string $identifier): void
    {
        if (!$this->inside($builder, $x, $z)) {
            return;
        }
        for ($y = $y1; $y <= $y2; ++$y) {
            $this->block($builder, $x, $y, $z, $identifier);
        }
    }

    public function intersects(
        MutableChunkBuilder $builder,
        int $minimumX,
        int $minimumZ,
        int $maximumX,
        int $maximumZ,
    ): bool {
        $chunkMinimumX = $builder->position->x * 16;
        $chunkMinimumZ = $builder->position->z * 16;

        return $maximumX >= $chunkMinimumX
            && $minimumX <= $chunkMinimumX + 15
            && $maximumZ >= $chunkMinimumZ
            && $minimumZ <= $chunkMinimumZ + 15;
    }

    /** @param non-empty-list<array{string, int}> $entries */
    public function loot(
        MutableChunkBuilder $builder,
        int $x,
        int $y,
        int $z,
        ?string $name,
        int $salt,
        array $entries,
    ): void {
        if (!$this->inside($builder, $x, $z)) {
            return;
        }
        $contents = [];
        foreach ($entries as $index => [$identifier, $maximum]) {
            if ($this->noise->chance($x, $y + $index, $z, $salt + $index, 4) === 0) {
                continue;
            }
            $contents[$index * 3] = new ContainerItemStack(
                $identifier,
                1 + $this->noise->chance($x, $y, $z, $salt + 31 + $index, $maximum),
            );
        }
        if ($contents === []) {
            $contents[0] = new ContainerItemStack($entries[0][0], 1);
        }
        $this->block($builder, $x, $y, $z, 'minecraft:chest');
        $builder->addBlockEntity(new ContainerBlockEntity(
            BlockEntityType::Chest,
            new BlockPosition($x, $y, $z),
            new ContainerInventory(ContainerBlockEntity::STORAGE_SLOT_COUNT, $contents),
            $name,
            facing: 2 + $this->noise->chance($x, $y, $z, $salt + 97, 4),
        ));
    }

    /** @return array{int, int, int, int} */
    private function clip(MutableChunkBuilder $builder, int $x1, int $x2, int $z1, int $z2): array
    {
        $minimumX = $builder->position->x * 16;
        $minimumZ = $builder->position->z * 16;

        return [max($x1, $minimumX), min($x2, $minimumX + 15), max($z1, $minimumZ), min($z2, $minimumZ + 15)];
    }

    private function inside(MutableChunkBuilder $builder, int $x, int $z): bool
    {
        $minimumX = $builder->position->x * 16;
        $minimumZ = $builder->position->z * 16;

        return $x >= $minimumX && $x <= $minimumX + 15 && $z >= $minimumZ && $z <= $minimumZ + 15;
    }
}
