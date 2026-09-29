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

namespace Bedriox\Server\Gameplay\Potion;

use Bedriox\Api\Effect\EffectInstance;
use InvalidArgumentException;

/** One effect plus the bounded intensity used by instant potion effects. */
final readonly class PotionEffectDose
{
    public function __construct(
        public EffectInstance $effect,
        public float $intensity = 1.0,
    ) {
        if (!is_finite($intensity) || $intensity < 0.0 || $intensity > 1.0) {
            throw new InvalidArgumentException('Potion effect intensity must be between zero and one.');
        }
    }
}
