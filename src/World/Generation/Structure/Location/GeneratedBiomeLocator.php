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

namespace Bedriox\Server\World\Generation\Structure\Location;

use Bedriox\Api\World\DimensionType;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\DefaultWorldGenerator;
use Bedriox\Server\World\NetherWorldGenerator;

/** Searches immutable generator samplers without generating, loading, or retaining chunks. */
final readonly class GeneratedBiomeLocator
{
    private const int COARSE_STEP = 192;
    private const int MAXIMUM_RADIUS = 12_288;
    private const array CAVE_HEIGHTS = [-60, -52, -44, -36, -28, -20, -12, -4, 4, 12, 20, 28, 36, 44];
    private DefaultWorldGenerator $overworld;
    private NetherWorldGenerator $nether;

    public function __construct(int $seed, BlockStateRegistry $states)
    {
        $this->overworld = new DefaultWorldGenerator($seed, $states);
        $this->nether = new NetherWorldGenerator($seed, $states);
    }

    public function locate(DimensionType $dimension, string $identifier, int $originX, int $originY, int $originZ): ?LocatedFeature
    {
        return match ($dimension) {
            DimensionType::OVERWORLD => $this->locateOverworld($identifier, $originX, $originZ),
            DimensionType::NETHER => $this->locateNether($identifier, $originX, $originZ),
            DimensionType::END => $identifier === 'minecraft:the_end'
                ? new LocatedFeature($identifier, $originX, max(0, min(255, $originY)), $originZ)
                : null,
        };
    }

    private function locateOverworld(string $identifier, int $originX, int $originZ): ?LocatedFeature
    {
        $cave = in_array($identifier, [
            'minecraft:deep_dark', 'minecraft:lush_caves',
            'minecraft:dripstone_caves', 'minecraft:sulfur_caves',
        ], true);
        $candidate = $this->coarseSearch(
            $originX,
            $originZ,
            function (int $x, int $z) use ($identifier, $cave): ?int {
                if (!$cave) {
                    $y = $this->overworld->locateSurfaceHeight($x, $z) + 1;

                    return $this->overworld->locateBiomeAt($x, $y, $z)->identifier === $identifier ? $y : null;
                }
                foreach (self::CAVE_HEIGHTS as $y) {
                    if ($this->overworld->locateBiomeAt(self::cellCenter($x), $y, self::cellCenter($z))->identifier === $identifier) {
                        return $y;
                    }
                }
                return null;
            },
        );
        if ($candidate === null) {
            return null;
        }
        [$x, $y, $z] = $this->refine(
            $candidate,
            $originX,
            $originZ,
            function (int $sampleX, int $sampleZ) use ($identifier, $cave, $candidate): ?int {
                if (!$cave) {
                    $sampleY = $this->overworld->locateSurfaceHeight($sampleX, $sampleZ) + 1;
                    return $this->overworld->locateBiomeAt($sampleX, $sampleY, $sampleZ)->identifier === $identifier
                        ? $sampleY
                        : null;
                }
                foreach (array_unique([$candidate[1], ...self::CAVE_HEIGHTS]) as $sampleY) {
                    if ($this->overworld->locateBiomeAt(
                        self::cellCenter($sampleX),
                        $sampleY,
                        self::cellCenter($sampleZ),
                    )->identifier === $identifier) {
                        return $sampleY;
                    }
                }
                return null;
            },
        );
        if ($cave) {
            $x = self::cellCenter($x);
            $z = self::cellCenter($z);
        }

        return new LocatedFeature($identifier, $x, $y, $z);
    }

    private function locateNether(string $identifier, int $originX, int $originZ): ?LocatedFeature
    {
        $candidate = $this->coarseSearch(
            $originX,
            $originZ,
            fn(int $x, int $z): ?int => $this->nether->biomeAt($x, $z) === $identifier ? 64 : null,
        );
        if ($candidate === null) {
            return null;
        }
        [$x, $y, $z] = $this->refine(
            $candidate,
            $originX,
            $originZ,
            fn(int $sampleX, int $sampleZ): ?int => $this->nether->biomeAt($sampleX, $sampleZ) === $identifier ? 64 : null,
        );

        return new LocatedFeature($identifier, $x, $y, $z);
    }

    /**
     * @param callable(int, int): ?int $matchingY
     * @return array{int, int, int}|null
     */
    private function coarseSearch(int $originX, int $originZ, callable $matchingY): ?array
    {
        $originY = $matchingY($originX, $originZ);
        if ($originY !== null) {
            return [$originX, $originY, $originZ];
        }
        for ($radius = self::COARSE_STEP; $radius <= self::MAXIMUM_RADIUS; $radius += self::COARSE_STEP) {
            $minimum = -$radius;
            for ($offset = $minimum; $offset <= $radius; $offset += self::COARSE_STEP) {
                foreach ([
                    [$originX + $offset, $originZ + $minimum],
                    [$originX + $offset, $originZ + $radius],
                    [$originX + $minimum, $originZ + $offset],
                    [$originX + $radius, $originZ + $offset],
                ] as [$x, $z]) {
                    $y = $matchingY($x, $z);
                    if ($y !== null) {
                        return [$x, $y, $z];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array{int, int, int}       $candidate
     * @param callable(int, int): ?int   $matchingY
     * @return array{int, int, int}
     */
    private function refine(array $candidate, int $originX, int $originZ, callable $matchingY): array
    {
        $best = $candidate;
        $bestDistance = self::distanceSquared($candidate[0], $candidate[2], $originX, $originZ);
        foreach ([48, 12, 3, 1] as $step) {
            $centerX = $best[0];
            $centerZ = $best[2];
            $radius = $step * 2;
            for ($z = $centerZ - $radius; $z <= $centerZ + $radius; $z += $step) {
                for ($x = $centerX - $radius; $x <= $centerX + $radius; $x += $step) {
                    $y = $matchingY($x, $z);
                    if ($y === null) {
                        continue;
                    }
                    $distance = self::distanceSquared($x, $z, $originX, $originZ);
                    if ($distance < $bestDistance || ($distance === $bestDistance && [$x, $y, $z] < $best)) {
                        $best = [$x, $y, $z];
                        $bestDistance = $distance;
                    }
                }
            }
        }

        return $best;
    }

    private static function distanceSquared(int $x, int $z, int $originX, int $originZ): int
    {
        return ($x - $originX) ** 2 + ($z - $originZ) ** 2;
    }

    private static function cellCenter(int $coordinate): int
    {
        $cell = $coordinate >= 0 ? intdiv($coordinate, 8) : -intdiv(-$coordinate + 7, 8);

        return $cell * 8 + 4;
    }
}
