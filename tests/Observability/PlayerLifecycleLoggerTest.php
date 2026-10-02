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

namespace Bedriox\Server\Tests\Observability;

use Bedriox\Api\Event\Player\PlayerQuitCause;
use Bedriox\RakNet\SessionInfo;
use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Observability\PlayerLifecycleLogger;
use Bedriox\Server\Observability\ServerLogger;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\PlayerSnapshot;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\VerticalState;
use PHPUnit\Framework\TestCase;

final class PlayerLifecycleLoggerTest extends TestCase
{
    public function testWritesSanitizedJoiningJoinedAndFailureLines(): void
    {
        $logger = new ServerLogger(static function (string $line): void {}, LogLevel::DEBUG, false, false, null);
        $lifecycle = new PlayerLifecycleLogger($logger);
        $session = new SessionInfo('127.0.0.1', 19_132, 1, 1_400, 11);
        $player = new PlayerSnapshot(
            'session',
            'identity',
            "Player\nForged",
            new Position(1.5, 64.0, -2.25),
            0.0,
            0.0,
            MovementMode::STOPPED,
            0,
            VerticalState::GROUNDED,
            0.0,
        );

        $lifecycle->joining('Player', $session);
        $lifecycle->joined($player, 'world', $session);
        $lifecycle->failed('Player', $session, "Rejected\nForged");
        $lifecycle->left('Player', $session, PlayerQuitCause::KICKED, 'Griefing', 'Console');
        $lines = $logger->recentLines();

        self::assertStringContainsString('[Player] Player[/127.0.0.1:19132] is joining', $lines[0]);
        self::assertStringContainsString('[Player] Player\\n Forged[/127.0.0.1:19132] joined world at (1.50, 64.00, -2.25)', $lines[1]);
        self::assertStringContainsString('failed to join: Rejected\\n Forged', $lines[2]);
        self::assertStringContainsString(
            '[Player] Player[/127.0.0.1:19132] left the server: Kicked by Console — Griefing',
            $lines[3],
        );
    }
}
