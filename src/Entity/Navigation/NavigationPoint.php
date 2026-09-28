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

namespace Bedriox\Server\Entity\Navigation;

use InvalidArgumentException;

final readonly class NavigationPoint
{
    public function __construct(
        public int $x,
        public int $y,
        public int $z,
    ) {
        if (abs($x) > 30_000_000 || abs($z) > 30_000_000 || abs($y) > 2_048) {
            throw new InvalidArgumentException('Navigation point is outside world bounds.');
        }
    }

    public function key(): string
    {
        return $this->x . ':' . $this->y . ':' . $this->z;
    }
}
