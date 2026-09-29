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

namespace Bedriox\Api\World\Particle;

use InvalidArgumentException;

final readonly class MobSpawnParticle implements Particle
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
        if ($width < 0 || $width > 255 || $height < 0 || $height > 255) {
            throw new InvalidArgumentException('Mob-spawn particle dimensions must be between 0 and 255.');
        }
    }

    public function estimatedBytes(): int
    {
        return 40;
    }
}
