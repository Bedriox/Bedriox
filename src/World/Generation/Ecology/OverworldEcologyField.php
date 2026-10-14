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

namespace Bedriox\Server\World\Generation\Ecology;

use Bedriox\Server\World\Generation\SeededNoise;

/** Builds continuous forest cores, edges and clearings with terrain-aware exclusions. */
final readonly class OverworldEcologyField
{
    public function __construct(private SeededNoise $noise) {}

    public function sample(OverworldEcologyContext $context): OverworldEcologySample
    {
        $macro = $this->noise->fractal2d($context->x, $context->z, 288, 3, 58, 83_101);
        $grove = $this->noise->fractal2d($context->x, $context->z, 96, 3, 52, 83_503);
        $detail = $this->noise->fractal2d($context->x, $context->z, 36, 2, 45, 83_903);
        $habitatMask = intdiv($macro * 5 + $grove * 3 + $detail * 2, 10);

        if ($context->waterDepth > 0 || $context->slope >= 7
            || $context->distanceToStructure <= 4 || $context->distanceToCaveEntrance <= 3) {
            return new OverworldEcologySample(OverworldEcologyZone::Excluded, 0, $habitatMask);
        }

        $density = $context->baseDensityPermille + intdiv($habitatMask * 700, 32_768);
        if ($context->slope > 2) {
            $density = intdiv($density * max(0, 7 - $context->slope), 5);
        }
        if ($context->distanceToWater < 6) {
            $density = intdiv($density * $context->distanceToWater, 6);
        }
        $density = max(0, min(1_000, $density));

        $zone = match (true) {
            $density < 200 => OverworldEcologyZone::Clearing,
            $density < 380 => OverworldEcologyZone::Edge,
            $density < 700 => OverworldEcologyZone::Woodland,
            default => OverworldEcologyZone::Core,
        };

        return new OverworldEcologySample($zone, $density, $habitatMask);
    }

    public function placementRoll(int $x, int $z): int
    {
        return $this->noise->scatteredChance($x, 0, $z, 84_307, 1_000);
    }
}
