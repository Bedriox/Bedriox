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

namespace Bedriox\Server\World\Generation\Structure\End;

use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\SeededNoise;

/** Deterministic outer-island city graph roots, including optional detached ships. */
final readonly class EndStructurePlanner
{
    private const int REGION = 512;
    private const int RADIUS = 104;

    private SeededNoise $noise;

    private int $seed;

    public function __construct(int $seed)
    {
        $this->seed = $seed;
        $this->noise = new SeededNoise($seed ^ 0x454e4421);
    }

    /** @return list<EndStructurePlan> */
    public function intersecting(ChunkPosition $position): array
    {
        $chunkMinimumX = $position->x * 16;
        $chunkMaximumX = $chunkMinimumX + 15;
        $chunkMinimumZ = $position->z * 16;
        $chunkMaximumZ = $chunkMinimumZ + 15;
        $plans = [];
        for ($regionX = self::floorDiv($chunkMinimumX - self::RADIUS, self::REGION); $regionX <= self::floorDiv($chunkMaximumX + self::RADIUS, self::REGION); ++$regionX) {
            for ($regionZ = self::floorDiv($chunkMinimumZ - self::RADIUS, self::REGION); $regionZ <= self::floorDiv($chunkMaximumZ + self::RADIUS, self::REGION); ++$regionZ) {
                $plan = $this->at($regionX, $regionZ);
                if ($plan === null) {
                    continue;
                }
                if ($plan->centerX + self::RADIUS < $chunkMinimumX || $plan->centerX - self::RADIUS > $chunkMaximumX
                    || $plan->centerZ + self::RADIUS < $chunkMinimumZ || $plan->centerZ - self::RADIUS > $chunkMaximumZ) {
                    continue;
                }
                $plans[] = $plan;
            }
        }
        usort($plans, static fn(EndStructurePlan $left, EndStructurePlan $right): int => $left->id <=> $right->id);

        return $plans;
    }

    public function nearest(
        EndStructureKind $kind,
        int $x,
        int $z,
        int $maximumRegionRadius = 64,
    ): ?EndStructurePlan {
        return $this->candidates($kind, $x, $z, $maximumRegionRadius)[0] ?? null;
    }

    /** @return list<EndStructurePlan> nearest first */
    public function candidates(
        EndStructureKind $kind,
        int $x,
        int $z,
        int $maximumRegionRadius = 64,
    ): array {
        if ($maximumRegionRadius < 0 || $maximumRegionRadius > 64) {
            throw new \InvalidArgumentException('End structure search radius must be between zero and 64 regions.');
        }
        $originX = self::floorDiv($x, self::REGION);
        $originZ = self::floorDiv($z, self::REGION);
        $candidates = [];
        for ($regionX = $originX - $maximumRegionRadius; $regionX <= $originX + $maximumRegionRadius; ++$regionX) {
            for ($regionZ = $originZ - $maximumRegionRadius; $regionZ <= $originZ + $maximumRegionRadius; ++$regionZ) {
                $candidate = $this->at($regionX, $regionZ);
                if ($candidate === null || ($kind === EndStructureKind::CityWithShip && $candidate->kind !== $kind)) {
                    continue;
                }
                $candidates[] = $candidate;
            }
        }
        usort($candidates, static function (EndStructurePlan $left, EndStructurePlan $right) use ($x, $z): int {
            $leftDistance = ($left->centerX - $x) ** 2 + ($left->centerZ - $z) ** 2;
            $rightDistance = ($right->centerX - $x) ** 2 + ($right->centerZ - $z) ** 2;

            return $leftDistance <=> $rightDistance ?: $left->id <=> $right->id;
        });

        return $candidates;
    }

    private function at(int $regionX, int $regionZ): ?EndStructurePlan
    {
        if ($this->noise->chance($regionX, 0, $regionZ, 1_901, 4) !== 0) {
            return null;
        }
        $variantHash = hexdec(substr(hash('sha256', $this->seed . ':end_city_variant:' . $regionX . ':' . $regionZ), 0, 8));

        return new EndStructurePlan(
            'city:' . $regionX . ':' . $regionZ,
            $this->noise->chance($regionX, 4, $regionZ, 1_921, 2) === 0
                ? EndStructureKind::CityWithShip
                : EndStructureKind::City,
            $regionX * self::REGION + 128 + $this->noise->chance($regionX, 1, $regionZ, 1_907, 256),
            $regionZ * self::REGION + 128 + $this->noise->chance($regionX, 2, $regionZ, 1_913, 256),
            $this->noise->chance($regionX, 3, $regionZ, 1_919, 2) === 0,
            $variantHash % 4,
        );
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
