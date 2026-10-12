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

use Bedriox\Api\Entity\Capability\Vehicle;
use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;

/** Cancellable collision response after contact validation and before its impulse is applied. */
final class VehicleCollisionEvent extends CancellableEvent
{
    public function __construct(
        public readonly Vehicle $vehicle,
        public readonly VehicleCollisionKind $kind,
        public readonly Entity|BlockPosition $other,
        public readonly float $relativeSpeed,
    ) {
        if (!is_finite($relativeSpeed) || $relativeSpeed < 0.0) {
            throw new InvalidArgumentException('Vehicle collision speed must be finite and non-negative.');
        }
    }
}
