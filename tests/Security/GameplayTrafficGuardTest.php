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

namespace Bedriox\Server\Tests\Security;

use Bedriox\Server\Security\GameplayTrafficGuard;
use PHPUnit\Framework\TestCase;

final class GameplayTrafficGuardTest extends TestCase
{
    public function testLegitimateCadenceRefillsWithoutFalsePositive(): void
    {
        $guard = new GameplayTrafficGuard(40, 4, 4_000, 400, 100, 10);
        $now = 0;
        for ($tick = 0; $tick < 200; ++$tick) {
            self::assertTrue($guard->admitEnvelope(100, $now));
            self::assertTrue($guard->admitPackets(2, $now));
            $now += 50_000_000;
        }
    }

    public function testEnvelopePacketAndByteBurstsFailClosed(): void
    {
        $envelopes = new GameplayTrafficGuard(10, 2, 1_000, 1_000, 100, 10);
        self::assertTrue($envelopes->admitEnvelope(1, 0));
        self::assertTrue($envelopes->admitEnvelope(1, 0));
        self::assertFalse($envelopes->admitEnvelope(1, 0));

        $bytes = new GameplayTrafficGuard(100, 10, 1_000, 100, 100, 10);
        self::assertFalse($bytes->admitEnvelope(101, 0));

        $packets = new GameplayTrafficGuard(100, 10, 1_000, 1_000, 100, 3);
        self::assertTrue($packets->admitPackets(3, 0));
        self::assertFalse($packets->admitPackets(1, 0));
    }
}
