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

/** Produces signed solid density for modern three-dimensional overworld terrain. */
final readonly class OverworldDensitySampler
{
    public function __construct(
        private SeededNoise $noise,
        private OverworldTerrainSampler $terrain,
    ) {}

    public function densityAt(int $x, int $y, int $z, OverworldClimate $climate): float
    {
        return $this->carvedDensityAt($x, $y, $z, $climate, $this->baseDensityAt($x, $y, $z, $climate));
    }

    /** Returns terrain density before caves are carved from otherwise-solid terrain. */
    public function baseDensityAt(int $x, int $y, int $z, OverworldClimate $climate): float
    {
        $surface = $this->terrain->surfaceHeight($climate);
        $continental = $climate->continentalness / 32_768;
        $ridge = 1.0 - abs($climate->ridge / 32_768);
        $erosion = ($climate->erosion + 32_768) / 65_536;
        $mountain = max(0.0, $climate->uplift / 32_768 - 0.08)
            * max(0.0, $continental + 0.12)
            * (1.15 - $erosion * 0.55);

        $verticalScale = 13.0 + $mountain * 14.0;
        $density = ($surface - $y) / $verticalScale;
        $density += ($this->noise->sample3d($x, $y, $z, 72, 3_101) / 32_768) * 0.72;
        $density += ($this->noise->sample3d($x, $y, $z, 31, 3_211) / 32_768)
            * (0.16 + $mountain * $ridge * 0.48);

        return $density;
    }

    /**
     * Applies cave carving to base terrain density.
     *
     * Keeping both values allows the generator to distinguish open terrain, which is
     * filled continuously to sea level, from underground caves governed by aquifers.
     */
    public function carvedDensityAt(
        int $x,
        int $y,
        int $z,
        OverworldClimate $climate,
        ?float $baseDensity = null,
    ): float {
        $surface = $this->terrain->surfaceHeight($climate);
        $density = $baseDensity ?? $this->baseDensityAt($x, $y, $z, $climate);

        if ($y < $surface - 7 && $y < 176) {
            $depth = min(1.0, max(0.0, ($surface - $y - 7) / 30.0));
            $cheese = $this->noise->sample3d($x, $y, $z, 54, 3_307) / 32_768;
            if ($cheese > 0.38) {
                $density -= (($cheese - 0.38) * 8.4 + 0.65) * $depth;
            }
            $spaghettiA = abs($this->noise->sample3d($x, $y, $z, 38, 3_401) / 32_768);
            $spaghettiB = abs($this->noise->sample3d($x, $y, $z, 31, 3_503) / 32_768);
            $tunnel = max($spaghettiA / 0.105, $spaghettiB / 0.105);
            if ($tunnel < 1.0) {
                $density -= (1.0 - $tunnel) * 3.2 * $depth;
            }
            if ($spaghettiA < 0.18 && $y < 72) {
                $noodle = abs($this->noise->sample3d($x, $y, $z, 21, 3_601) / 32_768);
                if ($noodle < 0.032) {
                    $density -= (0.032 - $noodle) * 72.0 * $depth;
                }
            }
        }

        return $density;
    }

    public function aquiferLevel(int $x, int $y, int $z): ?int
    {
        if ($y <= -55) {
            return -54;
        }
        if ($y > 36) {
            return null;
        }
        $wetness = $this->noise->sample3d($x, intdiv($y, 2), $z, 86, 3_701);
        if ($wetness < 10_500) {
            return null;
        }

        return 12 + intdiv($this->noise->sample2d($x, $z, 118, 3_709), 5_500);
    }
}
