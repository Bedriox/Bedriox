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

use Bedriox\Server\World\Biome;

/** Selects current vertical cave biomes without replacing the surface climate identity. */
final readonly class OverworldCaveBiomeResolver
{
    public function __construct(private SeededNoise $noise) {}

    public function resolve(int $x, int $y, int $z, int $surface, Biome $surfaceBiome): Biome
    {
        if ($y > 48 || $y > $surface - 12) {
            return $surfaceBiome;
        }
        $region = $this->noise->sample3d($x, intdiv($y, 2), $z, 260, 4_101);
        $detail = $this->noise->sample3d($x, $y, $z, 96, 4_211);
        if ($y < -28 && $region < -24_000 && $detail < -4_000) {
            return new Biome('minecraft:deep_dark');
        }
        if ($region > 22_000 && $detail > 4_000) {
            return new Biome('minecraft:lush_caves');
        }
        if ($region < -22_000 && $detail > 4_000) {
            return new Biome('minecraft:dripstone_caves');
        }
        if ($y < 24 && abs($region) < 2_000 && $detail > 24_000) {
            return new Biome('minecraft:sulfur_caves');
        }

        return $surfaceBiome;
    }
}
