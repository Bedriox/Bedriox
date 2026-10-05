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

namespace Bedriox\Server\Gameplay\Portal;

use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

final class PortalDestinationPlanner
{
    public const int NETHER_SEARCH_RADIUS = 16;
    public const int OVERWORLD_SEARCH_RADIUS = 128;
    public const int BUILD_HORIZONTAL_RADIUS = 16;
    public const int BUILD_VERTICAL_RADIUS = 16;
    public const int MAXIMUM_INDEXED_PORTALS = 4_096;

    public function plan(
        WorldDimension $source,
        Position $position,
        PortalAxis $preferredAxis,
    ): PortalDestinationPlan {
        [$target, $scale, $searchRadius] = match ($source) {
            WorldDimension::OVERWORLD => [WorldDimension::NETHER, 0.125, self::NETHER_SEARCH_RADIUS],
            WorldDimension::NETHER => [WorldDimension::OVERWORLD, 8.0, self::OVERWORLD_SEARCH_RADIUS],
            WorldDimension::END => throw new InvalidArgumentException('Nether portals cannot transfer from the End.'),
        };
        $x = max(-29_999_872.0, min(29_999_872.0, $position->x * $scale));
        $z = max(-29_999_872.0, min(29_999_872.0, $position->z * $scale));

        return new PortalDestinationPlan(
            $source,
            $target,
            new Position($x, $position->y, $z),
            $searchRadius,
            $preferredAxis,
        );
    }

    /**
     * Chooses the closest indexed portal deterministically without scanning every block in the search square.
     *
     * @param list<BlockPosition> $portals
     */
    public function nearest(PortalDestinationPlan $plan, array $portals): ?BlockPosition
    {
        if (count($portals) > self::MAXIMUM_INDEXED_PORTALS) {
            throw new InvalidArgumentException('Portal destination index exceeds its bounded capacity.');
        }
        $centerX = (int) floor($plan->projectedPosition->x);
        $centerZ = (int) floor($plan->projectedPosition->z);
        $best = null;
        $bestKey = null;
        foreach ($portals as $portal) {
            $dx = $portal->x - $centerX;
            $dz = $portal->z - $centerZ;
            if (abs($dx) > $plan->searchRadius || abs($dz) > $plan->searchRadius) {
                continue;
            }
            $key = [($dx * $dx) + ($dz * $dz), $portal->y, $portal->x, $portal->z];
            if ($bestKey === null || $key < $bestKey) {
                $best = $portal;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * Finds the first buildable 2x3 portal origin using a stable ring and vertical ordering.
     *
     * @param callable(BlockPosition, PortalAxis): bool $isSuitable
     */
    public function buildOrigin(PortalDestinationPlan $plan, callable $isSuitable): ?BlockPosition
    {
        $centerX = (int) floor($plan->projectedPosition->x);
        $centerY = max(-62, min(309, (int) floor($plan->projectedPosition->y)));
        $centerZ = (int) floor($plan->projectedPosition->z);
        for ($radius = 0; $radius <= self::BUILD_HORIZONTAL_RADIUS; ++$radius) {
            foreach ($this->ring($centerX, $centerZ, $radius) as [$x, $z]) {
                for ($offset = 0; $offset <= self::BUILD_VERTICAL_RADIUS; ++$offset) {
                    foreach ($offset === 0 ? [0] : [$offset, -$offset] as $vertical) {
                        $candidate = new BlockPosition($x, $centerY + $vertical, $z);
                        if ($isSuitable($candidate, $plan->preferredAxis)) {
                            return $candidate;
                        }
                    }
                }
            }
        }

        return null;
    }

    /** Returns the bounded vanilla-style fallback used when no naturally safe site exists. */
    public function fallbackBuildOrigin(PortalDestinationPlan $plan): BlockPosition
    {
        $minimumY = $plan->targetDimension === WorldDimension::NETHER ? 3 : -60;
        $maximumY = $plan->targetDimension === WorldDimension::NETHER ? 123 : 315;

        return new BlockPosition(
            (int) floor($plan->projectedPosition->x),
            max($minimumY, min($maximumY, (int) floor($plan->projectedPosition->y))),
            (int) floor($plan->projectedPosition->z),
        );
    }

    /** @return iterable<array{int, int}> */
    private function ring(int $centerX, int $centerZ, int $radius): iterable
    {
        if ($radius === 0) {
            yield [$centerX, $centerZ];

            return;
        }
        for ($x = -$radius; $x <= $radius; ++$x) {
            yield [$centerX + $x, $centerZ - $radius];
            yield [$centerX + $x, $centerZ + $radius];
        }
        for ($z = -$radius + 1; $z < $radius; ++$z) {
            yield [$centerX - $radius, $centerZ + $z];
            yield [$centerX + $radius, $centerZ + $z];
        }
    }
}
