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

namespace Bedriox\Server\Gameplay\Explosion\Value;

use Bedriox\Api\Event\Entity\EntityExplosionPrimeEvent;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Chunk;
use InvalidArgumentException;

final readonly class ExplosionRequest
{
    public function __construct(
        public Position $center,
        public float $radius,
        public bool $breaksBlocks = true,
        public float $fireChance = 0.0,
    ) {
        if (!is_finite($center->x) || !is_finite($center->y) || !is_finite($center->z)
            || abs($center->x) > 30_000_000.0 || abs($center->z) > 30_000_000.0
            || $center->y < Chunk::MIN_Y || $center->y > Chunk::MAX_Y) {
            throw new InvalidArgumentException('Explosion center is outside the supported world boundary.');
        }
        if (!is_finite($radius) || $radius <= 0.0 || $radius > EntityExplosionPrimeEvent::MAXIMUM_RADIUS) {
            throw new InvalidArgumentException('Explosion radius must be finite, positive, and at most 16 blocks.');
        }
        if (!is_finite($fireChance) || $fireChance < 0.0 || $fireChance > 1.0) {
            throw new InvalidArgumentException('Explosion fire chance must be between 0 and 1.');
        }
    }
}
