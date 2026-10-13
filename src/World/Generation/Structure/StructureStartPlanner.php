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

namespace Bedriox\Server\World\Generation\Structure;

final class StructureStartPlanner
{
    public function candidateForRegion(
        int $worldSeed,
        StructureSet $set,
        int $regionX,
        int $regionZ,
    ): StructureStartCandidate {
        $bound = $set->spacing - $set->separation;
        $digest = hash('sha256', implode(':', [$worldSeed, $set->salt, $regionX, $regionZ]), true);
        $offsetX = self::unsignedInt($digest, 0) % $bound;
        $offsetZ = self::unsignedInt($digest, 4) % $bound;

        return new StructureStartCandidate(
            $set->id,
            $regionX,
            $regionZ,
            $regionX * $set->spacing + $offsetX,
            $regionZ * $set->spacing + $offsetZ,
        );
    }

    public function candidateForChunk(int $worldSeed, StructureSet $set, int $chunkX, int $chunkZ): StructureStartCandidate
    {
        return $this->candidateForRegion(
            $worldSeed,
            $set,
            self::floorDiv($chunkX, $set->spacing),
            self::floorDiv($chunkZ, $set->spacing),
        );
    }

    /** @return list<StructureStartCandidate> */
    public function candidatesNearChunk(
        int $worldSeed,
        StructureSet $set,
        int $chunkX,
        int $chunkZ,
        int $regionRadius = 1,
    ): array {
        if ($regionRadius < 0 || $regionRadius > 16) {
            throw new \InvalidArgumentException('Structure candidate region radius must be between 0 and 16.');
        }
        $centerX = self::floorDiv($chunkX, $set->spacing);
        $centerZ = self::floorDiv($chunkZ, $set->spacing);
        $candidates = [];
        for ($regionX = $centerX - $regionRadius; $regionX <= $centerX + $regionRadius; ++$regionX) {
            for ($regionZ = $centerZ - $regionRadius; $regionZ <= $centerZ + $regionRadius; ++$regionZ) {
                $candidates[] = $this->candidateForRegion($worldSeed, $set, $regionX, $regionZ);
            }
        }

        return $candidates;
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);
        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }

    private static function unsignedInt(string $bytes, int $offset): int
    {
        return ord($bytes[$offset])
            | (ord($bytes[$offset + 1]) << 8)
            | (ord($bytes[$offset + 2]) << 16)
            | (ord($bytes[$offset + 3]) << 24);
    }
}
