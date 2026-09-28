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

namespace Bedriox\Server\Entity\Navigation;

use InvalidArgumentException;

final readonly class NavigationPath
{
    public const int MAXIMUM_POINTS = 512;

    /** @var list<NavigationPoint> */
    public array $points;

    /** @param array<array-key, mixed> $points */
    public function __construct(
        array $points,
        public NavigationPathStatus $status,
        public string $snapshotRevision,
        public int $visitedNodes,
    ) {
        if (!array_is_list($points) || $points === [] || count($points) > self::MAXIMUM_POINTS) {
            throw new InvalidArgumentException('Navigation path point count is outside its supported bounds.');
        }
        foreach ($points as $point) {
            if (!$point instanceof NavigationPoint) {
                throw new InvalidArgumentException('Navigation path contains an invalid point.');
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $snapshotRevision) !== 1
            || $visitedNodes < 0 || $visitedNodes > GroundPathfinder::MAXIMUM_VISITED_NODES) {
            throw new InvalidArgumentException('Navigation path metadata is outside its supported bounds.');
        }
        $this->points = $points;
    }

    public function reachedTarget(): bool
    {
        return $this->status === NavigationPathStatus::REACHED_TARGET;
    }
}
