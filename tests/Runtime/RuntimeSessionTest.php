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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\World\WorldDimension;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\RakNet\SessionInfo;
use Bedriox\Server\Runtime\RuntimeSession;
use PHPUnit\Framework\TestCase;

final class RuntimeSessionTest extends TestCase
{
    public function testSessionCanonicalizesWorldAndRetainsDimensionIdentity(): void
    {
        $session = new RuntimeSession(
            new SessionInfo('127.0.0.1', 19_132, 1, 1_400, 11),
            'session',
            UnsignedLong::fromInt(1),
            null,
            '  Example World  ',
            WorldDimension::NETHER,
        );

        self::assertSame('example-world', $session->worldId);
        self::assertSame(WorldDimension::NETHER, $session->dimension);
    }

    public function testSessionDefaultsToOverworld(): void
    {
        $session = new RuntimeSession(
            new SessionInfo('127.0.0.1', 19_132, 1, 1_400, 11),
            'session',
            UnsignedLong::fromInt(1),
            null,
        );

        self::assertSame(WorldDimension::OVERWORLD, $session->dimension);
    }
}
