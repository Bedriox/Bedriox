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
use Bedriox\Api\Entity\Value\Motion;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\World\Position;

/** Immutable observation after an authoritative vehicle displacement commits. */
final class VehicleMovedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Vehicle $vehicle,
        public readonly Position $from,
        public readonly Position $to,
        public readonly Motion $motion,
    ) {}
}
