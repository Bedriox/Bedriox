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

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\SeededNoise;

/** Resolves ownership by the built-in Nether generator, never by replaceable floor material. */
final readonly class NetherStructureLocator
{
    private const int REGION = 384;

    private SeededNoise $noise;

    public function __construct(int $worldSeed)
    {
        $this->noise = new SeededNoise($worldSeed ^ 0x4e455448);
    }

    public function at(Position $position): ?NetherStructureType
    {
        return $this->locate($position)?->type;
    }

    public function locate(Position $position): ?NetherStructureProvenance
    {
        $x = (int) floor($position->x);
        $supportY = (int) floor($position->y - 0.000_001);
        $z = (int) floor($position->z);
        $originRegionX = self::floorDiv($x, self::REGION);
        $originRegionZ = self::floorDiv($z, self::REGION);
        for ($regionX = $originRegionX - 1; $regionX <= $originRegionX + 1; ++$regionX) {
            for ($regionZ = $originRegionZ - 1; $regionZ <= $originRegionZ + 1; ++$regionZ) {
                if ($this->noise->chance($regionX, 0, $regionZ, 1_701, 3) !== 0) {
                    continue;
                }
                $structure = $this->structure($regionX, $regionZ);
                if (!$structure->occupiesFloor($x, $supportY, $z)) {
                    continue;
                }

                return $structure;
            }
        }

        return null;
    }

    /** @return list<NetherStructureProvenance> */
    public function intersectingChunk(ChunkPosition $chunk): array
    {
        $minimumX = ($chunk->x * 16) - 34;
        $maximumX = ($chunk->x * 16) + 49;
        $minimumZ = ($chunk->z * 16) - 34;
        $maximumZ = ($chunk->z * 16) + 49;
        $structures = [];
        for ($regionX = self::floorDiv($minimumX, self::REGION); $regionX <= self::floorDiv($maximumX, self::REGION); ++$regionX) {
            for ($regionZ = self::floorDiv($minimumZ, self::REGION); $regionZ <= self::floorDiv($maximumZ, self::REGION); ++$regionZ) {
                if ($this->noise->chance($regionX, 0, $regionZ, 1_701, 3) !== 0) {
                    continue;
                }
                $structure = $this->structure($regionX, $regionZ);
                if ($structure->centerX + 34 < $chunk->x * 16
                    || $structure->centerX - 34 > ($chunk->x * 16) + 15
                    || $structure->centerZ + 34 < $chunk->z * 16
                    || $structure->centerZ - 34 > ($chunk->z * 16) + 15) {
                    continue;
                }
                $structures[] = $structure;
            }
        }

        return $structures;
    }

    private function structure(int $regionX, int $regionZ): NetherStructureProvenance
    {
        return new NetherStructureProvenance(
            $this->noise->chance($regionX, 4, $regionZ, 1_727, 4) === 0
                ? NetherStructureType::BASTION
                : NetherStructureType::FORTRESS,
            $regionX,
            $regionZ,
            $regionX * self::REGION + 96 + $this->noise->chance($regionX, 1, $regionZ, 1_709, 192),
            48 + $this->noise->chance($regionX, 3, $regionZ, 1_723, 23),
            $regionZ * self::REGION + 96 + $this->noise->chance($regionX, 2, $regionZ, 1_717, 192),
        );
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
