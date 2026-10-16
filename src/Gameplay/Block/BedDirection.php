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

namespace Bedriox\Server\Gameplay\Block;

/** Canonical Bedrock `direction` values used by bed block states. */
enum BedDirection: int
{
    case SOUTH = 0;
    case WEST = 1;
    case NORTH = 2;
    case EAST = 3;

    public static function fromYaw(float $yaw): self
    {
        $yaw = fmod($yaw, 360.0);
        if ($yaw < 0.0) {
            $yaw += 360.0;
        }

        return match (true) {
            $yaw < 45.0 || $yaw >= 315.0 => self::SOUTH,
            $yaw < 135.0 => self::WEST,
            $yaw < 225.0 => self::NORTH,
            default => self::EAST,
        };
    }

    /** @return array{int, int} */
    public function offset(): array
    {
        return match ($this) {
            self::SOUTH => [0, 1],
            self::WEST => [-1, 0],
            self::NORTH => [0, -1],
            self::EAST => [1, 0],
        };
    }
}
