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

use Bedriox\Api\Player\ExperienceChangeCause;
use Bedriox\Api\Player\ExperienceSnapshot;
use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class PlayerExperienceChanged implements WorldEvent
{
    public function __construct(
        public PlayerSnapshot $player,
        public ExperienceSnapshot $previous,
        public ExperienceChangeCause $cause,
    ) {}

    public function recipients(): array
    {
        return [$this->player->sessionId];
    }
}
