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

use Bedriox\Server\Security\ProtocolViolationTracker;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProtocolViolationTrackerTest extends TestCase
{
    public function testSoftViolationsRequireRepeatedEvidence(): void
    {
        $tracker = new ProtocolViolationTracker(3, 15_000_000_000);

        self::assertFalse($tracker->recordSoftViolation(0));
        self::assertFalse($tracker->recordSoftViolation(1));
        self::assertTrue($tracker->recordSoftViolation(2));
        self::assertSame(3, $tracker->snapshot(2)['soft_strikes']);
    }

    public function testSoftEvidenceDecaysOneStrikePerInterval(): void
    {
        $tracker = new ProtocolViolationTracker(3, 10);
        self::assertFalse($tracker->recordSoftViolation(0));
        self::assertFalse($tracker->recordSoftViolation(1));

        self::assertSame(1, $tracker->snapshot(10)['soft_strikes']);
        self::assertSame(0, $tracker->snapshot(20)['soft_strikes']);
        self::assertFalse($tracker->recordSoftViolation(20));
    }

    public function testHardViolationsAreReportedSeparately(): void
    {
        $tracker = new ProtocolViolationTracker();
        $tracker->recordHardViolation();
        $tracker->recordHardViolation();

        self::assertSame(2, $tracker->snapshot(0)['hard_violations']);
        self::assertSame(0, $tracker->snapshot(0)['soft_strikes']);
    }

    public function testTimeMustRemainMonotonic(): void
    {
        $tracker = new ProtocolViolationTracker(startedNanoseconds: 10);

        $this->expectException(InvalidArgumentException::class);
        $tracker->recordSoftViolation(9);
    }
}
