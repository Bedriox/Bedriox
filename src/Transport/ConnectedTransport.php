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

namespace Bedriox\Server\Transport;

use Bedriox\NetherNet\Protocol\Reliability;
use Bedriox\NetherNet\Server\ServerSecuritySnapshot;

/** Testable boundary around the dedicated, non-blocking NetherNet process. */
interface ConnectedTransport
{
    public function poll(int $maximumDatagrams): int;

    /** @return list<SessionOpenedEvent|SessionClosedEvent> */
    public function drainSessionEvents(): array;

    /** @return list<ReceivedPayload> */
    public function drainReceivedPayloads(): array;

    public function sendPayload(
        int $sessionId,
        string $payload,
        Reliability $reliability,
    ): void;

    public function removeSession(int $sessionId): bool;

    public function updatePlayerCount(int $onlinePlayers): void;

    public function updateAdvertisement(string $serverName, int $onlinePlayers, int $maximumPlayers): void;

    public function securitySnapshot(): ?ServerSecuritySnapshot;

    public function close(): void;
}
