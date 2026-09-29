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

use Bedriox\Api\World\WeatherState;
use InvalidArgumentException;

/** Immutable state required to resume the same natural weather sequence after restart. */
final readonly class WeatherCycleState
{
    public function __construct(
        public WeatherState $weather,
        public int $transitionSequence = 0,
    ) {
        if ($transitionSequence < 0 || $transitionSequence > 0x7fffffff) {
            throw new InvalidArgumentException('Weather transition sequence must be a non-negative 32-bit integer.');
        }
    }
}
