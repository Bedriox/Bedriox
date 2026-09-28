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

use Bedriox\Server\World\Chunk;

/** Produces coherent continental, erosion, climate, river, and elevation fields. */
final readonly class OverworldTerrainSampler
{
    public const int SEA_LEVEL = 62;

    public function __construct(private SeededNoise $noise) {}

    public function sample(int $x, int $z): OverworldTerrainSample
    {
        $center = $this->climateAt($x, $z);
        $height = $this->surfaceHeight($center);
        $slope = max(
            abs($height - $this->heightAt($x + 2, $z)),
            abs($height - $this->heightAt($x - 2, $z)),
            abs($height - $this->heightAt($x, $z + 2)),
            abs($height - $this->heightAt($x, $z - 2)),
        );

        return new OverworldTerrainSample($center, $height, $slope, $this->riverStrength($center));
    }

    public function heightAt(int $x, int $z): int
    {
        return $this->surfaceHeight($this->climateAt($x, $z));
    }

    public function climateAt(int $x, int $z): OverworldClimate
    {
        $warpX = intdiv($this->noise->fractal2d($x, $z, 420, 2, 52, 1_003) * 72, 32_768);
        $warpZ = intdiv($this->noise->fractal2d($x, $z, 420, 2, 52, 1_211) * 72, 32_768);
        $warpedX = $x + $warpX;
        $warpedZ = $z + $warpZ;

        return new OverworldClimate(
            $this->noise->fractal2d($warpedX, $warpedZ, 1_100, 3, 54, 101),
            $this->noise->fractal2d($warpedX, $warpedZ, 520, 3, 57, 211),
            $this->noise->fractal2d($x, $z, 780, 3, 55, 307),
            $this->noise->fractal2d($warpedX, $warpedZ, 690, 3, 57, 401),
            $this->noise->fractal2d($warpedX, $warpedZ, 310, 3, 51, 503),
            $this->noise->fractal2d($warpedX, $warpedZ, 680, 3, 55, 601),
            $this->noise->fractal2d($warpedX, $warpedZ, 360, 3, 48, 701),
            $this->noise->fractal2d($warpedX, $warpedZ, 145, 3, 50, 809),
        );
    }

    public function surfaceHeight(OverworldClimate $climate): int
    {
        $continental = $climate->continentalness / 32_768;
        if ($continental < -0.48) {
            $base = 36.0 + (($continental + 1.0) / 0.52) * 16.0;
        } elseif ($continental < -0.12) {
            $base = 52.0 + (($continental + 0.48) / 0.36) * 12.0;
        } else {
            $base = 64.0 + min(1.0, ($continental + 0.12) / 1.12) * 13.0;
        }

        $erosion = ($climate->erosion + 32_768) / 65_536;
        $detail = ($climate->detail / 32_768) * (3.0 + (1.0 - $erosion) * 4.5);
        $ridgeShape = 1.0 - abs($climate->ridge / 32_768);
        $uplift = max(0.0, ($climate->uplift / 32_768) - 0.08);
        $land = max(0.0, min(1.0, ($continental + 0.16) * 2.4));
        $mountainMask = max(0.0, $uplift * 1.40 + $ridgeShape * 0.62 - 0.48);
        $mountains = ($mountainMask ** 1.7) * (52.0 + $ridgeShape * 48.0) * $land * (1.0 - $erosion * 0.58);
        $plateauSignal = $climate->uplift / 32_768;
        $plateau = $plateauSignal > 0.52 && $continental > 0.05
            ? min(18.0, ($plateauSignal - 0.52) * 55.0)
            : 0.0;
        $height = $base + $detail + $mountains + $plateau;

        $riverStrength = $this->riverStrength($climate);
        if ($riverStrength > 0 && $continental > -0.08) {
            $riverBed = self::SEA_LEVEL - 3 + ($climate->detail > 10_000 ? 1 : 0);
            $riverBlend = $riverStrength / 1_000;
            $height = $height * (1.0 - $riverBlend) + $riverBed * $riverBlend;
        }

        return max(Chunk::MIN_Y + 6, min(196, (int) round($height)));
    }

    public function riverStrength(OverworldClimate $climate): int
    {
        if ($climate->continentalness < -3_000) {
            return 0;
        }
        $distance = abs($climate->river);
        if ($distance >= 2_700) {
            return 0;
        }

        $normalized = 1.0 - ($distance / 2_700);

        return (int) round(($normalized * $normalized * (3.0 - 2.0 * $normalized)) * 1_000);
    }
}
