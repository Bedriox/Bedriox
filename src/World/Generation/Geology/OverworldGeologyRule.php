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

namespace Bedriox\Server\World\Generation\Geology;

use InvalidArgumentException;

/** One named, independently-seeded overworld geology placement rule. */
final readonly class OverworldGeologyRule
{
    /** @param array<string, string> $replacements Host block identifier to replacement identifier. */
    public function __construct(
        public string $identifier,
        public int $attempts,
        public ?int $chanceBound,
        public bool $triangularHeight,
        public int $heightOffset,
        public int $heightBound,
        public int $veinSize,
        public float $discardChanceOnAirExposure,
        public array $replacements,
    ) {
        if ($identifier === '' || $attempts < 0 || ($chanceBound !== null && $chanceBound < 2)
            || $heightBound < 1 || $veinSize < 1 || $veinSize > 64
            || $discardChanceOnAirExposure < 0.0 || $discardChanceOnAirExposure > 1.0
            || $replacements === []) {
            throw new InvalidArgumentException('Invalid overworld geology rule.');
        }
    }

    public function attemptCount(MersenneTwister $random): int
    {
        if ($this->chanceBound !== null) {
            return $random->nextInt($this->chanceBound) === 0 ? 1 : 0;
        }

        return $this->attempts;
    }

    /** @return array{x: int, y: int, z: int} */
    public function origin(MersenneTwister $random, int $chunkX, int $chunkZ): array
    {
        $z = $chunkZ * 16 + $random->nextInt(16);
        $y = $random->nextInt($this->heightBound);
        if ($this->triangularHeight) {
            $y += $random->nextInt($this->heightBound);
        }
        $x = $chunkX * 16 + $random->nextInt(16);

        return ['x' => $x, 'y' => $y + $this->heightOffset, 'z' => $z];
    }
}
