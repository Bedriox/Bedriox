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

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Server\Entity\Mount\MountSeatOffset;
use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class ActorMounted implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public int $vehicleRuntimeId,
        public int $passengerRuntimeId,
        public MountSeat $seat,
        public MountSeatOffset $seatOffset,
        public bool $riderInitiated,
        public ?PlayerSnapshot $passenger,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
