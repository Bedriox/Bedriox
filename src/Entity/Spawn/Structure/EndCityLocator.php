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

namespace Bedriox\Server\Entity\Spawn\Structure;

use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\SeededNoise;

/** Shares the built-in End generator's deterministic city placement contract. */
final readonly class EndCityLocator
{
    private const int REGION = 512;
    private const int RADIUS = 42;

    private SeededNoise $noise;

    public function __construct(int $seed)
    {
        $this->noise = new SeededNoise($seed ^ 0x454e4421);
    }

    /** @return list<EndCityPlacement> */
    public function intersectingChunk(ChunkPosition $chunk): array
    {
        $minimumX = $chunk->x * 16 - self::RADIUS;
        $maximumX = $chunk->x * 16 + 15 + self::RADIUS;
        $minimumZ = $chunk->z * 16 - self::RADIUS;
        $maximumZ = $chunk->z * 16 + 15 + self::RADIUS;
        $placements = [];
        for ($regionX = self::floorDiv($minimumX, self::REGION); $regionX <= self::floorDiv($maximumX, self::REGION); ++$regionX) {
            for ($regionZ = self::floorDiv($minimumZ, self::REGION); $regionZ <= self::floorDiv($maximumZ, self::REGION); ++$regionZ) {
                if ($this->noise->chance($regionX, 0, $regionZ, 1_901, 4) !== 0) {
                    continue;
                }
                $centerX = $regionX * self::REGION + 128 + $this->noise->chance($regionX, 1, $regionZ, 1_907, 256);
                $centerZ = $regionZ * self::REGION + 128 + $this->noise->chance($regionX, 2, $regionZ, 1_913, 256);
                if (hypot((float) $centerX, (float) $centerZ) < 1_150.0) {
                    continue;
                }
                $placements[] = new EndCityPlacement(
                    $regionX,
                    $regionZ,
                    $centerX,
                    $centerZ,
                    $this->noise->chance($regionX, 3, $regionZ, 1_919, 2) === 0,
                );
            }
        }

        return $placements;
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
