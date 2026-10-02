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

use Bedriox\Api\Event\Player\PlayerQuitCause;
use Bedriox\Api\TranslatableMessage;

final readonly class DisconnectPlayer implements WorldCommand
{
    public function __construct(
        public string $session,
        public PlayerQuitCause $cause,
        public string $reason,
        public ?string $actor,
        public string|TranslatableMessage|null $quitMessage,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 48 + strlen($this->session) + strlen($this->reason) + strlen($this->actor ?? '')
            + ($this->quitMessage instanceof TranslatableMessage ? 128 : strlen($this->quitMessage ?? ''));
    }
}
