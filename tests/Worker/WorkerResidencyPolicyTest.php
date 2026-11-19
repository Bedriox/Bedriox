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

namespace Bedriox\Server\Tests\Worker;

use Bedriox\Server\Worker\Internal\WorkerResidencyPolicy;
use PHPUnit\Framework\TestCase;

final class WorkerResidencyPolicyTest extends TestCase
{
    public function testDemandStartsOnlyTheCapacityNeededByOutstandingWork(): void
    {
        $policy = new WorkerResidencyPolicy();

        self::assertSame(1, $policy->desiredWorkers(8, 1, 0, 0));
        self::assertSame(2, $policy->desiredWorkers(8, 1, 1, 1));
        self::assertSame(8, $policy->desiredWorkers(8, 1, 1, 20));
        self::assertSame(4, $policy->desiredWorkers(8, 4, 0, 0));
    }

    public function testIdleRetirementUsesTheBoundedFiveMinuteWindow(): void
    {
        $policy = new WorkerResidencyPolicy();
        $startedAt = 1_000_000_000;

        self::assertFalse($policy->idleExpired($startedAt, $startedAt + 299_999_999_999));
        self::assertTrue($policy->idleExpired($startedAt, $startedAt + 300_000_000_000));
    }
}
