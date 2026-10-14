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

namespace Bedriox\Server\World\Generation\Surface;

use Bedriox\Server\World\Generation\SeededNoise;

/** Classifies coherent shore, lakebed, forest-floor, scree and exposed-rock regions. */
final class OverworldSurfaceField
{
    private const int ROCK_SALT = 71_101;
    private const int SEDIMENT_SALT = 71_303;
    private const int FOREST_FLOOR_SALT = 71_509;

    private const int SAMPLE_STEP = 4;
    private const int CACHE_LIMIT = 16_384;
    /** @var array<string, array{int, int, int}> */
    private array $maskCache = [];
    /** @var list<string> */
    private array $maskCacheOrder = [];

    public function __construct(private readonly SeededNoise $noise) {}

    public function sample(OverworldSurfaceContext $context): OverworldSurfaceSample
    {
        [$rockMask, $sedimentMask, $forestFloorMask] = $this->interpolatedMasks($context->x, $context->z);

        return new OverworldSurfaceSample(
            $this->material($context, $rockMask, $sedimentMask, $forestFloorMask),
            $rockMask,
            $sedimentMask,
            $forestFloorMask,
        );
    }

    /** @return array{int, int, int} */
    private function interpolatedMasks(int $x, int $z): array
    {
        $left = self::floorDiv($x, self::SAMPLE_STEP) * self::SAMPLE_STEP;
        $top = self::floorDiv($z, self::SAMPLE_STEP) * self::SAMPLE_STEP;
        $northWest = $this->masksAt($left, $top);
        $northEast = $this->masksAt($left + self::SAMPLE_STEP, $top);
        $southWest = $this->masksAt($left, $top + self::SAMPLE_STEP);
        $southEast = $this->masksAt($left + self::SAMPLE_STEP, $top + self::SAMPLE_STEP);
        $offsetX = $x - $left;
        $offsetZ = $z - $top;

        return [
            self::bilinear($northWest[0], $northEast[0], $southWest[0], $southEast[0], $offsetX, $offsetZ),
            self::bilinear($northWest[1], $northEast[1], $southWest[1], $southEast[1], $offsetX, $offsetZ),
            self::bilinear($northWest[2], $northEast[2], $southWest[2], $southEast[2], $offsetX, $offsetZ),
        ];
    }

    /** @return array{int, int, int} */
    private function masksAt(int $x, int $z): array
    {
        $key = $x . ':' . $z;
        $cached = $this->maskCache[$key] ?? null;
        if (is_array($cached)) {
            return $cached;
        }
        $masks = [
            $this->mixedField($x, $z, 176, 52, self::ROCK_SALT),
            $this->mixedField($x, $z, 144, 40, self::SEDIMENT_SALT),
            $this->mixedField($x, $z, 208, 56, self::FOREST_FLOOR_SALT),
        ];
        $this->maskCache[$key] = $masks;
        $this->maskCacheOrder[] = $key;
        if (count($this->maskCacheOrder) > self::CACHE_LIMIT) {
            foreach (array_splice($this->maskCacheOrder, 0, 2_048) as $expired) {
                unset($this->maskCache[$expired]);
            }
        }

        return $masks;
    }

    private static function bilinear(int $northWest, int $northEast, int $southWest, int $southEast, int $x, int $z): int
    {
        $north = $northWest + intdiv(($northEast - $northWest) * $x, self::SAMPLE_STEP);
        $south = $southWest + intdiv(($southEast - $southWest) * $x, self::SAMPLE_STEP);

        return $north + intdiv(($south - $north) * $z, self::SAMPLE_STEP);
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }

    private function material(
        OverworldSurfaceContext $context,
        int $rockMask,
        int $sedimentMask,
        int $forestFloorMask,
    ): OverworldSurfaceMaterial {
        if ($context->waterDepth > 0) {
            if ($context->riverStrength >= 600 || $sedimentMask < -8_000) {
                return OverworldSurfaceMaterial::GravelShore;
            }
            if ($context->humidity >= 7_000 || $sedimentMask > 9_000) {
                return OverworldSurfaceMaterial::MudBed;
            }

            return OverworldSurfaceMaterial::ClayBed;
        }

        $nearSeaLevel = abs($context->height - $context->seaLevel) <= 5;
        if ($nearSeaLevel && $context->distanceToWater <= 6) {
            if ($context->humidity >= 9_000) {
                return OverworldSurfaceMaterial::Grass;
            }
            if ($context->slope >= 10 && $rockMask > 2_000) {
                return OverworldSurfaceMaterial::Stone;
            }

            return $sedimentMask < -6_000
                ? OverworldSurfaceMaterial::GravelShore
                : OverworldSurfaceMaterial::Sand;
        }

        // Saturated lowlands keep a soil cap even where the broad terrain sample
        // sees a sharp wetland edge. This prevents flat marsh shelves from being
        // misclassified as exposed mountain rock.
        if ($context->humidity >= 9_000 && $context->height <= $context->seaLevel + 8) {
            return OverworldSurfaceMaterial::Grass;
        }

        // Exposed rock belongs to genuine escarpments. Requiring both a steep local
        // gradient and a connected regional mask prevents contour-following gray
        // ribbons from covering ordinary rolling hills.
        $highEscarpment = $context->height >= $context->seaLevel + 18
            && $context->slope >= 10
            && $rockMask >= 5_000;
        if ($context->slope >= 14 || $highEscarpment) {
            return OverworldSurfaceMaterial::Stone;
        }
        if ($context->slope >= 9 && $context->curvature <= -3 && $rockMask >= 5_000) {
            return OverworldSurfaceMaterial::GravelScree;
        }

        $humidForestFloor = $context->humidity >= 2_000 && $context->temperature < 18_000;
        if ($humidForestFloor && $forestFloorMask >= 9_000) {
            return OverworldSurfaceMaterial::Podzol;
        }
        if ($forestFloorMask <= -13_000 && $context->humidity < 8_000) {
            return OverworldSurfaceMaterial::CoarseDirt;
        }

        return OverworldSurfaceMaterial::Grass;
    }

    private function mixedField(int $x, int $z, int $macroScale, int $detailScale, int $salt): int
    {
        $macro = $this->noise->fractal2d($x, $z, $macroScale, 3, 55, $salt);
        $detail = $this->noise->fractal2d($x, $z, $detailScale, 2, 45, $salt + 1_009);

        return intdiv($macro * 3 + $detail, 4);
    }
}
