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

namespace Bedriox\Server\Tests\Player;

use Bedriox\Api\Player\ExperienceSnapshot;
use Bedriox\Server\Player\ExperienceMath;
use PHPUnit\Framework\TestCase;

final class ExperienceMathTest extends TestCase
{
    public function testVanillaCurveBoundaries(): void
    {
        self::assertSame(0, ExperienceMath::totalPointsToReachLevel(0));
        self::assertSame(7, ExperienceMath::totalPointsToReachLevel(1));
        self::assertSame(352, ExperienceMath::totalPointsToReachLevel(16));
        self::assertSame(394, ExperienceMath::totalPointsToReachLevel(17));
        self::assertSame(1_507, ExperienceMath::totalPointsToReachLevel(31));
        self::assertSame(1_628, ExperienceMath::totalPointsToReachLevel(32));
    }

    public function testSnapshotDerivesLevelAndProgressFromOneAuthoritativeValue(): void
    {
        $snapshot = new ExperienceSnapshot(10);
        self::assertSame(1, $snapshot->level);
        self::assertEqualsWithDelta(3 / 9, $snapshot->progress, 0.000001);
        self::assertSame(32, (new ExperienceSnapshot(1_628))->level);
    }
}
