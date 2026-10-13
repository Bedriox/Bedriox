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

namespace Bedriox\Server\World\Generation\Noise;

use Closure;

/**
 * Maps the large-scale climate fields to the three values used by the
 * overworld density function. The curves are seed-independent and therefore
 * built once per sampler instance.
 */
final class OverworldTerrainShapeSampler
{
    private const float GLOBAL_OFFSET = -0.5037500262260437;
    private const float FACTOR_SCALE = 128.0;

    private readonly CubicSpline $offset;
    private readonly CubicSpline $factor;
    private readonly CubicSpline $jaggedness;

    public function __construct()
    {
        $this->offset = $this->buildOffset();
        $this->factor = $this->buildFactor();
        $this->jaggedness = $this->buildJaggedness();
    }

    public function sample(float $continentalness, float $erosion, float $weirdness): TerrainShape
    {
        $point = new TerrainClimatePoint($continentalness, $erosion, $weirdness);

        return new TerrainShape(
            self::GLOBAL_OFFSET + $this->offset->evaluate($point),
            self::FACTOR_SCALE * $this->factor->evaluate($point),
            $this->jaggedness->evaluate($point),
        );
    }

    private function buildFactor(): CubicSpline
    {
        $ridge = self::ridge(...);
        $folded = self::foldedRidge(...);
        $erosion = self::erosion(...);
        $continents = self::continentalness(...);
        $rA = self::s($ridge, self::p(-0.2, 6.3), self::p(0.2, 6.25));
        $rB = self::s($ridge, self::p(-0.05, 6.3), self::p(0.05, 2.67));
        $rC = self::s($ridge, self::p(-0.05, 2.67), self::p(0.05, 6.3));
        $rD = self::s($ridge, self::p(-0.2, 6.3), self::p(0.2, 5.47));
        $rE = self::s($ridge, self::p(-0.2, 6.3), self::p(0.2, 5.08));
        $rF = self::s($ridge, self::p(-0.2, 6.3), self::p(0.2, 4.69));
        $rG = self::s($ridge, self::p(0.0, 6.25), self::p(0.1, 0.625));
        $rH = self::s($ridge, self::p(0.0, 5.47), self::p(0.1, 0.625));
        $rI = self::s($ridge, self::p(0.0, 5.08), self::p(0.1, 0.625));
        $fA = self::s($folded, self::p(-0.9, 6.25), self::p(-0.69, $rG));
        $fB = self::s($folded, self::p(-0.9, 5.47), self::p(-0.69, $rH));
        $fC = self::s($folded, self::p(-0.9, 5.08), self::p(-0.69, $rI));
        $fD = self::s($folded, self::p(0.45, $rF), self::p(0.7, 1.56));
        $fE = self::s($folded, self::p(-0.7, $rF), self::p(-0.15, 1.37));
        $e1 = self::s($erosion, self::p(-0.6, $rA), self::p(-0.5, $rB), self::p(-0.35, $rA), self::p(-0.25, $rA), self::p(-0.1, $rC), self::p(0.03, $rA), self::p(0.35, 6.25), self::p(0.45, $fA), self::p(0.55, $fA), self::p(0.62, 6.25));
        $e2 = self::s($erosion, self::p(-0.6, $rD), self::p(-0.5, $rB), self::p(-0.35, $rD), self::p(-0.25, $rD), self::p(-0.1, $rC), self::p(0.03, $rD), self::p(0.35, 5.47), self::p(0.45, $fB), self::p(0.55, $fB), self::p(0.62, 5.47));
        $e3 = self::s($erosion, self::p(-0.6, $rE), self::p(-0.5, $rB), self::p(-0.35, $rE), self::p(-0.25, $rE), self::p(-0.1, $rC), self::p(0.03, $rE), self::p(0.35, 5.08), self::p(0.45, $fC), self::p(0.55, $fC), self::p(0.62, 5.08));
        $e4 = self::s($erosion, self::p(-0.6, $rF), self::p(-0.5, $rB), self::p(-0.35, $rF), self::p(-0.25, $rF), self::p(-0.1, $rC), self::p(0.03, $rF), self::p(0.05, $fD), self::p(0.4, $fD), self::p(0.45, $fE), self::p(0.55, $fE), self::p(0.58, 4.69));

        return self::s($continents, self::p(-0.19, 3.95), self::p(-0.15, $e1), self::p(-0.1, $e2), self::p(0.03, $e3), self::p(0.06, $e4));
    }

    private function buildJaggedness(): CubicSpline
    {
        $ridge = self::ridge(...);
        $folded = self::foldedRidge(...);
        $erosion = self::erosion(...);
        $r1 = self::s($ridge, self::p(-0.01, 0.63), self::p(0.01, 0.3));
        $r2 = self::s($ridge, self::p(-0.01, 0.315), self::p(0.01, 0.15));
        $f1 = self::s($folded, self::p(0.19999999, 0.0), self::p(0.44999996, 0.0), self::p(1.0, $r1));
        $f2 = self::s($folded, self::p(0.19999999, 0.0), self::p(0.44999996, 0.0), self::p(1.0, $r2));
        $f4 = self::s($folded, self::p(0.19999999, 0.0), self::p(0.44999996, $r1), self::p(1.0, $r1));
        $e1 = self::s($erosion, self::p(-1.0, $f1), self::p(-0.78, $f2), self::p(-0.5775, $f2), self::p(-0.375, 0.0));
        $e2 = self::s($erosion, self::p(-1.0, $f4), self::p(-0.78, $f1), self::p(-0.5775, $f1), self::p(-0.375, 0.0));

        return self::s(self::continentalness(...), self::p(-0.11, 0.0), self::p(0.03, $e1), self::p(0.65, $e2));
    }

    private function buildOffset(): CubicSpline
    {
        $folded = self::foldedRidge(...);
        $erosion = self::erosion(...);
        $r = [];
        $r[] = self::s($folded, self::p(-1.0, -0.08880186, 0.38940096), self::p(1.0, 0.69000006, 0.38940096));
        $r[] = self::s($folded, self::p(-1.0, -0.115760356, 0.37788022), self::p(1.0, 0.6400001, 0.37788022));
        $r[] = self::s($folded, self::p(-1.0, -0.2222), self::p(-0.75, -0.2222), self::p(-0.65, 0.0), self::p(0.5954547, 2.9802322E-8), self::p(0.6054547, 2.9802322E-8, 0.2534563), self::p(1.0, 0.100000024, 0.2534563));
        $r[] = self::s($folded, self::p(-1.0, -0.3, 0.5), self::p(-0.4, 0.05), self::p(0.0, 0.05), self::p(0.4, 0.05), self::p(1.0, 0.060000002, 0.007000001));
        $r[] = self::s($folded, self::p(-1.0, -0.15, 0.5), self::p(-0.4, 0.0), self::p(0.0, 0.0), self::p(0.4, 0.05, 0.1), self::p(1.0, 0.060000002, 0.007000001));
        $r[] = self::s($folded, self::p(-1.0, -0.15, 0.5), self::p(-0.4, 0.0), self::p(0.0, 0.0), self::p(0.4, 0.0), self::p(1.0, 0.0));
        $r[] = self::s($folded, self::p(-1.0, -0.02), self::p(-0.4, -0.03), self::p(0.0, -0.03), self::p(0.4, 0.0, 0.06), self::p(1.0, 0.0));
        $r[] = self::s($folded, self::p(-1.0, -0.25, 0.5), self::p(-0.4, 0.05), self::p(0.0, 0.05), self::p(0.4, 0.05), self::p(1.0, 0.060000002, 0.007000001));
        $r[] = self::s($folded, self::p(-1.0, -0.1, 0.5), self::p(-0.4, 0.001, 0.01), self::p(0.0, 0.003, 0.01), self::p(0.4, 0.05, 0.094000004), self::p(1.0, 0.060000002, 0.007000001));
        $r[] = self::s($folded, self::p(-1.0, -0.1, 0.5), self::p(-0.4, 0.01), self::p(0.0, 0.01), self::p(0.4, 0.03, 0.04), self::p(1.0, 0.1, 0.049));
        $r[] = self::s($folded, self::p(-1.0, -0.02, 0.015), self::p(-0.4, 0.01), self::p(0.0, 0.01), self::p(0.4, 0.03, 0.04), self::p(1.0, 0.1, 0.049));
        $r[] = self::s($folded, self::p(-1.0, 0.20235021), self::p(0.0, 0.7161751, 0.5138249), self::p(1.0, 1.23, 0.5138249));
        $r[] = self::s($folded, self::p(-1.0, 0.2), self::p(0.0, 0.44682026, 0.43317974), self::p(1.0, 0.88, 0.43317974));
        $r[] = self::s($folded, self::p(-1.0, 0.2), self::p(0.0, 0.30829495, 0.3917051), self::p(1.0, 0.70000005, 0.3917051));
        $r[] = self::s($folded, self::p(-1.0, -0.25, 0.5), self::p(-0.4, 0.35), self::p(0.0, 0.35), self::p(0.4, 0.35), self::p(1.0, 0.42000002, 0.049000014));
        $r[] = self::s($folded, self::p(-1.0, -0.1, 0.5), self::p(-0.4, 0.0069999998, 0.07), self::p(0.0, 0.021, 0.07), self::p(0.4, 0.35, 0.658), self::p(1.0, 0.42000002, 0.049000014));
        $r[] = self::s($folded, self::p(-1.0, -0.1, 0.5), self::p(-0.4, 0.01), self::p(0.0, 0.01), self::p(0.4, 0.03, 0.04), self::p(1.0, 0.1, 0.049));
        $r[] = self::s($folded, self::p(-1.0, -0.05, 0.5), self::p(-0.4, 0.01), self::p(0.0, 0.01), self::p(0.4, 0.03, 0.04), self::p(1.0, 0.1, 0.049));
        $r[] = self::s($folded, self::p(-1.0, 0.2), self::p(0.0, 0.5391705, 0.4608295), self::p(1.0, 1.0, 0.4608295));
        $r[] = self::s($folded, self::p(-1.0, -0.2, 0.5), self::p(-0.4, 0.5), self::p(0.0, 0.5), self::p(0.4, 0.5), self::p(1.0, 0.6, 0.070000015));
        $r[] = self::s($folded, self::p(-1.0, -0.05, 0.5), self::p(-0.4, 0.01, 0.099999994), self::p(0.0, 0.03, 0.099999994), self::p(0.4, 0.5, 0.94), self::p(1.0, 0.6, 0.070000015));
        $r[] = self::s($folded, self::p(-1.0, -0.05, 0.5), self::p(-0.4, 0.01), self::p(0.0, 0.01), self::p(0.4, 0.03, 0.04), self::p(1.0, 0.1, 0.049));
        $r[] = self::s($folded, self::p(-1.0, -0.02, 0.015), self::p(-0.4, 0.01), self::p(0.0, 0.01), self::p(0.4, 0.03, 0.04), self::p(1.0, 0.1, 0.049));
        $r[] = self::s($folded, self::p(-1.0, 0.34792626), self::p(0.0, 0.9239631, 0.5760369), self::p(1.0, 1.5, 0.5760369));
        $r[] = self::s($folded, self::p(-1.0, -0.1), self::p(-0.4, 0.1), self::p(0.0, 0.17));
        $r[] = self::s($folded, self::p(-1.0, -0.05), self::p(-0.4, 0.1), self::p(0.0, 0.17));
        $e1 = self::s($erosion, self::p(-0.85, $r[0]), self::p(-0.7, $r[1]), self::p(-0.4, $r[2]), self::p(-0.35, $r[3]), self::p(-0.1, $r[4]), self::p(0.2, $r[5]), self::p(0.7, $r[6]));
        $e2 = self::s($erosion, self::p(-0.85, $r[0]), self::p(-0.7, $r[1]), self::p(-0.4, $r[2]), self::p(-0.35, $r[7]), self::p(-0.1, $r[8]), self::p(0.2, $r[9]), self::p(0.7, $r[10]));
        $e3 = self::s($erosion, self::p(-0.85, $r[11]), self::p(-0.7, $r[12]), self::p(-0.4, $r[13]), self::p(-0.35, $r[14]), self::p(-0.1, $r[15]), self::p(0.2, $r[16]), self::p(0.4, $r[16]), self::p(0.45, $r[24]), self::p(0.55, $r[24]), self::p(0.58, $r[17]), self::p(0.7, $r[10]));
        $e4 = self::s($erosion, self::p(-0.85, $r[23]), self::p(-0.7, $r[18]), self::p(-0.4, $r[18]), self::p(-0.35, $r[19]), self::p(-0.1, $r[20]), self::p(0.2, $r[21]), self::p(0.4, $r[21]), self::p(0.45, $r[25]), self::p(0.55, $r[25]), self::p(0.58, $r[21]), self::p(0.7, $r[22]));

        return self::s(self::continentalness(...), self::p(-1.1, 0.044), self::p(-1.02, -0.2222), self::p(-0.51, -0.2222), self::p(-0.44, -0.12), self::p(-0.18, -0.12), self::p(-0.16, $e1), self::p(-0.15, $e1), self::p(-0.1, $e2), self::p(0.25, $e3), self::p(1.0, $e4));
    }

    private static function p(float $location, float|CubicSpline $value, float $derivative = 0.0): CubicSplinePoint
    {
        return new CubicSplinePoint($location, $value, $derivative);
    }

    private static function s(Closure $coordinate, CubicSplinePoint $first, CubicSplinePoint ...$points): CubicSpline
    {
        $controlPoints = [$first];
        foreach ($points as $point) {
            $controlPoints[] = $point;
        }

        return new CubicSpline($coordinate, $controlPoints);
    }

    private static function continentalness(TerrainClimatePoint $point): float
    {
        return $point->continentalness;
    }

    private static function erosion(TerrainClimatePoint $point): float
    {
        return $point->erosion;
    }

    private static function ridge(TerrainClimatePoint $point): float
    {
        return $point->ridge;
    }

    private static function foldedRidge(TerrainClimatePoint $point): float
    {
        return $point->foldedRidge;
    }
}
