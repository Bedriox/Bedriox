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
}
