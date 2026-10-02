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

namespace Bedriox\Api\Player;

use Bedriox\Api\Event\Player\PlayerKickCause;
use Bedriox\Protocol\Packet\Packet;
use Closure;

/** A live, typed packet path to one player's current Bedrock connection. */
final readonly class PlayerConnection
{
    /**
     * @param Closure(): bool              $connected
     * @param Closure(Packet, bool): bool  $sendPacket
     * @param Closure(string, ?string, ?string, PlayerKickCause, ?string): bool $kick
     * @param Closure(): bool                         $swingArm
     * @internal The server owns connection construction; plugins receive it from Player::connection().
     */
    public function __construct(
        private Closure $connected,
        private Closure $sendPacket,
        private ?Closure $kick = null,
        private ?Closure $swingArm = null,
    ) {}

    public static function disconnected(): self
    {
        return new self(
            static fn(): bool => false,
            static fn(Packet $packet, bool $immediate): bool => false,
            static fn(string $reason, ?string $quitMessage, ?string $screenMessage, PlayerKickCause $cause, ?string $actor): bool => false,
            static fn(): bool => false,
        );
    }

    public function isConnected(): bool
    {
        return ($this->connected)();
    }

    public function sendPacket(Packet $packet, bool $immediate = false): bool
    {
        return ($this->sendPacket)($packet, $immediate);
    }

    public function kick(
        string $reason = '',
        ?string $quitMessage = null,
        ?string $disconnectScreenMessage = null,
        PlayerKickCause $cause = PlayerKickCause::PLUGIN,
        ?string $actor = null,
    ): bool {
        return $this->kick !== null && ($this->kick)($reason, $quitMessage, $disconnectScreenMessage, $cause, $actor);
    }

    public function swingArm(): bool
    {
        return $this->swingArm !== null && ($this->swingArm)();
    }
}
