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

namespace Bedriox\Server\World\Generation\Vegetation;

use Bedriox\Server\World\Generation\Ecology\OverworldEcologyContext;
use Bedriox\Server\World\Generation\Ecology\OverworldEcologyField;
use Bedriox\Server\World\Generation\SeededNoise;
use Closure;

/** Produces seam-stable, irregularly spaced tree plans with biome-scale clearings and groves. */
final class OverworldVegetationPlanner
{
    private const int CELL_SIZE = 5;
    private const int SEA_LEVEL = 63;
    public const int MAXIMUM_REACH = 8;
    private const int CACHE_LIMIT = 32_768;

    /** @var array<string, VegetationTreePlan|false> */
    private array $candidateCache = [];

    /** @var list<string> */
    private array $candidateCacheOrder = [];

    /** @var array<string, bool> */
    private array $activeCache = [];

    /** @var list<string> */
    private array $activeCacheOrder = [];

    private readonly OverworldEcologyField $ecology;

    public function __construct(private readonly SeededNoise $noise)
    {
        $this->ecology = new OverworldEcologyField($noise);
    }

    /**
     * @param Closure(int, int): string $biomeAt
     * @param Closure(int, int): int    $surfaceHeightAt
     * @return list<VegetationTreePlan>
     */
    public function plansAffecting(
        int $minimumX,
        int $maximumX,
        int $minimumZ,
        int $maximumZ,
        Closure $biomeAt,
        Closure $surfaceHeightAt,
        ?Closure $shapeHeightAt = null,
    ): array {
        $shapeHeightAt ??= $surfaceHeightAt;
        $cellMinimumX = self::floorDiv($minimumX - self::MAXIMUM_REACH, self::CELL_SIZE);
        $cellMaximumX = self::floorDiv($maximumX + self::MAXIMUM_REACH, self::CELL_SIZE);
        $cellMinimumZ = self::floorDiv($minimumZ - self::MAXIMUM_REACH, self::CELL_SIZE);
        $cellMaximumZ = self::floorDiv($maximumZ + self::MAXIMUM_REACH, self::CELL_SIZE);
        $plans = [];
        for ($cellZ = $cellMinimumZ; $cellZ <= $cellMaximumZ; ++$cellZ) {
            for ($cellX = $cellMinimumX; $cellX <= $cellMaximumX; ++$cellX) {
                $candidate = $this->candidate($cellX, $cellZ, $biomeAt, $surfaceHeightAt, $shapeHeightAt);
                if ($candidate === null || !$this->winsSpacingCompetition(
                    $candidate,
                    $cellX,
                    $cellZ,
                    $biomeAt,
                    $surfaceHeightAt,
                    $shapeHeightAt,
                )) {
                    continue;
                }
                $plans[] = $candidate;
            }
        }

        return $plans;
    }

    /**
     * @param Closure(int, int): string $biomeAt
     * @param Closure(int, int): int    $surfaceHeightAt
     */
    private function candidate(
        int $cellX,
        int $cellZ,
        Closure $biomeAt,
        Closure $surfaceHeightAt,
        Closure $shapeHeightAt,
    ): ?VegetationTreePlan
    {
        $key = $cellX . ':' . $cellZ;
        $cached = $this->candidateCache[$key] ?? null;
        if ($cached instanceof VegetationTreePlan) {
            return $cached;
        }
        if ($cached === false) {
            return null;
        }
        $x = $cellX * self::CELL_SIZE + $this->noise->scatteredChance($cellX, 0, $cellZ, 91_001, self::CELL_SIZE);
        $z = $cellZ * self::CELL_SIZE + $this->noise->scatteredChance($cellX, 1, $cellZ, 91_003, self::CELL_SIZE);
        $biome = $biomeAt($x, $z);
        $profile = VegetationProfileCatalog::forBiome($biome);
        if ($profile === null) {
            $this->remember($key, false);
            return null;
        }
        if (!$this->activeForProfile($cellX, $cellZ, $profile, $surfaceHeightAt, $shapeHeightAt)) {
            $this->remember($key, false);
            return null;
        }
        $archetype = $profile->selectArchetype($this->noise->scatteredChance($cellX, 3, $cellZ, 91_013, 65_536));
        $height = $archetype->baseHeight()
            + $this->noise->scatteredChance($x, 4, $z, 91_019, $archetype->heightVariation());
        $plan = new VegetationTreePlan(
            $x,
            $surfaceHeightAt($x, $z),
            $z,
            $biome,
            $profile->name,
            $archetype,
            $height,
            $this->noise->scatteredChance($cellX, 5, $cellZ, 91_021, 4),
            ($this->noise->scatteredChance($x, 6, $z, 91_027, 65_536) << 16)
                | $this->noise->scatteredChance($x, 8, $z, 91_029, 65_536),
            $this->noise->scatteredChance($cellX, 7, $cellZ, 91_031, 65_536),
            $profile->minimumSpacing,
        );
        $this->remember($key, $plan);

        return $plan;
    }

    /**
     * @param Closure(int, int): int $surfaceHeightAt
     * @param Closure(int, int): int $shapeHeightAt
     * @param Closure(int, int): string $biomeAt
     */
    private function winsSpacingCompetition(
        VegetationTreePlan $candidate,
        int $cellX,
        int $cellZ,
        Closure $biomeAt,
        Closure $surfaceHeightAt,
        Closure $shapeHeightAt,
    ): bool {
        $cellRadius = (int) ceil($candidate->minimumSpacing / self::CELL_SIZE);
        for ($neighborZ = $cellZ - $cellRadius; $neighborZ <= $cellZ + $cellRadius; ++$neighborZ) {
            for ($neighborX = $cellX - $cellRadius; $neighborX <= $cellX + $cellRadius; ++$neighborX) {
                if ($neighborX === $cellX && $neighborZ === $cellZ) {
                    continue;
                }
                $neighbor = $this->candidate(
                    $neighborX,
                    $neighborZ,
                    $biomeAt,
                    $surfaceHeightAt,
                    $shapeHeightAt,
                );
                if ($neighbor === null) {
                    continue;
                }
                $deltaX = $candidate->x - $neighbor->x;
                $deltaZ = $candidate->z - $neighbor->z;
                $minimumSpacing = max($candidate->minimumSpacing, $neighbor->minimumSpacing);
                if ($deltaX * $deltaX + $deltaZ * $deltaZ >= $minimumSpacing * $minimumSpacing) {
                    continue;
                }
                if ($neighbor->priority < $candidate->priority
                    || ($neighbor->priority === $candidate->priority
                        && ($neighborZ < $cellZ || ($neighborZ === $cellZ && $neighborX < $cellX)))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param Closure(int, int): int $surfaceHeightAt
     * @param Closure(int, int): int $shapeHeightAt
     */
    private function activeForProfile(
        int $cellX,
        int $cellZ,
        VegetationProfile $profile,
        Closure $surfaceHeightAt,
        Closure $shapeHeightAt,
    ): bool {
        $key = $profile->name . ':' . $cellX . ':' . $cellZ;
        if (isset($this->activeCache[$key])) {
            return $this->activeCache[$key];
        }
        $x = $cellX * self::CELL_SIZE + $this->noise->scatteredChance($cellX, 0, $cellZ, 91_001, self::CELL_SIZE);
        $z = $cellZ * self::CELL_SIZE + $this->noise->scatteredChance($cellX, 1, $cellZ, 91_003, self::CELL_SIZE);
        $profileClearing = $this->noise->sample2d($x, $z, $profile->clusterScale, 91_007);
        if ($profileClearing < $profile->clearingThreshold) {
            $this->rememberActive($key, false);

            return false;
        }
        $height = $surfaceHeightAt($x, $z);
        $shapeHeight = $shapeHeightAt === $surfaceHeightAt ? $height : $shapeHeightAt($x, $z);
        $slope = max(
            abs($shapeHeight - $shapeHeightAt($x + 2, $z)),
            abs($shapeHeight - $shapeHeightAt($x - 2, $z)),
            abs($shapeHeight - $shapeHeightAt($x, $z + 2)),
            abs($shapeHeight - $shapeHeightAt($x, $z - 2)),
        );
        $ecology = $this->ecology->sample(new OverworldEcologyContext(
            $x,
            $z,
            $profile->densityPermille,
            min(64, $slope),
            max(0, self::SEA_LEVEL - $height),
            $height < self::SEA_LEVEL ? 0 : 20,
            4_096,
            4_096,
        ));
        // The ecology density is the sole activation probability. Applying the old
        // macro chance as well would multiply two filters and empty dense forests.
        // The profile mask remains a deterministic zero-density clearing boundary,
        // while ecology supplies the continuous core/edge density inside woodland.
        $active = $ecology->accepts($this->ecology->placementRoll($x, $z));
        $this->rememberActive($key, $active);

        return $active;
    }

    private function rememberActive(string $key, bool $active): void
    {
        $this->activeCache[$key] = $active;
        $this->activeCacheOrder[] = $key;
        if (count($this->activeCacheOrder) > self::CACHE_LIMIT * 2) {
            foreach (array_splice($this->activeCacheOrder, 0, 4_096) as $expired) {
                unset($this->activeCache[$expired]);
            }
        }

    }

    private function remember(string $key, VegetationTreePlan|false $candidate): void
    {
        $this->candidateCache[$key] = $candidate;
        $this->candidateCacheOrder[] = $key;
        if (count($this->candidateCacheOrder) <= self::CACHE_LIMIT) {
            return;
        }
        foreach (array_splice($this->candidateCacheOrder, 0, 4_096) as $expired) {
            unset($this->candidateCache[$expired]);
        }
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);
        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
