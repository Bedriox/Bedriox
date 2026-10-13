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

namespace Bedriox\Server\World\Generation\Structure\Nether;

use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\SeededNoise;

/** Reconstructs every candidate from seed and region coordinates; no chunk order is observable. */
final readonly class NetherStructurePlanner
{
    private const int MAJOR_REGION = 384;
    private const int MAXIMUM_RADIUS = 48;

    private SeededNoise $noise;

    private int $seed;

    public function __construct(int $seed)
    {
        $this->seed = $seed;
        $this->noise = new SeededNoise($seed ^ 0x4e455448);
    }

    /** @return list<NetherStructurePlan> */
    public function intersecting(ChunkPosition $position): array
    {
        $plans = [];
        $minimumX = $position->x * 16 - self::MAXIMUM_RADIUS;
        $maximumX = $position->x * 16 + 15 + self::MAXIMUM_RADIUS;
        $minimumZ = $position->z * 16 - self::MAXIMUM_RADIUS;
        $maximumZ = $position->z * 16 + 15 + self::MAXIMUM_RADIUS;
        $this->appendMajor($plans, $position, $minimumX, $maximumX, $minimumZ, $maximumZ);
        usort($plans, static fn(NetherStructurePlan $left, NetherStructurePlan $right): int => $left->id <=> $right->id);

        return $plans;
    }

    public function nearest(
        NetherStructureKind $kind,
        int $x,
        int $z,
        int $maximumRegionRadius = 64,
    ): ?NetherStructurePlan {
        return $this->candidates($kind, $x, $z, $maximumRegionRadius)[0] ?? null;
    }

    /** @return list<NetherStructurePlan> nearest first */
    public function candidates(
        NetherStructureKind $kind,
        int $x,
        int $z,
        int $maximumRegionRadius = 64,
    ): array {
        if ($maximumRegionRadius < 0 || $maximumRegionRadius > 64) {
            throw new \InvalidArgumentException('Nether structure search radius must be between zero and 64 regions.');
        }
        $spacing = self::MAJOR_REGION;
        $originX = self::floorDiv($x, $spacing);
        $originZ = self::floorDiv($z, $spacing);
        $candidates = [];
        for ($radius = 0; $radius <= $maximumRegionRadius; ++$radius) {
            for ($regionX = $originX - $radius; $regionX <= $originX + $radius; ++$regionX) {
                for ($regionZ = $originZ - $radius; $regionZ <= $originZ + $radius; ++$regionZ) {
                    if ($radius !== 0 && abs($regionX - $originX) !== $radius && abs($regionZ - $originZ) !== $radius) {
                        continue;
                    }
                    $candidate = $this->majorAt($regionX, $regionZ);
                    if ($candidate?->kind !== $kind) {
                        continue;
                    }
                    $candidates[] = $candidate;
                }
            }
        }
        usort($candidates, static function (NetherStructurePlan $left, NetherStructurePlan $right) use ($x, $z): int {
            $leftDistance = ($left->centerX - $x) ** 2 + ($left->centerZ - $z) ** 2;
            $rightDistance = ($right->centerX - $x) ** 2 + ($right->centerZ - $z) ** 2;

            return $leftDistance <=> $rightDistance ?: $left->id <=> $right->id;
        });

        return $candidates;
    }

    /** @param list<NetherStructurePlan> $plans */
    private function appendMajor(array &$plans, ChunkPosition $chunk, int $minX, int $maxX, int $minZ, int $maxZ): void
    {
        for ($regionX = self::floorDiv($minX, self::MAJOR_REGION); $regionX <= self::floorDiv($maxX, self::MAJOR_REGION); ++$regionX) {
            for ($regionZ = self::floorDiv($minZ, self::MAJOR_REGION); $regionZ <= self::floorDiv($maxZ, self::MAJOR_REGION); ++$regionZ) {
                $plan = $this->majorAt($regionX, $regionZ);
                if ($plan?->intersects($chunk) === true) {
                    $plans[] = $plan;
                }
            }
        }
    }

    private function majorAt(int $regionX, int $regionZ): ?NetherStructurePlan
    {
        if ($this->noise->chance($regionX, 0, $regionZ, 1_701, 3) !== 0) {
            return null;
        }
        $bastion = $this->noise->chance($regionX, 4, $regionZ, 1_727, 4) === 0;
        $variant = hexdec(substr(hash('sha256', $this->seed . ':bastion:' . $regionX . ':' . $regionZ), 0, 8)) % 4;
        $kind = $bastion ? match ($variant) {
            0 => NetherStructureKind::BastionBridge,
            1 => NetherStructureKind::BastionHoglinStable,
            2 => NetherStructureKind::BastionHousing,
            default => NetherStructureKind::BastionTreasure,
        } : NetherStructureKind::Fortress;

        return new NetherStructurePlan(
            'major:' . $regionX . ':' . $regionZ,
            $kind,
            $regionX * self::MAJOR_REGION + 96 + $this->noise->chance($regionX, 1, $regionZ, 1_709, 192),
            48 + $this->noise->chance($regionX, 3, $regionZ, 1_723, 23),
            $regionZ * self::MAJOR_REGION + 96 + $this->noise->chance($regionX, 2, $regionZ, 1_717, 192),
            $this->noise->chance($regionX, 5, $regionZ, 1_729, 4),
            42,
        );
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
