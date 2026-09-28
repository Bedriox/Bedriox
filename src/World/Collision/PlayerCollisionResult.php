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

namespace Bedriox\Server\World\Collision;

use Bedriox\Server\Simulation\Position;

final readonly class PlayerCollisionResult
{
    public function __construct(
        public Position $position,
        public bool $collidedX,
        public bool $collidedY,
        public bool $collidedZ,
        public bool $stepped,
        public bool $grounded,
        public bool $fastPath = false,
        public int $obstacleCount = 0,
        public bool $terrainLoaded = true,
    ) {}

    public function collided(): bool
    {
        return $this->collidedX || $this->collidedY || $this->collidedZ;
    }
}
