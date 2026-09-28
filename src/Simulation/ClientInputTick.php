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

namespace Bedriox\Server\Simulation;

use InvalidArgumentException;

/** Protocol-independent unsigned 64-bit tick attached to one client movement input. */
final readonly class ClientInputTick
{
    private const int MAX_LIMB = 0xffffffff;

    public function __construct(
        public int $high,
        public int $low,
    ) {
        if ($high < 0 || $high > self::MAX_LIMB || $low < 0 || $low > self::MAX_LIMB) {
            throw new InvalidArgumentException('Client input tick limbs must be unsigned 32-bit integers.');
        }
    }

    public static function fromInt(int $value): self
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Client input tick cannot be negative.');
        }

        return new self(($value >> 32) & self::MAX_LIMB, $value & self::MAX_LIMB);
    }
}
