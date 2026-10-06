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

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Api\Entity\Value\SlimeSize;
use Bedriox\Server\Simulation\Position;

/** Stable weighted size selection without mutable global randomness. */
final class MagmaCubeSpawnVariantSelector
{
    public static function select(int $worldSeed, Position $position, int $tick): SlimeSize
    {
        $material = $worldSeed . ':' . (int) floor($position->x) . ':' . (int) floor($position->y)
            . ':' . (int) floor($position->z) . ':' . $tick;
        $digest = hash('sha256', $material, true);
        $bucket = self::uint32($digest) % 6;

        return match ($bucket) {
            0 => SlimeSize::LARGE,
            1, 2 => SlimeSize::MEDIUM,
            default => SlimeSize::SMALL,
        };
    }

    private static function uint32(string $digest): int
    {
        return (ord($digest[0]) << 24)
            | (ord($digest[1]) << 16)
            | (ord($digest[2]) << 8)
            | ord($digest[3]);
    }
}
