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

namespace Bedriox\Server\Tests\Observability\Memory;

use Bedriox\Server\Observability\Memory\IdleGarbageCollectionGate;
use PHPUnit\Framework\TestCase;

final class IdleGarbageCollectionGateTest extends TestCase
{
    public function testCollectionRequiresContinuousIdleGraceAndIsRateLimited(): void
    {
        $gate = new IdleGarbageCollectionGate(5_000_000_000, 30_000_000_000);

        self::assertFalse($gate->shouldCollect(1_000_000_000, true));
        self::assertFalse($gate->shouldCollect(5_999_999_999, true));
        self::assertTrue($gate->shouldCollect(6_000_000_000, true));
        self::assertFalse($gate->shouldCollect(35_999_999_999, true));
        self::assertTrue($gate->shouldCollect(36_000_000_000, true));
    }

    public function testActivityRestartsIdleGraceAndAttemptCadence(): void
    {
        $gate = new IdleGarbageCollectionGate(5_000_000_000, 30_000_000_000);

        self::assertFalse($gate->shouldCollect(1_000_000_000, true));
        self::assertTrue($gate->shouldCollect(6_000_000_000, true));
        self::assertFalse($gate->shouldCollect(7_000_000_000, false));
        self::assertFalse($gate->shouldCollect(8_000_000_000, true));
        self::assertFalse($gate->shouldCollect(12_999_999_999, true));
        self::assertTrue($gate->shouldCollect(13_000_000_000, true));
    }
}
