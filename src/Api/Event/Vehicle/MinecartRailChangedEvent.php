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
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\World\Rail\RailSegment;

/** Immutable observation after a minecart enters a different rail segment. */
final class MinecartRailChangedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Minecart $minecart,
        public readonly ?RailSegment $from,
        public readonly RailSegment $to,
        public readonly float $speed,
    ) {}
}
