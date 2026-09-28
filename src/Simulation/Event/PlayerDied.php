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

use Bedriox\Api\TranslatableMessage;
use Bedriox\Server\Simulation\DamageCause;
use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class PlayerDied implements WorldEvent
{
    /**
     * @param list<string> $animationRecipientSessionIds
     * @param list<string> $messageRecipientSessionIds
     */
    public function __construct(
        public PlayerSnapshot $player,
        public DamageCause $cause,
        public ?PlayerSnapshot $killer,
        public string|TranslatableMessage|null $deathMessage,
        public string|TranslatableMessage|null $deathScreenMessage,
        public array $animationRecipientSessionIds,
        public array $messageRecipientSessionIds,
    ) {}
    public function recipients(): array
    {
        $recipients = [$this->player->sessionId => true];
        foreach ($this->animationRecipientSessionIds as $recipient) {
            $recipients[$recipient] = true;
        }
        foreach ($this->messageRecipientSessionIds as $recipient) {
            $recipients[$recipient] = true;
        }

        return array_keys($recipients);
    }
}
