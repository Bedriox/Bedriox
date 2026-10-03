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

use Bedriox\Api\Entity\Vanilla\Boat;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Player\Player;

/** Immutable observation after authoritative boat control is accepted. */
final class VehicleControlledEvent extends Event
{
    public function __construct(
        public readonly Player $driver,
        public readonly Boat $vehicle,
        public readonly float $forward,
        public readonly float $strafe,
        public readonly float $yaw,
        public readonly bool $paddlingLeft,
        public readonly bool $paddlingRight,
    ) {}
}
