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

namespace Bedriox\Server\World\Generation\Structure;

use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;

final readonly class StructureBoundingBox
{
    public function __construct(
        public int $minimumX,
        public int $minimumY,
        public int $minimumZ,
        public int $maximumX,
        public int $maximumY,
        public int $maximumZ,
    ) {
        if ($minimumX > $maximumX || $minimumY > $maximumY || $minimumZ > $maximumZ) {
            throw new InvalidArgumentException('Structure bounding-box minimums must not exceed maximums.');
        }
    }

    public static function fromPlacement(StructurePlacement $placement): self
    {
        if ($placement->blocks === []) {
            throw new InvalidArgumentException('Cannot derive a bounding box from an empty structure placement.');
        }
        $first = $placement->blocks[0]->coordinate;
        $minimumX = $maximumX = $first->x;
        $minimumY = $maximumY = $first->y;
        $minimumZ = $maximumZ = $first->z;
        foreach ($placement->blocks as $block) {
            $coordinate = $block->coordinate;
            $minimumX = min($minimumX, $coordinate->x);
            $minimumY = min($minimumY, $coordinate->y);
            $minimumZ = min($minimumZ, $coordinate->z);
            $maximumX = max($maximumX, $coordinate->x);
            $maximumY = max($maximumY, $coordinate->y);
            $maximumZ = max($maximumZ, $coordinate->z);
        }

        return new self($minimumX, $minimumY, $minimumZ, $maximumX, $maximumY, $maximumZ);
    }

    public function intersects(self $other): bool
    {
        return $this->minimumX <= $other->maximumX && $this->maximumX >= $other->minimumX
            && $this->minimumY <= $other->maximumY && $this->maximumY >= $other->minimumY
            && $this->minimumZ <= $other->maximumZ && $this->maximumZ >= $other->minimumZ;
    }

    public function intersectsChunk(ChunkPosition $chunk): bool
    {
        $minimumX = $chunk->x * 16;
        $minimumZ = $chunk->z * 16;

        return $this->minimumX <= $minimumX + 15 && $this->maximumX >= $minimumX
            && $this->minimumZ <= $minimumZ + 15 && $this->maximumZ >= $minimumZ;
    }

    public function union(self $other): self
    {
        return new self(
            min($this->minimumX, $other->minimumX),
            min($this->minimumY, $other->minimumY),
            min($this->minimumZ, $other->minimumZ),
            max($this->maximumX, $other->maximumX),
            max($this->maximumY, $other->maximumY),
            max($this->maximumZ, $other->maximumZ),
        );
    }
}
