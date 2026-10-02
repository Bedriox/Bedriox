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

namespace Bedriox\Server\Observability;

use Bedriox\Api\Event\Player\PlayerQuitCause;
use Bedriox\RakNet\SessionInfo;
use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class PlayerLifecycleLogger
{
    public function __construct(private ServerLogger $logger) {}

    public function joining(string $name, SessionInfo $session): void
    {
        $this->logger->info(sprintf(
            '%s[/%s:%d] is joining',
            $name,
            $session->remoteAddress,
            $session->remotePort,
        ), 'Player');
    }

    public function joined(PlayerSnapshot $player, string $world, SessionInfo $session): void
    {
        $this->logger->info(sprintf(
            '%s[/%s:%d] joined %s at (%.2f, %.2f, %.2f)',
            $player->displayName,
            $session->remoteAddress,
            $session->remotePort,
            $world,
            $player->position->x,
            $player->position->y,
            $player->position->z,
        ), 'Player');
    }

    public function failed(string $name, SessionInfo $session, string $reason): void
    {
        $this->logger->info(sprintf(
            '%s[/%s:%d] failed to join: %s',
            $name,
            $session->remoteAddress,
            $session->remotePort,
            $reason,
        ), 'Player');
    }

    public function left(
        string $name,
        SessionInfo $session,
        PlayerQuitCause $cause,
        string $reason,
        ?string $actor = null,
    ): void {
        $category = match ($cause) {
            PlayerQuitCause::DISCONNECTED => 'Disconnected',
            PlayerQuitCause::KICKED => $actor === null ? 'Kicked' : 'Kicked by ' . $actor,
            PlayerQuitCause::BANNED => $actor === null ? 'Banned' : 'Banned by ' . $actor,
            PlayerQuitCause::TIMED_OUT => 'Connection timed out',
            PlayerQuitCause::CONNECTION_LOST => 'Connection lost',
            PlayerQuitCause::SERVER_SHUTDOWN => 'Server shutting down',
        };
        $detail = trim($reason);
        if ($detail !== '' && strcasecmp($detail, $category) !== 0) {
            $category .= ' — ' . $detail;
        }
        $this->logger->info(sprintf(
            '%s[/%s:%d] left the server: %s',
            $name,
            $session->remoteAddress,
            $session->remotePort,
            $category,
        ), 'Player');
    }
}
