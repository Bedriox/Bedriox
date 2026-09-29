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

final readonly class DragonEggTeleportParticle implements Particle
{
    public function __construct(
        public int $offsetX,
        public int $offsetY,
        public int $offsetZ,
    ) {
        foreach ([$offsetX, $offsetY, $offsetZ] as $offset) {
            if ($offset < -255 || $offset > 255) {
                throw new InvalidArgumentException('Dragon-egg particle offsets must be between -255 and 255.');
            }
        }
    }

    public function estimatedBytes(): int
    {
        return 48;
    }
}
