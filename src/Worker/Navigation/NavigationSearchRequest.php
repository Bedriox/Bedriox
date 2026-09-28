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

namespace Bedriox\Server\Worker\Navigation;

use Bedriox\Server\Entity\Navigation\GroundPathfinder;
use Bedriox\Server\Entity\Navigation\NavigationPoint;
use Bedriox\Server\Entity\Navigation\NavigationSnapshot;
use InvalidArgumentException;

final readonly class NavigationSearchRequest
{
    public const int MAXIMUM_DISTANCE_BLOCKS = 256;
    public const int MAXIMUM_VERTICAL_RANGE = 64;
    public const int MAXIMUM_RUNTIME_MILLISECONDS = 250;

    public function __construct(
        public NavigationSnapshot $snapshot,
        public NavigationPoint $start,
        public NavigationPoint $target,
        public int $maximumDistanceBlocks,
        public int $maximumVerticalRange,
        public int $maximumVisitedNodes,
        public int $maximumRuntimeMilliseconds,
    ) {
        if ($maximumDistanceBlocks < 1 || $maximumDistanceBlocks > self::MAXIMUM_DISTANCE_BLOCKS
            || $maximumVerticalRange < 1 || $maximumVerticalRange > self::MAXIMUM_VERTICAL_RANGE
            || $maximumVisitedNodes < 1 || $maximumVisitedNodes > GroundPathfinder::MAXIMUM_VISITED_NODES
            || $maximumRuntimeMilliseconds < 1
            || $maximumRuntimeMilliseconds > self::MAXIMUM_RUNTIME_MILLISECONDS) {
            throw new InvalidArgumentException('Navigation search limits are outside their supported bounds.');
        }
        if (!$snapshot->isWalkable($start) || !$snapshot->isWalkable($target)) {
            throw new InvalidArgumentException('Navigation search endpoints must be walkable snapshot cells.');
        }
        $deltaX = abs($start->x - $target->x);
        $deltaY = abs($start->y - $target->y);
        $deltaZ = abs($start->z - $target->z);
        if (($deltaX * $deltaX) + ($deltaZ * $deltaZ) > $maximumDistanceBlocks * $maximumDistanceBlocks
            || $deltaY > $maximumVerticalRange) {
            throw new InvalidArgumentException('Navigation search endpoints exceed the requested range.');
        }
    }
}
