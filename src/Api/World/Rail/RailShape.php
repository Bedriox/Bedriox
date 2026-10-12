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

namespace Bedriox\Api\World\Rail;

/** Stable rail geometry backed by the canonical Bedrock rail_direction property. */
enum RailShape: int
{
    case NORTH_SOUTH = 0;
    case EAST_WEST = 1;
    case ASCENDING_EAST = 2;
    case ASCENDING_WEST = 3;
    case ASCENDING_NORTH = 4;
    case ASCENDING_SOUTH = 5;
    case SOUTH_EAST = 6;
    case SOUTH_WEST = 7;
    case NORTH_WEST = 8;
    case NORTH_EAST = 9;

    public function isCurved(): bool
    {
        return $this->value >= self::SOUTH_EAST->value;
    }

    public function isAscending(): bool
    {
        return $this->value >= self::ASCENDING_EAST->value
            && $this->value <= self::ASCENDING_SOUTH->value;
    }

    /** @return array{RailEndpoint, RailEndpoint} */
    public function endpoints(): array
    {
        return match ($this) {
            self::NORTH_SOUTH => [new RailEndpoint(0, 0, -1), new RailEndpoint(0, 0, 1)],
            self::EAST_WEST => [new RailEndpoint(-1, 0, 0), new RailEndpoint(1, 0, 0)],
            self::ASCENDING_EAST => [new RailEndpoint(-1, 0, 0), new RailEndpoint(1, 1, 0)],
            self::ASCENDING_WEST => [new RailEndpoint(-1, 1, 0), new RailEndpoint(1, 0, 0)],
            self::ASCENDING_NORTH => [new RailEndpoint(0, 1, -1), new RailEndpoint(0, 0, 1)],
            self::ASCENDING_SOUTH => [new RailEndpoint(0, 0, -1), new RailEndpoint(0, 1, 1)],
            self::SOUTH_EAST => [new RailEndpoint(0, 0, 1), new RailEndpoint(1, 0, 0)],
            self::SOUTH_WEST => [new RailEndpoint(0, 0, 1), new RailEndpoint(-1, 0, 0)],
            self::NORTH_WEST => [new RailEndpoint(0, 0, -1), new RailEndpoint(-1, 0, 0)],
            self::NORTH_EAST => [new RailEndpoint(0, 0, -1), new RailEndpoint(1, 0, 0)],
        };
    }
}
