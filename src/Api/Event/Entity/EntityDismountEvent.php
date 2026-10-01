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

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\Value\MountReason;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;

/** Cancellable intent before one passenger leaves its vehicle. */
final class EntityDismountEvent extends CancellableEvent
{
    public function __construct(
        public readonly Entity|Player $passenger,
        public readonly Entity $vehicle,
        public readonly MountSeat $seat,
        public readonly MountReason $reason,
    ) {}

    protected function cancellationAllowed(): bool
    {
        return !in_array($this->reason, [
            MountReason::DEATH,
            MountReason::TELEPORT,
            MountReason::WORLD_CHANGE,
            MountReason::DISCONNECT,
            MountReason::VEHICLE_REMOVED,
        ], true);
    }
}
