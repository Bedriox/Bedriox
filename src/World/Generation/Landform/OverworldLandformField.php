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

namespace Bedriox\Server\World\Generation\Landform;

use Bedriox\Server\World\Generation\OverworldClimate;
use Bedriox\Server\World\Generation\OverworldTerrainSampler;
use Bedriox\Server\World\Generation\SeededNoise;

/** Applies continuous river and wetland relief to the final density surface. */
final readonly class OverworldLandformField
{
    public const int SEA_LEVEL = OverworldTerrainSampler::SEA_LEVEL;

    public function __construct(private SeededNoise $noise) {}

    public function surfaceHeight(
        int $x,
        int $z,
        int $densityHeight,
        OverworldClimate $climate,
        int $riverStrength,
    ): int {
        $height = $this->oceanFloorHeight($x, $z, $densityHeight);
        $height = $this->riverHeight($height, $riverStrength);
        $wetland = $this->wetlandStrength($x, $z, $height, $climate);
        if ($wetland <= 0) {
            return $height;
        }

        $channel = $this->noise->fractal2d($x, $z, 92, 3, 54, 76_403);
        $micro = $this->noise->fractal2d($x, $z, 31, 2, 48, 76_409);
        $target = match (true) {
            $channel > 9_000 => self::SEA_LEVEL - 2,
            $channel > 2_500 => self::SEA_LEVEL - 1,
            $micro > 11_000 => self::SEA_LEVEL + 1,
            default => self::SEA_LEVEL,
        };
        $blend = min(1.0, $wetland / 700.0);

        // Wetlands erode high ground down into connected basins; they never raise
        // an ocean floor or manufacture unsupported terrain above density volume.
        return min($height, (int) round($height * (1.0 - $blend) + $target * $blend));
    }

    public function wetlandStrength(int $x, int $z, int $height, OverworldClimate $climate): int
    {
        if ($height < self::SEA_LEVEL - 4 || $height > self::SEA_LEVEL + 12
            || $climate->humidity < 9_000 || $climate->temperature < -3_500
            || ($climate->continentalness < -2_000
                && ($height > self::SEA_LEVEL + 3 || $climate->humidity <= 11_000))) {
            return 0;
        }

        $climateStrength = min(1_000, intdiv(($climate->humidity - 9_000) * 1_000, 8_000));
        $macro = $this->noise->fractal2d($x, $z, 260, 3, 58, 76_401);
        $edge = max(0, min(1_000, intdiv(($macro + 11_000) * 1_000, 22_000)));

        return intdiv($climateStrength * $edge, 1_000);
    }

    private function riverHeight(int $height, int $riverStrength): int
    {
        if ($riverStrength < 650 || $height <= self::SEA_LEVEL - 3) {
            return $height;
        }
        $blend = ($riverStrength - 650) / 350;

        return (int) round($height * (1.0 - $blend) + (self::SEA_LEVEL - 3) * $blend);
    }

    private function oceanFloorHeight(int $x, int $z, int $height): int
    {
        if ($height >= self::SEA_LEVEL) {
            return $height;
        }
        $depth = self::SEA_LEVEL - $height;
        $macro = $this->noise->fractal2d($x, $z, 94, 3, 55, 76_701);
        $detail = $this->noise->fractal2d($x, $z, 27, 2, 48, 76_709);
        $micro = $this->noise->sample2d($x, $z, 9, 76_711);
        $signal = intdiv($macro * 3 + $detail * 2 + $micro * 4, 9);
        if ($depth <= 4) {
            // A symmetric perturbation clips positive values at sea level and
            // creates a conspicuous one-level shelf. Shallow water therefore
            // erodes downward only, while deeper bathymetry varies both ways.
            $offset = -(int) round((($signal + 32_768) / 65_536) * 6.0);
        } else {
            $amplitude = min(7.0, 3.0 + $depth / 4.5);
            $offset = (int) round(($signal / 32_768) * $amplitude);
        }
        $offset += match ($this->noise->scatteredChance($x, 0, $z, 76_719, 11)) {
            0 => -1,
            1 => 1,
            default => 0,
        };

        return min(self::SEA_LEVEL - 1, $height + $offset);
    }
}
