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

use Bedriox\Api\Event\Player\PlayerQuitCause;
use Bedriox\Api\TranslatableMessage;

final readonly class PlayerDisconnected implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public string $sessionId,
        public string $identity,
        public int $runtimeActorId,
        public array $recipientSessionIds,
        public PlayerQuitCause $cause = PlayerQuitCause::DISCONNECTED,
        public string $reason = 'Disconnected',
        public ?string $actor = null,
        public string|TranslatableMessage|null $quitMessage = null,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
