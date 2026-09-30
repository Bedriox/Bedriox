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

namespace Bedriox\Server\Gameplay\Combat;

use InvalidArgumentException;

final readonly class KnockbackMotion
{
    public function __construct(
        public float $x,
        public float $y,
        public float $z,
    ) {
        if (!is_finite($x) || !is_finite($y) || !is_finite($z)) {
            throw new InvalidArgumentException('Knockback motion must be finite.');
        }
    }
}
