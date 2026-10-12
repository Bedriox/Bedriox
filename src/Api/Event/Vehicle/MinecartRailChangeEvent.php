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

namespace Bedriox\Api\Event\Vehicle;

use Bedriox\Api\Entity\Vanilla\Minecart;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\Rail\RailSegment;
use InvalidArgumentException;

/** Cancellable transition before a minecart enters a different rail segment. */
final class MinecartRailChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly Minecart $minecart,
        public readonly ?RailSegment $from,
        public readonly RailSegment $to,
        public readonly float $speed,
    ) {
        if (!is_finite($speed) || abs($speed) > 100.0) {
            throw new InvalidArgumentException('Minecart rail-transition speed must be finite and bounded.');
        }
    }
}
