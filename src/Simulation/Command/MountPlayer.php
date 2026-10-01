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

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Api\Entity\Value\MountReason;
use Bedriox\Api\Entity\Value\MountSeat;

final readonly class MountPlayer implements WorldCommand
{
    public function __construct(
        public string $session,
        public int $vehicleRuntimeId,
        public string $vehicleUniqueId,
        public MountSeat $seat,
        public MountReason $reason = MountReason::INTERACTION,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 64 + strlen($this->session) + strlen($this->vehicleUniqueId);
    }
}
