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

use Bedriox\Server\Simulation\ClientInputTick;
use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class MovementCorrected implements WorldEvent
{
    /** @param list<string> $peerSessionIds */
    public function __construct(
        public PlayerSnapshot $authoritativePlayer,
        public string $reason,
        public array $peerSessionIds = [],
        public bool $postureChanged = false,
        public ?ClientInputTick $clientTick = null,
    ) {}

    public function recipients(): array
    {
        return array_values(array_unique([
            $this->authoritativePlayer->sessionId,
            ...$this->peerSessionIds,
        ]));
    }
}
