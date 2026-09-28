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

namespace Bedriox\Server\World;

use InvalidArgumentException;

final readonly class BlockPosition
{
    public function __construct(
        public int $x,
        public int $y,
        public int $z,
    ) {
        if ($x < -30_000_000 || $x > 30_000_000 || $z < -30_000_000 || $z > 30_000_000
            || $y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
            throw new InvalidArgumentException('Block position is outside the supported world boundary.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->x === $other->x && $this->y === $other->y && $this->z === $other->z;
    }
}
