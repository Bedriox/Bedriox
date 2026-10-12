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

namespace Bedriox\Server\Gameplay\Block\Environment;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Chunk;
use Closure;

/** Bounded 9x2x9 farmland-water search with an optional runtime-only hot-position hint. */
final readonly class FarmlandHydrationSearch
{
    /**
     * @param Closure(BlockPosition): (CanonicalBlockState|null) $stateAt Must not load or generate terrain.
     */
    public function findWater(
        BlockPosition $farmland,
        Closure $stateAt,
        ?BlockPosition $cachedWater = null,
    ): ?BlockPosition {
        if ($cachedWater !== null
            && abs($cachedWater->x - $farmland->x) <= 4
            && $cachedWater->y >= $farmland->y
            && $cachedWater->y <= $farmland->y + 1
            && abs($cachedWater->z - $farmland->z) <= 4
            && $this->isWater($stateAt($cachedWater))) {
            return $cachedWater;
        }

        for ($yOffset = 0; $yOffset <= 1; ++$yOffset) {
            $y = $farmland->y + $yOffset;
            if ($y > Chunk::MAX_Y) {
                continue;
            }
            for ($xOffset = -4; $xOffset <= 4; ++$xOffset) {
                for ($zOffset = -4; $zOffset <= 4; ++$zOffset) {
                    $position = new BlockPosition($farmland->x + $xOffset, $y, $farmland->z + $zOffset);
                    if ($this->isWater($stateAt($position))) {
                        return $position;
                    }
                }
            }
        }

        return null;
    }

    private function isWater(?CanonicalBlockState $state): bool
    {
        return $state?->identifier() === 'minecraft:water';
    }
}
